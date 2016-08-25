<?php

/* Copyright (C) 2016 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@pickmybra.in
 * or visit: http://www.pickmybra.in
 */

ini_set("display_errors", 1);
error_reporting(E_ALL);

/*
livesearch.php
*/

if ( !empty($_GET["q"]) )
{
	
	
	# calculate how many results can be fitted into the viewport
	$slots = 4;
	if ( !empty($_GET["h"]) )
	{
		$slots = (int)floor(($_GET["h"] - 110 - 30) / 154);
	}
	
	$offset = 0;
	if ( !empty($_GET["o"]) && is_numeric($_GET["o"]) )
	{
		$offset = (int)$_GET["o"];
	}
	
	$index_name = "";
	if ( !empty($_GET["index_name"]) )
	{
		$index_name = $_GET["index_name"];
	}

	include("PMBApi.php");
	
	# the index name can be defined with the constructor or later on with SetIndex-method
	$pickmybrain = new PickMyBrain($index_name); 
	
	$result = $pickmybrain->Search($_GET["q"], $offset, $slots);
	
	#echo "Final Memory usage: " . memory_get_usage()/1024/1024 . " MB"; 				# for debugging
	#echo "Final Memory usage (peak): " . memory_get_peak_usage()/1024/1024 . " MB";	# for debugging

	if ( !empty($result["matches"]) )
	{
		$pagenumber = "";
		if ( $offset > 0 )
		{
			$pagenumber = "Page " . (round($offset/$slots)+1) . " of ";
		}
		
		echo "<div class='result_container'>$pagenumber" . $result["total_matches"]." results ( ".round(ceil($result["query_time"]*100)/100, 2)." seconds )</div>";

		# this index contains time statistics 
		#print_r($result["stats"]); 

		foreach ( $result["matches"] as $doc_id => $row ) 
		{
			if ( !isset($row["title"]) )
			{
				echo "<div class='result_container'>
						<div class='result_title'>document_id: $doc_id</div>";
						
						foreach ( $row as $column => $col_val ) 
						{
							echo "<div class='result_text'>$column => $col_val</div>";
						}
						
						echo "</div>";
			}
			else
			{	
				# shorten title if necessary
				if ( mb_strlen($row["title"]) > 94 ) 
				{
					$row["title"] = mb_substr($row["title"], 0, 90) . " ...";
				}
				
				$compactlink = $pickmybrain->CompactLink($row["URL"], 60);
				
				echo "<div class='result_container'>
						<a class='result_title' href='".$row["URL"]."'>".$row["title"]."</a>
						<div class='result_url'>$compactlink</div>
						<div class='result_text'>".$row["content"]."</div>
					  </div>
						";
			}
		}

		# print pagelist if necessary
		if ( $result["total_matches"] > 10 ) 
		{
			$visible_result_count = $result["total_matches"];
			if ( $result["total_matches"] > 1000 ) 
			{
				$visible_result_count = 1000;
			}
			
			$page_html = "";
			$current_page = 0;
			$pages = ceil($visible_result_count/$slots);
			
			if ( $offset !== 0 ) 
			{
				$current_page = (int)($offset/$slots);
			}

			for ( $i = 0 ; $i*$slots < $visible_result_count ; ++$i ) 
			{
				# print page
				$offset = $i*$slots;
				$current = "";
				if ( $i == $current_page )  $current = "current";
				$page_html .= "<li><a onclick='pmbsearch(document.getElementById(\"pmblivesearchinput\").value, event, $offset)' class='page-numbers $current'>" . ($i+1) . "</a></li>";
			}
				
			echo "<nav class='col full pagination'>
			   <ul>
			   $page_html
			   </ul>
		   </nav>";
		   
		
		}
	}
	else if ( !empty($result["error"]) )
	{
		echo "<p>Following error message was received: ".$result["error"]."</p>";
	}
	else
	{
		echo "<p>Sorry, no results :(</p>";
	}	
}

return;

?>