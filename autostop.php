<?php

/* Copyright (C) 2017 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@gmail.com
 * or visit: http://www.hollilla.com/pickmybrain
 */

# check size of indexed 
$total_size = 0;
if ( $enable_exec && $enable_ext_sorting )
{
	for ( $i = 0 ; $i < $dist_threads ; ++$i ) 
	{
		$filename = $directory . "/datatemp_".$index_id."_".$i.".txt";
		if ( is_readable($filename) )
		{
			$total_size += filesize($filename);
		}
	}
}
else
{
	try
	{
		$autopdo = $connection->query("SELECT COUNT(checksum) FROM PMBdatatemp$index_suffix");
		$total_size += $autopdo->fetchColumn();
	}
	catch ( PDOException $e ) 
	{
		try
		{
			$autopdo = $connection->query("SELECT COUNT(checksum) FROM PMBdatatemp$index_suffix");
			$total_size += $autopdo->fetchColumn();
		}
		catch ( PDOException $e ) 
		{
			echo "Something went wrong: " . $e->getMessage() . "\n";
		}
	}
}

if ( $total_size == 0 ) 
{
	# if no elements detected, end the indexing process
	SetIndexingState(0, $index_id);
	SetProcessState($index_id, $process_number, 0);	
	sleep(1);
	
	echo "No new data to index!\n";
	if ( !empty($timer) )
	{
		$timer_end = microtime(true) - $timer;
		echo "The whole operation took $timer_end seconds \n";
	}
	echo "Memory usage : " . memory_get_usage()/1024/1024 . " MB\n";
	echo "Memory usage (peak) : " . memory_get_peak_usage()/1024/1024 . " MB\n";
	die("Quitting now. Bye!\n");
}

?>