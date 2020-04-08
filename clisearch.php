<?php

/* Copyright (C) 2017 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@gmail.com
 * or visit: http://www.pickmybra.in
 */
/*

Command line search
-------------------

This is just a simple wrapper for the PMBApi-class that enables you to make simple 
search queries from the command line

*/

if ( !empty($argv) )
{
	$supported_commands = array("search" 	=> 1,
								"filterby" 	=> 1,
								"index" 	=> 1,
								"offset" 	=> 1,
								"limit"		=> 1,
								"matchmode"	=> 1,
								"rankingmode"=> 1,
								"filterrange" => 1,
								"sortmode"	=> 1,
								"groupby" => 1);
								
	$help_string = "------------------------------------------\n";
	$help_string .= "Welcome to Pickmybrain command line search\n";
	$help_string .= "To get started, please provide the required attributes:\n";
	$help_string .= "------------------------------------------\n";
	$help_string .= "\nRequired attributes:\n";
	$help_string .= "--index=myindexname // defines which index you want to query \n";
	$help_string .= "--search=keyword1,keyword2 // your keyword(s) \n";
	$help_string .= "Optional attributes:\n";
	$help_string .= "--offset=0 // skip this many results\n";
	$help_string .= "--limit=10 // fetch this many results\n";
	$help_string .= "--sortmode=1 // how to sort results ( 1=RELEVANCE,2=POSITIVITY,3=NEGATIVITY,4=SORTBY_ATTR )\n";
	$help_string .= "  if (sortmode=4) then attribute and direction are required: sortmode=4:myAttribute:desc\n";
	$help_string .= "--groupby=1 // how to group results (1=DISABLED, 2=GROUPBY_ATTR)\n";
	$help_string .= "  if (groupby=2) then grouping attribute, direction and group sort attributes are required: sortmode=4:myGroupAttr:myGroupSortAttr:desc\n";
	$help_string .= "--matchmode=2 // matching mode (1=ANY,2=ALL,3=STRICT)\n";
	$help_string .= "--rankingmode=1 // rankers only for relevance sorting mode (1=PROXIMITY+BM25,2=BM25 ONLY,3=PROXIMITY ONLY)\n";
	$help_string .= "--filterby=myAttribute_1:value_1,value_2+myAttribute_2:value_1,value_2\n";
	$help_string .= "--filterrange=myAttribute:min_value,max_value // filter results by attribute\n\n";
	$help_string .= "";

	require_once("PMBApi.php");
	$pickmybrain = new PickMyBrain();

	$index_name   = "";
	$searchstring = "";
	$offset 	  = 0;
	$limit 		  = 10;
	$sortmode	  = 1;
	$sort_attr	  = "";
	$groupby	  = 1;
	$group_attr	  = "";
	$group_sort_attr = "";
	$matchmode 	  = 2;
	$rankingmode  = 1;
	
	$i = 0;
	while ( isset($argv[++$i]) )
	{
		if ( strpos($argv[$i], "--") === 0 )
		{
			# special case: --help
			if ( strpos($argv[$i], "--help") === 0 )
			{
				# print some info
				echo $help_string;
				return;
			}
			
			$pos = strpos($argv[$i], "=");
			
			if ( $pos ) 
			{
				$command = mb_substr($argv[$i], 2, $pos-2);	# the command ( before = ) 
				$value   = mb_substr($argv[$i], $pos+1);	# the value ( after = )
				
				# command is valid !
				if ( isset($supported_commands[$command]) )
				{
					if ( !isset($value) || $value == "" )
					{
						echo "Invalid value $value for attribute --$command \n";
						continue;
					}
					
					switch ( $command ) 
					{
						case "index":
						$index_name = $value;
						break;
						
						case "sortmode":
						if ( substr_count($value, ":") > 0 )
						{
							$expl = explode(":", $value);
							
							if ( $expl[0] == "4" && count($expl) === 3 )
							{
								$sortmode = 4;
								$sort_attr = $expl[1] . " " .  $expl[2];
							}
							else
							{
								echo "Invalid sorting expression\n";
							}
						}
						else
						{
							$sortmode = $value;
						}
						break;
						
						case "matchmode":
						$matchmode = (int)$value;
						break;
						
						case "rankingmode":
						$rankingmode = (int)$value;
						break;
						
						case "groupby":
						if ( substr_count($value, ":") > 0 )
						{
							$expl = explode(":", $value);
							
							if ( $expl[0] == "2" && count($expl) === 4 )
							{
								$groupby = 2;
								$group_attr = $expl[1];
								$group_sort_attr = $expl[2] . " " .  $expl[3];
							}
							else
							{
								echo "Invalid sorting expression\n";
							}
						}
						else
						{
							$groupby = $value;
						}
						break;
						
						case "search":
						$searchstring = str_replace(",", " ", $value);
						break;
						
						case "offset":
						if ( is_numeric($value) && $value >= 0 )
						{
							$offset = (int)$value;
						}
						break;
						
						case "limit":
						if ( is_numeric($value) && $value >= 1 )
						{
							$limit = (int)$value;
						}
						break;
						
						case "filterby":
						# parse value
						$parts = explode("+", $value);
						foreach ( $parts as $part ) 
						{
							$valpairs = explode(":", $part);
							
							if ( !empty($valpairs[1]) )
							{
								$filter_values = explode(",",$valpairs[1]); # values
								
								foreach ( $filter_values as $filter_value ) 
								{
									$pickmybrain->SetFilterBy($valpairs[0], (int)$filter_value);
								}
							}
						}
						break;
						
						case "filterrange":
						# parse value
						$parts = explode("+", $value);
						foreach ( $parts as $part ) 
						{
							$valpairs = explode(":", $part);
							
							if ( !empty($valpairs[1]) )
							{
								$filter_values = explode(",",$valpairs[1]); # values
								
								if ( count($filter_values) !== 2 )
								{
									echo "error: invalid filterrange expression\n";
									continue;
								}
								else if ( $filter_values[0] > $filter_values[1] )
								{
									echo "error: invalid filterrange values ( min > max )\n";
									continue;
								}
								
								$pickmybrain->SetFilterRange($valpairs[0], (int)$filter_values[0], (int)$filter_values[1]);
							}
						}
						break;
					}
				}
			}
		}
		else
		{
			# invalid command
			echo "Invalid command!\n";
			return;
		}
	}
	
	# if now attributes provided, show help
	if ( $i === 1 ) 
	{
		echo $help_string;
		return;
	}
	
	# set index
	$pickmybrain->SetIndex($index_name);
	
	if ( $sortmode == 4 ) 
	{
		$pickmybrain->SetSortMode(4, $sort_attr);
	}
	
	if ( $groupby == 2 ) 
	{
		$pickmybrain->SetGroupBy(2, $group_attr, $group_sort_attr);
	}
	
	$pickmybrain->SetRankingMode($rankingmode);
	$pickmybrain->SetMatchMode($matchmode);
	
	$pickmybrain->SetLogState(0); 		# disable query logging
	$results = $pickmybrain->Search($searchstring, $offset, $limit);
	
	echo $results["total_matches"] . " matching documents found.\n";
	
	if ( !empty($results["matches"]) )
	{
		foreach ( $results["matches"] as $doc_id => $doc_data ) 
		{
			$data_string = "doc_id:$doc_id"; 
			
			foreach ( $doc_data as $attr => $attr_value ) 
			{
				$data_string .= " $attr:$attr_value";
			}
			
			echo $data_string . "\n";
		}
	}
	
	echo "\n";
	

}




?>