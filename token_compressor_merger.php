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
	$old_unbuffered_connection = db_connection(false);
	
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

	$where_sql = "WHERE checksum >= $min_checksum AND checksum < $max_checksum";
	#$modulus_condition = "checksum < $max_checksum";

	if ( $process_number == $dist_threads-1 ) 
	{
		# last thread ( with biggest offset ) 
		$max_checksum = $max_id;
		$where_sql = "WHERE checksum >= $min_checksum AND checksum <= $max_checksum";
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
						 ID mediumint(10) unsigned NOT NULL,
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
						 ID mediumint(10) unsigned NOT NULL,
						 doc_ids mediumblob NOT NULL,
						 PRIMARY KEY(checksum, token)
						 ) ENGINE=INNODB DEFAULT CHARSET=utf8;");
		}
	}
	
	echo "Target table: $target_table process_id: $process_number \n";

	if ( $dist_threads === 1 ) 
	{
		$where_sql = "";
	}
	
	# for fetching data
	$datatemp_limit = 10000;
	$sql_query_count		= 0;
	$sql_query_limit		= ceil($scale / $datatemp_limit);

	$rows = 0;
	$write_buffer_len = 250;
	$flush_interval	= 	40;
	$insert_counter =    0;
	$w = 0;
	$insert_sql 	= array();
	$insert_escape 	= array();

	$min_checksum = 0;
	$min_tok_id = 0;
	$min_doc_id = 0;
	$min_tok_2_id = 0;
	
	$doc_ids 	= array();
	$countarray = array();
	$sentiscore = array();
	
	if ( SPL_EXISTS )
	{
		$pdo = $connection->query("SELECT MAX(ID) FROM PMBtoktemp$index_suffix");
		if ( $values = $pdo->fetchColumn() )
		{
			$token_ids = new SplFixedArray($values+1);
		}
	}
	
	/* Generate [token_id] => token pairs */ 
	$tokstart = microtime(true);
	$tokpdo = $unbuffered_connection->query("SELECT ID, token FROM PMBtoktemp$index_suffix $where_sql");
	
	while ( $row = $tokpdo->fetch(PDO::FETCH_ASSOC) )
	{
		$token_ids[(int)$row["ID"]] = $row["token"];
	}

	$tokend = microtime(true) - $tokstart;	
	$token_insert_time = 0;
	$statistic_total_time = 0;
	$select_time = 0;
	
	/* FOR BINARY DATA */
	$bin_separator = pack("H*", "80");
	for ( $i = 0 ; $i < 256 ; ++$i )
	{
		$bin_val = pack("H*", sprintf("%02x", $i));
		$hex_lookup_encode[$i] = $bin_val;
		$hex_lookup_decode[$bin_val] = $i;
	}
	
	$senti_sql_index_column = "";
	if ( $sentiment_analysis )
	{
		$senti_sql_index_column = ",sentiscore";
	}

	$select_start = microtime(true);
	$subpdo = $unbuffered_connection->query("SELECT checksum,
											token_id,
											doc_id, 
											token_id_2,
											count,
											((token_id_2 << $number_of_fields) | field_id) as combined
											".$senti_sql_index_column."
											FROM PMBdatatemp$index_suffix
											$where_sql
											ORDER BY checksum, token_id, doc_id, token_id_2");
	$select_time += (microtime(true)-$select_start);
	++$sql_query_count;
	
	$last_row = false;
	$counter = 0;
	
	if ( $process_number === 0 ) 
	{
		$connection->beginTransaction();
	}
	
	$combinations = 0;
	$oldreads = 1;
	$oldpdo = $old_unbuffered_connection->query("SELECT * FROM PMBTokens$index_suffix $where_sql ORDER BY checksum");
	
	# prefetch first old row
	$oldrow = $oldpdo->fetch(PDO::FETCH_ASSOC);

	while ( !$last_row )
	{
		++$counter;
		
		$row = $subpdo->fetch(PDO::FETCH_ASSOC);
		
		if ( $row )
		{
			$checksum 	= (int)$row["checksum"];
			$token_id 	= (int)$row["token_id"];
			$doc_id 	= (int)$row["doc_id"];
			$token_id_2 = (int)$row["token_id_2"];
			$combined	= (int)$row["combined"];
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

		# token_id changes now ! 
		if ( ($min_checksum > $start_checksum && $token_id !== $min_tok_id) || $last_row ) 
		{
			ksort($doc_ids);
			# document ids should be in ascending order
			$doc_id_string = "";
			$token_data_string = "";
			$last_string = false;
			$temp_doc_ids = array();
			
			foreach ( $doc_ids as $d_id => $data_value )
			{	
				$temp_doc_ids[] = $d_id;
			
				if ( $sentiment_analysis )
				{
					if ( $sentiscore[$d_id] < -128 ) 
					{
						$sentiscore[$d_id] = -128;
					}
					else if ( $sentiscore[$d_id] > 127 )
					{
						$sentiscore[$d_id] = 127;
					}
			
					# combine the count of tokens and sentiment score into one number
					$integer = ($countarray[$d_id] << 8) | ($sentiscore[$d_id]+128);
				}
				else
				{
					$integer = $countarray[$d_id];
				}
				
				# then vbencode the value here
				$temp_string = "";
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

				# sort / compress token hits for current document id ( d_id ) here
				asort($doc_ids[$d_id]);	
				$delta = 1;
				
				foreach ( $doc_ids[$d_id] as $datavalue )
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
				
				if ( $temp_string === $last_string ) 
				{
					# no need to store this string :)
					$temp_string = "";
				}
				else
				{
					$last_string = $temp_string;
				}
				
				$token_data_string .= $bin_separator . $temp_string;
			}

			# combine data making sure that the ids match !  
			$checksum_lookup[$min_tok_id] = $min_checksum;

			while ( $oldrow && ($min_checksum > $oldrow["checksum"]) ) # || ($min_checksum == $oldrow["checksum"] && $min_tok_id != $oldrow["ID"] )
			{
				$insert_sql[] 	 = "(?,?,?,?,?)";
				$insert_escape[] = (int)$oldrow["checksum"];
				$insert_escape[] = 		$oldrow["token"];
				$insert_escape[] = (int)$oldrow["doc_matches"];
				$insert_escape[] = (int)$oldrow["ID"];
				$insert_escape[] = 		$oldrow["doc_ids"];
				++$x;
				++$w;
				
				if ( $w >= $write_buffer_len )
				{				
					$token_insert_time_start = microtime(true);
					$ins = $connection->prepare("INSERT INTO $target_table (checksum, token, doc_matches, ID, doc_ids) VALUES " . implode(",", $insert_sql) );
					$ins->execute($insert_escape);
					$token_insert_time += (microtime(true)-$token_insert_time_start);
					$w = 0;
					++$insert_counter;
					
					# reset write buffer
					unset($insert_sql, $insert_escape);
					$insert_sql = array();
					$insert_escape = array();	
					
					if ( $process_number === 0 && $insert_counter >= $flush_interval ) 
					{
						$connection->commit();
						$connection->beginTransaction();
						$insert_counter = 0;
					}
				}
					
				# fetch new oldrow
				$oldrow = $oldpdo->fetch(PDO::FETCH_ASSOC);
				++$oldreads;	
			}
			
			# if these rows are to be combined
			if ( $oldrow && $min_checksum == $oldrow["checksum"]  )
			{
				if ( $min_tok_id == $oldrow["ID"] ) # && $min_tok_id == $oldrow["ID"]
				{
					# combine data !
					$pos = strpos($oldrow["doc_ids"], $bin_separator);
					$old_doc_ids = substr($oldrow["doc_ids"], 0, $pos);
						
					$max_doc_id = VBDeltaStringMaxValue($old_doc_ids, $hex_lookup_decode);
					$new_doc_id_string = DeltaVBencode($temp_doc_ids, $hex_lookup_encode, $max_doc_id);
						
					$combined_data = $old_doc_ids . $new_doc_id_string . substr($oldrow["doc_ids"], $pos) . $token_data_string; 
					
					# add data into write buffer
					$insert_sql[] 	 = "(?,?,?,?,?)";
					$insert_escape[] = (int)$oldrow["checksum"];
					$insert_escape[] = 		$oldrow["token"];
					$insert_escape[] = (int)$oldrow["doc_matches"] + count($doc_ids);
					$insert_escape[] = (int)$oldrow["ID"];
					$insert_escape[] = 		$combined_data;
					++$combinations;
					++$x;
					++$w;
					
					# fetch new oldrow
					$oldrow = $oldpdo->fetch(PDO::FETCH_ASSOC);
					++$oldreads;	
				}
				else
				{
					$oldrow_copy = $oldrow;
					# fetch next oldrow from database
					$oldrow = $oldpdo->fetch(PDO::FETCH_ASSOC); 
					++$oldreads;
					
					if ( $oldrow["checksum"] == $min_checksum && $oldrow["ID"] == $min_tok_id )
					{
						# combine old data with new data !
						$pos = strpos($oldrow["doc_ids"], $bin_separator);
						$old_doc_ids = substr($oldrow["doc_ids"], 0, $pos);
						
						$max_doc_id = VBDeltaStringMaxValue($old_doc_ids, $hex_lookup_decode);
						$new_doc_id_string = DeltaVBencode($temp_doc_ids, $hex_lookup_encode, $max_doc_id);
						
						$combined_data = $old_doc_ids . $new_doc_id_string . substr($oldrow["doc_ids"], $pos) . $token_data_string; 
						
						# add data into write buffer
						$insert_sql[] 	 = "(?,?,?,?,?)";
						$insert_escape[] = (int)$oldrow["checksum"];
						$insert_escape[] = 		$oldrow["token"];
						$insert_escape[] = (int)$oldrow["doc_matches"] + count($doc_ids);
						$insert_escape[] = (int)$oldrow["ID"];
						$insert_escape[] = 		$combined_data;
						++$combinations;
						++$x;
						++$w;
						
						echo "RESOLVED: OLD: checksum ".$oldrow["checksum"]." min_tok_id: ".$oldrow["ID"]." \n\n";
						
						$oldrow = $oldrow_copy; # this row is not yet inserted 
					}
					else
					{
						# if old checksum > $min_checksum
						# add current data into database and then the oldrow copy
						if ( $oldrow["checksum"] > $min_checksum ) 
						{
							# add current new row first into the write buffer
							$insert_sql[] 	 = "(?,?,?,?,?)";
							$insert_escape[] = crc32($token_ids[$min_tok_id]);
							$insert_escape[] = $token_ids[$min_tok_id];
							$insert_escape[] = count($doc_ids);
							$insert_escape[] = $min_tok_id;
							$insert_escape[] = $doc_id_string . $token_data_string;
							++$x;
							++$w;
							
							# add oldrow copy immediately after current new row
							$insert_sql[] 	 = "(?,?,?,?,?)";
							$insert_escape[] = (int)$oldrow_copy["checksum"];
							$insert_escape[] = 		$oldrow_copy["token"];
							$insert_escape[] = (int)$oldrow_copy["doc_matches"];
							$insert_escape[] = (int)$oldrow_copy["ID"];
							$insert_escape[] = 		$oldrow_copy["doc_ids"];
							++$x;
							++$w;
							echo "RESOLVED: added current row and olrow_copy \n\n";
							echo "current checksum: $min_checksum current min_tok_id: $min_tok_id \n";
							echo "oldrow copy: ".$oldrow_copy["checksum"]." min_tok_id: ".$oldrow_copy["ID"]." \n\n";
							
						}
						else
						{
							echo "NEW: checksum: $min_checksum min_tok_id: $min_tok_id \n";
							echo "OLD: checksum: ".$oldrow["checksum"]." min_tok_id: ".$oldrow["ID"]." \n\n";
						}
					}
					
					unset($oldrow_copy);
				}
			}
			else # just insert the current row, because min_checksum < $oldrow["checksum"]
			{
				if ( !isset($token_ids[$min_tok_id]) )
				{
					echo "not set: $min_tok_id in token_ids counter: $counter \n process_number: $process_number \n";
				}
				else
				{
					# add current new row first into the write buffer
					$insert_sql[] 	 = "(?,?,?,?,?)";
					$insert_escape[] = crc32($token_ids[$min_tok_id]);
					$insert_escape[] = $token_ids[$min_tok_id];
					$insert_escape[] = count($doc_ids);
					$insert_escape[] = $min_tok_id;
					$insert_escape[] = $doc_id_string . $token_data_string;
					++$x;
					++$w;
				}
			}
			
			# free some memory
			unset($doc_id_string, $token_data_string, $temp_string, $temp_doc_ids);
	
			
			if ( $w >= $write_buffer_len )
			{				
				$token_insert_time_start = microtime(true);
				$ins = $connection->prepare("INSERT INTO $target_table (checksum, token, doc_matches, ID, doc_ids) VALUES " . implode(",", $insert_sql) );
				$ins->execute($insert_escape);
				$token_insert_time += (microtime(true)-$token_insert_time_start);
				$w = 0;
				++$insert_counter;
				
				# reset write buffer
				unset($insert_sql, $insert_escape);
				$insert_sql = array();
				$insert_escape = array();	
				
				if ( $process_number === 0 && $insert_counter >= $flush_interval ) 
				{
					$connection->commit();
					$connection->beginTransaction();
					$insert_counter = 0;
				}
			}

			# reset temporary variables
			unset($doc_ids, $countarray, $sentiscore);
			$doc_ids 	= array();
			$countarray = array();
			$sentiscore = array();
		}

		if ( empty($doc_ids[$doc_id]) ) $doc_ids[$doc_id] = array();
		if ( empty($countarray[$doc_id]) ) $countarray[$doc_id] = 0;		
		$doc_ids[$doc_id][] = $combined;
		$countarray[$doc_id] += (int)$row["count"];
		
		if ( $sentiment_analysis ) 
		{
			if ( empty($sentiscore[$doc_id]) ) $sentiscore[$doc_id] = 0;
			$sentiscore[$doc_id] += (int)$row["sentiscore"];
		}
		
		# gather and write data
		$min_checksum 	= $checksum;
		$min_tok_id 	= $token_id;
		$min_doc_id 	= $doc_id;
		$min_tok_2_id 	= $token_id_2;	
		
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
				$oldpdo->closeCursor();
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
	if ( !empty($insert_escape) )
	{
		$token_insert_time_start = microtime(true);
		$ins = $connection->prepare("INSERT INTO $target_table (checksum, token, doc_matches, ID, doc_ids) VALUES " . implode(",", $insert_sql) );
		$ins->execute($insert_escape);
		$token_insert_time += (microtime(true)-$token_insert_time_start);
		
		# reset write buffer
		unset($insert_sql, $insert_escape);
		$insert_counter = 0;
	}
	
	$latent_oldreads = 0;
	# fetch rest of the old tokens
	while ( $oldrow = $oldpdo->fetch(PDO::FETCH_ASSOC) )
	{
		$insert_sql[] 	 = "(?,?,?,?,?)";
		$insert_escape[] = (int)$oldrow["checksum"];
		$insert_escape[] = 		$oldrow["token"];
		$insert_escape[] = (int)$oldrow["doc_matches"];
		$insert_escape[] = (int)$oldrow["ID"];
		$insert_escape[] = 		$oldrow["doc_ids"];
		++$x;
		++$w;
		
		if ( $w >= $write_buffer_len )
		{				
			$token_insert_time_start = microtime(true);
			$ins = $connection->prepare("INSERT INTO $target_table (checksum, token, doc_matches, ID, doc_ids) VALUES " . implode(",", $insert_sql) );
			$ins->execute($insert_escape);
			$token_insert_time += (microtime(true)-$token_insert_time_start);
			$w = 0;
			++$insert_counter;
			unset($insert_sql, $insert_escape);
			$insert_sql = array();
			$insert_escape = array();
		}

		++$latent_oldreads;
		++$oldreads;	
	}
	
	# remnants of the write buffer
	if ( !empty($insert_escape) )
	{
		$token_insert_time_start = microtime(true);
		$ins = $connection->prepare("INSERT INTO $target_table (checksum, token, doc_matches, ID, doc_ids) VALUES " . implode(",", $insert_sql) );
		$ins->execute($insert_escape);
		$token_insert_time += (microtime(true)-$token_insert_time_start);
		
		# reset write buffer
		unset($insert_sql, $insert_escape);
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

# mainpdo is not needed anymore
if ( isset($oldpdo) )
{
	$oldpdo->closeCursor();
	unset($oldpdo);
}

# wait for another processes to finish
require("process_listener.php");

$interval = microtime(true) - $timer;
echo "All token processes have now finished, $interval seconds elapsed, starting to transfer data... \n";
echo "Oldreads: $oldreads \n";
echo "Latent oldreads: $latent_oldreads \n";
try
{
	#$oldpdo = $old_unbuffered_connection->query("SELECT * FROM PMBTokens$index_suffix");
	#$oldrow = $oldpdo->fetch(PDO::FETCH_ASSOC);

	$transfer_time_start = microtime(true);
	for ( $i = 1 ; $i < $dist_threads ; ++$i ) 
	{
		$sql = "SELECT * FROM PMBtemporary".$index_suffix."_$i";
		$temppdo = $unbuffered_connection->query($sql);
		$connection->beginTransaction();
		$w = 0;
		$insert_counter = 0;
		while ( $row = $temppdo->fetch(PDO::FETCH_ASSOC) )
		{
			if ( $w >= $write_buffer_len ) 
			{
				$inspdo = $connection->prepare("INSERT INTO $target_table ( checksum, token, doc_matches, ID, doc_ids ) VALUES " . implode(",", $ins_sql) );
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
	
			$ins_sql[] = "(?, ?, ?, ?, ?)";
			$escape[] = $row["checksum"];
			$escape[] = $row["token"];
			$escape[] = $row["doc_matches"];
			$escape[] = $row["ID"];
			$escape[] = $row["doc_ids"];
			++$w;	
		}
		
		$temppdo->closeCursor();
		
		# rest of the values
		if ( !empty($escape) ) 
		{
			$inspdo = $connection->prepare("INSERT INTO $target_table ( checksum, token, doc_matches, ID, doc_ids ) VALUES " . implode(",", $ins_sql) );
			$inspdo->execute($escape);
			$ins_sql = array();
			$escape = array();
			$insert_counter = 0;
		}
		
		$connection->commit();
		#$connection->query("DROP TABLE IF EXISTS PMBtemporary".$index_suffix."_$i");
	}
	$transfer_time_end = microtime(true)-$transfer_time_start;
	if ( $dist_threads > 1 ) echo "Transferring token data into one table took $transfer_time_end seconds \n";
		
	#$transfer_time_start = microtime(true);
	#$connection->query("ALTER TABLE $target_table ADD PRIMARY KEY(checksum, token), ENGINE=INNODB $innodb_row_format_sql"); # ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=16 doc_matches, ID
	#$conversion_time = microtime(true)-$transfer_time_start;
	#echo "Converting tokens-table to INNODB took $transfer_time_end seconds \n";
	
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
	echo "Error during table merging: table number: $i \n ";
	echo $e->getMessage();
}



$tokens_end = microtime(true) - $tokens_start;

echo "\nSelecting data from database took $select_time seconds \n";
echo "Copying relevant dictionary into RAM took $tokend seconds \n";
echo "Inserting tokens into temp tables took $token_insert_time seconds \n";
echo "Updating statistics took $statistic_total_time seconds \n";
echo "Combining temp tables took $transfer_time_end seconds \n";
if ( !$clean_slate ) echo "Switching tables took $drop_end seconds \n";
echo "Memory usage : " . memory_get_usage()/1024/1024 . " MB\n";
echo "Memory usage (peak) : " . memory_get_peak_usage()/1024/1024 . " MB\n";
echo "------------------------------------------------\nCompressing token data took $tokens_end seconds \n------------------------------------------------\n\nWaiting for prefixes...";










?>