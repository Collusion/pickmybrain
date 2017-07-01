<?php

/* Copyright (C) 2017 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@gmail.com
 * or visit: http://www.hollilla.com/pickmybrain
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

# check that runtime environment is 64bir
if ( PHP_INT_SIZE !== 8 )
{
	echo "Pickmybrain requires a 64-bit PHP runtime environment.\n";
	echo "Please update your PHP to continue.\n";
	return;
}

# check php version
if ( version_compare(PHP_VERSION, '5.3.0') < 0) 
{
    echo "PHP version >= 5.3.0 required, " . PHP_VERSION . " detected.\n";
	echo "Please update your PHP to continue.\n";
	return;
}

if ( !checkPMBIndexes() )
{
	echo "Something went wrong while creating the PMBIndexes table.\n";
}

$folderpath = realpath(dirname(__FILE__));

$main_menu = "\n";
$main_menu .= "Pickmybrain command-line configuration tool\n";
$main_menu .= "-------------------------------------------\n";
$main_menu .= "This tool enables you to create, modify and delete existing search indexes.\n\n";
$main_menu .= "1. Show existing indexes\n";
$main_menu .= "2. Create a new index\n";
$main_menu .= "3. Compile index settings\n";
$main_menu .= "4. Purge index\n";
$main_menu .= "5. Delete index\n";
$main_menu .= "6. Reset indexing states\n";
$main_menu .= "7. Uninstall Pickmybrain\n\n";
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
						if ( deleteIndex($index_id) )
						{
							echo "Index was deleted succesfully.\n";
						}
						else
						{
							echo "Something went wrong while deleting the index.\n";
						}
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
		
		case "6":
		echo "You have chosen to reset indexing states.\n";
		echo "If you believe an indexing process has crashed, this option resets all process indicators.\n";
		echo "This in turn will make it possible to run the indexer again.\n";
		echo "Ongoing indexing processes will not be stopped.\n";
		echo "Continue? (y/n)";
		$id = str_replace("\n", "", fgets($fp, 1024));
		
		if ( $id == "Y" || $id == "y" ) 
		{
			try
			{
				$connection->query("UPDATE PMBIndexes SET current_state = 0");
				echo "Indexing states were resetted successfully.";
			}
			catch ( PDOException $e ) 
			{
				echo "Something went wrong while resetting indexing states: " . $e->getMessage() . "\n";
			}
		}
		else
		{
			echo "Resetting indexing states was aborted";
		}
		
		echo "\nPress Enter to continue... ";
		$subinput = fgets($fp, 1024);
		
		break;
		
		case "7":
		echo "You have chosen to uninstall Pickmybrain.\n";
		echo "All indexes, indexed data, settings and MySQL-tables will be permanently deleted.\n";
		echo "This program will quit immediately after deletion.\n";
		echo "You will have to manually delete the pickmybrain folder.\n";
		echo "If you do change your mind later, you can reinstall Pickmybrain by running this file again.\n";
		echo "To continue, please type y.\n";
		echo "To cancel, please type 0.\n";
		$id = str_replace("\n", "", fgets($fp, 1024));
		
		if ( $id == "y" || $id == "Y" ) 
		{
			echo "Are you sure? (y/n): ";
			$confirm = strtolower(str_replace("\n", "", fgets($fp, 1024)));
					
			if ( $confirm == "y" || $confirm == "Y" )
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
					
					die("Thanks for using Pickmybrain!\n");
				}
				catch ( PDOException $e ) 
				{
					echo "An error occurred during uninstallation: " . $e->getMessage() . "\n";
				}	
			}
			else
			{
				echo "Uninstallation was cancelled.\n";
			}
		}
		else
		{
			echo "Uninstallation was cancelled.\n";
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