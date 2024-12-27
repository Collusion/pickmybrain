<?php

/* Copyright (C) 2017 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@gmail.com
 * or visit: http://www.pickmybra.in
 */

ini_set("display_errors", 1);
error_reporting(E_ALL);
mb_internal_encoding("UTF-8");
set_time_limit(0);

require_once("password.php");
session_start();

# ensure that user has logged in and session has not been expired
if ( !isset($_SESSION["pmb_logged_in"]) || (PMB_SESSIONLEN != 0 && time() > $_SESSION["pmb_logged_in"] + PMB_SESSIONLEN) )
{
	header("Location: loginpage.php");
	return;
}

require_once("tokenizer_functions.php");

/* index id */
$index_id = "";
if ( isset($_POST["index_id"]) && is_numeric($_POST["index_id"]) )
{
	$index_id = $_POST["index_id"];
}
else if ( isset($_GET["index_id"]) && is_numeric($_GET["index_id"]) )
{
	$index_id = $_GET["index_id"];
}

if ( !empty($_GET["bin32replacement"]) && $_GET["bin32replacement"] === "true" )
{
	if ( PHP_INT_SIZE !== 8 ) 
	{
		// only attemp copying the files if user is running 32bit environment and binaries are actually missing
		$binary_check = check_32bit_binaries();
		$copy_errors = 0;
		
		foreach ( $binary_check as $binary_file_name ) 
		{
			if ( !copy("bin32/".$binary_file_name, $binary_file_name) )
			{
				++$copy_errors;
			}
		}
		
		if ( !$copy_errors ) 
		{
			header("Location: control.php");
			return;
		}
	}
}

?>


<!DOCTYPE html>
<!--[if lt IE 8 ]><html class="ie ie7" lang="en"> <![endif]-->
<!--[if IE 8 ]><html class="ie ie8" lang="en"> <![endif]-->
<!--[if (gte IE 8)|!(IE)]><!--><html lang="en"> <!--<![endif]-->
<head>


   <!--- Basic Page Needs
   ================================================== -->
	<meta charset="utf-8">
	<title>Pickmybrain Control Panel</title>
	<meta name="description" content="">
	<meta name="author" content="">

   <!-- Mobile Specific Metas
  ================================================== -->
	<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">

	<!-- CSS
   ================================================== -->
   <link rel="stylesheet" href="css/base.css">
   <link rel="stylesheet" href="css/layout.css">
   <link rel="stylesheet" href="css/pmb.css">
   <link rel="stylesheet" href="css/livesearch.css">
  
   <!-- Favicons
	================================================== -->
	<link rel="shortcut icon" href="images/favicon.ico">
	<script src='css/livesearch.js'></script>

</head>

<body>

   <!-- Header
   ================================================== -->
   <header id="top" class="static" >

      <div class="row">

         <div class="col full">

            <div class="logo">
               <a><img alt="" src="images/logo.png"></a>
            </div>

            <nav id="nav-wrap">

               <a class="mobile-btn" href="#nav-wrap" title="Show navigation">Show navigation</a>
	            <a class="mobile-btn" href="#" title="Hide navigation">Hide navigation</a>

               <ul id="nav" class="nav">
               		
	               <li class="active"><a href="control.php">Control panel</a></li>
               </ul>

            </nav>

         </div>

      </div>

   </header> <!-- Header End -->


   <!-- Container
   ================================================== -->
   <section class="container">

      <div class="row section-head add-bottom">
         <div class="col full">

            <h2>Control panel</h2>
            <a href='loginpage.php?logout=1'>Log out</a> 
            <hr />

         </div>
      </div>

      <div class="row add-bottom">

        

<?php


$errors = 0;
$folder_path 					= realpath(dirname(__FILE__));
$database_file_path 			= $folder_path . "/db_connection.php";
$search_file_path 				= $folder_path . "/PMBApi.php";
$indexer_file_path 				= $folder_path . "/indexer.php";
$web_tokenizer_file_path 		= $folder_path . "/web_tokenizer.php";
$db_tokenizer_file_path 		= $folder_path . "/db_tokenizer.php";
$web_tokenizer_ext_file_path 	= $folder_path . "/web_tokenizer_ext.php";
$db_tokenizer_ext_file_path 	= $folder_path . "/db_tokenizer_ext.php";
$tokenizer_functions_file_path 	= $folder_path . "/tokenizer_functions.php";
$settings_file_path 			= $folder_path . "/settings.php";
$settings_loader_path			= $folder_path . "/autoload_settings.php";

# 1. test if the database configuration file is readable
if ( !isset($_SESSION["self_check_ok"]) && !is_readable($database_file_path) )
{
	++$errors;
	$current_permissions = substr(sprintf('%o', fileperms($database_file_path)), -4);
	
	echo "<div class='errorbox'>
			<h3 style='color:#ff0000;'>Error: database configuration file db_connection.php cannot be read.</h3>
		  	<p>Please chmod the file for greater permissions. Current permissions: $current_permissions, required permissions: 0644 ( or greater )</p>
		  </div>";
}

# 2. test if the settings file is readable
if ( !isset($_SESSION["self_check_ok"]) && !is_readable($settings_file_path) )
{
	++$errors;
	$current_permissions = substr(sprintf('%o', fileperms($settings_file_path)), -4);

	echo "<div class='errorbox'>
			<h3 style='color:#ff0000;'>Error: settings file settings.php cannot be read.</h3>
		  	<p>Please chmod the file for greater permissions. Current permissions: $current_permissions, required permissions: 0644 ( or greater )</p>
		  </div>";
}

# 2. test if the settings-loader file is readable
if ( !isset($_SESSION["self_check_ok"]) && !is_readable($settings_loader_path) )
{
	++$errors;
	$current_permissions = substr(sprintf('%o', fileperms($settings_loader_path)), -4);

	echo "<div class='errorbox'>
			<h3 style='color:#ff0000;'>Error: settings loader file autoload_settings.php cannot be read.</h3>
		  	<p>Please chmod the file for greater permissions. Current permissions: $current_permissions, required permissions: 0644 ( or greater )</p>
		  </div>";
}

# 3. test if the search file is readable
if ( !isset($_SESSION["self_check_ok"]) && !is_readable($search_file_path) )
{
	++$errors;
	$current_permissions = substr(sprintf('%o', fileperms($search_file_path)), -4);
	
	echo "<div class='errorbox'>
			<h3 style='color:#ff0000;'>Error: search query input processor file PMBApi.php cannot be read.</h3>
		  	<p>Please chmod the file for greater permissions. Current permissions: $current_permissions, required permissions: 0644 ( or greater )</p>
		  </div>";
}

# 4. test if the document processor file is readable
if ( !isset($_SESSION["self_check_ok"]) && !is_readable($indexer_file_path) )
{
	++$errors;
	$current_permissions = substr(sprintf('%o', fileperms($indexer_file_path)), -4);
	
	echo "<div class='errorbox'>
			<h3 style='color:#ff0000;'>Error: indexer file indexer.php cannot be read.</h3>
		  	<p>Please chmod the file for greater permissions. Current permissions: $current_permissions, required permissions: 0644 ( or greater )</p>
		  </div>";
}

# 4. test if the document processor file is readable
if ( !isset($_SESSION["self_check_ok"]) && !is_readable($web_tokenizer_file_path) )
{
	++$errors;
	$current_permissions = substr(sprintf('%o', fileperms($web_tokenizer_file_path)), -4);
	
	echo "<div class='errorbox'>
			<h3 style='color:#ff0000;'>Error: web-crawler file web_tokenizer.php cannot be read.</h3>
		  	<p>Please chmod the file for greater permissions. Current permissions: $current_permissions, required permissions: 0644 ( or greater )</p>
		  </div>";
}

# 4. test if the document processor file is readable
if ( !isset($_SESSION["self_check_ok"]) && !is_readable($db_tokenizer_file_path) )
{
	++$errors;
	$current_permissions = substr(sprintf('%o', fileperms($db_tokenizer_file_path)), -4);
	
	echo "<div class='errorbox'>
			<h3 style='color:#ff0000;'>Error: database document collector/tokenizer file db_tokenizer.php cannot be read.</h3>
		  	<p>Please chmod the file for greater permissions. Current permissions: $current_permissions, required permissions: 0644 ( or greater )</p>
		  </div>";
}

# 4. test if the document processor file is readable
if ( !isset($_SESSION["self_check_ok"]) && !is_readable($web_tokenizer_ext_file_path) )
{
	++$errors;
	$current_permissions = substr(sprintf('%o', fileperms($web_tokenizer_ext_file_path)), -4);
	
	echo "<div class='errorbox'>
			<h3 style='color:#ff0000;'>Error: web-crawler file web_tokenizer_ext.php cannot be read.</h3>
		  	<p>Please chmod the file for greater permissions. Current permissions: $current_permissions, required permissions: 0644 ( or greater )</p>
		  </div>";
}

# 4. test if the document processor file is readable
if ( !isset($_SESSION["self_check_ok"]) && !is_readable($db_tokenizer_ext_file_path) )
{
	++$errors;
	$current_permissions = substr(sprintf('%o', fileperms($db_tokenizer_ext_file_path)), -4);
	
	echo "<div class='errorbox'>
			<h3 style='color:#ff0000;'>Error: database document collector/tokenizer file db_tokenizer_ext.php cannot be read.</h3>
		  	<p>Please chmod the file for greater permissions. Current permissions: $current_permissions, required permissions: 0644 ( or greater )</p>
		  </div>";
}

# 5. check that mbstring extension is loaded
if ( !isset($_SESSION["self_check_ok"]) && !extension_loaded("mbstring") )
{
	++$errors;
	
	echo "<div class='errorbox'>
			<h3 style='color:#ff0000;'>Error: mbstring php extension is disabled/missing</h3>
		  	<p>Pickmybrain relies heavily on handling multibyte strings and that is why mbstring is essential for operation. Please install or enable mbstring to continue.</p>
		  </div>";
}

# check php version ( x64 ) 
if ( !isset($_SESSION["self_check_ok"]) && PHP_INT_SIZE !== 8 )
{
	// check if user has replaced the default 64bit binaries with the provided 32bit ones
	$binary_check = check_32bit_binaries();

	if ( !empty($binary_check) ) 
	{
		$unsuccessful = "";
		if ( !empty($_GET["bin32replacement"]) && $_GET["bin32replacement"] === "true" )
		{
			$unsuccessful = "<p style='color:#ff0000;'>Replacement attempt was unsuccessful, please try replacing the files manually.</p>";
		}
		
		++$errors;
		echo "<div class='errorbox'>
		<h3 style='color:#ff0000;'>Error: 32-bit binaries not detected</h3>
		<p>
		You are running a 32-bit PHP runtime environment, 
		which requires replacing the default 64-bit binaries with the provided 32-bit ones.</p>
		<p>More specifically, these files are still needed to be copied from the bin32 folder to the main folder: <br>
		".implode("<br>", $binary_check)."
		</p>
		<p><a href='control.php?bin32replacement=true'>Attempt replacing the binaries automatically</a></p>
		$unsuccessful
	  </div>";
	}
}

# check php version
if ( !isset($_SESSION["self_check_ok"]) && version_compare(PHP_VERSION, '5.3.0') < 0) 
{
    ++$errors;
	
	echo "<div class='errorbox'>
			<h3 style='color:#ff0000;'>Error: PHP version >= 5.3.0 required</h3>
		  	<p>Pickmybrain requires a newer PHP runtime environment. Required version: 5.3.0, version installed: " . PHP_VERSION . "</p>
		  </div>";
}

# 6. check that curl extension is loaded
if ( !isset($_SESSION["self_check_ok"]) && !extension_loaded("curl") )
{
	++$errors;
	
	echo "<div class='errorbox'>
			<h3 style='color:#ff0000;'>Error: curl php extension is disabled/missing</h3>
		  	<p>Pickmybrain makes all web requests using functions based on the popular curl-extension. Please install or enable curl to continue.</p>
		  </div>";
}

