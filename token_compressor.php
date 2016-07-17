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
	
	$unbuffered_connection = db_connection(false);
	
	# for keeping tabs on progress
	$total_rows = 0;
	$progress = 0;

	$tokens_start = microtime(true);
	
	$x = 0;
	
	$max_id = pow(2, 32);
	$scale = (int)($max_id / $dist_threads);
	$start_checksum = $process_number * $scale;

	$min_checksum = $start_checksum;
	$max_checksum = $min_checksum + $scale;

	$where_sql = "WHERE PMBdatatemp".$index_suffix.".checksum >= $min_checksum AND PMBdatatemp".$index_suffix.".checksum < $max_checksum";

	if ( $process_number == $dist_threads-1 ) 
	{
		# last thread ( with biggest offset ) 
		$max_checksum = $max_id;
		$where_sql = "WHERE PMBdatatemp".$index_suffix.".checksum >= $min_checksum AND PMBdatatemp".$index_suffix.".checksum <= $max_checksum";
	}

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
		$clean_slate_target = "PMBTokens$index_suffix";

		if ( $clean_slate ) 
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

	if ( $dist_threads === 1 ) 
	{
		$where_sql = "";
	}
	
	# for fetching data
	$rows = 0;
	$write_buffer_len = 250;  
	$flush_interval	= 	40;
	$insert_counter =    0;
	$w = 0;
	$insert_sql = "";
	
	$min_checksum = 0;
	$min_token = 0;
	$min_doc_id = 0;

	$token_insert_time = 0;
	$statistic_total_time = 0;
	
	/* FOR BINARY DATA */
	$bin_separator = pack("H*", "80");
	for ( $i = 0 ; $i < 256 ; ++$i )
	{
		$bin_val = pack("H*", sprintf("%02x", $i));
		$hex_lookup_encode[$i] = $bin_val;
	}
	
	$senti_sql_index_column = "";
	if ( $sentiment_analysis )
	{
		$senti_sql_index_column = ",sentiscore";
	}
	
	# get initial memory usage
	$write_buffer_size	  = 20*1024*1024;
	$initial_memory_usage = memory_get_usage();
	$memory_usage_limit	  = $initial_memory_usage + $write_buffer_size;
	
	$subpdo = $unbuffered_connection->query("SELECT 
											PMBdatatemp".$index_suffix.".checksum,
											D.token,
											doc_id, 
											field_pos as combined
											".$senti_sql_index_column."
											FROM PMBdatatemp".$index_suffix."
											STRAIGHT_JOIN PMBtoktemp".$index_suffix." D ON (D.checksum = PMBdatatemp".$index_suffix.".checksum AND D.minichecksum = PMBdatatemp".$index_suffix.".minichecksum)
											$where_sql
											ORDER BY PMBdatatemp".$index_suffix.".checksum, PMBdatatemp".$index_suffix.".minichecksum, doc_id, field_pos");
	
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

	while ( !$last_row )
	{
		++$counter;
		
		$row = $subpdo->fetch(PDO::FETCH_ASSOC);
		
		if ( $row )
		{
			$checksum 	= +$row["checksum"];
			$doc_id 	= +$row["doc_id"];
			$token		= $row["token"];
			$combined	= +$row["combined"];
		}
		else
		{
			$last_row = true;
		}
		
		# document has changed => compress old data
		if ( ($min_checksum > $start_checksum && ($doc_id !== $min_doc_id || $token !== $min_token)) || $last_row ) 
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
			$m_delta = $min_doc_id;
			
			/* VBencode the sentiment score here */
			$temp_string = "";
			if ( $sentiment_analysis )
			{
				if ( $document_senti_score < -128 ) 
				{
					$document_senti_score = -128;
				}
				else if ( $document_senti_score > 127 )
				{
					$document_senti_score = 127;
				}
		
				# combine the count of tokens and sentiment score into one number
				$integer = $document_senti_score+128;
				
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
			$document_senti_score 	= 0;
			$token_match_data 		= array();
		}

		# token_id changes now ! 
		if ( ($min_checksum > $start_checksum && $token !== $min_token) || $last_row ) 
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
		}
		
		$token_match_data[] = $combined;

		if ( $sentiment_analysis ) 
		{
			$document_senti_score += +$row["sentiscore"];
		}
		
		# gather and write data
		$min_checksum 	= $checksum;
		$min_doc_id 	= $doc_id;
		$min_token		= $token;
		
		# check premissions every 10000th row
		if ( $counter % 10000 === 0 ) 
		{
			$statistic_start = microtime(true);
			# check indexing permission
			$perm = $connection->query("SELECT indexing_permission FROM PMBIndexes WHERE ID = $index_id");
			$permission = $perm->fetchColumn();
					
			if ( !$permission )
			{
				$subpdo->closeCursor();
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
	echo "Error during PMBTokens ($process_number) : \n";
	echo $e->getMessage();	
}

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
if ( isset($subpdo) )
{
	$subpdo->closeCursor();
	unset($subpdo);
}

unset($row, $oldrow);

# wait for another processes to finish
require("process_listener.php");

$interval = microtime(true) - $timer;
echo "All token processes have now finished, $interval seconds elapsed, starting to transfer data... \n";

try
{
	# remove the temporary table
	$connection->exec("DROP TABLE PMBdatatemp$index_suffix");	
}
catch ( PDOException $e ) 
{
	echo "An error occurred when removing the temporary data: " . $e->getMessage() . "\n";
}


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
	$transfer_time_end = microtime(true)-$transfer_time_start;
	if ( $dist_threads > 1 ) echo "Transferring token data into one table took $transfer_time_end seconds \n";

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
	echo "Error during tranferring data from myisam to innodb \n ";
	echo $e->getMessage();
}

$tokens_end = microtime(true) - $tokens_start;

echo "Inserting tokens into temp tables took $token_insert_time seconds \n";
echo "Updating statistics took $statistic_total_time seconds \n";
echo "Combining temp tables took $transfer_time_end seconds \n";
if ( !$clean_slate ) echo "Switching tables took $drop_end seconds \n";
echo "Memory usage : " . memory_get_usage()/1024/1024 . " MB\n";
echo "Memory usage (peak) : " . memory_get_peak_usage()/1024/1024 . " MB\n";
echo "------------------------------------------------\nCompressing token data took $tokens_end seconds \n------------------------------------------------\n\nWaiting for prefixes...";










?>