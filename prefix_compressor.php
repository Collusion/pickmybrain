<?php

/* Copyright (C) 2016 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@pickmybra.in
 * or visit: http://www.pickmybra.in
 */

ini_set('memory_limit', '3072M');

# check input parameters only if process number is not defined at all
# ==> this file is launched as a separate process
if ( !isset($process_number) )
{
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
	$escape					= array();
	$s = 0;
	$x = 1;
	
	/* FOR BINARY DATA */
	for ( $i = 0 ; $i < 256 ; ++$i )
	{
		$bin_val = pack("H*", sprintf("%02x", $i));
		$hex_lookup_encode[$i] = $bin_val;
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
						
	$select_time 			= 0;
	$insert_time 			= 0;
	$statistic_total_time 	= 0;
	$write_buffer_len 		= 250;
	$flush_interval			= 40;
	$insert_counter 		= 0;

	$select_start = microtime(true);
	$pdo = $unbuffered_connection->query("SELECT 
								checksum,
								(token_checksum << 6 | cutlen) as combined
								FROM PMBpretemp$index_suffix
								$where_sql
								ORDER BY checksum");		
	$select_time += (microtime(true) - $select_start);						
											
	$rowcounter = 0;
	
	if ( $process_number === 0 ) 
	{
		$connection->beginTransaction();
	}
	
	while ( $row = $pdo->fetch(PDO::FETCH_ASSOC) )
	{
		++$rowcounter;
		
		$checksum 		= (int)$row["checksum"];
		$combined		= (int)$row["combined"];
		
		# different checksum ! 
		if ( $min_checksum > $start_checksum && $checksum !== $min_checksum ) 
		{
			# sort combinations
			sort($combinations);

			$delta = 1;
			$bin_data = "";
			
			foreach ( $combinations as $c_i => $integer )
			{
				$tmp = $integer-$delta+1;
				
				do
				{
					$lowest7bits = $tmp & 127;
					$tmp >>= 7;
					
					if ( $tmp ) 
					{
						$bin_data .= $hex_lookup_encode[$lowest7bits];
					}
					else
					{
						$bin_data .= $hex_lookup_encode[(128 | $lowest7bits)];
					}
				}
				while ( $tmp ) ;
		
				$delta = $integer;
			}

			if ( $s > 0 ) $temp_sql .= ",";
			$temp_sql .= "(?, ?)";
			$escape[] = $min_checksum;
			$escape[] = $bin_data;
			++$s;

			if ( $s >= $write_buffer_len ) 
			{
				$insert_start = microtime(true);
				# write to disk 
				$inspdo = $connection->prepare("INSERT INTO $target_table (checksum, tok_data) VALUES " . $temp_sql);
				$inspdo->execute($escape);
				$insert_time += (microtime(true)-$insert_start);
				++$insert_counter;
				
				unset($temp_sql, $escape);				
				$temp_sql 		= "";
				$escape 		= array();
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
				$pdo->closeCursor();
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
				
	# after, try if there is still some data left
	if ( !empty($escape) )
	{
		# compress remaining data
		if ( !empty($combinations) )
		{
			sort($combinations);
				
			$bin_data = DeltaVBencode($combinations, $hex_lookup_encode);
								
			if ( $s > 0 ) $temp_sql .= ",";
			$temp_sql .= "(?, ?)";
			$escape[] = $min_checksum;
			$escape[] = $bin_data;
			++$s;
		}
		
		# write to disk 
		$insert_start = microtime(true);
		$inspdo = $connection->prepare("INSERT INTO $target_table (checksum, tok_data) VALUES " . $temp_sql);
		$inspdo->execute($escape);
		$insert_time += (microtime(true)-$insert_start);
	}
	
	if ( $process_number === 0 )
	{
		$connection->commit();
	}

}
catch ( PDOException $e ) 
{
	echo "error during PMBPrefixes: ";
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
		$ins_sql = array();
		$escape = array();
		$insert_counter = 0;
		while ( $row = $temppdo->fetch(PDO::FETCH_ASSOC) )
		{
			if ( $w >= $write_buffer_len ) 
			{
				$inspdo = $connection->prepare("INSERT INTO $target_table (checksum, tok_data) VALUES " . implode(",", $ins_sql) );
				$inspdo->execute($escape);
				$ins_sql = array();
				$escape = array();
				$w = 0;
				++$insert_counter;
				
				if ( $insert_counter >= $flush_interval ) 
				{
					$connection->commit();
					$connection->beginTransaction();
					$insert_counter = 0;
				}
			}
	
			$ins_sql[] = "(?, ?)";
			$escape[] = $row["checksum"];
			$escape[] = $row["tok_data"];
			++$w;	
		}
		
		$temppdo->closeCursor();
		
		# rest of the values
		if ( !empty($escape) ) 
		{
			$inspdo = $connection->prepare("INSERT INTO $target_table (checksum, tok_data) VALUES " . implode(",", $ins_sql) );
			$inspdo->execute($escape);
			$ins_sql = array();
			$escape = array();
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


#echo "PREFIX main process metrics:\ntotal select time: $select_time seconds\ntotal insert time: $insert_time\n";

$tokens_end = microtime(true) - $tokens_start;

echo "\nSelecting data from database took $select_time seconds \n";
echo "Copying relevant dictionary into RAM took $tokend seconds \n";
echo "Inserting tokens into temp tables took $token_insert_time seconds \n";
echo "Updating statistics took $statistic_total_time seconds \n";
echo "Combining temp tables took $transfer_time_end seconds \n";
if ( !$clean_slate ) echo "Switching tables took $drop_end seconds \n";
echo "Memory usage : " . memory_get_usage()/1024/1024 . " MB\n";
echo "Memory usage (peak) : " . memory_get_peak_usage()/1024/1024 . " MB\n";
echo "-------------------------------------------------\nCompressing prefix data took $tokens_end seconds \n-------------------------------------------------\n";


?>