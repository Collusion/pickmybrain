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

register_shutdown_function("shutdown", $index_id);

$suffix_list = array();
$suffix_list = get_suffix_list();

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

$found_urls = array();

$unwanted_characters_plain_text = array("\r\n", "\n\r", "\t", "\n", "\r", "&#13;");
$a_parents["p"] = 1;
$a_parents["i"] = 1;
$a_parents["sup"] = 1;
$a_parents["sub"] = 1;
$node_trim_chars = array("-", "/", "|");
$log = "";
$lp = 0; 
$documents = 0;

$url_list = array();
$allowed_domains = array();

# define a list of illegal/incorrect http response codes
$illegal_responses = array(400 => 1,
						  401 => 1,
						  402 => 1, 
						  403 => 1,
						  404 => 1, 
						  0   => 1);

# define a list of invalid filetypes ( non-indexable )						  
$invalid_filetypes = array("jpg" => 1,
							 "jpeg" => 1, 
							 "png" => 1, 
							 "gif" => 1, 
							 "raw" => 1, 
							 "exe" => 1, 
							 "zip" => 1, 
							 "rar" => 1, 
							 "mp3" => 1, 
							 "avi" => 1, 
							 "css" => 1, 
							 "msi" => 1,
							 "rar" => 1,
							 "dmg" => 1);
							 
$PackedIntegers = new PackedIntegers();
						  				  
# categories ( optional ) 
$categories = array();

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

# user defined keywords ? 
if ( !empty($url_keywords) )
{
	$ukeywords = StringToKeywords($url_keywords);
	$wanted_keywords = $ukeywords[0];
	$non_wanted_keywords = $ukeywords[1];
}
else
{
	$wanted_keywords = array();
	$non_wanted_keywords = array();
}

