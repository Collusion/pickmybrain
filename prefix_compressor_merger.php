<?php

/* Copyright (C) 2016 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@pickmybra.in
 * or visit: http://www.pickmybra.in
 */

ini_set('memory_limit', '1024M');

# check input parameters only if process number is not defined at all
# ==> this file is launched as a separate process
if ( !isset($process_number) )
{
	ini_set("display_errors", 1);
	error_reporting(E_ALL);
	mb_internal_encoding("UTF-8");
	set_time_limit(0);
	ignore_user_abort(true);
	
	# launches as a separate process, so these files are needed
	require_once("input_value_processor.php");
	require_once("tokenizer_functions.php");
	require_once("db_connection.php");
}

# launch sister processes here if multiprocessing is turned on! 
if ( $dist_threads > 1 && $process_number === 0  ) 
{	
	# launch sister-processes
	for ( $x = 1 ; $x < $dist_threads ; ++$x ) 
	{
		# start prefix compression in async mode	
		if ( $enable_exec )
		{
			# launch via exec()	
			execInBackground("php " . __FILE__ . " index_id=$index_id process_number=$x");
		}
		else
		{
			# launch via async curl
			$url_to_exec = "http://localhost" . str_replace($document_root, "", __FILE__ ) . "?index_id=$index_id&process_number=$x";
			execWithCurl($url_to_exec);
		}
	}
}

if ( $process_number !== 0 ) 
{
	register_shutdown_function("shutdown", $index_id, $process_number);
}

