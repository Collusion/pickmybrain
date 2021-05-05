<?php

/* Copyright (C) 2021 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@gmail.com
 * or visit: http://www.pickmybra.in
 */
 
?>

<!DOCTYPE html>
<!--[if (gte IE 8)|!(IE)]><!--><html lang="en"> <!--<![endif]-->
<head>
	<meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>My pickmybrain search</title>
	<meta name="description" content="Search feature powered by Pickmybrain (https://www.pickmybra.in)">
	<meta name="author" content="www.pickmybra.in - Pickmybrain Search for PHP">
	<!-- CSS
	================================================== -->
	<link rel="stylesheet" href="demo.css">

   <!-- Favicons
	================================================== -->
	<link rel="shortcut icon" href="../images/favicon.ico" >
</head>
<body>

<?php

/* Copyright (C) 2017 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@gmail.com
 * or visit: http://www.pickmybra.in
 */

# include pickmybrain api
require_once("../PMBApi.php");

# initialize pickmybrain class

$my_index_name 		= "index_name"; 	# your search index name
$results_per_page 	= 10; 				# display 10 results per page

/*
# Define the $sort_by array, if you want to display a <select> element with user selectable sorting mode for search results
# If you leave this array empty/undefined, no <select> element will be shown for sorting results and results will be ordered by relevance by default

prototype:
----------
$sort_by[SORT_MODE] = "Display name" 	
where
SORT_MODE		is either PMB_SORTBY_RELEVANCE, PMB_SORTBY_POSITIVITY or PMB_SORTBY_NEGATIVITY (internal sorting mode)
Display name	is the display name for this sorting option that ends up in the <select> element

usage examples: 
---------------
$sort_by[PMB_SORTBY_RELEVANCE] 	= "Best results"; 					# if you want to show a <select> element with "Best results" sort option
$sort_by[PMB_SORTBY_POSITIVITY]	= "Most positive matches first";	# if you want to allow users to sort results by positivity (sentiment analysis must be enabled)
$sort_by[PMB_SORTBY_NEGATIVITY]	= "Most negatve matches first";		# if you want to allow users to sort results by negativity (sentiment analysis must be enabled)

# more advanced sorting modes below (can be used same time with the examples above!)

prototype:
---------
$sort_by[SORT_MODE]["SORT_ATTRIBUTE"]["SORT_DIRECTION"] = "Display name";
where 
SORT_MODE 		is always PMB_SORTBY_ATTR
SORT_ATTRIBUTE 	is your custom db index attribute. In case of a web index it can be timestamp, domain or category
SORT_DIRECTION  defined whether you want the smallest or largest results first, asc OR desc
Display name	is the display name for this sorting option that ends up in the <select> element

usage examples:
---------------
$sort_by[PMB_SORTBY_ATTR]["timestamp"]["asc"] 	= "Oldest results";  # order results by timestamp attribute, smaller values first, display "Oldest results" in the <select> element
$sort_by[PMB_SORTBY_ATTR]["timestamp"]["desc"] 	= "Newest results";  # order results by timestamp attribute, larger values first, display "Newest results" in the <select> element

Sell all pickmybrain api features in:
https://www.pickmybra.in/api.php
*/

# define your custom sort modes here
$sort_by[PMB_SORTBY_RELEVANCE] 	= "Best results";					# remove/comment this line if you don't want to see "Best Results" sort option
#$sort_by[PMB_SORTBY_ATTR]["timestamp"]["desc"] 	= "Newest results";	# remove/comment this line if you don't want to see "Newest Results" sort option

