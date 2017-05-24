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

# if this file is launched as a separate process, do initialization here
if ( !isset($process_number) )
{
	define("MAX_TOKEN_LEN", 40);
	ini_set("display_errors", 1);
	error_reporting(E_ALL);
	mb_internal_encoding("UTF-8");
	set_time_limit(0);
	#ignore_user_abort(true);
	
	$log = "";
	$test_mode 		= false;
	$user_mode 		= false;
	$process_number = 0;
	require_once("input_value_processor.php");
	require_once("tokenizer_functions.php");
}

register_shutdown_function("shutdown", $index_id, $process_number);

define("CHARSET_REGEXP", "/[^" . $charset . preg_quote(implode("", $blend_chars)) . "]/u");

$forbidden_tokens[""] 	= 1;
$forbidden_tokens[" "] 	= 1;
							
foreach ( $blend_chars as $blend_char ) 
{
	$blend_chars_space[] = " $blend_char ";
	$blend_chars_space[] = " $blend_char";
	$blend_chars_space[] = "$blend_char ";
	
	if ( stripos($charset, $blend_char) === false ) 
	{
		$forbidden_tokens[$blend_char] = 1;
	}
}
							
$blend_chars_space[] = "&#13";

$temporary_token_ids = array();
$unwanted_characters_plain_text = array("\r\n", "\n\r", "\t", "\n", "\r", "&#13;");