# 5. try establishing a database connection
require("db_connection.php");
$connection = db_connection();

if ( is_string($connection) )
{
	++$errors;
	echo "<div class='errorbox'>
			<h3 style='color:#ff0000;'>Error: database connection could not be established</h3>
		  	<p>Please modify the file db_connection.php and place your database's login credentials into appropriate places. Additionally, a following error message was received: </p>
			<p>$connection</p>
		  </div>";
}

# 6. test that the connection actually works
try
{
	$pdo = $connection->query("SELECT 1+1 AS Test");
	
	# innodb file per table
	$tablepdo = $connection->query("SHOW VARIABLES LIKE '%innodb_file_per_table%'");
	$innodb_file_per_table = false;
	
	if ( $row = $tablepdo->fetch(PDO::FETCH_ASSOC) )
	{
		if ( stripos($row["Value"], "ON") !== false ) 
		{
			$innodb_file_per_table = true;
		}
	}
	
	# innodb file format 
	$tablepdo = $connection->query("SHOW VARIABLES LIKE 'innodb_file_format'");
	$innodb_file_format_barracuda = false;
	$innodb_file_format = "";
	
	# Older MySQL
	if ( $row = $tablepdo->fetch(PDO::FETCH_ASSOC) )
	{
		if ( stripos($row["Value"], "Barracuda") !== false ) 
		{
			$innodb_file_format_barracuda = true;
		}
		
		$innodb_file_format = strtolower($row["Value"]);
	}
	# MySQL >= 5.7
	else 
	{
		$tablepdo = $connection->query("SHOW VARIABLES LIKE 'innodb_default_row_format'");
		if ( $row = $tablepdo->fetch(PDO::FETCH_ASSOC) )
		{
			if ( stripos($row["Value"], "Barracuda") !== false ) 
			{
				$innodb_file_format_barracuda = true;
			}
			
			$innodb_file_format = strtolower($row["Value"]);
		}
	}

	
	$db_error = "";
	if ( !checkPMBIndexes($db_error) )
	{
		++$errors;
		echo "<div class='errorbox'>
			<h3 style='color:#ff0000;'>Error: table PMBIndexes could not be created or modified.</h3>
			<p>$db_error</p>
		  </div>";
	}
	
	try
	{	
		$pre_existing_indexes = array();

		# check if any indexes are defined
		$indexpdo = $connection->query("SELECT * FROM PMBIndexes ORDER BY ID ASC");
				
		while ( $row = $indexpdo->fetch(PDO::FETCH_ASSOC) )
		{
			$pre_existing_indexes[(int)$row["ID"]] = $row;
		}
		
	}
	catch ( PDOException $e ) 
	{
		++$errors;
		echo "<div class='errorbox'>
			<h3 style='color:#ff0000;'>Error: unable to check existing indexes</h3>
			<p>".$e->getMessage()."</p>
		  </div>";
	}
	
	if ( !empty($index_id) && !isset($pre_existing_indexes[(int)$index_id]) )
	{
		# if index id does not exist in the database, return to main menu
		header("Location: control.php");
		return;
	}
}
catch ( PDOException $e ) 
{
	++$errors;
	echo "<div class='errorbox'>
			<h3 style='color:#ff0000;'>Error: database communication failure</h3>
		  	<p>Database connection was established, but an error happened while testing the connection: </p>
			<p>".$e->getMessage()."</p>
		  </div>";
}

# test if exec is available
$exec_available = true;
if ( !function_exists('exec') || exec('echo EXEC') != 'EXEC' )
{
	$exec_available = false;
}
else if ( PHP_INT_SIZE === 4 ) 
{
	// exec() execution method is unavailable in 32bit environments
	$exec_available = false;
}

# 8. test if the current directory is writable
if ( !isset($_SESSION["self_check_ok"]) && !is_writable($folder_path) )
{
	++$errors;
	echo "<div class='errorbox'>
			<h3 style='color:#ff0000;'>Error: the folder $folder_path is not writable.</h3>
		  	<p>Pickmybrain must be able to modify the settings file and create temporary files if the PDF indexing is enabled.</p>
			<p>Please chmod the current folder and the settings file for creater permissions.</p>
		  </div>";
}

# 9. If there are errors, encourage the user to fix them ! 
if ( $errors === 1 ) 
{
	echo "<h2>Please resolve the error above to start indexing your website.</h2>";
	return;
}
else if ( $errors > 0 ) 
{
	echo "<h2>Please resolve all of above $errors errors to start indexing your website.</h2>";
	return;
}

// self check is now complete ! 
$_SESSION["self_check_ok"] = 1;

if ( !empty($index_id) )
{
	$index_type = $pre_existing_indexes[(int)$index_id]["type"];
	
	if ( !is_readable($folder_path . "/settings_$index_id.txt") )
	{
		$dummy = array("index_type" => $index_type);
		# create the settings file with default values for current index_id
		if ( $index_type == 1 ) 
		{
			$dummy = $dummy + array("data_columns" => "title,content,url,meta");
		}
		
		$outcome = write_settings($dummy, $index_id);
	}
	
	# now, include the just created settings file
	require("autoload_settings.php");
}

# show a warning if exec is enabled but not supported
if ( !$exec_available && !empty($enable_exec) )
{
	echo "<div class='errorbox'>
			<h3 style='color:#ff9c00;'>Warning: cannot launch scripts via exec() function</h3>
		  	<p>It seems your PHP runs in safe mode, the exec funtion is otherwise disabled or you have a 32bit PHP runtime environment.</p>
			<p>Please disable the safe mode, enable the exec function and/or update to a 64bit PHP environment if you want to use the exec() script execution method.</p>
			<p>If this is not possible, please use the alternative method for launching scripts at the <a href='#execution_method'>General settings section</a>.</p>
		  </div>";
}

