<?php

/* Copyright (C) 2016 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@pickmybra.in
 * or visit: http://www.pickmybra.in
 */

if ( $process_number === 0 )
{
	#echo "filepointer: \n";
	#var_dump($f);
	
	#sleep(10);
	
	/* DATA PORTITION SEEKER */
	$data_size = filesize($filepath);
	$data_portition = (int)($data_size/$dist_threads);
	
	$data_end = false;
	$data_partitions = array();
		
	for ( $i = 0 ; $i < $dist_threads ; ++$i )
	{
		if ( $i === 0 ) 
		{
			$data_start = 0;
			$start_checksum = 0;
		}
		else
		{
			$data_start = $data_end;
			$start_checksum = $checksum;
		}
		
		if ( $i+1 < $dist_threads ) 
		{
			# then, find the max checksum
			fseek($f, $data_start+$data_portition);
			$line = fgets($f);
			$line = fgets($f);
			$p = explode(" ", trim($line));
			$old_checksum = hexdec($p[0]);	
			$temp_len = 0;
			do
			{
				$line = fgets($f);
				$p = explode(" ", trim($line));
				$checksum = hexdec($p[0]);
				$temp_len = strlen($line);
				#echo "$checksum !== $old_checksum \n";
					
			} while ( $checksum === $old_checksum );
				
			# rewind until first complete row with the new checksum
			fseek($f, ftell($f)-$temp_len);
			$data_end = ftell($f);
			$data_partitions[$i] = array($data_start, $old_checksum, $start_checksum);
		}
		else
		{
			# this is the last thread
			# no need to find the max checksum
			$data_partitions[$i] = array($data_start, pow(2,48), $start_checksum);
		}
		
	}
	
	/* DATA PORTITION SEEKER */
}

?>