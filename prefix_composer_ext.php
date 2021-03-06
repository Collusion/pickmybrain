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
	
	$where_sql = "LIMIT $start_id, $scale";

	if ( $process_number == $dist_threads-1 ) 
	{
		# last thread ( with biggest offset ) 
		$where_sql = "LIMIT $start_id, 9999999999";
	}

	if ( $dist_threads === 1 ) 
	{
		$where_sql = "LIMIT $start_id, 9999999999";
	}

	$maximum_prefix_len 	= 20;
	$insert_prefix_sql 		= "";

	$data_rows = 0;
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
	
	if ( !empty($mysql_data_dir) )
	{
		$directory = $mysql_data_dir; # custom directory
	}
	else
	{
		$directory = realpath(dirname(__FILE__));
	}
	$filepath = $directory . "/pretemp_".$index_id."_".$process_number.".txt";
	$f = fopen($filepath, "a");
	
	$PackedIntegers = new PackedIntegers();
	
	$write_buffer = array();
	$pdo = $unbuffered_connection->query("SELECT token FROM PMBtoktemp$index_suffix $where_sql");
	
	echo "Prefix composer sql: SELECT token FROM PMBtoktemp$index_suffix $where_sql \n";
	$delta_values = array();

	while ( $row = $pdo->fetch(PDO::FETCH_ASSOC) ) 
	{	
		++$token_total_count;
		$token = $row["token"];
		$token_crc32_shifted = crc32($token)<<6;
	
		# dialect processing: remove dialect ( ä, ö, å + etc) from tokens and add as prefix
		if ( !empty($dialect_find) )
		{
			$nodialect = str_replace($dialect_find, $dialect_replace, $token);
			if ( $nodialect !== $token ) 
			{
				# prefix crc32 value, original token crc32 checksum, how many characters have been cut of from the original word 
				++$pr;
				$prefix_word = crc32($nodialect);
				if ( !isset($write_buffer[$prefix_word]) )
				{
					$write_buffer[$prefix_word] = array($token_crc32_shifted);
				}
				else
				{
					$write_buffer[$prefix_word][] = $token_crc32_shifted;
				}
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
							$prefix_word = crc32(mb_substr($token, $j, $i));
							if ( !isset($write_buffer[$prefix_word]) ) 
							{
								$write_buffer[$prefix_word] = array($token_crc32_shifted|($wordlen-$i));
							}
							else
							{
								$write_buffer[$prefix_word][] = $token_crc32_shifted|($wordlen-$i);
							}

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
						$prefix_word = crc32(mb_substr($token, 0, $i));
						if ( !isset($write_buffer[$prefix_word]) )
						{
							$write_buffer[$prefix_word] = array($token_crc32_shifted|($wordlen-$i));
						}
						else
						{
							$write_buffer[$prefix_word][] = $token_crc32_shifted|($wordlen-$i);
						}

						++$pr;
					}
					
					# postfix
					for ( $i = 1 ; $wordlen-$i >= $min_prefix_len ; ++$i )
					{
						$prefix_word = crc32(mb_substr($token, $i));
						if ( !isset($write_buffer[$prefix_word]) )
						{
							$write_buffer[$prefix_word] = array($token_crc32_shifted|$i);
						}
						else
						{
							$write_buffer[$prefix_word][] = $token_crc32_shifted|$i;
						}
						
						++$pr;
					}
				}
				else if ( $prefix_mode === 1 )
				{
					# default: prefixes only
					for ( $i = $wordlen-1 ; $i >= $min_prefix_len ; --$i )
					{
						$prefix_word = crc32(mb_substr($token, 0, $i)); 
						if ( !isset($write_buffer[$prefix_word]) ) 
						{
							$write_buffer[$prefix_word] = array($token_crc32_shifted|($wordlen-$i));
						}
						else
						{
							$write_buffer[$prefix_word][] = $token_crc32_shifted|($wordlen-$i);
						}

						++$pr;
					}
				}
			}
		}
		
		# if we have over 2000 prefixes already, insert them here and reset 
		if ( $pr > 40000 )
		{
			$write_buf = "";
			$ins_start = microtime(true);
			foreach ( $write_buffer as $checksum => $tokdata ) 
			{
				sort($tokdata);
				$write_buf .= $PackedIntegers->int32_to_bytes5($checksum);
				$delta = 0;
				
				foreach ( $tokdata as $checksum_38bit )
				{
					$write_buf .= " ".$PackedIntegers->int_to_bytes($checksum_38bit-$delta);
					$delta = $checksum_38bit;
				}
				
				$write_buf .= "\n";
				#$write_buf .= $PackedIntegers->int32_to_bytes5($checksum)."$tokdata\n";
				++$data_rows;
			}
						
			fwrite($f, $write_buf);
			$total_insert_time += (microtime(true) - $ins_start);
			
			$prefix_total_count += $pr;
			# reset variables
			$pr = 0;
			unset($write_buffer, $write_buf);
			$write_buffer = array();
			$log .= "prefix inserts ok \n";
			$loop_log .= "prefix inserts ok \n";
		}	
	}

	$pdo->closeCursor();
	unset($pdo);

	# insert rest of the prefixes, if available
	# if we have over 2000 prefixes already, insert them here and reset 
	if ( !empty($write_buffer) )
	{
		$write_buf = "";
		$ins_start = microtime(true);
		
		foreach ( $write_buffer as $checksum => $tokdata ) 
		{
			sort($tokdata);
			$write_buf .= $PackedIntegers->int32_to_bytes5($checksum);
			$delta = 0;
				
			foreach ( $tokdata as $checksum_38bit )
			{
				$write_buf .= " ".$PackedIntegers->int_to_bytes($checksum_38bit-$delta);
				$delta = $checksum_38bit;
			}
			
			$write_buf .= "\n";
			#$write_buf .= $PackedIntegers->int32_to_bytes5($checksum)."$tokdata\n";
			++$data_rows;
		}
					
		fwrite($f, $write_buf);
		$total_insert_time += (microtime(true) - $ins_start);
		
		$prefix_total_count += $pr;
		# reset variables
		$pr = 0;
		unset($write_buffer, $write_buf);
		$write_buffer = array();
		$log .= "prefix inserts ok \n";
		$loop_log .= "prefix inserts ok \n";
	}
	
	$connection->query("UPDATE PMBIndexes SET
						temp_loads_left = temp_loads_left + $data_rows
						WHERE ID = $index_id");
}
catch ( PDOException $e ) 
{
	echo "error during composing prefixes: ";
	echo $e->getMessage();
}

fclose($f);

# wait for another processes to finish
require("process_listener.php");

$interval = microtime(true) - $timer;
echo "All prefix processes have now finished, $interval seconds elapsed... \n";

echo "prefix composer is now finished \n";
$tokens_end = microtime(true) - $tokens_start;



echo "Memory usage : " . memory_get_usage()/1024/1024 . " MB\n";
echo "Memory usage (peak) : " . memory_get_peak_usage()/1024/1024 . " MB\n";
echo "$token_total_count tokens and $prefix_total_count prefixes processed \n";
echo "Inserting tokens into temp tables took $total_insert_time seconds \n";
echo "-------------------------------------------------\nComposing prefixes took $tokens_end seconds \n-------------------------------------------------\n";


?>