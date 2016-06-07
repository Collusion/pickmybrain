<?php

/* Copyright (C) 2016 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@pickmybra.in
 * or visit: http://www.pickmybra.in
 */

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
	
	# find out which tokens are already prefixes
	$countpdo = $connection->query("SELECT MAX(ID) FROM PMBTokens$index_suffix");
	$min_dictionary_id = $countpdo->fetchColumn();
	
	$temppdo = $connection->query("SELECT MAX(ID) FROM PMBtoktemp$index_suffix");
	$max_temp_id = $temppdo->fetchColumn();
	
	if ( !$min_dictionary_id ) 
	{
		$min_dictionary_id = 0;
	}
	else
	{
		++$min_dictionary_id;
	}
	
	# if we have encountered (enough) new tokens disable indexes
	if ( $max_temp_id > $min_dictionary_id + 1000 ) 
	{
		$connection->query("ALTER TABLE PMBpretemp$index_suffix DISABLE KEYS;");
	}
	else
	{
		echo "No need to disable keys...\n";
	}
	
	if ( $max_temp_id == $min_dictionary_id )
	{
		echo "No new prefixes to create ...\n";
	}
	else
	{
		echo "Starting prefix creation at token offset $min_dictionary_id (max token id: $max_temp_id)\n";
	}
	
	$countpdo = $connection->query("SELECT COUNT(ID) FROM PMBtoktemp$index_suffix");
	$tokcount = $countpdo->fetchColumn();

	$total_insert_time = 0;
	$scale = (int)($tokcount / $dist_threads);
	$start_id = $process_number * $scale + $min_dictionary_id;
	$min_id = $start_id;
	$max_id = $min_id + $scale;
	
	$where_sql = "WHERE ID >= $min_id AND ID < $max_id";

	if ( $process_number == $dist_threads-1 ) 
	{
		# last thread ( with biggest offset ) 
		$max_id = pow(2, 32); # just to be sure 
		$where_sql = "WHERE ID >= $min_id AND ID <= $max_id";
	}
	
	if ( $dist_threads > 1 ) 
	{
		$and_condition = " AND ";
	}
	else
	{
		$and_condition = " ";
		$where_sql = "";
	}

	$maximum_prefix_len 	= 20;

	$pretemp_limit 			= 20000;
	$sql_query_count		= 0;
	$sql_query_limit		= ceil($scale / $pretemp_limit);
	$insert_prefix_sql 		= "";
	$prefix_escape 			= array();
	
	$bytes_of_data 			= 0;
	$total_bytes_of_data 	= 0;
	$max_bytes				= 1024*1024*2;
	$s = 0;
	$x = 1;
	$pr = 0;
	$log = "";
	$loop_log = "";
	$token_total_count = 0;
	$prefix_total_count = 0;
	
	$tokens_start = microtime(true);
				
	$select_time = 0;
	$insert_time = 0;
	$statistic_total_time = 0;
	
	# create an another connection 
	$unbuffered_connection = db_connection(false);

	$pdo = $unbuffered_connection->query("SELECT token FROM PMBtoktemp$index_suffix $where_sql");
	
	while ( $row = $pdo->fetch(PDO::FETCH_ASSOC) ) 
	{	
		++$token_total_count;
		$token = $row["token"];
	
		# dialect processing: remove dialect ( ä, ö, å + etc) from tokens and add as prefix
		if ( !empty($dialect_find) )
		{
			$nodialect = str_replace($dialect_find, $dialect_replace, $token);
			if ( $nodialect !== $token ) 
			{
				if ( $pr > 0 ) $insert_prefix_sql .= ",";
					
				$insert_prefix_sql 		.= "(?,?,?)";
				$prefix_escape[] 		= crc32($nodialect);		 		# prefix crc32 value
				$prefix_escape[]		= crc32($token);   				 	# original token crc32 checksum
				$prefix_escape[] 		= 0;			 			  	 	# how many characters have been cut of from the original word 
				++$pr;
			}
		}
		
		# if prefix_mode > 0, prefixing is enabled
		if ( $prefix_mode ) 
		{
			$min_prefix_len = $prefix_length;
			$wordlen = mb_strlen($token);

			if ( $wordlen > $min_prefix_len ) 
			{
				if ( $prefix_mode === 3 ) 
				{
					# infixes
					for ( $i = $wordlen-1 ; $i >= $min_prefix_len ; --$i ) 
					{
						for ( $j = 0 ; ($i + $j) <= $wordlen ; ++$j )
						{
							$prefix_word = mb_substr($token, $j, $i);

							if ( $pr > 0 ) $insert_prefix_sql .= ",";
					
							$insert_prefix_sql 		.= "(?,?,?)";
							$prefix_escape[] 		= crc32($prefix_word);		 		# prefix crc32 value
							$prefix_escape[]		= crc32($token);   				 	# original token crc32 checksum
							$prefix_escape[] 		= $wordlen-$i;			 			# how many characters have been cut of from the original word 
							++$pr;
						}
					}
				}
				else if ( $prefix_mode === 2 )
				{
					# prefixes and postfixes
					# prefix
					for ( $i = $wordlen-1 ; $i >= $min_prefix_len ; --$i )
					{
						$prefix_word = mb_substr($token, 0, $i);

						if ( $pr > 0 ) $insert_prefix_sql .= ",";
					
						$insert_prefix_sql 		.= "(?,?,?)";
						$prefix_escape[] 		= crc32($prefix_word);		 		# prefix crc32 value
						$prefix_escape[]		= crc32($token);   				 	# original token crc32 checksum
						$prefix_escape[] 		= $wordlen-$i;			 			# how many characters have been cut of from the original word 
						++$pr;
					}
					
					# postfix
					for ( $i = 1 ; $wordlen-$i >= $min_prefix_len ; ++$i )
					{
						$prefix_word = mb_substr($token, $i);

						if ( $pr > 0 ) $insert_prefix_sql .= ",";
					
						$insert_prefix_sql 		.= "(?,?,?)";
						$prefix_escape[] 		= crc32($prefix_word);		 		# prefix crc32 value
						$prefix_escape[]		= crc32($token);   				 	# original token crc32 checksum
						$prefix_escape[] 		= $i;			 					# how many characters have been cut of from the original word 
						++$pr;
					}
				}
				else if ( $prefix_mode === 1 )
				{
					# default: prefixes only
					for ( $i = $wordlen-1 ; $i >= $min_prefix_len ; --$i )
					{
						$prefix_word = mb_substr($token, 0, $i); 

						if ( $pr > 0 ) $insert_prefix_sql .= ",";
					
						$insert_prefix_sql 		.= "(?,?,?)";
						$prefix_escape[] 		= crc32($prefix_word);		 		# prefix crc32 value
						$prefix_escape[]		= crc32($token);   				 	# original token crc32 checksum
						$prefix_escape[] 		= $wordlen-$i;			 			# how many characters have been cut of from the original word 
						++$pr;
					}
				}
			}
		}
		
		# if we have over 2000 prefixes already, insert them here and reset 
		if ( $pr > 2000 )
		{
			$ins_start = microtime(true);
			$updpdo = $connection->prepare("INSERT INTO PMBpretemp$index_suffix (checksum, token_checksum, cutlen) VALUES $insert_prefix_sql");
			$updpdo->execute($prefix_escape);
			$total_insert_time += (microtime(true) - $ins_start);
			
			$prefix_total_count += $pr;
			# reset variables
			$insert_prefix_sql = "";
			$prefix_escape = array();
			$pr = 0;
			$log .= "prefix inserts ok \n";
			$loop_log .= "prefix inserts ok \n";
		}	
	}
	
	$pdo->closeCursor();
	unset($pdo);

	# insert rest of the prefixes, if available
	# if we have over 2000 prefixes already, insert them here and reset 
	if ( !empty($prefix_escape) )
	{
		$ins_start = microtime(true);
		$updpdo = $connection->prepare("INSERT INTO PMBpretemp$index_suffix (checksum, token_checksum, cutlen) VALUES $insert_prefix_sql");
		$updpdo->execute($prefix_escape);
		$total_insert_time += (microtime(true) - $ins_start);
		
		$prefix_total_count += $pr;	
		# reset variables
		$insert_prefix_sql = "";
		$prefix_escape = array();
		$pr = 0;
		$log .= "residual prefix inserts ok \n";
		$loop_log .= "residual prefix inserts ok \n";
	}
}
catch ( PDOException $e ) 
{
	echo "error during composing prefixes: ";
	echo $e->getMessage();
}

# wait for another processes to finish
require("process_listener.php");

$interval = microtime(true) - $timer;
echo "All prefix processes have now finished, $interval seconds elapsed, enabling keys... \n";


try
{
	# now, enable keys for the prefix table
	if ( $max_temp_id > $min_dictionary_id + 1000 ) 
	{
		$keys_start = microtime(true);
		$connection->exec("ALTER TABLE PMBpretemp$index_suffix ENABLE KEYS;");
		$keys_end = microtime(true) - $keys_start;
	}
}
catch ( PDOException $e ) 
{
}

echo "prefix composer is now finished \n";
$tokens_end = microtime(true) - $tokens_start;

echo "Memory usage : " . memory_get_usage()/1024/1024 . " MB\n";
echo "Memory usage (peak) : " . memory_get_peak_usage()/1024/1024 . " MB\n";
echo "$token_total_count tokens and $prefix_total_count prefixes processed \n";
echo "Inserting tokens into temp tables took $total_insert_time seconds \n";
if ( isset($keys_end) ) echo "Enabling keys for table took $keys_end seconds \n";
echo "-------------------------------------------------\nComposing prefixes took $tokens_end seconds \n-------------------------------------------------\n";


?>