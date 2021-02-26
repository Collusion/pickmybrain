<?php

/* Copyright (C) 2017 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@gmail.com
 * or visit: http://www.pickmybra.in
 */

define("MAX_TOKEN_LEN", 40);
ini_set("display_errors", 1);
error_reporting(E_ALL);
mb_internal_encoding("UTF-8");
set_time_limit(0);
ignore_user_abort(true); 

# check php version
if ( version_compare(PHP_VERSION, '5.3.0') < 0) 
{
    echo "PHP version >= 5.3.0 required, " . PHP_VERSION . " detected.\n";
	echo "Please update your PHP to continue.\n";
	return;
}

$log = "";
$test_mode 		= false;
$user_mode 		= false;
$process_number = 0;

# process input variables
require_once("input_value_processor.php");
require_once("tokenizer_functions.php");

# check if exec() function works properly
$exec_supported = exec_available();

# 32bit runtime environment detected
if ( PHP_INT_SIZE === 4 )
{	
	$bincheck = check_32bit_binaries();
	
	if ( !empty($bincheck) ) 
	{
		# we have a 32bit environment, but not all 32bit files are in place
		echo "A 32-bit PHP runtime environment detected, but 32-bit binaries are not in place.\n";
		echo "Please copy the following files from the bin32 folder to the main folder to start indexing: \n";
		echo implode("\n", $bincheck);
		echo "\n";
	}
}
else 
{
	# 64bit runtime environment
	if ( $enable_exec && !$exec_supported ) 
	{
		# exec() enabled and not supported!
		echo "Your PHP runtime environment doesn't seem to support exec() script execution method.\n";
		echo "Please switch to asynchronous curl requests to continue indexing.\n";
		return;
	}
}

# check if sort is supported
$enable_ext_sorting = isSortSupported();

# check that table definitions are up-to-date
check_tables($index_id);

# web crawler
if ( $index_type == 1 ) 
{
	if ( $enable_exec && $enable_ext_sorting )
	{
		require_once("web_tokenizer_ext.php");
	}
	else
	{
		require_once("web_tokenizer.php");
	}
}
# database index
else if ( $index_type == 2 ) 
{
	if ( $enable_exec && $enable_ext_sorting )
	{
		require_once("db_tokenizer_ext.php");
	}
	else
	{
		require_once("db_tokenizer.php");
	}
}
else
{
	echo "Unrecognized index_type $index_type";
}

?>