if ( !empty($_POST["action"]) && $_POST["action"] === "updatesettings" )
{
	# settings are to be UPDATED
	$outcome = write_settings($_POST, $index_id);

	if ( $outcome ) 
	{
		#manually redirect user
		header("Location: control.php?index_id=$index_id");
		return;
	}
}
else if ( !empty($_GET["action"]) && $_GET["action"] === "resetindexer" )
{
	$directory = realpath(dirname(__FILE__));
	$exec_is_supported = false;

	if ( function_exists("exec") && exec('echo EXEC') == 'EXEC' )
	{
		# issue kill commands for processes if it is supported
		$exec_is_supported = true;
	}
	
	try
	{
		if ( !empty($_GET["kill"]) && $_GET["kill"] === "yes" )
		{
			$checkpdo = $connection->query("SELECT ID FROM PMBIndexes");
			
			while ( $row = $checkpdo->fetch(PDO::FETCH_ASSOC) )
			{
				$index_id = (int)$row["ID"];
				$pidlist = array();
				
				# open pid files
				for ( $i = 0 ; $i < 16 ; ++$i ) 
				{
					$filepath = $directory . "/pmb_".$index_id."_".$i.".pid";
					if ( is_readable($filepath) )
					{
						$pid = file_get_contents($filepath);			
						$pidlist[] = (int)$pid;
					}
				}
				
				if ( $exec_is_supported && !empty($pidlist) )
				{
					# kill 
					foreach ( $pidlist as $pid ) 
					{
						exec("kill -9 $pid");
					}
				}
			}
		}
		
		# after this, update the PMBIndexes table
		$connection->query("UPDATE PMBIndexes SET current_state = 0");
	}
	catch ( PDOException $e ) 
	{
		echo "An error occurred during uninstallation: " . $e->getMessage() . "\n";
	}
	
	header("Location: control.php");
	return;
}
else if ( !empty($_GET["action"]) && $_GET["action"] === "uninstallpmb" )
{
	try
	{
		$checkpdo = $connection->query("SELECT ID FROM PMBIndexes");
		
		while ( $row = $checkpdo->fetch(PDO::FETCH_ASSOC) )
		{
			$index_id = (int)$row["ID"];
			
			if ( !deleteIndex($index_id) )
			{
				echo "Something went wrong while deleting index $index_id\n";
			}
		}

		# then, remove the PMBIndexes table
		$connection->query("DROP TABLE PMBIndexes");
		$_SESSION = array();
		session_destroy();
		header("Location: https://www.pickmybra.in");
		return;
	}
	catch ( PDOException $e ) 
	{
		echo "An error occurred during uninstallation: " . $e->getMessage() . "\n";
	}
}
else if ( !empty($_POST["action"]) && $_POST["action"] === "createindex"  ) 
{
	$new_index_name = "";
	$new_index_type = 0;
	
	if ( !empty($_POST["new_index_name"])  )
	{
		$_POST["new_index_name"] = trim($_POST["new_index_name"]);
		
		$len = strlen($_POST["new_index_name"]);
		preg_match("/[^a-z0-9]/", $_POST["new_index_name"], $preg_m);

		if ( $len > 0 && $len <= 20 && empty($preg_m) ) 
		{
			$new_index_name = $_POST["new_index_name"];
		}
	}
	
	if ( !empty($_POST["new_index_type"]) 
		&& is_numeric($_POST["new_index_type"])
		&& $_POST["new_index_type"] > 0 
		&& $_POST["new_index_type"] < 3
		)
	{
		$new_index_type = $_POST["new_index_type"];
	}
	
	if ( empty($new_index_name) )
	{
		echo "<div class='errorbox'>
			<h3 style='color:#ff9c00;'>Warning: Incorrect index name</h3>
		  	<p>Please define the index name with only small letters ( a-z ) and numbers ( 0-9 ).</p>
		  </div>";
	}
	else if ( empty($new_index_type) ) 
	{
			echo "<div class='errorbox'>
			<h3 style='color:#ff9c00;'>Warning: Undefined index type</h3>
		  	<p>Please define the index type either as web-crawler or database index.</p>
		  </div>";
	}
	else
	{
		try
		{
			$precheck = $connection->prepare("SELECT ID FROM PMBIndexes WHERE name = ?");
			$precheck->execute(array($new_index_name));
			
			if ( $row = $precheck->fetch(PDO::FETCH_ASSOC) )
			{
				echo "<div class='errorbox'>
				<h3 style='color:#ff9c00;'>Warning: Index by the name $new_index_name already exists!</h3>
				<p>Please give your new index an unique name.</p>
			  </div>";
			}
			else
			{
				$indpdo = $connection->prepare("INSERT INTO PMBIndexes (name, type, updated) VALUES(?, ?, UNIX_TIMESTAMP())");
				$indpdo->execute(array($new_index_name, $new_index_type));
				
				# page needs to be loaded again
				header("Location: control.php");
				return;
			}

		}
		catch ( PDOException $e ) 
		{
			echo "An error occurred: " . $e->getMessage();
		}
	}
}
else if ( !empty($_POST["action"]) && $_POST["action"] === "runindexer" && !empty($index_id) && !empty($pre_existing_indexes[(int)$index_id]) )
{
	# index type ? 
	$index_type = $pre_existing_indexes[(int)$index_id]["type"];
	$index_suffix = "_" . $index_id;
	$redirect = 1;

	$file_to_execute = "indexer.php";

	try
	{	
		if ( !empty($_POST["test_index"]) )
		{
			# run the indexer in test mode
			$testpdo = $connection->prepare("UPDATE PMBIndexes SET indexing_permission = 1 WHERE ID = ?");
			$testpdo->execute(array($index_id));
			
			if ( $enable_exec ) 
			{
				$output = array();
				exec("php " . realpath(dirname(__FILE__)) . "/$file_to_execute index_id=$index_id testmode 2>&1", $output);
				echo "<div style='float:left;clear:both;display:inline-block;'><textarea rows='35' cols='100'>".implode("\n", $output)."</textarea></div>";	
			}
			else
			{
				$url_to_exec = "http://localhost" . str_replace("control.php", $file_to_execute, $_SERVER['SCRIPT_NAME']) . "?mode=testmode&index_id=$index_id";
				
				echo "<div style='float:left;clear:both;display:inline-block;'><textarea rows='35' cols='100'>";
				execWithCurl($url_to_exec, false);
				echo "</textarea></div>";
			}
			
			$redirect = false;
		}
		else if ( !empty($_POST["run_indexer"]) ) 
		{
			# set indexing state = 1, current run docloads, current run tick-tock ? 
			# run the indexer in user mode ( this mode overrides the indexer interval time ) 
			
			$connection->query("UPDATE PMBIndexes SET 
								indexing_permission = 1,
								current_state = 0,
								temp_loads = 0,
								temp_loads_left = 0,
								updated = 0
								WHERE ID = $index_id");
			
			if ( $enable_exec )
			{
				# launch via exec()	
				execInBackground("php " . realpath(dirname(__FILE__)) . "/$file_to_execute index_id=$index_id usermode");
			}
			else
			{
				# launch via async curl
				$url_to_exec = "http://localhost" . str_replace("control.php", $file_to_execute, $_SERVER['SCRIPT_NAME']) . "?mode=usermode&index_id=$index_id";
				execWithCurl($url_to_exec);
			}
		}
		else if ( !empty($_POST["purge_index"]) ) 
		{
			try
			{
				# stop the indexer, reset statics
				$connection->beginTransaction();		
				$connection->query("UPDATE PMBIndexes SET 
									documents = 0,
									max_id = 0,
									delta_documents = 0,
									latest_rotation = 0,
									current_state = 0,
									indexing_permission = 0,
									indexing_started = 0,
									updated = 0,
									temp_loads = 0,
									temp_loads_left = 0
									WHERE ID = $index_id");
				
				# wait
				sleep(1);
				
				# delete the index
				$connection->exec("SET FOREIGN_KEY_CHECKS=0;
									TRUNCATE TABLE PMBDocinfo$index_suffix;
									TRUNCATE TABLE PMBPrefixes$index_suffix;
									TRUNCATE TABLE PMBTokens$index_suffix;
									DROP TABLE IF EXISTS PMBTokens".$index_suffix."_delta;
									DROP TABLE IF EXISTS PMBPrefixes".$index_suffix."_delta;
									DROP TABLE IF EXISTS PMBDocinfo".$index_suffix."_delta;
									DROP TABLE IF EXISTS PMBTokens".$index_suffix."_temp;
									DROP TABLE IF EXISTS PMBPrefixes".$index_suffix."_temp;
									DROP TABLE IF EXISTS PMBDocinfo".$index_suffix."_temp;
									SET FOREIGN_KEY_CHECKS=1;");
									
				$connection->query("UPDATE PMBCategories$index_suffix SET count = 0");
				
				# delete temporary tables
				if ( isset($dist_threads) && $dist_threads > 1 )
				{
					$sql = "DROP TABLE IF EXISTS PMBdatatemp_$index_id;
							DROP TABLE IF EXISTS PMBpretemp_$index_id;
							DROP TABLE IF EXISTS PMBtoktemp_$index_id;";
							
					for ( $i = 1 ; $i < $dist_threads ; ++$i ) 
					{
						$sql .= "DROP TABLE IF EXISTS PMBtemporary_" . $index_id . "_" . $i . ";";
					}
					
					$connection->exec($sql);
				}	
				
				$connection->commit();
			}
			catch ( PDOException $e ) 
			{
				echo $e->getMessage();
				$connection->rollBack();
				die();
			}
		}
		else if ( !empty($_POST["delete_index"]) ) 
		{
			deleteIndex($index_id);
							
			$redirect = 2;	
			# reset statistics				
			#echo "<h2 class='green'>Index was removed successfully</h2>";	
		}
		else if ( !empty($_POST["stop_indexer"]) || !empty($_POST["stop_rebuilder"])  ) 
		{	
			$connection->query("UPDATE PMBIndexes SET 
								indexing_permission = 0,
								indexing_started = 0,
								temp_loads = 0,
								temp_loads_left = 0,
								updated = 0
								WHERE ID = $index_id");			
		}
		else if ( !empty($_POST["rebuild_prefixes"]) ) 
		{
			if ( $enable_exec ) 
			{
				execInBackground("php " . realpath(dirname(__FILE__)) . "/$file_to_execute index_id=$index_id rebuildprefixes");
			}
			else
			{
				# launch via async curl
				$url_to_exec = "http://localhost" . str_replace("control.php", $file_to_execute, $_SERVER['SCRIPT_NAME']) . "?mode=rebuildprefixes&index_id=$index_id";
				execWithCurl($url_to_exec);
			}
		}
	}
	catch ( PDOException $e ) 
	{
		echo $e->getMessage();
	}
	
									
	# redirect to index page
	if ( $redirect === 1 )
	{
		header("Location: control.php?index_id=$index_id");
		die();
	}
	# redirect to control panel
	else if ( $redirect === 2 ) 
	{
		header("Location: control.php");
		return;
	}
}
else if ( !empty($_POST["action"]) && isset($_POST["mysql_data_dir"]) && $_POST["action"] === "modify_mysql_data_dir" && !empty($index_id) && !empty($pre_existing_indexes[(int)$index_id]) ) 
{
	$mysql_data_dir = $_POST["mysql_data_dir"];
	## delete pre-existing tables and create them again with custom data dir
	#echo "deleting tables !!! ( $mysql_data_dir)";
	$index_suffix = "_" . $index_id; 
	
	
	try
	{
		$connection->beginTransaction();
		$connection->exec("SET FOREIGN_KEY_CHECKS=0;
								DROP TABLE IF EXISTS PMBDocinfo$index_suffix;
								DROP TABLE IF EXISTS PMBPrefixes$index_suffix;
								DROP TABLE IF EXISTS PMBTokens$index_suffix;
								DROP TABLE IF EXISTS PMBMatches$index_suffix;
								DROP TABLE IF EXISTS PMBDocMatches$index_suffix;
								DROP TABLE IF EXISTS PMBCategories$index_suffix;
								DROP TABLE IF EXISTS PMBQueryLog$index_suffix;
								DROP TABLE IF EXISTS PMBtoktemp$index_suffix;
								DROP TABLE IF EXISTS PMBdatatemp$index_suffix;
								DROP TABLE IF EXISTS PMBpretemp$index_suffix;
							SET FOREIGN_KEY_CHECKS=1;");
		
		# reset statistics						
		$connection->query("UPDATE PMBIndexes SET 
								documents = 0,
								current_state = 0,
								indexing_permission = 0,
								indexing_started = 0,
								updated = 0,
								temp_loads = 0,
								temp_loads_left = 0
								WHERE ID = $index_id");
		$connection->commit();
	}
	catch ( PDOException $e ) 
	{
		$connection->rollBack();
		echo "error during dropping tables: ";
		echo $e->getMessage(). "<br>";
	}
	
	$settings_to_write = $_POST;
}

$data_dir_sql = "";
# sql for table creation
if ( !empty($mysql_data_dir) && $innodb_file_per_table )
{
	$data_dir_sql = "DATA DIRECTORY = '$mysql_data_dir' INDEX DIRECTORY = '$mysql_data_dir'";
}

/*
	Show the welcome dialog if no index is selected or the provided index ID is incorrect
*/

if ( empty($index_id) || !is_numeric($index_id) || !isset($pre_existing_indexes[(int)$index_id]) )
{

?>



<p>
Hi there! Start configuration of your Pickmybrain search engine by selecting an existing search index or create a new one below.
</p>

<div class='settingsbox'>
	<h3>Pre-existing indexes</h3>
   
    <p>
    <?php
		if ( empty($pre_existing_indexes) ) 
		{
			echo "<b class='green'>It seems that you have not got any pre-existing indexes. Start by creating a new one below.</b>";
		}
		else
		{
			echo "<div style='width:100%;clear:both;display:inline-block;'>
					   <div style='width:23%;float:left;padding:2px 2% 2px 0%;'>name</div>
					   <div style='width:23%;float:left;padding:2px 2% 2px 0%;'>type</div>
					    <div style='width:23%;float:left;padding:2px 2% 2px 0%;'>docs</div>
						<div style='width:23%;float:left;padding:2px 2% 2px 0%;'>latest update/modification</div>
						</div>";
			
			
			foreach ( $pre_existing_indexes as $row ) 
			{
				$type = "web-crawler";
				if ( $row["type"] == 2 ) 
				{
					$type = "database index";
				}
				
				$latest_update = date("d.m.Y H:i:s", $row["updated"]);
				if ( $row["updated"] == 0 ) 
				{
					$latest_update = "n/a";
				}
				
				$wheel = "";
				if ( $row["current_state"] ) 
				{
					# is indexer currently running ?
					$wheel = "<div style='float:left;margin-top:8px;width:20px;height:20px;background:url(\"images/wheel.gif\") center center;'></div>";
				}
				
				echo "<a style='width:100%;clear:both;display:inline-block;' href='control.php?index_id=" . $row["ID"] . "'>
					   <div style='width:23%;float:left;padding:2px 2% 2px 0%;'>" . $row["name"] . "</div>
					   <div style='width:23%;float:left;padding:2px 2% 2px 0%;'>$type</div>
					    <div style='width:23%;float:left;padding:2px 2% 2px 0%;'>" . ($row["documents"]+$row["delta_documents"]) . "</div>
						<div style='width:23%;float:left;padding:2px 2% 2px 0%;'>$latest_update</div>
						$wheel
						</a>";
			}
		}
	?>
    </p>
</div>


<div class='settingsbox'>
    <h3>Create a new index</h3>
    <form action='control.php' method='post' enctype='application/x-www-form-urlencoded'>
    <input type='hidden' name='action' value='createindex' />
	<p>
		Please give your new index an unique name by using small letters ( a-z ) and numbers ( 0-9 ). Max. characters 20.
		<br>
		<input type='text' name='new_index_name' placeholder='Index name' />
	</p>
    <p>
		Index type defines whether the indexed data is collected by provided web-crawler or read directly from a chosen database.
    </p>
	<p>
   		<input type="radio" name="new_index_type" value="1" /> web-crawler
        <br />
        <input type="radio" name="new_index_type" value="2"  /> database index
        <br />
    </p>
    <p>
		<input type='submit' value='Create new index'>
    </p>
    </form>
</div>

<div class='settingsbox'>
    <h3>Management</h3>
    <p>
    <?php
	
	if ( !empty($_GET["show"]) && $_GET["show"] === "uninstall" ) 
	{
		echo "You have chosen to uninstall Pickmybrain.<br>";
		echo "All indexes, indexed data, settings and MySQL-tables will be permanently deleted.<br>";
		echo "This program will quit immediately after deletion.<br>";
		echo "You will have to manually delete the pickmybrain folder.<br>";
		echo "If you do change your mind later, you can reinstall Pickmybrain by opening the web control panel again.<br>";
		echo "<p> &gt; <a href='control.php'>I changed my mind.</a></p>";
		echo "<p> &gt; <a href='control.php?action=uninstallpmb' onClick='return confirm(\"Are you sure you want to uninstall Pickmybrain?\");'>I want to uninstall Pickmybrain</a></p>";
	}
	else
	{
		echo " <p>
				<a href='control.php?action=resetindexer' onClick='return confirm(\"If you believe an indexing process has crashed, you can reset all process indicators with this option. Ongoing indexing processes will not be stopped. Continue?\");'>Reset indexing states</a>
			   </p>
			   <p>
			   	<a href='control.php?show=uninstall'>Uninstall Pickmybrain</a>
			   </p>";
	}
	
	?>
   </p>
   
</div>
</div>
</section>
 <!-- footer
   ================================================== -->
   <footer>

      <div class="row">

         <div class="col g-7">
            <ul class="copyright">
               <li>&copy; 2017 Pickmybrain</li>
               <li>Design by <a href="http://www.styleshout.com/" title="Styleshout">Styleshout</a></li>               
            </ul>
         </div>

         <div class="col g-5 pull-right">
         </div>

      </div>

   </footer> <!-- Footer End-->
  
</body>
</html>

<?php

return;

}

$index_name 	= $pre_existing_indexes[(int)$index_id]["name"];
$index_type 	= $pre_existing_indexes[(int)$index_id]["type"];
$index_suffix 	= "_$index_id";   

if ( $index_type == 1 ) 
{
	#web-crawler index
	$web_visibility = "";
	$db_visibility = "style='display:none;'";
}
else
{
	# database index
	$db_visibility = "";
	$web_visibility = "style='display:none;'";
}

# echo the index name into proprietary div-tag
echo "<div id='pmb-index-name' data-index-name='$index_name'></div>";

$created_tables 			= array();
$data_directory_warning 	= "";
$general_database_errors 	= array();

# create database tables ( if not already created ) 
if ( !create_tables($index_id, $index_type, $created_tables, $data_directory_warning, $general_database_errors, $data_dir_sql) )
{
	++$errors;
}

if ( is_readable("sentiment/pmbsentiment.php") )
{
	include("sentiment/pmbsentiment.php");
		
	if ( class_exists("PMBSentiment") )
	{
		$sentiment_available = true;
	}
	else
	{
		$sentiment_available = false;
	}
}
else
{
	# sentiment analysis enabled, but missing
	$sentiment_available = false;
}
	
# check for errors during table creation
if ( !empty($general_database_errors) ) 
{
	foreach ( $general_database_errors as $error )
	{
		echo "<div class='errorbox'>
				<h3 style='color:#ff0000;'>Error: database table creation failure</h3>
				<p>Following error message was received:</p>
				<p>$error</p>
			  </div>";
	}
	
	echo "<h2>Please resolve the error(s) above to start indexing your website.</h2>";
	return;
}
else if ( !empty($data_directory_warning) ) 
{
	echo "<div class='errorbox'>
				<h3 style='color:#ff9c00;'>Warning: DATA DIRECTORY error</h3>
				<p>The provided data directory ( $mysql_data_dir ) is either unaccessible or invalid. Default data directory was used instead.
					Please make sure that your database has proper permissions for accessing the location.
				</p>
				<p>
					Tips: After opening terminal, issue following commands:<br>
					shell> sudo su -<br>				
					shell> cd /my/custom/location<br>
					shell> chown -R mysql:mysql directoryname<br>
					<br>
					Explanation:<br>
					1. the first command gives you root access<br>
					2. the second command opens your custom data directory location, replace '/my/custom/location' with your own path.<br>
					3. the third command changes the owner of the location to mysql, 'directoryname' being the directory you want save the data to.<br>
					4. if the second or third command fails, you have to of course create the custom location first. Type: mkdir /my/custom/location/directoryname<br>
				</p>
				<p style='color:#ff9c00;'>Error message: $data_directory_warning</p>
			  </div>";
			  
	# reset the settings
	$mysql_data_dir = "";
	$settings_to_write["mysql_data_dir"] = "";
	#echo "<h2>Please resolve the error(s) above to start indexing your website.</h2>";
	#return;
}

if ( !empty($_POST["action"]) && isset($_POST["mysql_data_dir"]) && $_POST["action"] === "modify_mysql_data_dir" && empty($data_directory_warning) ) 
{
	write_settings($settings_to_write, $index_id);
	header("Location: control.php?index_id=$index_id");
	return;
} 

try
{
	$indexing_state = 0;
	$indexing_permission = 0;	
	$doc_count = 0;
	$purge_button = "";
	$delete_button = "";

	$statepdo = $connection->query("SELECT * FROM PMBIndexes WHERE ID = $index_id");
	
	if ( $row = $statepdo->fetch(PDO::FETCH_ASSOC) )
	{
		$doc_count 				= (int)$row["documents"];
		$delta_doc_count		= (int)$row["delta_documents"];
		$indexing_permission 	= (int)$row["indexing_permission"];
		$indexing_state 		= (int)$row["current_state"];
		
		if ( $doc_count && !$indexing_state ) 
		{
			$purge_button = "<input type='submit' value='Purge index' name='purge_index' onClick='return confirm(\"Are you sure you want to empty the whole search index?\");' />";		
		}
		
		if ( !$indexing_state )
		{
			$delete_button = "<input style='float:right;' type='submit' value='Delete index' name='delete_index' onClick='return confirm(\"Are you sure you want to permanently delete the whole search index?\");' />";
		}

		$statistic[5] = $row["temp_loads"];
		$statistic[6] = $row["updated"];
		$statistic[7] = $row["temp_loads_left"];
		$indexing_time = "";
		if ( !empty($row["indexing_started"]) )
		{
			$indexing_time = ", " .  (time() - $row["indexing_started"]) . " seconds elapsed";
		}
		
		$total_documents = $doc_count + $delta_doc_count;
	}
	
	$run_indexer_button = "";
	$test_indexer_button = "";
	$rebuild_dictionary = "";
	
	# indexer is running but has been requested to be stopped 
	if ( !$indexing_permission && $indexing_state === 1 ) 
	{	
		$state = "Terminating indexer...";
	}
	else if ( !$indexing_permission && $indexing_state === 2 ) 
	{	
		$state = "Terminating prefix rebuilder...";
	}
	else if ( !$indexing_permission && $indexing_state === 3 ) 
	{	
		$state = "Aborting index compression...";
	}
	# indexing
	else if ( $indexing_state === 1 ) 
	{
		# indexer is running, show doc count etc
		$state = "Indexing, " . $statistic[5] . " documents processed $indexing_time";
		
		if ( !empty($statistic[7]) )
		{
			$state .= ", at least " . $statistic[7] . " pageloads left";
		}
		
		if ( !empty($statistic[6]) )
		{
			$state .= ", latest update: " . date("d.m.Y H:i:s", $statistic[6]);
		}
		
		$run_indexer_button = "<input type='submit' style='background:#ff0000;color:#fff;' value='Stop indexer' name='stop_indexer' onClick='return confirm(\"Indexer will be stopped. Continue?\");' />";
		
	}
	# rebuilding prefixes
	else if ( $indexing_state === 2 ) 
	{
		# indexer is running, show doc count etc
		$state = "Building prefixes";		
		$run_indexer_button = "<input type='submit' style='background:#ff0000;color:#fff;' value='Stop rebuilder' name='stop_rebuilder' onClick='return confirm(\"Indexer will be stopped. Continue?\");' />";
		
	}
	# compressing index
	else if ( $indexing_state === 3 ) 
	{
		if ( $statistic[5] )
		{
			$done = round(($statistic[7]/$statistic[5])*100);
		}
		else
		{
			$done = 0;
		}
		$state = "Compressing index, $done% done";
		$run_indexer_button = "<input type='submit' style='background:#ff0000;color:#fff;' value='Abort compression' name='stop_indexer' onClick='return confirm(\"Index compression will be stopped. Continue?\");' />";		
	}
	else if ( $indexing_state === 4 ) 
	{
		$state = "Sorting temporary prefix data";
		$run_indexer_button = "<input type='submit' style='background:#ff0000;color:#fff;' value='Abort compression' name='stop_indexer' onClick='return confirm(\"Index compression will be stopped after sorting is done. Continue?\");' />";	
	}
	else if ( $indexing_state === 5 ) 
	{
		$state = "Sorting temporary match data";
		$run_indexer_button = "<input type='submit' style='background:#ff0000;color:#fff;' value='Abort compression' name='stop_indexer' onClick='return confirm(\"Index compression will be stopped after sorting is done. Continue?\");' />";	
	}
	# idle state
	else
	{
		# indexer is not runnin
		$state = "Idle";
		if ( !empty($statistics[6]) )
		{
			$state .= ", last run: " . date("d.m.Y H:i:s", $statistic[6]);
		}
		
		if ( $index_type == 1 )
		{
			$test_indexer_button = "<input type='submit' value='Test index settings' name='test_index' onClick='return confirm(\"Seed URLs will be loaded and functionality will be checked. Continue?\");' />";
		}
		else
		{
			$test_indexer_button = "<input type='submit' value='Test index settings' name='test_index' onClick='return confirm(\"Main SQL query will be tested and functionality will be checked. Continue?\");' />";
		}
		
		
		$run_indexer_button = "<input type='submit' value='Run indexer' name='run_indexer' onClick='return confirm(\"Indexer will be started now, disregarding the indexing interval. Continue?\");' />";
		#$rebuild_dictionary = "<input type='submit' value='Rebuild prefixes' name='rebuild_prefixes' onClick='return confirm(\"Prefix-dictionary will be rebuild. This if necessary only if pre-existing search index&#39;s prefix-setting are modified. Continue?\");' />";
	}
	
	if ( $index_type == 1 )
	{
		$index_type_desc = "web-crawler";
	}
	else
	{
		$index_type_desc = "database index";
	}
	
	?>
    
    <a href="control.php" style="display:inline-block;float:left;clear:both;color:#fff;padding:10px 15px;margin:0 0 40px 0;" class="button"> <i class="icon-angle-left"></i> Back to index selection</a>
    
    <?php
	
	
	echo "<div class='settingsbox'>
			<form action='control.php' method='post' enctype='application/x-www-form-urlencoded'>
			<input type='hidden' name='index_id' value='$index_id' />
			<input type='hidden' name='action' value='runindexer' />
			<div style='width:100%;display:inline-block;position:relative;clear:both:float:left;'>
				<input type='button' style='display:inline-block;position:absolute;right:0;top:0;' value='Search' onClick='TogglePMBSearch();' />
				<div style='display:inline-block;position:absolute;right:15px;top:60px;color:#222;'>CTRL+Q</div>
				<h3>Index name: <span class='green'>$index_name</span> id: <span class='grey'>$index_id</span></h3>
				<h3>Index type: <span class='grey'>$index_type_desc</span></h3>";
				
				if ( $delta_indexing == 1 ) 
				{
					echo "<h3>Total indexed documents: $total_documents (main: $doc_count delta: $delta_doc_count)</h3>";
				}
				else
				{
					echo "<h3>Total indexed documents: $total_documents</h3>";
				}
				
		echo"<h3>Indexer state: $state</h3>
			</div>
			$test_indexer_button
			$run_indexer_button
			$purge_button
			$rebuild_dictionary
			$delete_button
			</form>
		  </div>";

}
catch ( PDOException $e ) 
{
	echo $e->getMessage();
}

# charset
if ( empty($charset) )
{
	# charset cannot be empty: revert to default
	$charset = "";
}

# prefix mode
$prefix_mode_0 = $prefix_mode_1 = $prefix_mode_2 = $prefix_mode_3 = "";
switch ( $prefix_mode ) 
{
	case 1:
	case 2:
	case 3:
	$varname = "prefix_mode_$prefix_mode";
	$$varname = "checked";
	break;
	
	default:
	$prefix_mode_0 = "checked";
	break;
}

$separate_mode_0 = $separate_mode_1 = "";
if ( $separate_alnum )
{
	 $separate_mode_1 = "checked";
}
else
{
	$separate_mode_0 = "checked";
}

$keyword_suggestions_0 = $keyword_suggestions_0 = "";
if ( $keyword_suggestions )
{
	 $keyword_suggestions_1 = "checked";
}
else
{
	$keyword_suggestions_0 = "checked";
}

$stemming_enabled_0 = $stemming_enabled_1 = "";
if ( $keyword_stemming )
{
	 $stemming_enabled_1 = "checked";
}
else
{
	$stemming_enabled_0 = "checked";
}

$quality_scoring_0 = $quality_scoring_1 = "";
if ( $quality_scoring )
{
	 $quality_scoring_1 = "checked";
}
else
{
	$quality_scoring_0 = "checked";
}

$honor_nofollows_0 = $honor_nofollows_1 = "";
if ( !empty($honor_nofollows) )
{
	 $honor_nofollows_1 = "checked";
}
else
{
	$honor_nofollows_0 = "checked";
}

$dialect_processing_0 = $dialect_processing_1 = "";
if ( $dialect_processing )
{
	 $dialect_processing_1 = "checked";
}
else
{
	$dialect_processing_0 = "checked";
}

$dialect_matching_0 = $dialect_matching_1 = "";
if ( $dialect_matching )
{
	 $dialect_matching_1 = "checked";
}
else
{
	$dialect_matching_0 = "checked";
}

$use_localhost_0 = $use_localhost_1 = "";
if ( !empty($use_localhost) )
{
	 $use_localhost_1 = "checked";
}
else
{
	$use_localhost_0 = "checked";
}

$enable_exec_0 = $enable_exec_1 = "";
if ( $exec_available && $enable_exec )
{
	 $enable_exec_1 = "checked";
}
else
{
	$enable_exec_0 = "checked";
}

$index_pdfs_0 = $index_pdfs_1 = "";
if ( !empty($index_pdfs) )
{
	 $index_pdfs_1 = "checked";
}
else
{
	$index_pdfs_0 = "checked";
}

$delta_indexing_0 = $delta_indexing_1 = "";
if ( !empty($delta_indexing) )
{
	 $delta_indexing_1 = "checked";
}
else
{
	$delta_indexing_0 = "checked";
}

$sentiment_analysis_0 = $sentiment_analysis_1 = $sentiment_analysis_2 = $sentiment_analysis_1001 = "";
if ( $sentiment_analysis == 2 && $sentiment_available )
{
	 $sentiment_analysis_2 = "checked";
}
else if ( $sentiment_analysis == 1 && $sentiment_available )
{
	 $sentiment_analysis_1 = "checked";
}
else if ( $sentiment_analysis == 1001 && $sentiment_available )
{
	 $sentiment_analysis_1001 = "checked";
}
else
{
	if ( !$sentiment_available )
	{
		 $sentiment_analysis_1001 = "disabled";
		 $sentiment_analysis_2 = "disabled";
		 $sentiment_analysis_1 = "disabled";
	}
	
	$sentiment_analysis_0 = "checked";
}

$sentiweight_0 = $sentiweight_1 = "";
if ( $sentiweight )
{
	 $sentiweight_1 = "checked";
}
else
{
	$sentiweight_0 = "checked";
}

$allow_subdomains_0 = $allow_subdomains_1 = "";
if ( !empty($allow_subdomains) )
{
	 $allow_subdomains_1 = "checked";
}
else
{
	$allow_subdomains_0 = "checked";
}

$log_queries_0 = $log_queries_1 = "";
if ( $log_queries )
{
	 $log_queries_1 = "checked";
}
else
{
	$log_queries_0 = "checked";
}

$include_original_data_0 = $include_original_data_1 = "";
if ( !empty($include_original_data) )
{
	$include_original_data_1 = "checked";
}
else
{
	$include_original_data_0 = "checked";
}

$use_internal_db_0 = $use_internal_db_1 = "";
if ( !empty($use_internal_db) )
{
	$use_internal_db_1 = "checked";
}
else
{
	$use_internal_db_0 = "checked";
}

$use_buffered_queries_0 = $use_buffered_queries_1 = "";
if ( !empty($use_buffered_queries) )
{
	$use_buffered_queries_1 = "checked";
}
else
{
	$use_buffered_queries_0 = "checked";
}

$html_strip_tags_0 = $html_strip_tags_1 = "";
if ( !empty($html_strip_tags) )
{
	$html_strip_tags_1 = "checked";
}
else
{
	$html_strip_tags_0 = "checked";
}

$row_format_0 = $row_format_1 = $row_format_2 = $row_format_3 = $row_format_4 = $row_format_5 = "";
switch ( $innodb_row_format ) 
{
	case 0:
	case 1:
	case 2:
	case 3:
	case 4:
	case 5:
	$varname = "row_format_$innodb_row_format";
	$$varname = "selected";
	break;
	
	default:
	$row_format_0 = "selected";
	break;
}

?>
<form action="control.php" enctype="application/x-www-form-urlencoded" method="post">
<input type="hidden" name="action" value="updatesettings" />
<input type='hidden' name='index_id' value='<?php echo $index_id; ?>' />
<input type='hidden' name='index_type' value='<?php echo $index_type; ?>' />
<h2>Indexer settings</h2>

<input type="submit" value="Save changes" style="width:100%;background:#e87400;color:#fff;padding:5px 15px;margin:20px 0;display:inline-block;float:left;clear:both;"/>

<div class='settingsbox' <?php echo $web_visibility; ?> >
    <h3>Seed urls</h3>
    <p>
    	The search engine starts indexing web pages from one or multiple pre-defined addresses by collecting and examining every link that these pages point to. 
    	One URL address is required, but it may be necessary to add other urls too if not every section of the website is interlinked. Please define one address per row. 
        Addresses must contain the protocol ( like http:// or https:// ).
    </p>
    <p>
    	Notice: subdomain can be given as a wildcard ( like http://*.mydomain.com ). This will allow all subdomains for this particular domain, even if subdomains are disabled otherwise. 
    </p>
    <p>
    <textarea name='seed_urls' style='width:100%;min-height:300px;' placeholder='Insert seed urls here, one per row' ><?php if ( !empty($seed_urls) ) echo implode("\n", $seed_urls) ?></textarea>
    </p>
   
</div>


<div class='settingsbox' <?php echo $web_visibility; ?> >
	<h3>Allow subdomains</h3>
    <p>
    	Only domains defined as seed urls will be indexed. This setting chooses, whether the indexer is allowed
        to index different subdomains, like subdomain.mydomain.com.
    </p>
    <p>
    	Notice: www.mydomain.com and mydomain.com are considered to be the same thing.
    </p>
     <p>
   		<input type="radio" name="allow_subdomains" value="0" <?php echo $allow_subdomains_0; ?> /> Disabled
        <br />
        <input type="radio" name="allow_subdomains" value="1" <?php echo $allow_subdomains_1; ?> /> Enabled
        <br />
    </p>
</div>

<div class='settingsbox' <?php echo $web_visibility; ?> >
	<h3>Scan depth</h3>
    <p>
    	When indexer discovers links from pages defined as seed urls, it will store and eventually index them as well. This setting controls how deep the link discovery is allowed to go.
    </p>
    <p>
    	Example values: 0 = disabled ( infinite depth ), 1 = only seed urls ( no links will be followed ), 2 = seed urls + pages they link directly to etc.
    </p>
    <p>
   		<input type="text" name="scan_depth" style="width:100px;" value="<?php echo $scan_depth; ?>"/> scan depth
    </p>
</div>

<div class='settingsbox' <?php echo $db_visibility; ?>>
	<h3>Target database</h3>
    <p>
    	This setting decides from which database the data to be indexed is read. If you configured Pickmybrain to use the same database as you have your indexable data in, you can choose the setting "Same as Pickmybrain".
    </p>
    <p>
    	However, if you need to use a different database, please make a copy of the file "ext_db_connection.php" and rename it as "<?php echo "ext_db_connection_" . $index_id . ".php"; ?>" ( for this particular index ). After renaming the copied file, open it and edit the login credentials and settings according to your target database's. <br>Notice: As the database is connected through PHP's PDO abstraction layer, also other databases than MySQL are supported. See all supported databases <a href='http://php.net/manual/en/pdo.drivers.php'>here</a>.
    </p>
     <p>
   		<input type="radio" name="use_internal_db" value="1" <?php echo $use_internal_db_1; ?> /> Same as Pickmybrain
        <br />
        <input type="radio" name="use_internal_db" value="0" <?php echo $use_internal_db_0; ?> /> Different database <span class='cyan'>( defined in "<?php echo "ext_db_connection_" . $index_id . ".php"; ?>" )</span> 
    </p>
</div>

<div class='settingsbox' <?php echo $db_visibility; ?>>
	<h3>Main SQL query</h3>
    <p>
    	This setting essentially decides what is to be indexed. User must provide a valid SQL-query that selects all the required rows from the database. Each column in the SQL query with a string data type will be then tokenized and processed according to predefined settings found from this index definition. Each selected row MUST have an unique identifier with a data type of an unsigned integer. Additionally, this identifier MUST be defined as the first column in the SQL-query.
    </p>
     <p>
   		<textarea name="main_sql_query" placeholder="Main SQL query" style='width:100%;height:500px;'><?php echo $main_sql_query; ?></textarea>
    </p>
    <p>
    	MySQL buffering mode chooses whether the dataset should be kept in the memory of 
        the PHP process or fetched with separate calls from the database server. 
        Unbuffered = lower memory consumption, buffered = faster if database is on another server.
    </p>
    <p>
    	<input type="radio" name="use_buffered_queries" value="0" <?php echo $use_buffered_queries_0; ?> /> Unbuffered queries 
        <br />
        <input type="radio" name="use_buffered_queries" value="1" <?php echo $use_buffered_queries_1; ?> /> Buffered queries 
    </p>
    <p>
    	Choose whether to divide the main sql query into multiple queries that combined return the same amount of documents. 
        This might be essential when fetching documents in the buffered mode, because otherwise the memory consumption might get out of hand. 0 = disabled, >0 is essentially
        the LIMIT parameter for one of the subqueries.
    </p>
    <p>
    	<input type="text" name="ranged_query_value" style="width:100px;" value="<?php echo $ranged_query_value; ?>"/> documents
    </p>
    <p>
    	If you want also to group, sort or filter results by other than their relevancy, you can define some columns in your SQL-query as attributes. 
        The data type of these columns must be unsigned integer. If grouping by a textual column is required, please use an applicable checksum function, like CRC32(text_column).
        <br><br>
        <b>Example:</b> SELECT ID, text, name FROM mytable <b class='cyan'>&nbsp;&gt;&gt;&nbsp;</b>SELECT ID, text, name, CRC32(name) as name_int FROM mytable ( this indexes the original name-field and allows you to sort/filter/group results with the name_int column, which must be defined as an attribute. ) 
        <br>
        <br>
        Please separate the attributes with a line break and make sure, that the names are exact matches of those in the SQL-query.
    </p>
    <p>
    	<textarea name="main_sql_attrs" placeholder="Optional attributes" style='width:50%;height:200px;'><?php print_r(implode("\n", $main_sql_attrs)); ?></textarea>
    </p>
</div>


<div class='settingsbox' >
    <h3>Minimal indexing interval</h3>
    <p>The search index must be updated regularly, if new content gets added into the website/database. 
       Indexer will run automatically every time search is used if previous indexing happened more than [indexing interval value] ago.
       <br /><br />
       <i>
       Notice: by defining indexing interval zero, automatic updating will be disabled and user can choose to run the indexer periodically through CRON or choose to disable 
       automatic indexing altogether.
       Please define the value zero only if you have got access to CRON or are otherwise sure that automatic index updating is not needed.</i>
    </p>
    <p>
    	<b>Indexing interval</b> defines how often the indexer is allowed to run. 
    </p>
    <p><input name='indexing_interval' type='text' value='<?php echo htmlentities($indexing_interval, ENT_QUOTES); ?>' style='width:100px;' /> minutes</p>  
</div>

<div class='settingsbox' <?php echo $web_visibility; ?>>
    <h3>Index through localhost</h3>
    <p>
    	This option chooses whether the data should be loaded directly from the localhost (local server). 
        This is highly beneficial, since unnecessary domain name lookups can be avoided. This works only, if
        Pickmybrain is at the same server as the actual web page that is indexed.
    	In practice this means that every found link is altered in the following manner before loading the actual contents:
    </p>
    <p>
    	http://www.mydomain.com/index.php => http://localhost/index.php<br/>
        http://mydomain.com/index.php => http://localhost/index.php<br/>
        http://subdomain.mydomain.com/index.php => http://subdomain.localhost/index.php<br/>
    </p>
	<p>
    	Notice: Original addresses will be preserved for search results.<br/>
    </p>
    <p>
   		<input type="radio" name="use_localhost" value="0" <?php echo $use_localhost_0; ?> /> Disabled
        <br />
        <input type="radio" name="use_localhost" value="1" <?php echo $use_localhost_1; ?> /> Enabled
        <br />
    </p>
    <p>
    	Custom address can also be defined, if Pickmybrain and the actual web page are on different servers,
        but still on the same local area network. 
    </p>
    <p>
    	<input name='custom_address' type='text' value='<?php echo htmlentities($custom_address, ENT_QUOTES); ?>'  style='width:350px;'  />
    </p>
    <p>
    	Notice #1: if this field is left blank, localhost will be used instead. <br />
        Notice #2: subdomains won't work with this option.
    </p>
</div>

<div class='settingsbox' <?php echo $web_visibility; ?>>
    <h3>Honor nofollow-attributes</h3>
    <p>Choose whether to follow links with nofollow-attribute ( rel=&quot;nofollow&quot; ).
    	<br />
        <br />
        Further filtering and categorization can done at <a href="#selective_indexing">Selective indexing</a> and <a href="#categories">Categories</a>.
    </p>

    <p>
   		<input type="radio" name="honor_nofollows" value="0" <?php echo $honor_nofollows_0; ?> /> Ignore nofollow-attributes
        <br />
        <input type="radio" name="honor_nofollows" value="1" <?php echo $honor_nofollows_1; ?> /> Honor nofollow-attributes
        <br />
    </p>
</div>

<div class='settingsbox' id="selective_indexing" <?php echo $web_visibility; ?>>
    <h3>Selective indexing (optional)</h3>
    <p>User can choose to index only certain web pages by filtering them by their respective URLs. This is done be defining an optional keyword or keywords. Non-wanted keywords can also be defined by adding - ( hyphen ) in front of them.</p>
    <p>
    	 Example, defined keywords <b><i><span class="green">news europe</span> <span class="red">-economy</span></i></b>  
    </p>
    <p>
    	Explanation: Only URLs with keywords news <b>and</b> europe will be indexed. URLs containing word economy will not be indexed in any case.
    </p>
    <p><input name='url_keywords' type='text' value='<?php echo htmlentities($url_keywords, ENT_QUOTES); ?>'  style='width:350px;'  /></p>
</div>

<div class='settingsbox' <?php echo $db_visibility; ?>>
	<h3>HTML/XML preprocessor</h3>
    <p>
    	If you are about to index XML/HTML documents stored into a database, it might be wise to remove the XML markup before
        indexing the actual content. This setting does not alter the contents of individual XML elements in any way.
    </p>
     <p>
   		<input type="radio" name="html_strip_tags" value="0" <?php echo $html_strip_tags_0; ?> /> Disabled
        <br />
        <input type="radio" name="html_strip_tags" value="1" <?php echo $html_strip_tags_1; ?> /> Enabled
        <br />
    </p>
    <p>
    	If you want to remove everything that is wrapped inside some XML elements, you can define them here.
        Please separate multiple elements with commas. Example value: script, style
        <br>
        <input type='text' style='width:300px;' name='html_remove_elements' value='<?php echo $html_remove_elements; ?>'/>
    </p>
    <p>
    	Additionally, some attribute values of elements to be removed can be preserved for indexing.
        Please define one element per line with a list of attributes to be preserved. Example value: img=alt,title,src
        <br>
        <textarea style='width:50%;min-width:250px;height:200px;' name='html_index_attrs'><?php echo implode("\n", $html_index_attrs); ?></textarea>
    </p>
</div>

<div class='settingsbox'>
	<h3>Sentiment analysis</h3>
    <p>
    	Provided by Pickmybrain proprietary algorithms, the sentiment analysis feature analyzes the incoming textual content and enables
        users to search and sort results by polarity of opinions. Notice: correct language must be set.
    </p>
    
    	<?php
		
		$sentiment_availability = "";
		if ( !$sentiment_available ) 
		{
			$sentiment_availability = "(unavailable)";
		}
		
		?>
    
     <p>
   		<input type="radio" name="sentiment_analysis" value="0" <?php echo $sentiment_analysis_0; ?> /> Disabled
        <br />
        <input type="radio" name="sentiment_analysis" value="1" <?php echo $sentiment_analysis_1; ?> /> English <?php echo $sentiment_availability; ?>
        <br />
        <input type="radio" name="sentiment_analysis" value="2" <?php echo $sentiment_analysis_2; ?> /> Finnish <?php echo $sentiment_availability; ?>
        <br />
        <input type="radio" name="sentiment_analysis" value="1001" <?php echo $sentiment_analysis_1001; ?> /> Use external attribute <?php echo $sentiment_availability; ?>
        <br />
    </p>
    <p>
    	<?php
		if ( $index_type == 1 ) 
		{
			echo "NOTICE: if external attribute is selected, pickmybrain will check the language attribute on the html-tag.";
		}
		else
		{
    		echo "NOTICE: if external attribute is selected, a column named pmb_language must be defined in the main sql query. 
			The column must return value 1 for english and value 2 for finnish. In addition, you can define this column as an attribute, if you wish
			to group, sort or filter results by language.";
		}
		?>
    </p>
</div>

<div class='settingsbox' <?php echo $web_visibility; ?>>
	<h3>Index PDF-files</h3>
    <p>
    	Chooses if PDF-files should be indexed. This is feature is provided by a third-party software.
        <br />Notice #1: This option works only with the exec() script execution method.
        <br />Notice #2: Copy protected PDFs will not be indexed.
    </p>
     <p>
   		<input type="radio" name="index_pdfs" value="0" <?php echo $index_pdfs_0; ?> /> Disabled
        <br />
        <input type="radio" name="index_pdfs" value="1" <?php echo $index_pdfs_1; ?> /> Enabled
        <br />
    </p>
</div>

<div class='settingsbox'>
    <h3>Character set</h3>
    <p>Only predefined characters will be kept. Other characters will be ignored. Letters are case-insensitive and if defined, blend chars will be added into the character set as well.</p>
    <p>
    	 Example: Character set <b>0-9a-zöäå#</b> will match all numbers between 0-9, letters between a-z and additional characters of <b>ö, ä, å</b> and <b>#</b>.
    </p>
    <p><input name='charset' type='text' value='<?php echo htmlentities($charset, ENT_QUOTES); ?>'  style='width:350px;'  /></p>
</div>

<div class='settingsbox'>
    <h3>Blend chars</h3>
    <p>
    	Words containing blend chars will be indexed as separate words. The original token will also be preserved.
    	<br />
        Example: If - ( hyphen ) would be defined as blend char, the word <i><b>well-kept</b></i> would be indexed as <i><b>well</b></i>, <i><b>kept</b></i> and <i><b>well-kept</b></i>
    </p>
    <p><input name='blend_chars' type='text' value='<?php echo htmlentities(implode("", $blend_chars), ENT_QUOTES); ?>' /></p>
</div>

<div class='settingsbox'>
    <h3>Ignore chars</h3>
    <p>
    	Ignore chars will be ignored alltogether and removed from the original document.
    	<br />
        Example: If ' ( apostrophe ) would be defined as an ignore char, the word <i><b>Joe's</b></i> would be indexed as <i><b>Joes</b></i>
    </p>
    <p><input name='ignore_chars' type='text' value='<?php echo htmlentities(implode("", $ignore_chars), ENT_QUOTES); ?>' /></p>
</div>

<div class='settingsbox'>
    <h3>Prefixes, Postfixes and Infixes</h3>
    
    	<div style='display:inline-block;float:left;clear:left;width:200px;'>
        <table border="1" cellpadding="1" cellspacing="1">
        <tr>
            <td>Disabled</td>
            <td>Prefixes</td>
            <td>Prefixes&amp;<br />Postfixes</td>
            <td>Infixes</td>
            <td>Min. length</td>
        </tr>
        <?php
		
		echo " <tr>
					<td><input type='radio' name='prefix_mode' value='0' $prefix_mode_0 /></td>
					<td><input type='radio' name='prefix_mode' value='1' $prefix_mode_1 /></td>
					<td><input type='radio' name='prefix_mode' value='2' $prefix_mode_2 /></td>
					<td><input type='radio' name='prefix_mode' value='3' $prefix_mode_3 /></td>
				   <td><input type='input' name='prefix_length' value='$prefix_length' style='width:50px;'/></td>
				</tr>";

		?>
        
        </table>
        </div>
       
    	
    <div style='float:left;clear:left;'>
    	<p>
    	By enabling prefixes, postfixes and/or infixes, each word will be indexed as multiple different tokens as this greatly improves search results. 
        <br />
        For example, the word <i><b>avenues</b></i> with the minumum length of 4 would be indexed as:
        <br />
        Disabled: <i><b>avenues</b></i>
        <br />
        Prefixes: <i><b>aven, avenu, avenue, avenues</b></i>
        <br />
        Prefixes &amp; Postfixes: <i><b>aven, avenu, avenue, avenues, nues, enues, venues</b></i>
        <br />
        Infixes: <i><b>aven, venu, enue, nues, avenu, venue, enues, avenue, venues, avenues</b></i>
        </p>
        <p>
        	Thus the search term <i><b>avenue</b></i> would yield results, but in the disabled mode it would not.
        </p>
    </div>
</div>

<div class='settingsbox'>
    <h3>Dialect processing</h3>
    <p>
    	This feature replaces non-ascii characters with ascii characters that bear the most resemblance to them. 
        Notice: if non-ascii characters are defined in the charset, words will not be altered. 
        However, prefix-words with similar alterations will be created instead.
    </p>
	<p>
    	Examples:<br/>
        r&auml;ikk&ouml;nen => raikkonen<br/>
        Fran&ccedil;ois => Francois<br/>
        Pok&eacute;mon  => Pokemon
    </p>
    <p>
   		<input type="radio" name="dialect_processing" value="0" <?php echo $dialect_processing_0; ?> /> Disabled
        <br />
        <input type="radio" name="dialect_processing" value="1" <?php echo $dialect_processing_1; ?> /> Enabled
        <br />
    </p>
</div>

<div class='settingsbox' <?php echo $web_visibility; ?>>
    <h3>Trim page titles</h3>
    <p>
    	If each web page's title contains a common part, like the domain name, it can be removed with this option as it improves search results.
    	<br />
        Example title: <b>My photo page - mydomain.com</b><br />
        Example trim value: <b>- mydomain.com</b><br />
        Outcome: <b>My photo page</b>
    </p>
    <p>
          <textarea name="trim_page_title" placeholder="Insert one trim value per line" style='width:50%;height:200px;'><?php if ( !empty($trim_page_title) ) print_r(implode("\n", $trim_page_title)); ?></textarea>
    </p>
</div>

<div class='settingsbox'>
    <h3>Separate letters from numbers with space</h3>
    
    	<div style='display:inline-block;float:left;clear:left;width:200px;'>
    	<input type="radio" name="separate_alnum" value="0" <?php echo $separate_mode_0; ?> /> Disabled
        <br />
        <input type="radio" name="separate_alnum" value="1" <?php echo $separate_mode_1; ?> /> Enabled
        <br />
        </div>
	
    <div style='float:left;clear:left;'>
    	<p>
    	Choose whether to separate numbers and letters from each other with a space. This is beneficial when infixing is not enabled and 
        the indexed documents include tokens that have both numbers and letters in them.
        </p>
        <p>
        Example: input <b>Sony KDL42W705B</b>  &nbsp;&nbsp; output <b>Sony KDL 42 W 705 B </b>
        </p>
        <p>Searching with query <b>Sony 705</b> would not yield any results with this feature disabled. However, this feature enabled the query would return results.</p>
    </div>
</div>

<div class='settingsbox'>
    <h3>Synonym definitions</h3>
    
    	<div style='display:inline-block;float:left;clear:left;width:100%;'>
    		<textarea name="synonyms" placeholder="Synonym definitions (optional)" style='width:100%;height:300px;'><?php print_r(implode("\n", $synonyms)); ?></textarea>
        </div>
	
    <div style='float:left;clear:left;'>
    	<p>
    		You can define an optional list of synonyms. Words on the same row are considered to have the same meaning. Gives more results when used.
        </p>
        <p>
        	Example values:<br>
            computer, pc<br>
            color, colour<br>
            theatre, theater<br>
            amazing, incredible, fabulous, wonderful, fantastic<br>
        </p>
    </div>
</div>

<p style='display:inline-block;float:left;clear:both;'>
	<h2 style='width:100%;'>Default search (runtime) settings</h2>
</p>
<p style='display:inline-block;float:left;clear:both;max-width:800px;'>
	These are the default runtime settings, used always except when user decides to provide his/hers own parameters using Pickmybrain API.  
</p>

<div class='settingsbox' >
    <h3>Field weights</h3>
    <p>
    	User can choose not to treat every keyword match equally. 
        Certain columns, such as titles, can be configured to have more weight on the final result than other columns.
    </p>
    <p>
    	<?php

		$non_empty_count = count(array_filter($field_weights));

		if ( $non_empty_count > 0 )
		{
			foreach ( $field_weights as $field_name => $field_weight ) 
			{
				echo "<label><input style='width:60px;min-width:60px;display:inline;' type='text' placeholder='$field_name' name='field_weight_$field_name' value='".$field_weight."'/> $field_name </label>";
			}
		}
		else
		{
			echo "<b>NOTICE: Please define/save your SQL query to set up field weights.</b>";
		}

		?>	
    </p>
    <p>
    	Choose whether to use custom field weights while sorting results by positivity / negativity. 
        This setting has effect only if sentiment analysis is enabled.
    </p>
     <p>
   		<input type="radio" name='sentiweight' value='0' <?php echo $sentiweight_0; ?> /> Disabled
        <br />
        <input type="radio" name='sentiweight' value='1' <?php echo $sentiweight_1; ?> /> Enabled
    </p>
</div>

<div class='settingsbox'>
    <h3>Keyword suggestions</h3>
    <p>
    	Pickmybrain can automatically suggest better search terms, if the provided keywords seem to be mistyped. 
        This feature is based on the double metaphone phonetic algorithm and works best with english language. 
        Operation depends entirely on index specific keywods, but with a certain data set following output is produced: 
    	<br />
        <br />
        Input: <i><b>hellsinki</b></i> &nbsp;&nbsp; Did you mean: <i><b>helsinki</b></i>
        <br />
        Input: <i><b>dynamiclly</b></i> &nbsp;&nbsp; Did you mean: <i><b>dynamically</b></i>
        <br>
        <br>
        Suggestions, if enabled ( and present ) can be found from the result variable $result["did_you_mean"] ( $result = $pmbinstance->Search("misstyped"); )
    </p>
    <p>
   		<input type="radio" name='keyword_suggestions' value='0' <?php echo $keyword_suggestions_0; ?> /> Disabled
        <br />
        <input type="radio" name='keyword_suggestions' value='1' <?php echo $keyword_suggestions_1; ?> /> Enabled 
    </p>
</div>


<div class='settingsbox'>
    <h3>Keyword stemming</h3>
    <p>
    	Whether search terms given by the user are stemmed before they will be matched against the search index.     Example: 
    	<br />
        <br />
        Input: <i><b>Cars</b></i> &nbsp;&nbsp; Output: <i><b>Car</b></i> <i>OR</i> <i><b>Cars</b></i>
    </p>
    <p>
   		<input type="radio" name='keyword_stemming' value='0' <?php echo $stemming_enabled_0; ?> /> Disabled
        <br />
        <input type="radio" name='keyword_stemming' value='1' <?php echo $stemming_enabled_1; ?> /> Enabled
    </p>
</div>

<div class='settingsbox'>
    <h3>Dialect matching</h3>
    <p>
    	This feature removes dialect from user-provided keywords. Either the original keyword or the processed keyword is required to match. 
    </p>
    <p>
    	Example: Input: <i><b>r&auml;ikk&ouml;nen</b></i> &nbsp;&nbsp; Output: <i><b>r&auml;ikk&ouml;nen</b></i> <i>OR</i> <i><b>raikkonen</b></i>
    </p>
    <p>
   		<input type="radio" name='dialect_matching' value='0' <?php echo $dialect_matching_0; ?> /> Disabled
        <br />
        <input type="radio" name='dialect_matching' value='1' <?php echo $dialect_matching_1; ?> /> Enabled
    </p>
</div>

<div class='settingsbox'>
    <h3>Enable prefix match quality scoring</h3>
    <p>
    	If given search term matches prefix, postfix or an infix of another word, this option chooses whether these kind of matches
        will be treated as equal or non-equal to exact matches. If this feature is disabled, each prefix will have a score of 1. Example: 
    </p>
    <p>
    	Provided keyword: <i><b>state</b></i>, length: 5, quality scoring enabled
        <br />
        Match 1: <i><b>state</b></i>, 5/5 = score 1.0
        <br />
        Match 2: <i><b>state</b></i>s, 5/6 = score 0.833
        <br />
        Match 3: <i><b>state</b></i>ment, 5/9 = score 0.555
        <br />
        Match 4: e<i><b>state</b></i>s, 5/7 = score 0.714
    </p>
    <p>
   		<input type="radio" name='quality_scoring' value='0' <?php echo $quality_scoring_0; ?> /> Disabled
        <br />
        <input type="radio" name='quality_scoring' value='1' <?php echo $quality_scoring_1; ?> /> Enabled
    </p>
</div>

<div class='settingsbox'>
    <h3>Prefix/Postfix/Infix Expansion limit</h3>
    <p>Limits the amount of prefixes, postfixes and infixes that the search term can match. Closest results come first.</p>
    <p>
    	Larger value: more results, slower
        <br />
        Smaller value: less results, faster
    </p>
    <p><input name='expansion_limit' type='text' value='<?php echo $expansion_limit; ?>' style="width:60px;" /></p>
</div>

<div class='settingsbox' <?php echo $db_visibility; ?>>
	<h3>Include original data</h3>
    <p>
    	This setting chooses, whether original indexed data is presented with the search results from PMBApi.
    </p>
     <p>
   		<input type="radio" name="include_original_data" value="0" <?php echo $include_original_data_0; ?> /> Disabled
        <br />
        <input type="radio" name="include_original_data" value="1" <?php echo $include_original_data_1; ?> /> Enabled
        <br />
    </p>
</div>

<div class='settingsbox'>
	<h3>Query logging</h3>
    <p>
    	As the name suggests, this feature stores all searches plus additional information such as date, user's ip address, count of returned results, selected search mode and query processing time.
        This data might be crucial for improving your service.
    </p>
     <p>
   		<input type="radio" name="log_queries" value="0" <?php echo $log_queries_0; ?> /> Disabled
        <br />
        <input type="radio" name="log_queries" value="1" <?php echo $log_queries_1; ?> /> Enabled
        <br />
    </p>
</div>

<?php

try
{
	# categories
	$catpdo = $connection->query("SELECT * FROM PMBCategories$index_suffix ORDER BY ID");
}
catch ( PDOException $e ) 
{
	echo $e->getMessage();
}

?>

<h2 <?php echo $web_visibility; ?>>Categories</h2>
<div class='settingsbox' id='categories' <?php echo $web_visibility; ?>>
    <h3>Define categories (optional)</h3>
    <p>
    	Pages can be categorized either by adding a specific HTML attribute in them or by filtering them by their respective URL addresses. 
        Searches can be then limited to these user defined categories only. Each page can have up to three different categories.
    </p>
    <p>
    	<b>Categorizing with attributes:</b><br />
        Create a new element or modify an existing element and add following attributes:<br />
        &lt;div id=&quot;pmb-category&quot; data-pmb-category=&quot;sports,foods,news&quot;&gt; &lt;/div&gt;
        <br />
        The attribute data-pmb-category now contains three user-defined categories: sports, foods and news.
        For these categories to work, they must also be defined below, each as their own category. 
        Set the category types as Attribute. These types of categories are case-insensitive.
    </p>
    <p>
    	<b>Categorizing with URLs:</b><br />
        For to a web page to match a category, user can filter them by giving wanted and non-wanted keywords.<br />
        example keywords: wantedword thistoo -butnotthis<br />
        Set the category type as URL. These types of categories are case-sensitive.
    </p>
    <p>
    <div style='float:left;width:100%;'>
        <div style='inline-block;float:left;width:290px;'>Category keyword(s)</div>
        <div style='inline-block;float:left;width:210px;'>Category description</div>
        <div style='inline-block;float:left;width:80px;'>Type</div>
    </div>
   <?php
   
   $cc = 0;
   # print the categories
   while ( $row = $catpdo->fetch(PDO::FETCH_ASSOC) )
   {
	   # type?
	   if ( $row["type"] == 0 ) 
	   {
		   # attribute
		   $options = "<option value='0' selected>Attribute</option>
						<option value='1'>URL</option>";
	   }
	   else
	   {
		   # URL
		   $options = "<option value='0'>Attribute</option>
						<option value='1' selected>URL</option>";
	   }
	   
	   echo " <div style='float:left;width:100%;padding:5px 0;'>
        <div style='inline-block;float:left;width:290px;'>
       		<input type='text' style='width:280px;min-width:280px;' name='" . $row["ID"] . "_cat_keywords' value='" . $row["keyword"] . "' />
        </div>
        <div style='inline-block;float:left;width:210px;'>
        	<input type='text' style='width:200px;min-width:200px;' name='" . $row["ID"] . "_cat_names' value='" . $row["name"] . "' />
        </div>
		<div style='inline-block;float:left;width:100px;'>
		<select name='" . $row["ID"] . "_cat_types' style='width:100px;min-width:100px;'>
			$options
		</select>
		</div>
		 <div style='inline-block;float:left;width:160px;padding:15px 0 0 15px;'>
        	" . $row["count"] . " matches, ID: " . $row["ID"] . "
        </div>
    	</div>
   	 	";
		
		++$cc;
   }
   
   if ( $cc < 10 ) 
   {
	   # print the remaining empty category slots
	   while ( $cc < 10 ) 
	   {
		   echo " <div style='float:left;width:100%;padding:5px 0;'>
			<div style='inline-block;float:left;width:290px;'>
				<input type='text' style='width:280px;min-width:280px;' name='empty_cat_keywords_$cc' />
			</div>
			<div style='inline-block;float:left;width:210px;'>
				<input type='text' style='width:200px;min-width:200px;' name='empty_cat_names_$cc' />
			</div>
			<div style='inline-block;float:left;width:100px;'>
			<select name='empty_cat_types_$cc' style='width:100px;min-width:100px;'>
				<option value='0'>Attribute</option>
				<option value='1'>URL</option>
			</select>
			</div>
			</div>
			";
		   ++$cc;
	   }
   }
   
  
   ?>
    </p>
   
</div>

<input type="submit" value="Save changes"  style="width:100%;background:#e87400;color:#fff;padding:5px 15px;margin:20px 0;display:inline-block;float:left;clear:both;"/>
<?php

$exec_state = "";
$exec_info = "recommended";
if ( !$exec_available ) 
{
	$exec_state = " disabled ";
	$exec_info = "N/A";
}
?>
<h2>General settings</h2>
<div class='settingsbox' id="execution_method">
    <h3>Script execution method</h3>
    <p>
    	By default script are launched via exec() function resulting in non-blocking background processes. 
        However, if this is not possible, an alternative method can be used instead.
    </p>
    <p>
    	Notice: Indexing PDF-files requires the exec() script execution method.
    </p>
    <p>
        <input type="radio" name='enable_exec' value='1' <?php echo "$enable_exec_1 $exec_state"; ?> /> Use exec() ( <?php echo $exec_info; ?> )
        <br />
   		<input type="radio" name='enable_exec' value='0' <?php echo $enable_exec_0; ?> /> Asynchronous CURL-request 
    </p>
</div>

<div class='settingsbox'>
    <h3>Multiprocessing</h3>
    <p>
    	Pickmybrain can do multiprocessing by launching separate processes and thus divide the workload. 
    </p>
    <p>
    For database indexes, multiprocessing is possible in three different stages: reading data/tokenizing, compressing/sorting keyword hits and compressing/sorting prefixes. 
    For web-crawler indexes, multiprocessing is supported only in the last two stages.
    </p>
    <p>
        <select name='dist_threads'>
        <?php
		
		for ( $i = 1 ; $i <= 16 ; ++$i ) 
		{
			$selected = "";
			if ( $dist_threads == $i ) 
			{
				$selected = "selected";
			}
			
			$desc = "$i processes";
			if ( $i == 1 ) 
			{
				$desc = "$i process (disabled)";
			}
			
			echo "<option value='$i' $selected>$desc</option>";
		}
		
		?>
        </select>
    </p>
</div>

<div class='settingsbox'>
    <h3>Index grow method</h3>
    <p>
    	Pickmybrain supports gradually growing indexes. This means you can add 
		new documents into your existing search index simply by running the indexer again. 
        This settings chooses whether the new documents are merged with the existing data or whether a paraller delta index containing the new data is created.  
    </p>
    <p>
    	<b>Merge-method</b>: Slower indexing performance, best search performance. 
        <br>
        <b>Delta-method</b>: Best indexing performance, somewhat slower search performance ( MySQL 5.7.3 or later recommended for best search performance )
    </p>
    <p>
        <input type="radio" name='delta_indexing' value='0' <?php echo $delta_indexing_0; ?> /> Merge-method
        <br />
   		<input type="radio" name='delta_indexing' value='1' <?php echo $delta_indexing_1; ?> /> Delta-method 
    </p>
    <p><b>Delta merge interval</b></p>
    <p>
    	If enabled, at some point the delta index must be merged into the main index to maintain the indexing speed advantages.
        This can be done automatically at certain point if you define a delta merge interval value. 
        Otherwise the indexes must be merged manually by running the indexer with <i>merge</i> parameter, or else the delta index will keep growing indefinitely.
        <br>0 = disabled, > 0 minutes from the last full indexing / merging.
    </p>
    <p>
    	<input name='delta_merge_interval' type='text' value='<?php echo htmlentities($delta_merge_interval, ENT_QUOTES); ?>' style='width:100px;' /> minutes</p>  
    </p>
</div>

<div class='settingsbox'>
    <h3>InnoDB table row format/compression</h3>
    <p>
    	Depending on your MySQL database configuration, the InnoDB database table containing the keyword data can be fine-tuned by choosing an appropriate file format.
    </p>
    <p>
    	<?php
		
		if ( !$innodb_file_format_barracuda ) 
		{
			echo "NOTICE: Because <i>innodb_file_format</i> is set as <b>$innodb_file_format</b>, some features are unavailable. 
			To gain access to these options, please make sure <i>innodb_file_per_table</i> is <b>on</b> and <i>innodb_file_format</i> is set as <b>Barracuda</b>.";
		}
		
		?>
    </p>
    <p>
    	Recommended setting: Dynamic OR Compressed, it is difficult to say whether using compression yields any actual improvements. If these settings are unavailable, Compact should be used instead.
    </p>
    <p>
        <select name='innodb_row_format'>
       
        <?php

		echo "<option value='0' $row_format_0>Compact</option>
			  <option value='1' $row_format_1>Redundant</option>";
		
		if ( $innodb_file_format_barracuda ) 
		{
			# dynamic and compressed id possible	
			echo "<option value='2' $row_format_2>Dynamic</option>
				  <option value='3' $row_format_3>Compressed (Key Block Size=16)</option>
				  <option value='4' $row_format_4>Compressed (Key Block Size=8)</option>
				  <option value='5' $row_format_5>Compressed (Key Block Size=4)</option>";
		}
		else
		{
			echo "<option value='2' disabled>Dynamic (N/A)</option>
				  <option value='3' disabled>Compressed (Key Block Size=16) (N/A)</option>
				  <option value='4' disabled>Compressed (Key Block Size=8) (N/A)</option>
				  <option value='5' disabled>Compressed (Key Block Size=4) (N/A)</option>";
		}
		
		
		
		?>
        </select>
    </p>
</div>

<div class='settingsbox'>
    <h3>Administrator email</h3>
    <p>
    	If something goes awry, the administrator can choose to receive notifications by defining his/hers email.
    </p>
    <p><input name='admin_email' type='text' value='<?php echo $admin_email; ?>' /></p>
</div>

</form>

<div class='settingsbox'>
    <h3>MySQL Data Directory</h3>
    <p>
    	This setting makes it possible to store the search index ( a group of MySQL InnoDB tables ) into a custom location. 
        Please use this setting only if you really know what you are doing. Example: You have configured your MySQL data directory
        on a HDD disk, but you have also got an SSD available. Therefore you can make the search index faster by storing the data files into the SSD.
    </p>
    <p>
    	Notice: If this setting is modified, the search index will be deleted and re-indexing is required.
    </p>
    <?php 
	if ( $innodb_file_per_table ) 
	{  
		?>
        
         <p>
            <form action='control.php' method='post' enctype='application/x-www-form-urlencoded'>
            <input type='hidden' name='index_id' value='<?php echo $index_id; ?>' />
            <input type='hidden' name='action' value='modify_mysql_data_dir' />
            <input name='mysql_data_dir' type='text' value='<?php echo $mysql_data_dir; ?>' style="width:300px;min-width:300px;clear:none;display:inline;float:left;line-height:30px;" />
            <input type='submit' value='Change index location' style='display:inline;clear:none;float:left;margin-left:10px;' onClick='return confirm("Search index will be deleted and moved to a new location. Continue?");'  />
            </form>
    	</p>
        
        <?php
	}
	else
	{
		?>
        
         <p>
            Unfortunately it seems that your MySQL does not support this feature at this moment. Please set the global variable <b>innodb_file_per_table</b> ON.
    	</p>
        
        <?php
	}
	?>
  
</div>



            

         </div>

         
      </div>

    
   </section> <!-- Container End -->

   <!-- footer
   ================================================== -->
   <footer>

      <div class="row">

         <div class="col g-7">
            <ul class="copyright">
               <li>&copy; 2017 Pickmybrain</li>
               <li>Design by <a href="http://www.styleshout.com/" title="Styleshout">Styleshout</a></li>               
            </ul>
         </div>

         <div class="col g-5 pull-right">
         </div>

      </div>

   </footer> <!-- Footer End-->
   <!-- initialize livesearch -->
   <?php 
  	 echo "<script src='css/init.js'></script>";
   
   	   $sort_select_values = "<option value='@count'>@count</option>\n<option value='@id'>@id</option>\n";
	   $group_attribute_values = "";
	   $group_sort_values = "<option value='@score'>@score</option>\n<option value='@id'>@id</option>\n";
	   
	   if ( $sentiment_analysis ) 
	   {
		   $group_sort_values .= "<option value='@sentiscore'>@sentiscore</option>\n";
	   }
   	   
	   if ( $index_type == 2 ) 
	   {
		   foreach ( $main_sql_attrs as $attribute ) 
		   {
			   $sort_select_values .= "<option value='$attribute'>$attribute</option>\n";
			   $group_attribute_values .= "<option value='$attribute'>$attribute</option>\n";
			   $group_sort_values .= "<option value='$attribute'>$attribute</option>\n";
		   }
	   }
	   else
	   {
		   $sort_select_values .= "<option value='domain'>domain</option>\n
		   						   <option value='timestamp'>timestamp</option>
								   <option value='category'>category</option>\n";
								   
		   $group_attribute_values .= "<option value='domain'>domain</option>\n
		   						   <option value='timestamp'>timestamp</option>
								   <option value='category'>category</option>\n";
								   
								   
		   $group_sort_values .= "<option value='domain'>domain</option>\n
		   						   <option value='timestamp'>timestamp</option>
								   <option value='category'>category</option>\n";
	   }
	   
	   $not_available = "";
	   $extra_info = "";
	   $disabled = "";
	   if ( !$sentiment_available )
	   {
		   $not_available = "color:#bbb;";
		   $extra_info = "<a href='http://www.pickmybra.in' target='_blank'>buy now</a>";
		   $disabled = "disabled";
	   }
	   else if ( !$sentiment_analysis )
	   {
		    $not_available = "color:#bbb;";
			$extra_info = "unavailable";
			$disabled = "disabled";
	   }
   
    ?>
   
 
   
   <div id='pmblivesearch' class='hidden'>
       
        <form id='pmblivesearchform'>
    		<input type='hidden' name='index_name' id='index_name' value='<?php echo $index_name; ?>'>
            <input type='text' placeholder='What are we looking for?' id='pmblivesearchinput' onkeyup='pmbsearch(this.value, event)' />
            <div class='sbutton' name='sort' onclick='searchOptions(this.innerHTML)'>sort</div>
            <div class='sbutton' name='group' onclick='searchOptions(this.innerHTML)'>group</div>
            <div class='sbutton' name='mode' onclick='searchOptions(this.innerHTML)'>mode</div>
            <div class='searchoptions' id='sort'>
            	<input type='radio' name='sort' value='1' checked onclick='pmbsearch(document.getElementById("pmblivesearchinput").value, event)'> PMB_SORTBY_RELEVANCE <br>
                <input <?php echo $disabled; ?> type='radio' name='sort' value='2' onclick='pmbsearch(document.getElementById("pmblivesearchinput").value, event)'> <span style=' <?php echo $not_available; ?>'>PMB_SORTBY_POSITIVITY </span> <?php echo $extra_info; ?><br>
                <input <?php echo $disabled; ?> type='radio' name='sort' value='3' onclick='pmbsearch(document.getElementById("pmblivesearchinput").value, event)'> <span style=' <?php echo $not_available; ?>'>PMB_SORTBY_NEGATIVITY </span> <?php echo $extra_info; ?><br>
                <input type='radio' name='sort' value='4' onclick='pmbsearch(document.getElementById("pmblivesearchinput").value, event)'> PMB_SORTBY_ATTR <br>
                <select name='sort_attribute' onchange='pmbsearch(document.getElementById("pmblivesearchinput").value, event)'>
                	<option value=''>Attribute</option>
                    <?php echo $sort_select_values; ?>
                </select>
                <select name='sort_direction' onchange='pmbsearch(document.getElementById("pmblivesearchinput").value, event)'>
                	<option value='desc'>DESCENDING</option>
                    <option value='asc'>ASCENDING</option>
                </select>
            </div>
            <div class='searchoptions' id='group'>
            	<input type='radio' name='groupmode' value='1' checked onclick='pmbsearch(document.getElementById("pmblivesearchinput").value, event)'> PMB_GROUPBY_DISABLED <br>
                <input type='radio' name='groupmode' value='2' onclick='pmbsearch(document.getElementById("pmblivesearchinput").value, event)'> PMB_GROUPBY_ATTR <br>
                 <select name='group_attribute' onchange='pmbsearch(document.getElementById("pmblivesearchinput").value, event)'>
                	<option value=''>Grouping attribute</option>
                    <?php echo $group_attribute_values; ?>
                </select>
                <select name='group_sort_attribute' onchange='pmbsearch(document.getElementById("pmblivesearchinput").value, event)'>
                	<option value=''>Groupsort attribute</option>
                    <?php echo $group_sort_values; ?>
                </select>
                <select name='group_sort_direction' onchange='pmbsearch(document.getElementById("pmblivesearchinput").value, event)'>
                	<option value='desc'>DESC</option>
                    <option value='asc'>ASC</option>
                </select>
            </div>
            <div class='searchoptions' id='mode'>
                <input type='radio' name='matchmode' value='1' onclick='pmbsearch(document.getElementById("pmblivesearchinput").value, event)'> PMB_MATCH_ANY <br>
                <input type='radio' name='matchmode' value='2' checked onclick='pmbsearch(document.getElementById("pmblivesearchinput").value, event)'> PMB_MATCH_ALL <br>
                <input type='radio' name='matchmode' value='3' onclick='pmbsearch(document.getElementById("pmblivesearchinput").value, event)'> PMB_MATCH_STRICT <br>
            </div>
        </form>
        <span id='pmbresultarea' data-checksum=''></span>
    </div>
		
   
   
   
   
</body>

</html>