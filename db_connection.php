<?php

/* Copyright (C) 2016 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@pickmybra.in
 * or visit: http://www.pickmybra.in
 */

/*
	Please modify these values according to your server's database's login information
*/

$mysql_database_name 	 = "my_database_name";  	# database's name ( required for login, may not be required if default database is set )
$mysql_database_username = "my_database_username";	# database's username ( for login ) 
$mysql_database_password = "my_database_password";	# database's password ( for login )

/*
	-------------------------------------------------------------------------
	DO NOT MODIFY VALUES BELOW THIS POINT (UNLESS YOU KNOW WHAT YOU'RE DOING)
	-------------------------------------------------------------------------
*/

define('MYSQL_USERNAME', $mysql_database_username);
define('MYSQL_PASSWORD', $mysql_database_password);
define('MYSQL_OPTIONS', "mysql:host=127.0.0.1;dbname=$mysql_database_name;");

function db_connection($buffered = true)
{
	try 
	{
		$connection = new PDO(  MYSQL_OPTIONS, 
								MYSQL_USERNAME, 
								MYSQL_PASSWORD, 
								array(
										PDO::ATTR_TIMEOUT 					=> 60, 
										PDO::ATTR_ERRMODE 					=> PDO::ERRMODE_EXCEPTION, 
										PDO::ATTR_PERSISTENT 				=> false,
										PDO::MYSQL_ATTR_USE_BUFFERED_QUERY 	=> $buffered
									  )
							 );  
	} 
	catch (PDOException $e) 
	{
		return $e->getMessage();
	}
	
	return $connection;
}
?>


