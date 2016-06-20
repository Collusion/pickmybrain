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
							
foreach ( $blend_chars as $blend_char ) 
{
	$blend_chars_space[] = " $blend_char ";
	$blend_chars_space[] = " $blend_char";
	$blend_chars_space[] = "$blend_char ";
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
$webpages = 0;

# write buffer variables
$write_buffer_len = 10;
$awaiting_writes = 0;

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
	$ind_state = $connection->prepare("SELECT current_state, updated, documents FROM PMBIndexes WHERE ID = ?");
	$ind_state->execute(array($index_id));
	
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
		die("Unknown index id $index_id");
	}
	
	# update current indexing state to true ( 1 ) 
	SetIndexingState(1, $index_id);
	
	$upd_state = $connection->prepare("UPDATE PMBIndexes SET indexing_started = UNIX_TIMESTAMP() WHERE ID = ?");
	$upd_state->execute(array($index_id));
	
	$data_dir_sql = "";
	if ( !empty($mysql_data_dir) )
	{
		$data_dir_sql = "DATA DIRECTORY = '$mysql_data_dir' INDEX DIRECTORY = '$mysql_data_dir'";
	}
	
	$temporary_table_type = "ENGINE=MYISAM DEFAULT CHARSET=utf8 ROW_FORMAT=FIXED $data_dir_sql";

	$senti_sql_column = "";
	$senti_sql_index_column = "";
	$senti_insert_sql_column = "";
	if ( $sentiment_analysis )
	{
		$senti_sql_column = "sentiscore tinyint(3) NOT NULL,";
		$senti_sql_index_column = ",sentiscore";
		$senti_insert_sql_column = "sentiscore,";
	}

	$connection->exec("DROP TABLE IF EXISTS PMBdatatemp$index_suffix;
								CREATE TABLE IF NOT EXISTS PMBdatatemp$index_suffix (
								checksum int(10) unsigned NOT NULL,
								minichecksum smallint(7) unsigned NOT NULL,
								doc_id int(10) unsigned NOT NULL,
								count tinyint(3) unsigned NOT NULL,
							 	field_id tinyint(3) unsigned NOT NULL,
								".$senti_sql_column."
								checksum_2 int(10) unsigned,
								minichecksum_2 smallint(7) unsigned,
							 	KEY (checksum,minichecksum,doc_id,checksum_2,minichecksum_2,field_id,count ".$senti_sql_index_column.")
								) $temporary_table_type PACK_KEYS=1");
								
	# create new temporary tables				   
	$connection->exec("DROP TABLE IF EXISTS PMBpretemp$index_suffix;
						CREATE TABLE IF NOT EXISTS PMBpretemp$index_suffix (
						checksum int(10) unsigned NOT NULL,
						token_checksum int(10) unsigned NOT NULL,
						cutlen tinyint(3) unsigned NOT NULL,
						KEY (checksum, cutlen, token_checksum)
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
							token varbinary(40) NOT NULL,
							ID mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
							checksum int(10) unsigned NOT NULL,
							minichecksum smallint(5) unsigned NOT NULL,
							PRIMARY KEY (token),
							KEY ID (ID),
							KEY checksum (checksum,minichecksum,ID)
							) ENGINE=MYISAM DEFAULT CHARSET=utf8 $data_dir_sql");
	}

	# disable non-unique keys					 
	$connection->exec("ALTER TABLE PMBtoktemp$index_suffix DISABLE KEYS;
					   ALTER TABLE PMBdatatemp$index_suffix DISABLE KEYS;");		
						
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

$indexed_docs = 0;
$token_stat_time = 0;
$insert_data_sql = "";
$up = 0;

# start by disabling autocommit
# flush write log to disk at every write buffer commit
$connection->beginTransaction();

while ( !empty($url_list[$lp]) )
{	
	$catpointer = 0;
	$category_ids = array(NULL); 	# reset categories
	$url = $url_list[$lp];
	++$lp;  # advance the array pointer by one
	++$webpages;
	
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
		if ( !empty($xpdf_folder) && $enable_exec ) 
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
			try
			{	
				$linkpdo = $connection->prepare("SELECT ID, 
												URL, 
												checksum, 
												url_checksum
												FROM PMBDocinfo$index_suffix 
												WHERE url_checksum IN ( " . implode(", ", array_fill(0, count($temp_links), "?")) . " )");
				$linkpdo->execute(array_keys($temp_links));
				
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
		# this checksum is the checksum for content, not url
		$checksumpdo = $connection->prepare("SELECT LOWER(HEX(checksum)) as checksum FROM PMBDocinfo$index_suffix WHERE url_checksum = UNHEX(MD5(:url))");	
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

	/*
		CHARSET PROCESSING STARTS
	*/	
	foreach ( $fields as $f_id => $field ) 
	{
		# add spaces in the front and the back of each field for the single blend char removal to work
		$fields[$f_id] = " " . $fields[$f_id] . " ";
		
		# if sentiment analysis is enabled
		if ( $f_id !== 2 && $sentiment_analysis && !empty($fields[$f_id]) && !empty($sentiment_class_name) ) 
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

		# decode html entities
		$fields[$f_id] = html_entity_decode($fields[$f_id]);
		
		# convert to lowercase
		$fields[$f_id] = mb_strtolower($fields[$f_id]);
		
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
				
				$temporary_token_ids[$match] = true;
				
				$first_token = $match;
			}
		}
		
		if ( isset($first_token) && $first_token !== '' )
		{
			# lastly, create a pseudo-pair ( last token of current field ) 
			if ( isset($token_pairs[$first_token][NULL][0]) )
			{
				$token_pairs[$first_token][NULL][0] += 1; 					# one new hit
				$token_pairs[$first_token][NULL][1] |= (1 << $field_id);	# add field id bit by or operation
			}
			else
			{
				$token_pairs[$first_token][NULL] = array(1, 1 << $field_id);
			}
			
			$temporary_token_ids[$first_token] = true;
		}
	}

	
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

		$aw = $awaiting_writes;
		
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
			
			$crc32 = crc32($token);
			$b = md5($token);
			$tid = hexdec($b[0].$b[1].$b[2].$b[3]);
			
			# generate a list of token pairs to insert for this doc_id
			foreach ( $tokenlist as $second_token => $token_data ) 
			{
				if ( !isset($second_token) )
				{
					# no second token present
					$second_token_checksum = "NULL"; 
					$second_minichecksum   = "NULL"; 
				}
				else
				{
					$second_token_checksum = crc32($second_token);
					$b = md5($second_token);
					$second_minichecksum = hexdec($b[0].$b[1].$b[2].$b[3]);
				}
				
				if ( $sentiment_analysis ) 
				{
					$insert_data_sql	.= ",($crc32, $tid, :doc_id$aw, $real_token_count, $wordsentiscore, $second_token_checksum, $second_minichecksum," . $token_data[1] . ")";
				}
				else
				{
					$insert_data_sql	.= ",($crc32, $tid, :doc_id$aw, $real_token_count, $second_token_checksum, $second_minichecksum," . $token_data[1] . ")";
				}
			}
			
			$temporary_ids[$aw] = true;
		}
		
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
				$tokpdo = $connection->query("INSERT IGNORE INTO PMBtoktemp$index_suffix (token, checksum, minichecksum) VALUES $tempsql");
				unset($temporary_token_ids);
			}
		
			# update temporary statistics
			$connection->query("UPDATE PMBIndexes SET 
						temp_loads = temp_loads + $awaiting_writes,
						updated = UNIX_TIMESTAMP(),
						temp_loads_left = 0,
						documents = documents + $awaiting_writes
						WHERE ID = $index_id");
						
			$docinfo_time_start = microtime(true);
			$count = count($docinfo_value_sets);
			$docpdo = $connection->prepare("INSERT INTO PMBDocinfo$index_suffix 
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
			
			$j = 0;
			for ( $i = $last_id ; $i < $last_id+$count ; ++$i )
			{
				if ( isset($temporary_ids[$j]) )
				{
					$insert_data_escape[":doc_id$j"] = $i;
				}
				++$j;
			}
				
			$log .= "token data inserts start  \n";
			$loop_log .= "token data inserts start  \n";
			# checksum,token_id,doc_id,token_id_2,field_id,count,sentiscore
			$insert_data_sql[0] = " "; 
			$datapdo = $connection->prepare("INSERT INTO PMBdatatemp$index_suffix (checksum,
																				minichecksum, 
																				doc_id, 
																				count, 
																				".$senti_insert_sql_column." 
																				checksum_2,
																				minichecksum_2, 
																				field_id) VALUES $insert_data_sql");
			$datapdo->execute($insert_data_escape);
			
			$log .= "token data inserts OK  \n";
			$loop_log .= "token data inserts OK  \n";
			
			$insert_data_sql = "";
			$up = 0;
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
	if ( $awaiting_writes )
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
			$tokpdo = $connection->query("INSERT IGNORE INTO PMBtoktemp$index_suffix (token, checksum, minichecksum) VALUES $tempsql");
			unset($temporary_token_ids);
		}
		
		# update temporary statistics
		$connection->query("UPDATE PMBIndexes SET 
					temp_loads = temp_loads + $awaiting_writes,
					updated = UNIX_TIMESTAMP(),
					temp_loads_left = 0,
					documents = documents + $awaiting_writes
					WHERE ID = $index_id");
					
		$docinfo_time_start = microtime(true);
		$count = count($docinfo_value_sets);
		$docpdo = $connection->prepare("INSERT INTO PMBDocinfo$index_suffix 
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
		
		$j = 0;
		for ( $i = $last_id ; $i < $last_id+$count ; ++$i )
		{
			if ( isset($temporary_ids[$j]) )
			{
				$insert_data_escape[":doc_id$j"] = $i;
			}
			++$j;
		}
			
		$log .= "token data inserts start  \n";
		$loop_log .= "token data inserts start  \n";
		
		$insert_data_sql[0] = " "; 
		$datapdo = $connection->prepare("INSERT INTO PMBdatatemp$index_suffix (checksum,
																				minichecksum, 
																				doc_id, 
																				count, 
																				".$senti_insert_sql_column." 
																				checksum_2,
																				minichecksum_2, 
																				field_id) VALUES $insert_data_sql");
		$datapdo->execute($insert_data_escape);
		
		$log .= "token data inserts OK  \n";
		$loop_log .= "token data inserts OK  \n";
		
		$insert_data_sql = "";
		$up = 0;
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
	
if ( $test_mode )
{
	echo $log;
	SetIndexingState(0, $index_id);
	return;
}

# if no new data was indexed
if ( $indexed_docs === 0 ) 
{
	echo "No new data to index!\n";
	$timer_end = microtime(true) - $timer;
	echo "The whole operation took $timer_end seconds \n";
	echo "Memory usage : " . memory_get_usage()/1024/1024 . " MB\n";
	echo "Memory usage (peak) : " . memory_get_peak_usage()/1024/1024 . " MB\n";
	SetIndexingState(0, $index_id);
	return;
}

# create prefixes
require_once("prefix_composer.php");

/*
	Now it is the time to handle temporary data! 
*/

try
{
	# update indexing status accordingly ( + set the indexing permission ) 
	$connection->query("UPDATE PMBIndexes SET 
						current_state = 3, 
						indexing_permission = 1, 
						temp_loads = 0, 
						temp_loads_left = 0
						WHERE ID = $index_id");
	
	$key_start = microtime(true);
	echo "Enabling keys for temporary tables...\n";
	

	# before anything else, build indexes for temporary tables
	$connection->exec("ALTER TABLE PMBdatatemp$index_suffix ENABLE KEYS;
					   ALTER TABLE PMBdatatemp$index_suffix ENABLE KEYS;");
	
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

$tokens_end = microtime(true) - $tokens_start;

# rum prefix compressor
if ( $clean_slate ) 
{
	require_once("prefix_compressor.php");
}
else
{
	require_once("prefix_compressor_merger.php");
}


$timer_end = microtime(true) - $timer;
echo "The whole operation took $timer_end seconds \n";
echo "Memory usage : " . memory_get_usage()/1024/1024 . " MB\n";
echo "Memory usage (peak) : " . memory_get_peak_usage()/1024/1024 . " MB\n";

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

# update current indexing state to false ( 0 ) 
SetIndexingState(0, $index_id);


?>
