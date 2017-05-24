<?php

/* Copyright (C) 2017 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@gmail.com
 * or visit: http://www.hollilla.com/pickmybrain
 */

/*
	Please modify these values according to your server's database's login information
*/

$database_name 	 	= "my_database_name";      # database's name ( required for login, may not be required if default database is set )
$database_username 	= "my_database_username";  # database's username ( for login ) 
$database_password 	= "my_database_password";  # database's password ( for login )

define('USERNAME', $database_username);
define('PASSWORD', $database_password);
define('OPTIONS', "mysql:host=127.0.0.1;dbname=$database_name;"); # please define databa


function ext_db_connection($buffered = true)
{
	try 
	{
		
		/*
		
		Please modify these values according to your database's type or/and settings 
		
		*/

		$connection = new PDO(  OPTIONS, 
								USERNAME, 
								PASSWORD, 
								array(
										PDO::ATTR_TIMEOUT 					=> 60, 
										PDO::ATTR_ERRMODE 					=> PDO::ERRMODE_EXCEPTION, 
										PDO::ATTR_PERSISTENT 				=> false,
										PDO::MYSQL_ATTR_USE_BUFFERED_QUERY 	=> $buffered # this is a MySQL-specific option
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