$sort_select_options = array();
# based on the $sort_by array, build the data for the <select> element here
# the same array can be also used for "security check" for $_GET["sort"] data
if ( !empty($sort_by) && is_array($sort_by) ) 
{
	foreach ( $sort_by as $sort_mode => $sort_data ) 
	{
		switch ( $sort_mode ) 
		{
			case PMB_SORTBY_RELEVANCE:
			case PMB_SORTBY_POSITIVITY:
			case PMB_SORTBY_NEGATIVITY:
			if ( !empty($sort_data) && is_string($sort_data) ) 
			{
				$sort_select_options["$sort_mode"] = "<option value='$sort_mode'>$sort_data</option>\n";
			}
			break;
			
			case PMB_SORTBY_ATTR:
			if ( !empty($sort_data) && is_array($sort_data) ) 
			{
				foreach ( $sort_data as $sort_attribute => $attr_data ) 
				{
					foreach ( $attr_data as $sort_direction => $display_name )
					{
						$option_value = implode("-", array("$sort_mode", "$sort_attribute", "$sort_direction"));
						
						# if there's currently a sort mode defined
						$selected = "";
						if ( !empty($_GET["sort"]) && $_GET["sort"] == $option_value ) 
						{
							$selected = "selected";
						}

						$sort_select_options[$option_value] = "<option value='$option_value' $selected>$display_name</option>\n";
					}
				}
			}
			break;
		}
	}
}

$search_query = "";

# check that search query has been defined/provided
if ( isset($_GET["q"]) && trim($_GET["q"]) !== "" )
{
	# initialize pickmybrain 
	$pickmybrain = new Pickmybrain($my_index_name);
	
	# for security:
	$_GET["q"] = strip_tags($_GET["q"]);
	
	$search_query = $_GET["q"];
	
	# offset
	$offset = 0;
	if ( !empty($_GET["offset"]) && is_numeric($_GET["offset"]) )
	{
		$offset = (int)$_GET["offset"];
	}
	
	# sortmode defined? + do a security check at the same time
	if ( isset($_GET["sort"]) && isset($sort_select_options[$_GET["sort"]]) )
	{
		$parts 	= explode("-", $_GET["sort"]);
		$count 	= count($parts);
	
		switch ( (int)$parts[0] )
		{
			case PMB_SORTBY_RELEVANCE:
			case PMB_SORTBY_POSITIVITY:
			case PMB_SORTBY_NEGATIVITY:
			if ( $count === 1 ) 
			{
				$pickmybrain->SetSortMode((int)$parts[0]);
			}
			break;
			
			case PMB_SORTBY_ATTR:
			if ( $count === 3 )
			{
				$pickmybrain->SetSortMode((int)$parts[0], $parts[1] . " " . $parts[2]);
			}
			break;
		}
	}
	
	# groupmode (not implemented in this demo search)
	if ( isset($_GET["group"]) )
	{
		$parts = explode("-", $_GET["group"]);
		$count = count($parts);
		
		switch ( (int)$parts[0] )
		{
			case PMB_GROUPBY_DISABLED:
			if ( $count === 1 ) 
			{
				$pickmybrain->SetGroupBy((int)$parts[0]);
			}
			break;
			
			case PMB_GROUPBY_ATTR:
			if ( $count === 4 && !empty($parts[1]) && !empty($parts[2]) && !empty($parts[3]) )
			{
				$pickmybrain->SetGroupBy((int)$parts[0], $parts[1], $parts[2] . " " . $parts[3]);
			}
			break;
		}
	}
	
	# match mode (not implemented in this demo search)
	if ( isset($_GET["match"]) )
	{
		$pickmybrain->SetMatchMode((int)$_GET["match"]);
	}
	
	# if you want to, you can deploy custom field weights here
	# for web indexes, predefined fields are: title, url, content, meta
	# for database indexes, you have your own set of fields
	# if you want to exclude a field from the search, just set the field weight to 0
	$pickmybrain->SetFieldWeights(array("title" => 10, "url" => 2, "content" => 1, "meta" => 2));
	
	# finally, search the index
	$result = $pickmybrain->Search($_GET["q"], $offset, $results_per_page);
}

