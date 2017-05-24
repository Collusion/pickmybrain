<?php

/* Copyright (C) 2017 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@gmail.com
 * or visit: http://www.hollilla.com/pickmybrain
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
$filepath = $directory."/datatemp".$index_suffix."_sorted.txt";

if ( !is_readable($filepath) )
{
	echo "ERROR: $filepath is not readable, skipping token compressing...\n";
	return;
}

$PackedIntegers = new PackedIntegers();
	
$f = fopen($filepath, "r");

require "data_partitioner.php";

# launch sister processes here if multiprocessing is turned on! 
if ( $dist_threads > 1 && $process_number === 0 && empty($temp_disable_multiprocessing) ) 
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
	
	$unbuffered_connection = db_connection(false);
	
	# for keeping tabs on progress
	$total_rows = 0;
	$progress = 0;

	$tokens_start = microtime(true);
	$x = 0;

	# create a temporary table if this is not the main process
	if ( $process_number > 0 ) 
	{
		$target_table = "PMBtemporary".$index_suffix."_$process_number";
		$connection->exec("DROP TABLE IF EXISTS $target_table;
						CREATE TABLE IF NOT EXISTS $target_table (
						 checksum int(10) unsigned NOT NULL,
						  token varbinary(40) NOT NULL,
						  doc_matches int(8) unsigned NOT NULL,
						  doc_ids mediumblob NOT NULL
						 ) ENGINE=MYISAM DEFAULT CHARSET=utf8;");	
	}
	else
	{
		if ( empty($replace_index) && $clean_slate ) 
		{
			$target_table = "PMBTokens$index_suffix";
		}
		else
		{
			# use temporary target table
			$target_table = "PMBTokens".$index_suffix."_temp";
			$connection->exec("DROP TABLE IF EXISTS $target_table;
						CREATE TABLE IF NOT EXISTS $target_table (
						 checksum int(10) unsigned NOT NULL,
						  token varbinary(40) NOT NULL,
						  doc_matches int(8) unsigned NOT NULL,
						  doc_ids mediumblob NOT NULL,
						  PRIMARY KEY (checksum,token)
						 ) ENGINE=INNODB DEFAULT CHARSET=utf8;");
		}
	}
	
	# for fetching data
	$rows = 0;
	$write_buffer_len = 250;  
	$flush_interval	= 	40;
	$insert_counter =    0;
	$w = 0;
	$insert_sql = "";
	
	$min_checksum 	= 0;
	$min_token 		= 0;
	$min_doc_id 	= 0;

	$token_insert_time = 0;
	$statistic_total_time = 0;
	
	/* FOR BINARY DATA */
	$bin_separator = pack("H*", "80");
	for ( $i = 0 ; $i < 256 ; ++$i )
	{
		$bin_val = pack("H*", sprintf("%02x", $i));
		$hex_lookup_encode[$i] = $bin_val;
	}
	
	# get initial memory usage
	$write_buffer_size	  = 20*1024*1024;
	$initial_memory_usage = memory_get_usage();
	$memory_usage_limit	  = $initial_memory_usage + $write_buffer_size;
	
	$counter = 0;
	$last_row = false;
	
	$document_hit_count		= 0;
	$document_senti_score 	= 0;
	$document_count			= 0;
	$m_delta 				= 1; # for deltaencoding
	$doc_id_string 			= "";
	$token_data_string 		= "";
	
	if ( $process_number === 0 ) 
	{
		$connection->beginTransaction();
	}
	
	$min_token = 0;

	fseek($f, $data_partition[0]);
	$maximum_checksum = (int)($data_partition[1]>>16);
	$sql_checksum	  = (int)($data_partition[2]>>16);

	$tokpdo = $unbuffered_connection->query("SELECT ((checksum<<16)|minichecksum) as checksum, 
													token
													FROM PMBtoktemp$index_suffix 
													WHERE checksum >= $sql_checksum 
													ORDER BY checksum, minichecksum");
																								
	$tokrow = $tokpdo->fetch(PDO::FETCH_ASSOC);
	$tokrow["checksum"] = +$tokrow["checksum"];
	
	$stored_lines = array();
	
	while ( !$last_row )
	{
		if ( !empty($stored_lines) )
		{
			# if this is a web-index, reset old document id to disable delta decoding
			if ( $index_type === 1 )
			{
				$doc_id = 0;
			}
			
			foreach ( $stored_lines as $stored_id => $line )
			{
				$p = explode(" ", $line);
				$doc_id 		= $PackedIntegers->bytes_to_int($p[0])+$doc_id;
						
				if ( $sentiment_analysis ) 
				{
					$sentiscore = $PackedIntegers->bytes_to_int($p[1]);
					$r = 2;
				}
				else
				{
					$r = 1;
				}
				break;
			}
			
			unset($stored_lines[$stored_id]);
		}
		else if ( $line = fgets($f) )
		{
			$stored_lines = explode("  ", trim($line));
			
			$p = explode(" ", $stored_lines[0]);
			unset($stored_lines[0]);

			$bigchecksum 	= $PackedIntegers->bytes_to_int($p[0]);
			$checksum 		= $bigchecksum>>16;
			$doc_id 		= $PackedIntegers->bytes_to_int($p[1]);
					
			if ( $sentiment_analysis ) 
			{
				$sentiscore = $PackedIntegers->bytes_to_int($p[2]);
				$r = 3;
			}
			else
			{
				$r = 2;
			}
					
			# advance token tables rowpointer ( if necessary ) 
			while ( $tokrow["checksum"] < $bigchecksum )
			{
				#$beforevalue = $tokrow["checksum"];
				$tokrow = $tokpdo->fetch(PDO::FETCH_ASSOC);
				$tokrow["checksum"] = +$tokrow["checksum"];
			}
		
			$token 		= $tokrow["token"];	
		}
		else
		{
			$last_row = true;
		}

		# document has changed => compress old data
		if ( ($min_token !== 0 && ($doc_id !== $min_doc_id || $token !== $min_token)) || $last_row ) 
		{
			++$document_count;
			/* DeltaVBencode the document id here */
			$tmp = $min_doc_id-$m_delta+1;

			do
			{
				$lowest7bits = $tmp & 127;
				$tmp >>= 7;
					
				if ( $tmp ) 
				{
					$doc_id_string .= $hex_lookup_encode[$lowest7bits];
				}
				else
				{
					$doc_id_string .= $hex_lookup_encode[(128 | $lowest7bits)];
				}
			}
			while ( $tmp ) ;
			#$m_delta = $unpacked_doc_id;
			$m_delta = $min_doc_id;
			
			/* VBencode the sentiment score here */
			$temp_string = "";
			if ( $sentiment_analysis )
			{
				$integer = $document_senti_score;
				
				do
				{
					# get 7 LSB bits
					$lowest7bits = $integer & 127;
					
					# shift original number >> 7 
					$integer = $integer >> 7;
					
					if ( $integer ) 
					{
						# number is yet to end, prepend 0 ( or actually do nothing :)
						$temp_string .= $hex_lookup_encode[$lowest7bits];
					}
					else
					{
						# number ends here, prepend 1
						$temp_string .= $hex_lookup_encode[(128 | $lowest7bits)];
					}
				}
				while ( $integer ) ;
			}

			/* DeltaVBencode token/document match data here */
			asort($token_match_data);	
			$delta = 1;
			foreach ( $token_match_data as $datavalue )
			{
				$tmp = $datavalue-$delta+1;

				do
				{
					$lowest7bits = $tmp & 127;
					$tmp >>= 7;
					
					if ( $tmp ) 
					{
						$temp_string .= $hex_lookup_encode[$lowest7bits];
					}
					else
					{
						$temp_string .= $hex_lookup_encode[(128 | $lowest7bits)];
					}
				}
				while ( $tmp ) ;
		
				$delta = $datavalue;
			}
			$token_data_string .= $bin_separator . $temp_string;
			
			# reset variables
			unset($temp_string, $token_match_data);
			$document_hit_count 	= 0;
			$document_senti_score 	= 0;
			$token_match_data 		= array();
		}

		# token_id changes now ! 
		if ( ($min_token !== 0 && $token !== $min_token) || $last_row ) 
		{
			$insert_sql .= ",($min_checksum,".$connection->quote($min_token).",$document_count,".$connection->quote($doc_id_string . $token_data_string).")";
			++$x;
			++$w;
			
			# reset temporary variables
			unset($doc_id_string, $token_data_string, $temp_string);
			$m_delta 			= 1;
			$document_count		= 0;
			$doc_id_string 		= "";
			$token_data_string 	= "";
			
			if ( $w >= $write_buffer_len || memory_get_usage() > $memory_usage_limit )
			{
				$token_insert_time_start = microtime(true);
				$insert_sql[0] = " ";
				$ins = $connection->query("INSERT INTO $target_table (checksum, token, doc_matches, doc_ids) VALUES $insert_sql");
				$token_insert_time += (microtime(true)-$token_insert_time_start);
				$w = 0;
				++$insert_counter;
				
				# reset write buffer
				unset($insert_sql);
				$insert_sql = "";
				
				if ( $process_number === 0 && $insert_counter >= $flush_interval ) 
				{
					$connection->commit();
					$connection->beginTransaction();
					$insert_counter = 0;
				}
			}
			
			if ( $checksum >= $maximum_checksum ) 
			{
				# end the process when checksum changes
				break;
			}
		}
		
		while ( isset($p[$r]) )
		{
			$token_match_data[] = $PackedIntegers->bytes_to_int($p[$r]);
			++$r;
		}

		if ( $sentiment_analysis ) 
		{
			$document_senti_score += $sentiscore;
		}
		
		# gather and write data
		$min_checksum 	= $checksum;
		$min_doc_id 	= $doc_id;
		$min_token		= $token;
		
		++$counter;
		
		# check premissions every 10000th row
		if ( $counter % 10000 === 0 ) 
		{
			$statistic_start = microtime(true);
			# check indexing permission
			$perm = $connection->query("SELECT indexing_permission FROM PMBIndexes WHERE ID = $index_id");
			$permission = $perm->fetchColumn();
					
			if ( !$permission )
			{
				$tokpdo->closeCursor();
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
			
			# update statistics
			$connection->query("UPDATE PMBIndexes SET temp_loads_left = temp_loads_left + $counter WHERE ID = $index_id");
			$statistic_total_time = microtime(true)-$statistic_start;
			$counter = 0;
		}
	}

	# remnants of the write buffer
	if ( !empty($insert_sql) )
	{	
		$token_insert_time_start = microtime(true);
		$insert_sql[0] = " ";
		$ins = $connection->query("INSERT INTO $target_table (checksum, token, doc_matches, doc_ids) VALUES $insert_sql");
		$token_insert_time += (microtime(true)-$token_insert_time_start);
		
		# reset write buffer
		unset($insert_sql);
		$insert_counter = 0;
	}	
}
catch ( PDOException $e ) 
{
	$connection->rollBack();
	$string = "Error during PMBTokens ($process_number) : \n" . $e->getMessage() . "\n";
	
	echo $string;
	
	file_put_contents("/var/www/localsearch/errorlog_".$process_number.".txt", $string);
}


fclose($f);

try
{
	if ( $process_number === 0 ) 
	{
		$connection->commit();
	}
}
catch ( PDOException $e ) 
{
	echo "An error occurred when committing transaction " . $e->getMessage() . "\n";
}

# mainpdo is not needed anymore
if ( isset($tokpdo) )
{
	$tokpdo->closeCursor();
	unset($tokpdo);
}

unset($row, $oldrow);

# wait for another processes to finish
require("process_listener.php");

$interval = microtime(true) - $timer;
echo "All token processes have now finished, $interval seconds elapsed, starting to transfer data... \n";

try
{
	$transfer_time_start = microtime(true);
	if ( empty($temp_disable_multiprocessing) )
	{
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
				if ( $w >= $write_buffer_len || memory_get_usage() > $memory_usage_limit ) 
				{
					$ins_sql[0] = " ";
					$inspdo = $connection->query("INSERT INTO $target_table (checksum, token, doc_matches, doc_ids) VALUES $ins_sql");
					unset($ins_sql);
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
	
				$ins_sql .= ",(".$row["checksum"].",".$connection->quote($row["token"]).",".$row["doc_matches"].",".$connection->quote($row["doc_ids"]).")";
				++$w;	
			}
			
			$temppdo->closeCursor();
			unset($temppdo);
			
			# rest of the values
			if ( !empty($ins_sql) ) 
			{
				$ins_sql[0] = " ";
				$inspdo = $connection->query("INSERT INTO $target_table (checksum, token, doc_matches, doc_ids) VALUES $ins_sql");
				unset($ins_sql);
				$ins_sql = "";
				$insert_counter = 0;
			}
			
			$connection->commit();
			$connection->query("DROP TABLE IF EXISTS PMBtemporary".$index_suffix."_$i");
		}
	}
	$transfer_time_end = microtime(true)-$transfer_time_start;

}
catch ( PDOException $e ) 
{
	echo "Error during tranferring data from myisam to innodb \n ";
	echo $e->getMessage();
	die("bye now \n");
}

$tokens_end = microtime(true) - $tokens_start;

# remove the file after everything is done
if ( !empty($mysql_data_dir) )
{
	$sorted_dir = $mysql_data_dir; # custom directory
}
else
{
	$sorted_dir = realpath(dirname(__FILE__));
}
$filepath = $directory."/datatemp".$index_suffix."_sorted.txt";
@unlink($filepath);

echo "Inserting tokens into temp tables took $token_insert_time seconds \n";
echo "Updating statistics took $statistic_total_time seconds \n";
if ( $dist_threads > 1 ) echo "Combining temp tables took $transfer_time_end seconds \n";
echo "Memory usage : " . memory_get_usage()/1024/1024 . " MB\n";
echo "Memory usage (peak) : " . memory_get_peak_usage()/1024/1024 . " MB\n";
echo "------------------------------------------------\nCompressing token data took $tokens_end seconds \n------------------------------------------------\n\nWaiting for prefixes...";










?>