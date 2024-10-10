<?php

/* Copyright (C) 2017 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@gmail.com
 * or visit: http://www.pickmybra.in
 */
 
if ( !isset($index_id) )
{
	die("error: index id not set.\n");
}

$folder_path = realpath(dirname(__FILE__));
$filepath = $folder_path . "/settings_".$index_id.".txt";

# check if the file is readable
if ( !is_readable($filepath) )
{
	die("error: the settings file is not readable.");
}

# use default settings as base values
include_once(realpath(dirname(__FILE__)) . "/settings.php");

# parse the settings file
if ( $data = parse_ini_file($filepath) )
{
	extract($data);
	
	# convert variables to integer
	if ( $index_type == 1 ) 
	{
		$allow_subdomains	= +$allow_subdomains;
 		$honor_nofollows	= +$honor_nofollows;
 		$use_localhost		= +$use_localhost;
 		$index_pdfs			= +$index_pdfs;
		$scan_depth			= +$scan_depth;
	}
	else
	{
		$use_internal_db		= +$use_internal_db;
		$use_buffered_queries	= +$use_buffered_queries;
		$ranged_query_value		= +$ranged_query_value;
		$html_strip_tags		= +$html_strip_tags;
		$include_original_data 	= +$include_original_data;
	}
	
	# general variables
	$indexing_interval 		= +$indexing_interval;
	$update_interval 		= +$update_interval;
	$sentiment_analysis 	= +$sentiment_analysis;
	$prefix_mode 			= +$prefix_mode;
	$prefix_length 			= +$prefix_length;
	$dialect_processing 	= +$dialect_processing;
	$separate_alnum 		= +$separate_alnum;
	$sentiweight 			= +$sentiweight;
	$keyword_suggestions 	= +$keyword_suggestions;
	$keyword_stemming 		= +$keyword_stemming;
	$dialect_matching 		= +$dialect_matching;
	$quality_scoring 		= +$quality_scoring;
	$expansion_limit 		= +$expansion_limit;
	$log_queries 			= +$log_queries;
	$dist_threads 			= +$dist_threads;
	$innodb_row_format 		= +$innodb_row_format;
	$enable_exec 			= +$enable_exec;
	$number_of_fields 		= +$number_of_fields;
	$index_type 			= +$index_type;
}
else
{
	die("error: the settings file $filepath could not be parsed.");
}

# php binary path?
if ( !defined("PHP_PATH") ) 
{
	if ( !empty($php_binary_path) ) 
	{
		define("PHP_PATH", $php_binary_path); # the default option
	}
	else
	{
		define("PHP_PATH", "php"); # the default option
	}
}

if ( $dialect_processing )
{
	$dialect_replacing = array();
	
	# create dialect arrays
	$dialect_array = array( 'š'=>'s', 'ž'=>'z', 'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'æ'=>'a', 'ç'=>'c', 'č'=>'c', 'è'=>'e', 'é'=>'e', 
							'ê'=>'e', 'ë'=>'e', 'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ð'=>'d', 'ñ'=>'n', 'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o',
                            'ö'=>'o', 'ø'=>'o', 'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'μ' => 'u', 'ý'=>'y', 'þ'=>'b', 'ÿ'=>'y', '$' => 's', 'ü' => 'u' , 'ş' => 's',
							'ş' => 's', 'ğ' => 'g', 'ı' => 'i', 'ǐ' => 'i', 'ǐ' => 'i', 'ĭ' => 'i', 'ḯ' => 'i', 'ĩ' => 'i', 'ȋ' => 'i' );
		
	$len = mb_strlen($charset);
	$charset_array = array();
	for ( $i = 0 ; $i < $len ; ++$i ) 
	{
		$charset_array[] = mb_substr($charset, $i, 1);
	}

	if ( !empty($charset_array) )
	{
		foreach ( $charset_array as $char ) 
		{
			if ( isset($dialect_array[$char]) )
			{
				$dialect_replacing[$char] = $dialect_array[$char];
						
				# unset index from dialect array
				unset($dialect_array[$char]);
			}
		}
	}		
}


?>