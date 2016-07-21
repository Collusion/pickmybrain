<?php

/* Copyright (C) 2016 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@pickmybra.in
 * or visit: http://www.pickmybra.in
 */

if( !defined('SPL_EXISTS') )
{
    define("SPL_EXISTS", class_exists("SplFixedArray"));
}

ini_set('memory_limit', '1024M');

$supported_commands = array("index_id" 			=> 1,
							"testmode" 			=> 1,
							"usermode" 			=> 1,
							"replace" 			=> 1,
							"purge" 			=> 1,
							"data_partition" 	=> 1,
							"process_number" 	=> 1,
							"rebuildprefixes" 	=> 1,
							"2>&1" 				=> 1);

# script initiated by a curl request
if ( !empty($_GET) )
{
	if( isset($_GET["index_id"]) && is_numeric($_GET["index_id"]) )
	{
		$index_id = $_GET["index_id"];
		$index_suffix = "_" . $index_id;
	}
	else
	{
		die("No index id set :(");
	}
	
	# include settings file
	require_once("autoload_settings.php");
	
	# database connection
	require_once("db_connection.php");
	
	if ( !empty($_GET["mode"]) && $_GET["mode"] === "usermode" && $_SERVER["REMOTE_ADDR"] === "127.0.0.1" ) 
	{
		$user_mode = true;
	}
	else if ( !empty($_GET["mode"]) && $_GET["mode"] === "testmode" && $_SERVER["REMOTE_ADDR"] === "127.0.0.1" ) 
	{
		$test_mode = true;
	}
	else if ( !empty($_GET["mode"]) && $_GET["mode"] === "rebuildprefixes" && $_SERVER["REMOTE_ADDR"] === "127.0.0.1" ) 
	{
		rebuild_prefixes($prefix_mode, $prefix_length, $dialect_replacing, $index_suffix);
		return;
	}

	# launch multiple processes ?
	if ( !empty($_GET["process_number"]) && is_numeric($_GET["process_number"]) && $_GET["process_number"] >= 1 && $_GET["process_number"] <= 16 )
	{
		$process_number = (int)$_GET["process_number"];
	}
	
	# launch multiple processes ?
	if ( isset($_GET["data_partition"]) )
	{
		$data_partition = explode("-", trim($_GET["data_partition"]));
	}
	
	if ( isset($_GET["replace"]) )
	{
		$replace_index = true;
	}
}
# script launched by command line / exec()
else if ( !empty($argv) )
{
	$i = 1;
	while ( isset($argv[$i]) )
	{
		$pos = strpos($argv[$i], "=");
		
		if ( $pos !== false )
		{
			$command = substr($argv[$i], 0, $pos);
			
			if ( !isset($supported_commands[$command]) )
			{
				die("invalid command ( " . $argv[$i] . " )\n");
			}
			
			$value = substr($argv[$i], $pos+1);

			switch ( $command ) 
			{
				case 'index_id';
				if ( is_numeric($value) )
				{
					$index_id = (int)$value;
					$index_suffix = "_" . $index_id;
				}
				break;

				case 'process_number':
				if ( is_numeric($value) && $value >= 1 && $value <= 16 )
				{
					$process_number = (int)$value;
				}
				break;
				
				case 'data_partition':
				if ( !empty($value) )
				{
					$data_partition = explode("-", trim($value));
				}
				break;
			}
		}
		else
		{
			if ( isset($supported_commands[$argv[$i]]) )
			{
				switch ( $argv[$i] )
				{
					case 'testmode':
					$test_mode = true;
					break;
						
					case 'usermode':
					$user_mode = true;
					break;
					
					case 'purge';
					$purge_index = true;
					break;
					
					case 'replace';
					$replace_index = true;
					break;
						
					# rebuild prefixes and quit
					case 'rebuildprefixes':
					rebuild_prefixes($prefix_mode, $prefix_length, $dialect_replacing, $index_suffix);
					return;
					break;
				}
			}
			else
			{
				die("invalid command ( " . $argv[$i] . " )\n");
			}
		}
		
		++$i;
	}
	
	if ( !isset($index_id) )
	{
		die("No index id set :(");
	}
	
	# include settings file
	require_once("autoload_settings.php");
	
	# database connection
	require_once("db_connection.php");
}
else
{
	die("Incorrect parameters provided.");
}

if ( !empty($sentiment_analysis) ) 
{
	if ( is_readable(realpath(dirname(__FILE__)) . "/sentiment/pmbsentiment.php") )
	{
		include("sentiment/pmbsentiment.php");
		
		if ( class_exists("PMBSentiment") )
		{
			switch ( $sentiment_analysis ) 
			{
				case 1:
				# english
				$sentiment = new PMBSentiment();
				$sentiment_class_name = "sentiment"; # generic name
				break;
				
				case 2:
				# finnish
				$sentiment = new PMBSentiment("fi");
				$sentiment_class_name = "sentiment"; # generic name
				break;
				
				case 1001:
				# all classes must be initialized
				$sentiment_en = new PMBSentiment();
				$sentiment_fi = new PMBSentiment("fi");
				$sentiment_class_name = ""; # will be decided later
				break;
				
				default:
				$sentiment_analysis = 0;
				break;	
			}
		}
		else
		{
			$log .= "NOTICE: Sentiment analysis is enabled, but the class library is missing. This feature will not work.\n";
			$sentiment_analysis = 0;
		}
	}
	else
	{
		# sentiment analysis enabled, but missing
		$log .= "NOTICE: Sentiment analysis is enabled, but the required library is missing. This feature will not work.\n";
		$sentiment_analysis = 0;
	}
}

$innodb_row_format_sql = "";
if ( isset($innodb_row_format) )
{
	switch ( $innodb_row_format ) 
	{
		case 0;
		$innodb_row_format_sql = "ROW_FORMAT=COMPACT";
		break;
		case 1;
		$innodb_row_format_sql = "ROW_FORMAT=REDUNDANT";
		break;
		case 2;
		$innodb_row_format_sql = "ROW_FORMAT=DYNAMIC";
		break;
		case 3;
		$innodb_row_format_sql = "ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=16";
		break;
		case 4;
		$innodb_row_format_sql = "ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=8";
		break;
		case 5;
		$innodb_row_format_sql = "ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=4";
		break;
	}
}

# special case
# 1. alternative script execution method is selected
# 2. indexer is launched via command line
# 3. but document root is not defined ( index is created with the cli tool )
# => enable script execution through exec()
if ( !$enable_exec && !empty($argv) && empty($document_root) ) 
{
	$enable_exec = 1;
}

?>