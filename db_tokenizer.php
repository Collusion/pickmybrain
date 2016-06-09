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

# if this file is launched as a separate process, do initialization here
if ( !isset($process_number) )
{
	define("MAX_TOKEN_LEN", 40);
	ini_set("display_errors", 1);
	error_reporting(E_ALL);
	mb_internal_encoding("UTF-8");
	set_time_limit(0);
	ignore_user_abort(true);
	
	$log = "";
	$test_mode 		= false;
	$user_mode 		= false;
	$process_number = 0;
	require_once("input_value_processor.php");
	require_once("tokenizer_functions.php");
}

register_shutdown_function("shutdown", $index_id, $process_number);

define("CHARSET_REGEXP", "/[^" . $charset . preg_quote(implode("", $blend_chars)) . "]/u");
							
foreach ( $blend_chars as $blend_char ) 
{
	$blend_chars_space[] = " $blend_char ";
	$blend_chars_space[] = " $blend_char";
	$blend_chars_space[] = "$blend_char ";
}
							
$blend_chars_space[] = "&#13";

$pre_existing_tokens = array();
$unwanted_characters_plain_text = array("\r\n", "\n\r", "\t", "\n", "\r", "&#13;");

# write buffer settings
$write_buffer_len = 20;
$awaiting_writes = 0;

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

/*
	Prepare data for the HTML preprocessor
*/

$preserve_attributes = array();
$elements_to_remove = array();

# attributes whose values are to be preserved
if ( !empty($html_index_attrs) )
{
	# get element => attributelist pairs 
	foreach ( $html_index_attrs as $i => $value ) 
	{
		$subexpl = explode("=", $value);

		if ( count($subexpl) === 2 ) 
		{
			$attrparts = explode(",", $subexpl[1]);
			
			$subexpl[0] = trim($subexpl[0]);
			if ( empty($preserve_attributes[$subexpl[0]]) )
			{
				$preserve_attributes[$subexpl[0]] = array();
			}
			
			foreach ( $attrparts as $attrpart ) 
			{
				$preserve_attributes[$subexpl[0]][] = trim($attrpart);
			}
		}
		else
		{
			$log .= "Error: following line of attributes to be preserved is incorrect: $value \n";
			++$e_count;
		}
	}
}

# elements that are to be removed completely
if ( !empty($html_remove_elements) )
{
	$expl = explode(",", $html_remove_elements);
	
	foreach ( $expl as $i => $value )
	{
		if ( !empty($value) )
		{
			$elements_to_remove[] = trim($value);
		}
	}
}

$reverse_attributes = array();
$database_attributes = array();

if ( !empty($main_sql_attrs) )
{
	$reverse_attributes = array_count_values($main_sql_attrs);
	
	foreach ( $reverse_attributes as $column_name => $column_count ) 
	{
		$database_attributes["attr_$column_name"] = 0;
	}
}

