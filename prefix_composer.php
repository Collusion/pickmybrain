<?php

/* Copyright (C) 2017 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@gmail.com
 * or visit: http://www.pickmybra.in
 */

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

# dialect processing
if ( $dialect_processing )
{
	# generate array of values to find and replace
	# for cloning tokens
	if ( !empty($dialect_replacing) )
	{
		$dialect_find = array_keys($dialect_replacing);
		$dialect_replace = array_values($dialect_replacing);
	}
	
	# for mass replacing
	if ( !empty($dialect_array) )
	{
		$mass_find = array_keys($dialect_array);
		$mass_replace = array_values($dialect_array);
	}
}

# launch sister processes here if multiprocessing is turned on! 
if ( $dist_threads > 1 && $process_number === 0  ) 
{	
	$cmd = "";
	$curl = "";
	if ( !empty($replace_index) )
	{
		$cmd = "replace";
		$curl = "&replace=1";
	}

	# launch sister-processes
	for ( $x = 1 ; $x < $dist_threads ; ++$x ) 
	{
		# start prefix compression in async mode	
		if ( $enable_exec )
		{
			# launch via exec()	
			execInBackground("php " . __FILE__ . " index_id=$index_id process_number=$x $cmd");
		}
		else
		{
			# launch via async curl
			$url_to_exec = "http://localhost" . str_replace($document_root, "", __FILE__ ) . "?index_id=$index_id&process_number=$x".$curl;
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
	$countpdo = $connection->query("SELECT COUNT(checksum) FROM PMBTokens$index_suffix");
	$min_dictionary_id = $countpdo->fetchColumn();
	
	$temppdo = $connection->query("SELECT COUNT(token) FROM PMBtoktemp$index_suffix");
	$max_temp_id = $temppdo->fetchColumn();
	
	if ( !$min_dictionary_id || !empty($replace_index) ) 
	{
		$min_dictionary_id = 0;
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

	$total_insert_time = 0;
	$scale = (int)(($max_temp_id-$min_dictionary_id) / $dist_threads); # count of tokens per process
	$start_id = $process_number * $scale + $min_dictionary_id;
	$min_id = $start_id;
	$max_id = $min_id + $scale;
	
	$where_sql = "LIMIT $start_id, $scale";

	if ( $process_number == $dist_threads-1 ) 
	{
		# last thread ( with biggest offset ) 
		$where_sql = "LIMIT $start_id, 9999999999";
	}

	if ( $dist_threads == 1 ) 
	{
		$where_sql = "LIMIT $start_id, 9999999999";
	}

	$maximum_prefix_len 	= 20;
	$insert_prefix_sql 		= "";
	
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
	
	ob_start();
	var_dump($dialect_find);
	$res = ob_get_clean();

	# create an another connection 
	$unbuffered_connection = db_connection(false);

	$pdo = $unbuffered_connection->query("SELECT token FROM PMBtoktemp$index_suffix $where_sql");
	
	while ( $row = $pdo->fetch(PDO::FETCH_ASSOC) ) 
	{	
		++$token_total_count;
		$token = $row["token"];
		$token_crc32 = crc32($token);
	
		# dialect processing: remove dialect ( ä, ö, å + etc) from tokens and add as prefix
		if ( !empty($dialect_find) )
		{
			$nodialect = str_replace($dialect_find, $dialect_replace, $token);
			if ( $nodialect !== $token ) 
			{
				# prefix crc32 value, original token crc32 checksum, how many characters have been cut of from the original word 
				$insert_prefix_sql 		.= ",(".crc32($nodialect).",$token_crc32,0)";	
				++$pr;
			}
		}
		
		# if prefix_mode > 0, prefixing is enabled
		if ( $prefix_mode ) 
		{
			$min_prefix_len = $prefix_length;
			$wordlen = mb_strlen($token);
			if ( $wordlen > $maximum_prefix_len ) 
			{
				$wordlen = $maximum_prefix_len;
			}
			
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
							# prefix crc32 value, original token crc32 checksum, how many characters have been cut of from the original word 
							$insert_prefix_sql 		.= ",(".crc32($prefix_word).",$token_crc32,".($wordlen-$i).")";
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
						# prefix crc32 value, original token crc32 checksum, how many characters have been cut of from the original word 
						$insert_prefix_sql 		.= ",(".crc32($prefix_word).",$token_crc32,".($wordlen-$i).")";
						++$pr;
					}
					
					# postfix
					for ( $i = 1 ; $wordlen-$i >= $min_prefix_len ; ++$i )
					{
						$prefix_word = mb_substr($token, $i);
						# prefix crc32 value, original token crc32 checksum, how many characters have been cut of from the original word 
						$insert_prefix_sql 		.= ",(".crc32($prefix_word).",$token_crc32,$i)";
						++$pr;
					}
				}
				else if ( $prefix_mode === 1 )
				{
					# default: prefixes only
					for ( $i = $wordlen-1 ; $i >= $min_prefix_len ; --$i )
					{
						$prefix_word = mb_substr($token, 0, $i); 
						# prefix crc32 value, original token crc32 checksum, how many characters have been cut of from the original word 
						$insert_prefix_sql 		.= ",(".crc32($prefix_word).",$token_crc32,".($wordlen-$i).")";
						++$pr;
					}
				}
			}
		}
		
		# if we have over 2000 prefixes already, insert them here and reset 
		if ( $pr > 2000 )
		{
			$insert_prefix_sql[0] = " "; # trim the first comma
			$ins_start = microtime(true);
			$updpdo = $connection->query("INSERT INTO PMBpretemp$index_suffix (checksum, token_checksum, cutlen) VALUES $insert_prefix_sql");
			$total_insert_time += (microtime(true) - $ins_start);
			
			$prefix_total_count += $pr;
			# reset variables
			$insert_prefix_sql = "";
			$pr = 0;
			$log .= "prefix inserts ok \n";
			$loop_log .= "prefix inserts ok \n";
		}	
	}

	# insert rest of the prefixes, if available
	# if we have over 2000 prefixes already, insert them here and reset 
	if ( !empty($insert_prefix_sql) )
	{
		$insert_prefix_sql[0] = " "; # trim the first comma
		$ins_start = microtime(true);
		$updpdo = $connection->query("INSERT INTO PMBpretemp$index_suffix (checksum, token_checksum, cutlen) VALUES $insert_prefix_sql");
		$total_insert_time += (microtime(true) - $ins_start);
		
		$prefix_total_count += $pr;	
		# reset variables
		$insert_prefix_sql = "";
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
	# update indexing state
	SetIndexingState(4, $index_id);
	
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