<?php

/* Copyright (C) 2017 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@gmail.com
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

# open the process specific file
if ( !empty($mysql_data_dir) )
{
	$directory = $mysql_data_dir; # custom directory
}
else
{
	$directory = realpath(dirname(__FILE__));
}
$filepath =  $directory . "/pretemp_".$index_id."_sorted.txt";

if ( !is_readable($filepath) )
{
	echo "ERROR: $filepath is not readable, skipping prefix compressing...\n";
	return;
}

$f = fopen($filepath, "r");

$PackedIntegers = new PackedIntegers();

require "data_partitioner.php";

# launch sister processes here if multiprocessing is turned on! 
if ( $dist_threads > 1 && $process_number === 0 && empty($temp_disable_multiprocessing) && $data_size > 0 ) 
{
	# launch sister-processes
	for ( $x = 1 ; $x < $dist_threads ; ++$x ) 
	{
		# start prefix compression in async mode	
		if ( $enable_exec )
		{
			# launch via exec()	
			execInBackground("php " . __FILE__ . " index_id=$index_id process_number=$x data_partition=" . implode("-", $data_partitions[$x]));
		}
		else
		{
			# launch via async curl
			$url_to_exec = "http://localhost" . str_replace($document_root, "", __FILE__ ) . "?index_id=$index_id&process_number=$x"."&data_partition=" . implode("-", $data_partitions[$x]);
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

		# use temporary target table
		$target_table = "PMBPrefixes".$index_suffix."_temp";
		$connection->exec("DROP TABLE IF EXISTS $target_table;
						CREATE TABLE IF NOT EXISTS $target_table (
						 checksum int(10) unsigned NOT NULL,
						 tok_data mediumblob NOT NULL,
						 PRIMARY KEY(checksum)
						 ) ENGINE=INNODB $innodb_row_format_sql");
		
	}
	
	
	# if we are at the main process
	# and there is no data to compress, skip the rest of the file
	if ( $process_number === 0 && $data_size === 0 ) 
	{
		echo "Skipping prefix compression, nothing to compress! \n";
		return;
	}
						
	$insert_time 			= 0;
	$statistic_total_time 	= 0;
	$write_buffer_len 		= 250;
	$flush_interval			= 40;
	$insert_counter 		= 0;

	$last_row 		= false;
	$counter 		= 0;										
	$rowcounter 	= 0;
	$min_checksum 	= NULL;
	
	if ( $process_number === 0 ) 
	{
		$connection->beginTransaction();
	}
	
	fseek($f, $data_partition[0]);
	$maximum_checksum = (int)($data_partition[1]);
	$sql_checksum	  = (int)($data_partition[2]);
	$break_now		  = false;

	$oldpdo = $unbuffered_connection->query("SELECT * FROM PMBPrefixes$index_suffix WHERE checksum >= $sql_checksum AND checksum < $maximum_checksum ORDER BY checksum");
	
	# prefetch first old row
	$oldrow = $oldpdo->fetch(PDO::FETCH_ASSOC);
	
	while ( !$last_row )
	{
		++$rowcounter;
		++$counter;
		
		if ( $line = fgets($f) )
		{
			$p = explode(" ", trim($line));
			$checksum = $p[0]; # unpack the value later
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
		if ( ($min_checksum !== NULL && $checksum !== $min_checksum) || $last_row ) 
		{
			$unpacked_checksum = $PackedIntegers->bytes_to_int($min_checksum);
			
			while ( $oldrow && ($unpacked_checksum > $oldrow["checksum"]) ) 
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
			if ( $oldrow && $unpacked_checksum == $oldrow["checksum"]  )
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
				
				$temp_sql .= ",($unpacked_checksum, ".$connection->quote($bin_data).")";
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
				
				$temp_sql .= ",($unpacked_checksum, ".$connection->quote($bin_data).")";
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
			
			if ( $PackedIntegers->bytes_to_int($checksum) >= $maximum_checksum ) 
			{
				# end the process when checksum changes
				#$break_now = true;
				break;
			}
		}
		
		foreach ( $p as $ind => $pdata ) 
		{
			if ( $ind > 0 ) 
			{
				$combinations[] = $PackedIntegers->bytes_to_int($pdata);
			}
		}

		++$x;	
		
		$min_checksum 		= $checksum;
		
		if ( $rowcounter >= 10000 ) 
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
			$rowcounter = 0;
		}
	}

	if ( !empty($oldrow) )
	{
		$temp_sql .= ",(".$oldrow["checksum"].", ".$connection->quote($oldrow["tok_data"]).")";
	}

	$oldcount = 0;
	# rest of the old data ( if availabe ) 
	while ( $oldrow = $oldpdo->fetch(PDO::FETCH_ASSOC) )
	{
		$temp_sql .= ",(".$oldrow["checksum"].", ".$connection->quote($oldrow["tok_data"]).")";
		++$s;
		++$oldcount;
				
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

if ( isset($oldpdo) )
{
	$oldpdo->closeCursor();
	unset($oldpdo);
}

# wait for another processes to finish
require("process_listener.php");

$interval = microtime(true) - $timer;
echo "All prefix processes have now finished, $interval seconds elapsed, starting to transfer data... \n";


try
{
	if ( empty($temp_disable_multiprocessing) )
	{
		$transfer_time_start = microtime(true);
		for ( $i = 1 ; $i < $dist_threads ; ++$i ) 
		{
			$temppdo = $unbuffered_connection->query("SELECT * FROM PMBtemporary".$index_suffix."_$i");
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
			unset($temppdo);

			# rest of the values
			if ( !empty($ins_sql) ) 
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
	}
	
	
	$drop_start = microtime(true);
	$connection->beginTransaction();
	# remove the old table and rename the new one
	$connection->query("DROP TABLE $clean_slate_target");
	$connection->query("ALTER TABLE $target_table RENAME TO $clean_slate_target");
		
	$connection->commit();
	$drop_end = microtime(true) - $drop_start;
	
}
catch ( PDOException $e ) 
{
	echo "Error during tranferring data from myisam to innodb ( process number: $i ) \n ";
	echo $e->getMessage();
}

$tokens_end = microtime(true) - $tokens_start;

# remove the temporary file
if ( !empty($mysql_data_dir) )
{
	$directory = $mysql_data_dir; # custom directory
}
else
{
	$directory = realpath(dirname(__FILE__));
}
$filepath =  $directory . "/pretemp_".$index_id."_sorted.txt";
@unlink($filepath);

echo "Inserting tokens into temp tables took $token_insert_time seconds \n";
echo "Updating statistics took $statistic_total_time seconds \n";
if ( !empty($transfer_time_end) ) echo "Combining temp tables took $transfer_time_end seconds \n";
if ( !$clean_slate ) echo "Switching tables took $drop_end seconds \n";
echo "Memory usage : " . memory_get_usage()/1024/1024 . " MB\n";
echo "Memory usage (peak) : " . memory_get_peak_usage()/1024/1024 . " MB\n";
echo "-------------------------------------------------\nCompressing prefix data took $tokens_end seconds \n-------------------------------------------------\n";


?>