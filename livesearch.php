<?php

/* Copyright (C) 2017 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@gmail.com
 * or visit: http://www.hollilla.com/pickmybrain
 */

ini_set("display_errors", 1);
error_reporting(E_ALL);
set_time_limit(10);

/*
livesearch.php
*/

if ( isset($_GET["q"]) && trim($_GET["q"]) !== "" )
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
	
	# sortmode
	if ( isset($_GET["sort"]) )
	{
		if ( !empty($_GET["sort_attr"]) )
		{
			$pickmybrain->SetSortMode((int)$_GET["sort"], $_GET["sort_attr"] . " " . $_GET["sort_dir"]);
		}
		else
		{
			$pickmybrain->SetSortMode((int)$_GET["sort"]);
		}
	}

	# groupmode
	if ( isset($_GET["group"]) )
	{
		if ( !empty($_GET["group_attr"]) )
		{
			$pickmybrain->SetGroupBy((int)$_GET["group"], $_GET["group_attr"], $_GET["group_sort_attr"] . " " . $_GET["group_sort_dir"]);
		}
		else
		{
			$pickmybrain->SetGroupBy((int)$_GET["sort"]);
		}
	}
	
	if ( isset($_GET["match"]) )
	{
		$pickmybrain->SetMatchMode((int)$_GET["match"]);
	}
	
	$result = $pickmybrain->Search($_GET["q"], $offset, $slots);

	if ( !empty($result["matches"]) )
	{
		$pagenumber = "";
		if ( $offset > 0 )
		{
			$pagenumber = "Page " . (round($offset/$slots)+1) . " of ";
		}
		
		echo "<div class='result_container'>$pagenumber" . $result["total_matches"]." results ( ".round(ceil($result["query_time"]*1000)/1000, 3)." seconds )</div>";

		if ( !empty($result["did_you_mean"]) )
		{
			echo "<div class='suggestions'>
					did you mean: <a class='cursor' onclick='document.getElementById(\"pmblivesearchinput\").value = this.innerText;pmbsearch(document.getElementById(\"pmblivesearchinput\").value, event, $offset);'>".$result["did_you_mean"]."</a>
				  </div>";
		}
		
		if ( !empty($result["error"]) )
		{
			echo "<div class='errormessage'>Following error message was received: ".$result["error"]."</div>";
		}

		$hidden_data = "";
		$hidden_title = "";
		$hidden_onclick = "";
		foreach ( $result["matches"] as $doc_id => $row ) 
		{
			foreach ( $row as $column => $col_val ) 
			{
				if ( $col_val == $doc_id ) 
				{
					$hidden_data = "hidden_data";
					$hidden_title = "title='Click to expand'";
					$hidden_onclick = "onclick='ExpandResult(this);'";
					break;
				}
			}
		}

		# this index contains time statistics 
		#print_r($result["stats"]); 

		foreach ( $result["matches"] as $doc_id => $row ) 
		{
			if ( isset($row["title"]) && isset($row["URL"]) && isset($row["content"]) )
			{	
				/* web-index */
			
				# shorten title if necessary
				if ( mb_strlen($row["title"]) > 94 ) 
				{
					$row["title"] = mb_substr($row["title"], 0, 90) . " ...";
				}

				$compactlink = $pickmybrain->CompactLink($row["URL"], 90);

				# focus the main content
				$row["content"] = $pickmybrain->SearchFocuser($row["content"], $_GET["q"], "fi", 70);

				echo "<div class='result_container'>
						<a class='result_title' href='".$row["URL"]."'>".$row["title"]."</a>
						<div class='result_url'>$compactlink</div>
						<div class='result_text'>" . $row["content"] . "</div>
					  </div>
						";
			}
			else
			{
				$extra_fields = "";
				/* database index */
				echo "<div class='result_container $hidden_data' $hidden_title $hidden_onclick>
						<div class='result_title '>document_id: $doc_id</div>";
						$extra_field_printed = false;
						foreach ( $row as $column => $col_val ) 
						{
							if ( $col_val == $doc_id && !$extra_field_printed ) 
							{
								$extra_fields .= "<div style='display:none;' name='hiddencontent'>";
								$extra_field_printed = true;
							}

							if ( mb_strlen($col_val) > 94 )
							{
								$col_val = $pickmybrain->SearchFocuser($col_val, $_GET["q"]);
								# content needs to be focused
								$extra_fields .= "<div class='result_text'>$column => $col_val</div>";
							}
							else
							{
								$col_val = strip_tags($col_val);
								$extra_fields .= "<div class='result_text'>$column => $col_val</div>";
							}
						}
						
						if ( !empty($extra_fields) )
						{
							echo "$extra_fields </div>";
						}
						
						echo "</div>";
			}
		
		}

		# print pagelist if necessary
		if ( $result["total_matches"] > $slots ) 
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
		echo "<div class='errormessage'>Following error message was received: ".$result["error"]."</div>";
	}
	else if ( !empty($result["did_you_mean"]) )
	{
		echo "<div class='suggestions'>
				did you mean: <a class='cursor' onclick='document.getElementById(\"pmblivesearchinput\").value = this.innerText;pmbsearch(document.getElementById(\"pmblivesearchinput\").value, event, $offset);'>".$result["did_you_mean"]."</a>
			  </div>
			  <div class='errormessage'>Sorry, no results :(</div>";
	}
	else
	{
		echo "<div class='errormessage'>Sorry, no results :(</div>";
	}	
}

return;

?>