/*
helper function: creates a list of links with offset parameter based on the provided item_count parameter
[params]:
item_count 			= how many items there are total
offset				= current offset
params 				= an associative array of parameters ( [parameter_name] => parameter_value )
items_per_page 		= how many items will be fit into one page
offset_seek_range 	= how many items will be printed before and after the current page ( this value / items_per_page => how many pages will be printed before&after )
[output]:
on success: a string containing a list of links ( <a> elements with predefined class and title attributes ), or an empty string if there's nothing to print
on error: an empty string
*/
function create_page_list($item_count, $offset, $params = array(), $items_per_page = 10, $offset_seek_range = 30)
{
	if ( empty($item_count) ) 				return "";
	if ( !isset($offset) || $offset < 0 ) 	return "";
	
	// how many pages are needed to render all the items ? 
	$pages 					= ceil($item_count / $items_per_page);
	if ( !$pages ) 			$pages = 1;
	$min_printable_offset 	= $offset-$offset_seek_range;
	$max_printable_offset 	= $offset+$offset_seek_range;
	$max_offset 			= ($pages - 1) * $items_per_page; // this is the max offset (showing very last items on this page)

	if ( $min_printable_offset < 0 )
	{
		// show more pages from another end then
		// if 10-30 => -20 => abs(20) => max_printable offset +20 (40+20) => 60
		$max_printable_offset += abs($min_printable_offset);
		$min_printable_offset = 0;
	}
	
	if ( $max_printable_offset > $max_offset ) 
	{
		// show more pages from another end then
		$min_printable_offset -= $max_printable_offset-$max_offset;
		if ( $min_printable_offset < 0 ) $min_printable_offset = 0;
		$max_printable_offset = $max_offset;
	}

	// start rendering from min printable offset
	$offset_c = $min_printable_offset;
	
	// add other relevant get variables
	$page_url_addon = "";
	$number_string 	= "";
	if ( !empty($params) && is_array($params) )
	{
		foreach ($params as $param_name => $param_value ) 
		{
			if ( !empty($param_name) && isset($param_value) )
			{
				$page_url_addon .= "&".$param_name."=$param_value";
			}
		}
	}
	
	// add links to first / last pages only if they are not "naturally" visible in the page numbers
	if ( $min_printable_offset > 0 )
	{
		$number_string .= "<a class='page_b radius shadow' href='?offset=0".$page_url_addon."' title='First page'>&laquo;</a>\n";
	}

	while ( $offset_c <= $max_printable_offset )
	{
		$current = "";
		if ( $offset_c == $offset ) $current = "page_b_sel";
		$current_page_num = ($offset_c + $items_per_page) / $items_per_page;
		$number_string .= "<a class='page_b $current radius shadow' href='?offset=$offset_c".$page_url_addon."' title='Page $current_page_num'>$current_page_num</a>\n";
		// add page link into a string
		$offset_c += $items_per_page;
	}
	
	// add links to first / last pages only if they are not "naturally" visible in the page numbers
	if ( $max_offset > $max_printable_offset )
	{
		$current_page_num = ($max_offset + $items_per_page) / $items_per_page;
		$number_string .= "<a class='page_b radius shadow' href='?offset=$max_offset".$page_url_addon."' title='Last page ($current_page_num)'>&raquo;</a>\n";
	}
	
	return $number_string;
}

?>

<!DOCTYPE html>
<!--[if (gte IE 8)|!(IE)]><!--><html lang="en"> <!--<![endif]-->
<head>
	<meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>My pickmybrain search</title>
	<meta name="description" content="Search feature powered by Pickmybrain (https://www.pickmybra.in)">
	<meta name="author" content="www.pickmybra.in - Pickmybrain Search for PHP">
	<!-- CSS
	================================================== -->
	<link rel="stylesheet" href="demo.css">

   <!-- Favicons
	================================================== -->
	<link rel="shortcut icon" href="../images/favicon.ico" >
