<?php

/* Copyright (C) 2017 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@gmail.com
 * or visit: http://www.pickmybra.in
 */

if ( $process_number === 0 )
{

	/* DATA PORTITION SEEKER */
	$data_size = filesize($filepath);
	$data_portition = (int)($data_size/$dist_threads);
	
	$data_end = false;
	$data_partitions = array();
	
	if ( $data_size < 1024*1024*1 ) 
	{
		# data_size is so small, that there is no need to use multiprocessing
		$data_partition[0] = 0; 		# start offset (bytes)
		$data_partition[1] = pow(2,48); # max checksum
		$data_partition[2] = 0; 		# start checksum
		$temp_disable_multiprocessing = true;
	}
	else
	{	
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
				$old_checksum = $PackedIntegers->bytes_to_int($p[0]);
				$temp_len = 0;
				do
				{
					$line = fgets($f);
					$p = explode(" ", trim($line));
					$checksum = $PackedIntegers->bytes_to_int($p[0]);
					$temp_len = strlen($line);
						
				} while ( $checksum === $old_checksum );
					
				# rewind until first complete row with the new checksum
				fseek($f, ftell($f)-$temp_len);
				$data_end = ftell($f);
				# data start offset, maximum checksum (old data), minimum checksum (old data)
				$data_partitions[$i] = array($data_start, $checksum, $start_checksum);
			}
			else
			{
				# this is the last thread
				# no need to find the max checksum
				$data_partitions[$i] = array($data_start, pow(2,48), $start_checksum);
			}
			
		}
		
		$data_partition = $data_partitions[0];
	}

	/* DATA PORTITION SEEKER */
}

?>