# write buffer settings
$write_buffer_len = 100;
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
		$value = trim($value);
		if ( !empty($value) )
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
		$ind_state = $connection->query("SELECT current_state, updated, documents, delta_documents, latest_rotation, max_id FROM PMBIndexes WHERE ID = $index_id");
		
		if ( $row = $ind_state->fetch(PDO::FETCH_ASSOC) )
		{
			if ( $row["current_state"] ) 
			{
				# abort, because indexer is already running !  
				die("already running");
			}
			# lastest indexing timestamp
			else if ( $indexing_interval && (int)$row["updated"] + ($indexing_interval*60) > time() && !$user_mode && !$test_mode )  
			{
				die("indexing interval is enabled - you are trying to index too soon\n");
			}
			
			$min_doc_id = (int)$row["max_id"]+1;
			$delta_document_start_count = (int)$row["delta_documents"];
			
			if ( $row["documents"] > 0 ) 
			{
				$clean_slate = false;
			}
			else
			{
				# if index replacing is enabled
				# it can be disabled to save some tome
				unset($replace_index);
			}
		}
		else
		{
			die("Incorrent index \n");
		}
		
		# update current indexing state to true ( 1 ) 
		SetIndexingState(1, $index_id);
		
		# update statistics
		$upd_state = $connection->prepare("UPDATE PMBIndexes SET indexing_started = UNIX_TIMESTAMP(), 
																comment = '',
																temp_loads  = 0,
																temp_loads_left = 0
																WHERE ID = ?");
		$upd_state->execute(array($index_id));
		
		# delta index rotation interval
		if ( !empty($delta_indexing) && !$clean_slate )
		{
			if ( (!empty($delta_merge_interval) && time() - ($delta_merge_interval*60) > $row["latest_rotation"]) || !empty($manual_delta_merge) )
			{
				# delta index doesn't exist/is empty
				if ( $delta_document_start_count === 0 ) 
				{
					if ( !empty($manual_delta_merge) )
					{
						SetIndexingState(0, $index_id);
						SetProcessState($index_id, $process_number, 0);	
						die("delta index merging requested, but there is nothing to merge ( delta index is empty ). Quitting now...\n");
					}
				}
				else
				{
					# temporarily disable delta indexing and switch to replace index
					$delta_indexing = null;
					$replace_index = null;
					
					# if we are not delta-indexing, then this should not be harmful in any way
					$connection->query("DELETE FROM PMBDocinfo".$index_suffix." WHERE ID > (SELECT max_id FROM PMBIndexes WHERE ID = $index_id)");
				}
			}
		}
		else
		{
			# if we are not delta-indexing, then this should not be harmful in any way
			$connection->query("DELETE FROM PMBDocinfo".$index_suffix." WHERE ID > (SELECT max_id FROM PMBIndexes WHERE ID = $index_id)");
		}
		
		$data_dir_sql = "";
		if ( !empty($mysql_data_dir) )
		{
			$data_dir_sql = "DATA DIRECTORY = '$mysql_data_dir' INDEX DIRECTORY = '$mysql_data_dir'";
		}
		
		if ( isset($purge_index) )
		{
			$clean_slate = true;
			$delta_indexing = null;
			$replace_index = null;
			$min_doc_id = 0;
			$connection->exec("TRUNCATE TABLE PMBDocinfo$index_suffix");
			$connection->query("UPDATE PMBIndexes SET documents = 0, delta_documents = 0, max_id = 0 WHERE ID = $index_id");
		}
		else
		{
			if ( !empty($replace_index) )
			{
				$connection->exec("DROP TABLE IF EXISTS PMBDocinfo".$index_suffix."_temp;
							CREATE TABLE IF NOT EXISTS PMBDocinfo".$index_suffix."_temp (
							 ID int(11) unsigned NOT NULL,
							 avgsentiscore tinyint(4) NOT NULL,
							 PRIMARY KEY (ID),
							 KEY avgsentiscore (ID, avgsentiscore)	
							) ENGINE=MYISAM DEFAULT CHARSET=utf8 PACK_KEYS=1 ROW_FORMAT=FIXED $data_dir_sql");	
			}
		}
	}
	
	if ( isset($purge_index) )
	{
		$clean_slate = true;
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

	if ( $process_number > 0 )
	{
		if ( !empty($data_partition) )
		{
			$min_doc_id = (int)$data_partition[0];
		}
		else
		{
			$min_doc_id = 0;
		}
	}
		
	$docinfo_target_table = "PMBDocinfo$index_suffix";
	$doc_counter_target_column = "documents";
	
	if ( !empty($replace_index) )
	{
		$docinfo_target_table = "PMBDocinfo".$index_suffix."_temp";
		$clean_slate = true;
		$min_doc_id = 0;
	}
	else if ( (!empty($delta_indexing) && !$clean_slate) || !empty($delta_mode) )
	{
		$docinfo_target_table = "PMBDocinfo".$index_suffix."_delta";
		$doc_counter_target_column = "delta_documents";
		
		# create the table if it doesn't already exist! 
		if ( $process_number === 0 ) 
		{
			try
			{
				$connection->query("UPDATE PMBIndexes SET $doc_counter_target_column = 0 WHERE ID = $index_id");
				$connection->query("DROP TABLE IF EXISTS $docinfo_target_table");
				$connection->query("CREATE TABLE IF NOT EXISTS $docinfo_target_table (
					 ID int(11) unsigned NOT NULL,
					 avgsentiscore tinyint(4) NOT NULL,
					 PRIMARY KEY (ID),
					 KEY avgsentiscore (avgsentiscore)	
					) ENGINE=MYISAM DEFAULT CHARSET=utf8 PACK_KEYS=1 ROW_FORMAT=FIXED $data_dir_sql");
				$connection->query("ALTER TABLE $docinfo_target_table DISABLE KEYS");
			}
			catch ( PDOException $e ) 
			{
				echo "Creating docinfo delta table failed :( \n";
			}
		}
	}
		
	$senti_sql_column = "";
	$senti_sql_index_column = "";
	
	if ( $sentiment_analysis )
	{
		$senti_sql_column = "sentiscore tinyint(3) NOT NULL,";
		$senti_sql_index_column = ",sentiscore";
	}
	
	# for measuring time
	$docinfo_extra_time = 0;
	
	$bitshift = requiredBits($number_of_fields);
	
	# do not run diagnostics & formatting unless we are in master process
	if ( $process_number === 0 ) 
	{
		# truncate and create temporary tables
		if ( !$test_mode )
		{
			$temporary_table_type = "ENGINE=MYISAM DEFAULT CHARSET=utf8 ROW_FORMAT=FIXED $data_dir_sql";

			$connection->exec("DROP TABLE IF EXISTS PMBdatatemp$index_suffix;
								CREATE TABLE IF NOT EXISTS PMBdatatemp$index_suffix (
								checksum int(10) unsigned NOT NULL,
								minichecksum smallint(7) unsigned NOT NULL,
								doc_id int(10) unsigned NOT NULL,
								field_pos int(10) unsigned NOT NULL,
								".$senti_sql_column."
							 	KEY (checksum,minichecksum,doc_id,field_pos".$senti_sql_index_column.")
								) $temporary_table_type PACK_KEYS=1");
												
			# create new temporary tables				   
			$connection->exec("DROP TABLE IF EXISTS PMBpretemp$index_suffix;
								CREATE TABLE IF NOT EXISTS PMBpretemp$index_suffix (
								checksum int(10) unsigned NOT NULL,
								token_checksum int(10) unsigned NOT NULL,
								cutlen tinyint(3) unsigned NOT NULL,
								KEY (checksum, cutlen, token_checksum)
								) $temporary_table_type PACK_KEYS=1");
			
			if ( $clean_slate || isset($purge_index) ) 
			{	
				if ( empty($replace_index) )
				{		
					$connection->exec("TRUNCATE TABLE PMBTokens$index_suffix;
									TRUNCATE TABLE PMBPrefixes$index_suffix;
									ALTER TABLE PMBTokens$index_suffix ENGINE=INNODB $innodb_row_format_sql;
									ALTER TABLE PMBPrefixes$index_suffix ENGINE=INNODB $innodb_row_format_sql");
				}
				
				# create new temporary tables	  KEY checksum (checksum,minichecksum,ID)	   
				$connection->exec("DROP TABLE IF EXISTS PMBtoktemp$index_suffix;
									CREATE TABLE IF NOT EXISTS PMBtoktemp$index_suffix (
									 token varbinary(40) NOT NULL,
									 checksum int(10) unsigned NOT NULL,
									 minichecksum smallint(5) unsigned NOT NULL,
									 PRIMARY KEY (checksum,minichecksum)
									) ENGINE=MYISAM DEFAULT CHARSET=utf8 $data_dir_sql");
			}
			else
			{
				echo "Loading index into cache... \n";
				$connection->query("LOAD INDEX INTO CACHE PMBtoktemp$index_suffix;");	
			}
			
			# disable non-unique keys 				 
			$connection->exec("ALTER TABLE PMBdatatemp$index_suffix DISABLE KEYS;");							
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
	SetIndexingState(0, $index_id);
	die();
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
		$preinsert = $connection->query("INSERT INTO $docinfo_target_table () VALUES()");
		
		$last_insert_id = $connection->lastInsertId();
		
		# 2. then fetch this row by last insert ID and see which columns are returned
		# internal attributes will start like attr_[column_name] ( to prevent duplicates ) 
		# remove columns ( alter table ) that are not present in main_sql_attrs
		# add columns ( alter table )  that are defined on main_sql_attrs but not present in the table
		$precheck = $connection->query("SELECT * FROM $docinfo_target_table WHERE ID = $last_insert_id");
		
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
				$connection->query("ALTER TABLE $docinfo_target_table " . implode(", ", $alter_operations));
				
				echo "alter table query: " . "ALTER TABLE $docinfo_target_table " . implode(", ", $alter_operations) . "\n";
			}
			else
			{
				echo "no alter table was needed\n";
			}
		}
		
		# finally, remove the added row
		$connection->query("DELETE FROM $docinfo_target_table WHERE ID = $last_insert_id");
		
		# disable keys
		$connection->exec("ALTER TABLE $docinfo_target_table DISABLE KEYS;");
		
	}
	catch ( PDOException $e ) 
	{
		
		echo "Something went wrong while proofing PMBDocmatches \n";
		echo $e->getMessage();
	}
}

if ( $dist_threads > 1 && $process_number === 0 )
{
	$cmd = "";
	$curl = "";
	if ( !empty($replace_index) )
	{
		$cmd = "replace";
		$curl = "&replace=1";
	}
	else if ( !empty($delta_indexing) && !$clean_slate ) 
	{
		$cmd = "delta";
		$curl = "&delta=1";
	}
	
	# launch sister-processes
	for ( $x = 1 ; $x < $dist_threads ; ++$x ) 
	{
		# start prefix compression in async mode	
		if ( $enable_exec )
		{
			# launch via exec()	
			execInBackground("php " . __FILE__ . " index_id=$index_id process_number=$x data_partition=$min_doc_id $cmd");
		}
		else
		{
			# launch via async curl
			$url_to_exec = "http://localhost" . str_replace($document_root, "", __FILE__ ) . "?index_id=$index_id&process_number=$x&data_partition=$min_doc_id".$curl;
			execWithCurl($url_to_exec);
		}
	}
}

# make a copy of the original query because we might need it later
$main_sql_query = str_replace(array("\n", "\r", "\t"), " ", $main_sql_query);

#echo "before modifying: $main_sql_query \n\n";

$original_main_sql_query = $main_sql_query;
$main_sql_query = ModifySQLQuery($original_main_sql_query, $dist_threads, $process_number, $min_doc_id, $ranged_query_value);

echo "SQL ($process_number) " . $main_sql_query . "\n\n";

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
$insert_counter = 0;
$flush_interval = 10;
$toktemp_total_insert = 0;

try
{
# start by disabling autocommit
# flush write log to disk at every write buffer commit
#$connection->beginTransaction();

$skipped = 0;
$fetched = 0;
$breakpoint = 0;
while ( true )
{
	if ( $row = $mainpdo->fetch(PDO::FETCH_ASSOC) ) 
	{
		++$fetched;
	}
	else
	{
		$breakpoint = $fetched;
		break;
	}
	
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
		++$skipped;
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
	
	unset($blend_replacements);
	
	/*
		CHARSET PROCESSING STARTS
	*/	
	foreach ( $fields as $f_id => $field ) 
	{
		# add spaces in the front and the back of each field for the single blend char removal to work
		$field = " " . $field . " ";
		
		# html preprocessor
		if ( $html_strip_tags )
		{
			if ( !empty($preserve_attributes) || !empty($elements_to_remove) )
			{
				$dom = new DOMDocument();
				libxml_use_internal_errors(TRUE);
				$dom->loadHTML(mb_convert_encoding($field, 'html-entities', "UTF-8"));
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
					$field = $dom->saveXML();
				}
				
				# remove cdata elements with preg_replace
				if ( $cdata ) 
				{
					$field = preg_replace('/<!\[cdata\[(.*?)\]\]>/is', "", $field);
				}
			}
			
			# finally, strip tags 
			$field = strip_tags(str_replace(array("<",">"), array(" <","> "), $field));
		}
		
		# decode html entities
		$field = html_entity_decode($field);
			
		# convert to lowercase
		$field = mb_strtolower($field);
		
		# unwanted characters
		$field = str_replace($unwanted_characters_plain_text, " ", $field);
		
		# if sentiment analysis is enabled, do 
		if ( $sentiment_analysis && !empty($field) && !empty($sentiment_class_name) ) 
		{
			$temp_sentiment_scores = $$sentiment_class_name->scoreContext($field);
			$document_avg_score   += $$sentiment_class_name->scoreContextAverage();
			
			foreach ( $temp_sentiment_scores as $token => $temp_average ) 
			{
				if ( $token === "" ) continue;
						
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
			$field = str_replace($ignore_chars, "", $field);
		}
		
		# mass-replace all non-defined dialect characters if necessary
		if ( $dialect_processing && !empty($mass_find) ) 
		{
			$field = str_replace($mass_find, $mass_replace, $field);
		}
		
		# remove non-wanted characters and keep others
		$field = preg_replace(CHARSET_REGEXP, " ", $field);
				
		# remove single blended chars
		$field = str_replace($blend_chars_space, " ", $field);
		
		# process words that contain blend chars ( in the middle )
		foreach ( $blend_chars as $blend_char ) 
		{
			$q = preg_quote($blend_char);
			$regexp = "/[".$q."\w]+(".$q.")[\w".$q."]+/u";
			unset($matches);
			preg_match_all($regexp, $field, $matches, PREG_SET_ORDER);
		
			if ( !empty($matches) ) 
			{
				foreach ( $matches as $data ) 
				{
					$blend_replacements[$data[0]] = blended_chars_new($data);
				}
			}
		}
	
		# separate numbers and letters from each other
		if ( $separate_alnum ) 
		{
			$field = preg_replace('/(?<=[a-z])(?=\d)|(?<=\d)(?=[a-z])/u', ' ', $field);
		}
		
		$fields[$f_id] = $field;
	}
	
	foreach ( $fields as $f_id => $field ) 
	{
		$pos = 1;
		$expl = explode(" ", $field);

		foreach ( $expl as $m_i => $match ) 
		{
			if ( empty($forbidden_tokens[$match]) )
			{
				$temporary_token_ids[$match] = 1;
				
				$crc32 = crc32($match);
				$b = md5($match);
				$tid = hexdec($b[0].$b[1].$b[2].$b[3]);
				$field_pos = ($pos << $bitshift) | $f_id;
				
				# sentiment score
				if ( !empty($word_sentiment_scores[$match]) )
				{
					$wordsentiscore = $word_sentiment_scores[$match];
				}
				else
				{
					$wordsentiscore = 0;
				}
				
				if ( $sentiment_analysis ) 
				{
					$insert_data_sql	.= ",($crc32, $tid, $document_id, $field_pos, $wordsentiscore)";
				}
				else
				{
					$insert_data_sql	.= ",($crc32, $tid, $document_id, $field_pos)";
				}
				
				# if this token consists blend_chars and has a parallel version
				if ( isset($blend_replacements[$match]) )
				{
					$t_pos = $pos;
					foreach ( explode(" ", $blend_replacements[$match]) as $token_part ) 
					{
						if ( $token_part === "" ) continue;
						
						$temporary_token_ids[$token_part] = 1;
						$crc32 = crc32($token_part);
						$b = md5($token_part);
						$tid = hexdec($b[0].$b[1].$b[2].$b[3]);
						$field_pos = ($t_pos << $bitshift) | $f_id;
						
						# sentiment score
						if ( !empty($word_sentiment_scores[$match]) )
						{
							$wordsentiscore = $word_sentiment_scores[$match];
						}
						else
						{
							$wordsentiscore = 0;
						}
						
						if ( $sentiment_analysis ) 
						{
							$insert_data_sql	.= ",($crc32, $tid, $document_id, $field_pos, $wordsentiscore)";
						}
						else
						{
							$insert_data_sql	.= ",($crc32, $tid, $document_id, $field_pos)";
						}
						++$t_pos;
					}
				}
				
				++$pos;
			}
		}
	}

	unset($expl, $fields);
	
	try
	{
		$columns = array();
		$updates = array();
	
		# document row id ( auto-increment )
		$columns[] = "ID";
		$doc_column_value_string = "($document_id";
		
		# add user-defined attributes ( if available ) 
		foreach ( $document_attrs as $column_name => $column_value )
		{
			$columns[] = $column_name;	
			$doc_column_value_string .= ",$column_value";
		}
		
		# add avgsentiscore
		if ( $sentiment_analysis ) 
		{
			$columns[] = "avgsentiscore";
			$doc_column_value_string .= ",$document_avg_score";
		}
		
		$doc_column_value_string .= ")";
		$cescape[] = $doc_column_value_string;

		$docinfo_columns 		= "(" . implode(",", $columns) . ")";								# this doesn't really change
		$docinfo_value_sets[] 	= "(" . implode(",", array_fill(0, count($columns), "?")) . ")";	# this one changes

		# increase the awaiting_writes counter
		++$awaiting_writes;
		
		# write buffer is full, write data now
		if ( $awaiting_writes % $write_buffer_len === 0 ) 
		{				
			if ( !empty($temporary_token_ids) && count($temporary_token_ids) > 20000 )
			{
				$tempsql = "";
				foreach ( $temporary_token_ids as $token => $val ) 
				{
					$b = md5($token);
					$minichecksum = hexdec($b[0].$b[1].$b[2].$b[3]);
					$tempsql .= ",(".$connection->quote($token).", ".crc32($token).", ".$minichecksum.")";
				}
				$tempsql[0] = " ";
				$ins_start = microtime(true);
				$tokpdo = $connection->query("INSERT IGNORE INTO PMBtoktemp$index_suffix (token, checksum, minichecksum) VALUES $tempsql");
				$toktemp_total_insert += microtime(true)-$ins_start;
				unset($temporary_token_ids);
			}
		
			$docinfo_time_start = microtime(true);
			$count = count($docinfo_value_sets);
			$docpdo = $connection->query("INSERT INTO $docinfo_target_table $docinfo_columns VALUES " . implode(",", $cescape) . "");	
			++$insert_counter;		

			$log .= "docinfo ok \n";
			$loop_log .= "docinfo ok \n";
				
			# update temporary statistics
			$connection->query("UPDATE PMBIndexes SET 
							temp_loads = temp_loads + $awaiting_writes,
							updated = UNIX_TIMESTAMP(),
							current_state = 1,
							$doc_counter_target_column = $doc_counter_target_column + $awaiting_writes
							WHERE ID = $index_id");
							
			$log .= "Index statistics ok \n";
			$loop_log .= "Index statistics ok \n";

			# reset variables
			$docinfo_value_sets = array();
			$cescape 			= array();

			$docinfo_extra_time += (microtime(true)-$docinfo_time_start);

			$insert_data_sql[0] = " "; 
			$datapdo = $connection->query("INSERT INTO PMBdatatemp$index_suffix (checksum,
																				minichecksum, 
																				doc_id, 
																				field_pos
																				".$senti_sql_index_column." 
																				) VALUES $insert_data_sql");
			unset($insert_data_sql);
			$insert_data_sql = "";
			$up = 0;
			
			$log .= "new word occurances ok \n";
			$loop_log .= "new word occurances ok \n";
			
			$awaiting_writes = 0;

			try
			{
				# check indexing permission
				$perm = $connection->query("SELECT indexing_permission FROM PMBIndexes WHERE ID = $index_id");
				$permission = $perm->fetchColumn();
				
				if ( $permission == 0 )
				{
					# close the mainpdo cursor
					unset($mainpdo);

					SetProcessState($index_id, $process_number, 0);
					SetIndexingState(0, $index_id);
					die("Indexing was requested to be terminated...\n");
				}
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

}
catch ( PDOException $e ) 
{
	echo "An error occurred in the main loop: " . $e->getMessage() . "\n";
}

if ( $process_number > 0 )
{
	echo "mainpdo finished after $documents ($process_number)\n";
}

# write remaining token data
try
{
	if ( !empty($temporary_token_ids) )
	{
		$tempsql = "";
		foreach ( $temporary_token_ids as $token => $val ) 
		{
			$b = md5($token);
			$minichecksum = hexdec($b[0].$b[1].$b[2].$b[3]);
			$tempsql .= ",(".$connection->quote($token).", ".crc32($token).", ".$minichecksum.")";
		}
		$tempsql[0] = " ";
		$ins_start = microtime(true);
		$tokpdo = $connection->query("INSERT IGNORE INTO PMBtoktemp$index_suffix (token, checksum, minichecksum) VALUES $tempsql");
		$toktemp_total_insert += microtime(true)-$ins_start;
		unset($temporary_token_ids);
	}
	
	# update token statistics
	if ( $awaiting_writes ) 
	{	
		$docinfo_time_start = microtime(true);
		$count = count($docinfo_value_sets);
		$docpdo = $connection->query("INSERT INTO $docinfo_target_table $docinfo_columns VALUES " . implode(",", $cescape) . "");												
		
		$log .= "docinfo ok \n";
		$loop_log .= "docinfo ok \n";

		# reset variables
		$docinfo_value_sets = array();
		$cescape 			= array();

		$docinfo_extra_time += (microtime(true)-$docinfo_time_start);

		# insert token data
		$insert_data_sql[0] = " "; 
		$datapdo = $connection->query("INSERT INTO PMBdatatemp$index_suffix (checksum, 
																				minichecksum, 
																				doc_id, 
																				field_pos 
																				".$senti_sql_index_column." 
																				) VALUES $insert_data_sql");
		$insert_data_sql = "";
		$up = 0;

		$log .= "new word occurances ok \n";
		$loop_log .= "new word occurances ok \n";	
		
		# update temporary statistics
		$connection->query("UPDATE PMBIndexes SET 
							temp_loads = temp_loads + $awaiting_writes,
							updated = UNIX_TIMESTAMP(),
							current_state = 1,
							$doc_counter_target_column = $doc_counter_target_column + $awaiting_writes
							WHERE ID = $index_id");
	}
}
catch ( PDOException $e ) 
{
	echo "an error occurred during updating the remaining token rows \n" . $e->getMessage() . "\n";
}

# mainpdo is not needed anymore
if ( isset($mainpdo) )
{
	unset($mainpdo);
}

/*
	Now it is the time to handle temporary data! 
*/

# no need for these anymore ! 
unset($temporary_token_ids);

echo "Memory usage : " . memory_get_usage()/1024/1024 . " MB\n";
echo "Memory usage (peak) : " . memory_get_peak_usage()/1024/1024 . " MB\n";

# wait for another processes to finish
require("process_listener.php");

$interval = microtime(true) - $timer;
echo "Maintaining a list of unique tokens took $toktemp_total_insert seconds \n";
echo "Data tokenization is complete, $interval seconds elapsed, starting to compose prefixes...\n";

# check that some data is indexed; stop now otherwise
require("autostop.php");

# start prefix creation
SetIndexingState(2, $index_id);

# create prefixes
require_once("prefix_composer.php");

$interval = microtime(true) - $timer;
echo "\nNow all processes are completed\nDocinfo time: $docinfo_extra_time \n\n";

# update indexing state
SetIndexingState(5, $index_id);

try
{
	# update indexing status accordingly ( + set the indexing permission ) 
	$connection->query("UPDATE PMBIndexes SET 
						indexing_permission = 1, 
						temp_loads = 0, 
						temp_loads_left = 0
						WHERE ID = $index_id");
	
	$key_start = microtime(true);
	echo "Enabling keys for temporary tables...\n";
	$connection->exec("ALTER TABLE PMBdatatemp$index_suffix ENABLE KEYS;");
	$key_end = microtime(true) - $key_start;					
	echo "Enabling keys took: $key_end seconds \n";					
	
	$key_start = microtime(true);
	echo "Enabling keys for PMBDocinfo table...\n";
	$connection->exec("ALTER TABLE $docinfo_target_table ENABLE KEYS;");
	$key_end = microtime(true) - $key_start;	
	echo "Enabling keys took: $key_end seconds \n";				
	
	# precache table index
	$connection->query("LOAD INDEX INTO CACHE PMBtoktemp$index_suffix;");
	$connection->query("LOAD INDEX INTO CACHE PMBdatatemp$index_suffix;");
	
	$data_count = 0;
	$pre_count = 0;
						
	# get count of token position data entries
	$dcountpdo = $connection->query("SELECT COUNT(checksum) FROM PMBdatatemp$index_suffix");
	$data_count = $dcountpdo->fetchColumn();
	$total_rows = $data_count;
	
	$pcountpdo = $connection->query("SELECT COUNT(checksum) FROM PMBpretemp$index_suffix");
	$pre_count = $pcountpdo->fetchColumn();
	$total_rows += $pre_count;
	
	# update indexing status accordingly ( + set the indexing permission ) 
	$connection->query("UPDATE PMBIndexes SET
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

# update indexing state
SetIndexingState(3, $index_id);

if ( $data_count < 100000 ) 
{
	$temp_disable_multiprocessing = true;
}

# run token compressor
if ( $clean_slate || !empty($delta_indexing) )
{
	require_once("token_compressor.php");
}
else
{
	require_once("token_compressor_merger.php");
}

$temp_disable_multiprocessing = NULL;
if ( $pre_count < 100000 ) 
{
	$temp_disable_multiprocessing = true;
}

# run prefix compressor
if ( $clean_slate || !empty($delta_indexing) )
{
	require_once("prefix_compressor.php");
}
else
{
	require_once("prefix_compressor_merger.php");
}

# finalize index / ( switch tables etc )
require("finalization.php");

echo "Memory usage : " . memory_get_usage()/1024/1024 . " MB\n";
echo "Memory usage (peak) : " . memory_get_peak_usage()/1024/1024 . " MB\n";
$timer_end = microtime(true) - $timer;
echo "The whole operation took $timer_end seconds \n";


# update current indexing state to false ( 0 ) 
SetIndexingState(0, $index_id);
SetProcessState($index_id, $process_number, 0);	


?>
