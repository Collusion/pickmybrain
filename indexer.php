<?php

/* Copyright (C) 2016 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@pickmybra.in
 * or visit: http://www.pickmybra.in
 */

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

# process input variables
require_once("input_value_processor.php");
require_once("tokenizer_functions.php");

# web crawler
if ( $index_type === 1 ) 
{
	require_once("web_tokenizer.php");
}
# database index
else if ( $index_type === 2 ) 
{
	require_once("db_tokenizer.php");
}
else
{
	echo "Unrecognized index_type $index_type";
}

?>