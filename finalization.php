<?php

/* Copyright (C) 2016 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@pickmybra.in
 * or visit: http://www.pickmybra.in
 */

try
{
	$latest_rotation_sql = ",latest_rotation = UNIX_TIMESTAMP()";
	$delta_doc_count_sql = "";
	$main_max_doc_id_sql = "";
	
	if ( !empty($replace_index) )
	{
		$drop_start = microtime(true);
		$connection->beginTransaction();
		# delete old docinfo table and replace it with the new one
		$connection->query("DROP TABLE PMBDocinfo$index_suffix");
		$connection->query("ALTER TABLE $docinfo_target_table RENAME TO PMBDocinfo$index_suffix");
		# delete old docinfo table and replace it with the new one
		$connection->query("DROP TABLE PMBTokens$index_suffix");
		$connection->query("ALTER TABLE PMBTokens".$index_suffix."_temp RENAME TO PMBTokens$index_suffix");
		# delete old docinfo table and replace it with the new one
		$connection->query("DROP TABLE PMBPrefixes$index_suffix");
		$connection->query("ALTER TABLE PMBPrefixes".$index_suffix."_temp RENAME TO PMBPrefixes$index_suffix");
		
		$connection->query("UPDATE PMBIndexes SET documents = ( SELECT COUNT(ID) FROM PMBDocinfo$index_suffix ) WHERE ID = $index_id");
		$connection->commit();
		$drop_end = microtime(true) - $drop_start;
	}
	else if ( !empty($delta_indexing) && !$clean_slate )
	{
		$connection->beginTransaction();
		$connection->query("DROP TABLE IF EXISTS PMBTokens".$index_suffix."_delta");
		$connection->query("ALTER TABLE PMBTokens".$index_suffix."_temp RENAME TO PMBTokens".$index_suffix."_delta");
		$connection->commit();
		
		$connection->beginTransaction();
		$connection->query("DROP TABLE IF EXISTS PMBPrefixes".$index_suffix."_delta");
		$connection->query("ALTER TABLE PMBPrefixes".$index_suffix."_temp RENAME TO PMBPrefixes".$index_suffix."_delta");
		$connection->commit();
		
		# add documents from the PMBDocinfo_id_delta to the main table
		$connection->query("DELETE FROM PMBDocinfo".$index_suffix." WHERE ID > (SELECT max_id FROM PMBIndexes WHERE ID = $index_id)");
		$connection->query("INSERT INTO PMBDocinfo".$index_suffix." SELECT * FROM PMBDocinfo".$index_suffix."_delta");
		$connection->query("DROP TABLE PMBDocinfo".$index_suffix."_delta");
		
		$latest_rotation_sql = "";
	}
	
	if ( empty($delta_indexing) )
	{
		$connection->query("DROP TABLE IF EXISTS PMBTokens".$index_suffix."_delta");
		$connection->query("DROP TABLE IF EXISTS PMBPrefixes".$index_suffix."_delta");
		$connection->query("DROP TABLE IF EXISTS PMBDocinfo".$index_suffix."_delta");
		$delta_doc_count_sql	 = ",delta_documents = 0";
	}
	
	if ( empty($delta_indexing) || (!empty($delta_indexing) && $clean_slate) )
	{
		$main_max_doc_id_sql = ",max_id = ( SELECT MAX(ID) FROM PMBDocinfo$index_suffix )";
	}

	$connection->query("UPDATE PMBIndexes SET 
						current_state = 0, 
						temp_loads = 0, 
						temp_loads_left = 0
						$latest_rotation_sql
						$delta_doc_count_sql
						$main_max_doc_id_sql
						WHERE ID = $index_id ");
}
catch ( PDOException $e ) 
{
	$connection->rollBack();
	echo "An error occurred when switching tables : " . $e->getMessage() . "\n";
}

?>