</head>
<body>
    <div class="center">
    
        <div class="iblock clear tcenter">
            <img src="logo.png" id="logo" class="radius"/>
        </div>
		<form method="get">
            <div class="iblock clear tcenter">
            	<input type='text' placeholder='What are we looking for?' name='q' class='searchbar radius shadow' value='<?php echo $search_query; ?>' />
                <input type='submit' value='Search' class='submit shadow' />
            </div>   
            <?php
			# display sorting options (if defined) and a search has been made already
			if ( !empty($sort_select_options) && !empty($search_query) ) 
			{
				echo "<div class='result_container'>";
				echo "<select class='select-css' name='sort' onchange='this.form.submit()'>".implode("\n", $sort_select_options)."</select>";
				echo "</div>";
			}
			?>
        </form>
        <div class="iblock clear">
        <?php
		
		if ( !empty($result) ) 
		{
			# handle / print the matches here
			if ( !empty($result["total_matches"]) ) 
			{				
				# if approximate count flag is on, inform the user about it
				$about = "";
				if ( isset($result["approximate_count"]) ) $about = "About";

				# calculate the current page number
				$pagenumber = "";
				if ( $offset > 0 )
				{
					$pagenumber = "Page " . (round($offset/$results_per_page)+1) . " of ";
					if ( isset($result["approximate_count"]) )
					{
						$about = "";
						$pagenumber .= " about ";
					}
				}
				
				# display search statistics => how many results, what page are we on and how long did the search take
				echo "<div class='result_container'>
						$about $pagenumber" . $result["total_matches"]." results ( ".round(ceil($result["query_time"]*1000)/1000, 3)." seconds )
					  </div>";

				# show "did you mean" keyword suggestion, if the user is on the first page of results
				if ( !empty($result['did_you_mean']) && !$offset )
				{
					echo "<div class='suggestions'>
						Did you mean: <a href='?q=".urlencode($result['did_you_mean'])."'>".$result['did_you_mean']."</a>
						</div>";
				}
				
				
				# make a copy of the search query for the SearchFocuser function
				# remove double quotes
				$search_query_copy = str_replace(array("\""), "", $search_query);
				
				foreach ( $result["matches"] as $document_id => $match ) 
				{
					# because it's going to be your index
					# you probably already know which fields are going to be defined
					if ( 	isset($match["title"]) && 
							isset($match["URL"]) && 
							isset($match["content"]) 
							)
					{
						# probably web index, since all the "integrated" fields are defined
						
						# page title, shorten if necessary
						if ( mb_strlen($match["title"]) > 94 ) 
						{
							$match["title"] = mb_substr($match["title"], 0, 90) . " ...";
						}
						# if page title is not availabe, use the start of the page content instead
						else if ( empty($match["title"]) ) 
						{
							# if the web page does not have a title defined, create one from the pages content
							$match["title"] = mb_substr($match["content"], 0, 60) . " ...";
						}
						
						# show a compactified version of the web pages URL
						$compact_url = $pickmybrain->CompactLink($match["URL"], 90);

						# provide a focused / shortened version of the main content
						$match["content"] = $pickmybrain->SearchFocuser($match["content"], $search_query_copy, "en", 70);
						
						echo "<div class='result_container'>
								<a class='result_title' href='".$match["URL"]."'>".$match["title"]."</a>
								<div class='result_url'>$compact_url</div>
								<div class='result_text'>" . $match["content"] . "</div>
							  </div>
								";
					}
					else
					{
						# most likely a database index
						# print your resulting document here		
						echo "<div class='result_container'>
								<a class='result_title' href=''>Result #".$document_id."</a>
								<div class='result_url'>Some short description</div>
								<div class='result_text'>Your matching document content</div>
							  </div>";
					}
				}
			}
			else
			{
				echo "<p>No matches.</p>";
			}
		}
		
		?>
        </div>
        <div class="iblock clear footer">
        <?php
		# print page list here
		if ( !empty($result['total_matches']) ) 
		{
			$params = array();
			if ( isset($_GET["q"]) ) 		$params["q"] 		= $search_query;
			if ( isset($_GET["sort"]) ) 	$params["sort"] 	= $_GET["sort"];
			if ( isset($_GET["group"]) ) 	$params["group"] 	= $_GET["group"];
			if ( isset($_GET["match"]) ) 	$params["match"] 	= $_GET["match"];
			echo create_page_list($result['total_matches'], $offset, $params, $results_per_page);
		}
		
		?>
        </div>
     </div>
</body>
</html>