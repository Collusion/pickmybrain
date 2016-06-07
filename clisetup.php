<?php

/* Copyright (C) 2016 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@pickmybra.in
 * or visit: http://www.pickmybra.in
 */

require_once("tokenizer_functions.php");
require_once("db_connection.php");
$connection = db_connection();

if ( is_string($connection) )
{
	echo "Could not establish database connection.\n";
	echo "Please review your settings in db_connection.php\n";
	echo "Additionally, a following error message was received:\n$connection \n";
	return;
}

try
{
	if ( !$connection->query("SHOW TABLES LIKE 'PMBIndexes'")->rowCount() )
	{
		# if the indexes table does not exists, create it now
		$connection->query("CREATE TABLE PMBIndexes (
			 ID mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
			 name varbinary(20) NOT NULL,
			 type tinyint(3) unsigned NOT NULL,
			 comment varbinary(255) NOT NULL,
			 documents int(10) unsigned NOT NULL DEFAULT '0',
			 updated int(10) unsigned NOT NULL,
			 indexing_permission tinyint(3) unsigned NOT NULL,
			 indexing_started int(10) unsigned NOT NULL,
			 current_state tinyint(3) unsigned NOT NULL,
			 running_processes smallint(5) unsigned NOT NULL,
			 temp_loads int(8) unsigned NOT NULL,
			 temp_loads_left int(8) unsigned NOT NULL,
			 PRIMARY KEY (ID,type,documents,current_state,updated,indexing_permission),
			 UNIQUE KEY name (name),
			 UNIQUE KEY ID (ID)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8");
	}
	
}
catch ( PDOException $e ) 
{
	echo "Something went wrong while creating the PMBIndexes table:\n";
	echo $e->getMessage() . "\n";
	return;
}

$folderpath = realpath(dirname(__FILE__));
#$settings_file_path = $folderpath . "/settings.php";

$main_menu = "\n";
$main_menu .= "Pickmybrain command-line configuration tool\n";
$main_menu .= "-------------------------------------------\n";
$main_menu .= "This tool enables you to create, modify and delete existing search indexes.\n\n";
$main_menu .= "1. Show existing indexes\n";
$main_menu .= "2. Create a new index\n";
$main_menu .= "3. Compile index settings\n";
$main_menu .= "4. Purge index\n";
$main_menu .= "5. Delete index\n\n";
$main_menu .= "0. Exit\n";
$main_menu .= "Input: ";

$fp = fopen('php://stdin', 'r');
$last_line = false;
$message = '';
while ( true ) 
{
	echo $main_menu;
	
    $input = fgets($fp, 1024); // read the special file to get the user input from keyboard
	$input = str_replace("\n", "", $input);
	switch ( $input ) 
	{
		case "1":
		# print all existing indexes
		try
		{
			echo "\n";
			printf('%4s %20s %12s %11s %20s', "[id]", "[name]", "[type]", "[documents]", "[latest update]");
			echo "\n";
			
			$count = 0;
			$pdo = $connection->query("SELECT * FROM PMBIndexes ORDER BY ID");
			while ( $row = $pdo->fetch(PDO::FETCH_ASSOC) )
			{
				if ( $row["type"] == 1 )
				{
					$type = "web-crawler";
				}
				else if ( $row["type"] == 2 ) 
				{
					$type = "database";
				}
				
				$latest_update = date("d.m.Y H:i:s", $row["updated"]);
				if ( $row["updated"] == 0 ) 
				{
					$latest_update = "n/a";
				}
				printf('%4d %20s %12s %11d %20s', (int)$row["ID"], $row["name"], $type, (int)$row["documents"], $latest_update);
				echo "\n";
				++$count;
			}
			
			if ( $count === 0 ) 
			{
				echo "No existing indexes\n";
			}	
		}
		catch ( PDOException $e ) 
		{
			echo "An error occurred: " . $e->getMessage() . "\n";
		}
		
		echo "\nPress Enter to continue... ";
		$subinput = fgets($fp, 1024);
		
		break;
		
		# create a new index
		case "2":
		echo "\nYou have chosen to create a new index. Type 0 to cancel.\n";
		echo "An unique name is required.\n";
		echo "Allowed characters: small letters and numbers.\n";
		echo "Maximum length: 20 characters.\n";
		
		try
		{
			while ( true ) 
			{
				echo "name: ";
				$name = str_replace("\n", "", fgets($fp, 1024));
				
				$name = trim($name);
				$len = strlen($name);
				preg_match("/[^a-z0-9]/", $name, $preg_m);

				if ( $len > 0 && $len <= 20 && empty($preg_m) ) 
				{
					$namepdo = $connection->prepare("SELECT ID FROM PMBIndexes WHERE name = ?");
					$namepdo->execute(array($name));
					
					if ( !$namepdo->fetch(PDO::FETCH_ASSOC) )
					{
						# we done here ! 
						break;
					}
					else
					{
						# already exists
						echo "Index by the name $name already exists!\n";
					}
				}
				else if ( $name == "0" ) 
				{
					# user wants to abort
					echo "Index creation was cancelled.\n";
					break;
				}
				else
				{
					echo "Invalid name!\n";
				}
			}
			
			if ( $name != "0" )
			{
				$type = "";
				echo "\nPlease define the index type.\n1 = web crawler\n2 = database index\n0 = cancel\n";
				while ( $type != "1" && $type != "2" && $type != "0" )
				{
					echo "type: ";
					$type = str_replace("\n", "", fgets($fp, 1024));
				}
				
				if ( $type != "0" )
				{
					$indpdo = $connection->prepare("INSERT INTO PMBIndexes (name, type, updated) VALUES(?, ?, UNIX_TIMESTAMP())");
					$indpdo->execute(array($name, $type));
					$last_id = $connection->lastInsertId();
						
					$dummy = array("index_type" => $type);
					# create the settings file with default values for current index_id
					if ( $type == 1 ) 
					{
						$dummy = $dummy + array("data_columns" => "title,content,url,meta");
					}
					
					# write the settings file
					if ( write_settings($dummy, $last_id) ) 
					{
						if ( create_tables($last_id, $type) )
						{
							echo "\nThe index $name was created successfully with id $last_id\n";
							echo "Please modify the file settings_".$last_id.".txt to customize your index settings.\n";
							if ( $type == 2 ) 
							{
								echo "Please run the 'Compile index settings' option from the main menu\nafter you have finished configuring the index.\n";
							}
							$filename = $folderpath."/settings_".$last_id.".txt";
							chmod($filename, fileperms($filename) | 128 + 16 + 2);
						}
						else
						{
							echo "Something went wrong while creating the database tables.\n";
							echo "Please check you have proper permissions.\n";
						}
					}
					else
					{
						echo "Something went wrong while writing the settings file.\n";
						echo "Please check you have proper permissions.\n";
					}
				}
				else
				{
					echo "Index creation was cancelled.\n";
				}
			}
			else
			{
				echo "Index creation was cancelled.\n";
			}
		}
		catch ( PDOException $e ) 
		{
			echo "An error occurred: " . $e->getMessage() . "\n";
		}
	
		echo "\nPress Enter to continue... ";
		$subinput = fgets($fp, 1024);
		
		break;
		
		case "3":
		echo "You have chosen to compile index settings.\n";
		echo "Type y to compile all settings files.\n";
		echo "Give an index id to compile settings for certain index only.\n";
		echo "Type 0 to cancel.\n";
		echo "input: ";
		
		$id = strtolower(str_replace("\n", "", fgets($fp, 1024)));
		if ( ($id == "y" || is_numeric($id)) && $id != "0" )
		{
			$sql_selector = "";
			if ( is_numeric($id) )
			{
				$sql_selector = " WHERE ID = $id";
			}
			
			try
			{
				$count = 0;
				$pdo = $connection->query("SELECT ID, name, type FROM PMBIndexes $sql_selector ORDER BY ID");
				while ( $row = $pdo->fetch(PDO::FETCH_ASSOC) )
				{
					echo "Starting to compile settings for index " . $row["name"] . " id:".$row["ID"]."\n";
					$filename = "settings_".$row["ID"].".txt";
					
					$dummy = array("index_type" => $row["type"], 
								   "action" => "check_db_settings");
					# create the settings file with default values for current index_id
					if ( $row["type"] == 1 ) 
					{
						$dummy = $dummy + array("data_columns" => "title,content,url,meta");
					}
						
					if ( write_settings($dummy, $row["ID"]) ) 
					{
						echo "Settings file was compiled successfully for index id:".$row["ID"]."\n";
						$filename = $folderpath."/settings_".$row["ID"].".txt";
						chmod($filename, fileperms($filename) | 128 + 16 + 2);
					}
					else
					{
						echo "An error occurred when compiling file $filename for index ".$row["name"]." id:".$row["ID"]."\n";
					}
					
					echo "\n";
					
					++$count;
				}
				
				if ( $count === 0 ) 
				{
					if ( is_numeric($id) )
					{
						echo "Unknown index id!\n";
					}
					else
					{
						echo "No existing indexes - nothing was compiled.\n";
					}
				}
			}
			catch ( PDOException $e ) 
			{
				echo "An error occurred: " . $e->getMessage() . "\n";
			}
		}
		else
		{
			echo "Settings compilation was cancelled.\n";
		}
		
		echo "\nPress Enter to continue... ";
		$subinput = fgets($fp, 1024);
		
		break;
		
		case "4":
		echo "You have chosen to purge an index.\n";
		echo "Indexed data will be deleted, but the settings will be kept.\n";
		echo "To continue, please give the index id. To cancel, please type 0.\n";
		echo "id: ";
		$id = str_replace("\n", "", fgets($fp, 1024));
		
		if ( $id != "0" ) 
		{
			try
			{
				$checkpdo = $connection->prepare("SELECT ID FROM PMBIndexes WHERE ID = ?");
				$checkpdo->execute(array($id));
				
				if ( $row = $checkpdo->fetch(PDO::FETCH_ASSOC) )
				{
					$index_id = (int)$row["ID"];
					$index_suffix = "_" . $index_id;
					echo "The index will purged.\n";
					echo "Are you sure? (y/n): ";
					$confirm = strtolower(str_replace("\n", "", fgets($fp, 1024)));
					
					if ( $confirm == "y" )
					{
						$connection->beginTransaction();		
						$connection->query("UPDATE PMBIndexes SET 
											documents = 0,
											current_state = 0,
											indexing_permission = 0,
											indexing_started = 0,
											updated = 0,
											temp_loads = 0,
											temp_loads_left = 0
											WHERE ID = $index_id");

						# delete the index
						$connection->exec("SET FOREIGN_KEY_CHECKS=0;
											TRUNCATE TABLE PMBDocinfo$index_suffix;
											TRUNCATE TABLE PMBPrefixes$index_suffix;
											TRUNCATE TABLE PMBTokens$index_suffix;
											TRUNCATE TABLE PMBMatches$index_suffix;
											TRUNCATE TABLE PMBDocMatches$index_suffix;
											SET FOREIGN_KEY_CHECKS=1;");
											
						$connection->query("UPDATE PMBCategories$index_suffix SET count = 0");
						
						# include the settings file
						
						# delete temporary tables
						
						$sql = "DROP TABLE IF EXISTS PMBdatatemp_$index_id;
								DROP TABLE IF EXISTS PMBpretemp_$index_id;
								DROP TABLE IF EXISTS PMBtoktemp_$index_id;";
						
						for ( $i = 1 ; $i < 16 ; ++$i ) 
						{
							$sql .= "DROP TABLE IF EXISTS PMBtemporary_" . $index_id . "_" . $i . ";";
						}
							
						$connection->exec($sql);
						$connection->commit();
						
						echo "Index was purged succesfully.\n";
						
					}
					else
					{
						echo "Index purging was cancelled.\n";
					}
				}
				else
				{
					echo "Index with an id $id does not exist.\n";
				}
			}
			catch ( PDOException $e ) 
			{
				echo "An error occurred: " . $e->getMessage() . "\n";
			}
		}
		else
		{
			echo "Index purging was cancelled.\n";
		}
		
		echo "\nPress Enter to continue... ";
		$subinput = fgets($fp, 1024);
		
		break;
		
		case "5":
		echo "You have chosen to delete an index.\n";
		echo "All data will be permanently deleted.\n";
		echo "To continue, please give the index id.\n";
		echo "To cancel, please type 0.\n";
		echo "id: ";
		$id = str_replace("\n", "", fgets($fp, 1024));
		
		if ( $id != "0" ) 
		{
			try
			{
				$checkpdo = $connection->prepare("SELECT ID FROM PMBIndexes WHERE ID = ?");
				$checkpdo->execute(array($id));
				
				if ( $row = $checkpdo->fetch(PDO::FETCH_ASSOC) )
				{
					$index_id = (int)$row["ID"];
					$index_suffix = "_" . $index_id;
					echo "The index will be deleted permanently.\n";
					echo "Are you sure? (y/n): ";
					$confirm = strtolower(str_replace("\n", "", fgets($fp, 1024)));
					
					if ( $confirm == "y" )
					{
						$sql = "SET FOREIGN_KEY_CHECKS=0;";			
						for ( $i = 1 ; $i < 16 ; ++$i ) 
						{
							$sql .= "DROP TABLE IF EXISTS PMBtemporary_" . $index_id . "_" . $i . ";";
						}
						$sql .= "DROP TABLE IF EXISTS PMBDocinfo$index_suffix;
								DROP TABLE IF EXISTS PMBPrefixes$index_suffix;
								DROP TABLE IF EXISTS PMBTokens$index_suffix;
								DROP TABLE IF EXISTS PMBMatches$index_suffix;
								DROP TABLE IF EXISTS PMBDocMatches$index_suffix;
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
						
						echo "Index was deleted succesfully.\n";
						
					}
					else
					{
						echo "Index deletion was cancelled.\n";
					}
				}
				else
				{
					echo "Index with an id $id does not exist.\n";
				}
			}
			catch ( PDOException $e ) 
			{
				echo "An error occurred: " . $e->getMessage() . "\n";
			}
		}
		else
		{
			echo "Index deletion was cancelled.\n";
		}
		
		echo "\nPress Enter to continue... ";
		$subinput = fgets($fp, 1024);
		
		break;
		
		case "0":
		echo "\nBye!\n";
		return;
		break;
		
		default:
		echo "Unrecognized command \n";
		break;
	}

}

return;
?>