try
{
	$connection = db_connection();
	$connection->query("SET NAMES UTF8");
	
	$clean_slate = true;
	
	if ( $process_number === 0 ) 
	{
		$ind_state = $connection->query("SELECT current_state, updated, documents FROM PMBIndexes WHERE ID = $index_id");
		
		if ( $row = $ind_state->fetch(PDO::FETCH_ASSOC) )
		{
			if ( $row["current_state"] ) 
			{
				# abort, because indexer is already running !  
				die("already running");
			}
			# lastest indexing timestamp
			else if ( $indexing_interval && time()-($indexing_interval*60) > (int)$row["updated"] && !$user_mode && !$test_mode )  
			{
				die("too soon");
			}
			
			if ( $row["documents"] > 0 ) 
			{
				$clean_slate = false;
			}
		}
		else
		{
			die("Incorrent index \n");
		}
		
		# update current indexing state to true ( 1 ) 
		SetIndexingState(1, $index_id);
		
		# update statistics
		$upd_state = $connection->prepare("UPDATE PMBIndexes SET indexing_started = UNIX_TIMESTAMP(), comment = '' WHERE ID = ?");
		$upd_state->execute(array($index_id));
	}
	
	if ( $use_internal_db === 1 ) 
	{
		# create nex connection for reading the data
		$ext_connection = db_connection();
	}
	else
	{
		# external connection ( different database ) 

		# the external PDO connection is defined in this file
		require "ext_db_connection".$index_suffix.".php"; 
		
		# create a new instance of the connection
		$ext_connection = call_user_func("ext_db_connection");
		
		if ( is_string($ext_connection) )
		{
			echo "Error: establishing the external database connection failed. Following error message was received: $ext_connection\n";
			return;
		}
	}
	
	$min_doc_id = 0;
	$idpdo = $connection->query("SELECT MAX(ID) FROM PMBDocinfo$index_suffix");
	if ( $val = $idpdo->fetchColumn() )
	{
		$min_doc_id = (int)$val+1;
	}

	echo "Preloading dictionary...\n";
	$preloadstart = microtime(true);
	$items = 0;
	# preload dictionary ( if exists already ) 
	$dictpdo = $connection->query("SELECT token, ID FROM PMBTokens$index_suffix;");
	while ( $row = $dictpdo->fetch(PDO::FETCH_ASSOC) )
	{
		$pre_existing_tokens[$row["token"]] = (int)$row["ID"];
		++$items;
	}
	$preloadend = microtime(true) - $preloadstart;
	echo "$items items preloaded successfully in $preloadend seconds \n";
	
	$senti_sql_column = "";
	$senti_sql_index_column = "";
	$senti_insert_sql_column = "";
	if ( $sentiment_analysis )
	{
		$senti_sql_column = "sentiscore tinyint(3) NOT NULL,";
		$senti_sql_index_column = ",sentiscore";
		$senti_insert_sql_column = "sentiscore,";
	}
	
	# for measuring time
	$extra_total_time = 0;
	$docinfo_extra_time = 0;
	$token_stat_time = 0;
	
	# do not run diagnostics & formatting unless we are in master process
	if ( $process_number === 0 ) 
	{
		# truncate and create temporary tables
		if ( !$test_mode )
		{
			$data_dir_sql = "";
			if ( !empty($mysql_data_dir) )
			{
				$data_dir_sql = "DATA DIRECTORY = '$mysql_data_dir' INDEX DIRECTORY = '$mysql_data_dir'";
			}
			
			$temporary_table_type = "ENGINE=MYISAM DEFAULT CHARSET=utf8 ROW_FORMAT=FIXED $data_dir_sql";
	
			$connection->exec("DROP TABLE IF EXISTS PMBdatatemp$index_suffix;
								CREATE TABLE IF NOT EXISTS PMBdatatemp$index_suffix (
								checksum int(10) unsigned NOT NULL,
								token_id mediumint(10) unsigned NOT NULL,
								doc_id int(10) unsigned NOT NULL,
								count tinyint(3) unsigned NOT NULL,
							 	field_id tinyint(3) unsigned NOT NULL,
								".$senti_sql_column."
								token_id_2 mediumint(10) unsigned NOT NULL,
							 	KEY (checksum,token_id,doc_id,token_id_2,field_id,count ".$senti_sql_index_column.")
								) $temporary_table_type PACK_KEYS=1");
			
			if ( $clean_slate ) 
			{				
				$connection->exec("TRUNCATE TABLE PMBTokens$index_suffix;
									TRUNCATE TABLE PMBPrefixes$index_suffix;
									ALTER TABLE PMBTokens$index_suffix ENGINE=INNODB $innodb_row_format_sql;
									ALTER TABLE PMBPrefixes$index_suffix ENGINE=INNODB $innodb_row_format_sql");
				
				# create new temporary tables		   
				$connection->exec("DROP TABLE IF EXISTS PMBtoktemp$index_suffix;
									CREATE TABLE IF NOT EXISTS PMBtoktemp$index_suffix (
									 checksum int(10) unsigned NOT NULL,
									 token varbinary(40) NOT NULL,
									 ID mediumint(10) unsigned NOT NULL AUTO_INCREMENT,
									 KEY(ID),
									 UNIQUE (token)
									) $temporary_table_type");
									
				# create new temporary tables				   
				$connection->exec("DROP TABLE IF EXISTS PMBpretemp$index_suffix;
									CREATE TABLE IF NOT EXISTS PMBpretemp$index_suffix (
									 checksum int(10) unsigned NOT NULL,
									 token_checksum int(10) unsigned NOT NULL,
									 cutlen tinyint(3) unsigned NOT NULL,
									 KEY (checksum, cutlen, token_checksum)
									) $temporary_table_type PACK_KEYS=1");
			}

			# disable non-unique keys					 
			$connection->exec("ALTER TABLE PMBtoktemp$index_suffix DISABLE KEYS;
								ALTER TABLE PMBdatatemp$index_suffix DISABLE KEYS;");		
			
		}
							
		$log .= "\n";

		# if test mode is on
		if ( $test_mode ) 
		{
			test_database_settings($index_id, $log);
			
			echo $log;
			return;
			# test mode ends
		}
	}
	
	$timer = microtime(true);
}
catch ( PDOException $e ) 
{
	echo "Something went wrong when creating temporary tables: \n";
	echo $e->getMessage() . "\n";
	return;
}

/* 
	PMBDocinfo table format check 
*/

if ( $process_number === 0 ) 
{	
	try
	{
		# 1. step, insert a random empty row into the PMBDocinfo-table 
		# all the extra attributes will have default values, so this will be OK
		$preinsert = $connection->query("INSERT INTO PMBDocinfo$index_suffix () VALUES()");
		
		$last_insert_id = $connection->lastInsertId();
		
		# 2. then fetch this row by last insert ID and see which columns are returned
		# internal attributes will start like attr_[column_name] ( to prevent duplicates ) 
		# remove columns ( alter table ) that are not present in main_sql_attrs
		# add columns ( alter table )  that are defined on main_sql_attrs but not present in the table
		$precheck = $connection->query("SELECT * FROM PMBDocinfo$index_suffix WHERE ID = $last_insert_id");
		
		if ( $row = $precheck->fetch(PDO::FETCH_ASSOC) )
		{
			# remove internal attributes
			unset($row["ID"]);
			unset($row["avgsentiscore"]);
			
			foreach ( $row as $column_name => $column_value ) 
			{
				# overwrite values to avoid NULLs
				$row[$column_name] = 1;
				
				if ( !isset($database_attributes[$column_name]) )
				{
					# column is defined in the database table, but not as attribute
					# therefore it can be removed from the database table
					$alter_operations[] = "DROP $column_name";
				}
			}
			
			foreach ( $database_attributes as $attribute => $attr_value ) 
			{
				if ( !isset($row[$attribute]) )
				{
					# attribute is defined, but matching column is not defined in the database table
					# time for alter table and adding a columng
					$alter_operations[] = "ADD $attribute INT UNSIGNED NULL DEFAULT NULL, ADD INDEX (ID, $attribute)";
				}
			}
			
			# execute modifications
			if ( !empty($alter_operations) )
			{
				$connection->query("ALTER TABLE PMBDocinfo$index_suffix " . implode(", ", $alter_operations));
				
				echo "alter table query: " . "ALTER TABLE PMBDocinfo$index_suffix " . implode(", ", $alter_operations) . "\n";
			}
			else
			{
				echo "no alter table was needed\n";
			}
		}
		
		# finally, remove the added row
		$connection->query("DELETE FROM PMBDocinfo$index_suffix WHERE ID = $last_insert_id");
		
	}
	catch ( PDOException $e ) 
	{
		
		echo "Something went wrong while proofing PMBDocmatches \n";
		echo $e->getMessage();
	}
}

if ( $dist_threads > 1 && $process_number === 0 )
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

# make a copy of the original query because we might need it later
$main_sql_query = str_replace(array("\n", "\r", "\t"), " ", $main_sql_query);

#echo "before modifying: $main_sql_query \n\n";

$original_main_sql_query = $main_sql_query;
$main_sql_query = ModifySQLQuery($original_main_sql_query, $dist_threads, $process_number, $min_doc_id, $ranged_query_value);

#echo "SQL ($process_number) " . $main_sql_query . "\n\n";

try
{
	$driver_name = $ext_connection->getAttribute(PDO::ATTR_DRIVER_NAME);
	
	if ( $driver_name == 'mysql' )
	{
		# this is a mysql database, apply 
		if ( $use_buffered_queries )
		{
			$ext_connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
		}
		else
		{
			$ext_connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
		}
	}

	# set process state on
	SetProcessState($index_id, $process_number, 1);
	
	# fetch data from ( external ) database
	$mainpdo = $ext_connection->query($main_sql_query);	
}
catch ( PDOException $e ) 
{
	$log .= "Error: main SQL query failed\n";
	$log .= "The original query: $original_main_sql_query \n\n";
	if ( $original_main_sql_query != $main_sql_query ) 
	{
		$log .= "The modified sql query: $main_sql_query \n\n";
	}
	$log .= $e->getMessage() . "\n";
	echo $e->getMessage();
	#echo $log;
	return;
}

$insert_data_sql = "";
$up = 0;
$documents = 0;
$temp_documents = 0;

# start by disabling autocommit
# flush write log to disk at every write buffer commit
$connection->beginTransaction();

while ( $row = $mainpdo->fetch(PDO::FETCH_ASSOC) )
{
	# reset document data
	$document_id = 0;
	$document_attrs = array();
	$fields = array();
	$page_word_counter = array();
	$loop_log ="";
	$log = "";
	++$documents;
	++$temp_documents;
	
	$i = 0;			
	foreach ( $row as $column_name => $column_value )  
	{
		if ( $i === 0 ) 
		{
			# document id ( first attribute ) 
			$document_id = (int)$column_value;
		}
		else if ( empty($reverse_attributes[$column_name]) && $column_name != "pmb_language" )
		{
			# field that is to be tokenized
			$fields[] = $column_value;
		}
		else
		{
			if ( $sentiment_analysis == 1001 && $column_name == "pmb_language" )
			{
				# attribute-based sentiment analysis
				switch ( (int)$column_value )
				{
					case 1:
					$sentiment_class_name = "sentiment_en"; # english
					break;
					
					case 2:
					$sentiment_class_name = "sentiment_fi"; # finnish
					break;
					
					# no proper value provided
					default:
					$sentiment_class_name = "";
					break;
				}
			}
			
			if ( isset($reverse_attributes[$column_name]) )
			{
				# field that is defined as an attribute
				$document_attrs["attr_$column_name"] = $column_value;
			}
		}
		
		++$i;
	}
	
	if ( $document_id < $min_doc_id )
	{
		# skip this document, because it has been already indexed
		continue;
	}
	
	$word_sentiment_scores = array();
	$document_avg_score = 0;
	
	# renew database connection if required
	if ( $ranged_query_value && $temp_documents >= $ranged_query_value ) 
	{
		$new_main_sql = ModifySQLQuery($original_main_sql_query, $dist_threads, $process_number, $document_id+1, $ranged_query_value);
		echo "RENEWING: $new_main_sql \n\n";
		
		unset($mainpdo);
		
		$mainpdo = $ext_connection->query($new_main_sql);
		
		# reset
		$temp_documents = 0;		
	}

	/*
		CHARSET PROCESSING STARTS
	*/	
	foreach ( $fields as $f_id => $field ) 
	{
		# add spaces in the front and the back of each field for the single blend char removal to work
		$fields[$f_id] = " " . $fields[$f_id] . " ";
		
		# html preprocessor
		if ( $html_strip_tags )
		{
			if ( !empty($preserve_attributes) || !empty($elements_to_remove) )
			{
				$dom = new DOMDocument();
				libxml_use_internal_errors(TRUE);
				$dom->loadHTML(mb_convert_encoding($fields[$f_id], 'html-entities', "UTF-8"));
				$dom->preserveWhiteSpace = false;
				$dom->formatOutput = false;
				$changes = 0;
				$cdata = 0;
				
				# attributes whose values are to be saved
				if ( !empty($preserve_attributes) )
				{
					foreach ( $preserve_attributes as $element => $attrlist ) 
					{
						$nodes = $dom->getElementsByTagName($element);
						
						if ( !empty($nodes) && $nodes->length > 0 ) 
						{
							foreach ( $nodes as $node ) 
							{
								foreach ( $attrlist as $attr_to_save ) 
								{
									if ( $node->hasAttribute($attr_to_save) )
									{
										# append the attribute value before the current node
										$attrnode = $dom->createTextNode(str_replace(array("http://", "https://", "ftp://", "."), " ", $node->getAttribute($attr_to_save)));
										$node->parentNode->insertBefore($attrnode, $node);
										++$changes;
									}
								}
							}
						}
					}
				}
				
				# elements that are to be removed
				if ( !empty($elements_to_remove) )
				{
					foreach ( $elements_to_remove as $elementname ) 
					{
						if ( $elementname === "cdata" )
						{
							# this is done by preg_replace
							$cdata = 1;
							continue;
						}
						
						$nodes = $dom->getElementsByTagName($elementname);
						
						if ( !empty($nodes) && $nodes->length > 0 ) 
						{
							foreach ( $nodes as $node ) 
							{
								$node->parentNode->removeChild($node);
								++$changes;
							}
						}
					}
				}
				
				# if there are changes, stringify the docuoment
				if ( $changes ) 
				{
					$fields[$f_id] = $dom->saveXML();
				}
				
				# remove cdata elements with preg_replace
				if ( $cdata ) 
				{
					$fields[$f_id] = preg_replace('/<!\[cdata\[(.*?)\]\]>/is', "", $fields[$f_id]);
				}
			}
			
			# finally, strip tags 
			$fields[$f_id] = strip_tags(str_replace(array("<",">"), array(" <","> "), $fields[$f_id]));
		}
		
		# decode html entities
		$fields[$f_id] = html_entity_decode($fields[$f_id]);
			
		# convert to lowercase
		$fields[$f_id] = mb_strtolower($fields[$f_id]);
		
		# if sentiment analysis is enabled, do 
		if ( $sentiment_analysis && !empty($fields[$f_id]) && !empty($sentiment_class_name) ) 
		{
			$temp_sentiment_scores = $$sentiment_class_name->scoreContext($fields[$f_id]);
			$document_avg_score   += $$sentiment_class_name->scoreContextAverage();
			
			foreach ( $temp_sentiment_scores as $token => $temp_average ) 
			{
				if ( empty($token) ) continue;
						
				if ( !isset($word_sentiment_scores[$token]) )
				{
					$word_sentiment_scores[$token] = $temp_average;
				}
				else
				{
					$word_sentiment_scores[$token] += $temp_average;
				}
			}
		}
		
		# remove ignore_chars
		if ( !empty($ignore_chars) )
		{
			$fields[$f_id] = str_replace($ignore_chars, "", $fields[$f_id]);
		}
		
		# mass-replace all non-defined dialect characters if necessary
		if ( $dialect_processing && !empty($mass_find) ) 
		{
			$fields[$f_id] = str_replace($mass_find, $mass_replace, $fields[$f_id]);
		}
		
		# remove non-wanted characters and keep others
		$fields[$f_id] = preg_replace(CHARSET_REGEXP, " ", $fields[$f_id]);
				
		# remove single blended chars
		$fields[$f_id] = str_replace($blend_chars_space, " ", $fields[$f_id]);
		
		# process words that contain blend chars ( in the middle )
		foreach ( $blend_chars as $blend_char ) 
		{
			$q = preg_quote($blend_char);
			$regexp = "/[".$q."\w]+(".$q.")[\w".$q."]+/u";
			
			$fields[$f_id] = preg_replace_callback($regexp, "blendedwords", $fields[$f_id]);
		}
	
		# separate numbers and letters from each other
		if ( $separate_alnum ) 
		{
			$fields[$f_id] = preg_replace('/(?<=[a-z])(?=\d)|(?<=\d)(?=[a-z])/u', ' ', $fields[$f_id]);
		}

		# unwanted characters
		$fields[$f_id] = str_replace($unwanted_characters_plain_text, " ", $fields[$f_id]);
	}

	$token_pairs = array();
	
	foreach ( $fields as $field_id => $field ) 
	{
		$expl = explode(" ", $field);
		$first_token = NULL;

		# ota kiinni tapaukset jossa expl-array vaan yhden alkion pituinen
		foreach ( $expl as $m_i => $match ) 
		{
			if ( isset($match) && $match !== '' )
			{
				# overlong token, cut 
				if ( isset($match[MAX_TOKEN_LEN]) )
				{
					#$match = mb_substr($match, 0, MAX_TOKEN_LEN);
					$match = substr($match, 0, MAX_TOKEN_LEN);
				}
				
				if ( isset($first_token) && $first_token !== '' )
				{
					if ( isset($token_pairs[$first_token][$match][0]) )
					{
						$token_pairs[$first_token][$match][0] += 1; 				# one new hit
						$token_pairs[$first_token][$match][1] |= (1 << $field_id);	# add field id bit by or operation
					}
					else
					{
						# new value ( count , field_id bit ) 
						$token_pairs[$first_token][$match] = array(1, 1 << $field_id);
					}
				}
				
				# prebuild array for matching pre-existing tokens
				if ( empty($pre_existing_tokens[$match]) )
				{
					$page_word_counter[$match] = 1;
				}
				
				$first_token = $match;
			}
		}
		
		# lastly, create a pseudo-pair ( last token of current field ) 
		if ( isset($first_token) && $first_token !== '' )
		{
			if ( isset($token_pairs[$first_token][NULL][0]) )
			{
				$token_pairs[$first_token][NULL][0] += 1; 					# one new hit
				$token_pairs[$first_token][NULL][1] |= (1 << $field_id);	# add field id bit by OR operation
			}
			else
			{
				$token_pairs[$first_token][NULL] = array(1, 1 << $field_id);
			}
			
			# prebuild array for matching pre-existing tokens
			if ( empty($pre_existing_tokens[$first_token]) )
			{
				$page_word_counter[$first_token] = 1;
			}
		}
	}

	try
	{
		# $document_attrs["attr_$column_name"] = $column_value; 
		$columns = array();
		$updates = array();
		
		# add user-defined attributes ( if available ) 
		foreach ( $document_attrs as $column_name => $column_value )
		{
			$columns[] = $column_name;	
			$cescape[] = $column_value;
		}
		
		# add avgsentiscore
		if ( $sentiment_analysis ) 
		{
			$columns[] = "avgsentiscore";
			$cescape[] = $document_avg_score;
		}

		$columns[] = "ID";
		$cescape[] = $document_id;

		$docinfo_columns 		= "(" . implode(",", $columns) . ")";								# this doesn't really change
		$docinfo_value_sets[] 	= "(" . implode(",", array_fill(0, count($columns), "?")) . ")";	# this one changes

		$insert_token_sql 	= "INSERT INTO PMBtoktemp$index_suffix (token, checksum) VALUES ";
		$insert_prefix_sql 	= "";
		
		$in = 0;
		$pr = 0;

		$insert_escape 	= array();
		$prefix_escape 	= array();
		#$pre_existing_ids = array();		

		# the rest of the tokens can be inserted directly as they're new ( if any of them is actually new )
		if ( !empty($token_pairs) )
		{
			# prefixes have also to be  inserted at the same time ! 		
			foreach ( $token_pairs as $token => $tokenlist ) 
			{
				if ( isset($pre_existing_tokens[$token]) )
				{
					# token already exists, no need to create insert sql or prefixes
					continue;
				}
					
				# this is a new token, create insert syntax here
				if ( $in > 0 ) $insert_token_sql .= ", ";
				
				$insert_token_sql 		   .= "(:tok$in, CRC32(:tok$in))";
				$insert_escape[":tok$in"] = $token;		  		# token
				++$in;		
		
			}
				
			# insert the new tokens ( shouldn't be any collisions )			
			if ( !empty($insert_escape) )
			{ 
				$log .= "straight inserts start \n";
				$loop_log .="straight inserts start \n";
			
				$inpdo = $connection->prepare($insert_token_sql . " ON DUPLICATE KEY UPDATE token = token");
				$inpdo->execute($insert_escape);
				
				$extra_start = microtime(true);
				
				# then, fetch the same tokens that were inserted in the previous query
				$token_sel_sql = "";
				foreach ( $page_word_counter as $tok => $val ) 
				{
					$token_sel_sql .= ",?";
				}
				$token_sel_sql[0] = " ";
				
				$prepdo = $connection->prepare("SELECT token, ID FROM PMBtoktemp$index_suffix WHERE token IN ( $token_sel_sql )");
				$prepdo->execute(array_keys($page_word_counter));
				unset($token_sel_sql);
				
				while ( $row = $prepdo->fetch(PDO::FETCH_ASSOC) )
				{
					$pre_existing_tokens[$row["token"]] = (int)$row["ID"];
				}
				
				# for statistics
				$extra_end = microtime(true) - $extra_start;
				$extra_total_time += $extra_end;
				
				$log .= "straight inserts ok \n";
				$loop_log .= "straight inserts ok \n";
			}
			else
			{
				$log .= "no new tokens to insert... \n";
				$loop_log .= "no new tokens to insert... \n";
			}
		}

		/*
			NOW ALL PRE-EXISTING TOKENS ARE STORED INTO $pre_existing_tokens[token] => db_row_id
		*/
		
		# update token stats and insert new occuranges
		foreach ( $token_pairs as $token => $tokenlist )
		{
			# OLD TOKENS: treat these as updates
			# update statistics ( doc_pointer and total_matches )
			$real_token_count = 0;
			
			# calculate real count
			foreach ( $tokenlist as $second_token => $token_data ) 
			{
				$real_token_count += $token_data[0];
			}
			
			# sentiment score
			$wordsentiscore = 0;
			if ( !empty($word_sentiment_scores[$token]) )
			{
				$wordsentiscore = $word_sentiment_scores[$token];
			}
			
			if ( empty($pre_existing_tokens[$token]) )
			{
				echo "this should not happen, $token index is empty \n";
				continue;
			}
			
			$tid = $pre_existing_tokens[$token];
			$crc32 = crc32($token);
			
			# generate a list of token pairs to insert for this doc_id
			foreach ( $tokenlist as $second_token => $token_data ) 
			{
				if ( empty($second_token) )
				{
					# no second token present
					$second_token_row_id = 0; 
				}
				else if ( isset($pre_existing_tokens[$second_token]) )
				{
					$second_token_row_id = $pre_existing_tokens[$second_token];	
				}
				else
				{
					# this shouldn't happen!
					$second_token_row_id = 0;
					continue;
				}
				
				if ( $up > 0 ) $insert_data_sql .= ",";
				
				if ( $sentiment_analysis ) 
				{
					$insert_data_sql	.= "($crc32, $tid, $document_id, $real_token_count, $wordsentiscore, $second_token_row_id, " . $token_data[1] . ")";
				}
				else
				{
					$insert_data_sql	.= "($crc32, $tid, $document_id, $real_token_count, $second_token_row_id, " . $token_data[1] . ")";
				}
				
				++$up;
			}
		}

		# increase the awaiting_writes counter
		++$awaiting_writes;
		
		# write buffer is full, write data now
		if ( $awaiting_writes % $write_buffer_len === 0 ) 
		{				
			$docinfo_time_start = microtime(true);
			$count = count($docinfo_value_sets);
			$docpdo = $connection->prepare("INSERT INTO PMBDocinfo$index_suffix $docinfo_columns VALUES " . implode(",", $docinfo_value_sets) . "");												
			$docpdo->execute($cescape);
			
			$log .= "docinfo ok \n";
			$loop_log .= "docinfo ok \n";
				
			# update temporary statistics
			$connection->query("UPDATE PMBIndexes SET 
							temp_loads = temp_loads + $awaiting_writes,
							updated = UNIX_TIMESTAMP(),
							documents = documents + $awaiting_writes
							WHERE ID = $index_id");
							
			$log .= "Index statistics ok \n";
			$loop_log .= "Index statistics ok \n";

			# reset variables
			$docinfo_value_sets = array();
			$cescape 			= array();

			$docinfo_extra_time += (microtime(true)-$docinfo_time_start);

			$datapdo = $connection->query("INSERT INTO PMBdatatemp$index_suffix (checksum, token_id, doc_id, count, ".$senti_insert_sql_column." token_id_2, field_id) VALUES $insert_data_sql");
			unset($insert_data_sql);
			$insert_data_sql = "";
			$up = 0;
			$temporary_ids = array();
			
			$log .= "new word occurances ok \n";
			$loop_log .= "new word occurances ok \n";
			
			$awaiting_writes = 0;
				
			try
			{
				# check indexing permission
				$perm = $connection->query("SELECT indexing_permission FROM PMBIndexes WHERE ID = $index_id");
				$permission = $perm->fetchColumn();
				
				if ( !$permission )
				{
					# close the mainpdo cursor
					unset($mainpdo);

					if ( $process_number > 0 ) 
					{
						SetProcessState($index_id, $process_number, 0);
						die("Indexing was requested to be terminated...\n");
					}
					echo "Indexing was requested to be terminated...\n";

					break;
				}
				
				#$connection->beginTransaction();
				#$transaction_on = true;
			}
			catch ( PDOException $e ) 
			{
				echo "Error during indexing permission check: " . $e->getMessage() . "\n";
			}
		}
	
	}
	catch ( PDOException $e ) 
	{
		$mainpdo->closeCursor();
		#$connection->rollBack();
		var_dump($e->getMessage());
		$log .= $e->getMessage();
		echo $loop_log;

		if ( $test_mode ) 
		{
			echo $log;
		}

		return;
	}
}

# write remaining token data
try
{
	# update token statistics
	if ( $awaiting_writes ) 
	{	
		$docinfo_time_start = microtime(true);
		$count = count($docinfo_value_sets);
		$docpdo = $connection->prepare("INSERT INTO PMBDocinfo$index_suffix $docinfo_columns VALUES " . implode(",", $docinfo_value_sets) . "");												
		$docpdo->execute($cescape);
		
		$log .= "docinfo ok \n";
		$loop_log .= "docinfo ok \n";

		# reset variables
		$docinfo_value_sets = array();
		$cescape 			= array();

		$docinfo_extra_time += (microtime(true)-$docinfo_time_start);

		# insert token data
		$datapdo = $connection->query("INSERT INTO PMBdatatemp$index_suffix (checksum, token_id, doc_id, count, ".$senti_insert_sql_column." token_id_2, field_id) VALUES $insert_data_sql");
		$insert_data_sql = "";
		$up = 0;
		$temporary_ids = array();

		$log .= "new word occurances ok \n";
		$loop_log .= "new word occurances ok \n";	
		
		# update temporary statistics
		$connection->query("UPDATE PMBIndexes SET 
							temp_loads = temp_loads + $awaiting_writes,
							updated = UNIX_TIMESTAMP(),
							documents = documents + $awaiting_writes
							WHERE ID = $index_id");
	}
}
catch ( PDOException $e ) 
{
	echo "an error occurred during updating the remaining token rows \n" . $e->getMessage() . "\n";
}

try
{
	$connection->commit();
}
catch ( PDOException $e ) 
{
	echo "An error occurred when committing final transaction " . $e->getMessage() . "\n";
}

# mainpdo is not needed anymore
if ( isset($mainpdo) )
{
	unset($mainpdo);
}

/*
	Now it is the time to handle temporary data! 
*/

echo "Memory usage : " . memory_get_usage()/1024/1024 . " MB\n";
echo "Memory usage (peak) : " . memory_get_peak_usage()/1024/1024 . " MB\n";

# no need for these anymore ! 
unset($pre_existing_tokens);

# wait for another processes to finish
require("process_listener.php");

$interval = microtime(true) - $timer;
echo "Data tokenization is complete, $interval seconds elapsed, starting to compose prefixes...\n";

if ( $documents === 0 ) 
{
	# no documents processed, no reason to continue!
	SetIndexingState(0, $index_id);
	SetProcessState($index_id, $process_number, 0);	
	die( "No documents processed, quitting now.. \n" );
}

# create prefixes
require_once("prefix_composer.php");

$interval = microtime(true) - $timer;
echo "\nNow all processes are completed\nExtra SQL time: $extra_total_time seconds \nDocinfo time: $docinfo_extra_time \nToken statistics time: $token_stat_time \n";

try
{
	# update indexing status accordingly ( + set the indexing permission ) 
	$connection->query("UPDATE PMBIndexes SET 
						current_state = 3, 
						indexing_permission = 1, 
						temp_loads = 0, 
						temp_loads_left = 0,
						documents = documents + $awaiting_writes
						WHERE ID = $index_id");
	
	$key_start = microtime(true);
	echo "Enabling keys for temporary tables...\n";

	# before anything else, build indexes for temporary tables + optimize
	$connection->exec("ALTER TABLE PMBdatatemp$index_suffix ENABLE KEYS;");
	
	$key_end = microtime(true) - $key_start;					
	echo "Enabling keys took: $key_end seconds \n";					
	
	# precache table index
	$connection->query("LOAD INDEX INTO CACHE PMBtoktemp$index_suffix;");
	$connection->query("LOAD INDEX INTO CACHE PMBdatatemp$index_suffix;");
						
	# get count of token position data entries
	$dcountpdo = $connection->query("SELECT COUNT(checksum) FROM PMBdatatemp$index_suffix");
	$total_rows = $dcountpdo->fetchColumn();
	
	$pcountpdo = $connection->query("SELECT COUNT(checksum) FROM PMBpretemp$index_suffix");
	$total_rows += $pcountpdo->fetchColumn();
	
	#echo "dist_threads: $dist_threads - total rows: $total_rows \n";

	# update indexing status accordingly ( + set the indexing permission ) 
	$connection->query("UPDATE PMBIndexes SET current_state = 3, 
						indexing_permission = 1, 
						temp_loads = temp_loads + $total_rows, 
						temp_loads_left = 0 
						WHERE ID = $index_id");

	
}
catch ( PDOException $e ) 
{
	echo "Error during PMBTokens: ";
	echo $e->getMessage();	
}

$interval = microtime(true) - $timer;
echo "----------------------------------------\nReading and tokenizing data took $interval seconds\n----------------------------------------------\n\nWaiting for tokens....\n";

# run token compressor
if ( $clean_slate )
{
	require_once("token_compressor.php");
}
else
{
	require_once("token_compressor_merger.php");
}

# run prefix compressor
require_once("prefix_compressor.php");

echo "Memory usage : " . memory_get_usage()/1024/1024 . " MB\n";
echo "Memory usage (peak) : " . memory_get_peak_usage()/1024/1024 . " MB\n";
$timer_end = microtime(true) - $timer;
echo "The whole operation took $timer_end seconds \n";

# reset temporary variables
try
{
	$connection->query("UPDATE PMBIndexes SET 
						current_state = 0, 
						temp_loads = 0, 
						temp_loads_left = 0
						WHERE ID = $index_id ");
}
catch ( PDOException $e ) 
{
	echo "An error occurred when updating statistics: " . $e->getMessage() . "\n";
}

# print debug information
if ( $test_mode ) 
{
	mail("ruutinen@gmail.com", "log", $log, 'From: postmaster@hollilla.com <postmaster@hollilla.com>', "-f postmaster@hollilla.com -r postmaster@hollilla.com");
	echo $log;
}


# update current indexing state to false ( 0 ) 
SetIndexingState(0, $index_id);
SetProcessState($index_id, $process_number, 0);	





?>