try
{	
	# update current process state
	SetProcessState($index_id, $process_number, 1);
	
	if ( $process_number > 0 )
	{
		$connection = db_connection();
	}
	else
	{
		# precache index in the main process
		$connection->query("LOAD INDEX INTO CACHE PMBpretemp$index_suffix;");
	}
	
	$unbuffered_connection = db_connection(false);
	$old_unbuffered_connection = db_connection(false);
	
	$max_id = pow(2, 32);
	$scale = (int)($max_id / $dist_threads);
	$start_checksum = $process_number * $scale;

	$min_checksum = $start_checksum;
	$max_checksum = $min_checksum + $scale;
	
	$where_sql = "WHERE checksum >= $min_checksum AND checksum < $max_checksum";
	
	if ( $process_number == $dist_threads-1 ) 
	{
		# last thread ( with biggest offset ) 
		$max_checksum = $max_id;
		$where_sql = "WHERE checksum >= $min_checksum AND checksum <= $max_checksum";
	}
	
	if ( $dist_threads ===  1 ) 
	{
		$where_sql = "";
	}
		
	$combinations 			= array();
	$temp_sql 				= "";
	$s = 0;
	$x = 1;
	
	/* FOR BINARY DATA */
	for ( $i = 0 ; $i < 256 ; ++$i )
	{
		$bin_val = pack("H*", sprintf("%02x", $i));
		$hex_lookup_encode[$i] = $bin_val;
		$hex_lookup_decode[$bin_val] = $i;
	}
	
	$tokens_start = microtime(true);

	# create temporary table for this process id
	if ( $process_number > 0 ) 
	{
		$target_table = "PMBtemporary".$index_suffix."_$process_number";
		$connection->exec("DROP TABLE IF EXISTS $target_table;
						CREATE TABLE IF NOT EXISTS $target_table (
						 checksum int(10) unsigned NOT NULL,
						 tok_data mediumblob NOT NULL
						 ) ENGINE=MYISAM DEFAULT CHARSET=utf8;");	
	}
	else
	{
		$clean_slate_target = "PMBPrefixes$index_suffix";

		if ( $clean_slate ) 
		{
			$target_table = "PMBPrefixes$index_suffix";
		}
		else
		{
			# use temporary target table
			$target_table = "PMBPrefixes".$index_suffix."_temp";
			$connection->exec("DROP TABLE IF EXISTS $target_table;
						CREATE TABLE IF NOT EXISTS $target_table (
						 checksum int(10) unsigned NOT NULL,
						 tok_data mediumblob NOT NULL,
						 PRIMARY KEY(checksum)
						 ) ENGINE=INNODB $innodb_row_format_sql");
		}
	}
						
	$insert_time 			= 0;
	$statistic_total_time 	= 0;
	$write_buffer_len 		= 250;
	$flush_interval			= 40;
	$insert_counter 		= 0;

	$pdo = $unbuffered_connection->query("SELECT 
								checksum,
								(token_checksum << 6 | cutlen) as combined
								FROM PMBpretemp$index_suffix
								$where_sql
								ORDER BY checksum");							
						
	$last_row = false;
	$counter = 0;										
	$rowcounter = 0;
	
	if ( $process_number === 0 ) 
	{
		$connection->beginTransaction();
	}
	
	$oldpdo = $old_unbuffered_connection->query("SELECT * FROM PMBPrefixes$index_suffix $where_sql ORDER BY checksum");
	
	# prefetch first old row
	$oldrow = $oldpdo->fetch(PDO::FETCH_ASSOC);
	
	while ( !$last_row )
	{
		$row = $pdo->fetch(PDO::FETCH_ASSOC);
		++$rowcounter;
		++$counter;
		
		if ( $row )
		{
			$checksum 		= +$row["checksum"];
			$combined		= +$row["combined"];
		}
		else
		{
			echo "LAST ROW - counter: $counter process_number: $process_number \n";
			$last_row = true;
			
			if ( $counter === 1 ) 
			{
				break;
			}
		}
		
		# different checksum ! 
		if ( ($min_checksum > $start_checksum && $checksum !== $min_checksum) || $last_row ) 
		{
			while ( $oldrow && ($min_checksum > $oldrow["checksum"]) ) 
			{
				$temp_sql .= ",(".$oldrow["checksum"].", ".$connection->quote($oldrow["tok_data"]).")";
				++$s;
				
				if ( $s >= $write_buffer_len ) 
				{
					$insert_start = microtime(true);
					# write to disk 
					$temp_sql[0] = " ";
					$inspdo = $connection->query("INSERT INTO $target_table (checksum, tok_data) VALUES " . $temp_sql);
					$insert_time += (microtime(true)-$insert_start);
					++$insert_counter;
					
					unset($temp_sql);				
					$temp_sql 		= "";
					$s				= 0;
					
					if ( $process_number === 0 && $insert_counter >= $flush_interval )
					{
						$connection->commit();
						$connection->beginTransaction();
						$insert_counter = 0;
					}
				}
				
				# fetch new row
				$oldrow = $oldpdo->fetch(PDO::FETCH_ASSOC);
			}
			
			# if these rows are to be combined
			if ( $oldrow && $min_checksum == $oldrow["checksum"]  )
			{
				# step 1: decode the old data
				$old_combinations = VBDeltaDecode($oldrow["tok_data"], $hex_lookup_decode);
				
				# step 2: merge the data
				foreach ( $old_combinations as $old_comb ) 
				{
					$combinations[] = $old_comb;
				}
				
				# step 3: remove duplicate values
				$combinations = array_flip(array_flip($combinations));
				
				# step 4: sort combinations
				sort($combinations);
				
				# step 5: recompress
				$bin_data = DeltaVBencode($combinations, $hex_lookup_encode);
				
				$temp_sql .= ",($min_checksum, ".$connection->quote($bin_data).")";
				++$s;
				
				# fetch new oldrow
				$oldrow = $oldpdo->fetch(PDO::FETCH_ASSOC);
			}
			else
			{
				# just insert the current row, because min_checksum < $oldrow["checksum"]
				# in other terms: this is a completely new prefix
				sort($combinations);
				$bin_data = DeltaVBencode($combinations, $hex_lookup_encode);
				
				$temp_sql .= ",($min_checksum, ".$connection->quote($bin_data).")";
				++$s;
			}
			
			if ( $s >= $write_buffer_len ) 
			{
				$insert_start = microtime(true);
				# write to disk 
				$temp_sql[0] = " ";
				$inspdo = $connection->query("INSERT INTO $target_table (checksum, tok_data) VALUES " . $temp_sql);
				$insert_time += (microtime(true)-$insert_start);
				++$insert_counter;
				
				unset($temp_sql);				
				$temp_sql 		= "";
				$s				= 0;
				
				if ( $process_number === 0 && $insert_counter >= $flush_interval )
				{
					$connection->commit();
					$connection->beginTransaction();
					$insert_counter = 0;
				}
			}
			
			unset($combinations, $bin_data);
			$combinations = array();
		}
		
		$combinations[] = $combined;

		++$x;	
		
		$min_checksum 		= $checksum;
		
		if ( $rowcounter % 10000 === 0 ) 
		{
			$statistic_start = microtime(true);
			
			# check indexing permission
			$perm = $connection->query("SELECT indexing_permission FROM PMBIndexes WHERE ID = $index_id");
			$permission = $perm->fetchColumn();
					
			if ( !$permission )
			{
				if ( isset($pdo) ) $pdo->closeCursor();
				$connection->query("UPDATE PMBIndexes SET current_state = 0 WHERE ID = $index_id");
				if ( $process_number > 0 ) 
				{
					SetProcessState($index_id, $process_number, 0);
				}
				else
				{
					SetIndexingState(0, $index_id);
				}
				# no indexing permission, abort now
				die( "Indexing was requested to be terminated (again)" );
				return;
			}
			
			# update progress
			$connection->query("UPDATE PMBIndexes SET temp_loads_left = temp_loads_left + $rowcounter WHERE ID = $index_id");
			
			$statistic_total_time += microtime(true)-$statistic_start;
		}
	}
	
	echo "$rowcounter rows fetched \n";

	# rest of the old data ( if availabe ) 
	while ( $oldrow = $oldpdo->fetch(PDO::FETCH_ASSOC) )
	{
		$temp_sql .= ",(".$oldrow["checksum"].", ".$connection->quote($oldrow["tok_data"]).")";
		++$s;
				
		if ( $s >= $write_buffer_len ) 
		{
			$insert_start = microtime(true);
			# write to disk 
			$temp_sql[0] = " ";
			$inspdo = $connection->query("INSERT INTO $target_table (checksum, tok_data) VALUES " . $temp_sql);
			$insert_time += (microtime(true)-$insert_start);
			++$insert_counter;
					
			unset($temp_sql);				
			$temp_sql 		= "";
			$s				= 0;
					
			if ( $process_number === 0 && $insert_counter >= $flush_interval )
			{
				$connection->commit();
				$connection->beginTransaction();
				$insert_counter = 0;
			}
		}
	}
	
	if ( !empty($temp_sql) )
	{
		# write rest of the data
		$insert_start = microtime(true);
		$temp_sql[0] = " ";
		$inspdo = $connection->query("INSERT INTO $target_table (checksum, tok_data) VALUES " . $temp_sql);
		$insert_time += (microtime(true)-$insert_start);
		++$insert_counter;
	}
	
	if ( $process_number === 0 )
	{
		$connection->commit();
	}

}
catch ( PDOException $e ) 
{
	echo "error during PMBPrefixes ($process_number): ";
	echo $e->getMessage();
}

# wait for another processes to finish
require("process_listener.php");

$interval = microtime(true) - $timer;
echo "All prefix processes have now finished, $interval seconds elapsed, starting to transfer data... \n";


try
{
	$transfer_time_start = microtime(true);
	for ( $i = 1 ; $i < $dist_threads ; ++$i ) 
	{
		$sql = "SELECT * FROM PMBtemporary".$index_suffix."_$i";
		$temppdo = $unbuffered_connection->query($sql);
		$connection->beginTransaction();
		$w = 0;
		$ins_sql = "";
		$insert_counter = 0;
		while ( $row = $temppdo->fetch(PDO::FETCH_ASSOC) )
		{
			if ( $w >= $write_buffer_len ) 
			{
				$ins_sql[0] = " ";
				$inspdo = $connection->query("INSERT INTO $target_table (checksum, tok_data) VALUES $ins_sql");
				$ins_sql = "";
				$w = 0;
				++$insert_counter;
				
				if ( $insert_counter >= $flush_interval ) 
				{
					$connection->commit();
					$connection->beginTransaction();
					$insert_counter = 0;
				}
			}
	
			$ins_sql .= ",(".$row["checksum"].", ".$connection->quote($row["tok_data"]).")";
			++$w;	
		}
		
		$temppdo->closeCursor();
		
		# rest of the values
		if ( !empty($escape) ) 
		{
			$ins_sql[0] = " ";
			$inspdo = $connection->query("INSERT INTO $target_table (checksum, tok_data) VALUES $ins_sql");
			$ins_sql = "";
			$insert_counter = 0;
		}
		
		$connection->commit();
		$connection->query("DROP TABLE IF EXISTS PMBtemporary".$index_suffix."_$i");
	}
	$transfer_time_end = microtime(true)-$transfer_time_start;
	if ( $dist_threads > 1 ) echo "Transferring data into one table took $transfer_time_end seconds \n";
	
	if ( !$clean_slate ) 
	{
		$drop_start = microtime(true);
		$connection->beginTransaction();
		# remove the old table and rename the new one
		$connection->query("DROP TABLE $clean_slate_target");
		$connection->query("ALTER TABLE $target_table RENAME TO $clean_slate_target");
		
		$connection->commit();
		$drop_end = microtime(true) - $drop_start;
	}
}
catch ( PDOException $e ) 
{
	echo "Error during tranferring data from myisam to innodb ( process number: $i ) \n ";
	echo $e->getMessage();
}

try
{
	# remove the temporary table
	$connection->exec("DROP TABLE PMBpretemp$index_suffix");	
}
catch ( PDOException $e ) 
{
	echo "An error occurred when removing the temporary data: " . $e->getMessage() . "\n";
}

$tokens_end = microtime(true) - $tokens_start;

echo "Inserting tokens into temp tables took $token_insert_time seconds \n";
echo "Updating statistics took $statistic_total_time seconds \n";
echo "Combining temp tables took $transfer_time_end seconds \n";
if ( !$clean_slate ) echo "Switching tables took $drop_end seconds \n";
echo "Memory usage : " . memory_get_usage()/1024/1024 . " MB\n";
echo "Memory usage (peak) : " . memory_get_peak_usage()/1024/1024 . " MB\n";
echo "-------------------------------------------------\nCompressing prefix data took $tokens_end seconds \n-------------------------------------------------\n";


?>