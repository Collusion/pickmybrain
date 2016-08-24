<?php

/* Copyright (C) 2016 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@pickmybra.in
 * or visit: http://www.pickmybra.in
 */

if ( $dist_threads > 1 ) 
{
	# if this is the master process, wait for the other processed to complete
	if ( $process_number === 0 ) 
	{
		# create a list of process pid files
		$directory = realpath(dirname(__FILE__));
		$pidlist = array();
		for ( $i = 1 ; $i < $dist_threads ; ++$i ) 
		{
			$pidlist[$directory . "/pmb_".$index_id."_".$i.".pid"] = $i;
		}
		
		$interval = microtime(true) - $timer;
		echo "Main process finished, $interval seconds elapsed, waiting for sister processes \n";

		while ( true ) 
		{
			$pdo = $connection->query("SELECT indexing_permission FROM PMBIndexes WHERE ID = $index_id");
			$row = $pdo->fetch(PDO::FETCH_ASSOC);
			
			foreach ( $pidlist as $pidfile => $pr_num )
			{
				if ( !file_exists($pidfile) ) 
				{
					unset($pidlist[$pidfile]);
				}
			}
			
			# processes have stopped
			if ( empty($pidlist) )
			{
				break;
			}

			if ( $row["indexing_permission"] == 0 ) 
			{
				SetIndexingState(0, $index_id);
				die("Indexing was requested to be terminated.\n");
			}
			
			# otherwise, wait
			sleep(1);
		}
	}
	else
	{
		# set process state off
		SetProcessState($index_id, $process_number, 0);	
		die();
	}
}