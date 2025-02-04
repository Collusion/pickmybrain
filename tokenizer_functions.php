<?php

/* Copyright (C) 2017 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@gmail.com
 * or visit: http://www.pickmybra.in
 */

class Metaphones
{
	private $meta_lookup_encode;
	private $meta_lookup_decode;
	private $mass_find;
	private $mass_replace;
	
	public function __construct()
	{
		$this->meta_lookup_decode = array (
									  0 => '',
									  1 => 'N',
									  2 => 'K',
									  3 => 'A',
									  4 => 'P',
									  5 => 'T',
									  6 => 'S',
									  7 => 'F',
									  8 => 'L',
									  9 => 'M',
									  10 => 'X',
									  11 => 'R',
									  12 => 'H',
									  13 => 'J',
									  14 => '0',
									);
									
		$this->meta_lookup_encode = array (
									  ' ' => 0,
									  'N' => 1,
									  'K' => 2,
									  'A' => 3,
									  'P' => 4,
									  'T' => 5,
									  'S' => 6,
									  'F' => 7,
									  'L' => 8,
									  'M' => 9,
									  'X' => 10,
									  'R' => 11,
									  'H' => 12,
									  'J' => 13,
									  '0' => 14,
									);
									
		# create dialect arrays
		$dialect_array = array( 'š'=>'s', 'ž'=>'z', 'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'æ'=>'a', 'ç'=>'c', 'č'=>'c', 'è'=>'e', 'é'=>'e', 
							'ê'=>'e', 'ë'=>'e', 'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ð'=>'d', 'ñ'=>'n', 'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o',
                            'ö'=>'o', 'ø'=>'o', 'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'μ' => 'u', 'ý'=>'y', 'þ'=>'b', 'ÿ'=>'y', '$' => 's', 'ü' => 'u' , 'ş' => 's',
							'ş' => 's', 'ğ' => 'g', 'ı' => 'i', 'ǐ' => 'i', 'ǐ' => 'i', 'ĭ' => 'i', 'ḯ' => 'i', 'ĩ' => 'i', 'ȋ' => 'i' );
							
		$this->mass_find 	= array_keys($dialect_array);
		$this->mass_replace = array_values($dialect_array);
		
			
	}
	
	public function token_to_int16($token)
	{
		# replace dialect chars
		$token = str_replace($this->mass_find, $this->mass_replace, $token);

		if ( ctype_alpha($token) ) 
		{
			$metaphones = double_metaphone($token);
			$metaphone = $metaphones["primary"];
			
			if ( isset($metaphone) )
			{
				# normalize metaphone to 4 characters
				$metaphone = str_pad($metaphone, 4, " ", STR_PAD_LEFT);
				$integer = 0;
				$shift = 0;
				
				for ( $i = 0 ; $i < 4 ; ++$i ) 
				{
					$integer |= ($this->meta_lookup_encode[$metaphone[$i]] << ($shift*4));
					++$shift;
				}
				
				return $integer;
			}
		}
		
		return 0;
	}

	
	public function metaphone_to_int16($metaphone)
	{
		# normalize metaphone to 4 characters
		$metaphone = str_pad($metaphone, 4, " ", STR_PAD_LEFT);
		$integer = 0;
		$shift = 0;
		
		for ( $i = 0 ; $i < 4 ; ++$i ) 
		{
			$integer |= ($this->meta_lookup_encode[$metaphone[$i]] << ($shift*4));
			++$shift;
		}
		
		return $integer;
	}

	public function int16_to_metaphone($integer)
	{
		$metaphone = "";
		while ( $integer ) 
		{
			$metaphone .= $this->meta_lookup_decode[$integer&15];
			$integer >>= 4;
		}
		
		if ( $metaphone === "" )
		{
			return NULL;
		}
		
		return $metaphone;
	}
}
 
class PackedIntegers
{
	private $bytes_to_int_lookup;
	private $int_to_bytes_lookup;
	private $bytes_to_vbencoded_lookup;
	private $bytes_to_vbencoded_end_lookup;
	private $vb_binary_zero;
	
	public function __construct()
	{
		$this->vb_binary_zero = pack("H*", "80");
		
		# format look-up arrays
		for ( $i = 33 ; $i < 161 ; ++$i )
		{
			$this->bytes_to_int_lookup[chr($i)] = $i-33;
			$this->int_to_bytes_lookup[$i-33] = chr($i);
			$this->bytes_to_vbencoded_lookup[chr($i)] = pack("H*", sprintf("%02x", $i-33));
			$this->bytes_to_vbencoded_end_lookup[chr($i)] = pack("H*", sprintf("%02x", ($i-33)|128));
		}
	}
	
	public function bytes_to_int($string)
	{
		$p = 0;
		$value = 0;
		do
		{
			$value <<= 7;
			$value |= $this->bytes_to_int_lookup[$string[$p]];
			++$p;
			
		} while ( isset($string[$p]) );
		
		return $value;
	}
	
	public function int_to_bytes($int)
	{
		$string = "";
		do 
		{
			$string = $this->int_to_bytes_lookup[127&$int].$string;
			$int >>= 7;
		}
		while ( $int );
		
		return $string;
	}
	
	public function int32_to_bytes5($int)
	{
		$string = "!!!!!";
		$p = 4;
		do 
		{
			$string[$p] = $this->int_to_bytes_lookup[127&$int];
			$int >>= 7;
			--$p;
		}
		while ( $int && $p >= 0 );
		
		return $string;
	}
	
	public function int38_to_bytes6($int)
	{
		$string = "!!!!!!";
		$p = 5;
		do 
		{
			$string[$p] = $this->int_to_bytes_lookup[127&$int];
			$int >>= 7;
			--$p;
		}
		while ( $int && $p >= 0 );
		
		return $string;
	}
	
	public function int48_to_bytes7($int)
	{
		$string = "!!!!!!!";
		$p = 6;
		do 
		{
			$string[$p] = $this->int_to_bytes_lookup[127&$int];
			$int >>= 7;
			--$p;
		}
		while ( $int && $p >= 0 );
		
		return $string;
	}
	
	public function bytes_to_vbencoded($string)
	{
		$p = 0;
		$value = 0;
		$compressed = $this->vb_binary_zero;
		
		# find the first non-zero 
		while ( $string[$p] === "!" )
		{
			++$p;
		}
		
		while ( isset($string[$p]) )
		{
			if ( !$value )
			{
				# first value needs to be 
				$compressed = $this->bytes_to_vbencoded_end_lookup[$string[$p]];
				++$value;
			}
			else
			{
				$compressed = $this->bytes_to_vbencoded_lookup[$string[$p]].$compressed;
				
			}

			++$p;
		}

		return $compressed;
	}
} 

/* check if all required 32bit binaries are installed by trying to find a predifined keyword in them
return values:
	- array of file names that were not detected to be 32bit compatible 
	- empty array if all checked files seemed to be 32bit compatible
	*/
function check_32bit_binaries()
{
	$files	= array();
	$result = array();
	
	$files[] = "db_tokenizer.php";
	$files[] = "decode_asc.php";
	$files[] = "decode_desc.php";
	$files[] = "PMBApi.php";
	$files[] = "prefix_composer.php";
	$files[] = "prefix_compressor.php";
	$files[] = "prefix_compressor_merger.php";
	$files[] = "token_compressor.php";
	$files[] = "token_compressor_merger.php";
	$files[] = "tokenizer_functions.php";
	$files[] = "web_tokenizer.php";
	
	$folder_path = realpath(dirname(__FILE__));
	
	# to prevent self-match
	$needle = "32_BIT";
	$needle .= "_VERSION";
	
	foreach ( $files as $filename ) 
	{
		$filepath = $folder_path . "/" . $filename;
		
		// read 500 bytes of the file and check if the read data contains the identifier created for the 32 bit binaries
		if ( strpos(file_get_contents($filepath, FALSE, NULL, 0, 500), $needle) === false )
		{
			$result[] = $filename;
		}
	}

	return $result;
}

/* placeholder function */
function test_custom_functions()
{
	return false;
}

function exec_available()
{
	if ( !function_exists('exec') || exec('echo EXEC') != 'EXEC' )
	{
		return false;
	}
	return true;
} 
 
function isSortSupported()
{
	if ( PHP_INT_SIZE === 4 ) 
	{
		# n/a on 32bit PHP environments
		return false;
	}
	if ( !function_exists('exec') || exec('echo EXEC') != 'EXEC' )
	{
		# n/a if exec() is unavailable
		return false;
	}
	
	$output = array();
	exec('sort --version', $output);
	$string = implode(" ", $output);
	
	if ( stripos($string, "written by") !== false || stripos($string, "GNU coreutils") !== false ) 
	{
		// sort command seems to be working OK
		return true;
	}
	
	# sort is not supported
	return false;
}

function requiredBits($number_of_fields)
{
	--$number_of_fields;
	if ( $number_of_fields <= 0 ) 
	{
		return 1;
	}
	
	return (int)(log($number_of_fields,2)+1);
}

function str_to_array($string)
{
	$len = mb_strlen($string);
	$arr = array();
	for ( $i = 0 ; $i < $len ; ++$i ) 
	{
		$arr[] = mb_substr($string, $i, 1);
	}
	
	return $arr;
}

function execInBackground($cmd) 
{
    if ( substr(php_uname(), 0, 7) == "Windows" )
	{
        pclose(popen("start /B ". $cmd, "r")); 
    }
    else
	{
        exec($cmd . " > /dev/null &");  
    }
}

function checkPMBIndexes($db_error = "")
{
	$table_sql = "CREATE TABLE PMBIndexes (
	 ID mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
	 name varbinary(20) NOT NULL DEFAULT '',
	 type tinyint(3) unsigned NOT NULL DEFAULT '1',
	 comment varbinary(255) NOT NULL DEFAULT '',
	 documents int(10) unsigned NOT NULL DEFAULT '0',
	 min_id int(10) unsigned NOT NULL DEFAULT '0',
	 max_id int(10) unsigned NOT NULL DEFAULT '0',
	 max_delta_id int(10) unsigned NOT NULL DEFAULT '0',
  	 delta_documents int(10) unsigned NOT NULL DEFAULT '0',
	 latest_rotation int(10) unsigned NOT NULL DEFAULT '0',
	 updated int(10) unsigned NOT NULL DEFAULT '0',
	 indexing_permission tinyint(3) unsigned NOT NULL DEFAULT '0',
	 pwd_token binary(12) DEFAULT NULL,
	 indexing_started int(10) unsigned NOT NULL DEFAULT '0',
	 current_state tinyint(3) unsigned NOT NULL DEFAULT '0',
	 temp_loads int(8) unsigned NOT NULL DEFAULT '0',
	 temp_loads_left int(8) unsigned NOT NULL DEFAULT '0',
	 disabled_documents mediumblob NOT NULL,
	 PRIMARY KEY (ID),
	 UNIQUE KEY name (name)
	) ENGINE=MYISAM DEFAULT CHARSET=utf8";
	
	$required_columns = array(
	"ID" 				=> "ID mediumint(8) unsigned NOT NULL AUTO_INCREMENT",
	"name" 				=> "name varbinary(20) NOT NULL DEFAULT ''",
	"type" 				=> "type tinyint(3) unsigned NOT NULL DEFAULT '1'",
	"comment" 			=> "comment varbinary(255) NOT NULL DEFAULT ''",
	"documents" 		=> "documents int(10) unsigned NOT NULL DEFAULT '0'",
	"min_id" 			=> "min_id int(10) unsigned NOT NULL DEFAULT '0'",
	"max_id" 			=> "max_id int(10) unsigned NOT NULL DEFAULT '0'",
	"max_delta_id" 		=> "max_delta_id int(10) unsigned NOT NULL DEFAULT '0'",
	"delta_documents" 	=> "delta_documents int(10) unsigned NOT NULL DEFAULT '0'",
	"latest_rotation" 	=> "latest_rotation int(10) unsigned NOT NULL DEFAULT '0'",
	"updated"			 => "updated int(10) unsigned NOT NULL DEFAULT '0'",
	"indexing_permission" => "indexing_permission tinyint(3) unsigned NOT NULL DEFAULT '0'",
	"pwd_token" 		=> "pwd_token binary(12) DEFAULT NULL",
	"indexing_started" 	=> "indexing_started int(10) unsigned NOT NULL DEFAULT '0'",
	"current_state" 	=> "current_state tinyint(3) unsigned NOT NULL DEFAULT '0'",
	"temp_loads" 		=> "temp_loads int(8) unsigned NOT NULL DEFAULT '0'",
	"temp_loads_left" 	=> "temp_loads_left int(8) unsigned NOT NULL DEFAULT '0'",
	"disabled_documents" => "disabled_documents mediumblob NOT NULL"
	);
	
	try
	{
		$connection = db_connection();
		
		# table doesn't exist, create it ! 
		if ( !$connection->query("SHOW TABLES LIKE 'PMBIndexes'")->rowCount() )
		{
			$connection->query($table_sql);
			
			# no need to alter the table after this
			return true;
		}
		
		# if table already existed, check that it has all the required columns
		$pdo = $connection->query("SELECT * FROM PMBIndexes LIMIT 1");
		
		if ( $row = $pdo->fetch(PDO::FETCH_ASSOC) )
		{
		}
		else
		{
			# table is empty, insert a dummy row now!
			$connection->query("INSERT INTO PMBIndexes () VALUES ()");
			$pdo = $connection->query("SELECT * FROM PMBIndexes LIMIT 1");
			$row = $pdo->fetch(PDO::FETCH_ASSOC);
			$connection->query("TRUNCATE TABLE PMBIndexes");
		}
		
		# now check each column 
		foreach ( $row as $column_name => $value ) 
		{
			if ( !isset($required_columns[$column_name]) )
			{
				# this column does not need to exist
				$alter_operations[] = "DROP $column_name";
			}
			else
			{
				$found_columns[$column_name] = 1;
			}
		}
		
		foreach ( $required_columns as $column_name => $column_sql )
		{
			if ( !isset($found_columns[$column_name]) )
			{
				# this column is missing from the table definition, so it has to be added
				$alter_operations[] = "ADD $column_sql";
			}
		}
		
		# finally, execute alter operations ( if any exist )
		if ( !empty($alter_operations) )
		{
			$connection->query("ALTER TABLE PMBIndexes " . implode(",", $alter_operations));
		}
	}
	catch ( PDOException $e )
	{
		$db_error = $e->getMessage();
		return false;
	}
	
	return true;
}

function deleteIndex($index_id)
{
	if ( empty($index_id) || !is_numeric($index_id) )
	{
		return false;
	}
	
	$folderpath = realpath(dirname(__FILE__));
	$index_suffix = "_".$index_id;
	
	try
	{
		$connection = db_connection();

		# check if settings file exists for this index
		$folder_path = realpath(dirname(__FILE__));
		$filepath = $folder_path . "/settings_".$index_id.".txt";
		if ( is_readable($filepath) )
		{
			include "autoload_settings.php";
		}
						
		if ( !empty($mysql_data_dir) )
		{
			$t_filedir = $mysql_data_dir;
		}
		else
		{
			$t_filedir = $folderpath;
		}
		
		@unlink($t_filedir . "/datatemp_".$index_id."_sorted.txt");
		@unlink($t_filedir . "/pretemp_".$index_id."_sorted.txt");

		$sql = "SET FOREIGN_KEY_CHECKS=0;";			
		for ( $i = 1 ; $i < 16 ; ++$i ) 
		{
			$sql .= "DROP TABLE IF EXISTS PMBtemporary_" . $index_id . "_" . $i . ";";
			@unlink($t_filedir . "/datatemp_".$index_id."_".$i.".txt");
			@unlink($t_filedir . "/pretemp_".$index_id."_".$i.".txt");
			@unlink($folderpath . "/pmb_".$index_id."_".$i.".pid");
		}
		$sql .= "DROP TABLE IF EXISTS PMBDocinfo$index_suffix;
				DROP TABLE IF EXISTS PMBPrefixes$index_suffix;
				DROP TABLE IF EXISTS PMBTokens$index_suffix;
				DROP TABLE IF EXISTS PMBCategories$index_suffix;
				DROP TABLE IF EXISTS PMBQueryLog$index_suffix;
				DROP TABLE IF EXISTS PMBtoktemp$index_suffix;
				DROP TABLE IF EXISTS PMBdatatemp$index_suffix;
				DROP TABLE IF EXISTS PMBpretemp$index_suffix;
				SET FOREIGN_KEY_CHECKS=1;";
		
		$connection->exec($sql);
		
		# delete the entry from the indexes table			
		$delpdo = $connection->prepare("DELETE FROM PMBIndexes WHERE ID = ?");
		$delpdo->execute(array($index_id));
		
		# delete the settings file
		@unlink($folderpath."/settings$index_suffix.txt");
		@unlink($folderpath . "/ext_db_connection$index_suffix.php");
	}
	catch ( PDOException $e ) 
	{
		echo "An error occurred when deleting the index: " . $e->getMessage() . "\n";
		return false;
	}
	
	return true;
}

function execWithCurl($url, $async = true)
{
	$url = str_replace("localhost", $_SERVER['SERVER_NAME'], $url);

	$timeout = 1;
	if ( empty($async) )
	{
		$async 		= false;
		$timeout 	= 10;
	}
	
	# maintain session through curl request
	if ( !empty($_SESSION["pmb_logged_in"]) )
	{
		$useragent = $_SERVER['HTTP_USER_AGENT'];
		$strCookie = 'PHPSESSID=' . $_COOKIE['PHPSESSID'] . '; path=/';
		session_write_close();
	}
	else
	{
		$useragent = "Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/51.0.2704.103 Safari/537.36";
	}
	
	$options = array(
        CURLOPT_RETURNTRANSFER 	=> false,     // do not return web page
        CURLOPT_HEADER         	=> false,    // don't return headers
        CURLOPT_FOLLOWLOCATION 	=> true,     // follow redirects
        CURLOPT_ENCODING       	=> "",       // handle all encodings
        CURLOPT_USERAGENT      	=> $useragent,    // who am i
        CURLOPT_AUTOREFERER    	=> true,     // set referer on redirect
        CURLOPT_CONNECTTIMEOUT 	=> 10,      // timeout on connect
        CURLOPT_TIMEOUT      	=> $timeout,      // timeout on response
		CURLOPT_FRESH_CONNECT 	=> $async
    );
	
	# maintain session through curl request
	if ( !empty($_SESSION["pmb_logged_in"]) )
	{
		$options += array(CURLOPT_COOKIE => $strCookie);
	}

    $ch      = curl_init($url);
    curl_setopt_array( $ch, $options );
    $content = curl_exec( $ch );
    curl_close( $ch );
	
	if ( empty($async) )
	{
		echo $content;
	}
	
	return $content;
}

function expand_synonyms($synonyms) 
{
	$expanded_synonyms = array();
	
	if ( empty($synonyms) || !is_array($synonyms) )
	{
		return $expanded_synonyms;
	}
	
	foreach ( $synonyms as $synonym_list )
	{
		$loop_assoc = array();
		$parts = explode(",", $synonym_list);
		$parts_trimmed = array();
		
		foreach ( $parts as $part )
		{
			$part = trim($part);
			if ( $part !== "" )
			{
				$parts_trimmed[] = $part; 
				$loop_assoc[$part] = array();
			}
		}
		
		foreach ( $loop_assoc as $synonym => $e_array ) 
		{
			foreach ( $parts_trimmed as $synonym_word ) 
			{
				if ( $synonym !== $synonym_word )
				{
					$loop_assoc[$synonym][] = $synonym_word;
				}
			}
		}
		
		# merge into expanded synonyms
		$expanded_synonyms += $loop_assoc;
	}
	
	return $expanded_synonyms;
}

function StringToKeywords($string)
{
	$string = trim($string);
	
	if ( empty($string) ) return false;
	
	$ukeywords = array_unique(explode(" ", $string));
	$wanted_keywords = array();
	$non_wanted_keywords = array();
	
	foreach ( $ukeywords as $ukeyword ) 
	{
		$ukeyword = trim($ukeyword);
		if ( $ukeyword[0] === "-" )
		{
			# non-wanted keyword
			$non_wanted_keywords[] = $ukeyword;
		}
		else
		{
			# wanted keyword
			$wanted_keywords[] = $ukeyword;
		}
	}
	
	return array($wanted_keywords, $non_wanted_keywords);
}

function keyword_check($url, array $wanted_keywords, array $non_wanted_keywords)
{
	if ( !empty($wanted_keywords) )
	{
		$wanted_hits = 0;
		str_replace($wanted_keywords, "", $url, $wanted_hits);
		
		if ( $wanted_hits < count($wanted_keywords) )
		{
			return false;
		}
	}
	
	if ( !empty($non_wanted_keywords) )
	{
		$non_wanted_hits = 0;
		str_replace($non_wanted_keywords, "", $url, $non_wanted_hits);
		
		if ( $non_wanted_hits > 0 )
		{
			return false;
		}
	}
	
	return true;
}

function test_database_settings($index_id, &$log = "", &$number_of_fields = 0, &$indexable_columns = array())
{
	$e_count = 0;
	
	include "autoload_settings.php";
	require_once("db_connection.php");
	
	$connection = db_connection();
	
	if ( $use_internal_db === 1 ) 
	{
		$ext_connection = $connection;
		
		if ( is_string($ext_connection) )
		{
			$log .= "Error: the internal database configuration seems to be invalid.\n";
			$log .= "Following error message was received: $ext_connection \n";
			++$e_count;
		}
	}
	else
	{
		# the external PDO connection is defined in this file
		require_once "ext_db_connection_".$index_id.".php"; 
		
		# create a new instance of the connection
		$ext_connection = call_user_func("ext_db_connection");
		
		if ( is_string($ext_connection) )
		{
			$log .= "Error: establishing the external database connection failed. Following error message was received: $ext_connection\n";
			++$e_count;
		}
	}
	
	if ( empty($main_sql_query) )
	{
		$log .= "Error: the main SQL query is not defined. To start indexing, please define the SQL query.";
		++$e_count;
	}
	else
	{
		
		# check that columns have been defined one-by-one, not by SELECT * FROM ...
		$main_sql_query = trim(str_replace(array("\n\t", "\t\n", "\r\n", "\n", "\t", "\r"), " ", $main_sql_query));
		$parts = explode(" ", $main_sql_query);
		$temp = array();
		foreach ( $parts as $part ) 
		{
			if ( $part != "" )
			{
				$temp[] = $part;
			}
		}
		$main_sql_query = implode(" ", $temp);
		
		if ( stripos($main_sql_query, "SELECT * FROM") !== false )
		{
			$log .= "Error: data columns must be defined separately, SELECT * FROM ... does not work.\n";
			++$e_count;
		}
		
		$lastpos = mb_strripos(str_replace("\n", " ", $main_sql_query), " limit ");
		
		if ( $lastpos !== false ) 
		{
			$main_sql_query = mb_substr($main_sql_query, 0, $lastpos) . " LIMIT 5";
		}
		else
		{
			$main_sql_query .= " LIMIT 5";
		}
		
		# compare query againts defined attributes
		if ( !empty($main_sql_attrs) && !empty($main_sql_attrs[0]) )
		{	
			# create reversed attribute list
			$reverse_attributes = array_count_values($main_sql_attrs);
		
			$main_sql_copy = $main_sql_query;
			$lastpos = mb_stripos(str_replace("\n", " ", $main_sql_copy), " from ");
			
			if ( $lastpos !== false ) 
			{
				$main_sql_copy = mb_substr($main_sql_copy, 0, $lastpos);
			}

			# each attribute must be found from the main SQL query
			foreach ( $main_sql_attrs as $attr ) 
			{
				# trim possible data length values
				$attr_parts = explode(":", $attr);
				
				if ( strpos($main_sql_copy, $attr_parts[0]) === false )
				{
					$log .= "Error: $attr was defined as an attribute, but no such column exists in the SQL query.\n";
					++$e_count;
				}
			}
		}
		
		if ( $e_count === 0 ) 
		{
			$sentiment_fields = 0;
			
			# proceed
			try
			{
				# test the main SQL query
				$testpdo = $ext_connection->query($main_sql_query);
				$data_array = array();
				$data_count = 0;
				$previous_doc_id = -1;
				
				while ( $row = $testpdo->fetch(PDO::FETCH_ASSOC) )
				{
					$c = 0;
					$fields = 0;
					$main_id_value = "";
					$columns = array();
					# check for data types
					foreach ( $row as $column_name => $column_value )
					{
						if ( $c === 0 ) 
						{
							$main_id_value = $column_value;
							
							# document id, do not count as field
							if ( !is_numeric($column_value) || $column_value < 0 )
							{
								# incorrect primary key
								$log .= "Error: the first defined column does not seem to have a correct unsigned integer value.\n";
								++$e_count;
							}
							else if ( $main_id_value <= $previous_doc_id )
							{
								$log .= "Error: the unique identifiers must be in ascending order.\n";
								++$e_count;
							}
							
							$previous_doc_id = $main_id_value;
						}
						# this column is defined as an attribute
						# all attributes must have numeric values
						else if ( isset($reverse_attributes[$column_name]) && (!ctype_digit($column_value) || $column_value < 0) )
						{
							$log .= "Error: attribute column $column_name returns $column_value (unsigned integer expected)\n";
							++$e_count;
						}
						else if ( !isset($reverse_attributes[$column_name]) && $column_name != "pmb_language" )
						{
							# this should be an indexable column
							++$fields;
							$columns[$column_name] = 1;
						}
						
						if ( $column_name == "pmb_language" )
						{
							++$sentiment_fields;
							
							if ( !is_numeric($column_value) )
							{
								# incorrect primary key
								$log .= "Error: column pmb_language returns $column_value (unsigned integer expected)\n";
								++$e_count;
							}
						}
						
						++$c;
					}
					
					$data_array[(string)$main_id_value] = 1;
					++$data_count;
					
					if ( $e_count > 0 )
					{
						break;
					}
				}

				if ( $data_count === 0 ) 
				{
					$log .= "Warning: the provided SQL query is syntactically correct, but it does not return any rows. 
					Therefore Pickmybrain is unable to make necessary adjustments.\n";
					++$e_count;
				}
				else if ( $data_count !== count($data_array) )
				{
					$log .= "Error: the document ID column returns non-unique values.\n";
					++$e_count;
				}
				else if ( $fields === 0 ) 
				{
					$log .= "Error: no indexable columns defined in the SQL query.\n";
					++$e_count;
				}
				else if ( $sentiment_fields == 0 && $sentiment_analysis == 1001 )
				{
					$log .= "Error: attribute-based sentiment analysis is enabled, but required column pmb_language is not defined in the SQL query.\n";
					++$e_count;
				}
			}
			catch ( PDOException $e ) 
			{
				$log .= "An error occured when executing the provided SQL query:\n";
				$log .= $e->getMessage() . "\n";
				++$e_count;
			}
		}

		if ( $e_count === 0 )
		{
			$log .= "SQL self-test was completed successfully.\n";
		}
		else if ( $e_count > 0 )
		{
			$log .= "Please resolve all errors above to start indexing your data.\n";
		}
	}
	
	if ( $e_count === 0 ) 
	{
		$number_of_fields = $fields;
		$indexable_columns = $columns;
		return 1;
	}
	
	return 0;
}

function write_settings(array $settings, $index_id = 0)
{	
	$index_suffix = "";
	if ( !empty($index_id) )
	{
		$index_suffix = "_" . $index_id;
	}

	# check if the current settings-file pre-exists
	if ( is_readable(realpath(dirname(__FILE__)) . "/settings$index_suffix.txt") )
	{
		# use these values as base-values
		include "autoload_settings.php";
	}
	else
	{
		# use old values as base-values
		include "settings.php"; 
	}
	
	# create copies of SQL settings
	if ( $index_type == 2 ) 
	{
		$previous_main_sql_query = $main_sql_query;
		$previous_main_sql_attrs = $main_sql_attrs;
	}
		
	# reset arrays ( if updating all settings ) 
	if ( !empty($settings["action"]) && $settings["action"] === "updatesettings" ) 
	{
		$trim_page_title 	= array();
		$seed_urls			= array();
		$main_sql_attrs 	= array();
		$html_index_attrs 	= array();
	}
	
	# overwrite old values with provided values
	foreach ( $settings as $setting_name => $setting_value ) 
	{
		$settings[$setting_name] 	= trim($settings[$setting_name]);
		$setting_value 				= trim($setting_value);
		
		if ( strpos($setting_name, "field_weight_") !== false ) 
		{
			# get the column name
			$field_weight_attribute = substr($setting_name, 13);
			# remove the last part of the name
			$setting_name = substr($setting_name, 0, 13);
		}
		
		switch ( $setting_name ) 
		{
			case 'index_type':
			if ( isset($setting_value) && is_numeric($setting_value) && $setting_value >= 1 && $setting_value <= 2 )
			{
				$$setting_name = $setting_value;
			}
			break;
			
			case 'main_sql_attrs':
			if ( isset($setting_value) )
			{
				$main_sql_attrs = array();
				if ( !empty($setting_value) )
				{
					$expl = explode("\n", $setting_value);
					
					foreach ( $expl as $attr ) 
					{
						$attr = trim($attr);
						if ( !empty($attr) )
						{
							$main_sql_attrs[] = $attr;
						}
					}
				}				
			}
			break;
			
			case 'synonyms':
			if ( isset($setting_value) )
			{
				$synonyms = array();
				if ( !empty($setting_value) )
				{
					$expl = explode("\n", $setting_value);
					
					foreach ( $expl as $attr ) 
					{
						$attr = trim($attr);
						if ( !empty($attr) )
						{
							$synonyms[] = $attr;
						}
					}
				}				
			}
			break;
			
			case 'scan_depth':
			case 'ranged_query_value':
			if ( isset($setting_value) && is_numeric($setting_value) && $setting_value >= 0 && $setting_value <= 10000000 )
			{
				$$setting_name = $setting_value;
			}
			break;
			
			case 'number_of_fields':
			if ( $index_type == 2 && isset($setting_value) && is_numeric($setting_value) && $setting_value >= 1 && $setting_value <= 8 )
			{
				$$setting_name = $setting_value;
			}
			break;
			
			case 'dist_threads':
			if ( isset($setting_value) && is_numeric($setting_value) && $setting_value >= 1 && $setting_value <= 16 )
			{
				$$setting_name = $setting_value;
			}
			break;
			
			case 'innodb_row_format':
			if ( isset($setting_value) && is_numeric($setting_value) && $setting_value >= 0 && $setting_value <= 5 )
			{
				$$setting_name = $setting_value;
			}
			break;
			
			
			case 'main_sql_query':
			case 'url_keywords':
			case 'mysql_data_dir':
			if ( isset($setting_value) )
			{
				$$setting_name = $setting_value;
			}
			break;
			
			case 'custom_address':
			if ( !empty($setting_value) )
			{
				$temp = trim(str_ireplace(array("https://", "http://", "/", "?", "#"), " ", $setting_value));
				$temps = explode(" ", $temp);
				
				if ( $temps[0] !== "localhost" ) 
				{
					$$setting_name = $temps[0];
				}
			}
			break;
			
			case 'data_columns':
			if ( !empty($setting_value) )
			{
				# we are updating data columns ( a special occasian )
				$old_data_columns = array();
				if ( !empty($data_columns) )
				{
					$old_data_columns = $data_columns;
				}
				
				$data_columns = array(); 	# clear old data
				$column_names = explode(",", $setting_value);
				
				foreach ( $column_names as $i => $column_name ) 
				{
					$column_name = trim($column_name);
					if ( !empty($column_name) )
					{
						$data_columns[] = $column_name;
						
						if ( !isset($field_weights[$column_name]) )
						{
							# new column, so use default score
							$field_weights[$column_name] = 1;
						}
					}
				}

				foreach ( $old_data_columns as $i => $old_column_name ) 
				{
					if ( !in_array($old_column_name, $data_columns, true) )
					{
						unset($field_weights[$old_column_name]);
					}
				}
				
				if ( !empty($field_weights) )
				{
					foreach ( $field_weights as $field_name => $field_weight ) 
					{
						if ( !in_array($field_name, $data_columns, true) )
						{
							unset($field_weights[$field_name]);
						}
					}
				}
			}
			break;
			
			case 'field_weight_':
			if ( isset($setting_value) ) 
			{		
				if ( is_numeric($setting_value) && $setting_value >= 0 ) 
				{
					$field_weights[$field_weight_attribute] = (int)$setting_value;
				}
				else
				{
					# if empty value given, set the score one
					$field_weights[$field_weight_attribute] = 1;
				}
			}
			break;
			
			case 'indexing_interval';
			case 'update_interval';
			case 'delta_merge_interval';
			if ( isset($setting_value) && is_numeric($setting_value) && $setting_value >= 0 ) 
			{
				$$setting_name = $setting_value;
			}
			break;
			
			case 'prefix_length':
			if ( isset($setting_value[0]) && is_numeric($setting_value) && $setting_value >= 1 && $setting_value <= 39 )
			{
				$prefix_length = $setting_value;
			}
			break;
			
			case 'sentiment_analysis':
			if ( isset($setting_value[0]) && is_numeric($setting_value) && (($setting_value >= 0 && $setting_value <= 2) || $setting_value == 1001 ) )
			{
				$$setting_name = $setting_value;
			}
			break;
			
			case 'prefix_mode':
			if ( isset($setting_value[0]) && is_numeric($setting_value) && $setting_value >= 0 && $setting_value <= 3 )
			{
				$prefix_mode = $setting_value;
			}
			break;
			
			case 'admin_email':
			if ( isset($setting_value[0]) && filter_var($setting_value, FILTER_VALIDATE_EMAIL) )
			{
				$$setting_name = $setting_value;
			}
			break;
			
			case 'seed_urls':
			if ( isset($setting_value) )
			{
				$seed_urls = array();
				if ( !empty($setting_value) )
				{
					$expl = explode("\n", $setting_value);
					
					foreach ( $expl as $attr ) 
					{
						$attr = trim($attr);
						if ( !empty($attr) )
						{
							$seed_urls[] = $attr;
						}
					}
				}				
			}
			break;

			case 'trim_page_title':
			if ( isset($setting_value) )
			{
				$trim_page_title = array();
				if ( !empty($setting_value) )
				{
					$expl = explode("\n", $setting_value);
					
					foreach ( $expl as $attr ) 
					{
						$attr = trim($attr);
						if ( !empty($attr) )
						{
							$trim_page_title[] = $attr;
						}
					}
				}				
			}
			break;
			
			case 'charset':
			if ( isset($setting_value[0]) )
			{
				$charset = mb_strtolower($setting_value);
			}
			break;
			
			case 'blend_chars':
			case 'ignore_chars':
			if ( isset($setting_value[0]) )
			{
				$len = mb_strlen($setting_value);
				$arr = array();
				for ( $i = 0 ; $i < $len ; ++$i ) 
				{
					$arr[] = $setting_value[$i];
				}
				
				$$setting_name = $arr;
			}
			break;
			
			case 'html_index_attrs':
			if ( isset($setting_value) )
			{
				$html_index_attrs = array();
				if ( !empty($setting_value) )
				{
					$expl = explode("\n", $setting_value);
					
					foreach ( $expl as $attr ) 
					{
						$attr = trim($attr);
						if ( !empty($attr) )
						{
							$html_index_attrs[] = $attr;
						}
					}
				}				
			}
			break;
			
			case 'html_remove_elements':
			if ( isset($setting_value) )
			{
				$$setting_name = $setting_value;
			}
			break;
			
			case 'use_buffered_queries':
			case 'html_strip_tags':
			case 'use_internal_db':
			case 'log_queries':
			case 'use_localhost':
			case 'dialect_processing':
			case 'dialect_matching':
			case 'separate_alnum':
			case 'quality_scoring':
			case 'keyword_stemming':
			case 'forgive_keywords':
			case 'honor_nofollows':
			case 'enable_exec':
			case 'sentiweight':
			case 'allow_subdomains':
			case 'index_pdfs':
			case 'delta_indexing':
			case 'include_original_data':
			case 'keyword_suggestions':
			if ( isset($setting_value) && $setting_value <= 1 && $setting_value >= 0 )
			{
				$$setting_name = $setting_value;
			}
			break;
					
			case 'min_prefix_len':
			if ( isset($setting_value) && is_integer((int)$setting_value) && $setting_value > 0 )
			{
				$$setting_name = $setting_value;
			}
			break;
			
			case 'expansion_limit':
			if ( isset($setting_value) && is_integer((int)$setting_value) && $setting_value >= 0 )
			{
				$$setting_name = $setting_value;
			}
			break;
			
			default:
			if ( strpos($setting_name, "_cat_keywords") !== false )
			{
				$number = explode("_", $setting_name);
				$id = $number[0];
				$pseudo_id = end($number);
				
				# pre-existing db-row
				if ( is_numeric($id) )
				{
					$pre_existing[$id][0] = $setting_value;
				}
				# empty db-row
				else if ( !empty($setting_value) ) 
				{
					$new_category[$pseudo_id][0] = $setting_value;
				}
			}
			else if ( strpos($setting_name, "_cat_names") !== false )
			{
				$number = explode("_", $setting_name);
				$id = $number[0];
				$pseudo_id = end($number);
				
				# pre-existing db-row
				if ( is_numeric($id) )
				{
					$pre_existing[$id][1] = $setting_value;
				}
				# empty db-row
				else if ( !empty($setting_value) ) 
				{
					$new_category[$pseudo_id][1] = $setting_value;
				}
			}
			else if ( strpos($setting_name, "_cat_types") !== false )
			{
				$number = explode("_", $setting_name);
				$id = $number[0];
				$pseudo_id = end($number);
				
				# pre-existing db-row
				if ( is_numeric($id) )
				{
					$pre_existing[$id][2] = $setting_value;
				}
				# empty db-row
				else if ( isset($setting_value) && is_numeric($setting_value) ) 
				{
					$new_category[$pseudo_id][2] = $setting_value;
				}
			}
			break;
		}
	}
	
	try
	{
		$connection = db_connection();
		$connection->beginTransaction();
		
		$affected_rows = 0;
		
		# update values ( if necessary ) 
		if ( !empty($pre_existing) )
		{
			$cat_update_sql 			= "";
			$cat_update_escape			= array();
			$cat_remove_sql				= "";
			$cat_remove_escape			= array();
			$p = 0;
			$r = 0;
			
			foreach ( $pre_existing as $cat_id => $cat_data ) 
			{
				if ( count($cat_data) === 3 ) 
				{
					# update this category
					if ( !empty($cat_data[0]) && !empty($cat_data[1]) )
					{
						if ( $p > 0 ) $cat_update_sql .= ", ";
						
						$cat_update_sql .= "(:catid$p, :keywords$p, :name$p, :type$p)";
						$cat_update_escape[":catid$p"] 		= $cat_id;
						$cat_update_escape[":keywords$p"] 	= $cat_data[0];
						$cat_update_escape[":name$p"] 		= $cat_data[1];
						$cat_update_escape[":type$p"] 		= $cat_data[2];
						
						++$p;
					}
					# remove this category
					else if ( empty($cat_data[0]) && empty($cat_data[1]) )
					{
						if ( $r > 0 ) $cat_remove_sql .= ", ";
						$cat_remove_sql 			   = ":catid$r";
						$cat_remove_escape[":catid$r"] = $cat_id;
						
						++$r;
					}
				}
			}
			
			if ( !empty($cat_update_sql) )
			{
				$upd = $connection->prepare("INSERT INTO PMBCategories$index_suffix (ID, keyword, name, type) VALUES " . $cat_update_sql . " ON DUPLICATE KEY UPDATE keyword = VALUES(keyword), name = VALUES(name), type = VALUES(type)");
				$upd->execute($cat_update_escape);
				$affected_rows += $upd->rowCount();
			}
			
			if ( !empty($cat_remove_sql) )
			{
				$rem = $connection->prepare("DELETE FROM PMBCategories$index_suffix WHERE ID IN (" . $cat_remove_sql . ")");
				$rem->execute($cat_remove_escape);
				$affected_rows += $rem->rowCount();
			}
		}

		# insert new categories
		if ( !empty($new_category) )
		{
			$cat_insert_sql				= "";
			$cat_insert_escape			= array();
			$p = 0;
				
			foreach ( $new_category as $cat_id => $cat_data ) 
			{
				if ( count($cat_data) === 3 ) 
				{
					if ( $p > 0 ) $cat_insert_sql .= ", ";
					
					$cat_insert_sql .= "(:keywords$p, :name$p, :type$p)";
					$cat_insert_escape[":keywords$p"] 	= $cat_data[0];
					$cat_insert_escape[":name$p"] 		= $cat_data[1];
					$cat_insert_escape[":type$p"] 		= $cat_data[2];
					
					++$p;
				}
			}
			
			if ( !empty($cat_insert_escape) )
			{
				$ins = $connection->prepare("INSERT INTO PMBCategories$index_suffix (keyword, name, type) VALUES " . $cat_insert_sql);
				$ins->execute($cat_insert_escape);
				$affected_rows += $ins->rowCount();
			}
		}

		$connection->commit();
		
				
		# also, at the same time update previous categories ( if changes were made )
		if ( $affected_rows )
		{
			$catpdo = $connection->query("SELECT ID, keyword FROM PMBCategories$index_suffix WHERE type = 1");
					
			$categories = array();
			while ( $row = $catpdo->fetch(PDO::FETCH_ASSOC) )
			{
				# create categories
				$catkeywords = StringToKeywords($row["keyword"]);
				$categories[(int)$row["ID"]] = $catkeywords;
				
				$cat_match_count[(int)$row["ID"]] = 0;
			}
			
			if ( !empty($categories) )
			{
				$doc_cat_update_sql = "";
				
				$catcount = 0;
				$docpdo = $connection->query("SELECT ID, URL FROM PMBDocinfo$index_suffix");
				
				while ( $row = $docpdo->fetch(PDO::FETCH_ASSOC) )
				{
					# does the url belong to any category ? 
					foreach ( $categories as $cat_id => $cat_data )
					{
						if ( keyword_check($row["URL"], $cat_data[0], $cat_data[1]) )
						{
							if ( $catcount > 0 ) $doc_cat_update_sql .= ", ";
							
							$doc_cat_update_sql .= "(".$row["ID"].", $cat_id)";
							
							++$catcount;
							++$cat_match_count[$cat_id];
						}
					}
				}
				
				if ( !empty($doc_cat_update_sql) )
				{
					$catinsert = $connection->query("INSERT INTO PMBDocinfo$index_suffix (ID, cat_id) VALUES $doc_cat_update_sql ON DUPLICATE KEY UPDATE cat_id = VALUES(cat_id)");
					$updcatcount = $catinsert->rowCount();	
					
					$cat_count_update = array();
					foreach ( $cat_match_count as $cat_id => $cat_m_count ) 
					{
						if ( $cat_m_count > 0 ) 
						{
							$cat_count_update[] =  "($cat_id, $cat_m_count)";
						}
					}
					
					$catcount = $connection->query("INSERT INTO PMBCategories$index_suffix (ID, count) VALUES " . implode(", ", $cat_count_update) . " ON DUPLICATE KEY UPDATE count = VALUES(count)");
				}	
			}
		}
	}
	catch ( PDOException $e ) 
	{
		echo $e->getMessage();	
	}

	# Setup the xpdf filepath
	$xpdf_filetype = "";
	$uname = strtolower(php_uname());
	if ( strpos($uname, "darwin") !== false ) 
	{
		// It's OSX
		$xpdf_folder = "xpdf_mac/";
	} 
	else if ( strpos($uname, "win") !== false ) 
	{
		// It's windows
		$xpdf_folder = "xpdf_win/";
		$xpdf_filetype = ".exe";
	} 
	else if ( strpos($uname, "linux") !== false) 
	{
		// It's Linux
		$xpdf_folder = "xpdf_linux/";
	}
	
	if ( !empty($xpdf_folder) )
	{
		if ( PHP_INT_SIZE === 8 ) 
		{
			# OS seems to be 64 bit
			$xpdf_folder_bit = "bin64/";
		}
		else 
		{
			# OS seems to be 32 bit
			$xpdf_folder_bit = "bin32/";
		}
		
		$xpdf_folder = $xpdf_folder . $xpdf_folder_bit . "pdftotext" . $xpdf_filetype;
	}
	else
	{
		$xpdf_folder = "";
	}

	# if this is a web crawler index, a fixed amount of fields is present
	if ( $index_type == 1 ) 
	{
		$number_of_fields = 4;
		$data_columns = array("title", "content", "url", "meta");
		$main_sql_attrs = array("timestamp", "domain", "category");
	}
	
	# if localhosting is disabled, empty custom address
	if ( isset($use_localhost) && $use_localhost == 0 )
	{
		$custom_address = "";
	}
	
	$document_root = "";
	if ( !empty($_SERVER["DOCUMENT_ROOT"]) )
	{
		$document_root = $_SERVER["DOCUMENT_ROOT"];
	}
			
	if ( $index_type == 1 ) 
	{
		$beginning = "
; web-crawler indexes
" . ini_array_value_export("seed_urls", $seed_urls) . "
allow_subdomains		= $allow_subdomains
honor_nofollows			= $honor_nofollows
use_localhost			= $use_localhost
scan_depth			= $scan_depth
custom_address			= \"$custom_address\"
url_keywords			= \"$url_keywords\"
index_pdfs			= $index_pdfs
" . ini_array_value_export("trim_page_title", $trim_page_title) . "";

	}
	else
	{
		$beginning = "
; database indexes
use_internal_db			= $use_internal_db
main_sql_query			= \"$main_sql_query\"
".ini_array_value_export("main_sql_attrs", $main_sql_attrs)."
use_buffered_queries 		= $use_buffered_queries
ranged_query_value		= $ranged_query_value
html_strip_tags			= $html_strip_tags
html_remove_elements		= \"$html_remove_elements\"
include_original_data	= $include_original_data
".ini_array_value_export("html_index_attrs", $html_index_attrs)."";

	}
	
	$settings_string = "
$beginning

; common indexer settings
indexing_interval		= $indexing_interval
update_interval			= $update_interval
sentiment_analysis		= $sentiment_analysis
prefix_mode			= $prefix_mode
prefix_length			= $prefix_length
dialect_processing		= $dialect_processing
charset				= \"$charset\"
" . ini_array_value_export("blend_chars", $blend_chars) . "
" . ini_array_value_export("ignore_chars", $ignore_chars) . "
separate_alnum			= $separate_alnum
delta_indexing			= $delta_indexing
delta_merge_interval	= $delta_merge_interval
".ini_array_value_export("synonyms", $synonyms)."
					
; runtime
" . ini_array_value_export("field_weights", $field_weights, true) . "
sentiweight			= $sentiweight
keyword_stemming		= $keyword_stemming
dialect_matching		= $dialect_matching
quality_scoring			= $quality_scoring
forgive_keywords		= $forgive_keywords
expansion_limit			= $expansion_limit
log_queries			= $log_queries
keyword_suggestions		= $keyword_suggestions

; general settings
admin_email			= \"$admin_email\"
mysql_data_dir			= \"$mysql_data_dir\"
dist_threads			= $dist_threads
innodb_row_format		= $innodb_row_format
enable_exec			= $enable_exec

; do not edit !
" . ini_array_value_export("data_columns", $data_columns) . "
number_of_fields		= $number_of_fields
index_type			= $index_type
xpdf_folder			= \"$xpdf_folder\"
document_root			= \"$document_root\"
								
";
	
	# write the new settings file
	$settings_file_path = realpath(dirname(__FILE__)) . "/settings$index_suffix.txt";
	
	if ( file_put_contents($settings_file_path, $settings_string) ) 
	{
		if ( !empty($_SERVER["REMOTE_ADDR"]) )
		{
			echo "<div class='errorbox'>
			<h3 style='color:#00ff00;'>Settings file was created successfully.</h3>
		  </div>";
		}
		else
		{
			echo "Settings file was created successfully.\n";
		}
		$successful_write = true;
	}
	else
	{
		if ( !empty($_SERVER["REMOTE_ADDR"]) )
		{
			echo "<div class='errorbox'>
				<h3 style='color:#ff0000;'>Error: could not write the settings file.</h3>
				<p>Please chmod the file for greater permissions.</p>
			  </div>";
		}
		else
		{
			echo "Error: could not write the settings file.\n";
			echo "Please chmod the file for greater permissions.\n";
		}
		$successful_write = false;
	}
	
	$action = "";
	if ( isset($settings["action"]) )
	{
		$action = $settings["action"];
	}
	
	# post check
	# if this is a database index, check if column count need to be redefined
	if ( 
		($action === "updatesettings" || 
		$action === "check_db_settings") && 
		$index_type == 2 && 
		$successful_write &&
		($previous_main_sql_query != $main_sql_query ||
		$previous_main_sql_attrs != $main_sql_attrs ||
		$action === "check_db_settings") ) 
	{
		$log = "";
		$new_field_count = 0;
		$columns = array();
		# count number of columns that the sql query returns
		if ( test_database_settings($index_id, $log, $new_field_count, $columns) ) 
		{ 
			# if number of fields has changed, update that particular value
			if ( $new_field_count != $number_of_fields || $columns !== $data_columns ) 
			{
				$string_columns = implode(",", array_keys($columns));

				# write settings again ( update field count )
				write_settings(array("number_of_fields" => $new_field_count,
									 "data_columns" 	=> $string_columns
									 ), $index_id);
			}			
		}
		else
		{
			$successful_write = false;
			if ( isset($_SERVER["REMOTE_ADDR"]) )
			{
				echo "<div class='errorbox'>
				<h3 style='color:#ff0000;'>Error: Incorrect SQL parameters </h3>
				<p>".str_replace("\n", "<br>", $log)."</p>
			  </div>";
			}
			else
			{
				echo "Error: Incorrect SQL parameters.\n";
				echo $log . "\n";
			}
		}
		
	}
	
	return $successful_write;
	
} # write_settings() ends

function ini_array_value_export($setting_name, $data, $write_keys = false)
{
	$index = "";
	if ( !empty($data) )
	{
		foreach ( $data as $key => $item )
		{
			if ( $write_keys ) $index = $key;
			if ( (int)$item === $item )
			{
				$arr[] = sprintf("%-32s", $setting_name."[$index]") . "= $item";
			}
			else
			{
				$arr[] = sprintf("%-32s", $setting_name."[$index]") . "= \"$item\"";
			}
		}
		
		$output = implode("\r\n", $arr);
	}
	else
	{
		# no defined values
		$output = sprintf("%-32s", $setting_name."[]") . "= ";
	}
	return $output;
}

function check_tables($index_id, &$log = "")
{
	$index_suffix = "_" . $index_id;
	
	try
	{
		$connection = db_connection();
		
		if ( is_string($connection) )
		{
			$log .= "Something went wrong while establishing database connection. Error message: $connection\n";
			die("Something went wrong while establishing database connection. Error message: $connection\n");
		}
		
		# check that index exists
		if ( !$connection->query("SHOW TABLES LIKE 'PMBIndexes'")->rowCount() )
		{
			# PMB master indexes table not defined
			$log .= "Pickmybrain master table PMBIndexes is not defined. Please open web control panel or run clisetup.php to continue.\n";
			die("Pickmybrain master table PMBIndexes is not defined. Please open web control panel or run clisetup.php to continue.\n");
		}
		else if ( !$connection->query("SHOW TABLES LIKE 'PMBTokens$index_suffix'")->rowCount() )
		{
			# index specific table not defined
			$log .= "Pickmybrain table PMBTokens$index_suffix is not defined. Please check that index_id is correct.\n";
			die("Pickmybrain table PMBTokens$index_suffix is not defined. Please check that index_id is correct.\n");
		}
		
		$pdo = $connection->query("SHOW CREATE TABLE PMBTokens$index_suffix");
		
		if ( $row = $pdo->fetch(PDO::FETCH_ASSOC) )
		{
			foreach ( $row as $data_type => $data_string ) 
			{
				if ( stripos($data_string, "CREATE TABLE") !== false ) 
				{
					$data_string = str_replace("`", "", $data_string);
				
					# if table has no metaphone definition	
					if ( stripos($data_string, "metaphone") === false )
					{
						# metaphone column is missing ! 
						$log .= "Metaphone column definition is missing, updating table...\n";
						echo "Metaphone column definition is missing, updating table...\n";
						$alter_sql[] = "ADD metaphone smallint(5) unsigned DEFAULT 0 AFTER token, ADD INDEX (metaphone, doc_matches)";
					}
					
					# if max_doc_id is not defined
					if ( stripos($data_string, "max_doc_id") === false )
					{
						# max_doc_id column is missing ! 
						$log .= "Maximum document id column definition is missing, updating table...\n";
						echo "Maximum document id column definition is missing, updating table...\n";
						$alter_sql[] = "ADD max_doc_id int(8) unsigned NOT NULL AFTER doc_matches";
					}
				}
			}			
		}
		
		if ( !empty($alter_sql) )
		{
			try
			{
				$connection->query("ALTER TABLE PMBTokens$index_suffix " . implode(", ", $alter_sql));
				
				echo "PMBTokens$index_suffix table definition updated successfully.\n";
				$log .= "PMBTokens$index_suffix table definition updated successfully.\n";
			}
			catch ( PDOException $e ) 
			{
				$log .= "Something went wrong when updating table format. Error mesage: ".$e->getMessage()."\n";
				die("Something went wrong when updating table format. Error mesage: ".$e->getMessage()."\n");
			}
		}
	}
	catch ( PDOException $e ) 
	{
		$log .= "Something went wrong when checking table formats. Error mesage: ".$e->getMessage()."\n";
		die("Something went wrong when checking table formats. Error mesage: ".$e->getMessage()."\n");
	}
	
}

function create_tables($index_id, $index_type, &$created_tables = array(), &$data_directory_warning = "", &$general_database_errors = array(), $data_dir_sql = "", $row_compression = "")
{
	$errors = 0;
	$index_suffix = "_" . $index_id;
	
	# web crawler
	if ( $index_type == 1 ) 
	{
		$create_table["PMBDocinfo$index_suffix"] = "CREATE TABLE IF NOT EXISTS PMBDocinfo$index_suffix (
		 ID mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
		 URL varbinary(500) NOT NULL DEFAULT '',
		 url_checksum binary(16) NOT NULL DEFAULT '',
		 token_count varchar(60) NOT NULL DEFAULT '0',
		 avgsentiscore tinyint(4) DEFAULT '0',
		 attr_category tinyint(3) unsigned DEFAULT NULL,
		 field0 varbinary(255) NOT NULL DEFAULT '',
		 field1 varbinary(10000) NOT NULL DEFAULT '',
		 field2 varbinary(255) NOT NULL DEFAULT '',
		 field3 varbinary(255) NOT NULL DEFAULT '',
		 attr_timestamp int(10) unsigned NOT NULL DEFAULT '0',
		 checksum binary(16) NOT NULL DEFAULT '',
		 attr_domain int(10) unsigned NOT NULL DEFAULT '0',
		 PRIMARY KEY (ID),
		 KEY attr_category (ID, attr_category),
		 KEY url_checksum (url_checksum)
		) ENGINE=INNODB DEFAULT CHARSET=utf8 $row_compression $data_dir_sql";
	}
	# database index
	else
	{
		$create_table["PMBDocinfo$index_suffix"] = "CREATE TABLE IF NOT EXISTS PMBDocinfo$index_suffix (
		 ID int(11) unsigned NOT NULL,
		 avgsentiscore tinyint(4) NOT NULL DEFAULT '0',
		 PRIMARY KEY (ID),
		 KEY avgsentiscore (ID, avgsentiscore)	
		) ENGINE=MYISAM DEFAULT CHARSET=utf8 PACK_KEYS=1 ROW_FORMAT=FIXED $data_dir_sql";
	}
	
	$create_table["PMBTokens$index_suffix"] = "CREATE TABLE IF NOT EXISTS PMBTokens$index_suffix (
	 checksum int(10) unsigned NOT NULL DEFAULT '0',
	 token varbinary(40) NOT NULL DEFAULT '',
	 metaphone smallint(5) unsigned DEFAULT '0',
	 doc_matches int(8) unsigned NOT NULL DEFAULT '0',
	 max_doc_id int(8) unsigned NOT NULL DEFAULT '0',
	 doc_ids mediumblob NOT NULL,
	 PRIMARY KEY (checksum, token),
	 KEY metaphone (metaphone,doc_matches)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8 $row_compression $data_dir_sql";
	
	$create_table["PMBCategories$index_suffix"] = "CREATE TABLE IF NOT EXISTS PMBCategories$index_suffix (
	 ID mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
	 keyword varchar(255) NOT NULL DEFAULT '',
	 name varchar(255) NOT NULL DEFAULT '',
	 count mediumint(8) unsigned NOT NULL DEFAULT 0,
	 type tinyint(3) unsigned NOT NULL DEFAULT 0,
	 PRIMARY KEY (ID),
	 KEY keyword (keyword)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8 $row_compression $data_dir_sql";
	
	$create_table["PMBPrefixes$index_suffix"] = "CREATE TABLE IF NOT EXISTS PMBPrefixes$index_suffix (
	 checksum int(10) unsigned NOT NULL DEFAULT '0',
	 tok_data mediumblob NOT NULL,
	 PRIMARY KEY (checksum)
	 ) ENGINE=InnoDB DEFAULT CHARSET=utf8 $row_compression $data_dir_sql";
	
	$create_table["PMBQueryLog$index_suffix"] = "CREATE TABLE PMBQueryLog$index_suffix (
	 ID int(10) unsigned NOT NULL AUTO_INCREMENT,
	 timestamp int(10) unsigned NOT NULL DEFAULT '0',
	 ip int(10) unsigned NOT NULL DEFAULT '0',
	 query varbinary(255) NOT NULL DEFAULT '',
	 results mediumint(8) unsigned NOT NULL DEFAULT '0',
	 searchmode tinyint(3) unsigned NOT NULL DEFAULT '0',
	 querytime mediumint(8) unsigned NOT NULL DEFAULT '0',
	 PRIMARY KEY (ID),
	 KEY query (query),
	 KEY ip (ip),
	 KEY results (results),
	 KEY querytime (querytime)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8 $data_dir_sql";

	# 7. If there are no errors, check whether the database tables exist
	# if not, create the tables
	try
	{
		$connection 				= db_connection();
		$created_tables 			= array();
		$data_directory_warning 	= "";
		$general_database_errors 	= array();
		$latest_sql 				= "";

		foreach ( $create_table as $table_name => $table_sql ) 
		{
			try
			{
				# table doesn't exist, create it ! 
				if ( !$connection->query("SHOW TABLES LIKE '$table_name'")->rowCount() )
				{
					$connection->query($table_sql);
					
					# store tables that have been created 
					$created_tables[$table_name] = 1;
				}
			}
			catch( PDOException $e )
			{
				if ( !empty($data_dir_sql) )
				{
					# maybe the failure is because because a custom data directory is set ! 
					# try againt without it
					try
					{
						$connection->query(str_ireplace($data_dir_sql, "", $table_sql));
						
						# it went through, so the data directory is the problem ! 
						$data_directory_warning = $e->getMessage();
						
						# store tables that have been created 
						$created_tables[$table_name] = 1;
					}
					catch ( PDOException $e ) 
					{
						# general database error
						$general_database_errors[] = $e->getMessage();
						++$errors;
					}
				}
				else
				{
					# general database error
					$general_database_errors[] = $e->getMessage();
					++$errors;
				}
			}

		}
	}
	catch ( PDOException $e ) 
	{
		$general_database_errors[] = $e->getMessage();
	}
	
	if ( $errors === 0 ) 
	{
		return true;
	}
	
	return false;
}

function removedomain($url)
{
	$p = parse_url($url);
	
	$path     = isset($p['path']) ? $p['path'] : '';
	$query    = isset($p['query']) ? '?' . $p['query'] : '';
	$fragment = isset($p['fragment']) ? '#' . $p['fragment'] : '';
	
	return "$path$query$fragment";
}

function DeltaVBencode(array $integers, array $hex_lookup, $start_offset = 0)
{
	$rparts = "";
	$delta = 1;
	if ( $start_offset > 0 ) 
	{
		$delta = $start_offset;
	}
	
	foreach ( $integers as $integer )
	{
		$tmp = $integer-$delta+1;
		
		do
		{
			# get 7 LSB bits
			$lowest7bits = $tmp & 127;
			
			# shift original number >> 7 
			$tmp >>= 7;
			
			if ( $tmp ) 
			{
				# number is yet to end, prepend 0 ( or actually do nothing :)
				$rparts .= $hex_lookup[$lowest7bits];
			}
			else
			{
				# number ends here, prepend 1
				$rparts .= $hex_lookup[(128 | $lowest7bits)];
			}
		}
		while ( $tmp ) ;

		$delta = $integer;
	}
	
	return $rparts;
}

function VBDeltaDecode($hexstring, array $hex_lookup)
{
	$delta = 1;
	$len = strlen($hexstring);
	$temp = 0;
	$shift = 0;
	$result = array();
	
	for ( $i = 0 ; $i < $len ; ++$i )
	{
		$bits = $hex_lookup[$hexstring[$i]];
		$temp |= (($bits & 127) << $shift*7);
		++$shift;
			
		if ( $bits > 127 )
		{
			# 8th bit is set, number ends here ! 
			$delta = $temp+$delta-1;
			$result[] = $delta;
			$temp = 0;
			$shift = 0;
		}
	}
		
	return $result;
}

function MergeCompressedData($old_data, $new_data, array $hex_lookup, array $hex_lookup_encode ) 
{
	# step 1: find the max delta value from the old sttring
	$delta = 1;
	$len = strlen($old_data);
	$temp = 0;
	$shift = 0;
	$max_val = 0;
	
	for ( $i = 0 ; $i < $len ; ++$i )
	{
		$bits = $hex_lookup[$old_data[$i]];
		$temp |= (($bits & 127) << $shift*7);
		++$shift;
			
		if ( $bits > 127 )
		{
			# 8th bit is set, number ends here ! 
			$delta = $temp+$delta-1;
			$max_val = $delta;
			$temp = 0;
			$shift = 0;
		}
	}

	# reset variables for decoding
	$delta = 1;
	$first_value_len = 0;
	
	# step 2: find the first value of the new_docids string and replace it with a proper delta value
	for ( $i = 0 ; $i < 12 ; ++$i )
	{
		$bits = $hex_lookup[$new_data[$i]];
		$temp |= (($bits & 127) << $shift*7);
		++$shift;
			
		if ( $bits > 127 )
		{
			# 8th bit is set, number ends here ! 
			$delta = $temp+$delta-1;
			$first_value = $delta; 
			$temp = 0;
			$shift = 0;
			$first_value_pos = $i+1;
			$i = 12; # we got what we wanted, end now
		}
	}
	
	# re-encode the first value
	if ( $max_val >= $first_value ) 
	{
		echo "Values to be merged have to be in ascending order!\n";
		return false;
	}
	
	# new delta 
	$first_new_value = VBencode(array($first_value-$max_val+1), $hex_lookup_encode);
	
	return $old_data . $first_new_value . substr($new_data, $first_value_pos);
}

# finds out maximum integer value of variable byte length and delta encoded array
function VBDeltaStringMaxValue($hexstring, array $hex_lookup)
{
	$delta = 1;
	$len = strlen($hexstring);
	$temp = 0;
	$shift = 0;
	$max_val = 0;
	
	for ( $i = 0 ; $i < $len ; ++$i )
	{
		$bits = $hex_lookup[$hexstring[$i]];
		$temp |= (($bits & 127) << $shift*7);
		++$shift;
			
		if ( $bits > 127 )
		{
			# 8th bit is set, number ends here ! 
			$delta = $temp+$delta-1;
			$max_val = $delta;
			$temp = 0;
			$shift = 0;
		}
	}
		
	return $max_val;
}

function VBencode(array $array, array $hex_lookup)
{
	$rparts = "";
	
	foreach ( $array as $integer )
	{	
		if ( $integer < 0 ) return false;
	
		do
		{
			# get 7 LSB bits
			$lowest7bits = $integer & 127;
			
			# shift original number >> 7 
			$integer = $integer >> 7;
			
			if ( $integer ) 
			{
				# number is yet to end, prepend 0 ( or actually do nothing :)
				$rparts .= $hex_lookup[$lowest7bits];
			}
			else
			{
				# number ends here, prepend 1
				$rparts .= $hex_lookup[(128 | $lowest7bits)];
			}
		}
		while ( $integer ) ;
	}
	
	# kaanna parts-taulukko toisin pain ( ja muuta heksaluvuiksi )
	# notice: the bytes are in little-endian order
	return $rparts;
}

function DeltaEncode(array $integers)
{
	$delta = 1;
	$result = array();
	
	foreach ( $integers as $integer )
	{
		$result[] = $integer-$delta+1;
		$delta = $integer;
	}
	
	return $result;
}

function get_local_url($url, $suffix_list, $custom_address = "")
{
	$p = parse_url($url);

	if ( !empty($custom_address) )
	{
		# use custom address
		# subdomains disabled
		$p["host"] = $custom_address;	
	}
	else # default custom_address localhost, preserve subdomains
	{
		$hostparts = explode(".", $p["host"]);
	
		if ( count($hostparts) > 2 )
		{
			$hostparts = array_reverse($hostparts);
			$j = 0;
			
			foreach ( $hostparts as $i => $hostpart ) 
			{
				if ( $i === 0 && isset($suffix_list[$hostpart]) )
				{
					$new_suffix = $suffix_list[$hostpart];
				}
				else if ( isset($new_suffix[$hostpart]) ) 
				{
					$new_suffix = $new_suffix[$hostpart];
				}
				else
				{
					break;
				}
				++$j;
			}

			if ( $j > 0 ) 
			{
				# replace current index with localhost
				$hostparts[$j] = "localhost";	
				$hostparts = array_reverse(array_slice($hostparts, $j));
				# remove first subdomain and rewrite original value
				$p["host"] = str_ireplace("www.", "", implode(".", $hostparts));
			}
			else
			{
				# no subdomains detected, replace whole host
				$p["host"] = "localhost";	
			}
		}
		else
		{
			# no subdomains detected, replace whole host
			$p["host"] = "localhost";	
		}
	}
	
	$scheme   = isset($p['scheme']) ? $p['scheme'] . '://' : '';
	$host     = isset($p['host']) ? $p['host'] : '';
	$port     = isset($p['port']) ? ':' . $p['port'] : '';
	$user     = isset($p['user']) ? $p['user'] : '';
	$pass     = isset($p['pass']) ? ':' . $p['pass']  : '';
	$pass     = ( $user || $pass ) ? "$pass@" : '';
	$path     = isset($p['path']) ? $p['path'] : '';
	$query    = isset($p['query']) ? '?' . $p['query'] : '';
	$fragment = isset($p['fragment']) ? '#' . $p['fragment'] : '';

	return "$scheme$user$pass$host$port$path$query$fragment";
}

function rebuild_prefixes($prefix_mode, $prefix_length, $dialect_replacing, $index_suffix)
{
	$index_id = (int)trim(str_replace("_", "", $index_suffix));
	
	if ( !is_numeric($index_id) )
	{
		return false;
	}
	
	$connection = db_connection();

	try
	{
		$pdo = $connection->query("SELECT ID, token FROM PMBTokens$index_suffix");
		$connection->query("TRUNCATE TABLE PMBPrefixes$index_suffix");
		
		$countpdo = $connection->query("SELECT COUNT(ID) FROM PMBTokens$index_suffix");
		$total_tok_count = $countpdo->fetchColumn();
		
		$connection->query("UPDATE PMBIndexes SET 
							indexing_permission = 1,
							current_state = 2,
							temp_loads = 0,
							temp_loads_left = $total_tok_count
							WHERE ID = $index_id");
		
	}
	catch ( PDOException $e ) 
	{
		echo $e->getMessage();
		return;
	}
	
	# for dialect processing
	if ( !empty($dialect_replacing) )
	{
		$dialect_find 		= array_keys($dialect_replacing);
		$dialect_replace 	= array_values($dialect_replacing);
	}
	
	$prefix_sql 	= array();
	$prefix_escape 	= array();
	$cc = 0;
	$tok = 0;

}

function url_basestructure($url, $http = true)
{
	if ( strpos($url, "https://") !== false ) # lisatty 250102012
	{
		$protocol = 'https://';
	}
	else 
	{
		$protocol = 'http://';
	}
	
	$parsed_url = parse_url($url);
	
	if ( !$http ) $protocol=''; 	
	$url = str_replace($protocol, "", $url);
	$parts = explode("/", $url);	
	$end = "";
	$count = count($parts);
	$i = 0;

	while ( $i < $count && ((!fileexpression($parts[$i]) && strpos($parts[$i], "?") === false && strpos($parts[$i], "#") === false) || (!empty($parsed_url['host']) && substr_count($parts[$i], $parsed_url['host'])) > 0))  
	{
		++$i;
	}
		
	# jos osoitetta leikattu
	if ( $i !== $count )
	{
		$end = "/";
	}
	
	return $protocol.implode("/", array_slice($parts, 0, $i)).$end;
}

function ModifySQLQuery($main_sql_query, $dist_threads, $process_number, $min_doc_id, $limit = 0, $write_buffer_len = 100)
{
	# alter the SQL query here if multiprocessing is turned on OR min_doc_id is greater than zero! 
	if ( $dist_threads > 1 || $min_doc_id > 0 ) 
	{
		$main_sql_query = trim(str_replace(array("\n\t", "\t\n", "\r\n", "\n", "\t", "\r"), " ", $main_sql_query));
		$main_sql_query = str_replace(",", ", ", $main_sql_query);

		$parts = explode(" ", $main_sql_query);
		$temp = array();
		foreach ( $parts as $part ) 
		{
			if ( $part != "" )
			{
				$temp[] = $part;
			}
		}
		
		$trimmed_sql = implode(" ", $temp);
		
		$where_cond     = " WHERE ";
		$prefix 		= "";
		$first_part 	= $trimmed_sql;
		$last_part 		= "";
		$catches = array("where", "group by", "having", "order by", "limit");
		
		/* NOTICE: IF LIMIT IS PRESENT, THE LIMIT MUST BE UPDATED LIMIT = LIMIT / DIST_THREADS */

		foreach ( $catches as $catch ) 
		{
			# find the last occurance of the needle
			$pos = strripos($trimmed_sql, " " . $catch );
			
			if ( $pos !== false ) 
			{
				if ( $catch === "where" ) 
				{
					# where condition already exists, so we must use AND condition
					$where_cond = "";
					$prefix = " AND ";
				}
				else
				{
					$first_part = substr($trimmed_sql, 0, $pos);
					$last_part = substr($trimmed_sql, $pos);
					break;
				}
			}
		}

		$mod_value = $dist_threads * $write_buffer_len;
		$mod_result_min = $write_buffer_len * $process_number;
		$mod_result_max = $mod_result_min + $write_buffer_len;

		$primary_column  =  trim($temp[1], " \t\n\r\0\x0B,");
		if ( $dist_threads > 1 ) 
		{
			$main_sql_query  = $first_part . $where_cond . $prefix . "$primary_column % $mod_value >= $mod_result_min AND $primary_column % $mod_value < $mod_result_max";
			if ( $min_doc_id > 0 ) $main_sql_query .= " AND $primary_column >= $min_doc_id";
		}
		else # min_doc_id > 0 
		{
			$main_sql_query  = $first_part . $where_cond . $prefix . "$primary_column >= $min_doc_id ";
		}
		
		$main_sql_query .= $last_part;
		
		if ( is_numeric($limit) && $limit > 0 ) $main_sql_query .= " LIMIT $limit";
	}
	
	return $main_sql_query;
}

# shutdown function is an anonymous function
# it requires a wrapper
if ( isset($index_id) && isset($process_number) )
{
	$shutdown_function = function() use($index_id, $process_number, &$log) 
	{ 
		$error = error_get_last();
		
		# if script execution halted to an error
		if ( $error['type'] === E_ERROR ) 
		{
			# include settings file ( again ) 
			include "autoload_settings.php";
			
			if ( !empty($admin_email) )
			{
				$from_domain 	= "mydomain.com";
				$from_mail 		= "sender@mydomain.com";
				$mailtail 		= "\n\nRegards,\n$from_domain";
				$message = $error['message'] . "\nIn file: " . $error['file'] . "\nOn line: " . $error['line'];
				
				mail($admin_email, "Fatal error occurred:\n", $message, 'From: ' . $from_domain . ' <'.$from_mail.'>', "-f $from_mail -r $from_mail");
			}
			
			# Set indexing state ( again ) 
			SetIndexingState(0, $index_id);
			SetProcessState($index_id, $process_number, 0);
		}
		
		# just to be sure
		# if multiprocessing is enabled, reset process states 
		if ( $process_number > 0 ) 
		{
			SetProcessState($index_id, $process_number, 0);
		}
		else
		{
			# write log file
			$log_file_path = realpath(dirname(__FILE__)) . "/log_".$index_id.".txt";
			file_put_contents($log_file_path, $log);
			
			# if we are in the main process
			SetIndexingState(0, $index_id);
			SetProcessState($index_id, $process_number, 0);
		}
	};
}

function shutdown($index_id, $process_number = 0)
{
	$error = error_get_last();
	
	# if script execution halted to an error
	if ( $error['type'] === E_ERROR ) 
	{
		# include settings file ( again ) 
		include "autoload_settings.php";
		
		if ( !empty($admin_email) )
		{
			$from_domain 	= "mydomain.com";
			$from_mail 		= "sender@mydomain.com";
			$mailtail 		= "\n\nRegards,\n$from_domain";
			$message = $error['message'] . "\nIn file: " . $error['file'] . "\nOn line: " . $error['line'];
			
			mail($admin_email, "Fatal error occurred:\n", $message, 'From: ' . $from_domain . ' <'.$from_mail.'>', "-f $from_mail -r $from_mail");
		}
		
		# Set indexing state ( again ) 
		SetIndexingState(0, $index_id);
		SetProcessState($index_id, $process_number, 0);
    }
	
	# just to be sure
	# if multiprocessing is enabled, reset process states 
	if ( $process_number > 0 ) 
	{
		SetProcessState($index_id, $process_number, 0);
	}
	else
	{
		# if we are in the main process
		SetIndexingState(0, $index_id);
		SetProcessState($index_id, $process_number, 0);
	}
}


function SetIndexingState($state, $index_id)
{
	if ( !is_numeric($state) || $state > 6 || $state < 0 )
	{
		return 0;
	}
	
	if ( !$state )
	{
		$indexing_permission = 0;
	}
	else
	{
		$indexing_permission = 1;
	}
	
	$connection = db_connection();
	
	try
	{
		$perm = $connection->prepare("UPDATE PMBIndexes SET current_state = ?, indexing_permission = $indexing_permission, updated = UNIX_TIMESTAMP() WHERE ID = ?");
		$perm->execute(array($state, $index_id));
	}
	catch ( PDOException $e ) 
	{
		echo $e->getMessage() . "\n";
		try
		{
			$perm = $connection->prepare("UPDATE PMBIndexes SET current_state = ?, indexing_permission = $indexing_permission, updated = UNIX_TIMESTAMP() WHERE ID = ?");
			$perm->execute(array($state, $index_id));
		}
		catch ( PDOException $e ) 
		{
			echo $e->getMessage() . "\n";
			return 0;
		}
	}
	
	return 1;
}

function delete_doc_data($identifier, $index_id, $remove_docinfo = false)
{
	$document_id = false;
	
	if ( empty($identifier) )
	{
		return false;
	}
	
	if ( empty($index_id) )
	{
		return false;
	}
	
	$index_suffix = "_" . $index_id;
	
	try
	{
		$connection = db_connection();
		
		# numeric identifier => database row id
		
		if ( is_numeric($identifier) )
		{
			# check if doc actually exists
			$prepdo = $connection->prepare("SELECT ID FROM PMBDocinfo$index_suffix WHERE ID = ?");
			$prepdo->execute(array($identifier));	
		}
		# string identifier => document url
		else
		{
			# check if doc actually exists
			$prepdo = $connection->prepare("SELECT ID FROM PMBDocinfo$index_suffix WHERE URL = ?");
			$prepdo->execute(array($identifier));
		}
	
		# if it does, proceed
		if ( $row = $prepdo->fetch(PDO::FETCH_ASSOC) ) 
		{
			$document_id = $row["ID"];
		
			if ( !empty($remove_docinfo) )
			{
				# also, remove docinfo if requested
				$connection->query("DELETE FROM PMBDocinfo$index_suffix WHERE ID = $document_id");
				$connection->query("UPDATE PMBIndexes SET documents = documents - 1 WHERE ID = $document_id");
			}
	
			##$connection->commit();	
		}
		else
		{
			# doc with provided url/id doesn't exist
			return false;
		}
	}
	catch ( PDOException $e ) 
	{
		echo "we in delete_doc_data with $identifier , message: " . $e->getMessage();
		die();
		#return false;
	}
	
	# document exists with id document_id
	return $document_id;
}

function SetProcessState($index_id, $process_number, $process_state)
{
	$connection = db_connection();
	$directory = realpath(dirname(__FILE__));
	$filepath = $directory . "/pmb_".$index_id."_".$process_number.".pid";
	
	try
	{
		if ( $process_state == 1 ) 
		{
			# turn process indicator on
			@unlink($filepath);
			file_put_contents($filepath, getmypid());
		}
		else
		{
			# turn process indicator off
			@unlink($filepath);
		}
		
	}
	catch ( PDOException $e ) 
	{
		echo "SetProcessState failure #1: " . $e->getMessage() . "\n";
		# dis important, try again
		try
		{
			if ( $process_state == 1 ) 
			{
				# turn process indicator on
				@unlink($filepath);
				file_put_contents($filepath, getmypid());
			}
			else
			{
				# turn process indicator off
				@unlink($filepath);
			}
			
		}
		catch ( PDOException $e ) 
		{
			echo "SetProcessState failure #2: " . $e->getMessage() . "\n";
		}
	}
} 

function blended_chars_new($data) 
{
	#$data[0]; # matched word
	#$data[1]; # blend char 
	$data[0] = trim($data[0], " \t\n\r\0\x0B" . $data[1]);
	$ret = str_replace($data[1], " ", $data[0]);	#  s.t.a.l.k.e.r. => s t a l k e r
	
	if ( $ret !== $data[0] ) 
	{	
		$combined = str_replace(" ", "", $ret); 		# s t a l k e r => stalker , b w => bw
		$comb_len = mb_strlen($combined);			# 7 2	

		if ( substr_count($ret, " ")+1 === $comb_len && $comb_len > 2 ) 
		{
			$ret = $combined;
		}
	}

	return $ret;			
}

function blendedwords($data) 
{
	#print_r($data);
	#$data[0]; # matches word
	#$data[1]; # blend char 
	$data[0] = trim($data[0], " \t\n\r\0\x0B" . $data[1]);
	$ret = $data[0];

	# use trim($string, " \t\n\r\0\x0B"); ????
	# s.t.a.l.k.e.r. => s t a l k e r 
	$t = str_replace($data[1], " ", $data[0]);	#  s.t.a.l.k.e.r. => s t a l k e r
	
	if ( $t === $data[0] ) 
	{
		return $t;
	}
	
	$combined = str_replace(" ", "", $t); 		# s t a l k e r => stalker , b w => bw
	$comb_len = mb_strlen($combined);			# 7 2	
				
	# s t a l k e r  => stalker
	# if conditions allow this
	if ( substr_count($t, " ")+1 === $comb_len ) 
	{
		#echo "\ncombined version of $t: $combined\n";
		if ( $comb_len > 2 ) 
		{
			$ret .= " $combined";
		}
	}
	# if length of all different words !== 1, replace blended chars with space
	else 
	{
		$ret .= " ".$t;
	}
	
	#echo "blend:" . $ret . "\n";
				
	return $ret;			
}

function scoreModifier(DOMNode $domNode, array &$data)
{
	$unwanted_characters = array("\t", "\n", "\r", "&#13;", "\r\n", "?", "!", ".", ",", ":", "\"", "/", "(", ")", "_", "[", "]", ";", " -", "- ", "&", "^", "@", "<", ">", "\\", "´", "|");
	
	# define custom scores [nodename] => score ( default score is 1 )
	$score_array["h1"] = 2;
	$score_array["h2"] = 1.8;
	$score_array["h3"] = 1.6;
	$score_array["h4"] = 1.4;
	$score_array["h5"] = 1.25;
	$score_array["h6"] = 1.1;
	
    foreach ($domNode->childNodes as $node)
    {
		# capture data of text nodes only
		if ( $node->nodeType === XML_TEXT_NODE && !empty($score_array[$node->parentNode->nodeName]) && $value = trim($node->nodeValue)  ) 
		{
			# store matches as an array: [token] => array(score1, score2...)
			foreach ( explode(" ", mb_strtolower(trim(str_replace($unwanted_characters, " ", $value)))) as $token ) 
			{
				if ( empty($data[$token]) ) $data[$token] = array();
				$data[$token][] = $score_array[$node->parentNode->nodeName];
			}	
		}
		# we must go deeper
		else if ( $node->nodeType === XML_ELEMENT_NODE && $node->hasChildNodes() ) 
		{
            scoreModifier($node, $data);
        }
    }    
}

function curl_exec_utf8($ch) {
    $data = curl_exec($ch); 
    if (!is_string($data)) return $data;

    unset($charset);
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

    # 1: HTTP Content-Type: header 
    preg_match( '@([\w/+]+)(;\s*charset=(\S+))?@i', $content_type, $matches );
    if ( !empty($matches[3]) )
        $charset = $matches[3];

    # 2: <meta> element in the page 
    if ( empty($charset) ) {
        preg_match( '@<meta\s+http-equiv="Content-Type"\s+content="([\w/]+)(;\s*charset=([^\s"]+))?@i', $data, $matches );
        if ( isset( $matches[3] ) )
            $charset = $matches[3];
    }

    # 3: <xml> element in the page 
    if ( empty($charset) ) {
        preg_match( '@<\?xml.+encoding="([^\s"]+)@si', $data, $matches );
        if ( isset( $matches[1] ) )
            $charset = $matches[1];
    }

    # 4: PHP's heuristic detection
    if ( empty($charset) ) {
        $encoding = mb_detect_encoding($data);
        if ($encoding)
            $charset = $encoding;
    }

    # 5: Default for HTML
    if ( empty($charset) ) {
        if (strstr($content_type, "text/html") === 0)
            $charset = "ISO 8859-1";
    }

    if (isset($charset) && strtoupper($charset) != "UTF-8")
        $data = iconv($charset, 'UTF-8', $data);

    return $data;
}

function uniteurl($base, $link_original)
{
	if ( empty($link_original) || empty($base) ) return false;
	
	if ( strpos($link_original, "https://") !== false || strpos($link_original, "http://") !== false || stripos($link_original, "mailto:") === 0 )
	{
		return $link_original;
	}
	else if ( strpos($link_original, "//") === 0 )
	{
		return parse_url($base, PHP_URL_SCHEME) . ":" . $link_original;
	}
		
	if ( strpos($base, "https://") !== false ) # 25102012 alken myos https tuki
	{
		$http = 'https://';
	}
	else
	{
		$http = 'http://';
	}
		
	$base = str_replace($http, "", $base);
	$link = str_replace($http, "", $link_original);
	$linkcopy=$http.$link;
	$basecopy=$http.$base;
	
	# mikali linkki on valmiiksi taydellinen, varmista tapaukset => varmista etta domain loytyy
	$linkarr = parse_url($link_original);
	if ( !empty($linkarr['host']) && $linkarr['host'] !== '.' && substr_count($linkarr['host'], ".") > 0 && !fileexpression($linkarr['host']) )
	{
		return $http.$link;
	}
	
	# fragment?
	if ( $link[0] === "#" ) 
	{
		return $http.$base.$link;
	}
	
	# linkki viittaa suoraan domainin juureen
	$basearr = parse_url($basecopy);
	if ( $link[0] === "/" )
	{
		$whole = $basearr['host'].$link;
		return $http.$whole;
	}
	
	# linkki ei viittaa suoraan domainin juureen
	$i = 0;
	$linkparts = explode("/", $link);
	$partcount = count($linkparts);
	
	if ( empty($linkparts[0]) || $linkparts[0] === '.'  ) 
	{
		++$i;
	}
	# strip one folder from the baseurl
	else if ( $linkparts[0] === '..' ) 
	{
		do
		{
			++$i;
			if ( !empty($basearr['path']) )
			{
				# remove the last folder from the path
				$bparts = explode("/", $basearr['path']);
				array_pop($bparts);							
				$nparts = implode("/", $bparts);
				$base = $basearr['host'].'/'.$nparts;
				
				# redefine $basearr['path'] for later iterations
				if ( !empty($nparts) )
				{
					$basearr['path'] = str_replace("//", "/", '/'.$nparts);
				}
			}
		} while ( $linkparts[$i] === '..' );	
	}

	if ( !empty($linkparts[$i]) ) 
	{
		# special case:
		if ( substr(trim($base), -1) !==  "/" )
		{
			$baseparts = explode("/", $base);
			if ( !fileexpression(endc($baseparts)) )
			{
				$newbase = trim(implode("/", array_slice($baseparts, 0, count($baseparts)-1)));
				
				if ( !empty($newbase) )
				{
					$base = $newbase;
				}
			}
		}
		
		while ( !empty($linkparts[$i]) && (strpos($base, '/'.$linkparts[$i]) !== false || strpos($base, $linkparts[$i].'/') !== false) ) 
		{
			++$i;
		}
		
		$newlink = implode('/', array_slice($linkparts, $i));
		$whole = str_replace("//", "/", $base.'/'.$newlink);
		return $http.$whole;
	}
}

# tarkistaa sisaltaako polku/linkki viittauksia tiedostoon, string, palautetaanko tiedoston nimi, palautetaanko vain tiedostonimet joissa muuttujia
function fileexpression($t, $rdata = false, $hasvariables = false, $n = false, $returnVar = false)
{
	if ( !$n ) $n = array('.php', '.html', '.htm', '.aspx', '.asp', '.cgi', '.cfm', '.jsp', '.pl');
	
	$pit = strlen($t);
	$i = 0;
	while ( !empty($n[$i]) ) 
	{
		$pos = strpos($t, $n[$i]);
		$ext_len = strlen($n[$i]);
		
		if ( $pos !== false && (!isset($t[$pos+$ext_len]) || $t[$pos+$ext_len] !== '-' ) ) #muokattu 25102012
		{
			$continue = true;
			# mikali halutaan vain sellaiset tiedostonimet joissa muuttujia, esim data.php?m=1
			if ( $hasvariables )
			{
				# anything after file ext ?
				$data = explode($n[$i], $t);
				
				if ( empty($data[1]) ) 
				{
					# nothing after the file ext, no need to continue
					$continue = false;	
				}
			}
			
			if ( $continue )
			{
				# jos tiedostonimea ei tarvitse palauttaa, vain varmistus etta sellainen loytyi	
				if ( !$rdata ) 
				{
					return true;
				}
				# palauta pelkka tiedostopaate
				else if ( $rdata === 1 )
				{
					return $n[$i];
				}
				
				# jos palautetaan tiedostonimi + paate + muuttujat
				if ( $returnVar )
				{
					$d = parse_url($t);
					
					if ( empty($d['query']) ) 
					{
						$q = false;
					}
					else
					{
						$q = $d['query'];
					}		
					$parts = explode("#", explode("?", basename($t)));
					return array($q, $parts[0]);
				}
				
				# jos palautetaan pelkka tiedosnimi + paate (tiedosto.php)
				$t = explode("#",substr($t, 0, $pos+$ext_len));
				return basename($t[0]);
			}
		}
		
		++$i;
	}
	
	# 25102012: lisatarkistus: www.osoite.com/Tiedosto-linkki?muuttuja=1 => palauta Tiedosto-linkki tiedostonimena
	$t = str_ireplace(array('https://', 'http://'), "", $t);
	$parts = explode("/", $t);
	$data = parse_url(endc($parts)); 
	if ( !empty($data['query']) && !empty($data['path']) ) 
	{
		if ( $rdata === true || $rdata === 1 ) 
		{
			return str_replace("?".$data['query'], "", endc($parts));
		}
		return true;
	}
	return false;
}

function endc( $array ) { return end( $array ); }

function getDomainInfo($link, $suffix_list = array())
{
	if ( empty($link) ) return false;
	
	$link = strtolower(trim($link)); # tutkittava linkki
	
	# quickfix for forgotten schemes
	if ( strpos($link, "www.") === 0 ) $link = "http://".$link;
	
	$protocols = array("http://", "https://", "//", "ftp://");
	$protocol_found = false;
	
	foreach ( $protocols as $protocol )
	{
		if ( strpos($link, $protocol) === 0 ) 
		{
			if ( $protocol === "//" ) 
			{
				# fix for old php versions
				$link = "http:" . $link; 
			}
			
			$protocol_found = true;
			break;
		}
	}
	
	# no procotol found
	# therefore this link cannot point outside the current domain
	if ( !$protocol_found ) 
	{
		return false;
	}
		
	$l = parse_url($link);

	$hostparts = explode(".", $l["host"]);
	$hostcount = count($hostparts);	

	$link_domain 	= "";
	$link_subdomain = "";
	
	if ( $hostcount > 2 )
	{
		$hostparts_r = array_reverse($hostparts);
		$j = 0;
			
		foreach ( $hostparts_r as $i => $hostpart ) 
		{
			if ( $i === 0 && isset($suffix_list[$hostpart]) )
			{
				$new_suffix = $suffix_list[$hostpart];
			}
			else if ( isset($new_suffix[$hostpart]) ) 
			{
				$new_suffix = $new_suffix[$hostpart];
			}
			else
			{
				break;
			}
			++$j;
		}
		
		if ( $j > 0 ) 
		{
			$link_domain 	= implode(".", array_slice($hostparts, $hostcount-$j-1));
			$link_subdomain = implode(".", array_slice($hostparts, 0, $hostcount-$j-1));	
		}
		else
		{	
			# special case: localhost
			$last = end($hostparts);
			
			if ( $last === "localhost" ) 
			{
				$link_subdomain = implode(".", array_slice($hostparts, 0, -1));
				$link_domain = "localhost";
			}
			else
			{
				# no subdomains detected
				$link_domain = $l["host"];
			}
		}
	}
	else if ( $hostcount === 2 ) 
	{
		# special case: localhost
		$last = end($hostparts);
			
		if ( $last === "localhost" ) 
		{
			$link_subdomain = implode(".", array_slice($hostparts, 0, -1));
			$link_domain = "localhost";
		}
		else
		{
			# no subdomains detected
			$link_domain = $l["host"];
		}
	}
	else
	{
		$link_domain = $hostparts[0];
	}

	$link_subdomain = str_replace(array("www.", "www"), "", $link_subdomain);
	
	return array("subdomain" => $link_subdomain, "domain" => $link_domain);
}

function domainCheck($link, $allowed_domains, $allow_subdomains = 1)
{
	if ( $link === false ) 
	{
		return true;
	}
	
	# domain name is not allowed ! 
	if ( !isset($allowed_domains[$link["domain"]]) )
	{
		return false;
	}
	
	# if subdomains are not allowed by default, do an extra check
	if ( !$allow_subdomains ) 
	{
		# subdomain has to be pre-defined in the seed urls
		# or a wildcard subdomain ( * ) is defined for this domain
		if ( !isset($allowed_domains[$link["domain"]][$link["subdomain"]]) && !isset($allowed_domains[$link["domain"]]["*"]) ) 
		{
			return false;	
		}
	}
	
	return true;
}

function curl_multi_getcontent_utf8($ch)
{
	$data = curl_multi_getcontent($ch);
	if ( empty($data) ) return false;
	$content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
	
	 # 1: HTTP Content-Type: header 
    preg_match( '@([\w/+]+)(;\s*charset=(\S+))?@i', $content_type, $matches );
    if ( !empty($matches[3]) )
        $charset = $matches[3];

    # 2: <meta> element in the page 
    if ( empty($charset) ) {
        preg_match( '@<meta\s+http-equiv="Content-Type"\s+content="([\w/]+)(;\s*charset=([^\s"]+))?@i', $data, $matches );
        if ( isset( $matches[3] ) )
            $charset = $matches[3];
    }

    # 3: <xml> element in the page 
    if ( empty($charset) ) {
        preg_match( '@<\?xml.+encoding="([^\s"]+)@si', $data, $matches );
        if ( isset( $matches[1] ) )
            $charset = $matches[1];
    }

    # 4: PHP's heuristic detection
    if ( empty($charset) ) {
        $encoding = mb_detect_encoding($data);
        if ($encoding)
            $charset = $encoding;
    }

    # 5: Default for HTML
    if ( empty($charset) ) {
        if (strstr($content_type, "text/html") === 0)
            $charset = "ISO 8859-1";
    }

    if (isset($charset) && strtoupper($charset) != "UTF-8")
        $data = iconv($charset, 'UTF-8', $data);

    return $data;
}


class webRequest
{
	private $urls;
	private $html;
	private $responses;
	private $redirections;
	private $runtime;
	private $loadtimes;
	private $useragent;
	private $proxy;
	private $cookiefile;
	private $javascript;
	private $js_gate_url;
	private $failed_pageloads;
	private $retry_attempts;
	private $followlocation;
	
	public function getRunTime()
	{
		return $this->runtime;
	}
	
	public function getResponses()
	{
		return $this->responses;
	}
	
	public function getResult()
	{
		return $this->html;
	}
	
	public function getRedirections()
	{
		return $this->redirections;
	}
	
	public function getLoadTimes()
	{
		return $this->loadtimes;
	}
	
	public function __construct($urlList, $useragent = false, $proxy = false, $cookiefile = true, $javascript = false, $followlocation = true)
	{
		$this->urls = $urlList;
		$this->html = array();
		$this->responses = array();
		$this->cookiefile = $cookiefile;
		$this->loadtimes = array();
		$this->javascript 	= $javascript;
		$this->js_gate_url	= "127.0.0.1/testing/jsgate.php?url=";
		$this->retry_attempts = 1;
		$this->failed_pageloads = 0;
		$this->followlocation = $followlocation;
		
		if ( empty($useragent) )
		{
			$this->useragent = "Mozilla/5.0 (Windows; U; Windows NT 5.1; rv:1.7.3) Gecko/20041001 Firefox/0.10.1";
		}
		else
		{
			$this->useragent = $useragent;
		}
		
		if ( !empty($proxy) )
		{
			$this->proxy['ip'] = $proxy[0];
			$this->proxy['auth'] = $proxy[1];
		}
		else
		{
			$this->proxy = false;
		}
	}
	
	public function startRequest()
	{
		# array of curl handles
		$curly = array();
		# results
		$result = array();
		# http responses
		$httpcodes = array();
		# loadtimes
		$loadtimes = array();
		# multi handle
		$mh = curl_multi_init();
		
		# loop through $data and create curl handles
		# then add them to the multi-handle
		foreach ($this->urls as $id => $d) 
		{
			$curly[$id] = curl_init();
			$curl_options = array();
			
			# if javascript is enabled, do the request through local gateway
			if ( !empty($this->javascript) && $this->javascript === true )
			{
				$d = $this->js_gate_url . urlencode($d);
			}
		  	
			# settings		 
			$curl_options = array( 
				CURLOPT_URL            => $d, 
				CURLOPT_FOLLOWLOCATION => $this->followlocation, 
				CURLOPT_RETURNTRANSFER => 1,
				CURLOPT_HEADER		   => false,   
				CURLOPT_ENCODING       => "",      
				CURLOPT_USERAGENT      => $this->useragent, 
				CURLOPT_AUTOREFERER    => true,     
				CURLOPT_CONNECTTIMEOUT => 120,     
				CURLOPT_TIMEOUT        => 10,      
				CURLOPT_MAXREDIRS      => 10,      
				CURLOPT_SSL_VERIFYHOST => false,
				CURLOPT_SSL_VERIFYPEER => false
			);  
			
			# proxy ?
			if ( !empty($this->proxy['ip']) )
			{
				$curl_options[CURLOPT_PROXY] 		= $this->proxy['ip'];
				$curl_options[CURLOPT_PROXYUSERPWD] = $this->proxy['auth'];
			}
			
			# tor ?
			$tor = strpos($d, ".onion");
			
			if ( $tor !== false )
			{
				$curl_options[CURLOPT_PROXY] 		= 'http://127.0.0.1:9050/';
       			$curl_options[CURLOPT_PROXYTYPE] 	= 7;
			}
			
			# disable header ? 
			if ( !empty($this->cookiefile) )
			{
				$curl_options[CURLOPT_COOKIEJAR]	= $this->cookiefile;
				$curl_options[CURLOPT_COOKIEFILE]	= $this->cookiefile;
			}
			
			curl_setopt_array($curly[$id], $curl_options);
			curl_multi_add_handle($mh, $curly[$id]);
		}
		
		#execute the handles 27.08.2012
		$active = null;
		do 
		{
			curl_multi_exec($mh, $active);
			curl_multi_select($mh);
		} while ( $active > 0 );
		
		foreach ($curly as $id => $c) 
		{
			$this->html[$id] 		= curl_multi_getcontent_utf8($c);
			$this->responses[$id] 	= curl_getinfo($c, CURLINFO_HTTP_CODE);
			$this->loadtimes[$id] 	= curl_getinfo($c, CURLINFO_TOTAL_TIME) - curl_getinfo($c, CURLINFO_NAMELOOKUP_TIME); # loading time (without name resolving)
			#$result[$id] = curl_multi_getcontent_utf8($c);
			#$httpcodes[$id] = curl_getinfo($c, CURLINFO_HTTP_CODE);
			#$loadtimes[$id] = curl_getinfo($c, CURLINFO_TOTAL_TIME) - curl_getinfo($c, CURLINFO_NAMELOOKUP_TIME); # loading name (without name resolving)
			
			# redirected?
			$this->redirections[$id] = false;
			#$redirections[$id] = false;
			$effective_url = curl_getinfo($c, CURLINFO_EFFECTIVE_URL);
			if ( $this->urls[$id] != $effective_url )
			{
				$this->redirections[$id] = $effective_url;
				#$redirections[$id] = $effective_url;
				
				# redirected permanently ?
				if ( $this->responses[$id] === 301 ) 
				{
					# try downloading the given address!
				}
			}
			
			# download again (failed pageload)?
			if ( $this->responses[$id] === 500 || $this->responses[$id] === 503 || $this->responses[$id] === 0 )
			{
				#$this->retrylist[$id] = $this->urls[$id];
				#echo "downloading again! " . $this->urls[$id] . " \n";	
				++$this->failed_pageloads;
			}
			else
			{
				# how bout 
				unset($this->urls[$id]);
			}
			
			curl_multi_remove_handle($mh, $c);
		}
	
		# all done
		curl_multi_close($mh);
		
		# try downloading failed pages again
		if ( $this->failed_pageloads > 0 && $this->retry_attempts > 0 ) 
		{
			--$this->retry_attempts;		# one less retry attempt left
			$this->failed_pageloads = 0; 	# reset counter
			usleep(500000);					# wait 0.5 seconds before attempting again
			$this->startRequest();			# run this function again
		}
		
		#$this->html = $result;
		#$this->responses = $httpcodes;
		#$this->redirections = $redirections;
		#$this->loadtimes = $loadtimes;
		return true;
	}
}

function HaeHTML($url, $utf8 = true, $proxy = false, $cookiefile = false, $useragent = "", $javascript = false, $retry_attempts = 1, $followlocation = true)
{
	$ch = curl_init();
	$js_gate_url	= "127.0.0.1/testing/jsgate.php?url=";
	$url_copy = $url;
	
	if ( !empty($javascript) )
	{
		$url = $js_gate_url . urlencode($url);
	}

	$options = array(
		CURLOPT_URL 		   => $url,				# url to download
        CURLOPT_RETURNTRANSFER => true,     		# return web page data
        CURLOPT_HEADER         => false,    			# return headers
        CURLOPT_FOLLOWLOCATION => $followlocation,  # follow redirects
        CURLOPT_ENCODING       => "",       		# handle all encodings
        CURLOPT_USERAGENT      => "",  # who am i
        CURLOPT_AUTOREFERER    => true,     		# set referer on redirect
        CURLOPT_CONNECTTIMEOUT => 120,      		# timeout on connect
        CURLOPT_TIMEOUT        => 10,      			# timeout on response
        CURLOPT_MAXREDIRS      => 10       			# stop after 10 redirects
    );
	
	# use cookies ?
	if ( !empty($cookiefile) )
	{
		$options[CURLOPT_USERAGENT] 	= $useragent;
		$options[CURLOPT_COOKIEJAR] 	= $cookiefile;
		$options[CURLOPT_COOKIEFILE] 	= $cookiefile;
	}
	
	# tor-address ?
	$tor = strpos($url, ".onion");
	 
	if ( $tor !== false )
	{
		# use local tor-server
		$options[CURLOPT_PROXY] 	= 'http://127.0.0.1:9050/';
        $options[CURLOPT_PROXYTYPE] = 7;
	}
	# proxy ? 
	else if ( !empty($proxy) )
	{
		$options[CURLOPT_PROXY] 		= $proxy[0];	# ip
		$options[CURLOPT_PROXYUSERPWD] 	= $proxy[1];	# authentication (username:password)
	}
	
	curl_setopt_array($ch, $options);
	
	if ( $utf8 ) 
	{
		$data = curl_exec_utf8($ch);
	}
	else
	{
		$data = curl_exec($ch);
	}
	
	$status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);		# get status code
	$effective_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);	# effective url
	curl_close($ch);											# close connection
	
	# try reloading page
	if ( $status_code === 500 || $status_code === 503 || $status_code === 0 ) 
	{
		if ( $retry_attempts > 0 )
		{
			--$retry_attempts;		#
			usleep(400000);			# wait 0.4 seconds
			$data = HaeHTML($url_copy, $utf8, $proxy, $cookiefile, $useragent, $javascript, $retry_attempts);
		}
	}
	
	return $data; 
}

function get_suffix_list()
{
	
return array (
  'ac' => 
  array (
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'net' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'org' => 
    array (
    ),
  ),
  'ad' => 
  array (
    'nom' => 
    array (
    ),
  ),
  'ae' => 
  array (
    'co' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'sch' => 
    array (
    ),
    'ac' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'blogspot' => 
    array (
    ),
  ),
  'aero' => 
  array (
    'accident-investigation' => 
    array (
    ),
    'accident-prevention' => 
    array (
    ),
    'aerobatic' => 
    array (
    ),
    'aeroclub' => 
    array (
    ),
    'aerodrome' => 
    array (
    ),
    'agents' => 
    array (
    ),
    'aircraft' => 
    array (
    ),
    'airline' => 
    array (
    ),
    'airport' => 
    array (
    ),
    'air-surveillance' => 
    array (
    ),
    'airtraffic' => 
    array (
    ),
    'air-traffic-control' => 
    array (
    ),
    'ambulance' => 
    array (
    ),
    'amusement' => 
    array (
    ),
    'association' => 
    array (
    ),
    'author' => 
    array (
    ),
    'ballooning' => 
    array (
    ),
    'broker' => 
    array (
    ),
    'caa' => 
    array (
    ),
    'cargo' => 
    array (
    ),
    'catering' => 
    array (
    ),
    'certification' => 
    array (
    ),
    'championship' => 
    array (
    ),
    'charter' => 
    array (
    ),
    'civilaviation' => 
    array (
    ),
    'club' => 
    array (
    ),
    'conference' => 
    array (
    ),
    'consultant' => 
    array (
    ),
    'consulting' => 
    array (
    ),
    'control' => 
    array (
    ),
    'council' => 
    array (
    ),
    'crew' => 
    array (
    ),
    'design' => 
    array (
    ),
    'dgca' => 
    array (
    ),
    'educator' => 
    array (
    ),
    'emergency' => 
    array (
    ),
    'engine' => 
    array (
    ),
    'engineer' => 
    array (
    ),
    'entertainment' => 
    array (
    ),
    'equipment' => 
    array (
    ),
    'exchange' => 
    array (
    ),
    'express' => 
    array (
    ),
    'federation' => 
    array (
    ),
    'flight' => 
    array (
    ),
    'freight' => 
    array (
    ),
    'fuel' => 
    array (
    ),
    'gliding' => 
    array (
    ),
    'government' => 
    array (
    ),
    'groundhandling' => 
    array (
    ),
    'group' => 
    array (
    ),
    'hanggliding' => 
    array (
    ),
    'homebuilt' => 
    array (
    ),
    'insurance' => 
    array (
    ),
    'journal' => 
    array (
    ),
    'journalist' => 
    array (
    ),
    'leasing' => 
    array (
    ),
    'logistics' => 
    array (
    ),
    'magazine' => 
    array (
    ),
    'maintenance' => 
    array (
    ),
    'marketplace' => 
    array (
    ),
    'media' => 
    array (
    ),
    'microlight' => 
    array (
    ),
    'modelling' => 
    array (
    ),
    'navigation' => 
    array (
    ),
    'parachuting' => 
    array (
    ),
    'paragliding' => 
    array (
    ),
    'passenger-association' => 
    array (
    ),
    'pilot' => 
    array (
    ),
    'press' => 
    array (
    ),
    'production' => 
    array (
    ),
    'recreation' => 
    array (
    ),
    'repbody' => 
    array (
    ),
    'res' => 
    array (
    ),
    'research' => 
    array (
    ),
    'rotorcraft' => 
    array (
    ),
    'safety' => 
    array (
    ),
    'scientist' => 
    array (
    ),
    'services' => 
    array (
    ),
    'show' => 
    array (
    ),
    'skydiving' => 
    array (
    ),
    'software' => 
    array (
    ),
    'student' => 
    array (
    ),
    'taxi' => 
    array (
    ),
    'trader' => 
    array (
    ),
    'trading' => 
    array (
    ),
    'trainer' => 
    array (
    ),
    'union' => 
    array (
    ),
    'workinggroup' => 
    array (
    ),
    'works' => 
    array (
    ),
  ),
  'af' => 
  array (
    'gov' => 
    array (
    ),
    'com' => 
    array (
    ),
    'org' => 
    array (
    ),
    'net' => 
    array (
    ),
    'edu' => 
    array (
    ),
  ),
  'ag' => 
  array (
    'com' => 
    array (
    ),
    'org' => 
    array (
    ),
    'net' => 
    array (
    ),
    'co' => 
    array (
    ),
    'nom' => 
    array (
    ),
  ),
  'ai' => 
  array (
    'off' => 
    array (
    ),
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
  ),
  'al' => 
  array (
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
  ),
  'am' => 
  array (
  ),
  'an' => 
  array (
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'edu' => 
    array (
    ),
  ),
  'ao' => 
  array (
    'ed' => 
    array (
    ),
    'gv' => 
    array (
    ),
    'og' => 
    array (
    ),
    'co' => 
    array (
    ),
    'pb' => 
    array (
    ),
    'it' => 
    array (
    ),
  ),
  'aq' => 
  array (
  ),
  'ar' => 
  array (
    'com' => 
    array (
      'blogspot' => 
      array (
      ),
    ),
    'edu' => 
    array (
    ),
    'gob' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'int' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'tur' => 
    array (
    ),
  ),
  'arpa' => 
  array (
    'e164' => 
    array (
    ),
    'in-addr' => 
    array (
    ),
    'ip6' => 
    array (
    ),
    'iris' => 
    array (
    ),
    'uri' => 
    array (
    ),
    'urn' => 
    array (
    ),
  ),
  'as' => 
  array (
    'gov' => 
    array (
    ),
  ),
  'asia' => 
  array (
  ),
  'at' => 
  array (
    'ac' => 
    array (
    ),
    'co' => 
    array (
      'blogspot' => 
      array (
      ),
    ),
    'gv' => 
    array (
    ),
    'or' => 
    array (
    ),
    'biz' => 
    array (
    ),
    'info' => 
    array (
    ),
    'priv' => 
    array (
    ),
  ),
  'au' => 
  array (
    'com' => 
    array (
      'blogspot' => 
      array (
      ),
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'edu' => 
    array (
      'act' => 
      array (
      ),
      'nsw' => 
      array (
      ),
      'nt' => 
      array (
      ),
      'qld' => 
      array (
      ),
      'sa' => 
      array (
      ),
      'tas' => 
      array (
      ),
      'vic' => 
      array (
      ),
      'wa' => 
      array (
      ),
    ),
    'gov' => 
    array (
      'qld' => 
      array (
      ),
      'sa' => 
      array (
      ),
      'tas' => 
      array (
      ),
      'vic' => 
      array (
      ),
      'wa' => 
      array (
      ),
    ),
    'asn' => 
    array (
    ),
    'id' => 
    array (
    ),
    'info' => 
    array (
    ),
    'conf' => 
    array (
    ),
    'oz' => 
    array (
    ),
    'act' => 
    array (
    ),
    'nsw' => 
    array (
    ),
    'nt' => 
    array (
    ),
    'qld' => 
    array (
    ),
    'sa' => 
    array (
    ),
    'tas' => 
    array (
    ),
    'vic' => 
    array (
    ),
    'wa' => 
    array (
    ),
  ),
  'aw' => 
  array (
    'com' => 
    array (
    ),
  ),
  'ax' => 
  array (
  ),
  'az' => 
  array (
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
    'int' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'org' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'info' => 
    array (
    ),
    'pp' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'name' => 
    array (
    ),
    'pro' => 
    array (
    ),
    'biz' => 
    array (
    ),
  ),
  'ba' => 
  array (
    'org' => 
    array (
    ),
    'net' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'unsa' => 
    array (
    ),
    'unbi' => 
    array (
    ),
    'co' => 
    array (
    ),
    'com' => 
    array (
    ),
    'rs' => 
    array (
    ),
  ),
  'bb' => 
  array (
    'biz' => 
    array (
    ),
    'co' => 
    array (
    ),
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'info' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'store' => 
    array (
    ),
    'tv' => 
    array (
    ),
  ),
  'bd' => 
  array (
    '*' => 
    array (
    ),
  ),
  'be' => 
  array (
    'ac' => 
    array (
    ),
    'blogspot' => 
    array (
    ),
  ),
  'bf' => 
  array (
    'gov' => 
    array (
    ),
  ),
  'bg' => 
  array (
    'a' => 
    array (
    ),
    'b' => 
    array (
    ),
    'c' => 
    array (
    ),
    'd' => 
    array (
    ),
    'e' => 
    array (
    ),
    'f' => 
    array (
    ),
    'g' => 
    array (
    ),
    'h' => 
    array (
    ),
    'i' => 
    array (
    ),
    'j' => 
    array (
    ),
    'k' => 
    array (
    ),
    'l' => 
    array (
    ),
    'm' => 
    array (
    ),
    'n' => 
    array (
    ),
    'o' => 
    array (
    ),
    'p' => 
    array (
    ),
    'q' => 
    array (
    ),
    'r' => 
    array (
    ),
    's' => 
    array (
    ),
    't' => 
    array (
    ),
    'u' => 
    array (
    ),
    'v' => 
    array (
    ),
    'w' => 
    array (
    ),
    'x' => 
    array (
    ),
    'y' => 
    array (
    ),
    'z' => 
    array (
    ),
    0 => 
    array (
    ),
    1 => 
    array (
    ),
    2 => 
    array (
    ),
    3 => 
    array (
    ),
    4 => 
    array (
    ),
    5 => 
    array (
    ),
    6 => 
    array (
    ),
    7 => 
    array (
    ),
    8 => 
    array (
    ),
    9 => 
    array (
    ),
  ),
  'bh' => 
  array (
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'gov' => 
    array (
    ),
  ),
  'bi' => 
  array (
    'co' => 
    array (
    ),
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'or' => 
    array (
    ),
    'org' => 
    array (
    ),
  ),
  'biz' => 
  array (
    'dyndns' => 
    array (
    ),
    'for-better' => 
    array (
    ),
    'for-more' => 
    array (
    ),
    'for-some' => 
    array (
    ),
    'for-the' => 
    array (
    ),
    'selfip' => 
    array (
    ),
    'webhop' => 
    array (
    ),
  ),
  'bj' => 
  array (
    'asso' => 
    array (
    ),
    'barreau' => 
    array (
    ),
    'gouv' => 
    array (
    ),
    'blogspot' => 
    array (
    ),
  ),
  'bm' => 
  array (
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
  ),
  'bn' => 
  array (
    '*' => 
    array (
    ),
  ),
  'bo' => 
  array (
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'gob' => 
    array (
    ),
    'int' => 
    array (
    ),
    'org' => 
    array (
    ),
    'net' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'tv' => 
    array (
    ),
  ),
  'br' => 
  array (
    'adm' => 
    array (
    ),
    'adv' => 
    array (
    ),
    'agr' => 
    array (
    ),
    'am' => 
    array (
    ),
    'arq' => 
    array (
    ),
    'art' => 
    array (
    ),
    'ato' => 
    array (
    ),
    'b' => 
    array (
    ),
    'bio' => 
    array (
    ),
    'blog' => 
    array (
    ),
    'bmd' => 
    array (
    ),
    'cim' => 
    array (
    ),
    'cng' => 
    array (
    ),
    'cnt' => 
    array (
    ),
    'com' => 
    array (
      'blogspot' => 
      array (
      ),
    ),
    'coop' => 
    array (
    ),
    'ecn' => 
    array (
    ),
    'eco' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'emp' => 
    array (
    ),
    'eng' => 
    array (
    ),
    'esp' => 
    array (
    ),
    'etc' => 
    array (
    ),
    'eti' => 
    array (
    ),
    'far' => 
    array (
    ),
    'flog' => 
    array (
    ),
    'fm' => 
    array (
    ),
    'fnd' => 
    array (
    ),
    'fot' => 
    array (
    ),
    'fst' => 
    array (
    ),
    'g12' => 
    array (
    ),
    'ggf' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'imb' => 
    array (
    ),
    'ind' => 
    array (
    ),
    'inf' => 
    array (
    ),
    'jor' => 
    array (
    ),
    'jus' => 
    array (
    ),
    'leg' => 
    array (
    ),
    'lel' => 
    array (
    ),
    'mat' => 
    array (
    ),
    'med' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'mp' => 
    array (
    ),
    'mus' => 
    array (
    ),
    'net' => 
    array (
    ),
    'nom' => 
    array (
      '*' => 
      array (
      ),
    ),
    'not' => 
    array (
    ),
    'ntr' => 
    array (
    ),
    'odo' => 
    array (
    ),
    'org' => 
    array (
    ),
    'ppg' => 
    array (
    ),
    'pro' => 
    array (
    ),
    'psc' => 
    array (
    ),
    'psi' => 
    array (
    ),
    'qsl' => 
    array (
    ),
    'radio' => 
    array (
    ),
    'rec' => 
    array (
    ),
    'slg' => 
    array (
    ),
    'srv' => 
    array (
    ),
    'taxi' => 
    array (
    ),
    'teo' => 
    array (
    ),
    'tmp' => 
    array (
    ),
    'trd' => 
    array (
    ),
    'tur' => 
    array (
    ),
    'tv' => 
    array (
    ),
    'vet' => 
    array (
    ),
    'vlog' => 
    array (
    ),
    'wiki' => 
    array (
    ),
    'zlg' => 
    array (
    ),
  ),
  'bs' => 
  array (
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
  ),
  'bt' => 
  array (
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
  ),
  'bv' => 
  array (
  ),
  'bw' => 
  array (
    'co' => 
    array (
    ),
    'org' => 
    array (
    ),
  ),
  'by' => 
  array (
    'gov' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'com' => 
    array (
    ),
    'of' => 
    array (
    ),
  ),
  'bz' => 
  array (
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'za' => 
    array (
    ),
  ),
  'ca' => 
  array (
    'ab' => 
    array (
    ),
    'bc' => 
    array (
    ),
    'mb' => 
    array (
    ),
    'nb' => 
    array (
    ),
    'nf' => 
    array (
    ),
    'nl' => 
    array (
    ),
    'ns' => 
    array (
    ),
    'nt' => 
    array (
    ),
    'nu' => 
    array (
    ),
    'on' => 
    array (
    ),
    'pe' => 
    array (
    ),
    'qc' => 
    array (
    ),
    'sk' => 
    array (
    ),
    'yk' => 
    array (
    ),
    'gc' => 
    array (
    ),
    'co' => 
    array (
    ),
    'blogspot' => 
    array (
    ),
  ),
  'cat' => 
  array (
  ),
  'cc' => 
  array (
    'ftpaccess' => 
    array (
    ),
    'game-server' => 
    array (
    ),
    'myphotos' => 
    array (
    ),
    'scrapping' => 
    array (
    ),
  ),
  'cd' => 
  array (
    'gov' => 
    array (
    ),
  ),
  'cf' => 
  array (
    'blogspot' => 
    array (
    ),
  ),
  'cg' => 
  array (
  ),
  'ch' => 
  array (
    'blogspot' => 
    array (
    ),
  ),
  'ci' => 
  array (
    'org' => 
    array (
    ),
    'or' => 
    array (
    ),
    'com' => 
    array (
    ),
    'co' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'ed' => 
    array (
    ),
    'ac' => 
    array (
    ),
    'net' => 
    array (
    ),
    'go' => 
    array (
    ),
    'asso' => 
    array (
    ),
    'xn--aroport-bya' => 
    array (
    ),
    'int' => 
    array (
    ),
    'presse' => 
    array (
    ),
    'md' => 
    array (
    ),
    'gouv' => 
    array (
    ),
  ),
  'ck' => 
  array (
    '*' => 
    array (
    ),
    'www' => 
    array (
      '!' => '',
    ),
  ),
  'cl' => 
  array (
    'gov' => 
    array (
    ),
    'gob' => 
    array (
    ),
    'co' => 
    array (
    ),
    'mil' => 
    array (
    ),
  ),
  'cm' => 
  array (
    'co' => 
    array (
    ),
    'com' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'net' => 
    array (
    ),
  ),
  'cn' => 
  array (
    'ac' => 
    array (
    ),
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'xn--55qx5d' => 
    array (
    ),
    'xn--io0a7i' => 
    array (
    ),
    'xn--od0alg' => 
    array (
    ),
    'ah' => 
    array (
    ),
    'bj' => 
    array (
    ),
    'cq' => 
    array (
    ),
    'fj' => 
    array (
    ),
    'gd' => 
    array (
    ),
    'gs' => 
    array (
    ),
    'gz' => 
    array (
    ),
    'gx' => 
    array (
    ),
    'ha' => 
    array (
    ),
    'hb' => 
    array (
    ),
    'he' => 
    array (
    ),
    'hi' => 
    array (
    ),
    'hl' => 
    array (
    ),
    'hn' => 
    array (
    ),
    'jl' => 
    array (
    ),
    'js' => 
    array (
    ),
    'jx' => 
    array (
    ),
    'ln' => 
    array (
    ),
    'nm' => 
    array (
    ),
    'nx' => 
    array (
    ),
    'qh' => 
    array (
    ),
    'sc' => 
    array (
    ),
    'sd' => 
    array (
    ),
    'sh' => 
    array (
    ),
    'sn' => 
    array (
    ),
    'sx' => 
    array (
    ),
    'tj' => 
    array (
    ),
    'xj' => 
    array (
    ),
    'xz' => 
    array (
    ),
    'yn' => 
    array (
    ),
    'zj' => 
    array (
    ),
    'hk' => 
    array (
    ),
    'mo' => 
    array (
    ),
    'tw' => 
    array (
    ),
    'amazonaws' => 
    array (
      'compute' => 
      array (
        'cn-north-1' => 
        array (
        ),
      ),
    ),
  ),
  'co' => 
  array (
    'arts' => 
    array (
    ),
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'firm' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'info' => 
    array (
    ),
    'int' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'net' => 
    array (
    ),
    'nom' => 
    array (
    ),
    'org' => 
    array (
    ),
    'rec' => 
    array (
    ),
    'web' => 
    array (
    ),
  ),
  'com' => 
  array (
    'amazonaws' => 
    array (
      'compute' => 
      array (
        'ap-northeast-1' => 
        array (

        ),
        'ap-southeast-1' => 
        array (
        ),
        'ap-southeast-2' => 
        array (
        ),
        'eu-west-1' => 
        array (
        ),
        'eu-central-1' => 
        array (
        ),
        'sa-east-1' => 
        array (
        ),
        'us-gov-west-1' => 
        array (
        ),
        'us-west-1' => 
        array (
        ),
        'us-west-2' => 
        array (
        ),
      ),
      'compute-1' => 
      array (
        'z-1' => 
        array (
        ),
        'z-2' => 
        array (
        ),
      ),
      'us-east-1' => 
      array (
      ),
      'elb' => 
      array (
      ),
      's3' => 
      array (
      ),
      's3-us-west-2' => 
      array (
      ),
      's3-us-west-1' => 
      array (
      ),
      's3-eu-west-1' => 
      array (
      ),
      's3-ap-southeast-1' => 
      array (
      ),
      's3-ap-southeast-2' => 
      array (
      ),
      's3-ap-northeast-1' => 
      array (
      ),
      's3-sa-east-1' => 
      array (
      ),
      's3-us-gov-west-1' => 
      array (
      ),
      's3-fips-us-gov-west-1' => 
      array (
      ),
      's3-website-us-east-1' => 
      array (
      ),
      's3-website-us-west-2' => 
      array (
      ),
      's3-website-us-west-1' => 
      array (
      ),
      's3-website-eu-west-1' => 
      array (
      ),
      's3-website-ap-southeast-1' => 
      array (
      ),
      's3-website-ap-southeast-2' => 
      array (
      ),
      's3-website-ap-northeast-1' => 
      array (
      ),
      's3-website-sa-east-1' => 
      array (
      ),
      's3-website-us-gov-west-1' => 
      array (
      ),
    ),
    'elasticbeanstalk' => 
    array (
    ),
    'betainabox' => 
    array (
    ),
    'ar' => 
    array (
    ),
    'br' => 
    array (
    ),
    'cn' => 
    array (
    ),
    'de' => 
    array (
    ),
    'eu' => 
    array (
    ),
    'gb' => 
    array (
    ),
    'hu' => 
    array (
    ),
    'jpn' => 
    array (
    ),
    'kr' => 
    array (
    ),
    'mex' => 
    array (
    ),
    'no' => 
    array (
    ),
    'qc' => 
    array (
    ),
    'ru' => 
    array (
    ),
    'sa' => 
    array (
    ),
    'se' => 
    array (
    ),
    'uk' => 
    array (
    ),
    'us' => 
    array (
    ),
    'uy' => 
    array (
    ),
    'za' => 
    array (
    ),
    'africa' => 
    array (
    ),
    'gr' => 
    array (
    ),
    'co' => 
    array (
    ),
    'cloudcontrolled' => 
    array (
    ),
    'cloudcontrolapp' => 
    array (
    ),
    'dreamhosters' => 
    array (
    ),
    'dyndns-at-home' => 
    array (
    ),
    'dyndns-at-work' => 
    array (
    ),
    'dyndns-blog' => 
    array (
    ),
    'dyndns-free' => 
    array (
    ),
    'dyndns-home' => 
    array (
    ),
    'dyndns-ip' => 
    array (
    ),
    'dyndns-mail' => 
    array (
    ),
    'dyndns-office' => 
    array (
    ),
    'dyndns-pics' => 
    array (
    ),
    'dyndns-remote' => 
    array (
    ),
    'dyndns-server' => 
    array (
    ),
    'dyndns-web' => 
    array (
    ),
    'dyndns-wiki' => 
    array (
    ),
    'dyndns-work' => 
    array (
    ),
    'blogdns' => 
    array (
    ),
    'cechire' => 
    array (
    ),
    'dnsalias' => 
    array (
    ),
    'dnsdojo' => 
    array (
    ),
    'doesntexist' => 
    array (
    ),
    'dontexist' => 
    array (
    ),
    'doomdns' => 
    array (
    ),
    'dyn-o-saur' => 
    array (
    ),
    'dynalias' => 
    array (
    ),
    'est-a-la-maison' => 
    array (
    ),
    'est-a-la-masion' => 
    array (
    ),
    'est-le-patron' => 
    array (
    ),
    'est-mon-blogueur' => 
    array (
    ),
    'from-ak' => 
    array (
    ),
    'from-al' => 
    array (
    ),
    'from-ar' => 
    array (
    ),
    'from-ca' => 
    array (
    ),
    'from-ct' => 
    array (
    ),
    'from-dc' => 
    array (
    ),
    'from-de' => 
    array (
    ),
    'from-fl' => 
    array (
    ),
    'from-ga' => 
    array (
    ),
    'from-hi' => 
    array (
    ),
    'from-ia' => 
    array (
    ),
    'from-id' => 
    array (
    ),
    'from-il' => 
    array (
    ),
    'from-in' => 
    array (
    ),
    'from-ks' => 
    array (
    ),
    'from-ky' => 
    array (
    ),
    'from-ma' => 
    array (
    ),
    'from-md' => 
    array (
    ),
    'from-mi' => 
    array (
    ),
    'from-mn' => 
    array (
    ),
    'from-mo' => 
    array (
    ),
    'from-ms' => 
    array (
    ),
    'from-mt' => 
    array (
    ),
    'from-nc' => 
    array (
    ),
    'from-nd' => 
    array (
    ),
    'from-ne' => 
    array (
    ),
    'from-nh' => 
    array (
    ),
    'from-nj' => 
    array (
    ),
    'from-nm' => 
    array (
    ),
    'from-nv' => 
    array (
    ),
    'from-oh' => 
    array (
    ),
    'from-ok' => 
    array (
    ),
    'from-or' => 
    array (
    ),
    'from-pa' => 
    array (
    ),
    'from-pr' => 
    array (
    ),
    'from-ri' => 
    array (
    ),
    'from-sc' => 
    array (
    ),
    'from-sd' => 
    array (
    ),
    'from-tn' => 
    array (
    ),
    'from-tx' => 
    array (
    ),
    'from-ut' => 
    array (
    ),
    'from-va' => 
    array (
    ),
    'from-vt' => 
    array (
    ),
    'from-wa' => 
    array (
    ),
    'from-wi' => 
    array (
    ),
    'from-wv' => 
    array (
    ),
    'from-wy' => 
    array (
    ),
    'getmyip' => 
    array (
    ),
    'gotdns' => 
    array (
    ),
    'hobby-site' => 
    array (
    ),
    'homelinux' => 
    array (
    ),
    'homeunix' => 
    array (
    ),
    'iamallama' => 
    array (
    ),
    'is-a-anarchist' => 
    array (
    ),
    'is-a-blogger' => 
    array (
    ),
    'is-a-bookkeeper' => 
    array (
    ),
    'is-a-bulls-fan' => 
    array (
    ),
    'is-a-caterer' => 
    array (
    ),
    'is-a-chef' => 
    array (
    ),
    'is-a-conservative' => 
    array (
    ),
    'is-a-cpa' => 
    array (
    ),
    'is-a-cubicle-slave' => 
    array (
    ),
    'is-a-democrat' => 
    array (
    ),
    'is-a-designer' => 
    array (
    ),
    'is-a-doctor' => 
    array (
    ),
    'is-a-financialadvisor' => 
    array (
    ),
    'is-a-geek' => 
    array (
    ),
    'is-a-green' => 
    array (
    ),
    'is-a-guru' => 
    array (
    ),
    'is-a-hard-worker' => 
    array (
    ),
    'is-a-hunter' => 
    array (
    ),
    'is-a-landscaper' => 
    array (
    ),
    'is-a-lawyer' => 
    array (
    ),
    'is-a-liberal' => 
    array (
    ),
    'is-a-libertarian' => 
    array (
    ),
    'is-a-llama' => 
    array (
    ),
    'is-a-musician' => 
    array (
    ),
    'is-a-nascarfan' => 
    array (
    ),
    'is-a-nurse' => 
    array (
    ),
    'is-a-painter' => 
    array (
    ),
    'is-a-personaltrainer' => 
    array (
    ),
    'is-a-photographer' => 
    array (
    ),
    'is-a-player' => 
    array (
    ),
    'is-a-republican' => 
    array (
    ),
    'is-a-rockstar' => 
    array (
    ),
    'is-a-socialist' => 
    array (
    ),
    'is-a-student' => 
    array (
    ),
    'is-a-teacher' => 
    array (
    ),
    'is-a-techie' => 
    array (
    ),
    'is-a-therapist' => 
    array (
    ),
    'is-an-accountant' => 
    array (
    ),
    'is-an-actor' => 
    array (
    ),
    'is-an-actress' => 
    array (
    ),
    'is-an-anarchist' => 
    array (
    ),
    'is-an-artist' => 
    array (
    ),
    'is-an-engineer' => 
    array (
    ),
    'is-an-entertainer' => 
    array (
    ),
    'is-certified' => 
    array (
    ),
    'is-gone' => 
    array (
    ),
    'is-into-anime' => 
    array (
    ),
    'is-into-cars' => 
    array (
    ),
    'is-into-cartoons' => 
    array (
    ),
    'is-into-games' => 
    array (
    ),
    'is-leet' => 
    array (
    ),
    'is-not-certified' => 
    array (
    ),
    'is-slick' => 
    array (
    ),
    'is-uberleet' => 
    array (
    ),
    'is-with-theband' => 
    array (
    ),
    'isa-geek' => 
    array (
    ),
    'isa-hockeynut' => 
    array (
    ),
    'issmarterthanyou' => 
    array (
    ),
    'likes-pie' => 
    array (
    ),
    'likescandy' => 
    array (
    ),
    'neat-url' => 
    array (
    ),
    'saves-the-whales' => 
    array (
    ),
    'selfip' => 
    array (
    ),
    'sells-for-less' => 
    array (
    ),
    'sells-for-u' => 
    array (
    ),
    'servebbs' => 
    array (
    ),
    'simple-url' => 
    array (
    ),
    'space-to-rent' => 
    array (
    ),
    'teaches-yoga' => 
    array (
    ),
    'writesthisblog' => 
    array (
    ),
    'firebaseapp' => 
    array (
    ),
    'flynnhub' => 
    array (
    ),
    'githubusercontent' => 
    array (
    ),
    'ro' => 
    array (
    ),
    'appspot' => 
    array (
    ),
    'blogspot' => 
    array (
    ),
    'codespot' => 
    array (
    ),
    'googleapis' => 
    array (
    ),
    'googlecode' => 
    array (
    ),
    'pagespeedmobilizer' => 
    array (
    ),
    'withgoogle' => 
    array (
    ),
    'herokuapp' => 
    array (
    ),
    'herokussl' => 
    array (
    ),
    'nfshost' => 
    array (
    ),
    'operaunite' => 
    array (
    ),
    'outsystemscloud' => 
    array (
    ),
    'rhcloud' => 
    array (
    ),
    'sinaapp' => 
    array (
    ),
    'vipsinaapp' => 
    array (
    ),
    '1kapp' => 
    array (
    ),
    'hk' => 
    array (
    ),
    'yolasite' => 
    array (
    ),
  ),
  'coop' => 
  array (
  ),
  'cr' => 
  array (
    'ac' => 
    array (
    ),
    'co' => 
    array (
    ),
    'ed' => 
    array (
    ),
    'fi' => 
    array (
    ),
    'go' => 
    array (
    ),
    'or' => 
    array (
    ),
    'sa' => 
    array (
    ),
  ),
  'cu' => 
  array (
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'org' => 
    array (
    ),
    'net' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'inf' => 
    array (
    ),
  ),
  'cv' => 
  array (
    'blogspot' => 
    array (
    ),
  ),
  'cw' => 
  array (
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
  ),
  'cx' => 
  array (
    'gov' => 
    array (
    ),
    'ath' => 
    array (
    ),
  ),
  'cy' => 
  array (
    '*' => 
    array (
    ),
  ),
  'cz' => 
  array (
    'blogspot' => 
    array (
    ),
  ),
  'de' => 
  array (
    'com' => 
    array (
    ),
    'fuettertdasnetz' => 
    array (
    ),
    'isteingeek' => 
    array (
    ),
    'istmein' => 
    array (
    ),
    'lebtimnetz' => 
    array (
    ),
    'leitungsen' => 
    array (
    ),
    'traeumtgerade' => 
    array (
    ),
    'blogspot' => 
    array (
    ),
  ),
  'dj' => 
  array (
  ),
  'dk' => 
  array (
    'blogspot' => 
    array (
    ),
  ),
  'dm' => 
  array (
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
  ),
  'do' => 
  array (
    'art' => 
    array (
    ),
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gob' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'sld' => 
    array (
    ),
    'web' => 
    array (
    ),
  ),
  'dz' => 
  array (
    'com' => 
    array (
    ),
    'org' => 
    array (
    ),
    'net' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'asso' => 
    array (
    ),
    'pol' => 
    array (
    ),
    'art' => 
    array (
    ),
  ),
  'ec' => 
  array (
    'com' => 
    array (
    ),
    'info' => 
    array (
    ),
    'net' => 
    array (
    ),
    'fin' => 
    array (
    ),
    'k12' => 
    array (
    ),
    'med' => 
    array (
    ),
    'pro' => 
    array (
    ),
    'org' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'gob' => 
    array (
    ),
    'mil' => 
    array (
    ),
  ),
  'edu' => 
  array (
  ),
  'ee' => 
  array (
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'riik' => 
    array (
    ),
    'lib' => 
    array (
    ),
    'med' => 
    array (
    ),
    'com' => 
    array (
    ),
    'pri' => 
    array (
    ),
    'aip' => 
    array (
    ),
    'org' => 
    array (
    ),
    'fie' => 
    array (
    ),
  ),
  'eg' => 
  array (
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'eun' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'name' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'sci' => 
    array (
    ),
  ),
  'er' => 
  array (
    '*' => 
    array (
    ),
  ),
  'es' => 
  array (
    'com' => 
    array (
      'blogspot' => 
      array (
      ),
    ),
    'nom' => 
    array (
    ),
    'org' => 
    array (
    ),
    'gob' => 
    array (
    ),
    'edu' => 
    array (
    ),
  ),
  'et' => 
  array (
    'com' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'org' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'biz' => 
    array (
    ),
    'name' => 
    array (
    ),
    'info' => 
    array (
    ),
  ),
  'eu' => 
  array (
  ),
  'fi' => 
  array (
    'aland' => 
    array (
    ),
    'blogspot' => 
    array (
    ),
    'iki' => 
    array (
    ),
  ),
  'fj' => 
  array (
    '*' => 
    array (
    ),
  ),
  'fk' => 
  array (
    '*' => 
    array (
    ),
  ),
  'fm' => 
  array (
  ),
  'fo' => 
  array (
  ),
  'fr' => 
  array (
    'com' => 
    array (
    ),
    'asso' => 
    array (
    ),
    'nom' => 
    array (
    ),
    'prd' => 
    array (
    ),
    'presse' => 
    array (
    ),
    'tm' => 
    array (
    ),
    'aeroport' => 
    array (
    ),
    'assedic' => 
    array (
    ),
    'avocat' => 
    array (
    ),
    'avoues' => 
    array (
    ),
    'cci' => 
    array (
    ),
    'chambagri' => 
    array (
    ),
    'chirurgiens-dentistes' => 
    array (
    ),
    'experts-comptables' => 
    array (
    ),
    'geometre-expert' => 
    array (
    ),
    'gouv' => 
    array (
    ),
    'greta' => 
    array (
    ),
    'huissier-justice' => 
    array (
    ),
    'medecin' => 
    array (
    ),
    'notaires' => 
    array (
    ),
    'pharmacien' => 
    array (
    ),
    'port' => 
    array (
    ),
    'veterinaire' => 
    array (
    ),
    'blogspot' => 
    array (
    ),
  ),
  'ga' => 
  array (
  ),
  'gb' => 
  array (
  ),
  'gd' => 
  array (
  ),
  'ge' => 
  array (
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'org' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'net' => 
    array (
    ),
    'pvt' => 
    array (
    ),
  ),
  'gf' => 
  array (
  ),
  'gg' => 
  array (
    'co' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
  ),
  'gh' => 
  array (
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'org' => 
    array (
    ),
    'mil' => 
    array (
    ),
  ),
  'gi' => 
  array (
    'com' => 
    array (
    ),
    'ltd' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'mod' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'org' => 
    array (
    ),
  ),
  'gl' => 
  array (
  ),
  'gm' => 
  array (
  ),
  'gn' => 
  array (
    'ac' => 
    array (
    ),
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'org' => 
    array (
    ),
    'net' => 
    array (
    ),
  ),
  'gov' => 
  array (
  ),
  'gp' => 
  array (
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
    'mobi' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'org' => 
    array (
    ),
    'asso' => 
    array (
    ),
  ),
  'gq' => 
  array (
  ),
  'gr' => 
  array (
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'blogspot' => 
    array (
    ),
  ),
  'gs' => 
  array (
  ),
  'gt' => 
  array (
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gob' => 
    array (
    ),
    'ind' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
  ),
  'gu' => 
  array (
    '*' => 
    array (
    ),
  ),
  'gw' => 
  array (
  ),
  'gy' => 
  array (
    'co' => 
    array (
    ),
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
  ),
  'hk' => 
  array (
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'idv' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'xn--55qx5d' => 
    array (
    ),
    'xn--wcvs22d' => 
    array (
    ),
    'xn--lcvr32d' => 
    array (
    ),
    'xn--mxtq1m' => 
    array (
    ),
    'xn--gmqw5a' => 
    array (
    ),
    'xn--ciqpn' => 
    array (
    ),
    'xn--gmq050i' => 
    array (
    ),
    'xn--zf0avx' => 
    array (
    ),
    'xn--io0a7i' => 
    array (
    ),
    'xn--mk0axi' => 
    array (
    ),
    'xn--od0alg' => 
    array (
    ),
    'xn--od0aq3b' => 
    array (
    ),
    'xn--tn0ag' => 
    array (
    ),
    'xn--uc0atv' => 
    array (
    ),
    'xn--uc0ay4a' => 
    array (
    ),
    'blogspot' => 
    array (
    ),
    'ltd' => 
    array (
    ),
    'inc' => 
    array (
    ),
  ),
  'hm' => 
  array (
  ),
  'hn' => 
  array (
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'org' => 
    array (
    ),
    'net' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'gob' => 
    array (
    ),
  ),
  'hr' => 
  array (
    'iz' => 
    array (
    ),
    'from' => 
    array (
    ),
    'name' => 
    array (
    ),
    'com' => 
    array (
    ),
  ),
  'ht' => 
  array (
    'com' => 
    array (
    ),
    'shop' => 
    array (
    ),
    'firm' => 
    array (
    ),
    'info' => 
    array (
    ),
    'adult' => 
    array (
    ),
    'net' => 
    array (
    ),
    'pro' => 
    array (
    ),
    'org' => 
    array (
    ),
    'med' => 
    array (
    ),
    'art' => 
    array (
    ),
    'coop' => 
    array (
    ),
    'pol' => 
    array (
    ),
    'asso' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'rel' => 
    array (
    ),
    'gouv' => 
    array (
    ),
    'perso' => 
    array (
    ),
  ),
  'hu' => 
  array (
    'co' => 
    array (
    ),
    'info' => 
    array (
    ),
    'org' => 
    array (
    ),
    'priv' => 
    array (
    ),
    'sport' => 
    array (
    ),
    'tm' => 
    array (
    ),
    2000 => 
    array (
    ),
    'agrar' => 
    array (
    ),
    'bolt' => 
    array (
    ),
    'casino' => 
    array (
    ),
    'city' => 
    array (
    ),
    'erotica' => 
    array (
    ),
    'erotika' => 
    array (
    ),
    'film' => 
    array (
    ),
    'forum' => 
    array (
    ),
    'games' => 
    array (
    ),
    'hotel' => 
    array (
    ),
    'ingatlan' => 
    array (
    ),
    'jogasz' => 
    array (
    ),
    'konyvelo' => 
    array (
    ),
    'lakas' => 
    array (
    ),
    'media' => 
    array (
    ),
    'news' => 
    array (
    ),
    'reklam' => 
    array (
    ),
    'sex' => 
    array (
    ),
    'shop' => 
    array (
    ),
    'suli' => 
    array (
    ),
    'szex' => 
    array (
    ),
    'tozsde' => 
    array (
    ),
    'utazas' => 
    array (
    ),
    'video' => 
    array (
    ),
    'blogspot' => 
    array (
    ),
  ),
  'id' => 
  array (
    'ac' => 
    array (
    ),
    'biz' => 
    array (
    ),
    'co' => 
    array (
    ),
    'desa' => 
    array (
    ),
    'go' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'my' => 
    array (
    ),
    'net' => 
    array (
    ),
    'or' => 
    array (
    ),
    'sch' => 
    array (
    ),
    'web' => 
    array (
    ),
  ),
  'ie' => 
  array (
    'gov' => 
    array (
    ),
    'blogspot' => 
    array (
    ),
  ),
  'il' => 
  array (
    '*' => 
    array (
    ),
    'co' => 
    array (
      'blogspot' => 
      array (
      ),
    ),
  ),
  'im' => 
  array (
    'ac' => 
    array (
    ),
    'co' => 
    array (
      'ltd' => 
      array (
      ),
      'plc' => 
      array (
      ),
    ),
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'tt' => 
    array (
    ),
    'tv' => 
    array (
    ),
  ),
  'in' => 
  array (
    'co' => 
    array (
    ),
    'firm' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'gen' => 
    array (
    ),
    'ind' => 
    array (
    ),
    'nic' => 
    array (
    ),
    'ac' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'res' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'blogspot' => 
    array (
    ),
  ),
  'info' => 
  array (
    'dyndns' => 
    array (
    ),
    'barrel-of-knowledge' => 
    array (
    ),
    'barrell-of-knowledge' => 
    array (
    ),
    'for-our' => 
    array (
    ),
    'groks-the' => 
    array (
    ),
    'groks-this' => 
    array (
    ),
    'here-for-more' => 
    array (
    ),
    'knowsitall' => 
    array (
    ),
    'selfip' => 
    array (
    ),
    'webhop' => 
    array (
    ),
  ),
  'int' => 
  array (
    'eu' => 
    array (
    ),
  ),
  'io' => 
  array (
    'com' => 
    array (
    ),
    'github' => 
    array (
    ),
    'nid' => 
    array (
    ),
  ),
  'iq' => 
  array (
    'gov' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'com' => 
    array (
    ),
    'org' => 
    array (
    ),
    'net' => 
    array (
    ),
  ),
  'ir' => 
  array (
    'ac' => 
    array (
    ),
    'co' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'id' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'sch' => 
    array (
    ),
    'xn--mgba3a4f16a' => 
    array (
    ),
    'xn--mgba3a4fra' => 
    array (
    ),
  ),
  'is' => 
  array (
    'net' => 
    array (
    ),
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'org' => 
    array (
    ),
    'int' => 
    array (
    ),
    'cupcake' => 
    array (
    ),
  ),
  'it' => 
  array (
    'gov' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'abr' => 
    array (
    ),
    'abruzzo' => 
    array (
    ),
    'aosta-valley' => 
    array (
    ),
    'aostavalley' => 
    array (
    ),
    'bas' => 
    array (
    ),
    'basilicata' => 
    array (
    ),
    'cal' => 
    array (
    ),
    'calabria' => 
    array (
    ),
    'cam' => 
    array (
    ),
    'campania' => 
    array (
    ),
    'emilia-romagna' => 
    array (
    ),
    'emiliaromagna' => 
    array (
    ),
    'emr' => 
    array (
    ),
    'friuli-v-giulia' => 
    array (
    ),
    'friuli-ve-giulia' => 
    array (
    ),
    'friuli-vegiulia' => 
    array (
    ),
    'friuli-venezia-giulia' => 
    array (
    ),
    'friuli-veneziagiulia' => 
    array (
    ),
    'friuli-vgiulia' => 
    array (
    ),
    'friuliv-giulia' => 
    array (
    ),
    'friulive-giulia' => 
    array (
    ),
    'friulivegiulia' => 
    array (
    ),
    'friulivenezia-giulia' => 
    array (
    ),
    'friuliveneziagiulia' => 
    array (
    ),
    'friulivgiulia' => 
    array (
    ),
    'fvg' => 
    array (
    ),
    'laz' => 
    array (
    ),
    'lazio' => 
    array (
    ),
    'lig' => 
    array (
    ),
    'liguria' => 
    array (
    ),
    'lom' => 
    array (
    ),
    'lombardia' => 
    array (
    ),
    'lombardy' => 
    array (
    ),
    'lucania' => 
    array (
    ),
    'mar' => 
    array (
    ),
    'marche' => 
    array (
    ),
    'mol' => 
    array (
    ),
    'molise' => 
    array (
    ),
    'piedmont' => 
    array (
    ),
    'piemonte' => 
    array (
    ),
    'pmn' => 
    array (
    ),
    'pug' => 
    array (
    ),
    'puglia' => 
    array (
    ),
    'sar' => 
    array (
    ),
    'sardegna' => 
    array (
    ),
    'sardinia' => 
    array (
    ),
    'sic' => 
    array (
    ),
    'sicilia' => 
    array (
    ),
    'sicily' => 
    array (
    ),
    'taa' => 
    array (
    ),
    'tos' => 
    array (
    ),
    'toscana' => 
    array (
    ),
    'trentino-a-adige' => 
    array (
    ),
    'trentino-aadige' => 
    array (
    ),
    'trentino-alto-adige' => 
    array (
    ),
    'trentino-altoadige' => 
    array (
    ),
    'trentino-s-tirol' => 
    array (
    ),
    'trentino-stirol' => 
    array (
    ),
    'trentino-sud-tirol' => 
    array (
    ),
    'trentino-sudtirol' => 
    array (
    ),
    'trentino-sued-tirol' => 
    array (
    ),
    'trentino-suedtirol' => 
    array (
    ),
    'trentinoa-adige' => 
    array (
    ),
    'trentinoaadige' => 
    array (
    ),
    'trentinoalto-adige' => 
    array (
    ),
    'trentinoaltoadige' => 
    array (
    ),
    'trentinos-tirol' => 
    array (
    ),
    'trentinostirol' => 
    array (
    ),
    'trentinosud-tirol' => 
    array (
    ),
    'trentinosudtirol' => 
    array (
    ),
    'trentinosued-tirol' => 
    array (
    ),
    'trentinosuedtirol' => 
    array (
    ),
    'tuscany' => 
    array (
    ),
    'umb' => 
    array (
    ),
    'umbria' => 
    array (
    ),
    'val-d-aosta' => 
    array (
    ),
    'val-daosta' => 
    array (
    ),
    'vald-aosta' => 
    array (
    ),
    'valdaosta' => 
    array (
    ),
    'valle-aosta' => 
    array (
    ),
    'valle-d-aosta' => 
    array (
    ),
    'valle-daosta' => 
    array (
    ),
    'valleaosta' => 
    array (
    ),
    'valled-aosta' => 
    array (
    ),
    'valledaosta' => 
    array (
    ),
    'vallee-aoste' => 
    array (
    ),
    'valleeaoste' => 
    array (
    ),
    'vao' => 
    array (
    ),
    'vda' => 
    array (
    ),
    'ven' => 
    array (
    ),
    'veneto' => 
    array (
    ),
    'ag' => 
    array (
    ),
    'agrigento' => 
    array (
    ),
    'al' => 
    array (
    ),
    'alessandria' => 
    array (
    ),
    'alto-adige' => 
    array (
    ),
    'altoadige' => 
    array (
    ),
    'an' => 
    array (
    ),
    'ancona' => 
    array (
    ),
    'andria-barletta-trani' => 
    array (
    ),
    'andria-trani-barletta' => 
    array (
    ),
    'andriabarlettatrani' => 
    array (
    ),
    'andriatranibarletta' => 
    array (
    ),
    'ao' => 
    array (
    ),
    'aosta' => 
    array (
    ),
    'aoste' => 
    array (
    ),
    'ap' => 
    array (
    ),
    'aq' => 
    array (
    ),
    'aquila' => 
    array (
    ),
    'ar' => 
    array (
    ),
    'arezzo' => 
    array (
    ),
    'ascoli-piceno' => 
    array (
    ),
    'ascolipiceno' => 
    array (
    ),
    'asti' => 
    array (
    ),
    'at' => 
    array (
    ),
    'av' => 
    array (
    ),
    'avellino' => 
    array (
    ),
    'ba' => 
    array (
    ),
    'balsan' => 
    array (
    ),
    'bari' => 
    array (
    ),
    'barletta-trani-andria' => 
    array (
    ),
    'barlettatraniandria' => 
    array (
    ),
    'belluno' => 
    array (
    ),
    'benevento' => 
    array (
    ),
    'bergamo' => 
    array (
    ),
    'bg' => 
    array (
    ),
    'bi' => 
    array (
    ),
    'biella' => 
    array (
    ),
    'bl' => 
    array (
    ),
    'bn' => 
    array (
    ),
    'bo' => 
    array (
    ),
    'bologna' => 
    array (
    ),
    'bolzano' => 
    array (
    ),
    'bozen' => 
    array (
    ),
    'br' => 
    array (
    ),
    'brescia' => 
    array (
    ),
    'brindisi' => 
    array (
    ),
    'bs' => 
    array (
    ),
    'bt' => 
    array (
    ),
    'bz' => 
    array (
    ),
    'ca' => 
    array (
    ),
    'cagliari' => 
    array (
    ),
    'caltanissetta' => 
    array (
    ),
    'campidano-medio' => 
    array (
    ),
    'campidanomedio' => 
    array (
    ),
    'campobasso' => 
    array (
    ),
    'carbonia-iglesias' => 
    array (
    ),
    'carboniaiglesias' => 
    array (
    ),
    'carrara-massa' => 
    array (
    ),
    'carraramassa' => 
    array (
    ),
    'caserta' => 
    array (
    ),
    'catania' => 
    array (
    ),
    'catanzaro' => 
    array (
    ),
    'cb' => 
    array (
    ),
    'ce' => 
    array (
    ),
    'cesena-forli' => 
    array (
    ),
    'cesenaforli' => 
    array (
    ),
    'ch' => 
    array (
    ),
    'chieti' => 
    array (
    ),
    'ci' => 
    array (
    ),
    'cl' => 
    array (
    ),
    'cn' => 
    array (
    ),
    'co' => 
    array (
    ),
    'como' => 
    array (
    ),
    'cosenza' => 
    array (
    ),
    'cr' => 
    array (
    ),
    'cremona' => 
    array (
    ),
    'crotone' => 
    array (
    ),
    'cs' => 
    array (
    ),
    'ct' => 
    array (
    ),
    'cuneo' => 
    array (
    ),
    'cz' => 
    array (
    ),
    'dell-ogliastra' => 
    array (
    ),
    'dellogliastra' => 
    array (
    ),
    'en' => 
    array (
    ),
    'enna' => 
    array (
    ),
    'fc' => 
    array (
    ),
    'fe' => 
    array (
    ),
    'fermo' => 
    array (
    ),
    'ferrara' => 
    array (
    ),
    'fg' => 
    array (
    ),
    'fi' => 
    array (
    ),
    'firenze' => 
    array (
    ),
    'florence' => 
    array (
    ),
    'fm' => 
    array (
    ),
    'foggia' => 
    array (
    ),
    'forli-cesena' => 
    array (
    ),
    'forlicesena' => 
    array (
    ),
    'fr' => 
    array (
    ),
    'frosinone' => 
    array (
    ),
    'ge' => 
    array (
    ),
    'genoa' => 
    array (
    ),
    'genova' => 
    array (
    ),
    'go' => 
    array (
    ),
    'gorizia' => 
    array (
    ),
    'gr' => 
    array (
    ),
    'grosseto' => 
    array (
    ),
    'iglesias-carbonia' => 
    array (
    ),
    'iglesiascarbonia' => 
    array (
    ),
    'im' => 
    array (
    ),
    'imperia' => 
    array (
    ),
    'is' => 
    array (
    ),
    'isernia' => 
    array (
    ),
    'kr' => 
    array (
    ),
    'la-spezia' => 
    array (
    ),
    'laquila' => 
    array (
    ),
    'laspezia' => 
    array (
    ),
    'latina' => 
    array (
    ),
    'lc' => 
    array (
    ),
    'le' => 
    array (
    ),
    'lecce' => 
    array (
    ),
    'lecco' => 
    array (
    ),
    'li' => 
    array (
    ),
    'livorno' => 
    array (
    ),
    'lo' => 
    array (
    ),
    'lodi' => 
    array (
    ),
    'lt' => 
    array (
    ),
    'lu' => 
    array (
    ),
    'lucca' => 
    array (
    ),
    'macerata' => 
    array (
    ),
    'mantova' => 
    array (
    ),
    'massa-carrara' => 
    array (
    ),
    'massacarrara' => 
    array (
    ),
    'matera' => 
    array (
    ),
    'mb' => 
    array (
    ),
    'mc' => 
    array (
    ),
    'me' => 
    array (
    ),
    'medio-campidano' => 
    array (
    ),
    'mediocampidano' => 
    array (
    ),
    'messina' => 
    array (
    ),
    'mi' => 
    array (
    ),
    'milan' => 
    array (
    ),
    'milano' => 
    array (
    ),
    'mn' => 
    array (
    ),
    'mo' => 
    array (
    ),
    'modena' => 
    array (
    ),
    'monza-brianza' => 
    array (
    ),
    'monza-e-della-brianza' => 
    array (
    ),
    'monza' => 
    array (
    ),
    'monzabrianza' => 
    array (
    ),
    'monzaebrianza' => 
    array (
    ),
    'monzaedellabrianza' => 
    array (
    ),
    'ms' => 
    array (
    ),
    'mt' => 
    array (
    ),
    'na' => 
    array (
    ),
    'naples' => 
    array (
    ),
    'napoli' => 
    array (
    ),
    'no' => 
    array (
    ),
    'novara' => 
    array (
    ),
    'nu' => 
    array (
    ),
    'nuoro' => 
    array (
    ),
    'og' => 
    array (
    ),
    'ogliastra' => 
    array (
    ),
    'olbia-tempio' => 
    array (
    ),
    'olbiatempio' => 
    array (
    ),
    'or' => 
    array (
    ),
    'oristano' => 
    array (
    ),
    'ot' => 
    array (
    ),
    'pa' => 
    array (
    ),
    'padova' => 
    array (
    ),
    'padua' => 
    array (
    ),
    'palermo' => 
    array (
    ),
    'parma' => 
    array (
    ),
    'pavia' => 
    array (
    ),
    'pc' => 
    array (
    ),
    'pd' => 
    array (
    ),
    'pe' => 
    array (
    ),
    'perugia' => 
    array (
    ),
    'pesaro-urbino' => 
    array (
    ),
    'pesarourbino' => 
    array (
    ),
    'pescara' => 
    array (
    ),
    'pg' => 
    array (
    ),
    'pi' => 
    array (
    ),
    'piacenza' => 
    array (
    ),
    'pisa' => 
    array (
    ),
    'pistoia' => 
    array (
    ),
    'pn' => 
    array (
    ),
    'po' => 
    array (
    ),
    'pordenone' => 
    array (
    ),
    'potenza' => 
    array (
    ),
    'pr' => 
    array (
    ),
    'prato' => 
    array (
    ),
    'pt' => 
    array (
    ),
    'pu' => 
    array (
    ),
    'pv' => 
    array (
    ),
    'pz' => 
    array (
    ),
    'ra' => 
    array (
    ),
    'ragusa' => 
    array (
    ),
    'ravenna' => 
    array (
    ),
    'rc' => 
    array (
    ),
    're' => 
    array (
    ),
    'reggio-calabria' => 
    array (
    ),
    'reggio-emilia' => 
    array (
    ),
    'reggiocalabria' => 
    array (
    ),
    'reggioemilia' => 
    array (
    ),
    'rg' => 
    array (
    ),
    'ri' => 
    array (
    ),
    'rieti' => 
    array (
    ),
    'rimini' => 
    array (
    ),
    'rm' => 
    array (
    ),
    'rn' => 
    array (
    ),
    'ro' => 
    array (
    ),
    'roma' => 
    array (
    ),
    'rome' => 
    array (
    ),
    'rovigo' => 
    array (
    ),
    'sa' => 
    array (
    ),
    'salerno' => 
    array (
    ),
    'sassari' => 
    array (
    ),
    'savona' => 
    array (
    ),
    'si' => 
    array (
    ),
    'siena' => 
    array (
    ),
    'siracusa' => 
    array (
    ),
    'so' => 
    array (
    ),
    'sondrio' => 
    array (
    ),
    'sp' => 
    array (
    ),
    'sr' => 
    array (
    ),
    'ss' => 
    array (
    ),
    'suedtirol' => 
    array (
    ),
    'sv' => 
    array (
    ),
    'ta' => 
    array (
    ),
    'taranto' => 
    array (
    ),
    'te' => 
    array (
    ),
    'tempio-olbia' => 
    array (
    ),
    'tempioolbia' => 
    array (
    ),
    'teramo' => 
    array (
    ),
    'terni' => 
    array (
    ),
    'tn' => 
    array (
    ),
    'to' => 
    array (
    ),
    'torino' => 
    array (
    ),
    'tp' => 
    array (
    ),
    'tr' => 
    array (
    ),
    'trani-andria-barletta' => 
    array (
    ),
    'trani-barletta-andria' => 
    array (
    ),
    'traniandriabarletta' => 
    array (
    ),
    'tranibarlettaandria' => 
    array (
    ),
    'trapani' => 
    array (
    ),
    'trentino' => 
    array (
    ),
    'trento' => 
    array (
    ),
    'treviso' => 
    array (
    ),
    'trieste' => 
    array (
    ),
    'ts' => 
    array (
    ),
    'turin' => 
    array (
    ),
    'tv' => 
    array (
    ),
    'ud' => 
    array (
    ),
    'udine' => 
    array (
    ),
    'urbino-pesaro' => 
    array (
    ),
    'urbinopesaro' => 
    array (
    ),
    'va' => 
    array (
    ),
    'varese' => 
    array (
    ),
    'vb' => 
    array (
    ),
    'vc' => 
    array (
    ),
    've' => 
    array (
    ),
    'venezia' => 
    array (
    ),
    'venice' => 
    array (
    ),
    'verbania' => 
    array (
    ),
    'vercelli' => 
    array (
    ),
    'verona' => 
    array (
    ),
    'vi' => 
    array (
    ),
    'vibo-valentia' => 
    array (
    ),
    'vibovalentia' => 
    array (
    ),
    'vicenza' => 
    array (
    ),
    'viterbo' => 
    array (
    ),
    'vr' => 
    array (
    ),
    'vs' => 
    array (
    ),
    'vt' => 
    array (
    ),
    'vv' => 
    array (
    ),
    'blogspot' => 
    array (
    ),
  ),
  'je' => 
  array (
    'co' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
  ),
  'jm' => 
  array (
    '*' => 
    array (
    ),
  ),
  'jo' => 
  array (
    'com' => 
    array (
    ),
    'org' => 
    array (
    ),
    'net' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'sch' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'name' => 
    array (
    ),
  ),
  'jobs' => 
  array (
  ),
  'jp' => 
  array (
    'ac' => 
    array (
    ),
    'ad' => 
    array (
    ),
    'co' => 
    array (
    ),
    'ed' => 
    array (
    ),
    'go' => 
    array (
    ),
    'gr' => 
    array (
    ),
    'lg' => 
    array (
    ),
    'ne' => 
    array (
    ),
    'or' => 
    array (
    ),
    'aichi' => 
    array (
      'aisai' => 
      array (
      ),
      'ama' => 
      array (
      ),
      'anjo' => 
      array (
      ),
      'asuke' => 
      array (
      ),
      'chiryu' => 
      array (
      ),
      'chita' => 
      array (
      ),
      'fuso' => 
      array (
      ),
      'gamagori' => 
      array (
      ),
      'handa' => 
      array (
      ),
      'hazu' => 
      array (
      ),
      'hekinan' => 
      array (
      ),
      'higashiura' => 
      array (
      ),
      'ichinomiya' => 
      array (
      ),
      'inazawa' => 
      array (
      ),
      'inuyama' => 
      array (
      ),
      'isshiki' => 
      array (
      ),
      'iwakura' => 
      array (
      ),
      'kanie' => 
      array (
      ),
      'kariya' => 
      array (
      ),
      'kasugai' => 
      array (
      ),
      'kira' => 
      array (
      ),
      'kiyosu' => 
      array (
      ),
      'komaki' => 
      array (
      ),
      'konan' => 
      array (
      ),
      'kota' => 
      array (
      ),
      'mihama' => 
      array (
      ),
      'miyoshi' => 
      array (
      ),
      'nishio' => 
      array (
      ),
      'nisshin' => 
      array (
      ),
      'obu' => 
      array (
      ),
      'oguchi' => 
      array (
      ),
      'oharu' => 
      array (
      ),
      'okazaki' => 
      array (
      ),
      'owariasahi' => 
      array (
      ),
      'seto' => 
      array (
      ),
      'shikatsu' => 
      array (
      ),
      'shinshiro' => 
      array (
      ),
      'shitara' => 
      array (
      ),
      'tahara' => 
      array (
      ),
      'takahama' => 
      array (
      ),
      'tobishima' => 
      array (
      ),
      'toei' => 
      array (
      ),
      'togo' => 
      array (
      ),
      'tokai' => 
      array (
      ),
      'tokoname' => 
      array (
      ),
      'toyoake' => 
      array (
      ),
      'toyohashi' => 
      array (
      ),
      'toyokawa' => 
      array (
      ),
      'toyone' => 
      array (
      ),
      'toyota' => 
      array (
      ),
      'tsushima' => 
      array (
      ),
      'yatomi' => 
      array (
      ),
    ),
    'akita' => 
    array (
      'akita' => 
      array (
      ),
      'daisen' => 
      array (
      ),
      'fujisato' => 
      array (
      ),
      'gojome' => 
      array (
      ),
      'hachirogata' => 
      array (
      ),
      'happou' => 
      array (
      ),
      'higashinaruse' => 
      array (
      ),
      'honjo' => 
      array (
      ),
      'honjyo' => 
      array (
      ),
      'ikawa' => 
      array (
      ),
      'kamikoani' => 
      array (
      ),
      'kamioka' => 
      array (
      ),
      'katagami' => 
      array (
      ),
      'kazuno' => 
      array (
      ),
      'kitaakita' => 
      array (
      ),
      'kosaka' => 
      array (
      ),
      'kyowa' => 
      array (
      ),
      'misato' => 
      array (
      ),
      'mitane' => 
      array (
      ),
      'moriyoshi' => 
      array (
      ),
      'nikaho' => 
      array (
      ),
      'noshiro' => 
      array (
      ),
      'odate' => 
      array (
      ),
      'oga' => 
      array (
      ),
      'ogata' => 
      array (
      ),
      'semboku' => 
      array (
      ),
      'yokote' => 
      array (
      ),
      'yurihonjo' => 
      array (
      ),
    ),
    'aomori' => 
    array (
      'aomori' => 
      array (
      ),
      'gonohe' => 
      array (
      ),
      'hachinohe' => 
      array (
      ),
      'hashikami' => 
      array (
      ),
      'hiranai' => 
      array (
      ),
      'hirosaki' => 
      array (
      ),
      'itayanagi' => 
      array (
      ),
      'kuroishi' => 
      array (
      ),
      'misawa' => 
      array (
      ),
      'mutsu' => 
      array (
      ),
      'nakadomari' => 
      array (
      ),
      'noheji' => 
      array (
      ),
      'oirase' => 
      array (
      ),
      'owani' => 
      array (
      ),
      'rokunohe' => 
      array (
      ),
      'sannohe' => 
      array (
      ),
      'shichinohe' => 
      array (
      ),
      'shingo' => 
      array (
      ),
      'takko' => 
      array (
      ),
      'towada' => 
      array (
      ),
      'tsugaru' => 
      array (
      ),
      'tsuruta' => 
      array (
      ),
    ),
    'chiba' => 
    array (
      'abiko' => 
      array (
      ),
      'asahi' => 
      array (
      ),
      'chonan' => 
      array (
      ),
      'chosei' => 
      array (
      ),
      'choshi' => 
      array (
      ),
      'chuo' => 
      array (
      ),
      'funabashi' => 
      array (
      ),
      'futtsu' => 
      array (
      ),
      'hanamigawa' => 
      array (
      ),
      'ichihara' => 
      array (
      ),
      'ichikawa' => 
      array (
      ),
      'ichinomiya' => 
      array (
      ),
      'inzai' => 
      array (
      ),
      'isumi' => 
      array (
      ),
      'kamagaya' => 
      array (
      ),
      'kamogawa' => 
      array (
      ),
      'kashiwa' => 
      array (
      ),
      'katori' => 
      array (
      ),
      'katsuura' => 
      array (
      ),
      'kimitsu' => 
      array (
      ),
      'kisarazu' => 
      array (
      ),
      'kozaki' => 
      array (
      ),
      'kujukuri' => 
      array (
      ),
      'kyonan' => 
      array (
      ),
      'matsudo' => 
      array (
      ),
      'midori' => 
      array (
      ),
      'mihama' => 
      array (
      ),
      'minamiboso' => 
      array (
      ),
      'mobara' => 
      array (
      ),
      'mutsuzawa' => 
      array (
      ),
      'nagara' => 
      array (
      ),
      'nagareyama' => 
      array (
      ),
      'narashino' => 
      array (
      ),
      'narita' => 
      array (
      ),
      'noda' => 
      array (
      ),
      'oamishirasato' => 
      array (
      ),
      'omigawa' => 
      array (
      ),
      'onjuku' => 
      array (
      ),
      'otaki' => 
      array (
      ),
      'sakae' => 
      array (
      ),
      'sakura' => 
      array (
      ),
      'shimofusa' => 
      array (
      ),
      'shirako' => 
      array (
      ),
      'shiroi' => 
      array (
      ),
      'shisui' => 
      array (
      ),
      'sodegaura' => 
      array (
      ),
      'sosa' => 
      array (
      ),
      'tako' => 
      array (
      ),
      'tateyama' => 
      array (
      ),
      'togane' => 
      array (
      ),
      'tohnosho' => 
      array (
      ),
      'tomisato' => 
      array (
      ),
      'urayasu' => 
      array (
      ),
      'yachimata' => 
      array (
      ),
      'yachiyo' => 
      array (
      ),
      'yokaichiba' => 
      array (
      ),
      'yokoshibahikari' => 
      array (
      ),
      'yotsukaido' => 
      array (
      ),
    ),
    'ehime' => 
    array (
      'ainan' => 
      array (
      ),
      'honai' => 
      array (
      ),
      'ikata' => 
      array (
      ),
      'imabari' => 
      array (
      ),
      'iyo' => 
      array (
      ),
      'kamijima' => 
      array (
      ),
      'kihoku' => 
      array (
      ),
      'kumakogen' => 
      array (
      ),
      'masaki' => 
      array (
      ),
      'matsuno' => 
      array (
      ),
      'matsuyama' => 
      array (
      ),
      'namikata' => 
      array (
      ),
      'niihama' => 
      array (
      ),
      'ozu' => 
      array (
      ),
      'saijo' => 
      array (
      ),
      'seiyo' => 
      array (
      ),
      'shikokuchuo' => 
      array (
      ),
      'tobe' => 
      array (
      ),
      'toon' => 
      array (
      ),
      'uchiko' => 
      array (
      ),
      'uwajima' => 
      array (
      ),
      'yawatahama' => 
      array (
      ),
    ),
    'fukui' => 
    array (
      'echizen' => 
      array (
      ),
      'eiheiji' => 
      array (
      ),
      'fukui' => 
      array (
      ),
      'ikeda' => 
      array (
      ),
      'katsuyama' => 
      array (
      ),
      'mihama' => 
      array (
      ),
      'minamiechizen' => 
      array (
      ),
      'obama' => 
      array (
      ),
      'ohi' => 
      array (
      ),
      'ono' => 
      array (
      ),
      'sabae' => 
      array (
      ),
      'sakai' => 
      array (
      ),
      'takahama' => 
      array (
      ),
      'tsuruga' => 
      array (
      ),
      'wakasa' => 
      array (
      ),
    ),
    'fukuoka' => 
    array (
      'ashiya' => 
      array (
      ),
      'buzen' => 
      array (
      ),
      'chikugo' => 
      array (
      ),
      'chikuho' => 
      array (
      ),
      'chikujo' => 
      array (
      ),
      'chikushino' => 
      array (
      ),
      'chikuzen' => 
      array (
      ),
      'chuo' => 
      array (
      ),
      'dazaifu' => 
      array (
      ),
      'fukuchi' => 
      array (
      ),
      'hakata' => 
      array (
      ),
      'higashi' => 
      array (
      ),
      'hirokawa' => 
      array (
      ),
      'hisayama' => 
      array (
      ),
      'iizuka' => 
      array (
      ),
      'inatsuki' => 
      array (
      ),
      'kaho' => 
      array (
      ),
      'kasuga' => 
      array (
      ),
      'kasuya' => 
      array (
      ),
      'kawara' => 
      array (
      ),
      'keisen' => 
      array (
      ),
      'koga' => 
      array (
      ),
      'kurate' => 
      array (
      ),
      'kurogi' => 
      array (
      ),
      'kurume' => 
      array (
      ),
      'minami' => 
      array (
      ),
      'miyako' => 
      array (
      ),
      'miyama' => 
      array (
      ),
      'miyawaka' => 
      array (
      ),
      'mizumaki' => 
      array (
      ),
      'munakata' => 
      array (
      ),
      'nakagawa' => 
      array (
      ),
      'nakama' => 
      array (
      ),
      'nishi' => 
      array (
      ),
      'nogata' => 
      array (
      ),
      'ogori' => 
      array (
      ),
      'okagaki' => 
      array (
      ),
      'okawa' => 
      array (
      ),
      'oki' => 
      array (
      ),
      'omuta' => 
      array (
      ),
      'onga' => 
      array (
      ),
      'onojo' => 
      array (
      ),
      'oto' => 
      array (
      ),
      'saigawa' => 
      array (
      ),
      'sasaguri' => 
      array (
      ),
      'shingu' => 
      array (
      ),
      'shinyoshitomi' => 
      array (
      ),
      'shonai' => 
      array (
      ),
      'soeda' => 
      array (
      ),
      'sue' => 
      array (
      ),
      'tachiarai' => 
      array (
      ),
      'tagawa' => 
      array (
      ),
      'takata' => 
      array (
      ),
      'toho' => 
      array (
      ),
      'toyotsu' => 
      array (
      ),
      'tsuiki' => 
      array (
      ),
      'ukiha' => 
      array (
      ),
      'umi' => 
      array (
      ),
      'usui' => 
      array (
      ),
      'yamada' => 
      array (
      ),
      'yame' => 
      array (
      ),
      'yanagawa' => 
      array (
      ),
      'yukuhashi' => 
      array (
      ),
    ),
    'fukushima' => 
    array (
      'aizubange' => 
      array (
      ),
      'aizumisato' => 
      array (
      ),
      'aizuwakamatsu' => 
      array (
      ),
      'asakawa' => 
      array (
      ),
      'bandai' => 
      array (
      ),
      'date' => 
      array (
      ),
      'fukushima' => 
      array (
      ),
      'furudono' => 
      array (
      ),
      'futaba' => 
      array (
      ),
      'hanawa' => 
      array (
      ),
      'higashi' => 
      array (
      ),
      'hirata' => 
      array (
      ),
      'hirono' => 
      array (
      ),
      'iitate' => 
      array (
      ),
      'inawashiro' => 
      array (
      ),
      'ishikawa' => 
      array (
      ),
      'iwaki' => 
      array (
      ),
      'izumizaki' => 
      array (
      ),
      'kagamiishi' => 
      array (
      ),
      'kaneyama' => 
      array (
      ),
      'kawamata' => 
      array (
      ),
      'kitakata' => 
      array (
      ),
      'kitashiobara' => 
      array (
      ),
      'koori' => 
      array (
      ),
      'koriyama' => 
      array (
      ),
      'kunimi' => 
      array (
      ),
      'miharu' => 
      array (
      ),
      'mishima' => 
      array (
      ),
      'namie' => 
      array (
      ),
      'nango' => 
      array (
      ),
      'nishiaizu' => 
      array (
      ),
      'nishigo' => 
      array (
      ),
      'okuma' => 
      array (
      ),
      'omotego' => 
      array (
      ),
      'ono' => 
      array (
      ),
      'otama' => 
      array (
      ),
      'samegawa' => 
      array (
      ),
      'shimogo' => 
      array (
      ),
      'shirakawa' => 
      array (
      ),
      'showa' => 
      array (
      ),
      'soma' => 
      array (
      ),
      'sukagawa' => 
      array (
      ),
      'taishin' => 
      array (
      ),
      'tamakawa' => 
      array (
      ),
      'tanagura' => 
      array (
      ),
      'tenei' => 
      array (
      ),
      'yabuki' => 
      array (
      ),
      'yamato' => 
      array (
      ),
      'yamatsuri' => 
      array (
      ),
      'yanaizu' => 
      array (
      ),
      'yugawa' => 
      array (
      ),
    ),
    'gifu' => 
    array (
      'anpachi' => 
      array (
      ),
      'ena' => 
      array (
      ),
      'gifu' => 
      array (
      ),
      'ginan' => 
      array (
      ),
      'godo' => 
      array (
      ),
      'gujo' => 
      array (
      ),
      'hashima' => 
      array (
      ),
      'hichiso' => 
      array (
      ),
      'hida' => 
      array (
      ),
      'higashishirakawa' => 
      array (
      ),
      'ibigawa' => 
      array (
      ),
      'ikeda' => 
      array (
      ),
      'kakamigahara' => 
      array (
      ),
      'kani' => 
      array (
      ),
      'kasahara' => 
      array (
      ),
      'kasamatsu' => 
      array (
      ),
      'kawaue' => 
      array (
      ),
      'kitagata' => 
      array (
      ),
      'mino' => 
      array (
      ),
      'minokamo' => 
      array (
      ),
      'mitake' => 
      array (
      ),
      'mizunami' => 
      array (
      ),
      'motosu' => 
      array (
      ),
      'nakatsugawa' => 
      array (
      ),
      'ogaki' => 
      array (
      ),
      'sakahogi' => 
      array (
      ),
      'seki' => 
      array (
      ),
      'sekigahara' => 
      array (
      ),
      'shirakawa' => 
      array (
      ),
      'tajimi' => 
      array (
      ),
      'takayama' => 
      array (
      ),
      'tarui' => 
      array (
      ),
      'toki' => 
      array (
      ),
      'tomika' => 
      array (
      ),
      'wanouchi' => 
      array (
      ),
      'yamagata' => 
      array (
      ),
      'yaotsu' => 
      array (
      ),
      'yoro' => 
      array (
      ),
    ),
    'gunma' => 
    array (
      'annaka' => 
      array (
      ),
      'chiyoda' => 
      array (
      ),
      'fujioka' => 
      array (
      ),
      'higashiagatsuma' => 
      array (
      ),
      'isesaki' => 
      array (
      ),
      'itakura' => 
      array (
      ),
      'kanna' => 
      array (
      ),
      'kanra' => 
      array (
      ),
      'katashina' => 
      array (
      ),
      'kawaba' => 
      array (
      ),
      'kiryu' => 
      array (
      ),
      'kusatsu' => 
      array (
      ),
      'maebashi' => 
      array (
      ),
      'meiwa' => 
      array (
      ),
      'midori' => 
      array (
      ),
      'minakami' => 
      array (
      ),
      'naganohara' => 
      array (
      ),
      'nakanojo' => 
      array (
      ),
      'nanmoku' => 
      array (
      ),
      'numata' => 
      array (
      ),
      'oizumi' => 
      array (
      ),
      'ora' => 
      array (
      ),
      'ota' => 
      array (
      ),
      'shibukawa' => 
      array (
      ),
      'shimonita' => 
      array (
      ),
      'shinto' => 
      array (
      ),
      'showa' => 
      array (
      ),
      'takasaki' => 
      array (
      ),
      'takayama' => 
      array (
      ),
      'tamamura' => 
      array (
      ),
      'tatebayashi' => 
      array (
      ),
      'tomioka' => 
      array (
      ),
      'tsukiyono' => 
      array (
      ),
      'tsumagoi' => 
      array (
      ),
      'ueno' => 
      array (
      ),
      'yoshioka' => 
      array (
      ),
    ),
    'hiroshima' => 
    array (
      'asaminami' => 
      array (
      ),
      'daiwa' => 
      array (
      ),
      'etajima' => 
      array (
      ),
      'fuchu' => 
      array (
      ),
      'fukuyama' => 
      array (
      ),
      'hatsukaichi' => 
      array (
      ),
      'higashihiroshima' => 
      array (
      ),
      'hongo' => 
      array (
      ),
      'jinsekikogen' => 
      array (
      ),
      'kaita' => 
      array (
      ),
      'kui' => 
      array (
      ),
      'kumano' => 
      array (
      ),
      'kure' => 
      array (
      ),
      'mihara' => 
      array (
      ),
      'miyoshi' => 
      array (
      ),
      'naka' => 
      array (
      ),
      'onomichi' => 
      array (
      ),
      'osakikamijima' => 
      array (
      ),
      'otake' => 
      array (
      ),
      'saka' => 
      array (
      ),
      'sera' => 
      array (
      ),
      'seranishi' => 
      array (
      ),
      'shinichi' => 
      array (
      ),
      'shobara' => 
      array (
      ),
      'takehara' => 
      array (
      ),
    ),
    'hokkaido' => 
    array (
      'abashiri' => 
      array (
      ),
      'abira' => 
      array (
      ),
      'aibetsu' => 
      array (
      ),
      'akabira' => 
      array (
      ),
      'akkeshi' => 
      array (
      ),
      'asahikawa' => 
      array (
      ),
      'ashibetsu' => 
      array (
      ),
      'ashoro' => 
      array (
      ),
      'assabu' => 
      array (
      ),
      'atsuma' => 
      array (
      ),
      'bibai' => 
      array (
      ),
      'biei' => 
      array (
      ),
      'bifuka' => 
      array (
      ),
      'bihoro' => 
      array (
      ),
      'biratori' => 
      array (
      ),
      'chippubetsu' => 
      array (
      ),
      'chitose' => 
      array (
      ),
      'date' => 
      array (
      ),
      'ebetsu' => 
      array (
      ),
      'embetsu' => 
      array (
      ),
      'eniwa' => 
      array (
      ),
      'erimo' => 
      array (
      ),
      'esan' => 
      array (
      ),
      'esashi' => 
      array (
      ),
      'fukagawa' => 
      array (
      ),
      'fukushima' => 
      array (
      ),
      'furano' => 
      array (
      ),
      'furubira' => 
      array (
      ),
      'haboro' => 
      array (
      ),
      'hakodate' => 
      array (
      ),
      'hamatonbetsu' => 
      array (
      ),
      'hidaka' => 
      array (
      ),
      'higashikagura' => 
      array (
      ),
      'higashikawa' => 
      array (
      ),
      'hiroo' => 
      array (
      ),
      'hokuryu' => 
      array (
      ),
      'hokuto' => 
      array (
      ),
      'honbetsu' => 
      array (
      ),
      'horokanai' => 
      array (
      ),
      'horonobe' => 
      array (
      ),
      'ikeda' => 
      array (
      ),
      'imakane' => 
      array (
      ),
      'ishikari' => 
      array (
      ),
      'iwamizawa' => 
      array (
      ),
      'iwanai' => 
      array (
      ),
      'kamifurano' => 
      array (
      ),
      'kamikawa' => 
      array (
      ),
      'kamishihoro' => 
      array (
      ),
      'kamisunagawa' => 
      array (
      ),
      'kamoenai' => 
      array (
      ),
      'kayabe' => 
      array (
      ),
      'kembuchi' => 
      array (
      ),
      'kikonai' => 
      array (
      ),
      'kimobetsu' => 
      array (
      ),
      'kitahiroshima' => 
      array (
      ),
      'kitami' => 
      array (
      ),
      'kiyosato' => 
      array (
      ),
      'koshimizu' => 
      array (
      ),
      'kunneppu' => 
      array (
      ),
      'kuriyama' => 
      array (
      ),
      'kuromatsunai' => 
      array (
      ),
      'kushiro' => 
      array (
      ),
      'kutchan' => 
      array (
      ),
      'kyowa' => 
      array (
      ),
      'mashike' => 
      array (
      ),
      'matsumae' => 
      array (
      ),
      'mikasa' => 
      array (
      ),
      'minamifurano' => 
      array (
      ),
      'mombetsu' => 
      array (
      ),
      'moseushi' => 
      array (
      ),
      'mukawa' => 
      array (
      ),
      'muroran' => 
      array (
      ),
      'naie' => 
      array (
      ),
      'nakagawa' => 
      array (
      ),
      'nakasatsunai' => 
      array (
      ),
      'nakatombetsu' => 
      array (
      ),
      'nanae' => 
      array (
      ),
      'nanporo' => 
      array (
      ),
      'nayoro' => 
      array (
      ),
      'nemuro' => 
      array (
      ),
      'niikappu' => 
      array (
      ),
      'niki' => 
      array (
      ),
      'nishiokoppe' => 
      array (
      ),
      'noboribetsu' => 
      array (
      ),
      'numata' => 
      array (
      ),
      'obihiro' => 
      array (
      ),
      'obira' => 
      array (
      ),
      'oketo' => 
      array (
      ),
      'okoppe' => 
      array (
      ),
      'otaru' => 
      array (
      ),
      'otobe' => 
      array (
      ),
      'otofuke' => 
      array (
      ),
      'otoineppu' => 
      array (
      ),
      'oumu' => 
      array (
      ),
      'ozora' => 
      array (
      ),
      'pippu' => 
      array (
      ),
      'rankoshi' => 
      array (
      ),
      'rebun' => 
      array (
      ),
      'rikubetsu' => 
      array (
      ),
      'rishiri' => 
      array (
      ),
      'rishirifuji' => 
      array (
      ),
      'saroma' => 
      array (
      ),
      'sarufutsu' => 
      array (
      ),
      'shakotan' => 
      array (
      ),
      'shari' => 
      array (
      ),
      'shibecha' => 
      array (
      ),
      'shibetsu' => 
      array (
      ),
      'shikabe' => 
      array (
      ),
      'shikaoi' => 
      array (
      ),
      'shimamaki' => 
      array (
      ),
      'shimizu' => 
      array (
      ),
      'shimokawa' => 
      array (
      ),
      'shinshinotsu' => 
      array (
      ),
      'shintoku' => 
      array (
      ),
      'shiranuka' => 
      array (
      ),
      'shiraoi' => 
      array (
      ),
      'shiriuchi' => 
      array (
      ),
      'sobetsu' => 
      array (
      ),
      'sunagawa' => 
      array (
      ),
      'taiki' => 
      array (
      ),
      'takasu' => 
      array (
      ),
      'takikawa' => 
      array (
      ),
      'takinoue' => 
      array (
      ),
      'teshikaga' => 
      array (
      ),
      'tobetsu' => 
      array (
      ),
      'tohma' => 
      array (
      ),
      'tomakomai' => 
      array (
      ),
      'tomari' => 
      array (
      ),
      'toya' => 
      array (
      ),
      'toyako' => 
      array (
      ),
      'toyotomi' => 
      array (
      ),
      'toyoura' => 
      array (
      ),
      'tsubetsu' => 
      array (
      ),
      'tsukigata' => 
      array (
      ),
      'urakawa' => 
      array (
      ),
      'urausu' => 
      array (
      ),
      'uryu' => 
      array (
      ),
      'utashinai' => 
      array (
      ),
      'wakkanai' => 
      array (
      ),
      'wassamu' => 
      array (
      ),
      'yakumo' => 
      array (
      ),
      'yoichi' => 
      array (
      ),
    ),
    'hyogo' => 
    array (
      'aioi' => 
      array (
      ),
      'akashi' => 
      array (
      ),
      'ako' => 
      array (
      ),
      'amagasaki' => 
      array (
      ),
      'aogaki' => 
      array (
      ),
      'asago' => 
      array (
      ),
      'ashiya' => 
      array (
      ),
      'awaji' => 
      array (
      ),
      'fukusaki' => 
      array (
      ),
      'goshiki' => 
      array (
      ),
      'harima' => 
      array (
      ),
      'himeji' => 
      array (
      ),
      'ichikawa' => 
      array (
      ),
      'inagawa' => 
      array (
      ),
      'itami' => 
      array (
      ),
      'kakogawa' => 
      array (
      ),
      'kamigori' => 
      array (
      ),
      'kamikawa' => 
      array (
      ),
      'kasai' => 
      array (
      ),
      'kasuga' => 
      array (
      ),
      'kawanishi' => 
      array (
      ),
      'miki' => 
      array (
      ),
      'minamiawaji' => 
      array (
      ),
      'nishinomiya' => 
      array (
      ),
      'nishiwaki' => 
      array (
      ),
      'ono' => 
      array (
      ),
      'sanda' => 
      array (
      ),
      'sannan' => 
      array (
      ),
      'sasayama' => 
      array (
      ),
      'sayo' => 
      array (
      ),
      'shingu' => 
      array (
      ),
      'shinonsen' => 
      array (
      ),
      'shiso' => 
      array (
      ),
      'sumoto' => 
      array (
      ),
      'taishi' => 
      array (
      ),
      'taka' => 
      array (
      ),
      'takarazuka' => 
      array (
      ),
      'takasago' => 
      array (
      ),
      'takino' => 
      array (
      ),
      'tamba' => 
      array (
      ),
      'tatsuno' => 
      array (
      ),
      'toyooka' => 
      array (
      ),
      'yabu' => 
      array (
      ),
      'yashiro' => 
      array (
      ),
      'yoka' => 
      array (
      ),
      'yokawa' => 
      array (
      ),
    ),
    'ibaraki' => 
    array (
      'ami' => 
      array (
      ),
      'asahi' => 
      array (
      ),
      'bando' => 
      array (
      ),
      'chikusei' => 
      array (
      ),
      'daigo' => 
      array (
      ),
      'fujishiro' => 
      array (
      ),
      'hitachi' => 
      array (
      ),
      'hitachinaka' => 
      array (
      ),
      'hitachiomiya' => 
      array (
      ),
      'hitachiota' => 
      array (
      ),
      'ibaraki' => 
      array (
      ),
      'ina' => 
      array (
      ),
      'inashiki' => 
      array (
      ),
      'itako' => 
      array (
      ),
      'iwama' => 
      array (
      ),
      'joso' => 
      array (
      ),
      'kamisu' => 
      array (
      ),
      'kasama' => 
      array (
      ),
      'kashima' => 
      array (
      ),
      'kasumigaura' => 
      array (
      ),
      'koga' => 
      array (
      ),
      'miho' => 
      array (
      ),
      'mito' => 
      array (
      ),
      'moriya' => 
      array (
      ),
      'naka' => 
      array (
      ),
      'namegata' => 
      array (
      ),
      'oarai' => 
      array (
      ),
      'ogawa' => 
      array (
      ),
      'omitama' => 
      array (
      ),
      'ryugasaki' => 
      array (
      ),
      'sakai' => 
      array (
      ),
      'sakuragawa' => 
      array (
      ),
      'shimodate' => 
      array (
      ),
      'shimotsuma' => 
      array (
      ),
      'shirosato' => 
      array (
      ),
      'sowa' => 
      array (
      ),
      'suifu' => 
      array (
      ),
      'takahagi' => 
      array (
      ),
      'tamatsukuri' => 
      array (
      ),
      'tokai' => 
      array (
      ),
      'tomobe' => 
      array (
      ),
      'tone' => 
      array (
      ),
      'toride' => 
      array (
      ),
      'tsuchiura' => 
      array (
      ),
      'tsukuba' => 
      array (
      ),
      'uchihara' => 
      array (
      ),
      'ushiku' => 
      array (
      ),
      'yachiyo' => 
      array (
      ),
      'yamagata' => 
      array (
      ),
      'yawara' => 
      array (
      ),
      'yuki' => 
      array (
      ),
    ),
    'ishikawa' => 
    array (
      'anamizu' => 
      array (
      ),
      'hakui' => 
      array (
      ),
      'hakusan' => 
      array (
      ),
      'kaga' => 
      array (
      ),
      'kahoku' => 
      array (
      ),
      'kanazawa' => 
      array (
      ),
      'kawakita' => 
      array (
      ),
      'komatsu' => 
      array (
      ),
      'nakanoto' => 
      array (
      ),
      'nanao' => 
      array (
      ),
      'nomi' => 
      array (
      ),
      'nonoichi' => 
      array (
      ),
      'noto' => 
      array (
      ),
      'shika' => 
      array (
      ),
      'suzu' => 
      array (
      ),
      'tsubata' => 
      array (
      ),
      'tsurugi' => 
      array (
      ),
      'uchinada' => 
      array (
      ),
      'wajima' => 
      array (
      ),
    ),
    'iwate' => 
    array (
      'fudai' => 
      array (
      ),
      'fujisawa' => 
      array (
      ),
      'hanamaki' => 
      array (
      ),
      'hiraizumi' => 
      array (
      ),
      'hirono' => 
      array (
      ),
      'ichinohe' => 
      array (
      ),
      'ichinoseki' => 
      array (
      ),
      'iwaizumi' => 
      array (
      ),
      'iwate' => 
      array (
      ),
      'joboji' => 
      array (
      ),
      'kamaishi' => 
      array (
      ),
      'kanegasaki' => 
      array (
      ),
      'karumai' => 
      array (
      ),
      'kawai' => 
      array (
      ),
      'kitakami' => 
      array (
      ),
      'kuji' => 
      array (
      ),
      'kunohe' => 
      array (
      ),
      'kuzumaki' => 
      array (
      ),
      'miyako' => 
      array (
      ),
      'mizusawa' => 
      array (
      ),
      'morioka' => 
      array (
      ),
      'ninohe' => 
      array (
      ),
      'noda' => 
      array (
      ),
      'ofunato' => 
      array (
      ),
      'oshu' => 
      array (
      ),
      'otsuchi' => 
      array (
      ),
      'rikuzentakata' => 
      array (
      ),
      'shiwa' => 
      array (
      ),
      'shizukuishi' => 
      array (
      ),
      'sumita' => 
      array (
      ),
      'tanohata' => 
      array (
      ),
      'tono' => 
      array (
      ),
      'yahaba' => 
      array (
      ),
      'yamada' => 
      array (
      ),
    ),
    'kagawa' => 
    array (
      'ayagawa' => 
      array (
      ),
      'higashikagawa' => 
      array (
      ),
      'kanonji' => 
      array (
      ),
      'kotohira' => 
      array (
      ),
      'manno' => 
      array (
      ),
      'marugame' => 
      array (
      ),
      'mitoyo' => 
      array (
      ),
      'naoshima' => 
      array (
      ),
      'sanuki' => 
      array (
      ),
      'tadotsu' => 
      array (

      ),
      'takamatsu' => 
      array (
      ),
      'tonosho' => 
      array (
      ),
      'uchinomi' => 
      array (
      ),
      'utazu' => 
      array (
      ),
      'zentsuji' => 
      array (
      ),
    ),
    'kagoshima' => 
    array (
      'akune' => 
      array (
      ),
      'amami' => 
      array (
      ),
      'hioki' => 
      array (
      ),
      'isa' => 
      array (
      ),
      'isen' => 
      array (
      ),
      'izumi' => 
      array (
      ),
      'kagoshima' => 
      array (
      ),
      'kanoya' => 
      array (
      ),
      'kawanabe' => 
      array (
      ),
      'kinko' => 
      array (
      ),
      'kouyama' => 
      array (
      ),
      'makurazaki' => 
      array (
      ),
      'matsumoto' => 
      array (
      ),
      'minamitane' => 
      array (
      ),
      'nakatane' => 
      array (
      ),
      'nishinoomote' => 
      array (
      ),
      'satsumasendai' => 
      array (
      ),
      'soo' => 
      array (
      ),
      'tarumizu' => 
      array (
      ),
      'yusui' => 
      array (
      ),
    ),
    'kanagawa' => 
    array (
      'aikawa' => 
      array (
      ),
      'atsugi' => 
      array (
      ),
      'ayase' => 
      array (
      ),
      'chigasaki' => 
      array (
      ),
      'ebina' => 
      array (
      ),
      'fujisawa' => 
      array (
      ),
      'hadano' => 
      array (
      ),
      'hakone' => 
      array (
      ),
      'hiratsuka' => 
      array (
      ),
      'isehara' => 
      array (
      ),
      'kaisei' => 
      array (
      ),
      'kamakura' => 
      array (
      ),
      'kiyokawa' => 
      array (
      ),
      'matsuda' => 
      array (
      ),
      'minamiashigara' => 
      array (
      ),
      'miura' => 
      array (
      ),
      'nakai' => 
      array (
      ),
      'ninomiya' => 
      array (
      ),
      'odawara' => 
      array (
      ),
      'oi' => 
      array (
      ),
      'oiso' => 
      array (
      ),
      'sagamihara' => 
      array (
      ),
      'samukawa' => 
      array (
      ),
      'tsukui' => 
      array (
      ),
      'yamakita' => 
      array (
      ),
      'yamato' => 
      array (
      ),
      'yokosuka' => 
      array (
      ),
      'yugawara' => 
      array (
      ),
      'zama' => 
      array (
      ),
      'zushi' => 
      array (
      ),
    ),
    'kochi' => 
    array (
      'aki' => 
      array (
      ),
      'geisei' => 
      array (
      ),
      'hidaka' => 
      array (
      ),
      'higashitsuno' => 
      array (
      ),
      'ino' => 
      array (
      ),
      'kagami' => 
      array (
      ),
      'kami' => 
      array (
      ),
      'kitagawa' => 
      array (
      ),
      'kochi' => 
      array (
      ),
      'mihara' => 
      array (
      ),
      'motoyama' => 
      array (
      ),
      'muroto' => 
      array (
      ),
      'nahari' => 
      array (
      ),
      'nakamura' => 
      array (
      ),
      'nankoku' => 
      array (
      ),
      'nishitosa' => 
      array (
      ),
      'niyodogawa' => 
      array (
      ),
      'ochi' => 
      array (
      ),
      'okawa' => 
      array (
      ),
      'otoyo' => 
      array (
      ),
      'otsuki' => 
      array (
      ),
      'sakawa' => 
      array (
      ),
      'sukumo' => 
      array (
      ),
      'susaki' => 
      array (
      ),
      'tosa' => 
      array (
      ),
      'tosashimizu' => 
      array (
      ),
      'toyo' => 
      array (
      ),
      'tsuno' => 
      array (
      ),
      'umaji' => 
      array (
      ),
      'yasuda' => 
      array (
      ),
      'yusuhara' => 
      array (
      ),
    ),
    'kumamoto' => 
    array (
      'amakusa' => 
      array (
      ),
      'arao' => 
      array (
      ),
      'aso' => 
      array (
      ),
      'choyo' => 
      array (
      ),
      'gyokuto' => 
      array (
      ),
      'hitoyoshi' => 
      array (
      ),
      'kamiamakusa' => 
      array (
      ),
      'kashima' => 
      array (
      ),
      'kikuchi' => 
      array (
      ),
      'kosa' => 
      array (
      ),
      'kumamoto' => 
      array (
      ),
      'mashiki' => 
      array (
      ),
      'mifune' => 
      array (
      ),
      'minamata' => 
      array (
      ),
      'minamioguni' => 
      array (
      ),
      'nagasu' => 
      array (
      ),
      'nishihara' => 
      array (
      ),
      'oguni' => 
      array (
      ),
      'ozu' => 
      array (
      ),
      'sumoto' => 
      array (
      ),
      'takamori' => 
      array (
      ),
      'uki' => 
      array (
      ),
      'uto' => 
      array (
      ),
      'yamaga' => 
      array (
      ),
      'yamato' => 
      array (
      ),
      'yatsushiro' => 
      array (
      ),
    ),
    'kyoto' => 
    array (
      'ayabe' => 
      array (
      ),
      'fukuchiyama' => 
      array (
      ),
      'higashiyama' => 
      array (
      ),
      'ide' => 
      array (
      ),
      'ine' => 
      array (
      ),
      'joyo' => 
      array (
      ),
      'kameoka' => 
      array (
      ),
      'kamo' => 
      array (
      ),
      'kita' => 
      array (
      ),
      'kizu' => 
      array (
      ),
      'kumiyama' => 
      array (
      ),
      'kyotamba' => 
      array (
      ),
      'kyotanabe' => 
      array (
      ),
      'kyotango' => 
      array (
      ),
      'maizuru' => 
      array (
      ),
      'minami' => 
      array (
      ),
      'minamiyamashiro' => 
      array (
      ),
      'miyazu' => 
      array (
      ),
      'muko' => 
      array (
      ),
      'nagaokakyo' => 
      array (
      ),
      'nakagyo' => 
      array (
      ),
      'nantan' => 
      array (
      ),
      'oyamazaki' => 
      array (
      ),
      'sakyo' => 
      array (
      ),
      'seika' => 
      array (
      ),
      'tanabe' => 
      array (
      ),
      'uji' => 
      array (
      ),
      'ujitawara' => 
      array (
      ),
      'wazuka' => 
      array (
      ),
      'yamashina' => 
      array (
      ),
      'yawata' => 
      array (
      ),
    ),
    'mie' => 
    array (
      'asahi' => 
      array (
      ),
      'inabe' => 
      array (
      ),
      'ise' => 
      array (
      ),
      'kameyama' => 
      array (
      ),
      'kawagoe' => 
      array (
      ),
      'kiho' => 
      array (
      ),
      'kisosaki' => 
      array (
      ),
      'kiwa' => 
      array (
      ),
      'komono' => 
      array (
      ),
      'kumano' => 
      array (
      ),
      'kuwana' => 
      array (
      ),
      'matsusaka' => 
      array (
      ),
      'meiwa' => 
      array (
      ),
      'mihama' => 
      array (
      ),
      'minamiise' => 
      array (
      ),
      'misugi' => 
      array (
      ),
      'miyama' => 
      array (
      ),
      'nabari' => 
      array (
      ),
      'shima' => 
      array (
      ),
      'suzuka' => 
      array (
      ),
      'tado' => 
      array (
      ),
      'taiki' => 
      array (
      ),
      'taki' => 
      array (
      ),
      'tamaki' => 
      array (
      ),
      'toba' => 
      array (
      ),
      'tsu' => 
      array (
      ),
      'udono' => 
      array (
      ),
      'ureshino' => 
      array (
      ),
      'watarai' => 
      array (
      ),
      'yokkaichi' => 
      array (
      ),
    ),
    'miyagi' => 
    array (
      'furukawa' => 
      array (
      ),
      'higashimatsushima' => 
      array (
      ),
      'ishinomaki' => 
      array (
      ),
      'iwanuma' => 
      array (
      ),
      'kakuda' => 
      array (
      ),
      'kami' => 
      array (
      ),
      'kawasaki' => 
      array (
      ),
      'kesennuma' => 
      array (
      ),
      'marumori' => 
      array (
      ),
      'matsushima' => 
      array (
      ),
      'minamisanriku' => 
      array (
      ),
      'misato' => 
      array (
      ),
      'murata' => 
      array (
      ),
      'natori' => 
      array (
      ),
      'ogawara' => 
      array (
      ),
      'ohira' => 
      array (
      ),
      'onagawa' => 
      array (
      ),
      'osaki' => 
      array (
      ),
      'rifu' => 
      array (
      ),
      'semine' => 
      array (
      ),
      'shibata' => 
      array (
      ),
      'shichikashuku' => 
      array (
      ),
      'shikama' => 
      array (
      ),
      'shiogama' => 
      array (
      ),
      'shiroishi' => 
      array (
      ),
      'tagajo' => 
      array (
      ),
      'taiwa' => 
      array (
      ),
      'tome' => 
      array (
      ),
      'tomiya' => 
      array (
      ),
      'wakuya' => 
      array (
      ),
      'watari' => 
      array (
      ),
      'yamamoto' => 
      array (
      ),
      'zao' => 
      array (
      ),
    ),
    'miyazaki' => 
    array (
      'aya' => 
      array (
      ),
      'ebino' => 
      array (
      ),
      'gokase' => 
      array (
      ),
      'hyuga' => 
      array (
      ),
      'kadogawa' => 
      array (
      ),
      'kawaminami' => 
      array (
      ),
      'kijo' => 
      array (
      ),
      'kitagawa' => 
      array (
      ),
      'kitakata' => 
      array (
      ),
      'kitaura' => 
      array (
      ),
      'kobayashi' => 
      array (
      ),
      'kunitomi' => 
      array (
      ),
      'kushima' => 
      array (
      ),
      'mimata' => 
      array (
      ),
      'miyakonojo' => 
      array (
      ),
      'miyazaki' => 
      array (
      ),
      'morotsuka' => 
      array (
      ),
      'nichinan' => 
      array (
      ),
      'nishimera' => 
      array (
      ),
      'nobeoka' => 
      array (
      ),
      'saito' => 
      array (
      ),
      'shiiba' => 
      array (
      ),
      'shintomi' => 
      array (
      ),
      'takaharu' => 
      array (
      ),
      'takanabe' => 
      array (
      ),
      'takazaki' => 
      array (
      ),
      'tsuno' => 
      array (
      ),
    ),
    'nagano' => 
    array (
      'achi' => 
      array (
      ),
      'agematsu' => 
      array (
      ),
      'anan' => 
      array (
      ),
      'aoki' => 
      array (
      ),
      'asahi' => 
      array (
      ),
      'azumino' => 
      array (
      ),
      'chikuhoku' => 
      array (
      ),
      'chikuma' => 
      array (
      ),
      'chino' => 
      array (
      ),
      'fujimi' => 
      array (
      ),
      'hakuba' => 
      array (
      ),
      'hara' => 
      array (
      ),
      'hiraya' => 
      array (
      ),
      'iida' => 
      array (
      ),
      'iijima' => 
      array (
      ),
      'iiyama' => 
      array (
      ),
      'iizuna' => 
      array (
      ),
      'ikeda' => 
      array (
      ),
      'ikusaka' => 
      array (
      ),
      'ina' => 
      array (
      ),
      'karuizawa' => 
      array (
      ),
      'kawakami' => 
      array (
      ),
      'kiso' => 
      array (
      ),
      'kisofukushima' => 
      array (
      ),
      'kitaaiki' => 
      array (
      ),
      'komagane' => 
      array (
      ),
      'komoro' => 
      array (
      ),
      'matsukawa' => 
      array (
      ),
      'matsumoto' => 
      array (
      ),
      'miasa' => 
      array (
      ),
      'minamiaiki' => 
      array (
      ),
      'minamimaki' => 
      array (
      ),
      'minamiminowa' => 
      array (
      ),
      'minowa' => 
      array (
      ),
      'miyada' => 
      array (
      ),
      'miyota' => 
      array (
      ),
      'mochizuki' => 
      array (
      ),
      'nagano' => 
      array (
      ),
      'nagawa' => 
      array (
      ),
      'nagiso' => 
      array (
      ),
      'nakagawa' => 
      array (
      ),
      'nakano' => 
      array (
      ),
      'nozawaonsen' => 
      array (
      ),
      'obuse' => 
      array (
      ),
      'ogawa' => 
      array (
      ),
      'okaya' => 
      array (
      ),
      'omachi' => 
      array (
      ),
      'omi' => 
      array (
      ),
      'ookuwa' => 
      array (
      ),
      'ooshika' => 
      array (
      ),
      'otaki' => 
      array (
      ),
      'otari' => 
      array (
      ),
      'sakae' => 
      array (
      ),
      'sakaki' => 
      array (
      ),
      'saku' => 
      array (
      ),
      'sakuho' => 
      array (
      ),
      'shimosuwa' => 
      array (
      ),
      'shinanomachi' => 
      array (
      ),
      'shiojiri' => 
      array (
      ),
      'suwa' => 
      array (
      ),
      'suzaka' => 
      array (
      ),
      'takagi' => 
      array (
      ),
      'takamori' => 
      array (
      ),
      'takayama' => 
      array (
      ),
      'tateshina' => 
      array (
      ),
      'tatsuno' => 
      array (
      ),
      'togakushi' => 
      array (
      ),
      'togura' => 
      array (
      ),
      'tomi' => 
      array (
      ),
      'ueda' => 
      array (
      ),
      'wada' => 
      array (
      ),
      'yamagata' => 
      array (
      ),
      'yamanouchi' => 
      array (
      ),
      'yasaka' => 
      array (
      ),
      'yasuoka' => 
      array (
      ),
    ),
    'nagasaki' => 
    array (
      'chijiwa' => 
      array (
      ),
      'futsu' => 
      array (
      ),
      'goto' => 
      array (
      ),
      'hasami' => 
      array (
      ),
      'hirado' => 
      array (
      ),
      'iki' => 
      array (
      ),
      'isahaya' => 
      array (
      ),
      'kawatana' => 
      array (
      ),
      'kuchinotsu' => 
      array (
      ),
      'matsuura' => 
      array (
      ),
      'nagasaki' => 
      array (
      ),
      'obama' => 
      array (
      ),
      'omura' => 
      array (
      ),
      'oseto' => 
      array (
      ),
      'saikai' => 
      array (
      ),
      'sasebo' => 
      array (
      ),
      'seihi' => 
      array (
      ),
      'shimabara' => 
      array (
      ),
      'shinkamigoto' => 
      array (
      ),
      'togitsu' => 
      array (
      ),
      'tsushima' => 
      array (
      ),
      'unzen' => 
      array (
      ),
    ),
    'nara' => 
    array (
      'ando' => 
      array (
      ),
      'gose' => 
      array (
      ),
      'heguri' => 
      array (
      ),
      'higashiyoshino' => 
      array (
      ),
      'ikaruga' => 
      array (
      ),
      'ikoma' => 
      array (
      ),
      'kamikitayama' => 
      array (
      ),
      'kanmaki' => 
      array (
      ),
      'kashiba' => 
      array (
      ),
      'kashihara' => 
      array (
      ),
      'katsuragi' => 
      array (
      ),
      'kawai' => 
      array (
      ),
      'kawakami' => 
      array (
      ),
      'kawanishi' => 
      array (
      ),
      'koryo' => 
      array (
      ),
      'kurotaki' => 
      array (
      ),
      'mitsue' => 
      array (
      ),
      'miyake' => 
      array (
      ),
      'nara' => 
      array (
      ),
      'nosegawa' => 
      array (
      ),
      'oji' => 
      array (
      ),
      'ouda' => 
      array (
      ),
      'oyodo' => 
      array (
      ),
      'sakurai' => 
      array (
      ),
      'sango' => 
      array (
      ),
      'shimoichi' => 
      array (
      ),
      'shimokitayama' => 
      array (
      ),
      'shinjo' => 
      array (
      ),
      'soni' => 
      array (
      ),
      'takatori' => 
      array (
      ),
      'tawaramoto' => 
      array (
      ),
      'tenkawa' => 
      array (
      ),
      'tenri' => 
      array (
      ),
      'uda' => 
      array (
      ),
      'yamatokoriyama' => 
      array (
      ),
      'yamatotakada' => 
      array (
      ),
      'yamazoe' => 
      array (
      ),
      'yoshino' => 
      array (
      ),
    ),
    'niigata' => 
    array (
      'aga' => 
      array (
      ),
      'agano' => 
      array (
      ),
      'gosen' => 
      array (
      ),
      'itoigawa' => 
      array (
      ),
      'izumozaki' => 
      array (
      ),
      'joetsu' => 
      array (
      ),
      'kamo' => 
      array (
      ),
      'kariwa' => 
      array (
      ),
      'kashiwazaki' => 
      array (
      ),
      'minamiuonuma' => 
      array (
      ),
      'mitsuke' => 
      array (
      ),
      'muika' => 
      array (
      ),
      'murakami' => 
      array (
      ),
      'myoko' => 
      array (
      ),
      'nagaoka' => 
      array (
      ),
      'niigata' => 
      array (
      ),
      'ojiya' => 
      array (
      ),
      'omi' => 
      array (
      ),
      'sado' => 
      array (
      ),
      'sanjo' => 
      array (
      ),
      'seiro' => 
      array (
      ),
      'seirou' => 
      array (
      ),
      'sekikawa' => 
      array (
      ),
      'shibata' => 
      array (
      ),
      'tagami' => 
      array (
      ),
      'tainai' => 
      array (
      ),
      'tochio' => 
      array (
      ),
      'tokamachi' => 
      array (
      ),
      'tsubame' => 
      array (
      ),
      'tsunan' => 
      array (
      ),
      'uonuma' => 
      array (
      ),
      'yahiko' => 
      array (
      ),
      'yoita' => 
      array (
      ),
      'yuzawa' => 
      array (
      ),
    ),
    'oita' => 
    array (
      'beppu' => 
      array (
      ),
      'bungoono' => 
      array (
      ),
      'bungotakada' => 
      array (
      ),
      'hasama' => 
      array (
      ),
      'hiji' => 
      array (
      ),
      'himeshima' => 
      array (
      ),
      'hita' => 
      array (
      ),
      'kamitsue' => 
      array (
      ),
      'kokonoe' => 
      array (
      ),
      'kuju' => 
      array (
      ),
      'kunisaki' => 
      array (
      ),
      'kusu' => 
      array (
      ),
      'oita' => 
      array (
      ),
      'saiki' => 
      array (
      ),
      'taketa' => 
      array (
      ),
      'tsukumi' => 
      array (
      ),
      'usa' => 
      array (
      ),
      'usuki' => 
      array (
      ),
      'yufu' => 
      array (
      ),
    ),
    'okayama' => 
    array (
      'akaiwa' => 
      array (
      ),
      'asakuchi' => 
      array (
      ),
      'bizen' => 
      array (
      ),
      'hayashima' => 
      array (
      ),
      'ibara' => 
      array (
      ),
      'kagamino' => 
      array (
      ),
      'kasaoka' => 
      array (
      ),
      'kibichuo' => 
      array (
      ),
      'kumenan' => 
      array (
      ),
      'kurashiki' => 
      array (
      ),
      'maniwa' => 
      array (
      ),
      'misaki' => 
      array (
      ),
      'nagi' => 
      array (
      ),
      'niimi' => 
      array (
      ),
      'nishiawakura' => 
      array (
      ),
      'okayama' => 
      array (
      ),
      'satosho' => 
      array (
      ),
      'setouchi' => 
      array (
      ),
      'shinjo' => 
      array (
      ),
      'shoo' => 
      array (
      ),
      'soja' => 
      array (
      ),
      'takahashi' => 
      array (
      ),
      'tamano' => 
      array (
      ),
      'tsuyama' => 
      array (
      ),
      'wake' => 
      array (
      ),
      'yakage' => 
      array (
      ),
    ),
    'okinawa' => 
    array (
      'aguni' => 
      array (
      ),
      'ginowan' => 
      array (
      ),
      'ginoza' => 
      array (
      ),
      'gushikami' => 
      array (
      ),
      'haebaru' => 
      array (
      ),
      'higashi' => 
      array (
      ),
      'hirara' => 
      array (
      ),
      'iheya' => 
      array (
      ),
      'ishigaki' => 
      array (
      ),
      'ishikawa' => 
      array (
      ),
      'itoman' => 
      array (
      ),
      'izena' => 
      array (
      ),
      'kadena' => 
      array (
      ),
      'kin' => 
      array (
      ),
      'kitadaito' => 
      array (
      ),
      'kitanakagusuku' => 
      array (
      ),
      'kumejima' => 
      array (
      ),
      'kunigami' => 
      array (
      ),
      'minamidaito' => 
      array (
      ),
      'motobu' => 
      array (
      ),
      'nago' => 
      array (
      ),
      'naha' => 
      array (
      ),
      'nakagusuku' => 
      array (
      ),
      'nakijin' => 
      array (
      ),
      'nanjo' => 
      array (
      ),
      'nishihara' => 
      array (
      ),
      'ogimi' => 
      array (
      ),
      'okinawa' => 
      array (
      ),
      'onna' => 
      array (
      ),
      'shimoji' => 
      array (
      ),
      'taketomi' => 
      array (
      ),
      'tarama' => 
      array (
      ),
      'tokashiki' => 
      array (
      ),
      'tomigusuku' => 
      array (
      ),
      'tonaki' => 
      array (
      ),
      'urasoe' => 
      array (
      ),
      'uruma' => 
      array (
      ),
      'yaese' => 
      array (
      ),
      'yomitan' => 
      array (
      ),
      'yonabaru' => 
      array (
      ),
      'yonaguni' => 
      array (
      ),
      'zamami' => 
      array (
      ),
    ),
    'osaka' => 
    array (
      'abeno' => 
      array (
      ),
      'chihayaakasaka' => 
      array (
      ),
      'chuo' => 
      array (
      ),
      'daito' => 
      array (
      ),
      'fujiidera' => 
      array (
      ),
      'habikino' => 
      array (
      ),
      'hannan' => 
      array (
      ),
      'higashiosaka' => 
      array (
      ),
      'higashisumiyoshi' => 
      array (
      ),
      'higashiyodogawa' => 
      array (
      ),
      'hirakata' => 
      array (
      ),
      'ibaraki' => 
      array (
      ),
      'ikeda' => 
      array (
      ),
      'izumi' => 
      array (
      ),
      'izumiotsu' => 
      array (
      ),
      'izumisano' => 
      array (
      ),
      'kadoma' => 
      array (
      ),
      'kaizuka' => 
      array (
      ),
      'kanan' => 
      array (
      ),
      'kashiwara' => 
      array (
      ),
      'katano' => 
      array (
      ),
      'kawachinagano' => 
      array (
      ),
      'kishiwada' => 
      array (
      ),
      'kita' => 
      array (
      ),
      'kumatori' => 
      array (
      ),
      'matsubara' => 
      array (
      ),
      'minato' => 
      array (
      ),
      'minoh' => 
      array (
      ),
      'misaki' => 
      array (
      ),
      'moriguchi' => 
      array (
      ),
      'neyagawa' => 
      array (
      ),
      'nishi' => 
      array (
      ),
      'nose' => 
      array (
      ),
      'osakasayama' => 
      array (
      ),
      'sakai' => 
      array (
      ),
      'sayama' => 
      array (
      ),
      'sennan' => 
      array (
      ),
      'settsu' => 
      array (
      ),
      'shijonawate' => 
      array (
      ),
      'shimamoto' => 
      array (
      ),
      'suita' => 
      array (
      ),
      'tadaoka' => 
      array (
      ),
      'taishi' => 
      array (
      ),
      'tajiri' => 
      array (
      ),
      'takaishi' => 
      array (
      ),
      'takatsuki' => 
      array (
      ),
      'tondabayashi' => 
      array (
      ),
      'toyonaka' => 
      array (
      ),
      'toyono' => 
      array (
      ),
      'yao' => 
      array (
      ),
    ),
    'saga' => 
    array (
      'ariake' => 
      array (
      ),
      'arita' => 
      array (
      ),
      'fukudomi' => 
      array (
      ),
      'genkai' => 
      array (
      ),
      'hamatama' => 
      array (
      ),
      'hizen' => 
      array (
      ),
      'imari' => 
      array (
      ),
      'kamimine' => 
      array (
      ),
      'kanzaki' => 
      array (
      ),
      'karatsu' => 
      array (
      ),
      'kashima' => 
      array (
      ),
      'kitagata' => 
      array (
      ),
      'kitahata' => 
      array (
      ),
      'kiyama' => 
      array (
      ),
      'kouhoku' => 
      array (
      ),
      'kyuragi' => 
      array (
      ),
      'nishiarita' => 
      array (
      ),
      'ogi' => 
      array (
      ),
      'omachi' => 
      array (
      ),
      'ouchi' => 
      array (
      ),
      'saga' => 
      array (
      ),
      'shiroishi' => 
      array (
      ),
      'taku' => 
      array (
      ),
      'tara' => 
      array (
      ),
      'tosu' => 
      array (
      ),
      'yoshinogari' => 
      array (
      ),
    ),
    'saitama' => 
    array (
      'arakawa' => 
      array (
      ),
      'asaka' => 
      array (
      ),
      'chichibu' => 
      array (
      ),
      'fujimi' => 
      array (
      ),
      'fujimino' => 
      array (
      ),
      'fukaya' => 
      array (
      ),
      'hanno' => 
      array (
      ),
      'hanyu' => 
      array (
      ),
      'hasuda' => 
      array (
      ),
      'hatogaya' => 
      array (
      ),
      'hatoyama' => 
      array (
      ),
      'hidaka' => 
      array (
      ),
      'higashichichibu' => 
      array (
      ),
      'higashimatsuyama' => 
      array (
      ),
      'honjo' => 
      array (
      ),
      'ina' => 
      array (
      ),
      'iruma' => 
      array (
      ),
      'iwatsuki' => 
      array (
      ),
      'kamiizumi' => 
      array (
      ),
      'kamikawa' => 
      array (
      ),
      'kamisato' => 
      array (
      ),
      'kasukabe' => 
      array (
      ),
      'kawagoe' => 
      array (
      ),
      'kawaguchi' => 
      array (
      ),
      'kawajima' => 
      array (
      ),
      'kazo' => 
      array (
      ),
      'kitamoto' => 
      array (
      ),
      'koshigaya' => 
      array (
      ),
      'kounosu' => 
      array (
      ),
      'kuki' => 
      array (
      ),
      'kumagaya' => 
      array (
      ),
      'matsubushi' => 
      array (
      ),
      'minano' => 
      array (
      ),
      'misato' => 
      array (
      ),
      'miyashiro' => 
      array (
      ),
      'miyoshi' => 
      array (
      ),
      'moroyama' => 
      array (
      ),
      'nagatoro' => 
      array (
      ),
      'namegawa' => 
      array (
      ),
      'niiza' => 
      array (
      ),
      'ogano' => 
      array (
      ),
      'ogawa' => 
      array (
      ),
      'ogose' => 
      array (
      ),
      'okegawa' => 
      array (
      ),
      'omiya' => 
      array (
      ),
      'otaki' => 
      array (
      ),
      'ranzan' => 
      array (
      ),
      'ryokami' => 
      array (
      ),
      'saitama' => 
      array (
      ),
      'sakado' => 
      array (
      ),
      'satte' => 
      array (
      ),
      'sayama' => 
      array (
      ),
      'shiki' => 
      array (
      ),
      'shiraoka' => 
      array (
      ),
      'soka' => 
      array (
      ),
      'sugito' => 
      array (
      ),
      'toda' => 
      array (
      ),
      'tokigawa' => 
      array (
      ),
      'tokorozawa' => 
      array (
      ),
      'tsurugashima' => 
      array (
      ),
      'urawa' => 
      array (
      ),
      'warabi' => 
      array (
      ),
      'yashio' => 
      array (
      ),
      'yokoze' => 
      array (
      ),
      'yono' => 
      array (
      ),
      'yorii' => 
      array (
      ),
      'yoshida' => 
      array (
      ),
      'yoshikawa' => 
      array (
      ),
      'yoshimi' => 
      array (
      ),
    ),
    'shiga' => 
    array (
      'aisho' => 
      array (
      ),
      'gamo' => 
      array (
      ),
      'higashiomi' => 
      array (
      ),
      'hikone' => 
      array (
      ),
      'koka' => 
      array (
      ),
      'konan' => 
      array (
      ),
      'kosei' => 
      array (
      ),
      'koto' => 
      array (
      ),
      'kusatsu' => 
      array (
      ),
      'maibara' => 
      array (
      ),
      'moriyama' => 
      array (
      ),
      'nagahama' => 
      array (
      ),
      'nishiazai' => 
      array (
      ),
      'notogawa' => 
      array (
      ),
      'omihachiman' => 
      array (
      ),
      'otsu' => 
      array (
      ),
      'ritto' => 
      array (
      ),
      'ryuoh' => 
      array (
      ),
      'takashima' => 
      array (
      ),
      'takatsuki' => 
      array (
      ),
      'torahime' => 
      array (
      ),
      'toyosato' => 
      array (
      ),
      'yasu' => 
      array (
      ),
    ),
    'shimane' => 
    array (
      'akagi' => 
      array (
      ),
      'ama' => 
      array (
      ),
      'gotsu' => 
      array (
      ),
      'hamada' => 
      array (
      ),
      'higashiizumo' => 
      array (
      ),
      'hikawa' => 
      array (
      ),
      'hikimi' => 
      array (
      ),
      'izumo' => 
      array (
      ),
      'kakinoki' => 
      array (
      ),
      'masuda' => 
      array (
      ),
      'matsue' => 
      array (
      ),
      'misato' => 
      array (
      ),
      'nishinoshima' => 
      array (
      ),
      'ohda' => 
      array (
      ),
      'okinoshima' => 
      array (
      ),
      'okuizumo' => 
      array (
      ),
      'shimane' => 
      array (
      ),
      'tamayu' => 
      array (
      ),
      'tsuwano' => 
      array (
      ),
      'unnan' => 
      array (
      ),
      'yakumo' => 
      array (
      ),
      'yasugi' => 
      array (
      ),
      'yatsuka' => 
      array (
      ),
    ),
    'shizuoka' => 
    array (
      'arai' => 
      array (
      ),
      'atami' => 
      array (
      ),
      'fuji' => 
      array (
      ),
      'fujieda' => 
      array (
      ),
      'fujikawa' => 
      array (
      ),
      'fujinomiya' => 
      array (
      ),
      'fukuroi' => 
      array (
      ),
      'gotemba' => 
      array (
      ),
      'haibara' => 
      array (
      ),
      'hamamatsu' => 
      array (
      ),
      'higashiizu' => 
      array (
      ),
      'ito' => 
      array (
      ),
      'iwata' => 
      array (
      ),
      'izu' => 
      array (
      ),
      'izunokuni' => 
      array (
      ),
      'kakegawa' => 
      array (
      ),
      'kannami' => 
      array (
      ),
      'kawanehon' => 
      array (
      ),

      'kawazu' => 
      array (
      ),
      'kikugawa' => 
      array (
      ),
      'kosai' => 
      array (
      ),
      'makinohara' => 
      array (
      ),
      'matsuzaki' => 
      array (
      ),
      'minamiizu' => 
      array (
      ),
      'mishima' => 
      array (
      ),
      'morimachi' => 
      array (
      ),
      'nishiizu' => 
      array (
      ),
      'numazu' => 
      array (
      ),
      'omaezaki' => 
      array (
      ),
      'shimada' => 
      array (
      ),
      'shimizu' => 
      array (
      ),
      'shimoda' => 
      array (
      ),
      'shizuoka' => 
      array (
      ),
      'susono' => 
      array (
      ),
      'yaizu' => 
      array (
      ),
      'yoshida' => 
      array (
      ),
    ),
    'tochigi' => 
    array (
      'ashikaga' => 
      array (
      ),
      'bato' => 
      array (
      ),
      'haga' => 
      array (
      ),
      'ichikai' => 
      array (
      ),
      'iwafune' => 
      array (
      ),
      'kaminokawa' => 
      array (
      ),
      'kanuma' => 
      array (
      ),
      'karasuyama' => 
      array (
      ),
      'kuroiso' => 
      array (
      ),
      'mashiko' => 
      array (
      ),
      'mibu' => 
      array (
      ),
      'moka' => 
      array (
      ),
      'motegi' => 
      array (
      ),
      'nasu' => 
      array (
      ),
      'nasushiobara' => 
      array (
      ),
      'nikko' => 
      array (
      ),
      'nishikata' => 
      array (
      ),
      'nogi' => 
      array (
      ),
      'ohira' => 
      array (
      ),
      'ohtawara' => 
      array (
      ),
      'oyama' => 
      array (
      ),
      'sakura' => 
      array (
      ),
      'sano' => 
      array (
      ),
      'shimotsuke' => 
      array (
      ),
      'shioya' => 
      array (
      ),
      'takanezawa' => 
      array (
      ),
      'tochigi' => 
      array (
      ),
      'tsuga' => 
      array (
      ),
      'ujiie' => 
      array (
      ),
      'utsunomiya' => 
      array (
      ),
      'yaita' => 
      array (
      ),
    ),
    'tokushima' => 
    array (
      'aizumi' => 
      array (
      ),
      'anan' => 
      array (
      ),
      'ichiba' => 
      array (
      ),
      'itano' => 
      array (
      ),
      'kainan' => 
      array (
      ),
      'komatsushima' => 
      array (
      ),
      'matsushige' => 
      array (
      ),
      'mima' => 
      array (
      ),
      'minami' => 
      array (
      ),
      'miyoshi' => 
      array (
      ),
      'mugi' => 
      array (
      ),
      'nakagawa' => 
      array (
      ),
      'naruto' => 
      array (
      ),
      'sanagochi' => 
      array (
      ),
      'shishikui' => 
      array (
      ),
      'tokushima' => 
      array (
      ),
      'wajiki' => 
      array (
      ),
    ),
    'tokyo' => 
    array (
      'adachi' => 
      array (
      ),
      'akiruno' => 
      array (
      ),
      'akishima' => 
      array (
      ),
      'aogashima' => 
      array (
      ),
      'arakawa' => 
      array (
      ),
      'bunkyo' => 
      array (
      ),
      'chiyoda' => 
      array (
      ),
      'chofu' => 
      array (
      ),
      'chuo' => 
      array (
      ),
      'edogawa' => 
      array (
      ),
      'fuchu' => 
      array (
      ),
      'fussa' => 
      array (
      ),
      'hachijo' => 
      array (
      ),
      'hachioji' => 
      array (
      ),
      'hamura' => 
      array (
      ),
      'higashikurume' => 
      array (
      ),
      'higashimurayama' => 
      array (
      ),
      'higashiyamato' => 
      array (
      ),
      'hino' => 
      array (
      ),
      'hinode' => 
      array (
      ),
      'hinohara' => 
      array (
      ),
      'inagi' => 
      array (
      ),
      'itabashi' => 
      array (
      ),
      'katsushika' => 
      array (
      ),
      'kita' => 
      array (
      ),
      'kiyose' => 
      array (
      ),
      'kodaira' => 
      array (
      ),
      'koganei' => 
      array (
      ),
      'kokubunji' => 
      array (
      ),
      'komae' => 
      array (
      ),
      'koto' => 
      array (
      ),
      'kouzushima' => 
      array (
      ),
      'kunitachi' => 
      array (
      ),
      'machida' => 
      array (
      ),
      'meguro' => 
      array (
      ),
      'minato' => 
      array (
      ),
      'mitaka' => 
      array (
      ),
      'mizuho' => 
      array (
      ),
      'musashimurayama' => 
      array (
      ),
      'musashino' => 
      array (
      ),
      'nakano' => 
      array (
      ),
      'nerima' => 
      array (
      ),
      'ogasawara' => 
      array (
      ),
      'okutama' => 
      array (
      ),
      'ome' => 
      array (
      ),
      'oshima' => 
      array (
      ),
      'ota' => 
      array (
      ),
      'setagaya' => 
      array (
      ),
      'shibuya' => 
      array (
      ),
      'shinagawa' => 
      array (
      ),
      'shinjuku' => 
      array (
      ),
      'suginami' => 
      array (
      ),
      'sumida' => 
      array (
      ),
      'tachikawa' => 
      array (
      ),
      'taito' => 
      array (
      ),
      'tama' => 
      array (
      ),
      'toshima' => 
      array (
      ),
    ),
    'tottori' => 
    array (
      'chizu' => 
      array (
      ),
      'hino' => 
      array (
      ),
      'kawahara' => 
      array (
      ),
      'koge' => 
      array (
      ),
      'kotoura' => 
      array (
      ),
      'misasa' => 
      array (
      ),
      'nanbu' => 
      array (
      ),
      'nichinan' => 
      array (
      ),
      'sakaiminato' => 
      array (
      ),
      'tottori' => 
      array (
      ),
      'wakasa' => 
      array (
      ),
      'yazu' => 
      array (
      ),
      'yonago' => 
      array (
      ),
    ),
    'toyama' => 
    array (
      'asahi' => 
      array (
      ),
      'fuchu' => 
      array (
      ),
      'fukumitsu' => 
      array (
      ),
      'funahashi' => 
      array (
      ),
      'himi' => 
      array (
      ),
      'imizu' => 
      array (
      ),
      'inami' => 
      array (
      ),
      'johana' => 
      array (
      ),
      'kamiichi' => 
      array (
      ),
      'kurobe' => 
      array (
      ),
      'nakaniikawa' => 
      array (
      ),
      'namerikawa' => 
      array (
      ),
      'nanto' => 
      array (
      ),
      'nyuzen' => 
      array (
      ),
      'oyabe' => 
      array (
      ),
      'taira' => 
      array (
      ),
      'takaoka' => 
      array (
      ),
      'tateyama' => 
      array (
      ),
      'toga' => 
      array (
      ),
      'tonami' => 
      array (
      ),
      'toyama' => 
      array (
      ),
      'unazuki' => 
      array (
      ),
      'uozu' => 
      array (
      ),
      'yamada' => 
      array (
      ),
    ),
    'wakayama' => 
    array (
      'arida' => 
      array (
      ),
      'aridagawa' => 
      array (
      ),
      'gobo' => 
      array (
      ),
      'hashimoto' => 
      array (
      ),
      'hidaka' => 
      array (
      ),
      'hirogawa' => 
      array (
      ),
      'inami' => 
      array (
      ),
      'iwade' => 
      array (
      ),
      'kainan' => 
      array (
      ),
      'kamitonda' => 
      array (
      ),
      'katsuragi' => 
      array (
      ),
      'kimino' => 
      array (
      ),
      'kinokawa' => 
      array (
      ),
      'kitayama' => 
      array (
      ),
      'koya' => 
      array (
      ),
      'koza' => 
      array (
      ),
      'kozagawa' => 
      array (
      ),
      'kudoyama' => 
      array (
      ),
      'kushimoto' => 
      array (
      ),
      'mihama' => 
      array (
      ),
      'misato' => 
      array (
      ),
      'nachikatsuura' => 
      array (
      ),
      'shingu' => 
      array (
      ),
      'shirahama' => 
      array (
      ),
      'taiji' => 
      array (
      ),
      'tanabe' => 
      array (
      ),
      'wakayama' => 
      array (
      ),
      'yuasa' => 
      array (
      ),
      'yura' => 
      array (
      ),
    ),
    'yamagata' => 
    array (
      'asahi' => 
      array (
      ),
      'funagata' => 
      array (
      ),
      'higashine' => 
      array (
      ),
      'iide' => 
      array (
      ),
      'kahoku' => 
      array (
      ),
      'kaminoyama' => 
      array (
      ),
      'kaneyama' => 
      array (
      ),
      'kawanishi' => 
      array (
      ),
      'mamurogawa' => 
      array (
      ),
      'mikawa' => 
      array (
      ),
      'murayama' => 
      array (
      ),
      'nagai' => 
      array (
      ),
      'nakayama' => 
      array (
      ),
      'nanyo' => 
      array (
      ),
      'nishikawa' => 
      array (
      ),
      'obanazawa' => 
      array (
      ),
      'oe' => 
      array (
      ),
      'oguni' => 
      array (
      ),
      'ohkura' => 
      array (
      ),
      'oishida' => 
      array (
      ),
      'sagae' => 
      array (
      ),
      'sakata' => 
      array (
      ),
      'sakegawa' => 
      array (
      ),
      'shinjo' => 
      array (
      ),
      'shirataka' => 
      array (
      ),
      'shonai' => 
      array (
      ),
      'takahata' => 
      array (
      ),
      'tendo' => 
      array (
      ),
      'tozawa' => 
      array (
      ),
      'tsuruoka' => 
      array (
      ),
      'yamagata' => 
      array (
      ),
      'yamanobe' => 
      array (
      ),
      'yonezawa' => 
      array (
      ),
      'yuza' => 
      array (
      ),
    ),
    'yamaguchi' => 
    array (
      'abu' => 
      array (
      ),
      'hagi' => 
      array (
      ),
      'hikari' => 
      array (
      ),
      'hofu' => 
      array (
      ),
      'iwakuni' => 
      array (
      ),
      'kudamatsu' => 
      array (
      ),
      'mitou' => 
      array (
      ),
      'nagato' => 
      array (
      ),
      'oshima' => 
      array (
      ),
      'shimonoseki' => 
      array (
      ),
      'shunan' => 
      array (
      ),
      'tabuse' => 
      array (
      ),
      'tokuyama' => 
      array (
      ),
      'toyota' => 
      array (
      ),
      'ube' => 
      array (
      ),
      'yuu' => 
      array (
      ),
    ),
    'yamanashi' => 
    array (
      'chuo' => 
      array (
      ),
      'doshi' => 
      array (
      ),
      'fuefuki' => 
      array (
      ),
      'fujikawa' => 
      array (
      ),
      'fujikawaguchiko' => 
      array (
      ),
      'fujiyoshida' => 
      array (
      ),
      'hayakawa' => 
      array (
      ),
      'hokuto' => 
      array (
      ),
      'ichikawamisato' => 
      array (
      ),
      'kai' => 
      array (
      ),
      'kofu' => 
      array (
      ),
      'koshu' => 
      array (
      ),
      'kosuge' => 
      array (
      ),
      'minami-alps' => 
      array (
      ),
      'minobu' => 
      array (
      ),
      'nakamichi' => 
      array (
      ),
      'nanbu' => 
      array (
      ),
      'narusawa' => 
      array (
      ),
      'nirasaki' => 
      array (
      ),
      'nishikatsura' => 
      array (
      ),
      'oshino' => 
      array (
      ),
      'otsuki' => 
      array (
      ),
      'showa' => 
      array (
      ),
      'tabayama' => 
      array (
      ),
      'tsuru' => 
      array (
      ),
      'uenohara' => 
      array (
      ),
      'yamanakako' => 
      array (
      ),
      'yamanashi' => 
      array (
      ),
    ),
    'xn--4pvxs' => 
    array (
    ),
    'xn--vgu402c' => 
    array (
    ),
    'xn--c3s14m' => 
    array (
    ),
    'xn--f6qx53a' => 
    array (
    ),
    'xn--8pvr4u' => 
    array (
    ),
    'xn--uist22h' => 
    array (
    ),
    'xn--djrs72d6uy' => 
    array (
    ),
    'xn--mkru45i' => 
    array (
    ),
    'xn--0trq7p7nn' => 
    array (
    ),
    'xn--8ltr62k' => 
    array (
    ),
    'xn--2m4a15e' => 
    array (
    ),
    'xn--efvn9s' => 
    array (
    ),
    'xn--32vp30h' => 
    array (
    ),
    'xn--4it797k' => 
    array (
    ),
    'xn--1lqs71d' => 
    array (
    ),
    'xn--5rtp49c' => 
    array (
    ),
    'xn--5js045d' => 
    array (
    ),
    'xn--ehqz56n' => 
    array (
    ),
    'xn--1lqs03n' => 
    array (
    ),
    'xn--qqqt11m' => 
    array (
    ),
    'xn--kbrq7o' => 
    array (
    ),
    'xn--pssu33l' => 
    array (
    ),
    'xn--ntsq17g' => 
    array (
    ),
    'xn--uisz3g' => 
    array (
    ),
    'xn--6btw5a' => 
    array (
    ),
    'xn--1ctwo' => 
    array (
    ),
    'xn--6orx2r' => 
    array (
    ),
    'xn--rht61e' => 
    array (
    ),
    'xn--rht27z' => 
    array (
    ),
    'xn--djty4k' => 
    array (
    ),
    'xn--nit225k' => 
    array (
    ),
    'xn--rht3d' => 
    array (
    ),
    'xn--klty5x' => 
    array (
    ),
    'xn--kltx9a' => 
    array (
    ),
    'xn--kltp7d' => 
    array (
    ),
    'xn--uuwu58a' => 
    array (
    ),
    'xn--zbx025d' => 
    array (
    ),
    'xn--ntso0iqx3a' => 
    array (
    ),
    'xn--elqq16h' => 
    array (
    ),
    'xn--4it168d' => 
    array (
    ),
    'xn--klt787d' => 
    array (
    ),
    'xn--rny31h' => 
    array (
    ),
    'xn--7t0a264c' => 
    array (
    ),
    'xn--5rtq34k' => 
    array (
    ),
    'xn--k7yn95e' => 
    array (
    ),
    'xn--tor131o' => 
    array (
    ),
    'xn--d5qv7z876c' => 
    array (
    ),
    'kawasaki' => 
    array (
      '*' => 
      array (
      ),
      'city' => 
      array (
        '!' => '',
      ),
    ),
    'kitakyushu' => 
    array (
      '*' => 
      array (
      ),
      'city' => 
      array (
        '!' => '',
      ),
    ),
    'kobe' => 
    array (
      '*' => 
      array (
      ),
      'city' => 
      array (
        '!' => '',
      ),
    ),
    'nagoya' => 
    array (
      '*' => 
      array (
      ),
      'city' => 
      array (
        '!' => '',
      ),
    ),
    'sapporo' => 
    array (
      '*' => 
      array (
      ),
      'city' => 
      array (
        '!' => '',
      ),
    ),
    'sendai' => 
    array (
      '*' => 
      array (
      ),
      'city' => 
      array (
        '!' => '',
      ),
    ),
    'yokohama' => 
    array (
      '*' => 
      array (
      ),
      'city' => 
      array (
        '!' => '',
      ),
    ),
    'blogspot' => 
    array (
    ),
  ),
  'ke' => 
  array (
    '*' => 
    array (
    ),
  ),
  'kg' => 
  array (
    'org' => 
    array (
    ),
    'net' => 
    array (
    ),
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'mil' => 
    array (
    ),
  ),
  'kh' => 
  array (
    '*' => 
    array (
    ),
  ),
  'ki' => 
  array (
    'edu' => 
    array (
    ),
    'biz' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'info' => 
    array (
    ),
    'com' => 
    array (
    ),
  ),
  'km' => 
  array (
    'org' => 
    array (
    ),
    'nom' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'prd' => 
    array (
    ),
    'tm' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'ass' => 
    array (
    ),
    'com' => 
    array (
    ),
    'coop' => 
    array (
    ),
    'asso' => 
    array (
    ),
    'presse' => 
    array (
    ),
    'medecin' => 
    array (
    ),
    'notaires' => 
    array (
    ),
    'pharmaciens' => 
    array (
    ),
    'veterinaire' => 
    array (
    ),
    'gouv' => 
    array (
    ),
  ),
  'kn' => 
  array (
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
  ),
  'kp' => 
  array (
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'org' => 
    array (
    ),
    'rep' => 
    array (
    ),
    'tra' => 
    array (
    ),
  ),
  'kr' => 
  array (
    'ac' => 
    array (
    ),
    'co' => 
    array (
    ),
    'es' => 
    array (
    ),
    'go' => 
    array (
    ),
    'hs' => 
    array (
    ),
    'kg' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'ms' => 
    array (
    ),
    'ne' => 
    array (
    ),
    'or' => 
    array (
    ),
    'pe' => 
    array (
    ),
    're' => 
    array (
    ),
    'sc' => 
    array (
    ),
    'busan' => 
    array (
    ),
    'chungbuk' => 
    array (
    ),
    'chungnam' => 
    array (
    ),
    'daegu' => 
    array (
    ),
    'daejeon' => 
    array (
    ),
    'gangwon' => 
    array (
    ),
    'gwangju' => 
    array (
    ),
    'gyeongbuk' => 
    array (
    ),
    'gyeonggi' => 
    array (
    ),
    'gyeongnam' => 
    array (
    ),
    'incheon' => 
    array (
    ),
    'jeju' => 
    array (
    ),
    'jeonbuk' => 
    array (
    ),
    'jeonnam' => 
    array (
    ),
    'seoul' => 
    array (
    ),
    'ulsan' => 
    array (
    ),
    'blogspot' => 
    array (
    ),
  ),
  'kw' => 
  array (
    '*' => 
    array (
    ),
  ),
  'ky' => 
  array (
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'com' => 
    array (
    ),
    'org' => 
    array (
    ),
    'net' => 
    array (
    ),
  ),
  'kz' => 
  array (
    'org' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'net' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'com' => 
    array (
    ),
  ),
  'la' => 
  array (
    'int' => 
    array (
    ),
    'net' => 
    array (
    ),
    'info' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'per' => 
    array (
    ),
    'com' => 
    array (
    ),
    'org' => 
    array (
    ),
    'c' => 
    array (
    ),
  ),
  'lb' => 
  array (
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
  ),
  'lc' => 
  array (
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
    'co' => 
    array (
    ),
    'org' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
  ),
  'li' => 
  array (
  ),
  'lk' => 
  array (
    'gov' => 
    array (
    ),
    'sch' => 
    array (
    ),
    'net' => 
    array (
    ),
    'int' => 
    array (
    ),
    'com' => 
    array (
    ),
    'org' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'ngo' => 
    array (
    ),
    'soc' => 
    array (
    ),
    'web' => 
    array (
    ),
    'ltd' => 
    array (
    ),
    'assn' => 
    array (
    ),
    'grp' => 
    array (
    ),
    'hotel' => 
    array (
    ),
  ),
  'lr' => 
  array (
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'org' => 
    array (
    ),
    'net' => 
    array (
    ),
  ),
  'ls' => 
  array (
    'co' => 
    array (
    ),
    'org' => 
    array (
    ),
  ),
  'lt' => 
  array (
    'gov' => 
    array (
    ),
  ),
  'lu' => 
  array (
  ),
  'lv' => 
  array (
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'org' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'id' => 
    array (
    ),
    'net' => 
    array (
    ),
    'asn' => 
    array (
    ),
    'conf' => 
    array (
    ),
  ),
  'ly' => 
  array (
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'plc' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'sch' => 
    array (
    ),
    'med' => 
    array (
    ),
    'org' => 
    array (
    ),
    'id' => 
    array (
    ),
  ),
  'ma' => 
  array (
    'co' => 
    array (
    ),
    'net' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'org' => 
    array (
    ),
    'ac' => 
    array (
    ),
    'press' => 
    array (
    ),
  ),
  'mc' => 
  array (
    'tm' => 
    array (
    ),
    'asso' => 
    array (
    ),
  ),
  'md' => 
  array (
  ),
  'me' => 
  array (
    'co' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'ac' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'its' => 
    array (
    ),
    'priv' => 
    array (
    ),
  ),
  'mg' => 
  array (
    'org' => 
    array (
    ),
    'nom' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'prd' => 
    array (
    ),
    'tm' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'com' => 
    array (
    ),
  ),
  'mh' => 
  array (
  ),
  'mil' => 
  array (
  ),
  'mk' => 
  array (
    'com' => 
    array (
    ),
    'org' => 
    array (
    ),
    'net' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'inf' => 
    array (
    ),
    'name' => 
    array (
    ),
  ),
  'ml' => 
  array (
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gouv' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'presse' => 
    array (
    ),
  ),
  'mm' => 
  array (
    '*' => 
    array (
    ),
  ),
  'mn' => 
  array (
    'gov' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'org' => 
    array (
    ),
    'nyc' => 
    array (
    ),
  ),
  'mo' => 
  array (
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
  ),
  'mobi' => 
  array (
  ),
  'mp' => 
  array (
  ),
  'mq' => 
  array (
  ),
  'mr' => 
  array (
    'gov' => 
    array (
    ),
    'blogspot' => 
    array (
    ),
  ),
  'ms' => 
  array (
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
  ),
  'mt' => 
  array (
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
  ),
  'mu' => 
  array (
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'ac' => 
    array (
    ),
    'co' => 
    array (
    ),
    'or' => 
    array (
    ),
  ),
  'museum' => 
  array (
    'academy' => 
    array (
    ),
    'agriculture' => 
    array (
    ),
    'air' => 
    array (
    ),
    'airguard' => 
    array (
    ),
    'alabama' => 
    array (
    ),
    'alaska' => 
    array (
    ),
    'amber' => 
    array (
    ),
    'ambulance' => 
    array (
    ),
    'american' => 
    array (
    ),
    'americana' => 
    array (
    ),
    'americanantiques' => 
    array (
    ),
    'americanart' => 
    array (
    ),
    'amsterdam' => 
    array (
    ),
    'and' => 
    array (
    ),
    'annefrank' => 
    array (
    ),
    'anthro' => 
    array (
    ),
    'anthropology' => 
    array (
    ),
    'antiques' => 
    array (
    ),
    'aquarium' => 
    array (
    ),
    'arboretum' => 
    array (
    ),
    'archaeological' => 
    array (
    ),
    'archaeology' => 
    array (
    ),
    'architecture' => 
    array (
    ),
    'art' => 
    array (
    ),
    'artanddesign' => 
    array (
    ),
    'artcenter' => 
    array (
    ),
    'artdeco' => 
    array (
    ),
    'arteducation' => 
    array (
    ),
    'artgallery' => 
    array (
    ),
    'arts' => 
    array (
    ),
    'artsandcrafts' => 
    array (
    ),
    'asmatart' => 
    array (
    ),
    'assassination' => 
    array (
    ),
    'assisi' => 
    array (
    ),
    'association' => 
    array (
    ),
    'astronomy' => 
    array (
    ),
    'atlanta' => 
    array (
    ),
    'austin' => 
    array (
    ),
    'australia' => 
    array (
    ),
    'automotive' => 
    array (
    ),
    'aviation' => 
    array (
    ),
    'axis' => 
    array (
    ),
    'badajoz' => 
    array (
    ),
    'baghdad' => 
    array (
    ),
    'bahn' => 
    array (
    ),
    'bale' => 
    array (
    ),
    'baltimore' => 
    array (
    ),
    'barcelona' => 
    array (
    ),
    'baseball' => 
    array (
    ),
    'basel' => 
    array (
    ),
    'baths' => 
    array (
    ),
    'bauern' => 
    array (
    ),
    'beauxarts' => 
    array (
    ),
    'beeldengeluid' => 
    array (
    ),
    'bellevue' => 
    array (
    ),
    'bergbau' => 
    array (
    ),
    'berkeley' => 
    array (
    ),
    'berlin' => 
    array (
    ),
    'bern' => 
    array (
    ),
    'bible' => 
    array (
    ),
    'bilbao' => 
    array (
    ),
    'bill' => 
    array (
    ),
    'birdart' => 
    array (
    ),
    'birthplace' => 
    array (
    ),
    'bonn' => 
    array (
    ),
    'boston' => 
    array (
    ),
    'botanical' => 
    array (
    ),
    'botanicalgarden' => 
    array (
    ),
    'botanicgarden' => 
    array (
    ),
    'botany' => 
    array (
    ),
    'brandywinevalley' => 
    array (
    ),
    'brasil' => 
    array (
    ),
    'bristol' => 
    array (
    ),
    'british' => 
    array (
    ),
    'britishcolumbia' => 
    array (
    ),
    'broadcast' => 
    array (
    ),
    'brunel' => 
    array (
    ),
    'brussel' => 
    array (
    ),
    'brussels' => 
    array (
    ),
    'bruxelles' => 
    array (
    ),
    'building' => 
    array (
    ),
    'burghof' => 
    array (
    ),
    'bus' => 
    array (
    ),
    'bushey' => 
    array (
    ),
    'cadaques' => 
    array (
    ),
    'california' => 
    array (
    ),
    'cambridge' => 
    array (
    ),
    'can' => 
    array (
    ),
    'canada' => 
    array (
    ),
    'capebreton' => 
    array (
    ),
    'carrier' => 
    array (
    ),
    'cartoonart' => 
    array (
    ),
    'casadelamoneda' => 
    array (
    ),
    'castle' => 
    array (
    ),
    'castres' => 
    array (
    ),
    'celtic' => 
    array (
    ),
    'center' => 
    array (
    ),
    'chattanooga' => 
    array (
    ),
    'cheltenham' => 
    array (
    ),
    'chesapeakebay' => 
    array (
    ),
    'chicago' => 
    array (
    ),
    'children' => 
    array (
    ),
    'childrens' => 
    array (
    ),
    'childrensgarden' => 
    array (
    ),
    'chiropractic' => 
    array (
    ),
    'chocolate' => 
    array (
    ),
    'christiansburg' => 
    array (
    ),
    'cincinnati' => 
    array (
    ),
    'cinema' => 
    array (
    ),
    'circus' => 
    array (
    ),
    'civilisation' => 
    array (
    ),
    'civilization' => 
    array (
    ),
    'civilwar' => 
    array (
    ),
    'clinton' => 
    array (
    ),
    'clock' => 
    array (
    ),
    'coal' => 
    array (
    ),
    'coastaldefence' => 
    array (
    ),
    'cody' => 
    array (
    ),
    'coldwar' => 
    array (
    ),
    'collection' => 
    array (
    ),
    'colonialwilliamsburg' => 
    array (
    ),
    'coloradoplateau' => 
    array (
    ),
    'columbia' => 
    array (
    ),
    'columbus' => 
    array (
    ),
    'communication' => 
    array (
    ),
    'communications' => 
    array (
    ),
    'community' => 
    array (
    ),
    'computer' => 
    array (
    ),
    'computerhistory' => 
    array (
    ),
    'xn--comunicaes-v6a2o' => 
    array (
    ),
    'contemporary' => 
    array (
    ),
    'contemporaryart' => 
    array (
    ),
    'convent' => 
    array (
    ),
    'copenhagen' => 
    array (
    ),
    'corporation' => 
    array (
    ),
    'xn--correios-e-telecomunicaes-ghc29a' => 
    array (
    ),
    'corvette' => 
    array (
    ),
    'costume' => 
    array (
    ),
    'countryestate' => 
    array (
    ),
    'county' => 
    array (
    ),
    'crafts' => 
    array (
    ),
    'cranbrook' => 
    array (
    ),
    'creation' => 
    array (
    ),
    'cultural' => 
    array (
    ),
    'culturalcenter' => 
    array (
    ),
    'culture' => 
    array (
    ),
    'cyber' => 
    array (
    ),
    'cymru' => 
    array (
    ),
    'dali' => 
    array (
    ),
    'dallas' => 
    array (
    ),
    'database' => 
    array (
    ),
    'ddr' => 
    array (
    ),
    'decorativearts' => 
    array (
    ),
    'delaware' => 
    array (
    ),
    'delmenhorst' => 
    array (
    ),
    'denmark' => 
    array (
    ),
    'depot' => 
    array (
    ),
    'design' => 
    array (
    ),
    'detroit' => 
    array (
    ),
    'dinosaur' => 
    array (
    ),
    'discovery' => 
    array (
    ),
    'dolls' => 
    array (
    ),
    'donostia' => 
    array (
    ),
    'durham' => 
    array (
    ),
    'eastafrica' => 
    array (
    ),
    'eastcoast' => 
    array (
    ),
    'education' => 
    array (
    ),
    'educational' => 
    array (
    ),
    'egyptian' => 
    array (
    ),
    'eisenbahn' => 
    array (
    ),
    'elburg' => 
    array (
    ),
    'elvendrell' => 
    array (
    ),
    'embroidery' => 
    array (
    ),
    'encyclopedic' => 
    array (
    ),
    'england' => 
    array (
    ),
    'entomology' => 
    array (
    ),
    'environment' => 
    array (
    ),
    'environmentalconservation' => 
    array (
    ),
    'epilepsy' => 
    array (
    ),
    'essex' => 
    array (
    ),
    'estate' => 
    array (
    ),
    'ethnology' => 
    array (
    ),
    'exeter' => 
    array (
    ),
    'exhibition' => 
    array (
    ),
    'family' => 
    array (
    ),
    'farm' => 
    array (
    ),
    'farmequipment' => 
    array (
    ),
    'farmers' => 
    array (
    ),
    'farmstead' => 
    array (
    ),
    'field' => 
    array (
    ),
    'figueres' => 
    array (
    ),
    'filatelia' => 
    array (
    ),
    'film' => 
    array (
    ),
    'fineart' => 
    array (
    ),
    'finearts' => 
    array (
    ),
    'finland' => 
    array (
    ),
    'flanders' => 
    array (
    ),
    'florida' => 
    array (
    ),
    'force' => 
    array (
    ),
    'fortmissoula' => 
    array (
    ),
    'fortworth' => 
    array (
    ),
    'foundation' => 
    array (
    ),
    'francaise' => 
    array (
    ),
    'frankfurt' => 
    array (
    ),
    'franziskaner' => 
    array (
    ),
    'freemasonry' => 
    array (
    ),
    'freiburg' => 
    array (
    ),
    'fribourg' => 
    array (
    ),
    'frog' => 
    array (
    ),
    'fundacio' => 
    array (
    ),
    'furniture' => 
    array (
    ),
    'gallery' => 
    array (
    ),
    'garden' => 
    array (
    ),
    'gateway' => 
    array (
    ),
    'geelvinck' => 
    array (
    ),
    'gemological' => 
    array (
    ),
    'geology' => 
    array (
    ),
    'georgia' => 
    array (
    ),
    'giessen' => 
    array (
    ),
    'glas' => 
    array (
    ),
    'glass' => 
    array (
    ),
    'gorge' => 
    array (
    ),
    'grandrapids' => 
    array (
    ),
    'graz' => 
    array (
    ),
    'guernsey' => 
    array (
    ),
    'halloffame' => 
    array (
    ),
    'hamburg' => 
    array (
    ),
    'handson' => 
    array (
    ),
    'harvestcelebration' => 
    array (
    ),
    'hawaii' => 
    array (
    ),
    'health' => 
    array (
    ),
    'heimatunduhren' => 
    array (
    ),
    'hellas' => 
    array (
    ),
    'helsinki' => 
    array (
    ),
    'hembygdsforbund' => 
    array (
    ),
    'heritage' => 
    array (
    ),
    'histoire' => 
    array (
    ),
    'historical' => 
    array (
    ),
    'historicalsociety' => 
    array (
    ),
    'historichouses' => 
    array (
    ),
    'historisch' => 
    array (
    ),
    'historisches' => 
    array (
    ),
    'history' => 
    array (
    ),
    'historyofscience' => 
    array (
    ),
    'horology' => 
    array (
    ),
    'house' => 
    array (
    ),
    'humanities' => 
    array (
    ),
    'illustration' => 
    array (
    ),
    'imageandsound' => 
    array (
    ),
    'indian' => 
    array (
    ),
    'indiana' => 
    array (
    ),
    'indianapolis' => 
    array (
    ),
    'indianmarket' => 
    array (
    ),
    'intelligence' => 
    array (
    ),
    'interactive' => 
    array (
    ),
    'iraq' => 
    array (
    ),
    'iron' => 
    array (
    ),
    'isleofman' => 
    array (
    ),
    'jamison' => 
    array (
    ),
    'jefferson' => 
    array (
    ),
    'jerusalem' => 
    array (
    ),
    'jewelry' => 
    array (
    ),
    'jewish' => 
    array (
    ),
    'jewishart' => 
    array (
    ),
    'jfk' => 
    array (
    ),
    'journalism' => 
    array (
    ),
    'judaica' => 
    array (
    ),
    'judygarland' => 
    array (
    ),
    'juedisches' => 
    array (
    ),
    'juif' => 
    array (
    ),
    'karate' => 
    array (
    ),
    'karikatur' => 
    array (
    ),
    'kids' => 
    array (
    ),
    'koebenhavn' => 
    array (
    ),
    'koeln' => 
    array (
    ),
    'kunst' => 
    array (
    ),
    'kunstsammlung' => 
    array (
    ),
    'kunstunddesign' => 
    array (
    ),
    'labor' => 
    array (
    ),
    'labour' => 
    array (
    ),
    'lajolla' => 
    array (
    ),
    'lancashire' => 
    array (
    ),
    'landes' => 
    array (
    ),
    'lans' => 
    array (
    ),
    'xn--lns-qla' => 
    array (
    ),
    'larsson' => 
    array (
    ),
    'lewismiller' => 
    array (
    ),
    'lincoln' => 
    array (
    ),
    'linz' => 
    array (
    ),
    'living' => 
    array (
    ),
    'livinghistory' => 
    array (
    ),
    'localhistory' => 
    array (
    ),
    'london' => 
    array (
    ),
    'losangeles' => 
    array (
    ),
    'louvre' => 
    array (
    ),
    'loyalist' => 
    array (
    ),
    'lucerne' => 
    array (
    ),
    'luxembourg' => 
    array (
    ),
    'luzern' => 
    array (
    ),
    'mad' => 
    array (
    ),
    'madrid' => 
    array (
    ),
    'mallorca' => 
    array (
    ),
    'manchester' => 
    array (
    ),
    'mansion' => 
    array (
    ),
    'mansions' => 
    array (
    ),
    'manx' => 
    array (
    ),
    'marburg' => 
    array (
    ),
    'maritime' => 
    array (
    ),
    'maritimo' => 
    array (
    ),
    'maryland' => 
    array (
    ),
    'marylhurst' => 
    array (
    ),
    'media' => 
    array (
    ),
    'medical' => 
    array (
    ),
    'medizinhistorisches' => 
    array (
    ),
    'meeres' => 
    array (
    ),
    'memorial' => 
    array (
    ),
    'mesaverde' => 
    array (
    ),
    'michigan' => 
    array (
    ),
    'midatlantic' => 
    array (
    ),
    'military' => 
    array (
    ),
    'mill' => 
    array (
    ),
    'miners' => 
    array (
    ),
    'mining' => 
    array (
    ),
    'minnesota' => 
    array (
    ),
    'missile' => 
    array (
    ),
    'missoula' => 
    array (
    ),
    'modern' => 
    array (
    ),
    'moma' => 
    array (
    ),
    'money' => 
    array (
    ),
    'monmouth' => 
    array (
    ),
    'monticello' => 
    array (
    ),
    'montreal' => 
    array (
    ),
    'moscow' => 
    array (
    ),
    'motorcycle' => 
    array (
    ),
    'muenchen' => 
    array (
    ),
    'muenster' => 
    array (
    ),
    'mulhouse' => 
    array (
    ),
    'muncie' => 
    array (
    ),
    'museet' => 
    array (
    ),
    'museumcenter' => 
    array (
    ),
    'museumvereniging' => 
    array (
    ),
    'music' => 
    array (
    ),
    'national' => 
    array (
    ),
    'nationalfirearms' => 
    array (
    ),
    'nationalheritage' => 
    array (
    ),
    'nativeamerican' => 
    array (
    ),
    'naturalhistory' => 
    array (
    ),
    'naturalhistorymuseum' => 
    array (
    ),
    'naturalsciences' => 
    array (
    ),
    'nature' => 
    array (
    ),
    'naturhistorisches' => 
    array (
    ),
    'natuurwetenschappen' => 
    array (
    ),
    'naumburg' => 
    array (
    ),
    'naval' => 
    array (
    ),
    'nebraska' => 
    array (
    ),
    'neues' => 
    array (
    ),
    'newhampshire' => 
    array (
    ),
    'newjersey' => 
    array (
    ),
    'newmexico' => 
    array (
    ),
    'newport' => 
    array (
    ),
    'newspaper' => 
    array (
    ),
    'newyork' => 
    array (
    ),
    'niepce' => 
    array (
    ),
    'norfolk' => 
    array (
    ),
    'north' => 
    array (
    ),
    'nrw' => 
    array (
    ),
    'nuernberg' => 
    array (
    ),
    'nuremberg' => 
    array (
    ),
    'nyc' => 
    array (
    ),
    'nyny' => 
    array (
    ),
    'oceanographic' => 
    array (
    ),
    'oceanographique' => 
    array (
    ),
    'omaha' => 
    array (
    ),
    'online' => 
    array (
    ),
    'ontario' => 
    array (
    ),
    'openair' => 
    array (
    ),
    'oregon' => 
    array (
    ),
    'oregontrail' => 
    array (
    ),
    'otago' => 
    array (
    ),
    'oxford' => 
    array (
    ),
    'pacific' => 
    array (
    ),
    'paderborn' => 
    array (
    ),
    'palace' => 
    array (
    ),
    'paleo' => 
    array (
    ),
    'palmsprings' => 
    array (
    ),
    'panama' => 
    array (
    ),
    'paris' => 
    array (
    ),
    'pasadena' => 
    array (
    ),
    'pharmacy' => 
    array (
    ),
    'philadelphia' => 
    array (
    ),
    'philadelphiaarea' => 
    array (
    ),
    'philately' => 
    array (
    ),
    'phoenix' => 
    array (
    ),
    'photography' => 
    array (
    ),
    'pilots' => 
    array (
    ),
    'pittsburgh' => 
    array (
    ),
    'planetarium' => 
    array (
    ),
    'plantation' => 
    array (
    ),
    'plants' => 
    array (
    ),
    'plaza' => 
    array (
    ),
    'portal' => 
    array (
    ),
    'portland' => 
    array (
    ),
    'portlligat' => 
    array (
    ),
    'posts-and-telecommunications' => 
    array (
    ),
    'preservation' => 
    array (
    ),
    'presidio' => 
    array (
    ),
    'press' => 
    array (
    ),
    'project' => 
    array (
    ),
    'public' => 
    array (
    ),
    'pubol' => 
    array (
    ),
    'quebec' => 
    array (
    ),
    'railroad' => 
    array (
    ),
    'railway' => 
    array (
    ),
    'research' => 
    array (
    ),
    'resistance' => 
    array (
    ),
    'riodejaneiro' => 
    array (
    ),
    'rochester' => 
    array (
    ),
    'rockart' => 
    array (
    ),
    'roma' => 
    array (
    ),
    'russia' => 
    array (
    ),
    'saintlouis' => 
    array (
    ),
    'salem' => 
    array (
    ),
    'salvadordali' => 
    array (
    ),
    'salzburg' => 
    array (
    ),
    'sandiego' => 
    array (
    ),
    'sanfrancisco' => 
    array (
    ),
    'santabarbara' => 
    array (
    ),
    'santacruz' => 
    array (
    ),
    'santafe' => 
    array (
    ),
    'saskatchewan' => 
    array (
    ),
    'satx' => 
    array (
    ),
    'savannahga' => 
    array (
    ),
    'schlesisches' => 
    array (
    ),
    'schoenbrunn' => 
    array (
    ),
    'schokoladen' => 
    array (
    ),
    'school' => 
    array (
    ),
    'schweiz' => 
    array (
    ),
    'science' => 
    array (
    ),
    'scienceandhistory' => 
    array (
    ),
    'scienceandindustry' => 
    array (
    ),
    'sciencecenter' => 
    array (
    ),
    'sciencecenters' => 
    array (
    ),
    'science-fiction' => 
    array (
    ),
    'sciencehistory' => 
    array (
    ),
    'sciences' => 
    array (
    ),
    'sciencesnaturelles' => 
    array (
    ),
    'scotland' => 
    array (
    ),
    'seaport' => 
    array (
    ),
    'settlement' => 
    array (
    ),
    'settlers' => 
    array (
    ),
    'shell' => 
    array (
    ),
    'sherbrooke' => 
    array (
    ),
    'sibenik' => 
    array (
    ),
    'silk' => 
    array (
    ),
    'ski' => 
    array (
    ),
    'skole' => 
    array (
    ),
    'society' => 
    array (
    ),
    'sologne' => 
    array (
    ),
    'soundandvision' => 
    array (
    ),
    'southcarolina' => 
    array (
    ),
    'southwest' => 
    array (
    ),
    'space' => 
    array (
    ),
    'spy' => 
    array (
    ),
    'square' => 
    array (
    ),
    'stadt' => 
    array (
    ),
    'stalbans' => 
    array (
    ),
    'starnberg' => 
    array (
    ),
    'state' => 
    array (
    ),
    'stateofdelaware' => 
    array (
    ),
    'station' => 
    array (
    ),
    'steam' => 
    array (
    ),
    'steiermark' => 
    array (
    ),
    'stjohn' => 
    array (
    ),
    'stockholm' => 
    array (
    ),
    'stpetersburg' => 
    array (
    ),
    'stuttgart' => 
    array (
    ),
    'suisse' => 
    array (
    ),
    'surgeonshall' => 
    array (
    ),
    'surrey' => 
    array (
    ),
    'svizzera' => 
    array (
    ),
    'sweden' => 
    array (
    ),
    'sydney' => 
    array (
    ),
    'tank' => 
    array (
    ),
    'tcm' => 
    array (
    ),
    'technology' => 
    array (
    ),
    'telekommunikation' => 
    array (
    ),
    'television' => 
    array (
    ),
    'texas' => 
    array (
    ),
    'textile' => 
    array (
    ),
    'theater' => 
    array (
    ),
    'time' => 
    array (
    ),
    'timekeeping' => 
    array (
    ),
    'topology' => 
    array (
    ),
    'torino' => 
    array (
    ),
    'touch' => 
    array (
    ),
    'town' => 
    array (
    ),
    'transport' => 
    array (
    ),
    'tree' => 
    array (
    ),
    'trolley' => 
    array (
    ),
    'trust' => 
    array (
    ),
    'trustee' => 
    array (
    ),
    'uhren' => 
    array (
    ),
    'ulm' => 
    array (
    ),
    'undersea' => 
    array (
    ),
    'university' => 
    array (
    ),
    'usa' => 
    array (
    ),
    'usantiques' => 
    array (
    ),
    'usarts' => 
    array (
    ),
    'uscountryestate' => 
    array (
    ),
    'usculture' => 
    array (
    ),
    'usdecorativearts' => 
    array (
    ),
    'usgarden' => 
    array (
    ),
    'ushistory' => 
    array (
    ),
    'ushuaia' => 
    array (
    ),
    'uslivinghistory' => 
    array (
    ),
    'utah' => 
    array (
    ),
    'uvic' => 
    array (
    ),
    'valley' => 
    array (
    ),
    'vantaa' => 
    array (
    ),
    'versailles' => 
    array (
    ),
    'viking' => 
    array (
    ),
    'village' => 
    array (
    ),
    'virginia' => 
    array (
    ),
    'virtual' => 
    array (
    ),
    'virtuel' => 
    array (
    ),
    'vlaanderen' => 
    array (
    ),
    'volkenkunde' => 
    array (
    ),
    'wales' => 
    array (
    ),
    'wallonie' => 
    array (
    ),
    'war' => 
    array (
    ),
    'washingtondc' => 
    array (
    ),
    'watchandclock' => 
    array (
    ),
    'watch-and-clock' => 
    array (
    ),
    'western' => 
    array (
    ),
    'westfalen' => 
    array (
    ),
    'whaling' => 
    array (
    ),
    'wildlife' => 
    array (
    ),
    'williamsburg' => 
    array (
    ),
    'windmill' => 
    array (
    ),
    'workshop' => 
    array (
    ),
    'york' => 
    array (
    ),
    'yorkshire' => 
    array (
    ),
    'yosemite' => 
    array (
    ),
    'youth' => 
    array (
    ),
    'zoological' => 
    array (
    ),
    'zoology' => 
    array (
    ),
    'xn--9dbhblg6di' => 
    array (
    ),
    'xn--h1aegh' => 
    array (
    ),
  ),
  'mv' => 
  array (
    'aero' => 
    array (
    ),
    'biz' => 
    array (
    ),
    'com' => 
    array (
    ),
    'coop' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'info' => 
    array (
    ),
    'int' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'museum' => 
    array (
    ),
    'name' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'pro' => 
    array (
    ),
  ),
  'mw' => 
  array (
    'ac' => 
    array (
    ),
    'biz' => 
    array (
    ),
    'co' => 
    array (
    ),
    'com' => 
    array (
    ),
    'coop' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'int' => 
    array (
    ),
    'museum' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
  ),
  'mx' => 
  array (
    'com' => 
    array (
    ),
    'org' => 
    array (
    ),
    'gob' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'net' => 
    array (
    ),
    'blogspot' => 
    array (
    ),
  ),
  'my' => 
  array (
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'name' => 
    array (
    ),
  ),
  'mz' => 
  array (
    '*' => 
    array (
    ),
    'teledata' => 
    array (
      '!' => '',
    ),
  ),
  'na' => 
  array (
    'info' => 
    array (
    ),
    'pro' => 
    array (
    ),
    'name' => 
    array (
    ),
    'school' => 
    array (
    ),
    'or' => 
    array (
    ),
    'dr' => 
    array (
    ),
    'us' => 
    array (
    ),
    'mx' => 
    array (
    ),
    'ca' => 
    array (
    ),
    'in' => 
    array (
    ),
    'cc' => 
    array (
    ),
    'tv' => 
    array (
    ),
    'ws' => 
    array (
    ),
    'mobi' => 
    array (
    ),
    'co' => 
    array (
    ),
    'com' => 
    array (
    ),
    'org' => 
    array (
    ),
  ),
  'name' => 
  array (
    'her' => 
    array (
      'forgot' => 
      array (
      ),
    ),
    'his' => 
    array (
      'forgot' => 
      array (
      ),
    ),
  ),
  'nc' => 
  array (
    'asso' => 
    array (
    ),
  ),
  'ne' => 
  array (
  ),
  'net' => 
  array (
    'cloudfront' => 
    array (
    ),
    'gb' => 
    array (
    ),
    'hu' => 
    array (
    ),
    'jp' => 
    array (
    ),
    'se' => 
    array (
    ),
    'uk' => 
    array (
    ),
    'in' => 
    array (
    ),
    'at-band-camp' => 
    array (
    ),
    'blogdns' => 
    array (
    ),
    'broke-it' => 
    array (
    ),
    'buyshouses' => 
    array (
    ),
    'dnsalias' => 
    array (
    ),
    'dnsdojo' => 
    array (
    ),
    'does-it' => 
    array (
    ),
    'dontexist' => 
    array (
    ),
    'dynalias' => 
    array (
    ),
    'dynathome' => 
    array (
    ),
    'endofinternet' => 
    array (
    ),
    'from-az' => 
    array (
    ),
    'from-co' => 
    array (
    ),
    'from-la' => 
    array (
    ),
    'from-ny' => 
    array (
    ),
    'gets-it' => 
    array (
    ),
    'ham-radio-op' => 
    array (
    ),
    'homeftp' => 
    array (
    ),
    'homeip' => 
    array (
    ),
    'homelinux' => 
    array (
    ),
    'homeunix' => 
    array (
    ),
    'in-the-band' => 
    array (
    ),
    'is-a-chef' => 
    array (
    ),
    'is-a-geek' => 
    array (
    ),
    'isa-geek' => 
    array (
    ),
    'kicks-ass' => 
    array (
    ),
    'office-on-the' => 
    array (
    ),
    'podzone' => 
    array (
    ),
    'scrapper-site' => 
    array (
    ),
    'selfip' => 
    array (
    ),
    'sells-it' => 
    array (
    ),
    'servebbs' => 
    array (
    ),
    'serveftp' => 
    array (
    ),
    'thruhere' => 
    array (
    ),
    'webhop' => 
    array (
    ),
    'fastly' => 
    array (
      'ssl' => 
      array (
        'a' => 
        array (
        ),
        'b' => 
        array (
        ),
        'global' => 
        array (
        ),
      ),
      'prod' => 
      array (
        'a' => 
        array (
        ),
        'global' => 
        array (
        ),
      ),
    ),
    'azurewebsites' => 
    array (
    ),
    'azure-mobile' => 
    array (
    ),
    'cloudapp' => 
    array (
    ),
    'za' => 
    array (
    ),
  ),
  'nf' => 
  array (
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
    'per' => 
    array (
    ),
    'rec' => 
    array (
    ),
    'web' => 
    array (
    ),
    'arts' => 
    array (
    ),
    'firm' => 
    array (
    ),
    'info' => 
    array (
    ),
    'other' => 
    array (
    ),
    'store' => 
    array (
    ),
  ),
  'ng' => 
  array (
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'name' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'sch' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'mobi' => 
    array (
    ),
  ),
  'ni' => 
  array (
    '*' => 
    array (
    ),
  ),
  'nl' => 
  array (
    'bv' => 
    array (
    ),
    'co' => 
    array (
    ),
    'blogspot' => 
    array (
    ),
  ),
  'no' => 
  array (
    'fhs' => 
    array (
    ),
    'vgs' => 
    array (
    ),
    'fylkesbibl' => 
    array (
    ),
    'folkebibl' => 
    array (
    ),
    'museum' => 
    array (
    ),
    'idrett' => 
    array (
    ),
    'priv' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'stat' => 
    array (
    ),
    'dep' => 
    array (
    ),
    'kommune' => 
    array (
    ),
    'herad' => 
    array (
    ),
    'aa' => 
    array (
      'gs' => 
      array (
      ),
    ),
    'ah' => 
    array (
      'gs' => 
      array (
      ),
    ),
    'bu' => 
    array (
      'gs' => 
      array (
      ),
    ),
    'fm' => 
    array (
      'gs' => 
      array (
      ),
    ),
    'hl' => 
    array (
      'gs' => 
      array (
      ),
    ),
    'hm' => 
    array (
      'gs' => 
      array (
      ),
    ),
    'jan-mayen' => 
    array (
      'gs' => 
      array (
      ),
    ),
    'mr' => 
    array (
      'gs' => 
      array (
      ),
    ),
    'nl' => 
    array (
      'gs' => 
      array (
      ),
    ),
    'nt' => 
    array (
      'gs' => 
      array (
      ),
    ),
    'of' => 
    array (
      'gs' => 
      array (
      ),
    ),
    'ol' => 
    array (
      'gs' => 
      array (
      ),
    ),
    'oslo' => 
    array (
      'gs' => 
      array (
      ),
    ),
    'rl' => 
    array (
      'gs' => 
      array (
      ),
    ),
    'sf' => 
    array (
      'gs' => 
      array (
      ),
    ),
    'st' => 
    array (
      'gs' => 
      array (
      ),
    ),
    'svalbard' => 
    array (
      'gs' => 
      array (
      ),
    ),
    'tm' => 
    array (
      'gs' => 
      array (
      ),
    ),
    'tr' => 
    array (
      'gs' => 
      array (
      ),
    ),
    'va' => 
    array (
      'gs' => 
      array (
      ),
    ),
    'vf' => 
    array (
      'gs' => 
      array (
      ),
    ),
    'akrehamn' => 
    array (
    ),
    'xn--krehamn-dxa' => 
    array (
    ),
    'algard' => 
    array (
    ),
    'xn--lgrd-poac' => 
    array (
    ),
    'arna' => 
    array (
    ),
    'brumunddal' => 
    array (
    ),
    'bryne' => 
    array (
    ),
    'bronnoysund' => 
    array (
    ),
    'xn--brnnysund-m8ac' => 
    array (
    ),
    'drobak' => 
    array (
    ),
    'xn--drbak-wua' => 
    array (
    ),
    'egersund' => 
    array (
    ),
    'fetsund' => 
    array (
    ),
    'floro' => 
    array (
    ),
    'xn--flor-jra' => 
    array (
    ),
    'fredrikstad' => 
    array (
    ),
    'hokksund' => 
    array (
    ),
    'honefoss' => 
    array (
    ),
    'xn--hnefoss-q1a' => 
    array (
    ),
    'jessheim' => 
    array (
    ),
    'jorpeland' => 
    array (
    ),
    'xn--jrpeland-54a' => 
    array (
    ),
    'kirkenes' => 
    array (
    ),
    'kopervik' => 
    array (
    ),
    'krokstadelva' => 
    array (
    ),
    'langevag' => 
    array (
    ),
    'xn--langevg-jxa' => 
    array (
    ),
    'leirvik' => 
    array (
    ),
    'mjondalen' => 
    array (
    ),
    'xn--mjndalen-64a' => 
    array (
    ),
    'mo-i-rana' => 
    array (
    ),
    'mosjoen' => 
    array (
    ),
    'xn--mosjen-eya' => 
    array (
    ),
    'nesoddtangen' => 
    array (
    ),
    'orkanger' => 
    array (
    ),
    'osoyro' => 
    array (
    ),
    'xn--osyro-wua' => 
    array (
    ),
    'raholt' => 
    array (
    ),
    'xn--rholt-mra' => 
    array (
    ),
    'sandnessjoen' => 
    array (
    ),
    'xn--sandnessjen-ogb' => 
    array (
    ),
    'skedsmokorset' => 
    array (
    ),
    'slattum' => 
    array (
    ),
    'spjelkavik' => 
    array (
    ),
    'stathelle' => 
    array (
    ),
    'stavern' => 
    array (
    ),
    'stjordalshalsen' => 
    array (
    ),
    'xn--stjrdalshalsen-sqb' => 
    array (
    ),
    'tananger' => 
    array (
    ),
    'tranby' => 
    array (
    ),
    'vossevangen' => 
    array (
    ),
    'afjord' => 
    array (
    ),
    'xn--fjord-lra' => 
    array (
    ),
    'agdenes' => 
    array (
    ),
    'al' => 
    array (
    ),
    'xn--l-1fa' => 
    array (
    ),
    'alesund' => 
    array (
    ),
    'xn--lesund-hua' => 
    array (
    ),
    'alstahaug' => 
    array (
    ),
    'alta' => 
    array (
    ),
    'xn--lt-liac' => 
    array (
    ),
    'alaheadju' => 
    array (
    ),
    'xn--laheadju-7ya' => 
    array (
    ),
    'alvdal' => 
    array (
    ),
    'amli' => 
    array (
    ),
    'xn--mli-tla' => 
    array (
    ),
    'amot' => 
    array (
    ),
    'xn--mot-tla' => 
    array (
    ),
    'andebu' => 
    array (
    ),
    'andoy' => 
    array (
    ),
    'xn--andy-ira' => 
    array (
    ),
    'andasuolo' => 
    array (
    ),
    'ardal' => 
    array (
    ),
    'xn--rdal-poa' => 
    array (
    ),
    'aremark' => 
    array (
    ),
    'arendal' => 
    array (
    ),
    'xn--s-1fa' => 
    array (
    ),
    'aseral' => 
    array (
    ),
    'xn--seral-lra' => 
    array (
    ),
    'asker' => 
    array (
    ),
    'askim' => 
    array (
    ),
    'askvoll' => 
    array (
    ),
    'askoy' => 
    array (
    ),
    'xn--asky-ira' => 
    array (
    ),
    'asnes' => 
    array (
    ),
    'xn--snes-poa' => 
    array (
    ),
    'audnedaln' => 
    array (
    ),
    'aukra' => 
    array (
    ),
    'aure' => 
    array (
    ),
    'aurland' => 
    array (
    ),
    'aurskog-holand' => 
    array (
    ),
    'xn--aurskog-hland-jnb' => 
    array (
    ),
    'austevoll' => 
    array (
    ),
    'austrheim' => 
    array (
    ),
    'averoy' => 
    array (
    ),
    'xn--avery-yua' => 
    array (
    ),
    'balestrand' => 
    array (
    ),
    'ballangen' => 
    array (
    ),
    'balat' => 
    array (
    ),
    'xn--blt-elab' => 
    array (
    ),
    'balsfjord' => 
    array (
    ),
    'bahccavuotna' => 
    array (
    ),
    'xn--bhccavuotna-k7a' => 
    array (
    ),
    'bamble' => 
    array (
    ),
    'bardu' => 
    array (
    ),
    'beardu' => 
    array (
    ),
    'beiarn' => 
    array (
    ),
    'bajddar' => 
    array (
    ),
    'xn--bjddar-pta' => 
    array (
    ),
    'baidar' => 
    array (
    ),
    'xn--bidr-5nac' => 
    array (
    ),
    'berg' => 
    array (
    ),
    'bergen' => 
    array (
    ),
    'berlevag' => 
    array (
    ),
    'xn--berlevg-jxa' => 
    array (
    ),
    'bearalvahki' => 
    array (
    ),
    'xn--bearalvhki-y4a' => 
    array (
    ),
    'bindal' => 
    array (
    ),
    'birkenes' => 
    array (
    ),
    'bjarkoy' => 
    array (
    ),
    'xn--bjarky-fya' => 
    array (
    ),
    'bjerkreim' => 
    array (
    ),
    'bjugn' => 
    array (
    ),
    'bodo' => 
    array (
    ),
    'xn--bod-2na' => 
    array (
    ),
    'badaddja' => 
    array (
    ),
    'xn--bdddj-mrabd' => 
    array (
    ),
    'budejju' => 
    array (
    ),
    'bokn' => 
    array (
    ),
    'bremanger' => 
    array (
    ),
    'bronnoy' => 
    array (
    ),
    'xn--brnny-wuac' => 
    array (
    ),
    'bygland' => 
    array (
    ),
    'bykle' => 
    array (
    ),
    'barum' => 
    array (
    ),
    'xn--brum-voa' => 
    array (
    ),
    'telemark' => 
    array (
      'bo' => 
      array (
      ),
      'xn--b-5ga' => 
      array (
      ),
    ),
    'nordland' => 
    array (
      'bo' => 
      array (
      ),
      'xn--b-5ga' => 
      array (
      ),
      'heroy' => 
      array (
      ),
      'xn--hery-ira' => 
      array (
      ),
    ),
    'bievat' => 
    array (
    ),
    'xn--bievt-0qa' => 
    array (
    ),
    'bomlo' => 
    array (
    ),
    'xn--bmlo-gra' => 
    array (
    ),
    'batsfjord' => 
    array (
    ),
    'xn--btsfjord-9za' => 
    array (
    ),
    'bahcavuotna' => 
    array (
    ),
    'xn--bhcavuotna-s4a' => 
    array (
    ),
    'dovre' => 
    array (
    ),
    'drammen' => 
    array (
    ),
    'drangedal' => 
    array (
    ),
    'dyroy' => 
    array (
    ),
    'xn--dyry-ira' => 
    array (
    ),
    'donna' => 
    array (
    ),
    'xn--dnna-gra' => 
    array (
    ),
    'eid' => 
    array (
    ),
    'eidfjord' => 
    array (
    ),
    'eidsberg' => 
    array (
    ),
    'eidskog' => 
    array (
    ),
    'eidsvoll' => 
    array (
    ),
    'eigersund' => 
    array (
    ),
    'elverum' => 
    array (
    ),
    'enebakk' => 
    array (
    ),
    'engerdal' => 
    array (
    ),
    'etne' => 
    array (
    ),
    'etnedal' => 
    array (
    ),
    'evenes' => 
    array (
    ),
    'evenassi' => 
    array (
    ),
    'xn--eveni-0qa01ga' => 
    array (
    ),
    'evje-og-hornnes' => 
    array (
    ),
    'farsund' => 
    array (
    ),
    'fauske' => 
    array (
    ),
    'fuossko' => 
    array (
    ),
    'fuoisku' => 
    array (
    ),
    'fedje' => 
    array (
    ),
    'fet' => 
    array (
    ),
    'finnoy' => 
    array (
    ),
    'xn--finny-yua' => 
    array (
    ),
    'fitjar' => 
    array (
    ),
    'fjaler' => 
    array (
    ),
    'fjell' => 
    array (
    ),
    'flakstad' => 
    array (
    ),
    'flatanger' => 
    array (
    ),
    'flekkefjord' => 
    array (
    ),
    'flesberg' => 
    array (
    ),
    'flora' => 
    array (
    ),
    'fla' => 
    array (
    ),
    'xn--fl-zia' => 
    array (
    ),
    'folldal' => 
    array (
    ),
    'forsand' => 
    array (
    ),
    'fosnes' => 
    array (
    ),
    'frei' => 
    array (
    ),
    'frogn' => 
    array (
    ),
    'froland' => 
    array (
    ),
    'frosta' => 
    array (
    ),
    'frana' => 
    array (
    ),
    'xn--frna-woa' => 
    array (
    ),
    'froya' => 
    array (
    ),
    'xn--frya-hra' => 
    array (
    ),
    'fusa' => 
    array (
    ),
    'fyresdal' => 
    array (
    ),
    'forde' => 
    array (
    ),
    'xn--frde-gra' => 
    array (
    ),
    'gamvik' => 
    array (
    ),
    'gangaviika' => 
    array (
    ),
    'xn--ggaviika-8ya47h' => 
    array (
    ),
    'gaular' => 
    array (
    ),
    'gausdal' => 
    array (
    ),
    'gildeskal' => 
    array (
    ),
    'xn--gildeskl-g0a' => 
    array (
    ),
    'giske' => 
    array (
    ),
    'gjemnes' => 
    array (
    ),
    'gjerdrum' => 
    array (
    ),
    'gjerstad' => 
    array (
    ),
    'gjesdal' => 
    array (
    ),
    'gjovik' => 
    array (
    ),
    'xn--gjvik-wua' => 
    array (
    ),
    'gloppen' => 
    array (
    ),
    'gol' => 
    array (
    ),
    'gran' => 
    array (
    ),
    'grane' => 
    array (
    ),
    'granvin' => 
    array (
    ),
    'gratangen' => 
    array (
    ),
    'grimstad' => 
    array (
    ),
    'grong' => 
    array (
    ),
    'kraanghke' => 
    array (
    ),
    'xn--kranghke-b0a' => 
    array (
    ),
    'grue' => 
    array (
    ),
    'gulen' => 
    array (
    ),
    'hadsel' => 
    array (
    ),
    'halden' => 
    array (
    ),
    'halsa' => 
    array (
    ),
    'hamar' => 
    array (
    ),
    'hamaroy' => 
    array (
    ),
    'habmer' => 
    array (
    ),
    'xn--hbmer-xqa' => 
    array (
    ),
    'hapmir' => 
    array (
    ),
    'xn--hpmir-xqa' => 
    array (
    ),
    'hammerfest' => 
    array (
    ),
    'hammarfeasta' => 
    array (
    ),
    'xn--hmmrfeasta-s4ac' => 
    array (
    ),
    'haram' => 
    array (
    ),
    'hareid' => 
    array (
    ),
    'harstad' => 
    array (
    ),
    'hasvik' => 
    array (
    ),
    'aknoluokta' => 
    array (
    ),
    'xn--koluokta-7ya57h' => 
    array (
    ),
    'hattfjelldal' => 
    array (
    ),
    'aarborte' => 
    array (
    ),
    'haugesund' => 
    array (
    ),
    'hemne' => 
    array (
    ),
    'hemnes' => 
    array (
    ),
    'hemsedal' => 
    array (
    ),
    'more-og-romsdal' => 
    array (
      'heroy' => 
      array (
      ),
      'sande' => 
      array (
      ),
    ),
    'xn--mre-og-romsdal-qqb' => 
    array (
      'xn--hery-ira' => 
      array (
      ),
      'sande' => 
      array (
      ),
    ),
    'hitra' => 
    array (
    ),
    'hjartdal' => 
    array (
    ),
    'hjelmeland' => 
    array (
    ),
    'hobol' => 
    array (
    ),
    'xn--hobl-ira' => 
    array (
    ),
    'hof' => 
    array (
    ),
    'hol' => 
    array (
    ),
    'hole' => 
    array (
    ),
    'holmestrand' => 
    array (
    ),
    'holtalen' => 
    array (
    ),
    'xn--holtlen-hxa' => 
    array (
    ),
    'hornindal' => 
    array (
    ),
    'horten' => 
    array (
    ),
    'hurdal' => 
    array (
    ),
    'hurum' => 
    array (
    ),
    'hvaler' => 
    array (
    ),
    'hyllestad' => 
    array (
    ),
    'hagebostad' => 
    array (
    ),
    'xn--hgebostad-g3a' => 
    array (
    ),
    'hoyanger' => 
    array (
    ),
    'xn--hyanger-q1a' => 
    array (
    ),
    'hoylandet' => 
    array (
    ),
    'xn--hylandet-54a' => 
    array (
    ),
    'ha' => 
    array (
    ),
    'xn--h-2fa' => 
    array (
    ),
    'ibestad' => 
    array (
    ),
    'inderoy' => 
    array (
    ),
    'xn--indery-fya' => 
    array (
    ),
    'iveland' => 
    array (
    ),
    'jevnaker' => 
    array (
    ),
    'jondal' => 
    array (
    ),
    'jolster' => 
    array (
    ),
    'xn--jlster-bya' => 
    array (
    ),
    'karasjok' => 
    array (
    ),
    'karasjohka' => 
    array (
    ),
    'xn--krjohka-hwab49j' => 
    array (
    ),
    'karlsoy' => 
    array (
    ),
    'galsa' => 
    array (
    ),
    'xn--gls-elac' => 
    array (
    ),
    'karmoy' => 
    array (
    ),
    'xn--karmy-yua' => 
    array (
    ),
    'kautokeino' => 
    array (
    ),
    'guovdageaidnu' => 
    array (
    ),
    'klepp' => 
    array (
    ),
    'klabu' => 
    array (
    ),
    'xn--klbu-woa' => 
    array (
    ),
    'kongsberg' => 
    array (
    ),
    'kongsvinger' => 
    array (
    ),
    'kragero' => 
    array (
    ),
    'xn--krager-gya' => 
    array (
    ),
    'kristiansand' => 
    array (
    ),
    'kristiansund' => 
    array (
    ),
    'krodsherad' => 
    array (
    ),
    'xn--krdsherad-m8a' => 
    array (
    ),
    'kvalsund' => 
    array (
    ),
    'rahkkeravju' => 
    array (
    ),
    'xn--rhkkervju-01af' => 
    array (
    ),
    'kvam' => 
    array (
    ),
    'kvinesdal' => 
    array (
    ),
    'kvinnherad' => 
    array (
    ),
    'kviteseid' => 
    array (
    ),
    'kvitsoy' => 
    array (
    ),
    'xn--kvitsy-fya' => 
    array (
    ),
    'kvafjord' => 
    array (
    ),
    'xn--kvfjord-nxa' => 
    array (
    ),
    'giehtavuoatna' => 
    array (
    ),
    'kvanangen' => 
    array (
    ),
    'xn--kvnangen-k0a' => 
    array (
    ),
    'navuotna' => 
    array (
    ),
    'xn--nvuotna-hwa' => 
    array (
    ),
    'kafjord' => 
    array (
    ),
    'xn--kfjord-iua' => 
    array (
    ),
    'gaivuotna' => 
    array (
    ),
    'xn--givuotna-8ya' => 
    array (
    ),
    'larvik' => 
    array (
    ),
    'lavangen' => 
    array (
    ),
    'lavagis' => 
    array (
    ),
    'loabat' => 
    array (
    ),
    'xn--loabt-0qa' => 
    array (
    ),
    'lebesby' => 
    array (
    ),
    'davvesiida' => 
    array (
    ),
    'leikanger' => 
    array (
    ),
    'leirfjord' => 
    array (
    ),
    'leka' => 
    array (
    ),
    'leksvik' => 
    array (
    ),
    'lenvik' => 
    array (
    ),
    'leangaviika' => 
    array (
    ),
    'xn--leagaviika-52b' => 
    array (
    ),
    'lesja' => 
    array (
    ),
    'levanger' => 
    array (
    ),
    'lier' => 
    array (
    ),
    'lierne' => 
    array (
    ),
    'lillehammer' => 
    array (
    ),
    'lillesand' => 
    array (
    ),
    'lindesnes' => 
    array (
    ),
    'lindas' => 
    array (
    ),
    'xn--linds-pra' => 
    array (
    ),
    'lom' => 
    array (
    ),
    'loppa' => 
    array (
    ),
    'lahppi' => 
    array (
    ),
    'xn--lhppi-xqa' => 
    array (
    ),
    'lund' => 
    array (
    ),
    'lunner' => 
    array (
    ),
    'luroy' => 
    array (
    ),
    'xn--lury-ira' => 
    array (
    ),
    'luster' => 
    array (
    ),
    'lyngdal' => 
    array (
    ),
    'lyngen' => 
    array (
    ),
    'ivgu' => 
    array (
    ),
    'lardal' => 
    array (
    ),
    'lerdal' => 
    array (
    ),
    'xn--lrdal-sra' => 
    array (
    ),
    'lodingen' => 
    array (
    ),
    'xn--ldingen-q1a' => 
    array (
    ),
    'lorenskog' => 
    array (
    ),
    'xn--lrenskog-54a' => 
    array (
    ),
    'loten' => 
    array (
    ),
    'xn--lten-gra' => 
    array (
    ),
    'malvik' => 
    array (
    ),
    'masoy' => 
    array (
    ),
    'xn--msy-ula0h' => 
    array (
    ),
    'muosat' => 
    array (
    ),
    'xn--muost-0qa' => 
    array (
    ),
    'mandal' => 
    array (
    ),
    'marker' => 
    array (
    ),
    'marnardal' => 
    array (
    ),
    'masfjorden' => 
    array (
    ),
    'meland' => 
    array (
    ),
    'meldal' => 
    array (
    ),
    'melhus' => 
    array (
    ),
    'meloy' => 
    array (
    ),
    'xn--mely-ira' => 
    array (
    ),
    'meraker' => 
    array (
    ),
    'xn--merker-kua' => 
    array (
    ),
    'moareke' => 
    array (
    ),
    'xn--moreke-jua' => 
    array (
    ),
    'midsund' => 
    array (
    ),
    'midtre-gauldal' => 
    array (
    ),
    'modalen' => 
    array (
    ),
    'modum' => 
    array (
    ),
    'molde' => 
    array (
    ),
    'moskenes' => 
    array (
    ),
    'moss' => 
    array (
    ),
    'mosvik' => 
    array (
    ),
    'malselv' => 
    array (
    ),
    'xn--mlselv-iua' => 
    array (
    ),
    'malatvuopmi' => 
    array (
    ),
    'xn--mlatvuopmi-s4a' => 
    array (
    ),
    'namdalseid' => 
    array (
    ),
    'aejrie' => 
    array (
    ),
    'namsos' => 
    array (
    ),
    'namsskogan' => 
    array (
    ),
    'naamesjevuemie' => 
    array (
    ),
    'xn--nmesjevuemie-tcba' => 
    array (
    ),
    'laakesvuemie' => 
    array (
    ),
    'nannestad' => 
    array (
    ),
    'narvik' => 
    array (
    ),
    'narviika' => 
    array (
    ),
    'naustdal' => 
    array (
    ),
    'nedre-eiker' => 
    array (
    ),
    'akershus' => 
    array (
      'nes' => 
      array (
      ),
    ),
    'buskerud' => 
    array (
      'nes' => 
      array (
      ),
    ),
    'nesna' => 
    array (
    ),
    'nesodden' => 
    array (
    ),
    'nesseby' => 
    array (
    ),
    'unjarga' => 
    array (
    ),
    'xn--unjrga-rta' => 
    array (
    ),
    'nesset' => 
    array (
    ),
    'nissedal' => 
    array (
    ),
    'nittedal' => 
    array (
    ),
    'nord-aurdal' => 
    array (
    ),
    'nord-fron' => 
    array (
    ),
    'nord-odal' => 
    array (
    ),
    'norddal' => 
    array (
    ),
    'nordkapp' => 
    array (
    ),
    'davvenjarga' => 
    array (
    ),
    'xn--davvenjrga-y4a' => 
    array (
    ),
    'nordre-land' => 
    array (
    ),
    'nordreisa' => 
    array (
    ),
    'raisa' => 
    array (
    ),
    'xn--risa-5na' => 
    array (
    ),
    'nore-og-uvdal' => 
    array (
    ),
    'notodden' => 
    array (
    ),
    'naroy' => 
    array (
    ),
    'xn--nry-yla5g' => 
    array (
    ),
    'notteroy' => 
    array (
    ),
    'xn--nttery-byae' => 
    array (
    ),
    'odda' => 
    array (
    ),
    'oksnes' => 
    array (
    ),
    'xn--ksnes-uua' => 
    array (
    ),
    'oppdal' => 
    array (
    ),
    'oppegard' => 
    array (
    ),
    'xn--oppegrd-ixa' => 
    array (
    ),
    'orkdal' => 
    array (
    ),
    'orland' => 
    array (
    ),
    'xn--rland-uua' => 
    array (
    ),
    'orskog' => 
    array (
    ),
    'xn--rskog-uua' => 
    array (
    ),
    'orsta' => 
    array (
    ),
    'xn--rsta-fra' => 
    array (
    ),
    'hedmark' => 
    array (
      'os' => 
      array (
      ),
      'valer' => 
      array (
      ),
      'xn--vler-qoa' => 
      array (
      ),
    ),
    'hordaland' => 
    array (
      'os' => 
      array (
      ),
    ),
    'osen' => 
    array (
    ),
    'osteroy' => 
    array (
    ),
    'xn--ostery-fya' => 
    array (
    ),
    'ostre-toten' => 
    array (
    ),
    'xn--stre-toten-zcb' => 
    array (
    ),
    'overhalla' => 
    array (
    ),
    'ovre-eiker' => 
    array (
    ),
    'xn--vre-eiker-k8a' => 
    array (
    ),
    'oyer' => 
    array (
    ),
    'xn--yer-zna' => 
    array (
    ),
    'oygarden' => 
    array (
    ),
    'xn--ygarden-p1a' => 
    array (
    ),
    'oystre-slidre' => 
    array (
    ),
    'xn--ystre-slidre-ujb' => 
    array (
    ),
    'porsanger' => 
    array (
    ),
    'porsangu' => 
    array (
    ),
    'xn--porsgu-sta26f' => 
    array (
    ),
    'porsgrunn' => 
    array (
    ),
    'radoy' => 
    array (
    ),
    'xn--rady-ira' => 
    array (
    ),
    'rakkestad' => 
    array (
    ),
    'rana' => 
    array (
    ),
    'ruovat' => 
    array (
    ),
    'randaberg' => 
    array (
    ),
    'rauma' => 
    array (
    ),
    'rendalen' => 
    array (
    ),
    'rennebu' => 
    array (
    ),
    'rennesoy' => 
    array (
    ),
    'xn--rennesy-v1a' => 
    array (
    ),
    'rindal' => 
    array (
    ),
    'ringebu' => 
    array (
    ),
    'ringerike' => 
    array (
    ),
    'ringsaker' => 
    array (
    ),
    'rissa' => 
    array (
    ),
    'risor' => 
    array (
    ),
    'xn--risr-ira' => 
    array (
    ),
    'roan' => 
    array (
    ),
    'rollag' => 
    array (
    ),
    'rygge' => 
    array (
    ),
    'ralingen' => 
    array (
    ),
    'xn--rlingen-mxa' => 
    array (
    ),
    'rodoy' => 
    array (
    ),
    'xn--rdy-0nab' => 
    array (
    ),
    'romskog' => 
    array (
    ),
    'xn--rmskog-bya' => 
    array (
    ),
    'roros' => 
    array (
    ),
    'xn--rros-gra' => 
    array (
    ),
    'rost' => 
    array (
    ),
    'xn--rst-0na' => 
    array (
    ),
    'royken' => 
    array (
    ),
    'xn--ryken-vua' => 
    array (
    ),
    'royrvik' => 
    array (
    ),
    'xn--ryrvik-bya' => 
    array (
    ),
    'rade' => 
    array (
    ),
    'xn--rde-ula' => 
    array (
    ),
    'salangen' => 
    array (
    ),
    'siellak' => 
    array (
    ),
    'saltdal' => 
    array (
    ),
    'salat' => 
    array (
    ),
    'xn--slt-elab' => 
    array (
    ),
    'xn--slat-5na' => 
    array (
    ),
    'samnanger' => 
    array (
    ),
    'vestfold' => 
    array (
      'sande' => 
      array (
      ),
    ),
    'sandefjord' => 
    array (
    ),
    'sandnes' => 
    array (
    ),
    'sandoy' => 
    array (
    ),
    'xn--sandy-yua' => 
    array (
    ),
    'sarpsborg' => 
    array (
    ),
    'sauda' => 
    array (
    ),
    'sauherad' => 
    array (
    ),
    'sel' => 
    array (
    ),
    'selbu' => 
    array (
    ),
    'selje' => 
    array (
    ),
    'seljord' => 
    array (
    ),
    'sigdal' => 
    array (
    ),
    'siljan' => 
    array (
    ),
    'sirdal' => 
    array (
    ),
    'skaun' => 
    array (
    ),
    'skedsmo' => 
    array (
    ),
    'ski' => 
    array (
    ),
    'skien' => 
    array (
    ),
    'skiptvet' => 
    array (
    ),
    'skjervoy' => 
    array (
    ),
    'xn--skjervy-v1a' => 
    array (
    ),
    'skierva' => 
    array (
    ),
    'xn--skierv-uta' => 
    array (
    ),
    'skjak' => 
    array (
    ),
    'xn--skjk-soa' => 
    array (
    ),
    'skodje' => 
    array (
    ),
    'skanland' => 
    array (
    ),
    'xn--sknland-fxa' => 
    array (
    ),
    'skanit' => 
    array (
    ),
    'xn--sknit-yqa' => 
    array (
    ),
    'smola' => 
    array (
    ),
    'xn--smla-hra' => 
    array (
    ),
    'snillfjord' => 
    array (
    ),
    'snasa' => 
    array (
    ),
    'xn--snsa-roa' => 
    array (
    ),
    'snoasa' => 
    array (
    ),
    'snaase' => 
    array (
    ),
    'xn--snase-nra' => 
    array (
    ),
    'sogndal' => 
    array (
    ),
    'sokndal' => 
    array (
    ),
    'sola' => 
    array (
    ),
    'solund' => 
    array (
    ),
    'songdalen' => 
    array (
    ),
    'sortland' => 
    array (
    ),
    'spydeberg' => 
    array (
    ),
    'stange' => 
    array (
    ),
    'stavanger' => 
    array (
    ),
    'steigen' => 
    array (
    ),
    'steinkjer' => 
    array (
    ),
    'stjordal' => 
    array (
    ),
    'xn--stjrdal-s1a' => 
    array (
    ),
    'stokke' => 
    array (
    ),
    'stor-elvdal' => 
    array (
    ),
    'stord' => 
    array (
    ),
    'stordal' => 
    array (
    ),
    'storfjord' => 
    array (
    ),
    'omasvuotna' => 
    array (
    ),
    'strand' => 
    array (
    ),
    'stranda' => 
    array (
    ),
    'stryn' => 
    array (
    ),
    'sula' => 
    array (
    ),
    'suldal' => 
    array (
    ),
    'sund' => 
    array (
    ),
    'sunndal' => 
    array (
    ),
    'surnadal' => 
    array (
    ),
    'sveio' => 
    array (
    ),
    'svelvik' => 
    array (
    ),
    'sykkylven' => 
    array (
    ),
    'sogne' => 
    array (
    ),
    'xn--sgne-gra' => 
    array (
    ),
    'somna' => 
    array (
    ),
    'xn--smna-gra' => 
    array (
    ),
    'sondre-land' => 
    array (
    ),
    'xn--sndre-land-0cb' => 
    array (
    ),
    'sor-aurdal' => 
    array (
    ),
    'xn--sr-aurdal-l8a' => 
    array (
    ),
    'sor-fron' => 
    array (
    ),
    'xn--sr-fron-q1a' => 
    array (
    ),
    'sor-odal' => 
    array (
    ),
    'xn--sr-odal-q1a' => 
    array (
    ),
    'sor-varanger' => 
    array (
    ),
    'xn--sr-varanger-ggb' => 
    array (
    ),
    'matta-varjjat' => 
    array (
    ),
    'xn--mtta-vrjjat-k7af' => 
    array (
    ),
    'sorfold' => 
    array (
    ),
    'xn--srfold-bya' => 
    array (
    ),
    'sorreisa' => 
    array (
    ),
    'xn--srreisa-q1a' => 
    array (
    ),
    'sorum' => 
    array (
    ),
    'xn--srum-gra' => 
    array (
    ),
    'tana' => 
    array (
    ),
    'deatnu' => 
    array (
    ),
    'time' => 
    array (
    ),
    'tingvoll' => 
    array (
    ),
    'tinn' => 
    array (
    ),
    'tjeldsund' => 
    array (
    ),
    'dielddanuorri' => 
    array (
    ),
    'tjome' => 
    array (
    ),
    'xn--tjme-hra' => 
    array (
    ),
    'tokke' => 
    array (
    ),
    'tolga' => 
    array (
    ),
    'torsken' => 
    array (
    ),
    'tranoy' => 
    array (
    ),
    'xn--trany-yua' => 
    array (
    ),
    'tromso' => 
    array (
    ),
    'xn--troms-zua' => 
    array (
    ),
    'tromsa' => 
    array (
    ),
    'romsa' => 
    array (
    ),
    'trondheim' => 
    array (
    ),
    'troandin' => 
    array (
    ),
    'trysil' => 
    array (
    ),
    'trana' => 
    array (
    ),
    'xn--trna-woa' => 
    array (
    ),
    'trogstad' => 
    array (
    ),
    'xn--trgstad-r1a' => 
    array (
    ),
    'tvedestrand' => 
    array (
    ),
    'tydal' => 
    array (
    ),
    'tynset' => 
    array (
    ),
    'tysfjord' => 
    array (
    ),
    'divtasvuodna' => 
    array (
    ),
    'divttasvuotna' => 
    array (
    ),
    'tysnes' => 
    array (
    ),
    'tysvar' => 
    array (
    ),
    'xn--tysvr-vra' => 
    array (
    ),
    'tonsberg' => 
    array (
    ),
    'xn--tnsberg-q1a' => 
    array (
    ),
    'ullensaker' => 
    array (
    ),
    'ullensvang' => 
    array (
    ),
    'ulvik' => 
    array (
    ),
    'utsira' => 
    array (
    ),
    'vadso' => 
    array (
    ),
    'xn--vads-jra' => 
    array (
    ),
    'cahcesuolo' => 
    array (
    ),
    'xn--hcesuolo-7ya35b' => 
    array (
    ),
    'vaksdal' => 
    array (
    ),
    'valle' => 
    array (
    ),
    'vang' => 
    array (
    ),
    'vanylven' => 
    array (
    ),
    'vardo' => 
    array (
    ),
    'xn--vard-jra' => 
    array (
    ),
    'varggat' => 
    array (
    ),
    'xn--vrggt-xqad' => 
    array (
    ),
    'vefsn' => 
    array (
    ),
    'vaapste' => 
    array (
    ),
    'vega' => 
    array (
    ),
    'vegarshei' => 
    array (
    ),
    'xn--vegrshei-c0a' => 
    array (
    ),
    'vennesla' => 
    array (
    ),
    'verdal' => 
    array (
    ),
    'verran' => 
    array (
    ),
    'vestby' => 
    array (
    ),
    'vestnes' => 
    array (
    ),
    'vestre-slidre' => 
    array (
    ),
    'vestre-toten' => 
    array (
    ),
    'vestvagoy' => 
    array (
    ),
    'xn--vestvgy-ixa6o' => 
    array (
    ),
    'vevelstad' => 
    array (
    ),
    'vik' => 
    array (
    ),
    'vikna' => 
    array (
    ),
    'vindafjord' => 
    array (
    ),
    'volda' => 
    array (
    ),
    'voss' => 
    array (
    ),
    'varoy' => 
    array (
    ),
    'xn--vry-yla5g' => 
    array (
    ),
    'vagan' => 
    array (
    ),
    'xn--vgan-qoa' => 
    array (
    ),
    'voagat' => 
    array (
    ),
    'vagsoy' => 
    array (
    ),
    'xn--vgsy-qoa0j' => 
    array (
    ),
    'vaga' => 
    array (
    ),
    'xn--vg-yiab' => 
    array (
    ),
    'ostfold' => 
    array (
      'valer' => 
      array (
      ),
    ),
    'xn--stfold-9xa' => 
    array (
      'xn--vler-qoa' => 
      array (
      ),
    ),
    'co' => 
    array (
    ),
    'blogspot' => 
    array (
    ),
  ),
  'np' => 
  array (
    '*' => 
    array (
    ),
  ),
  'nr' => 
  array (
    'biz' => 
    array (
    ),
    'info' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'org' => 
    array (
    ),
    'net' => 
    array (
    ),
    'com' => 
    array (
    ),
  ),
  'nu' => 
  array (
    'merseine' => 
    array (
    ),
    'mine' => 
    array (
    ),
    'shacknet' => 
    array (
    ),
  ),
  'nz' => 
  array (
    'ac' => 
    array (
    ),
    'co' => 
    array (
      'blogspot' => 
      array (
      ),
    ),
    'cri' => 
    array (
    ),
    'geek' => 
    array (
    ),
    'gen' => 
    array (
    ),
    'govt' => 
    array (
    ),
    'health' => 
    array (
    ),
    'iwi' => 
    array (
    ),
    'kiwi' => 
    array (
    ),
    'maori' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'xn--mori-qsa' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'parliament' => 
    array (
    ),
    'school' => 
    array (
    ),
  ),
  'om' => 
  array (
    'co' => 
    array (
    ),
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'med' => 
    array (
    ),
    'museum' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'pro' => 
    array (
    ),
  ),
  'org' => 
  array (
    'ae' => 
    array (
    ),
    'us' => 
    array (
    ),
    'dyndns' => 
    array (
      'go' => 
      array (
      ),
      'home' => 
      array (
      ),
    ),
    'blogdns' => 
    array (
    ),
    'blogsite' => 
    array (
    ),
    'boldlygoingnowhere' => 
    array (
    ),
    'dnsalias' => 
    array (
    ),
    'dnsdojo' => 
    array (
    ),
    'doesntexist' => 
    array (
    ),
    'dontexist' => 
    array (
    ),
    'doomdns' => 
    array (
    ),
    'dvrdns' => 
    array (
    ),
    'dynalias' => 
    array (
    ),
    'endofinternet' => 
    array (
    ),
    'endoftheinternet' => 
    array (
    ),
    'from-me' => 
    array (
    ),
    'game-host' => 
    array (
    ),
    'gotdns' => 
    array (
    ),
    'hobby-site' => 
    array (
    ),
    'homedns' => 
    array (
    ),
    'homeftp' => 
    array (
    ),
    'homelinux' => 
    array (
    ),
    'homeunix' => 
    array (
    ),
    'is-a-bruinsfan' => 
    array (
    ),
    'is-a-candidate' => 
    array (
    ),
    'is-a-celticsfan' => 
    array (
    ),
    'is-a-chef' => 
    array (
    ),
    'is-a-geek' => 
    array (
    ),
    'is-a-knight' => 
    array (
    ),
    'is-a-linux-user' => 
    array (
    ),
    'is-a-patsfan' => 
    array (
    ),
    'is-a-soxfan' => 
    array (
    ),
    'is-found' => 
    array (
    ),
    'is-lost' => 
    array (
    ),
    'is-saved' => 
    array (
    ),
    'is-very-bad' => 
    array (
    ),
    'is-very-evil' => 
    array (
    ),
    'is-very-good' => 
    array (
    ),
    'is-very-nice' => 
    array (
    ),
    'is-very-sweet' => 
    array (
    ),
    'isa-geek' => 
    array (
    ),
    'kicks-ass' => 
    array (
    ),
    'misconfused' => 
    array (
    ),
    'podzone' => 
    array (
    ),
    'readmyblog' => 
    array (
    ),
    'selfip' => 
    array (
    ),
    'sellsyourhome' => 
    array (
    ),
    'servebbs' => 
    array (
    ),
    'serveftp' => 
    array (
    ),
    'servegame' => 
    array (
    ),
    'stuff-4-sale' => 
    array (
    ),
    'webhop' => 
    array (
    ),
    'hk' => 
    array (
    ),
    'za' => 
    array (
    ),
  ),
  'pa' => 
  array (
    'ac' => 
    array (
    ),
    'gob' => 
    array (
    ),
    'com' => 
    array (
    ),
    'org' => 
    array (
    ),
    'sld' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'net' => 
    array (
    ),
    'ing' => 
    array (
    ),
    'abo' => 
    array (
    ),
    'med' => 
    array (
    ),
    'nom' => 
    array (
    ),
  ),
  'pe' => 
  array (
    'edu' => 
    array (
    ),
    'gob' => 
    array (
    ),
    'nom' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'org' => 
    array (
    ),
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
  ),
  'pf' => 
  array (
    'com' => 
    array (
    ),
    'org' => 
    array (
    ),
    'edu' => 
    array (
    ),
  ),
  'pg' => 
  array (
    '*' => 
    array (
    ),
  ),
  'ph' => 
  array (
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'ngo' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'i' => 
    array (
    ),
  ),
  'pk' => 
  array (
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'org' => 
    array (
    ),
    'fam' => 
    array (
    ),
    'biz' => 
    array (
    ),
    'web' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'gob' => 
    array (
    ),
    'gok' => 
    array (
    ),
    'gon' => 
    array (
    ),
    'gop' => 
    array (
    ),
    'gos' => 
    array (
    ),
    'info' => 
    array (
    ),
  ),
  'pl' => 
  array (
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'info' => 
    array (
    ),
    'waw' => 
    array (
    ),
    'gov' => 
    array (
      'uw' => 
      array (
      ),
      'um' => 
      array (
      ),
      'ug' => 
      array (
      ),
      'upow' => 
      array (
      ),
      'starostwo' => 
      array (
      ),
      'so' => 
      array (
      ),
      'sr' => 
      array (
      ),
      'po' => 
      array (
      ),
      'pa' => 
      array (
      ),
    ),
    'aid' => 
    array (
    ),
    'agro' => 
    array (
    ),
    'atm' => 
    array (
    ),
    'auto' => 
    array (
    ),
    'biz' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gmina' => 
    array (
    ),
    'gsm' => 
    array (
    ),
    'mail' => 
    array (
    ),
    'miasta' => 
    array (
    ),
    'media' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'nieruchomosci' => 
    array (
    ),
    'nom' => 
    array (
    ),
    'pc' => 
    array (
    ),
    'powiat' => 
    array (
    ),
    'priv' => 
    array (
    ),
    'realestate' => 
    array (
    ),
    'rel' => 
    array (
    ),
    'sex' => 
    array (
    ),
    'shop' => 
    array (
    ),
    'sklep' => 
    array (
    ),
    'sos' => 
    array (
    ),
    'szkola' => 
    array (
    ),
    'targi' => 
    array (
    ),
    'tm' => 
    array (
    ),
    'tourism' => 
    array (
    ),
    'travel' => 
    array (
    ),
    'turystyka' => 
    array (
    ),
    'augustow' => 
    array (
    ),
    'babia-gora' => 
    array (
    ),
    'bedzin' => 
    array (
    ),
    'beskidy' => 
    array (
    ),
    'bialowieza' => 
    array (
    ),
    'bialystok' => 
    array (
    ),
    'bielawa' => 
    array (
    ),
    'bieszczady' => 
    array (
    ),
    'boleslawiec' => 
    array (
    ),
    'bydgoszcz' => 
    array (
    ),
    'bytom' => 
    array (
    ),
    'cieszyn' => 
    array (
    ),
    'czeladz' => 
    array (
    ),
    'czest' => 
    array (
    ),
    'dlugoleka' => 
    array (
    ),
    'elblag' => 
    array (
    ),
    'elk' => 
    array (
    ),
    'glogow' => 
    array (
    ),
    'gniezno' => 
    array (
    ),
    'gorlice' => 
    array (
    ),
    'grajewo' => 
    array (
    ),
    'ilawa' => 
    array (
    ),
    'jaworzno' => 
    array (
    ),
    'jelenia-gora' => 
    array (
    ),
    'jgora' => 
    array (
    ),
    'kalisz' => 
    array (
    ),
    'kazimierz-dolny' => 
    array (
    ),
    'karpacz' => 
    array (
    ),
    'kartuzy' => 
    array (
    ),
    'kaszuby' => 
    array (
    ),
    'katowice' => 
    array (
    ),
    'kepno' => 
    array (
    ),
    'ketrzyn' => 
    array (
    ),
    'klodzko' => 
    array (
    ),
    'kobierzyce' => 
    array (
    ),
    'kolobrzeg' => 
    array (
    ),
    'konin' => 
    array (
    ),
    'konskowola' => 
    array (
    ),
    'kutno' => 
    array (
    ),
    'lapy' => 
    array (
    ),
    'lebork' => 
    array (
    ),
    'legnica' => 
    array (
    ),
    'lezajsk' => 
    array (
    ),
    'limanowa' => 
    array (
    ),
    'lomza' => 
    array (
    ),
    'lowicz' => 
    array (
    ),
    'lubin' => 
    array (
    ),
    'lukow' => 
    array (
    ),
    'malbork' => 
    array (
    ),
    'malopolska' => 
    array (
    ),
    'mazowsze' => 
    array (
    ),
    'mazury' => 
    array (
    ),
    'mielec' => 
    array (
    ),
    'mielno' => 
    array (
    ),
    'mragowo' => 
    array (
    ),
    'naklo' => 
    array (
    ),
    'nowaruda' => 
    array (
    ),
    'nysa' => 
    array (
    ),
    'olawa' => 
    array (
    ),
    'olecko' => 
    array (
    ),
    'olkusz' => 
    array (
    ),
    'olsztyn' => 
    array (
    ),
    'opoczno' => 
    array (
    ),
    'opole' => 
    array (
    ),
    'ostroda' => 
    array (
    ),
    'ostroleka' => 
    array (
    ),
    'ostrowiec' => 
    array (
    ),
    'ostrowwlkp' => 
    array (
    ),
    'pila' => 
    array (
    ),
    'pisz' => 
    array (
    ),
    'podhale' => 
    array (
    ),
    'podlasie' => 
    array (
    ),
    'polkowice' => 
    array (
    ),
    'pomorze' => 
    array (
    ),
    'pomorskie' => 
    array (
    ),
    'prochowice' => 
    array (
    ),
    'pruszkow' => 
    array (
    ),
    'przeworsk' => 
    array (
    ),
    'pulawy' => 
    array (
    ),
    'radom' => 
    array (
    ),
    'rawa-maz' => 
    array (
    ),
    'rybnik' => 
    array (
    ),
    'rzeszow' => 
    array (
    ),
    'sanok' => 
    array (
    ),
    'sejny' => 
    array (
    ),
    'slask' => 
    array (
    ),
    'slupsk' => 
    array (
    ),
    'sosnowiec' => 
    array (
    ),
    'stalowa-wola' => 
    array (
    ),
    'skoczow' => 
    array (
    ),
    'starachowice' => 
    array (
    ),
    'stargard' => 
    array (
    ),
    'suwalki' => 
    array (
    ),
    'swidnica' => 
    array (
    ),
    'swiebodzin' => 
    array (
    ),
    'swinoujscie' => 
    array (
    ),
    'szczecin' => 
    array (
    ),
    'szczytno' => 
    array (
    ),
    'tarnobrzeg' => 
    array (
    ),
    'tgory' => 
    array (
    ),
    'turek' => 
    array (
    ),
    'tychy' => 
    array (
    ),
    'ustka' => 
    array (
    ),
    'walbrzych' => 
    array (
    ),
    'warmia' => 
    array (
    ),
    'warszawa' => 
    array (
    ),
    'wegrow' => 
    array (
    ),
    'wielun' => 
    array (
    ),
    'wlocl' => 
    array (
    ),
    'wloclawek' => 
    array (
    ),
    'wodzislaw' => 
    array (
    ),
    'wolomin' => 
    array (
    ),
    'wroclaw' => 
    array (
    ),
    'zachpomor' => 
    array (
    ),
    'zagan' => 
    array (
    ),
    'zarow' => 
    array (
    ),
    'zgora' => 
    array (
    ),
    'zgorzelec' => 
    array (
    ),
    'co' => 
    array (
    ),
    'art' => 
    array (
    ),
    'gliwice' => 
    array (
    ),
    'krakow' => 
    array (
    ),
    'poznan' => 
    array (
    ),
    'wroc' => 
    array (
    ),
    'zakopane' => 
    array (
    ),
    'gda' => 
    array (
    ),
    'gdansk' => 
    array (
    ),
    'gdynia' => 
    array (
    ),
    'med' => 
    array (
    ),
    'sopot' => 
    array (
    ),
  ),
  'pm' => 
  array (
  ),
  'pn' => 
  array (
    'gov' => 
    array (
    ),
    'co' => 
    array (
    ),
    'org' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'net' => 
    array (
    ),
  ),
  'post' => 
  array (
  ),
  'pr' => 
  array (
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'isla' => 
    array (
    ),
    'pro' => 
    array (
    ),
    'biz' => 
    array (
    ),
    'info' => 
    array (
    ),
    'name' => 
    array (
    ),
    'est' => 
    array (
    ),
    'prof' => 
    array (
    ),
    'ac' => 
    array (
    ),
  ),
  'pro' => 
  array (
    'aca' => 
    array (
    ),
    'bar' => 
    array (
    ),
    'cpa' => 
    array (
    ),
    'jur' => 
    array (
    ),
    'law' => 
    array (
    ),
    'med' => 
    array (
    ),
    'eng' => 
    array (
    ),
  ),
  'ps' => 
  array (
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'sec' => 
    array (
    ),
    'plo' => 
    array (
    ),
    'com' => 
    array (
    ),
    'org' => 
    array (
    ),
    'net' => 
    array (
    ),
  ),
  'pt' => 
  array (
    'net' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'org' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'int' => 
    array (
    ),
    'publ' => 
    array (
    ),
    'com' => 
    array (
    ),
    'nome' => 
    array (
    ),
    'blogspot' => 
    array (
    ),
  ),
  'pw' => 
  array (
    'co' => 
    array (
    ),
    'ne' => 
    array (
    ),
    'or' => 
    array (
    ),
    'ed' => 
    array (
    ),
    'go' => 
    array (
    ),
    'belau' => 
    array (
    ),
  ),
  'py' => 
  array (
    'com' => 
    array (
    ),
    'coop' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
  ),
  'qa' => 
  array (
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'name' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'sch' => 
    array (
    ),
  ),
  're' => 
  array (
    'com' => 
    array (
    ),
    'asso' => 
    array (
    ),
    'nom' => 
    array (
    ),
    'blogspot' => 
    array (
    ),
  ),
  'ro' => 
  array (
    'com' => 
    array (
    ),
    'org' => 
    array (
    ),
    'tm' => 
    array (
    ),
    'nt' => 
    array (
    ),
    'nom' => 
    array (
    ),
    'info' => 
    array (
    ),
    'rec' => 
    array (
    ),
    'arts' => 
    array (
    ),
    'firm' => 
    array (
    ),
    'store' => 
    array (
    ),
    'www' => 
    array (
    ),
    'blogspot' => 
    array (
    ),
  ),
  'rs' => 
  array (
    'co' => 
    array (
    ),
    'org' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'ac' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'in' => 
    array (
    ),
  ),
  'ru' => 
  array (
    'ac' => 
    array (
    ),
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'int' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'pp' => 
    array (
    ),
    'adygeya' => 
    array (
    ),
    'altai' => 
    array (
    ),
    'amur' => 
    array (
    ),
    'arkhangelsk' => 
    array (
    ),
    'astrakhan' => 
    array (
    ),
    'bashkiria' => 
    array (
    ),
    'belgorod' => 
    array (
    ),
    'bir' => 
    array (
    ),
    'bryansk' => 
    array (
    ),
    'buryatia' => 
    array (
    ),
    'cbg' => 
    array (
    ),
    'chel' => 
    array (
    ),
    'chelyabinsk' => 
    array (
    ),
    'chita' => 
    array (
    ),
    'chukotka' => 
    array (
    ),
    'chuvashia' => 
    array (
    ),
    'dagestan' => 
    array (
    ),
    'dudinka' => 
    array (
    ),
    'e-burg' => 
    array (
    ),
    'grozny' => 
    array (
    ),
    'irkutsk' => 
    array (
    ),
    'ivanovo' => 
    array (
    ),
    'izhevsk' => 
    array (
    ),
    'jar' => 
    array (
    ),
    'joshkar-ola' => 
    array (
    ),
    'kalmykia' => 
    array (
    ),
    'kaluga' => 
    array (
    ),
    'kamchatka' => 
    array (
    ),
    'karelia' => 
    array (
    ),
    'kazan' => 
    array (
    ),
    'kchr' => 
    array (
    ),
    'kemerovo' => 
    array (
    ),
    'khabarovsk' => 
    array (
    ),
    'khakassia' => 
    array (
    ),
    'khv' => 
    array (
    ),
    'kirov' => 
    array (
    ),
    'koenig' => 
    array (
    ),
    'komi' => 
    array (
    ),
    'kostroma' => 
    array (
    ),
    'krasnoyarsk' => 
    array (
    ),
    'kuban' => 
    array (
    ),
    'kurgan' => 
    array (
    ),
    'kursk' => 
    array (
    ),
    'lipetsk' => 
    array (
    ),
    'magadan' => 
    array (
    ),
    'mari' => 
    array (
    ),
    'mari-el' => 
    array (
    ),
    'marine' => 
    array (
    ),
    'mordovia' => 
    array (
    ),
    'msk' => 
    array (
    ),
    'murmansk' => 
    array (
    ),
    'nalchik' => 
    array (
    ),
    'nnov' => 
    array (
    ),
    'nov' => 
    array (
    ),
    'novosibirsk' => 
    array (
    ),
    'nsk' => 
    array (
    ),
    'omsk' => 
    array (
    ),
    'orenburg' => 
    array (
    ),
    'oryol' => 
    array (
    ),
    'palana' => 
    array (
    ),
    'penza' => 
    array (
    ),
    'perm' => 
    array (
    ),
    'ptz' => 
    array (
    ),
    'rnd' => 
    array (
    ),
    'ryazan' => 
    array (
    ),
    'sakhalin' => 
    array (
    ),
    'samara' => 
    array (
    ),
    'saratov' => 
    array (
    ),
    'simbirsk' => 
    array (
    ),
    'smolensk' => 
    array (
    ),
    'spb' => 
    array (
    ),
    'stavropol' => 
    array (
    ),
    'stv' => 
    array (
    ),
    'surgut' => 
    array (
    ),
    'tambov' => 
    array (
    ),
    'tatarstan' => 
    array (
    ),
    'tom' => 
    array (
    ),
    'tomsk' => 
    array (
    ),
    'tsaritsyn' => 
    array (
    ),
    'tsk' => 
    array (
    ),
    'tula' => 
    array (
    ),
    'tuva' => 
    array (
    ),
    'tver' => 
    array (
    ),
    'tyumen' => 
    array (
    ),
    'udm' => 
    array (
    ),
    'udmurtia' => 
    array (
    ),
    'ulan-ude' => 
    array (
    ),
    'vladikavkaz' => 
    array (
    ),
    'vladimir' => 
    array (
    ),
    'vladivostok' => 
    array (
    ),
    'volgograd' => 
    array (
    ),
    'vologda' => 
    array (
    ),
    'voronezh' => 
    array (
    ),
    'vrn' => 
    array (
    ),
    'vyatka' => 
    array (
    ),
    'yakutia' => 
    array (
    ),
    'yamal' => 
    array (
    ),
    'yaroslavl' => 
    array (
    ),
    'yekaterinburg' => 
    array (
    ),
    'yuzhno-sakhalinsk' => 
    array (
    ),
    'amursk' => 
    array (
    ),
    'baikal' => 
    array (
    ),
    'cmw' => 
    array (
    ),
    'fareast' => 
    array (
    ),
    'jamal' => 
    array (
    ),
    'kms' => 
    array (
    ),
    'k-uralsk' => 
    array (
    ),
    'kustanai' => 
    array (
    ),
    'kuzbass' => 
    array (
    ),
    'magnitka' => 
    array (
    ),
    'mytis' => 
    array (
    ),
    'nakhodka' => 
    array (
    ),
    'nkz' => 
    array (
    ),
    'norilsk' => 
    array (
    ),
    'oskol' => 
    array (
    ),
    'pyatigorsk' => 
    array (
    ),
    'rubtsovsk' => 
    array (
    ),
    'snz' => 
    array (
    ),
    'syzran' => 
    array (
    ),
    'vdonsk' => 
    array (
    ),
    'zgrad' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'test' => 
    array (
    ),
    'blogspot' => 
    array (
    ),
  ),
  'rw' => 
  array (
    'gov' => 
    array (
    ),
    'net' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'ac' => 
    array (
    ),
    'com' => 
    array (
    ),
    'co' => 
    array (
    ),
    'int' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'gouv' => 
    array (
    ),
  ),
  'sa' => 
  array (
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'med' => 
    array (
    ),
    'pub' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'sch' => 
    array (
    ),
  ),
  'sb' => 
  array (
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
  ),
  'sc' => 
  array (
    'com' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'edu' => 
    array (
    ),
  ),
  'sd' => 
  array (
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'med' => 
    array (
    ),
    'tv' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'info' => 
    array (
    ),
  ),
  'se' => 
  array (
    'a' => 
    array (
    ),
    'ac' => 
    array (
    ),
    'b' => 
    array (
    ),
    'bd' => 
    array (
    ),
    'brand' => 
    array (
    ),
    'c' => 
    array (
    ),
    'd' => 
    array (
    ),
    'e' => 
    array (
    ),
    'f' => 
    array (
    ),
    'fh' => 
    array (
    ),
    'fhsk' => 
    array (
    ),
    'fhv' => 
    array (
    ),
    'g' => 
    array (
    ),
    'h' => 
    array (
    ),
    'i' => 
    array (
    ),
    'k' => 
    array (
    ),
    'komforb' => 
    array (
    ),
    'kommunalforbund' => 
    array (
    ),
    'komvux' => 
    array (
    ),
    'l' => 
    array (
    ),
    'lanbib' => 
    array (
    ),
    'm' => 
    array (
    ),
    'n' => 
    array (
    ),
    'naturbruksgymn' => 
    array (
    ),
    'o' => 
    array (
    ),
    'org' => 
    array (
    ),
    'p' => 
    array (
    ),
    'parti' => 
    array (
    ),
    'pp' => 
    array (
    ),
    'press' => 
    array (
    ),
    'r' => 
    array (
    ),
    's' => 
    array (
    ),
    't' => 
    array (
    ),
    'tm' => 
    array (
    ),
    'u' => 
    array (
    ),
    'w' => 
    array (
    ),
    'x' => 
    array (
    ),
    'y' => 
    array (
    ),
    'z' => 
    array (
    ),
    'com' => 
    array (
    ),
    'blogspot' => 
    array (
    ),
  ),
  'sg' => 
  array (
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'per' => 
    array (
    ),
    'blogspot' => 
    array (
    ),
  ),
  'sh' => 
  array (
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'org' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'platform' => 
    array (
      '*' => 
      array (
      ),
    ),
  ),
  'si' => 
  array (
  ),
  'sj' => 
  array (
  ),
  'sk' => 
  array (
    'blogspot' => 
    array (
    ),
  ),
  'sl' => 
  array (
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'org' => 
    array (
    ),
  ),
  'sm' => 
  array (
  ),
  'sn' => 
  array (
    'art' => 
    array (
    ),
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gouv' => 
    array (
    ),
    'org' => 
    array (
    ),
    'perso' => 
    array (
    ),
    'univ' => 
    array (
    ),
  ),
  'so' => 
  array (
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
  ),
  'sr' => 
  array (
  ),
  'st' => 
  array (
    'co' => 
    array (
    ),
    'com' => 
    array (
    ),
    'consulado' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'embaixada' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'principe' => 
    array (
    ),
    'saotome' => 
    array (
    ),
    'store' => 
    array (
    ),
  ),
  'su' => 
  array (
    'adygeya' => 
    array (
    ),
    'arkhangelsk' => 
    array (
    ),
    'balashov' => 
    array (
    ),
    'bashkiria' => 
    array (
    ),
    'bryansk' => 
    array (
    ),
    'dagestan' => 
    array (
    ),
    'grozny' => 
    array (
    ),
    'ivanovo' => 
    array (
    ),
    'kalmykia' => 
    array (
    ),
    'kaluga' => 
    array (
    ),
    'karelia' => 
    array (
    ),
    'khakassia' => 
    array (
    ),
    'krasnodar' => 
    array (
    ),
    'kurgan' => 
    array (
    ),
    'lenug' => 
    array (
    ),
    'mordovia' => 
    array (
    ),
    'msk' => 
    array (
    ),
    'murmansk' => 
    array (
    ),
    'nalchik' => 
    array (
    ),
    'nov' => 
    array (
    ),
    'obninsk' => 
    array (
    ),
    'penza' => 
    array (
    ),
    'pokrovsk' => 
    array (
    ),
    'sochi' => 
    array (
    ),
    'spb' => 
    array (
    ),
    'togliatti' => 
    array (
    ),
    'troitsk' => 
    array (
    ),
    'tula' => 
    array (
    ),
    'tuva' => 
    array (
    ),
    'vladikavkaz' => 
    array (
    ),
    'vladimir' => 
    array (
    ),
    'vologda' => 
    array (
    ),
  ),
  'sv' => 
  array (
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gob' => 
    array (
    ),
    'org' => 
    array (
    ),
    'red' => 
    array (
    ),
  ),
  'sx' => 
  array (
    'gov' => 
    array (
    ),
  ),
  'sy' => 
  array (
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'net' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'com' => 
    array (
    ),
    'org' => 
    array (
    ),
  ),
  'sz' => 
  array (
    'co' => 
    array (
    ),
    'ac' => 
    array (
    ),
    'org' => 
    array (
    ),
  ),
  'tc' => 
  array (
  ),
  'td' => 
  array (
    'blogspot' => 
    array (
    ),
  ),
  'tel' => 
  array (
  ),
  'tf' => 
  array (
  ),
  'tg' => 
  array (
  ),
  'th' => 
  array (
    'ac' => 
    array (
    ),
    'co' => 
    array (
    ),
    'go' => 
    array (
    ),
    'in' => 
    array (
    ),
    'mi' => 
    array (
    ),
    'net' => 
    array (
    ),
    'or' => 
    array (
    ),
  ),
  'tj' => 
  array (
    'ac' => 
    array (
    ),
    'biz' => 
    array (
    ),
    'co' => 
    array (
    ),
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'go' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'int' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'name' => 
    array (
    ),
    'net' => 
    array (
    ),
    'nic' => 
    array (
    ),
    'org' => 
    array (
    ),
    'test' => 
    array (
    ),
    'web' => 
    array (
    ),
  ),
  'tk' => 
  array (
  ),
  'tl' => 
  array (
    'gov' => 
    array (
    ),
  ),
  'tm' => 
  array (
    'com' => 
    array (
    ),
    'co' => 
    array (
    ),
    'org' => 
    array (
    ),
    'net' => 
    array (
    ),
    'nom' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'edu' => 
    array (
    ),
  ),
  'tn' => 
  array (
    'com' => 
    array (
    ),
    'ens' => 
    array (
    ),
    'fin' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'ind' => 
    array (
    ),
    'intl' => 
    array (
    ),
    'nat' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'info' => 
    array (
    ),
    'perso' => 
    array (
    ),
    'tourism' => 
    array (
    ),
    'edunet' => 
    array (
    ),
    'rnrt' => 
    array (
    ),
    'rns' => 
    array (
    ),
    'rnu' => 
    array (
    ),
    'mincom' => 
    array (
    ),
    'agrinet' => 
    array (
    ),
    'defense' => 
    array (
    ),
    'turen' => 
    array (
    ),
  ),
  'to' => 
  array (
    'com' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'mil' => 
    array (
    ),
  ),
  'tp' => 
  array (
  ),
  'tr' => 
  array (
    'com' => 
    array (
      'blogspot' => 
      array (
      ),
    ),
    'info' => 
    array (
    ),
    'biz' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'web' => 
    array (
    ),
    'gen' => 
    array (
    ),
    'tv' => 
    array (
    ),
    'av' => 
    array (
    ),
    'dr' => 
    array (
    ),
    'bbs' => 
    array (
    ),
    'name' => 
    array (
    ),
    'tel' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'bel' => 
    array (
    ),
    'pol' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'k12' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'kep' => 
    array (
    ),
    'nc' => 
    array (
      'gov' => 
      array (
      ),
    ),
  ),
  'travel' => 
  array (
  ),
  'tt' => 
  array (
    'co' => 
    array (
    ),
    'com' => 
    array (
    ),
    'org' => 
    array (
    ),
    'net' => 
    array (
    ),
    'biz' => 
    array (
    ),
    'info' => 
    array (
    ),
    'pro' => 
    array (
    ),
    'int' => 
    array (
    ),
    'coop' => 
    array (
    ),
    'jobs' => 
    array (
    ),
    'mobi' => 
    array (
    ),
    'travel' => 
    array (
    ),
    'museum' => 
    array (
    ),
    'aero' => 
    array (
    ),
    'name' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'edu' => 
    array (
    ),
  ),
  'tv' => 
  array (
    'dyndns' => 
    array (
    ),
    'better-than' => 
    array (
    ),
    'on-the-web' => 
    array (
    ),
    'worse-than' => 
    array (
    ),
  ),
  'tw' => 
  array (
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'idv' => 
    array (
    ),
    'game' => 
    array (
    ),
    'ebiz' => 
    array (
    ),
    'club' => 
    array (
    ),
    'xn--zf0ao64a' => 
    array (
    ),
    'xn--uc0atv' => 
    array (
    ),
    'xn--czrw28b' => 
    array (
    ),
    'blogspot' => 
    array (
    ),
  ),
  'tz' => 
  array (
    'ac' => 
    array (
    ),
    'co' => 
    array (
    ),
    'go' => 
    array (
    ),
    'hotel' => 
    array (
    ),
    'info' => 
    array (
    ),
    'me' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'mobi' => 
    array (
    ),
    'ne' => 
    array (
    ),
    'or' => 
    array (
    ),
    'sc' => 
    array (
    ),
    'tv' => 
    array (
    ),
  ),
  'ua' => 
  array (
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'in' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'cherkassy' => 
    array (
    ),
    'cherkasy' => 
    array (
    ),
    'chernigov' => 
    array (
    ),
    'chernihiv' => 
    array (
    ),
    'chernivtsi' => 
    array (
    ),
    'chernovtsy' => 
    array (
    ),
    'ck' => 
    array (
    ),
    'cn' => 
    array (
    ),
    'cr' => 
    array (
    ),
    'crimea' => 
    array (
    ),
    'cv' => 
    array (
    ),
    'dn' => 
    array (
    ),
    'dnepropetrovsk' => 
    array (
    ),
    'dnipropetrovsk' => 
    array (
    ),
    'dominic' => 
    array (
    ),
    'donetsk' => 
    array (
    ),
    'dp' => 
    array (
    ),
    'if' => 
    array (
    ),
    'ivano-frankivsk' => 
    array (
    ),
    'kh' => 
    array (
    ),
    'kharkiv' => 
    array (
    ),
    'kharkov' => 
    array (
    ),
    'kherson' => 
    array (
    ),
    'khmelnitskiy' => 
    array (
    ),
    'khmelnytskyi' => 
    array (
    ),
    'kiev' => 
    array (
    ),
    'kirovograd' => 
    array (
    ),
    'km' => 
    array (
    ),
    'kr' => 
    array (
    ),
    'krym' => 
    array (
    ),
    'ks' => 
    array (
    ),
    'kv' => 
    array (
    ),
    'kyiv' => 
    array (
    ),
    'lg' => 
    array (
    ),
    'lt' => 
    array (
    ),
    'lugansk' => 
    array (
    ),
    'lutsk' => 
    array (
    ),
    'lv' => 
    array (
    ),
    'lviv' => 
    array (
    ),
    'mk' => 
    array (
    ),
    'mykolaiv' => 
    array (
    ),
    'nikolaev' => 
    array (
    ),
    'od' => 
    array (
    ),
    'odesa' => 
    array (
    ),
    'odessa' => 
    array (
    ),
    'pl' => 
    array (
    ),
    'poltava' => 
    array (
    ),
    'rivne' => 
    array (
    ),
    'rovno' => 
    array (
    ),
    'rv' => 
    array (
    ),
    'sb' => 
    array (
    ),
    'sebastopol' => 
    array (
    ),
    'sevastopol' => 
    array (
    ),
    'sm' => 
    array (
    ),
    'sumy' => 
    array (
    ),
    'te' => 
    array (
    ),
    'ternopil' => 
    array (
    ),
    'uz' => 
    array (
    ),
    'uzhgorod' => 
    array (
    ),
    'vinnica' => 
    array (
    ),
    'vinnytsia' => 
    array (
    ),
    'vn' => 
    array (
    ),
    'volyn' => 
    array (
    ),
    'yalta' => 
    array (
    ),
    'zaporizhzhe' => 
    array (
    ),
    'zaporizhzhia' => 
    array (
    ),
    'zhitomir' => 
    array (
    ),
    'zhytomyr' => 
    array (
    ),
    'zp' => 
    array (
    ),
    'zt' => 
    array (
    ),
    'co' => 
    array (
    ),
    'pp' => 
    array (
    ),
  ),
  'ug' => 
  array (
    'co' => 
    array (
    ),
    'or' => 
    array (
    ),
    'ac' => 
    array (
    ),
    'sc' => 
    array (
    ),
    'go' => 
    array (
    ),
    'ne' => 
    array (
    ),
    'com' => 
    array (
    ),
    'org' => 
    array (
    ),
  ),
  'uk' => 
  array (
    'ac' => 
    array (
    ),
    'co' => 
    array (
      'blogspot' => 
      array (
      ),
    ),
    'gov' => 
    array (
      'service' => 
      array (
      ),
    ),
    'ltd' => 
    array (
    ),
    'me' => 
    array (
    ),
    'net' => 
    array (
    ),
    'nhs' => 
    array (
    ),
    'org' => 
    array (
    ),
    'plc' => 
    array (
    ),
    'police' => 
    array (
    ),
    'sch' => 
    array (
      '*' => 
      array (
      ),
    ),
  ),
  'us' => 
  array (
    'dni' => 
    array (
    ),
    'fed' => 
    array (
    ),
    'isa' => 
    array (
    ),
    'kids' => 
    array (
    ),
    'nsn' => 
    array (
    ),
    'ak' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'al' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'ar' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'as' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'az' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'ca' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'co' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'ct' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'dc' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'de' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'fl' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'ga' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'gu' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'hi' => 
    array (
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'ia' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'id' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'il' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'in' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'ks' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'ky' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'la' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'ma' => 
    array (
      'k12' => 
      array (
        'pvt' => 
        array (
        ),
        'chtr' => 
        array (
        ),
        'paroch' => 
        array (
        ),
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'md' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'me' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'mi' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'mn' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'mo' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'ms' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'mt' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'nc' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'nd' => 
    array (
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'ne' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'nh' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'nj' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'nm' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'nv' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'ny' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'oh' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'ok' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'or' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'pa' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'pr' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'ri' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'sc' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'sd' => 
    array (
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'tn' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'tx' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'ut' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'vi' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'vt' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'va' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'wa' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'wi' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (
      ),
      'lib' => 
      array (
      ),
    ),
    'wv' => 
    array (
      'cc' => 
      array (
      ),
    ),
    'wy' => 
    array (
      'k12' => 
      array (
      ),
      'cc' => 
      array (

      ),
      'lib' => 
      array (
      ),
    ),
    'is-by' => 
    array (
    ),
    'land-4-sale' => 
    array (
    ),
    'stuff-4-sale' => 
    array (
    ),
  ),
  'uy' => 
  array (
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gub' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
  ),
  'uz' => 
  array (
    'co' => 
    array (
    ),
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
  ),
  'va' => 
  array (
  ),
  'vc' => 
  array (
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'edu' => 
    array (
    ),
  ),
  've' => 
  array (
    'arts' => 
    array (
    ),
    'co' => 
    array (
    ),
    'com' => 
    array (
    ),
    'e12' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'firm' => 
    array (
    ),
    'gob' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'info' => 
    array (
    ),
    'int' => 
    array (
    ),
    'mil' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'rec' => 
    array (
    ),
    'store' => 
    array (
    ),
    'tec' => 
    array (
    ),
    'web' => 
    array (
    ),
  ),
  'vg' => 
  array (
  ),
  'vi' => 
  array (
    'co' => 
    array (
    ),
    'com' => 
    array (
    ),
    'k12' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
  ),
  'vn' => 
  array (
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'int' => 
    array (
    ),
    'ac' => 
    array (
    ),
    'biz' => 
    array (
    ),
    'info' => 
    array (
    ),
    'name' => 
    array (
    ),
    'pro' => 
    array (
    ),
    'health' => 
    array (
    ),
  ),
  'vu' => 
  array (
    'com' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
  ),
  'wf' => 
  array (
  ),
  'ws' => 
  array (
    'com' => 
    array (
    ),
    'net' => 
    array (
    ),
    'org' => 
    array (
    ),
    'gov' => 
    array (
    ),
    'edu' => 
    array (
    ),
    'dyndns' => 
    array (
    ),
    'mypets' => 
    array (
    ),
  ),
  'yt' => 
  array (
  ),
  'xn--mgbaam7a8h' => 
  array (
  ),
  'xn--54b7fta0cc' => 
  array (
  ),
  'xn--fiqs8s' => 
  array (
  ),
  'xn--fiqz9s' => 
  array (
  ),
  'xn--lgbbat1ad8j' => 
  array (
  ),
  'xn--wgbh1c' => 
  array (
  ),
  'xn--node' => 
  array (
  ),
  'xn--j6w193g' => 
  array (
  ),
  'xn--h2brj9c' => 
  array (
  ),
  'xn--mgbbh1a71e' => 
  array (
  ),
  'xn--fpcrj9c3d' => 
  array (
  ),
  'xn--gecrj9c' => 
  array (
  ),
  'xn--s9brj9c' => 
  array (
  ),
  'xn--45brj9c' => 
  array (
  ),
  'xn--xkc2dl3a5ee0h' => 
  array (
  ),
  'xn--mgba3a4f16a' => 
  array (
  ),
  'xn--mgba3a4fra' => 
  array (
  ),
  'xn--mgbayh7gpa' => 
  array (
  ),
  'xn--3e0b707e' => 
  array (
  ),
  'xn--80ao21a' => 
  array (
  ),
  'xn--fzc2c9e2c' => 
  array (
  ),
  'xn--xkc2al3hye2a' => 
  array (
  ),
  'xn--mgbc0a9azcg' => 
  array (
  ),
  'xn--l1acc' => 
  array (
  ),
  'xn--mgbx4cd0ab' => 
  array (
  ),
  'xn--mgb9awbf' => 
  array (
  ),
  'xn--ygbi2ammx' => 
  array (
  ),
  'xn--90a3ac' => 
  array (
    'xn--o1ac' => 
    array (
    ),
    'xn--c1avg' => 
    array (
    ),
    'xn--90azh' => 
    array (
    ),
    'xn--d1at' => 
    array (
    ),
    'xn--o1ach' => 
    array (
    ),
    'xn--80au' => 
    array (
    ),

  ),
  'xn--p1ai' => 
  array (
  ),
  'xn--wgbl6a' => 
  array (
  ),
  'xn--mgberp4a5d4ar' => 
  array (
  ),
  'xn--mgberp4a5d4a87g' => 
  array (
  ),
  'xn--mgbqly7c0a67fbc' => 
  array (
  ),
  'xn--mgbqly7cvafr' => 
  array (
  ),
  'xn--ogbpf8fl' => 
  array (
  ),
  'xn--mgbtf8fl' => 
  array (
  ),
  'xn--yfro4i67o' => 
  array (
  ),
  'xn--clchc0ea0b2g2a9gcd' => 
  array (
  ),
  'xn--o3cw4h' => 
  array (
  ),
  'xn--pgbs0dh' => 
  array (
  ),
  'xn--kpry57d' => 
  array (
  ),
  'xn--kprw13d' => 
  array (
  ),
  'xn--nnx388a' => 
  array (
  ),
  'xn--j1amh' => 
  array (
  ),
  'xn--mgb2ddes' => 
  array (
  ),
  'xxx' => 
  array (
  ),
  'ye' => 
  array (
    '*' => 
    array (
    ),
  ),
  'za' => 
  array (
    '*' => 
    array (
    ),
  ),
  'zm' => 
  array (
    '*' => 
    array (
    ),
  ),
  'zw' => 
  array (
    '*' => 
    array (
    ),
  ),
  'abb' => 
  array (
  ),
  'abbott' => 
  array (
  ),
  'abogado' => 
  array (
  ),
  'academy' => 
  array (
  ),
  'accenture' => 
  array (
  ),
  'accountant' => 
  array (
  ),
  'accountants' => 
  array (
  ),
  'aco' => 
  array (
  ),
  'active' => 
  array (
  ),
  'actor' => 
  array (
  ),
  'ads' => 
  array (
  ),
  'adult' => 
  array (
  ),
  'afl' => 
  array (
  ),
  'africa' => 
  array (
  ),
  'agency' => 
  array (
  ),
  'aig' => 
  array (
  ),
  'airforce' => 
  array (
  ),
  'airtel' => 
  array (
  ),
  'alibaba' => 
  array (
  ),
  'alipay' => 
  array (
  ),
  'allfinanz' => 
  array (
  ),
  'alsace' => 
  array (
  ),
  'amsterdam' => 
  array (
  ),
  'analytics' => 
  array (
  ),
  'android' => 
  array (
  ),
  'anquan' => 
  array (
  ),
  'apartments' => 
  array (
  ),
  'aquarelle' => 
  array (
  ),
  'aramco' => 
  array (
  ),
  'archi' => 
  array (
  ),
  'army' => 
  array (
  ),
  'arte' => 
  array (
  ),
  'associates' => 
  array (
  ),
  'attorney' => 
  array (
  ),
  'auction' => 
  array (
  ),
  'audio' => 
  array (
  ),
  'author' => 
  array (
  ),
  'auto' => 
  array (
  ),
  'autos' => 
  array (
  ),
  'avianca' => 
  array (
  ),
  'axa' => 
  array (
  ),
  'azure' => 
  array (
  ),
  'baidu' => 
  array (
  ),
  'band' => 
  array (
  ),
  'bank' => 
  array (
  ),
  'bar' => 
  array (
  ),
  'barcelona' => 
  array (
  ),
  'barclaycard' => 
  array (
  ),
  'barclays' => 
  array (
  ),
  'bargains' => 
  array (
  ),
  'bauhaus' => 
  array (
  ),
  'bayern' => 
  array (
  ),
  'bbc' => 
  array (
  ),
  'bbva' => 
  array (
  ),
  'bcn' => 
  array (
  ),
  'beer' => 
  array (
  ),
  'bentley' => 
  array (
  ),
  'berlin' => 
  array (
  ),
  'best' => 
  array (
  ),
  'bharti' => 
  array (
  ),
  'bible' => 
  array (
  ),
  'bid' => 
  array (
  ),
  'bike' => 
  array (
  ),
  'bing' => 
  array (
  ),
  'bingo' => 
  array (
  ),
  'bio' => 
  array (
  ),
  'black' => 
  array (
  ),
  'blackfriday' => 
  array (
  ),
  'bloomberg' => 
  array (
  ),
  'blue' => 
  array (
  ),
  'bms' => 
  array (
  ),
  'bmw' => 
  array (
  ),
  'bnl' => 
  array (
  ),
  'bnpparibas' => 
  array (
  ),
  'boats' => 
  array (
  ),
  'bom' => 
  array (
  ),
  'bond' => 
  array (
  ),
  'boo' => 
  array (
  ),
  'boots' => 
  array (
  ),
  'bot' => 
  array (
  ),
  'boutique' => 
  array (
  ),
  'bradesco' => 
  array (
  ),
  'bridgestone' => 
  array (
  ),
  'broadway' => 
  array (
  ),
  'broker' => 
  array (
  ),
  'brussels' => 
  array (
  ),
  'budapest' => 
  array (
  ),
  'build' => 
  array (
  ),
  'builders' => 
  array (
  ),
  'business' => 
  array (
  ),
  'buy' => 
  array (
  ),
  'buzz' => 
  array (
  ),
  'bzh' => 
  array (
  ),
  'cab' => 
  array (
  ),
  'cal' => 
  array (
  ),
  'call' => 
  array (
  ),
  'camera' => 
  array (
  ),
  'camp' => 
  array (
  ),
  'cancerresearch' => 
  array (
  ),
  'canon' => 
  array (
  ),
  'capetown' => 
  array (
  ),
  'capital' => 
  array (
  ),
  'car' => 
  array (
  ),
  'caravan' => 
  array (
  ),
  'cards' => 
  array (
  ),
  'care' => 
  array (
  ),
  'career' => 
  array (
  ),
  'careers' => 
  array (
  ),
  'cars' => 
  array (
  ),
  'cartier' => 
  array (
  ),
  'casa' => 
  array (
  ),
  'cash' => 
  array (
  ),
  'casino' => 
  array (
  ),
  'catering' => 
  array (
  ),
  'cba' => 
  array (
  ),
  'cbn' => 
  array (
  ),
  'center' => 
  array (
  ),
  'ceo' => 
  array (
  ),
  'cern' => 
  array (
  ),
  'cfa' => 
  array (
  ),
  'cfd' => 
  array (
  ),
  'channel' => 
  array (
  ),
  'chat' => 
  array (
  ),
  'cheap' => 
  array (
  ),
  'chloe' => 
  array (
  ),
  'christmas' => 
  array (
  ),
  'chrome' => 
  array (
  ),
  'church' => 
  array (
  ),
  'circle' => 
  array (
  ),
  'cisco' => 
  array (
  ),
  'citic' => 
  array (
  ),
  'city' => 
  array (
  ),
  'cityeats' => 
  array (
  ),
  'claims' => 
  array (
  ),
  'cleaning' => 
  array (
  ),
  'click' => 
  array (
  ),
  'clinic' => 
  array (
  ),
  'clothing' => 
  array (
  ),
  'club' => 
  array (
  ),
  'coach' => 
  array (
  ),
  'codes' => 
  array (
  ),
  'coffee' => 
  array (
  ),
  'college' => 
  array (
  ),
  'cologne' => 
  array (
  ),
  'commbank' => 
  array (
  ),
  'community' => 
  array (
  ),
  'company' => 
  array (
  ),
  'computer' => 
  array (
  ),
  'comsec' => 
  array (
  ),
  'condos' => 
  array (
  ),
  'construction' => 
  array (
  ),
  'consulting' => 
  array (
  ),
  'contact' => 
  array (
  ),
  'contractors' => 
  array (
  ),
  'cooking' => 
  array (
  ),
  'cool' => 
  array (
  ),
  'corsica' => 
  array (
  ),
  'country' => 
  array (
  ),
  'courses' => 
  array (
  ),
  'credit' => 
  array (
  ),
  'creditcard' => 
  array (
  ),
  'creditunion' => 
  array (
  ),
  'cricket' => 
  array (
  ),
  'crown' => 
  array (
  ),
  'crs' => 
  array (
  ),
  'cruises' => 
  array (
  ),
  'csc' => 
  array (
  ),
  'cuisinella' => 
  array (
  ),
  'cymru' => 
  array (
  ),
  'cyou' => 
  array (
  ),
  'dabur' => 
  array (
  ),
  'dad' => 
  array (
  ),
  'dance' => 
  array (
  ),
  'date' => 
  array (
  ),
  'dating' => 
  array (
  ),
  'datsun' => 
  array (
  ),
  'day' => 
  array (
  ),
  'dclk' => 
  array (
  ),
  'dealer' => 
  array (
  ),
  'deals' => 
  array (
  ),
  'degree' => 
  array (
  ),
  'delivery' => 
  array (
  ),
  'dell' => 
  array (
  ),
  'democrat' => 
  array (
  ),
  'dental' => 
  array (
  ),
  'dentist' => 
  array (
  ),
  'desi' => 
  array (
  ),
  'design' => 
  array (
  ),
  'dev' => 
  array (
  ),
  'diamonds' => 
  array (
  ),
  'diet' => 
  array (
  ),
  'digital' => 
  array (
  ),
  'direct' => 
  array (
  ),
  'directory' => 
  array (
  ),
  'discount' => 
  array (
  ),
  'dnp' => 
  array (
  ),
  'docs' => 
  array (
  ),
  'dog' => 
  array (
  ),
  'doha' => 
  array (
  ),
  'domains' => 
  array (
  ),
  'doosan' => 
  array (
  ),
  'download' => 
  array (
  ),
  'dubai' => 
  array (
  ),
  'durban' => 
  array (
  ),
  'dvag' => 
  array (
  ),
  'earth' => 
  array (
  ),
  'eat' => 
  array (
  ),
  'edeka' => 
  array (
  ),
  'education' => 
  array (
  ),
  'email' => 
  array (
  ),
  'emerck' => 
  array (
  ),
  'energy' => 
  array (
  ),
  'engineer' => 
  array (
  ),
  'engineering' => 
  array (
  ),
  'enterprises' => 
  array (
  ),
  'epson' => 
  array (
  ),
  'equipment' => 
  array (
  ),
  'erni' => 
  array (
  ),
  'esq' => 
  array (
  ),
  'estate' => 
  array (
  ),
  'eurovision' => 
  array (
  ),
  'eus' => 
  array (
  ),
  'events' => 
  array (
  ),
  'everbank' => 
  array (
  ),
  'exchange' => 
  array (
  ),
  'expert' => 
  array (
  ),
  'exposed' => 
  array (
  ),
  'fage' => 
  array (
  ),
  'fail' => 
  array (
  ),
  'fairwinds' => 
  array (
  ),
  'faith' => 
  array (
  ),
  'fan' => 
  array (
  ),
  'fans' => 
  array (
  ),
  'farm' => 
  array (
  ),
  'fashion' => 
  array (
  ),
  'fast' => 
  array (
  ),
  'feedback' => 
  array (
  ),
  'ferrero' => 
  array (
  ),
  'film' => 
  array (
  ),
  'final' => 
  array (
  ),
  'finance' => 
  array (
  ),
  'financial' => 
  array (
  ),
  'firestone' => 
  array (
  ),
  'firmdale' => 
  array (
  ),
  'fish' => 
  array (
  ),
  'fishing' => 
  array (
  ),
  'fit' => 
  array (
  ),
  'fitness' => 
  array (
  ),
  'flights' => 
  array (
  ),
  'florist' => 
  array (
  ),
  'flowers' => 
  array (
  ),
  'flsmidth' => 
  array (
  ),
  'fly' => 
  array (
  ),
  'foo' => 
  array (
  ),
  'football' => 
  array (
  ),
  'ford' => 
  array (
  ),
  'forex' => 
  array (
  ),
  'forsale' => 
  array (
  ),
  'foundation' => 
  array (
  ),
  'frl' => 
  array (
  ),
  'frogans' => 
  array (
  ),
  'fund' => 
  array (
  ),
  'furniture' => 
  array (
  ),
  'futbol' => 
  array (
  ),
  'gal' => 
  array (
  ),
  'gallery' => 
  array (
  ),
  'garden' => 
  array (
  ),
  'gbiz' => 
  array (
  ),
  'gdn' => 
  array (
  ),
  'gea' => 
  array (
  ),
  'gent' => 
  array (
  ),
  'ggee' => 
  array (
  ),
  'gift' => 
  array (
  ),
  'gifts' => 
  array (
  ),
  'gives' => 
  array (
  ),
  'giving' => 
  array (
  ),
  'glass' => 
  array (
  ),
  'gle' => 
  array (
  ),
  'global' => 
  array (
  ),
  'globo' => 
  array (
  ),
  'gmail' => 
  array (
  ),
  'gmo' => 
  array (
  ),
  'gmx' => 
  array (
  ),
  'gold' => 
  array (
  ),
  'goldpoint' => 
  array (
  ),
  'golf' => 
  array (
  ),
  'goo' => 
  array (
  ),
  'goog' => 
  array (
  ),
  'google' => 
  array (
  ),
  'gop' => 
  array (
  ),
  'got' => 
  array (
  ),
  'graphics' => 
  array (
  ),
  'gratis' => 
  array (
  ),
  'green' => 
  array (
  ),
  'gripe' => 
  array (
  ),
  'group' => 
  array (
  ),
  'gucci' => 
  array (
  ),
  'guge' => 
  array (
  ),
  'guide' => 
  array (
  ),
  'guitars' => 
  array (
  ),
  'guru' => 
  array (
  ),
  'hamburg' => 
  array (
  ),
  'hangout' => 
  array (
  ),
  'haus' => 
  array (
  ),
  'healthcare' => 
  array (
  ),
  'help' => 
  array (
  ),
  'here' => 
  array (
  ),
  'hermes' => 
  array (
  ),
  'hiphop' => 
  array (
  ),
  'hitachi' => 
  array (
  ),
  'hiv' => 
  array (
  ),
  'holdings' => 
  array (
  ),
  'holiday' => 
  array (
  ),
  'homes' => 
  array (
  ),
  'honda' => 
  array (
  ),
  'horse' => 
  array (
  ),
  'host' => 
  array (
  ),
  'hosting' => 
  array (
  ),
  'hotmail' => 
  array (
  ),
  'house' => 
  array (
  ),
  'how' => 
  array (
  ),
  'hsbc' => 
  array (
  ),
  'ibm' => 
  array (
  ),
  'ice' => 
  array (
  ),
  'icu' => 
  array (
  ),
  'ifm' => 
  array (
  ),
  'iinet' => 
  array (
  ),
  'immo' => 
  array (
  ),
  'immobilien' => 
  array (
  ),
  'industries' => 
  array (
  ),
  'infiniti' => 
  array (
  ),
  'ing' => 
  array (
  ),
  'ink' => 
  array (
  ),
  'institute' => 
  array (
  ),
  'insure' => 
  array (
  ),
  'international' => 
  array (
  ),
  'investments' => 
  array (
  ),
  'ipiranga' => 
  array (
  ),
  'irish' => 
  array (
  ),
  'ist' => 
  array (
  ),
  'istanbul' => 
  array (
  ),
  'itau' => 
  array (
  ),
  'iwc' => 
  array (
  ),
  'jaguar' => 
  array (
  ),
  'java' => 
  array (
  ),
  'jcb' => 
  array (
  ),
  'jetzt' => 
  array (
  ),
  'jlc' => 
  array (
  ),
  'joburg' => 
  array (
  ),
  'jot' => 
  array (
  ),
  'joy' => 
  array (
  ),
  'jprs' => 
  array (
  ),
  'juegos' => 
  array (
  ),
  'kaufen' => 
  array (
  ),
  'kddi' => 
  array (
  ),
  'kfh' => 
  array (
  ),
  'kim' => 
  array (
  ),
  'kinder' => 
  array (
  ),
  'kitchen' => 
  array (
  ),
  'kiwi' => 
  array (
  ),
  'koeln' => 
  array (
  ),
  'komatsu' => 
  array (
  ),
  'kpn' => 
  array (
  ),
  'krd' => 
  array (
  ),
  'kred' => 
  array (
  ),
  'kyoto' => 
  array (
  ),
  'lacaixa' => 
  array (
  ),
  'land' => 
  array (
  ),
  'landrover' => 
  array (
  ),
  'lat' => 
  array (
  ),
  'latrobe' => 
  array (
  ),
  'law' => 
  array (
  ),
  'lawyer' => 
  array (
  ),
  'lds' => 
  array (
  ),
  'lease' => 
  array (
  ),
  'leclerc' => 
  array (
  ),
  'legal' => 
  array (
  ),
  'lgbt' => 
  array (
  ),
  'liaison' => 
  array (
  ),
  'lidl' => 
  array (
  ),
  'life' => 
  array (
  ),
  'lifeinsurance' => 
  array (
  ),
  'lifestyle' => 
  array (
  ),
  'lighting' => 
  array (
  ),
  'like' => 
  array (
  ),
  'limited' => 
  array (
  ),
  'limo' => 
  array (
  ),
  'lincoln' => 
  array (
  ),
  'linde' => 
  array (
  ),
  'link' => 
  array (
  ),
  'live' => 
  array (
  ),
  'loan' => 
  array (
  ),
  'loans' => 
  array (
  ),
  'london' => 
  array (
  ),
  'lotte' => 
  array (
  ),
  'lotto' => 
  array (
  ),
  'love' => 
  array (
  ),
  'ltd' => 
  array (
  ),
  'ltda' => 
  array (
  ),
  'lupin' => 
  array (
  ),
  'luxe' => 
  array (
  ),
  'luxury' => 
  array (
  ),
  'madrid' => 
  array (
  ),
  'maif' => 
  array (
  ),
  'maison' => 
  array (
  ),
  'makeup' => 
  array (
  ),
  'man' => 
  array (
  ),
  'management' => 
  array (
  ),
  'mango' => 
  array (
  ),
  'market' => 
  array (
  ),
  'marketing' => 
  array (
  ),
  'markets' => 
  array (
  ),
  'marriott' => 
  array (
  ),
  'media' => 
  array (
  ),
  'meet' => 
  array (
  ),
  'melbourne' => 
  array (
  ),
  'meme' => 
  array (
  ),
  'memorial' => 
  array (
  ),
  'menu' => 
  array (
  ),
  'meo' => 
  array (
  ),
  'miami' => 
  array (
  ),
  'microsoft' => 
  array (
  ),
  'mini' => 
  array (
  ),
  'mma' => 
  array (
  ),
  'mobily' => 
  array (
  ),
  'moda' => 
  array (
  ),
  'moe' => 
  array (
  ),
  'moi' => 
  array (
  ),
  'monash' => 
  array (
  ),
  'money' => 
  array (
  ),
  'montblanc' => 
  array (
  ),
  'mormon' => 
  array (
  ),
  'mortgage' => 
  array (
  ),
  'moscow' => 
  array (
  ),
  'motorcycles' => 
  array (
  ),
  'mov' => 
  array (
  ),
  'movistar' => 
  array (
  ),
  'mtn' => 
  array (
  ),
  'mtpc' => 
  array (
  ),
  'nadex' => 
  array (
  ),
  'nagoya' => 
  array (
  ),
  'navy' => 
  array (
  ),
  'nec' => 
  array (
  ),
  'netbank' => 
  array (
  ),
  'network' => 
  array (
  ),
  'neustar' => 
  array (
  ),
  'new' => 
  array (
  ),
  'news' => 
  array (
  ),
  'nexus' => 
  array (
  ),
  'ngo' => 
  array (
  ),
  'nhk' => 
  array (
  ),
  'nico' => 
  array (
  ),
  'ninja' => 
  array (
  ),
  'nissan' => 
  array (
  ),
  'nokia' => 
  array (
  ),
  'norton' => 
  array (
  ),
  'nowruz' => 
  array (
  ),
  'nra' => 
  array (
  ),
  'nrw' => 
  array (
  ),
  'ntt' => 
  array (
  ),
  'nyc' => 
  array (
  ),
  'obi' => 
  array (
  ),
  'okinawa' => 
  array (
  ),
  'omega' => 
  array (
  ),
  'one' => 
  array (
  ),
  'ong' => 
  array (
  ),
  'onl' => 
  array (
  ),
  'online' => 
  array (
  ),
  'ooo' => 
  array (
  ),
  'oracle' => 
  array (
  ),
  'organic' => 
  array (
  ),
  'osaka' => 
  array (
  ),
  'otsuka' => 
  array (
  ),
  'ovh' => 
  array (
  ),
  'page' => 
  array (
  ),
  'panerai' => 
  array (
  ),
  'paris' => 
  array (
  ),
  'pars' => 
  array (
  ),
  'partners' => 
  array (
  ),
  'parts' => 
  array (
  ),
  'party' => 
  array (
  ),
  'pharmacy' => 
  array (
  ),
  'philips' => 
  array (
  ),
  'photo' => 
  array (
  ),
  'photography' => 
  array (
  ),
  'photos' => 
  array (
  ),
  'physio' => 
  array (
  ),
  'piaget' => 
  array (
  ),
  'pics' => 
  array (
  ),
  'pictet' => 
  array (
  ),
  'pictures' => 
  array (
  ),
  'pid' => 
  array (
  ),
  'pin' => 
  array (
  ),
  'pink' => 
  array (
  ),
  'pizza' => 
  array (
  ),
  'place' => 
  array (
  ),
  'plumbing' => 
  array (
  ),
  'pohl' => 
  array (
  ),
  'poker' => 
  array (
  ),
  'porn' => 
  array (
  ),
  'praxi' => 
  array (
  ),
  'press' => 
  array (
  ),
  'prod' => 
  array (
  ),
  'productions' => 
  array (
  ),
  'prof' => 
  array (
  ),
  'promo' => 
  array (
  ),
  'properties' => 
  array (
  ),
  'property' => 
  array (
  ),
  'pub' => 
  array (
  ),
  'qpon' => 
  array (
  ),
  'quebec' => 
  array (
  ),
  'racing' => 
  array (
  ),
  'read' => 
  array (
  ),
  'realtor' => 
  array (
  ),
  'recipes' => 
  array (
  ),
  'red' => 
  array (
  ),
  'redstone' => 
  array (
  ),
  'rehab' => 
  array (
  ),
  'reise' => 
  array (
  ),
  'reisen' => 
  array (
  ),
  'reit' => 
  array (
  ),
  'ren' => 
  array (
  ),
  'rent' => 
  array (
  ),
  'rentals' => 
  array (
  ),
  'repair' => 
  array (
  ),
  'report' => 
  array (
  ),
  'republican' => 
  array (
  ),
  'rest' => 
  array (
  ),
  'restaurant' => 
  array (
  ),
  'review' => 
  array (
  ),
  'reviews' => 
  array (
  ),
  'rich' => 
  array (
  ),
  'ricoh' => 
  array (
  ),
  'rio' => 
  array (
  ),
  'rip' => 
  array (
  ),
  'rocher' => 
  array (
  ),
  'rocks' => 
  array (
  ),
  'rodeo' => 
  array (
  ),
  'room' => 
  array (
  ),
  'rsvp' => 
  array (
  ),
  'ruhr' => 
  array (
  ),
  'ryukyu' => 
  array (
  ),
  'saarland' => 
  array (
  ),
  'safe' => 
  array (
  ),
  'safety' => 
  array (
  ),
  'sakura' => 
  array (
  ),
  'sale' => 
  array (
  ),
  'salon' => 
  array (
  ),
  'samsung' => 
  array (
  ),
  'sandvik' => 
  array (
  ),
  'sandvikcoromant' => 
  array (
  ),
  'sanofi' => 
  array (
  ),
  'sap' => 
  array (
  ),
  'sapo' => 
  array (
  ),
  'sarl' => 
  array (
  ),
  'saxo' => 
  array (
  ),
  'sbs' => 
  array (
  ),
  'sca' => 
  array (
  ),
  'scb' => 
  array (
  ),
  'schmidt' => 
  array (
  ),
  'scholarships' => 
  array (
  ),
  'school' => 
  array (
  ),
  'schule' => 
  array (
  ),
  'schwarz' => 
  array (
  ),
  'science' => 
  array (
  ),
  'scor' => 
  array (
  ),
  'scot' => 
  array (
  ),
  'seat' => 
  array (
  ),
  'seek' => 
  array (
  ),
  'sener' => 
  array (
  ),
  'services' => 
  array (
  ),
  'sew' => 
  array (
  ),
  'sex' => 
  array (
  ),
  'sexy' => 
  array (
  ),
  'sharp' => 
  array (
  ),
  'shia' => 
  array (
  ),
  'shiksha' => 
  array (
  ),
  'shoes' => 
  array (
  ),
  'shouji' => 
  array (
  ),
  'shriram' => 
  array (
  ),
  'singles' => 
  array (
  ),
  'site' => 
  array (
  ),
  'skin' => 
  array (
  ),
  'sky' => 
  array (
  ),
  'skype' => 
  array (
  ),
  'smile' => 
  array (
  ),
  'social' => 
  array (
  ),
  'software' => 
  array (
  ),
  'sohu' => 
  array (
  ),
  'solar' => 
  array (
  ),
  'solutions' => 
  array (
  ),
  'sony' => 
  array (
  ),
  'soy' => 
  array (
  ),
  'space' => 
  array (
  ),
  'spiegel' => 
  array (
  ),
  'spreadbetting' => 
  array (
  ),
  'stada' => 
  array (
  ),
  'star' => 
  array (
  ),
  'statoil' => 
  array (
  ),
  'stc' => 
  array (
  ),
  'stcgroup' => 
  array (
  ),
  'stockholm' => 
  array (
  ),
  'storage' => 
  array (
  ),
  'study' => 
  array (
  ),
  'style' => 
  array (
  ),
  'sucks' => 
  array (
  ),
  'supplies' => 
  array (
  ),
  'supply' => 
  array (
  ),
  'support' => 
  array (
  ),
  'surf' => 
  array (
  ),
  'surgery' => 
  array (
  ),
  'suzuki' => 
  array (
  ),
  'swatch' => 
  array (
  ),
  'swiss' => 
  array (
  ),
  'sydney' => 
  array (
  ),
  'symantec' => 
  array (
  ),
  'systems' => 
  array (
  ),
  'tab' => 
  array (
  ),
  'taipei' => 
  array (
  ),
  'taobao' => 
  array (
  ),
  'tatar' => 
  array (
  ),
  'tattoo' => 
  array (
  ),
  'tax' => 
  array (
  ),
  'tci' => 
  array (
  ),
  'technology' => 
  array (
  ),
  'telefonica' => 
  array (
  ),
  'temasek' => 
  array (
  ),
  'tennis' => 
  array (
  ),
  'tienda' => 
  array (
  ),
  'tips' => 
  array (
  ),
  'tires' => 
  array (
  ),
  'tirol' => 
  array (
  ),
  'tmall' => 
  array (
  ),
  'today' => 
  array (
  ),
  'tokyo' => 
  array (
  ),
  'tools' => 
  array (
  ),
  'top' => 
  array (
  ),
  'toray' => 
  array (
  ),
  'toshiba' => 
  array (
  ),
  'tours' => 
  array (
  ),
  'town' => 
  array (
  ),
  'toys' => 
  array (
  ),
  'trade' => 
  array (
  ),
  'trading' => 
  array (
  ),
  'training' => 
  array (
  ),
  'trust' => 
  array (
  ),
  'tui' => 
  array (
  ),
  'tushu' => 
  array (
  ),
  'ubs' => 
  array (
  ),
  'university' => 
  array (
  ),
  'uno' => 
  array (
  ),
  'uol' => 
  array (
  ),
  'vacations' => 
  array (
  ),
  'vana' => 
  array (
  ),
  'vegas' => 
  array (
  ),
  'ventures' => 
  array (
  ),
  'versicherung' => 
  array (
  ),
  'vet' => 
  array (
  ),
  'viajes' => 
  array (
  ),
  'video' => 
  array (
  ),
  'villas' => 
  array (
  ),
  'vip' => 
  array (
  ),
  'virgin' => 
  array (
  ),
  'vision' => 
  array (
  ),
  'vista' => 
  array (
  ),
  'vistaprint' => 
  array (
  ),
  'viva' => 
  array (
  ),
  'vlaanderen' => 
  array (
  ),
  'vodka' => 
  array (
  ),
  'vote' => 
  array (
  ),
  'voting' => 
  array (
  ),
  'voto' => 
  array (
  ),
  'voyage' => 
  array (
  ),
  'wales' => 
  array (
  ),
  'walter' => 
  array (
  ),
  'wang' => 
  array (
  ),
  'wanggou' => 
  array (
  ),
  'watch' => 
  array (
  ),
  'watches' => 
  array (
  ),
  'weather' => 
  array (
  ),
  'webcam' => 
  array (
  ),
  'website' => 
  array (
  ),
  'wed' => 
  array (
  ),
  'wedding' => 
  array (
  ),
  'whoswho' => 
  array (
  ),
  'wien' => 
  array (
  ),
  'wiki' => 
  array (
  ),
  'williamhill' => 
  array (
  ),
  'win' => 
  array (
  ),
  'windows' => 
  array (
  ),
  'wme' => 
  array (
  ),
  'work' => 
  array (
  ),
  'works' => 
  array (
  ),
  'world' => 
  array (
  ),
  'wtc' => 
  array (
  ),
  'wtf' => 
  array (
  ),
  'xbox' => 
  array (
  ),
  'xerox' => 
  array (
  ),
  'xihuan' => 
  array (
  ),
  'xin' => 
  array (
  ),
  'xn--11b4c3d' => 
  array (
  ),
  'xn--1qqw23a' => 
  array (
  ),
  'xn--30rr7y' => 
  array (
  ),
  'xn--3bst00m' => 
  array (
  ),
  'xn--3ds443g' => 
  array (
  ),
  'xn--3pxu8k' => 
  array (
  ),
  'xn--42c2d9a' => 
  array (
  ),
  'xn--45q11c' => 
  array (
  ),
  'xn--4gbrim' => 
  array (
  ),
  'xn--55qw42g' => 
  array (
  ),
  'xn--55qx5d' => 
  array (
  ),
  'xn--5tzm5g' => 
  array (
  ),
  'xn--6frz82g' => 
  array (
  ),
  'xn--6qq986b3xl' => 
  array (
  ),
  'xn--80adxhks' => 
  array (
  ),
  'xn--80asehdb' => 
  array (
  ),
  'xn--80aswg' => 
  array (
  ),
  'xn--9dbq2a' => 
  array (
  ),
  'xn--9et52u' => 
  array (
  ),
  'xn--b4w605ferd' => 
  array (
  ),
  'xn--c1avg' => 
  array (
  ),
  'xn--c2br7g' => 
  array (
  ),
  'xn--cg4bki' => 
  array (
  ),
  'xn--czr694b' => 
  array (
  ),
  'xn--czrs0t' => 
  array (
  ),
  'xn--czru2d' => 
  array (
  ),
  'xn--d1acj3b' => 
  array (
  ),
  'xn--eckvdtc9d' => 
  array (
  ),
  'xn--efvy88h' => 
  array (
  ),
  'xn--fhbei' => 
  array (
  ),
  'xn--fiq228c5hs' => 
  array (
  ),
  'xn--fiq64b' => 
  array (
  ),
  'xn--fjq720a' => 
  array (
  ),
  'xn--flw351e' => 
  array (
  ),
  'xn--hxt814e' => 
  array (
  ),
  'xn--i1b6b1a6a2e' => 
  array (
  ),
  'xn--imr513n' => 
  array (
  ),
  'xn--io0a7i' => 
  array (
  ),
  'xn--j1aef' => 
  array (
  ),
  'xn--jlq61u9w7b' => 
  array (
  ),
  'xn--kcrx77d1x4a' => 
  array (
  ),
  'xn--kpu716f' => 
  array (
  ),
  'xn--kput3i' => 
  array (
  ),
  'xn--mgba3a3ejt' => 
  array (
  ),
  'xn--mgbab2bd' => 
  array (
  ),
  'xn--mgbb9fbpob' => 
  array (
  ),
  'xn--mgbt3dhd' => 
  array (
  ),
  'xn--mk1bu44c' => 
  array (
  ),
  'xn--mxtq1m' => 
  array (
  ),
  'xn--ngbc5azd' => 
  array (
  ),
  'xn--ngbe9e0a' => 
  array (
  ),
  'xn--nqv7f' => 
  array (
  ),
  'xn--nqv7fs00ema' => 
  array (
  ),
  'xn--nyqy26a' => 
  array (
  ),
  'xn--p1acf' => 
  array (
  ),
  'xn--pbt977c' => 
  array (
  ),
  'xn--pssy2u' => 
  array (
  ),
  'xn--q9jyb4c' => 
  array (
  ),
  'xn--qcka1pmc' => 
  array (
  ),
  'xn--rhqv96g' => 
  array (
  ),
  'xn--ses554g' => 
  array (
  ),
  'xn--t60b56a' => 
  array (
  ),
  'xn--tckwe' => 
  array (
  ),
  'xn--unup4y' => 
  array (
  ),
  'xn--vermgensberater-ctb' => 
  array (
  ),
  'xn--vermgensberatung-pwb' => 
  array (
  ),
  'xn--vhquv' => 
  array (
  ),
  'xn--vuq861b' => 
  array (
  ),
  'xn--xhq521b' => 
  array (
  ),
  'xn--zfr164b' => 
  array (
  ),
  'xyz' => 
  array (
  ),
  'yachts' => 
  array (
  ),
  'yamaxun' => 
  array (
  ),
  'yandex' => 
  array (
  ),
  'yodobashi' => 
  array (
  ),
  'yoga' => 
  array (
  ),
  'yokohama' => 
  array (
  ),
  'youtube' => 
  array (
  ),
  'yun' => 
  array (
  ),
  'zara' => 
  array (
  ),
  'zero' => 
  array (
  ),
  'zip' => 
  array (
  ),
  'zone' => 
  array (
  ),
  'zuerich' => 
  array (
  ),
);
}


/**
 * Name:		double_metaphone( $string )
 * Purpose:		Get the primary and secondary double metaphone tokens
 * Return:		Array: if secondary == primary, secondary = NULL
 */

/**
 * VERSION
 * 
 * DoubleMetaphone Functional 1.01 (altered)
 * 
 * DESCRIPTION
 * 
 * This function implements a "sounds like" algorithm developed
 * by Lawrence Philips which he published in the June, 2000 issue
 * of C/C++ Users Journal.  Double Metaphone is an improved
 * version of Philips' original Metaphone algorithm.
 * 
 * COPYRIGHT
 * 
 * Slightly adapted from the class by Stephen Woodbridge.
 * Copyright 2001, Stephen Woodbridge <woodbri@swoodbridge.com>
 * All rights reserved.
 * 
 * http://swoodbridge.com/DoubleMetaPhone/
 * 
 * This PHP translation is based heavily on the C implementation
 * by Maurice Aubrey <maurice@hevanet.com>, which in turn  
 * is based heavily on the C++ implementation by
 * Lawrence Philips and incorporates several bug fixes courtesy
 * of Kevin Atkinson <kevina@users.sourceforge.net>.
 * 
 * This module is free software; you may redistribute it and/or
 * modify it under the same terms as Perl itself.
 * 
 * 
 * CONTRIBUTIONS
 * 
 * 2002/05/17 Geoff Caplan  http://www.advantae.com
 *   Bug fix: added code to return class object which I forgot to do
 *   Created a functional callable version instead of the class version
 *   which is faster if you are calling this a lot.
 * 
 * 2013/05/04 Steen RÃ©mi
 *   New indentation of the code for better readability
 *   Some small alterations
 *   Replace ereg by preg_match
 *     ( ereg : This function has been DEPRECATED as of PHP 5.3.0 )
 *   Improve performance (10 - 20 % faster)
 *
 * 2014/11/07 Ross Kelly
 *   Reported a bug with the oreg_match change that it needed delimiters
 *   around the the regular expressions.
 */
 
function double_metaphone( $string )
{
	$primary = '';
	$secondary = '';
	$current = 0;
	$length = strlen( $string );
	$last = $length - 1;
	$original = strtoupper( $string ).'     ';

	// skip this at beginning of word
	if (string_at($original, 0, 2, array('GN','KN','PN','WR','PS'))){
		$current++;
	}

	// Initial 'X' is pronounced 'Z' e.g. 'Xavier'
	if (substr($original, 0, 1) == 'X'){
		$primary   .= 'S'; // 'Z' maps to 'S'
		$secondary .= 'S';
		$current++;
	}

	// main loop

	while (strlen($primary) < 4 || strlen($secondary) < 4){
		if ($current >= $length){
			break;
		}

		// switch (substr($original, $current, 1)){
		switch ($original[$current]){
			case 'A':
			case 'E':
			case 'I':
			case 'O':
			case 'U':
			case 'Y':
				if ($current == 0){
					// all init vowels now map to 'A'
					$primary   .= 'A';
					$secondary .= 'A';
				}
				++$current;
				break;

			case 'B':
				// '-mb', e.g. "dumb", already skipped over ...
				$primary   .= 'P';
				$secondary .= 'P';

				if (substr($original, $current + 1, 1) == 'B'){
					$current += 2;
				} else {
					++$current;
				}
				break;

			case 'Ã‡':
				$primary   .= 'S';
				$secondary .= 'S';
				++$current;
				break;

			case 'C':
				// various gremanic
				if ($current > 1
				 && !is_vowel($original, $current - 2)
				 && string_at($original, $current - 1, 3, array('ACH'))
				 && (
						(substr($original, $current + 2, 1) != 'I')
					 && (
							(substr($original, $current + 2, 1) != 'E')
						 || string_at($original, $current - 2, 6, array('BACHER', 'MACHER'))
						)
					)
				){
					$primary   .= 'K';
					$secondary .= 'K';
					$current += 2;
					break;
				}

				// special case 'caesar'
				if ($current == 0
				 && string_at($original, $current, 6, array('CAESAR'))
				){
					$primary   .= 'S';
					$secondary .= 'S';
					$current += 2;
					break;
				}

				// italian 'chianti'
				if (string_at($original, $current, 4, array('CHIA'))){
					$primary   .= 'K';
					$secondary .= 'K';
					$current += 2;
					break;
				}

				if (string_at($original, $current, 2, array('CH'))){

					// find 'michael'
					if ($current > 0
					 && string_at($original, $current, 4, array('CHAE'))
					){
						$primary   .= 'K';
						$secondary .= 'X';
						$current += 2;
						break;
					}

					// greek roots e.g. 'chemistry', 'chorus'
					if ($current == 0
					 && (
							string_at($original, $current + 1, 5, array('HARAC', 'HARIS'))
						 || string_at($original, $current + 1, 3, array('HOR', 'HYM', 'HIA', 'HEM'))
						)
					 && !string_at($original, 0, 5, array('CHORE'))
					){
						$primary   .= 'K';
						$secondary .= 'K';
						$current += 2;
						break;
					}

					// germanic, greek, or otherwise 'ch' for 'kh' sound
					if ((
							string_at($original, 0, 4, array('VAN ', 'VON '))
						 || string_at($original, 0, 3, array('SCH'))
						)
						// 'architect' but not 'arch', orchestra', 'orchid'
					 || string_at($original, $current - 2, 6, array('ORCHES', 'ARCHIT', 'ORCHID'))
					 || string_at($original, $current + 2, 1, array('T', 'S'))
					 || (
							(
								string_at($original, $current - 1, 1, array('A','O','U','E'))
							 || $current == 0
							)
							// e.g. 'wachtler', 'weschsler', but not 'tichner'
						 && string_at($original, $current + 2, 1, array('L','R','N','M','B','H','F','V','W',' '))
						)
					){
						$primary   .= 'K';
						$secondary .= 'K';
					} else {
						if ($current > 0){
							if (string_at($original, 0, 2, array('MC'))){
								// e.g. 'McHugh'
								$primary   .= 'K';
								$secondary .= 'K';
							} else {
								$primary   .= 'X';
								$secondary .= 'K';
							}
						} else {
							$primary   .= 'X';
							$secondary .= 'X';
						}
					}
					$current += 2;
					break;
				}

				// e.g. 'czerny'
				if (string_at($original, $current, 2, array('CZ'))
				 && !string_at($original, $current -2, 4, array('WICZ'))
				){
					$primary   .= 'S';
					$secondary .= 'X';
					$current += 2;
					break;
				}

				// e.g. 'focaccia'
				if (string_at($original, $current + 1, 3, array('CIA'))){
					$primary   .= 'X';
					$secondary .= 'X';
					$current += 3;
					break;
				}

				// double 'C', but not McClellan'
				if (string_at($original, $current, 2, array('CC'))
				 && !(
						$current == 1
					 && substr($original, 0, 1) == 'M'
					)
				){
					// 'bellocchio' but not 'bacchus'
					if (string_at($original, $current + 2, 1, array('I','E','H'))
					 && !string_at($original, $current + 2, 2, array('HU'))
					){
						// 'accident', 'accede', 'succeed'
						if ((
								$current == 1
							 && substr($original, $current - 1, 1) == 'A'
							)
						 || string_at($original, $current - 1, 5,array('UCCEE', 'UCCES'))
						){
							$primary   .= 'KS';
							$secondary .= 'KS';
							// 'bacci', 'bertucci', other italian
						} else {
							$primary   .= 'X';
							$secondary .= 'X';
						}
						$current += 3;
						break;
					} else {
						// Pierce's rule
						$primary   .= 'K';
						$secondary .= 'K';
						$current += 2;
						break;
					}
				}

				if (string_at($original, $current, 2, array('CK','CG','CQ'))){
					$primary   .= 'K';
					$secondary .= 'K';
					$current += 2;
					break;
				}

				if (string_at($original, $current, 2, array('CI','CE','CY'))){
					// italian vs. english
					if (string_at($original, $current, 3, array('CIO','CIE','CIA'))){
						$primary   .= 'S';
						$secondary .= 'X';
					} else {
						$primary   .= 'S';
						$secondary .= 'S';
					}
					$current += 2;
					break;
				}

				// else
				$primary   .= 'K';
				$secondary .= 'K';

				// name sent in 'mac caffrey', 'mac gregor'
				if (string_at($original, $current + 1, 2, array(' C',' Q',' G'))){
					$current += 3;
				} else {
					if (string_at($original, $current + 1, 1, array('C','K','Q'))
					 && !string_at($original, $current + 1, 2, array('CE','CI'))
					){
						$current += 2;
					} else {
						++$current;
					}
				}
				break;

			case 'D':
				if (string_at($original, $current, 2, array('DG'))){
					if (string_at($original, $current + 2, 1, array('I','E','Y'))){
						// e.g. 'edge'
						$primary   .= 'J';
						$secondary .= 'J';
						$current += 3;
						break;
					} else {
						// e.g. 'edgar'
						$primary   .= 'TK';
						$secondary .= 'TK';
						$current += 2;
						break;
					}
				}

				if (string_at($original, $current, 2, array('DT','DD'))){
					$primary   .= 'T';
					$secondary .= 'T';
					$current += 2;
					break;
				}

				// else
				$primary   .= 'T';
				$secondary .= 'T';
				++$current;
				break;

			case 'F':
				if (substr($original, $current + 1, 1) == 'F'){
					$current += 2;
				} else {
					++$current;
				}
				$primary   .= 'F';
				$secondary .= 'F';
				break;

			case 'G':
				if (substr($original, $current + 1, 1) == 'H'){
					if ($current > 0
					 && !is_vowel($original, $current - 1)
					){
						$primary   .= 'K';
						$secondary .= 'K';
						$current += 2;
						break;
					}

					if ($current < 3){
						// 'ghislane', 'ghiradelli'
						if ($current == 0){
							if (substr($original, $current + 2, 1) == 'I'){
								$primary   .= 'J';
								$secondary .= 'J';
							} else {
								$primary   .= 'K';
								$secondary .= 'K';
							}
							$current += 2;
							break;
						}
					}

					// Parker's rule (with some further refinements) - e.g. 'hugh'
					if ((
							$current > 1
						 && string_at($original, $current - 2, 1, array('B','H','D'))
						)
					// e.g. 'bough'
					 || (
							$current > 2
						 && string_at($original, $current - 3, 1, array('B','H','D'))
						)
					// e.g. 'broughton'
					 || (
							$current > 3
						 && string_at($original, $current - 4, 1, array('B','H'))
						)
					){
						$current += 2;
						break;
					} else {
						// e.g. 'laugh', 'McLaughlin', 'cough', 'gough', 'rough', 'tough'
						if ($current > 2
						 && substr($original, $current - 1, 1) == 'U'
						 && string_at($original, $current - 3, 1,array('C','G','L','R','T'))
						){
							$primary   .= 'F';
							$secondary .= 'F';
						} else if (
							$current > 0
						 && substr($original, $current - 1, 1) != 'I'
						){
							$primary   .= 'K';
							$secondary .= 'K';
						}
						$current += 2;
						break;
					}
				}

				if (substr($original, $current + 1, 1) == 'N'){
					if ($current == 1
					 && is_vowel($original, 0)
					 && !Slavo_Germanic($original)
					){
						$primary   .= 'KN';
						$secondary .= 'N';
					} else {
						// not e.g. 'cagney'
						if (!string_at($original, $current + 2, 2, array('EY'))
						 && substr($original, $current + 1) != 'Y'
						 && !Slavo_Germanic($original)
						){
							$primary   .= 'N';
							$secondary .= 'KN';
						} else {
							$primary   .= 'KN';
							$secondary .= 'KN';
						}
					}
					$current += 2;
					break;
				}

				// 'tagliaro'
				if (string_at($original, $current + 1, 2,array('LI'))
				 && !Slavo_Germanic($original)
				){
					$primary   .= 'KL';
					$secondary .= 'L';
					$current += 2;
					break;
				}

				// -ges-, -gep-, -gel- at beginning
				if ($current == 0
				 && (
						substr($original, $current + 1, 1) == 'Y'
					 || string_at($original, $current + 1, 2, array('ES','EP','EB','EL','EY','IB','IL','IN','IE','EI','ER'))
					)
				){
					$primary   .= 'K';
					$secondary .= 'J';
					$current += 2;
					break;
				}

				// -ger-, -gy-
				if ((
						string_at($original, $current + 1, 2,array('ER'))
					 || substr($original, $current + 1, 1) == 'Y'
					)
				 && !string_at($original, 0, 6, array('DANGER','RANGER','MANGER'))
				 && !string_at($original, $current -1, 1, array('E', 'I'))
				 && !string_at($original, $current -1, 3, array('RGY','OGY'))
				){
					$primary   .= 'K';
					$secondary .= 'J';
					$current += 2;
					break;
				}

				// italian e.g. 'biaggi'
				if (string_at($original, $current + 1, 1, array('E','I','Y'))
				 || string_at($original, $current -1, 4, array('AGGI','OGGI'))
				){
					// obvious germanic
					if ((
							string_at($original, 0, 4, array('VAN ', 'VON '))
						 || string_at($original, 0, 3, array('SCH'))
						)
					 || string_at($original, $current + 1, 2, array('ET'))
					){
						$primary   .= 'K';
						$secondary .= 'K';
					} else {
						// always soft if french ending
						if (string_at($original, $current + 1, 4, array('IER '))){
							$primary   .= 'J';
							$secondary .= 'J';
						} else {
							$primary   .= 'J';
							$secondary .= 'K';
						}
					}
					$current += 2;
					break;
				}

				if (substr($original, $current +1, 1) == 'G'){
					$current += 2;
				} else {
					++$current;
				}

				$primary   .= 'K';
				$secondary .= 'K';
				break;

			case 'H':
				// only keep if first & before vowel or btw. 2 vowels
				if ((
						$current == 0
					 || is_vowel($original, $current - 1)
					)
				  && is_vowel($original, $current + 1)
				){
					$primary   .= 'H';
					$secondary .= 'H';
					$current += 2;
				} else {
					++$current;
				}
				break;

			case 'J':
				// obvious spanish, 'jose', 'san jacinto'
				if (string_at($original, $current, 4, array('JOSE'))
				 || string_at($original, 0, 4, array('SAN '))
				){
					if ((
							$current == 0
						 && substr($original, $current + 4, 1) == ' '
						)
					 || string_at($original, 0, 4, array('SAN '))
					){
						$primary   .= 'H';
						$secondary .= 'H';
					} else {
						$primary   .= 'J';
						$secondary .= 'H';
					}
					++$current;
					break;
				}

				if ($current == 0
				 && !string_at($original, $current, 4, array('JOSE'))
				){
					$primary   .= 'J';  // Yankelovich/Jankelowicz
					$secondary .= 'A';
				} else {
					// spanish pron. of .e.g. 'bajador'
					if (is_vowel($original, $current - 1)
					 && !Slavo_Germanic($original)
					 && (
							substr($original, $current + 1, 1) == 'A'
						 || substr($original, $current + 1, 1) == 'O'
						)
					){
						$primary   .= 'J';
						$secondary .= 'H';
					} else {
						if ($current == $last){
							$primary   .= 'J';
							// $secondary .= '';
						} else {
							if (!string_at($original, $current + 1, 1, array('L','T','K','S','N','M','B','Z'))
							 && !string_at($original, $current - 1, 1, array('S','K','L'))
							){
								$primary   .= 'J';
								$secondary .= 'J';
							}
						}
					}
				}

				if (substr($original, $current + 1, 1) == 'J'){ // it could happen
					$current += 2;
				} else {
					++$current;
				}
				break;

			case 'K':
				if (substr($original, $current + 1, 1) == 'K'){
					$current += 2;
				} else {
					++$current;
				}
				$primary   .= 'K';
				$secondary .= 'K';
				break;

			case 'L':
				if (substr($original, $current + 1, 1) == 'L'){
					// spanish e.g. 'cabrillo', 'gallegos'
					if ((
							$current == ($length - 3)
						 && string_at($original, $current - 1, 4, array('ILLO','ILLA','ALLE'))
						)
					 || (
							(
								string_at($original, $last-1, 2, array('AS','OS'))
							 || string_at($original, $last, 1, array('A','O'))
							)
						 && string_at($original, $current - 1, 4, array('ALLE'))
						)
					){
						$primary   .= 'L';
						// $secondary .= '';
						$current += 2;
						break;
					}
					$current += 2;
				} else {
					++$current;
				}
				$primary   .= 'L';
				$secondary .= 'L';
				break;

			case 'M':
				if ((
						string_at($original, $current - 1, 3,array('UMB'))
					 && (
							($current + 1) == $last
						 || string_at($original, $current + 2, 2, array('ER'))
						)
					)
				  // 'dumb', 'thumb'
				 || substr($original, $current + 1, 1) == 'M'
				){
					$current += 2;
				} else {
					++$current;
				}
				$primary   .= 'M';
				$secondary .= 'M';
				break;

			case 'N':
				if (substr($original, $current + 1, 1) == 'N'){
					$current += 2;
				} else {
					++$current;
				}
				$primary   .= 'N';
				$secondary .= 'N';
				break;

			case 'Ã‘':
				++$current;
				$primary   .= 'N';
				$secondary .= 'N';
				break;

			case 'P':
				if (substr($original, $current + 1, 1) == 'H'){
					$current += 2;
					$primary   .= 'F';
					$secondary .= 'F';
					break;
				}

				// also account for "campbell" and "raspberry"
				if (string_at($original, $current + 1, 1, array('P','B'))){
					$current += 2;
				} else {
					++$current;
				}
				$primary   .= 'P';
				$secondary .= 'P';
				break;

			case 'Q':
				if (substr($original, $current + 1, 1) == 'Q'){
					$current += 2;
				} else {
					++$current;
				}
				$primary   .= 'K';
				$secondary .= 'K';
				break;

			case 'R':
				// french e.g. 'rogier', but exclude 'hochmeier'
				if ($current == $last
				 && !Slavo_Germanic($original)
				 && string_at($original, $current - 2, 2,array('IE'))
				 && !string_at($original, $current - 4, 2,array('ME','MA'))
				){
					// $primary   .= '';
					$secondary .= 'R';
				} else {
					$primary   .= 'R';
					$secondary .= 'R';
				}
				if (substr($original, $current + 1, 1) == 'R'){
					$current += 2;
				} else {
					++$current;
				}
				break;

			case 'S':
				// special cases 'island', 'isle', 'carlisle', 'carlysle'
				if (string_at($original, $current - 1, 3, array('ISL','YSL'))){
					++$current;
					break;
				}

				// special case 'sugar-'
				if ($current == 0
				 && string_at($original, $current, 5, array('SUGAR'))
				){
					$primary   .= 'X';
					$secondary .= 'S';
					++$current;
					break;
				}

				if (string_at($original, $current, 2, array('SH'))){
					// germanic
					if (string_at($original, $current + 1, 4, array('HEIM','HOEK','HOLM','HOLZ'))){
						$primary   .= 'S';
						$secondary .= 'S';
					} else {
						$primary   .= 'X';
						$secondary .= 'X';
					}
					$current += 2;
					break;
				}

				// italian & armenian 
				if (string_at($original, $current, 3, array('SIO','SIA'))
				 || string_at($original, $current, 4, array('SIAN'))
				){
					if (!Slavo_Germanic($original)){
						$primary   .= 'S';
						$secondary .= 'X';
					} else {
						$primary   .= 'S';
						$secondary .= 'S';
					}
					$current += 3;
					break;
				}

				// german & anglicisations, e.g. 'smith' match 'schmidt', 'snider' match 'schneider'
				// also, -sz- in slavic language altho in hungarian it is pronounced 's'
				if ((
						$current == 0
					 && string_at($original, $current + 1, 1, array('M','N','L','W'))
					)
				 || string_at($original, $current + 1, 1, array('Z'))
				){
					$primary   .= 'S';
					$secondary .= 'X';
					if (string_at($original, $current + 1, 1, array('Z'))){
						$current += 2;
					} else {
						++$current;
					}
					break;
				}

			  if (string_at($original, $current, 2, array('SC'))){
				// Schlesinger's rule 
				if (substr($original, $current + 2, 1) == 'H')
					// dutch origin, e.g. 'school', 'schooner'
					if (string_at($original, $current + 3, 2, array('OO','ER','EN','UY','ED','EM'))){
						// 'schermerhorn', 'schenker' 
						if (string_at($original, $current + 3, 2, array('ER','EN'))){
							$primary   .= 'X';
							$secondary .= 'SK';
						} else {
							$primary   .= 'SK';
							$secondary .= 'SK';
						}
						$current += 3;
						break;
					} else {
						if ($current == 0
						 && !is_vowel($original, 3)
						 && substr($original, $current + 3, 1) != 'W'
						){
							$primary   .= 'X';
							$secondary .= 'S';
						} else {
							$primary   .= 'X';
							$secondary .= 'X';
						}
						$current += 3;
						break;
					}

					if (string_at($original, $current + 2, 1,array('I','E','Y'))){
						$primary   .= 'S';
						$secondary .= 'S';
						$current += 3;
						break;
					}

					// else
					$primary   .= 'SK';
					$secondary .= 'SK';
					$current += 3;
					break;
				}

				// french e.g. 'resnais', 'artois'
				if ($current == $last
				 && string_at($original, $current - 2, 2, array('AI','OI'))
				){
					// $primary   .= '';
					$secondary .= 'S';
				} else {
					$primary   .= 'S';
					$secondary .= 'S';
				}

				if (string_at($original, $current + 1, 1, array('S','Z'))){
					$current += 2;
				} else {
					++$current;
				}
				break;

			case 'T':
				if (string_at($original, $current, 4, array('TION'))){
					$primary   .= 'X';
					$secondary .= 'X';
					$current += 3;
					break;
				}

				if (string_at($original, $current, 3, array('TIA','TCH'))){
					$primary   .= 'X';
					$secondary .= 'X';
					$current += 3;
					break;
				}

				if (string_at($original, $current, 2, array('TH'))
				 || string_at($original, $current, 3, array('TTH'))
				){
					// special case 'thomas', 'thames' or germanic
					if (string_at($original, $current + 2, 2, array('OM','AM'))
					 || string_at($original, 0, 4, array('VAN ','VON '))
					 || string_at($original, 0, 3, array('SCH'))
					){
						$primary   .= 'T';
						$secondary .= 'T';
					} else {
						$primary   .= '0';
						$secondary .= 'T';
					}
					$current += 2;
					break;
				}

				if (string_at($original, $current + 1, 1, array('T','D'))){
					$current += 2;
				} else {
					++$current;
				}
				$primary   .= 'T';
				$secondary .= 'T';
				break;

			case 'V':
				if (substr($original, $current + 1, 1) == 'V'){
					$current += 2;
				} else {
					++$current;
				}
				$primary   .= 'F';
				$secondary .= 'F';
				break;

			case 'W':
				// can also be in middle of word
				if (string_at($original, $current, 2, array('WR'))){
					$primary   .= 'R';
					$secondary .= 'R';
					$current += 2;
					break;
				}

				if (($current == 0)
				 && (
						is_vowel($original, $current + 1)
					 || string_at($original, $current, 2, array('WH'))
					)
				){
					// Wasserman should match Vasserman 
					if (is_vowel($original, $current + 1)){
						$primary   .= 'A';
						$secondary .= 'F';
					} else {
						// need Uomo to match Womo 
						$primary   .= 'A';
						$secondary .= 'A';
					}
				}

				// Arnow should match Arnoff
				if ((
						$current == $last
					&& is_vowel($original, $current - 1)
					)
				 || string_at($original, $current - 1, 5, array('EWSKI','EWSKY','OWSKI','OWSKY'))
				 || string_at($original, 0, 3, array('SCH'))
				){
					// $primary   .= '';
					$secondary .= 'F';
					++$current;
					break;
				}

				// polish e.g. 'filipowicz'
				if (string_at($original, $current, 4,array('WICZ','WITZ'))){
					$primary   .= 'TS';
					$secondary .= 'FX';
					$current += 4;
					break;
				}

				// else skip it
				++$current;
				break;

			case 'X':
				// french e.g. breaux 
				if (!(
						$current == $last
					 && (
							string_at($original, $current - 3, 3, array('IAU', 'EAU'))
						 || string_at($original, $current - 2, 2, array('AU', 'OU'))
						)
					)
				){
					$primary   .= 'KS';
					$secondary .= 'KS';
				}

				if (string_at($original, $current + 1, 1, array('C','X'))){
					$current += 2;
				} else {
					++$current;
				}
				break;

			case 'Z':
				// chinese pinyin e.g. 'zhao' 
				if (substr($original, $current + 1, 1) == 'H'){
					$primary   .= 'J';
					$secondary .= 'J';
					$current += 2;
					break;

				} else if (
					string_at($original, $current + 1, 2, array('ZO', 'ZI', 'ZA'))
				 || (
						Slavo_Germanic($original)
					 && (
							$current > 0
						 && substr($original, $current - 1, 1) != 'T'
						)
					)
				){
					$primary   .= 'S';
					$secondary .= 'TS';
				} else {
					$primary   .= 'S';
					$secondary .= 'S';
				}

				if (substr($original, $current + 1, 1) == 'Z'){
					$current += 2;
				} else {
					++$current;
				}
				break;

			default:
				++$current;

		} // end switch

	} // end while

	// printf("<br />ORIGINAL:   %s\n", $original);
	// printf("<br />current:    %s\n", $current);
	// printf("<br />PRIMARY:    %s\n", $primary);
	// printf("<br />SECONDARY:  %s\n", $secondary);

	$primary = substr($primary, 0, 4);
	$secondary = substr($secondary, 0, 4);

	if( $primary == $secondary ){
		$secondary = NULL;
	}

	return array(
				'primary'	=> $primary,
				'secondary'	=> $secondary
				);

} // end of function MetaPhone


/**
 * Name:	string_at($string, $start, $length, $list)
 * Purpose:	Helper function for double_metaphone( )
 * Return:	Bool
 */
function string_at($string, $start, $length, $list){
	if ($start < 0
	 || $start >= strlen($string)
	){
		return 0;
	}

	foreach ($list as $t){
		if ($t == substr($string, $start, $length)){
			return 1;
		}
	}

	return 0;
}


/**
 * Name:	is_vowel($string, $pos)
 * Purpose:	Helper function for double_metaphone( )
 * Return:	Bool
 */
function is_vowel($string, $pos){
	return preg_match("/[AEIOUY]/", substr($string, $pos, 1));
}

/**
 * Name:	Slavo_Germanic($string, $pos)
 * Purpose:	Helper function for double_metaphone( )
 * Return:	Bool
 */

function Slavo_Germanic($string){
	return preg_match("/W|K|CZ|WITZ/", $string);
}

?>