try
{
	$clean_slate = true;
	
	$connection = db_connection();
	$connection->query("SET NAMES UTF8");
	
	# check current indexing state
	$ind_state = $connection->prepare("SELECT current_state, updated, documents, delta_documents, latest_rotation, max_id FROM PMBIndexes WHERE ID = ?");
	$ind_state->execute(array($index_id));
	
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
	}
	else
	{
		die("Unknown index id $index_id");
	}
		
	# update current indexing state to true ( 1 ) 
	SetProcessState($index_id, $process_number, 1);
	SetIndexingState(1, $index_id);
	
	$upd_state = $connection->prepare("UPDATE PMBIndexes SET indexing_started = UNIX_TIMESTAMP() WHERE ID = ?");
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
		$connection->exec("TRUNCATE TABLE PMBDocinfo$index_suffix");
		$connection->query("UPDATE PMBIndexes SET documents = 0, delta_documents = 0, max_id = 0 WHERE ID = $index_id");
	}
	else if ( !empty($replace_index) )
	{
		$connection->exec("DROP TABLE IF EXISTS PMBDocinfo".$index_suffix."_temp;
							CREATE TABLE IF NOT EXISTS PMBDocinfo".$index_suffix."_temp (
							 ID mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
							 URL varbinary(500) NOT NULL,
							 url_checksum binary(16) NOT NULL,
							 token_count varchar(60) NOT NULL,
							 avgsentiscore tinyint(4) DEFAULT '0',
							 attr_category tinyint(3) unsigned DEFAULT NULL,
							 field0 varbinary(255) NOT NULL,
							 field1 varbinary(10000) NOT NULL,
							 field2 varbinary(255) NOT NULL,
							 field3 varbinary(255) NOT NULL,
							 attr_timestamp int(10) unsigned NOT NULL,
							 checksum binary(16) NOT NULL,
							 attr_domain int(10) unsigned NOT NULL,
							 PRIMARY KEY (ID),
							 KEY avgsentiscore (avgsentiscore),
							 KEY attr_category (attr_category),
							 KEY url_checksum (url_checksum)
							) ENGINE=INNODB DEFAULT CHARSET=utf8 $innodb_row_format_sql $data_dir_sql");	
	}
	
	$doc_counter_target_column = "documents";
	$docinfo_target_table = "PMBDocinfo$index_suffix";
	
	if ( !empty($replace_index) )
	{
		$docinfo_target_table = "PMBDocinfo".$index_suffix."_temp";
		$clean_slate = true;
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
									ID mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
									URL varbinary(500) NOT NULL,
									url_checksum binary(16) NOT NULL,
									token_count varchar(60) NOT NULL,
									avgsentiscore tinyint(4) DEFAULT '0',
									attr_category tinyint(3) unsigned DEFAULT NULL,
									field0 varbinary(255) NOT NULL,
									field1 varbinary(10000) NOT NULL,
									field2 varbinary(255) NOT NULL,
									field3 varbinary(255) NOT NULL,
									attr_timestamp int(10) unsigned NOT NULL,
									checksum binary(16) NOT NULL,
									attr_domain int(10) unsigned NOT NULL,
									PRIMARY KEY (ID),
									KEY url_checksum (url_checksum)
									) ENGINE=MYISAM DEFAULT CHARSET=utf8 PACK_KEYS=1 ROW_FORMAT=FIXED $data_dir_sql AUTO_INCREMENT=$min_doc_id");
			}
			catch ( PDOException $e ) 
			{
				echo "Creating docinfo delta table failed :( \n";
			}
		}
	}
	
	if ( $clean_slate || isset($purge_index) ) 
	{				
		if ( empty($replace_index) )
		{			
			$connection->exec("TRUNCATE TABLE PMBTokens$index_suffix;
							TRUNCATE TABLE PMBPrefixes$index_suffix;
							ALTER TABLE PMBTokens$index_suffix ENGINE=INNODB $innodb_row_format_sql;
							ALTER TABLE PMBPrefixes$index_suffix ENGINE=INNODB $innodb_row_format_sql");
		}
				
		# create new temporary tables		   
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

	echo "Temp tables created \n";					
						
	$timer = microtime(true);
	$tokcount = 0;			 
	
	$log .= "Configuring seed urls:\n----------------------\n";
	
	# add the seed url into the list
	if ( !empty($seed_urls) )
	{
		foreach ( $seed_urls as $seed_url ) 
		{
			if ( $dinfo = getDomainInfo($seed_url, $suffix_list) ) 
			{
				$allowed_domains[$dinfo["domain"]][$dinfo["subdomain"]] = 1;
				
				if ( $dinfo["subdomain"] === "*" )
				{
					$log .= "Allowed domain " . $dinfo["domain"] . " with subdomain wildcard\n";
				}
				else
				{
					$log .= "Allowed domain " . $dinfo["domain"] . " with subdomain (" .$dinfo["subdomain"]. ")\n";
				}
			}

			# hack: replace wildcard subdomains * with www
			$seed_url = str_replace("/*.", "/www.", $seed_url);
			$url_list[] = $seed_url;
			$checked_urls[pack("H*", md5($seed_url))] = 1;
			
			# create local versions of seed urls
			if ( $use_localhost )
			{
				$local_url = get_local_url($seed_url, $suffix_list, $custom_address);
				
				if ( $linfo = getDomainInfo($local_url, $suffix_list) ) 
				{
					$allowed_domains[$linfo["domain"]][$linfo["subdomain"]] = 1;
					
					$log .= "Allowed domain " . $linfo["domain"] . " with subdomain (" .$linfo["subdomain"]. ")\n";
				}
			}
		}	
	}
	
	$log .= "\n";

	# run pdf self check if test mode is on
	if ( $test_mode )
	{
		# test mode on
		if ( $index_pdfs ) 
		{
			# $xpdf_folder 
			# test if xpdf actually works
			$log .= "PDF indexer self-test starts \n----------------------------\n";
			if ( !$enable_exec ) 
			{
				$log .= "NOTICE: PDF-indexing is enabled, but exec() script execution method is disabled. PDFs will not be indexed \n";
				$xpdf_folder = "";
			}
			else if ( empty($xpdf_folder) )
			{
				$log .= "NOTICE: PDF-indexing is enabled, but the path for the pdftotext program is not defined. PDFs will not be indexed \n";
			}
			else
			{
				$dir = realpath(dirname(__FILE__));
				if ( is_readable($dir . "/pdftest.pdf") )
				{
					# remove the old txt file if it exists
					@unlink($dir . "/pdftest.txt");
					
					$output = array();
					# text if thisworks
					exec("$dir/$xpdf_folder $dir/pdftest.pdf 2>&1", $output);
					
					if ( is_readable($dir . "/pdftest.txt") && $testpdf = file_get_contents($dir . "/pdftest.txt") ) 
					{
						# success,
						#$log .= "pdf test file was successfully opened with content $testpdf \n";
						if ( stripos($testpdf, "123pdftest") !== false ) 
						{
							$log .= "SUCCESS: PDF test file was successfully opened and the content was successfully recognized. \n";
						}
						else
						{
							$log .= "NOTICE: pdf test file was successfully opened but the content was unrecognizable. \n";
						}
					}
					else
					{
						$log .= "NOTICE: PDF self-test failed. Additionally, exec() function outputted the following: " . implode("\n", $output) . "\n";
					}
				}
				else
				{
					$log .= "NOTICE: PDF self-test failed, because opening the required test file pdftest.pdf failed. Please make sure that the file exists and has proper permissions. \n";
				}
				
			}
			
			$log .= "\n";
		}
	}

	#preload user defined categories
	$catpdo = $connection->query("SELECT * FROM PMBCategories$index_suffix");
	while ( $row = $catpdo->fetch(PDO::FETCH_ASSOC) )
	{
		# create 
		if ( $row["type"] == 0 ) 
		{
			# filter by attributes
			$attr_categories[trim(mb_strtolower($row["keyword"]))] = $row["ID"];
		}
		else
		{
			# filter by url
			$catkeywords = StringToKeywords($row["keyword"]);
			$categories[(int)$row["ID"]] = $catkeywords;
		}
	}
}
catch ( PDOException $e ) 
{
	echo $e->getMessage();
	return;
}

if ( empty($url_list) )
{
	$log .= "URL list is empty, nothing to index. Please define seed urls to start.";
}

$bitshift = requiredBits($number_of_fields);
$indexed_docs = 0;
$token_stat_time = 0;
$insert_buffer = "";
$insert_counter = 0;
$flush_interval = 10;
$write_buffer_len = 3;
$awaiting_writes = 0;
$data_rows = 0;
$toktemp_total_insert = 0;
$docinfo_extra_time = 0;
$doc_prefix = chr(27);

if ( !empty($mysql_data_dir) )
{
	$directory = $mysql_data_dir; # custom directory
}
else
{
	$directory = realpath(dirname(__FILE__));
}

$filename = $directory . "/datatemp_".$index_id."_".$process_number.".txt";
# create a new temporary file
# remoe existing files
@unlink($filename);
$f = fopen($filename, "a");

# start by disabling autocommit
# flush write log to disk at every write buffer commit
$connection->beginTransaction();

while ( !empty($url_list[$lp]) )
{	
	$catpointer = 0;
	$category_ids = array(NULL); 	# reset categories
	$url = $url_list[$lp];
	++$lp;  # advance the array pointer by one
	++$documents;
	
	# get a combination checksum for subdomain and domain
	$domain_info = getDomainInfo($url, $suffix_list);
	$url_domain = "";
	if ( !empty($domain_info["subdomain"]) )
	{
		$url_domain = $domain_info["subdomain"] . ".";
	}
	$url_domain .= $domain_info["domain"];
	$domain_checksum = crc32(mb_strtolower($url_domain));
	
	# free some memory ( remove the last entry from the url_list )
	unset($url_list[$lp-1]);
	
	$log .= "processing: $url \n";
	
	if ( empty($url) )
	{
		$log .= "INVALID URL: $url \n";
		continue;
	}

	if ( $use_localhost ) 
	{
		$complete_url = get_local_url($url, $suffix_list, $custom_address);
		$log .= "effective url: $complete_url , was $url \n";
	}
	else
	{
		$complete_url = $url;
	}
	
	# pdf file ! 
	if ( stripos($complete_url, ".pdf") !== false ) 
	{
		if ( !empty($xpdf_folder) && $enable_exec && $index_pdfs ) 
		{
			$log .= "found pdf $url \n";
			$dir = realpath(dirname(__FILE__));
			$fp = fopen ($dir . '/tempdocument.pdf', 'w+');				# create the temporary file
			$ch = curl_init(str_replace(" ", "%20", $complete_url));
			curl_setopt($ch, CURLOPT_TIMEOUT, 50);
			curl_setopt($ch, CURLOPT_FILE, $fp);						# write the curl response directly into the file
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_exec($ch);
			$http_code		= curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$effective_url 	= curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
			curl_close($ch);
			fclose($fp);
			
			# illegal response
			if ( isset($illegal_responses[$http_code]) )
			{
				$log .= "Invalid response $http_code for $url, aborting... \n";
			
				# optional: add url into kill-list 
				
				continue;
			}
			
			if ( $effective_url !== $url ) 
			{
				$e_data = getDomainInfo($effective_url, $suffix_list);
			}
			
			# check for redirections ! 
			if ( $effective_url !== $url && !domainCheck($e_data, $allowed_domains, $allow_subdomains) ) 
			{
				$log .= "address $url redirected to $effective_url\n";
			
				# special case: localhost is enabled and the address redirects into another localhost address
				# convert to new localhost address into new address with correct domain
				
				# get the original domain
				if ( $use_localhost ) 
				{
					# replace the localhost domain with the original domain again, and check 
					
					$earr = array();
					$url_copy = $url;
					$url = get_local_url($effective_url, $earr, parse_url($url, PHP_URL_HOST));
					$log .= "final global address for $effective_url is $url\n";
				}
				else
				{
					# update the url
					$url_copy = $url;
					$url = $effective_url;
				}
				
				# check again if keyword conditions are met
				if ( !keyword_check($url, $wanted_keywords, $non_wanted_keywords) )
				{
					# keyword check failed
					$log .= "keyword check fail: $url \n";
					continue;
				}
				
				# delete the document if a real redirection happened ! 
				# index the redirected address instead
				if ( $url !== $url_copy )
				{
					# optional: add url_copy ( the old url ) into kill-list
				}
				
			}
			
			#$log .= "";
			$output = array();
			# xpdf_mode contains both os/bit folders
			# now that the file is saved, process it with xpfd
			# a new txt file with tempdocument-name is createds
			exec("$dir/$xpdf_folder $dir/tempdocument.pdf 2>&1", $output);
			
			# open the file created just now
			$pdfcontents = file_get_contents("$dir/tempdocument.txt");
			
			# use the filename as result title
			$filename =	pathinfo($url, PATHINFO_FILENAME);
			
			$fields = array();
			$fields[0]	= $filename;
			$fields[1] 	= $pdfcontents;
			$fields[2]  = str_ireplace(array("https", "http", "www"), " ", $url);
			
		}
		else
		{
			# xpdf undefined or exec() script execution method disabled
			continue;
		}
	}
	else
	{
	
		# fetch the given url
		$web = new webRequest(array($complete_url));
		$web->startRequest();
		$result 		= $web->getResult();
		$redirections 	= $web->getRedirections();
		$responses 		= $web->getResponses();
		
		if ( !empty($redirections[0]) && $redirections[0] !== $url ) 
		{
			$e_data = getDomainInfo($redirections[0], $suffix_list);
		}
		
		# if a redirection has occurred
		# the new redirected address must be different than the original ( unaltered ) address
		# and domainCheck fails
		# this is a incorrect URL, do not continue
		if ( !empty($redirections[0]) && $redirections[0] !== $url && !domainCheck($e_data, $allowed_domains, $allow_subdomains) )
		{
			$log .= "Redirected to: " . $redirections[0] . " aborting ... (domainCheck failed)\n";
			
			# optional: add document into kill-list
			# both url ( original ) and $redirections[0] ( the new url ) 
			
			continue;
		}
	
		# 404 response ?
		if ( isset($illegal_responses[$responses[0]]) ) 
		{
			$log .= "Invalid response " . $responses[0] . " for $url, aborting... \n";
			
			# optional: add document into kill-list

			continue;
		}
		
		# if redirected, update the url now 
		if ( !empty($redirections[0]) )
		{
			$log .= "address $url redirected to " . $redirections[0] . "\n";
			
			# special case: localhost is enabled and the address redirects into another localhost address
			# convert to new localhost address into new address with correct domain
			
			# get the original domain
			if ( $use_localhost ) 
			{
				$earr = array();
				$url_copy = $url;
				$url = get_local_url($redirections[0], $earr, parse_url($url, PHP_URL_HOST));
				$log .= "final global address for " . $redirections[0] . " is $url\n";
			}
			else
			{
				# update the url
				$url_copy = $url;
				$url = $redirections[0];
			}
			
			# check again if keyword conditions are met
			if ( !keyword_check($url, $wanted_keywords, $non_wanted_keywords) )
			{
				# keyword check failed
				$log .= "keyword check fail: $url \n";
				continue;
			}
			
			# if everything is okay, delete the old document ! 
			if ( $url !== $url_copy ) 
			{
				# optional: add url_copy ( the old url ) into kill-list
			}
		}

		# does the url belong to any category ? 
		if ( !empty($categories) )
		{
			foreach ( $categories as $cat_id => $cat_data )
			{
				if ( keyword_check($url, $cat_data[0], $cat_data[1]) )
				{
					$log .= "proper category for $url \n";
					# this url has a proper category
					#$category_id = $cat_id;
					$category_ids[$catpointer++] = $cat_id;
				}
			}
		}
		
		$doc_data = $result[0];
		
		if ( empty($doc_data) )
		{
			$log .= "empty data for $url \n";
			continue;
		}
		
		# tokenize
		$dom = new DOMDocument();
		libxml_use_internal_errors(TRUE);
		$dom->loadHTML(mb_convert_encoding($doc_data, 'html-entities', "UTF-8"));
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = false;
		$base_url = url_basestructure($url);
		$removes = 0;
		
		# attribute based sentiment analysis
		if ( $sentiment_analysis == 1001 ) 
		{
			$sentiment_class_name = ""; # reset class name at every new document
			
			# get all html tags, but analyze only the first one
			$html_nodes = $dom->getElementsByTagName("html");
			
			if ( !empty($html_nodes) && $html_nodes->length > 0 ) 
			{
				foreach ( $html_nodes as $html_node ) 
				{
					if ( $html_node->hasAttribute("lang") ) 
					{
						switch ( $html_node->getAttribute("lang") )
						{
							case 'en':
							$sentiment_class_name = "sentiment_en";
							break;
							
							case 'fi':
							$sentiment_class_name = "sentiment_fi";
							break;
							
							default:
							# unknown language
							break;
						}
					}
					
					break;
				}
			}
		}
		
		# first, remove all script tags
		$scripts = $dom->getElementsByTagName("script");
		
		if ( !empty($scripts) && $scripts->length > 0 ) 
		{
			$log .= "found " . $scripts->length ." script nodes \n";
			
			foreach ( $scripts as $script ) 
			{
				$script->parentNode->removeChild($script);
				++$removes;
			}
		}
		
		# remove all style tags
		$styles = $dom->getElementsByTagName("style");
		
		if ( !empty($styles) && $styles->length > 0 ) 
		{
			$log .= "found " . $styles->length ." style nodes \n";
			
			foreach ( $styles as $style ) 
			{
				$style->parentNode->removeChild($style);
				++$removes;
			}
		}
		
		# does the page have any user defined attribute-categories ?
		if ( !empty($attr_categories) )
		{
			$catnode = $dom->getElementById("pmb-category");
			
			if ( !empty($catnode) )
			{
				if ( $catnode->hasAttribute("data-pmb-category") )
				{
					$temp_categories = explode(",", mb_strtolower($catnode->getAttribute("data-pmb-category")));
					
					foreach ( $temp_categories as $catdesc )
					{
						$catdesc = trim($catdesc);
						if ( !empty($attr_categories[$catdesc]) )
						{
							# dis category exists ! 
							$category_ids[$catpointer++] = $attr_categories[$catdesc];
						}
					}
				}
				
			}
		}
		
		# reset url arrays
		$temp_links = array();
		#$outgoing_links[$url] = array();
		$remove_links = array();
			
		$frames = $dom->getElementsByTagName('iframe');
		
		if ( !empty($frames) && $frames->length > 0 ) 
		{
			foreach ( $frames as $frame ) 
			{
				if ( !$frame->hasAttribute("src") )
				{
					# nothing to follow
					continue;
				}
				
				$src = trim($frame->getAttribute("src"));
				
				if ( empty($src) )
				{
					# empty source
					continue;
				}
				
				$d_info = getDomainInfo($src, $suffix_list);
				
				if ( domainCheck($d_info, $allowed_domains, $allow_subdomains) )
				{	
					# convert to an absolute url
					$src = uniteurl($base_url, $src);
				
					# check for fragment and cut it off if necessary
					if ( $hashpos = strpos($src, "#") )
					{
						$src = mb_substr($src, 0, $hashpos);
					}
					
					# check for keywords
					if ( !keyword_check($src, $wanted_keywords, $non_wanted_keywords) )
					{
						# keyword check failed
						#$log .= "keyword check fail: $url \n";
						continue;
					}
					
					$md5_src = pack("H*", md5($src));
					
					# investigate this page ? 
					if ( empty($checked_urls[$md5_src]) && !$test_mode )
					{
						$temp_links[$md5_src] = $src;
						$checked_urls[$md5_src] = 1;
					}
				}
				
			}
		}
		
		$links = $dom->getElementsByTagName('a');
		
		# find all the links 
		if ( !empty($links) && $links->length > 0 )
		{
			foreach ( $links as $link ) 
			{
				# no-follow attriburtes
				if ( $honor_nofollows && $link->hasAttribute("rel") && strtolower($link->getAttribute("rel")) === "nofollow" ) 
				{
					# link has nofollow attributes and user has chosen to honor them
					continue;
				}
						
				$remove = false;			
				$href = "";
				
				if ( !$link->hasAttribute("href") || "" === ($href = trim(html_entity_decode($link->getAttribute("href")))) )
				{
					# nothing to follow, remove link from dom
					$remove = true;
				}
				
				# no javascript/mailto/tel links
				$count = 0;
				str_replace(array("javascript:", "mailto:", "whatsapp:", "intent:"), "", $href, $count);
				
				if ( empty($href) || $count || $href[0] === "#" || strpos($href, "tel:") === 0 )
				{
					$remove = true;
				}

				# does the link point towards the site itself ? 
				if ( !$remove && domainCheck(getDomainInfo($href, $suffix_list), $allowed_domains, $allow_subdomains) )
				{	
					# transform the url into an absolute url ( if necessary ) 
					$href = uniteurl($base_url, $href);
				
					# check for fragment and cut it off if necessary
					if ( $hashpos = strpos($href, "#") )
					{
						$href = mb_substr($href, 0, $hashpos);
					}
						
					# check if url contains an illegel file extension
					$apath = parse_url($href, PHP_URL_PATH);
					$extension = pathinfo($apath, PATHINFO_EXTENSION);
					
					if ( !empty($invalid_filetypes[$extension]) )
					{
						# invalid filetype
						# append href into document and continue
						$a = $dom->createTextNode(str_replace(array("http://", "https://", "ftp://", "."), " ", $href));
						$link->parentNode->insertBefore($a, $link);
						$log .= "invalid file type for url $href ($extension)\n";
						continue;
					}
					
					# check for keywords
					if ( !keyword_check($href, $wanted_keywords, $non_wanted_keywords) )
					{
						# keyword check failed
						$log .= "keyword check fail: $href \n";
						continue;
					}
					
					$md5_href = pack("H*", md5($href));
					$md5_alt = false;
					
					# do extra check for the url
					if ( substr($href, -1) === "/" ) 
					{
						$md5_alt = pack("H*", md5(substr($href, 0, -1)));
					}
			
					# investigate this page ? 
					if ( empty($checked_urls[$md5_href]) && !$test_mode && (!$md5_alt || empty($checked_urls[$md5_alt])) )
					{
						$temp_links[$md5_href] = $href;
						$checked_urls[$md5_href] = 1;
						
						if ( $md5_alt )
						{
							$checked_urls[$md5_alt] = 1;
						}
					}
				
					# default: remove innerlinks
					$remove = true;
				}
				else if ( !$remove ) 
				{
					$remove = false;
					$log .= "not an innerlink: $href \n";
					# link points outside current domain
					# append the href attribute into the document
					# remove points from this ? 
					$a = $dom->createTextNode(str_replace(array("http://", "https://", "ftp://", "."), " ", $href));
					$link->parentNode->insertBefore($a, $link);
					
					# we done, this link will not be removed
					continue;
				}
				
				# previous sibling is text node, do not remove link from dom
				if ( $link->previousSibling && $link->previousSibling->nodeType === 3 )
				{
					$val = trim(str_replace($node_trim_chars, "", html_entity_decode($link->previousSibling->nodeValue)), " \t\n\r\0\x0B".chr(0xC2).chr(0xA0) );
					if ( !empty($val) )
					{
						$remove = false;
					}
				}
				
				# next sibling is text node, do not remove link from dom
				if ( $link->nextSibling && $link->nextSibling->nodeType === 3 ) 
				{
					$val = trim(str_replace($node_trim_chars, "", html_entity_decode($link->nextSibling->nodeValue)), " \t\n\r\0\x0B".chr(0xC2).chr(0xA0) );
					if ( !empty($val) )
					{
						$remove = false;
					}
				}
				
				if ( $remove ) 
				{
					# check the link's parentnode if not eligible
					if ( $link->parentNode && $link->parentNode->nodeType === 1 && !empty($a_parents[$link->parentNode->nodeName]) ) 
					{
						if ( $link->parentNode->previousSibling && $link->parentNode->previousSibling->nodeType === 3 )
						{
							$val = trim(str_replace($node_trim_chars, "", html_entity_decode($link->parentNode->previousSibling->nodeValue)), " \t\n\r\0\x0B".chr(0xC2).chr(0xA0) );
							if ( !empty($val) )
							{
								$remove = false;
							}
						}
						
						if ( $remove && $link->parentNode->nextSibling && $link->parentNode->nextSibling->nodeType === 3 )
						{
							$val = trim(str_replace($node_trim_chars, "", html_entity_decode($link->parentNode->nextSibling->nodeValue)), " \t\n\r\0\x0B".chr(0xC2).chr(0xA0) );  # # chr(0xC2).chr(0xA0) 
							if ( !empty($val) )
							{
								$remove = false;
							}
						}	
					}
				}
				
				if ( $remove ) 
				{
					$log .= "removing $href \n";
					$remove_links[] = $link;	# store the domnode and remove later
				}
				
			}
			
			# remove links from dom
			if ( !empty($remove_links) )
			{
				foreach ( $remove_links as $link ) 
				{
					$link->parentNode->removeChild($link);
					++$removes;
				}
			}
		}
		
		# if there are proper links, investigate
		if ( !empty($temp_links) )
		{
			$t_sql = "";
			foreach ( $temp_links as $bin_md5 => $source ) 
			{
				$t_sql .= ",".$connection->quote($bin_md5);
			}
			$t_sql[0] = " ";
			
			if ( !empty($delta_indexing) && !$clean_slate )
			{
				$linksql = "(
							SELECT url_checksum FROM $docinfo_target_table WHERE url_checksum IN ($t_sql)
							)
							UNION ALL
							(
							SELECT url_checksum FROM PMBDocinfo$index_suffix WHERE url_checksum IN ($t_sql) AND ID < $min_doc_id
							)";
			}
			else
			{
				$linksql = "SELECT url_checksum FROM $docinfo_target_table WHERE url_checksum IN ($t_sql)";
			}

			try
			{	
				$linkpdo = $connection->query($linksql);

				while ( $row = $linkpdo->fetch(PDO::FETCH_ASSOC) )
				{
					unset($temp_links[$row["url_checksum"]]);
				}
	
				if ( !empty($temp_links) )
				{
					foreach ( $temp_links as $tmp_link ) 
					{
						$url_list[] = $tmp_link;
					}
				}
			}
			catch ( PDOException $e ) 
			{
				echo $e->getMessage();
				$log .= $e->getMessage();
				return;
			}
		}

		# define fields here
		$fields = array();
		$fields[0] = strip_tags($dom->saveXML($dom->getElementsByTagName('title')->item(0)));	# get the page title content
		$fields[1] = strip_tags(str_replace(array("<",">"), array(" <","> "), preg_replace('/<!\[cdata\[(.*?)\]\]>/is', "", $dom->saveXML($dom->getElementsByTagName('body')->item(0)))));	# get the body content
		$fields[2] = removedomain($url);					# get the url and do not index to domain name
			
		$description = "";
		$meta_len = 0;
		$metanodes = $dom->getElementsByTagName('meta');
	
		if ( !empty($metanodes) )
		{
			foreach ( $metanodes as $meta ) 
			{
				if ( strtolower($meta->getAttribute('name')) === 'description' || strtolower($meta->getAttribute('property')) === 'og:description' ) 
				{
					$mlen = mb_strlen($meta->getAttribute('content'));
					
					if ( $mlen > $meta_len ) 
					{
						$description = $meta->getAttribute('content');
						$meta_len = $mlen;
					}
				}
			}
		}
		
		# fields[3]: the meta description ( if available ) 
		$fields[3] = $description;
	}
	
	try
	{
		if ( !empty($delta_indexing) && !$clean_slate )
		{
			$checksumsql = "(
							SELECT url_checksum FROM $docinfo_target_table WHERE url_checksum = UNHEX(MD5(:url))
							)
							UNION ALL
							(
							SELECT url_checksum FROM PMBDocinfo$index_suffix WHERE url_checksum = UNHEX(MD5(:url)) AND ID < $min_doc_id
							)";
		}
		else
		{
			$checksumsql = "SELECT url_checksum FROM $docinfo_target_table WHERE url_checksum = UNHEX(MD5(:url))";
		}
		
		# this checksum is the checksum for content, not url
		$checksumpdo = $connection->prepare($checksumsql);	
		$checksumpdo->execute(array(":url" => $url));
		$db_md5_checksum = "";
		
		if ( $row = $checksumpdo->fetch(PDO::FETCH_ASSOC) )
		{
			# document is old, no need to continue after this point, do not update
			continue;
		}

		++$indexed_docs;
		# document is new ! 
		$log .= "db md5 for $url is $db_md5_checksum \n";		
	}
	catch ( PDOException $e ) 
	{
		echo "An error occurred while checking whether document pre-exists: " . $e->getMessage() . "\n";
		continue;
	}
	
	# trim page title?
	if ( !empty($trim_page_title) )
	{
		$fields[0] = str_ireplace($trim_page_title, " ", $fields[0]);
	}

	# get the md5 checksum for this page
	$md5_checksum = strtolower(md5(implode(" ", $fields)));
	
	# for plain thext
	$title_plain_text = preg_replace('/\s+/', ' ', str_replace($unwanted_characters_plain_text, " ", mb_substr($fields[0], 0, 5000)));
	$body_plain_text = preg_replace('/\s+/', ' ', str_replace($unwanted_characters_plain_text, " ", mb_substr($fields[1], 0, 5000)));
	
	$word_sentiment_scores = array();
	$document_avg_score = 0;
	
	$tokens = array();
	unset($blend_replacements);

	/*
		CHARSET PROCESSING STARTS
	*/	
	foreach ( $fields as $f_id => $field ) 
	{
		# add spaces in the front and the back of each field for the single blend char removal to work
		$field = " " . $field . " ";
		
		# if sentiment analysis is enabled ( skip the url field ) 
		if ( $f_id !== 2 && $sentiment_analysis && !empty($field) && !empty($sentiment_class_name) ) 
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

		# decode html entities
		$field = html_entity_decode($field);
		
		# convert to lowercase
		$field = mb_strtolower($field);
		
		# unwanted characters
		$field = str_replace($unwanted_characters_plain_text, " ", $field);
		
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
		
		$pos = 1;
		$expl = explode(" ", $field);

		# ota kiinni tapaukset jossa expl-array vaan yhden alkion pituinen
		foreach ( $expl as $m_i => $match ) 
		{
			if ( empty($forbidden_tokens[$match]) )
			{
				$temporary_token_ids[$match] = 1;

				if ( !isset($tokens[$match]) ) 
				{
					$tokens[$match] = " ".$PackedIntegers->int_to_bytes(($pos<<$bitshift)|$f_id);
				}
				else
				{
					$tokens[$match] .= " ".$PackedIntegers->int_to_bytes(($pos<<$bitshift)|$f_id);
				}
				
				# if this token consists blend_chars and has a parallel version
				if ( isset($blend_replacements[$match]) )
				{
					$t_pos = $pos;
					foreach ( explode(" ", $blend_replacements[$match]) as $token_part ) 
					{
						if ( $token_part === "" ) continue;
						
						$temporary_token_ids[$token_part] = 1;

						if ( empty($tokens[$token_part]) ) 
						{
							$tokens[$token_part] = " ".$PackedIntegers->int_to_bytes(($t_pos<<$bitshift)|$f_id);
						}
						else
						{
							$tokens[$token_part] .= " ".$PackedIntegers->int_to_bytes(($t_pos<<$bitshift)|$f_id);
						}
						++$t_pos;
					}
				}

				++$pos;
			}
		}
	}

	$aw = $doc_prefix."$awaiting_writes";

	unset($expl, $fields);
	
	if ( $sentiment_analysis ) 
	{
		foreach ( $tokens as $token => $string ) 
		{
			$crc32 = crc32($token);
			$b = md5($token);
			$tid = hexdec($b[0].$b[1].$b[2].$b[3]);
			$checksum_48bit = ($crc32<<16)|$tid;
				
			if ( isset($word_sentiment_scores[$token]) )
			{
				$wordsenti = $word_sentiment_scores[$token];
				if ( $wordsenti < -128 ) 
				{
					$wordsenti = -128;
				}
				else if ( $wordsenti > 127 )
				{
					$wordsenti = 127;
				}
				
				$wordsenti = $wordsenti+128; # negative values not allowed
			}
			else
			{
				$wordsenti = 128; # equal to zero
			}
			
			# PackedIntegers
			if ( empty($insert_buffer_array[$checksum_48bit]) )
			{
				$insert_buffer_array[$checksum_48bit] = "$aw " . $PackedIntegers->int_to_bytes($wordsenti) . "$string";
			}
			else
			{
				$insert_buffer_array[$checksum_48bit] .= "  $aw " . $PackedIntegers->int_to_bytes($wordsenti) . "$string";
				
			}

			++$data_rows;
		}
	}
	else
	{
		foreach ( $tokens as $token => $string ) 
		{
			$crc32 = crc32($token);
			$b = md5($token);
			$tid = hexdec($b[0].$b[1].$b[2].$b[3]);
			$checksum_48bit = ($crc32<<16)|$tid;
			
			if ( empty($insert_buffer_array[$checksum_48bit]) )
			{
				$insert_buffer_array[$checksum_48bit] = "$aw" . "$string";
			}
			else
			{
				$insert_buffer_array[$checksum_48bit] .= "  $aw" . "$string";
			}

			++$data_rows;
		}
	}
	
	unset($tokens);
	
	try
	{	
		$loop_log = "";	

		if ( $catpointer ) 
		{
			foreach ( $category_ids as $cat_id )
			{
				if ( $cat_id !== NULL )
				{
					$connection->query("UPDATE PMBCategories$index_suffix SET count = count + 1 WHERE ID = $cat_id");
				}
			}
		}

		$aw = $awaiting_writes;
		$docinfo_value_sets[] = "(:id$aw, :url$aw, UNHEX(MD5(:url$aw)), :avgsenti$aw, :field0$aw, :field1$aw, :cat_id$aw,  UNIX_TIMESTAMP(), :d_checksum$aw, UNHEX(:checksum$aw))";
		$cescape[":id$aw"] 			= NULL;
		$cescape[":url$aw"] 		= $url;
		$cescape[":avgsenti$aw"] 	= $document_avg_score;
		$cescape[":field0$aw"] 		= $title_plain_text;
		$cescape[":field1$aw"] 		= $body_plain_text;
		$cescape[":cat_id$aw"] 		= $category_ids[0];
		$cescape[":d_checksum$aw"] 	= $domain_checksum;	
		$cescape[":checksum$aw"] 	= $md5_checksum;
						
		$log .= "docinfo ok \n";
		$loop_log .= "docinfo ok \n";
		$loop_log .= "prepdo ok \n";

		$temporary_ids[$aw] = true;

		# increase the awaiting_writes counter
		++$awaiting_writes;

		# update token statistics
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
				$tok_insert_start = microtime(true);
				$tokpdo = $connection->query("INSERT IGNORE INTO PMBtoktemp$index_suffix (token, checksum, minichecksum) VALUES $tempsql");
				$toktemp_total_insert += (microtime(true)-$tok_insert_start);
				unset($temporary_token_ids);
			}
		
			# update temporary statistics
			$connection->query("UPDATE PMBIndexes SET 
						temp_loads = temp_loads + $awaiting_writes,
						updated = UNIX_TIMESTAMP(),
						current_state = 1,
						$doc_counter_target_column = $doc_counter_target_column + $awaiting_writes
						WHERE ID = $index_id");
						
			$docinfo_time_start = microtime(true);
			$count = count($docinfo_value_sets);
			$docpdo = $connection->prepare("INSERT INTO $docinfo_target_table
											(
											ID, 
											URL, 
											url_checksum, 
											avgsentiscore,
											field0, 
											field1, 
											attr_category, 
											attr_timestamp,
											attr_domain, 
											checksum
											) 
											VALUES " . implode(",", $docinfo_value_sets) . "
											");												
			$docpdo->execute($cescape);
			$last_id = $connection->lastInsertId();
			$docinfo_extra_time += (microtime(true)-$docinfo_time_start);
			
			# replace the :1 -> :20 entries with correct document ids
			$insert_find = array();
			$insert_repl = array();
			$j = 0;
			for ( $i = $last_id ; $i < $last_id+$count ; ++$i )
			{
				if ( isset($temporary_ids[$j]) )
				{
					$insert_find[] = $doc_prefix."$j";
					$insert_repl[] = $PackedIntegers->int32_to_bytes5($i);
				}
				++$j;
			}
	
			$log .= "token data inserts start  \n";
			$loop_log .= "token data inserts start  \n";

			$insert_buffer = "";
			foreach ( $insert_buffer_array as $checksum_48bit => $doc_match_data ) 
			{
				$insert_buffer .= $PackedIntegers->int48_to_bytes7($checksum_48bit) . " $doc_match_data\n";
			}
			
			# replace the document id placeholders with actual document ids
			$insert_buffer = str_replace($insert_find, $insert_repl, $insert_buffer);

			fwrite($f, $insert_buffer);
			unset($insert_buffer, $insert_buffer_array, $insert_buffer_delta);
			
			$log .= "token data inserts OK  \n";
			$loop_log .= "token data inserts OK  \n";
			
			$insert_data_sql = "";
			$awaiting_writes = 0;
			
			$log .= "new word occurances ok \n";
			$loop_log .= "new word occurances ok \n";
			
			$docinfo_value_sets = array();
			$cescape 			= array();
			
			++$insert_counter;		
			# commit changes to disk and start a new transaction
			if ( $insert_counter >= $flush_interval )
			{
				$connection->commit();
				$connection->beginTransaction();
				$insert_counter = 0;
			}
			
			try
			{
				# check indexing permission
				$perm = $connection->query("SELECT indexing_permission FROM PMBIndexes WHERE ID = $index_id");
				$permission = $perm->fetchColumn();
				
				if ( !$permission )
				{
					SetProcessState($index_id, $process_number, 0);
					$connection->query("UPDATE PMBIndexes SET current_state = 0 WHERE ID = $index_id");
					die ("Indexing was requested to be terminated...\n");
				}
			}
			catch ( PDOException $e ) 
			{
				
			}
		}
	}
	catch ( PDOException $e ) 
	{
		echo "Error when handling url $url and doc_id $document_id \n";
		
		var_dump($e->getMessage());
		var_dump($loop_log);
		$log .= $e->getMessage();
		if ( $test_mode ) 
		{
			echo $log;
		}
		
		print_r($insert_data_sql);
		
		SetIndexingState(0, $index_id);
		return false;
	}

	if ( !$test_mode ) $log = "";
}


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
		$tok_insert_start = microtime(true);
		$tokpdo = $connection->query("INSERT IGNORE INTO PMBtoktemp$index_suffix (token, checksum, minichecksum) VALUES $tempsql");
		$toktemp_total_insert += (microtime(true)-$tok_insert_start);
		unset($temporary_token_ids);
	}
	
	if ( $awaiting_writes )
	{
		# update temporary statistics
		$connection->query("UPDATE PMBIndexes SET 
					temp_loads = temp_loads + $awaiting_writes,
					updated = UNIX_TIMESTAMP(),
					temp_loads_left = 0,
					$doc_counter_target_column = $doc_counter_target_column + $awaiting_writes
					WHERE ID = $index_id");
					
		$docinfo_time_start = microtime(true);
		$count = count($docinfo_value_sets);
		$docpdo = $connection->prepare("INSERT INTO $docinfo_target_table 
										(
										ID, 
										URL, 
										url_checksum, 
										avgsentiscore,
										field0, 
										field1, 
										attr_category, 
										attr_timestamp, 
										attr_domain,
										checksum
										) 
										VALUES " . implode(",", $docinfo_value_sets) . "
										");												
		$docpdo->execute($cescape);
		$last_id = $connection->lastInsertId();
		$docinfo_extra_time += (microtime(true)-$docinfo_time_start);
		
		# replace the :1 -> :20 entries with correct document ids
		$insert_find = array();
		$insert_repl = array();
		$j = 0;
		for ( $i = $last_id ; $i < $last_id+$count ; ++$i )
		{
			if ( isset($temporary_ids[$j]) )
			{
				$insert_find[] = $doc_prefix."$j";
				$insert_repl[] = $PackedIntegers->int32_to_bytes5($i);					
			}
			++$j;
		}
					
		$log .= "token data inserts start  \n";
		$loop_log .= "token data inserts start  \n";

		$insert_buffer = "";
		foreach ( $insert_buffer_array as $checksum_48bit => $doc_match_data ) 
		{
			$insert_buffer .= $PackedIntegers->int48_to_bytes7($checksum_48bit) . " $doc_match_data\n";
		}
		
		# replace the document id placeholders with actual document ids
		$insert_buffer = str_replace($insert_find, $insert_repl, $insert_buffer, $r_count);

		fwrite($f, $insert_buffer);
		unset($insert_buffer, $insert_buffer_array, $insert_buffer_delta);
		
		$log .= "token data inserts OK  \n";
		$loop_log .= "token data inserts OK  \n";
		
		$insert_buffer = "";
		$awaiting_writes = 0;
		
		$log .= "new word occurances ok \n";
		$loop_log .= "new word occurances ok \n";
		
		$docinfo_value_sets = array();
		$cescape 			= array();
		$insert_data_escape = array();
		
		$connection->commit();				# commit changes to disk and flush log
		$connection->beginTransaction();	# start new transaction
		
		try
		{
			# check indexing permission
			$perm = $connection->query("SELECT indexing_permission FROM PMBIndexes WHERE ID = $index_id");
			$permission = $perm->fetchColumn();
			
			if ( !$permission )
			{
				SetProcessState($index_id, $process_number, 0);
				echo "Indexing was requested to be terminated...\n";
				$connection->query("UPDATE PMBIndexes SET current_state = 0 WHERE ID = $index_id");
				break;
				
				# no indexing permission, abort now
				#die("Indexing was requested to be terminated");
			}
		}
		catch ( PDOException $e ) 
		{
			
		}
		
	}
	
	$connection->commit();
}
catch ( PDOException $e ) 
{
	echo "an error occurred during updating the remaining token rows \n" . $e->getMessage() . "\n";
}

fclose($f);
	
if ( $test_mode )
{
	echo $log;
	SetIndexingState(0, $index_id);
	return;
}

# no need for these anymore ! 
unset($temporary_token_ids);

echo "Memory usage : " . memory_get_usage()/1024/1024 . " MB\n";
echo "Memory usage (peak) : " . memory_get_peak_usage()/1024/1024 . " MB\n";

$interval = microtime(true) - $timer;
echo "Maintaining a list of unique tokens took $toktemp_total_insert seconds \n";
echo "Data tokenization is complete, $interval seconds elapsed, starting to compose prefixes...\n";

if ( $indexed_docs === 0 ) 
{
	SetIndexingState(0, $index_id);
	sleep(1);
	# no documents processed, no reason to continue!
	echo "No new data to index!\n";
	$timer_end = microtime(true) - $timer;
	echo "The whole operation took $timer_end seconds \n";
	echo "Memory usage : " . memory_get_usage()/1024/1024 . " MB\n";
	echo "Memory usage (peak) : " . memory_get_peak_usage()/1024/1024 . " MB\n";
	die("Quitting now. Bye!\n");
}

# start prefix creation
SetIndexingState(2, $index_id);

# create prefixes
if ( !empty($mysql_data_dir) )
{
	$sort_directory = $mysql_data_dir; # custom directory
	$tmp_sort_dir = "--temporary-directory=$mysql_data_dir";
}
else
{
	$sort_directory = realpath(dirname(__FILE__));
	$tmp_sort_dir = "";
}

for ( $i = 0 ; $i < $dist_threads ; ++$i ) 
{
	$filepath = $sort_directory . "/pretemp_".$index_id."_".$i.".txt";
	$filepath_sorted =  $sort_directory . "/pretemp_".$index_id."_sorted.txt";
	@unlink($filepath); # remove existing file ( just to be sure ) 
	@unlink($filepath_sorted); # remove existing file ( just to be sure ) 
}

require_once("prefix_composer_ext.php");

# update indexing state
SetIndexingState(4, $index_id);

$filepath_sorted =  $sort_directory . "/pretemp_".$index_id."_sorted.txt";
$all_filepaths = "";

# sort the external data files
$sort_start = microtime(true);
for ( $i = 0 ; $i < $dist_threads ; ++$i ) 
{
	#echo "Sorting temporary prefix data for process_number $i \n";
	$filepath = $sort_directory . "/pretemp_".$index_id."_".$i.".txt";
	
	if ( is_readable($filepath) && filesize($filepath) )
	{ 
		$all_filepaths .= $filepath . " ";
	}
}

if ( !empty($all_filepaths) )
{
	echo "Starting to sort prefix data \n";
	exec("LC_ALL=C sort $tmp_sort_dir -k1,1 $all_filepaths > $filepath_sorted");
}
else
{
	# create a dummy file for the prefix compressor ( so it won't get nervous ) 
	file_put_contents($filepath_sorted, "");
	echo "No prefix data to sort...\n";
}


for ( $i = 0 ; $i < $dist_threads ; ++$i ) 
{
	$filepath = $sort_directory . "/pretemp_".$index_id."_".$i.".txt";
	@unlink($filepath); # remove the unsorted file
}

$sort_end = microtime(true)-$sort_start;
echo "Sorting temporary prefix data took $sort_end seconds \n";

$interval = microtime(true) - $timer;
echo "\nNow all processes are completed\nDocinfo time: $docinfo_extra_time \n\n";

try
{
	# update indexing status accordingly ( + set the indexing permission ) 
	$connection->query("UPDATE PMBIndexes SET 
						current_state = 3, 
						indexing_permission = 1,  
						temp_loads_left = 0
						WHERE ID = $index_id");
		
	# precache table index
	$connection->query("LOAD INDEX INTO CACHE PMBtoktemp$index_suffix;");

	# update indexing status accordingly ( + set the indexing permission ) 
	$connection->query("UPDATE PMBIndexes SET current_state = 3, 
						indexing_permission = 1, 
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
SetIndexingState(5, $index_id);

$all_filepaths = "";
# open the process specific file
if ( !empty($mysql_data_dir) )
{
	$sort_directory = $mysql_data_dir; # custom directory
	$tmp_sort_dir = "--temporary-directory=$mysql_data_dir";
}
else
{
	$sort_directory = realpath(dirname(__FILE__));
	$tmp_sort_dir = "";
}

$filepath_sorted =  $sort_directory . "/datatemp_".$index_id."_sorted.txt";
@unlink($filepath_sorted); # remove previous datatemp file
$all_filepaths = $sort_directory . "/datatemp_".$index_id."_0.txt";

if ( is_readable($all_filepaths) && filesize($all_filepaths) )
{
	# sort the external data files
	$sort_start = microtime(true);
	exec("LC_ALL=C sort $tmp_sort_dir -k1,1 -k2,2 $all_filepaths > $filepath_sorted");
	$sort_end = microtime(true)-$sort_start;
	echo "Sorting temporary match data took $sort_end seconds \n";
}
else
{
	echo "No temporary token data to sort...\n";
}
	
@unlink($all_filepaths);

# reset temporary variables
try
{
	$connection->query("UPDATE PMBIndexes SET 
						current_state = 3, 
						temp_loads_left = 0,
						temp_loads = temp_loads + $data_rows
						WHERE ID = $index_id ");
}
catch ( PDOException $e ) 
{
	echo "An error occurred when updating statistics: " . $e->getMessage() . "\n";
}

# update indexing state
SetIndexingState(3, $index_id);

# run token compressor
if ( $clean_slate || !empty($delta_indexing)  )
{	
	require_once("token_compressor_ext.php");
}
else
{
	require_once("token_compressor_merger_ext.php");
}

# run prefix compressor
if ( $clean_slate || !empty($delta_indexing) )
{
	require_once("prefix_compressor_ext.php");
}
else
{
	require_once("prefix_compressor_merger_ext.php");
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
