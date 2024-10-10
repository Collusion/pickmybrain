<?php

/* Copyright (C) 2017 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@gmail.com
 * or visit: http://www.pickmybra.in
 */

/* 32_BIT_VERSION (DO NOT MODIFY OR REMOVE THIS COMMENT) */

# important
mb_internal_encoding("UTF-8");

# sorting modes
define("PMB_SORTBY_RELEVANCE"		, 1);
define("PMB_SORTBY_POSITIVITY"		, 2);
define("PMB_SORTBY_NEGATIVITY"		, 3);
define("PMB_SORTBY_ATTR"			, 4);
define("PMB_SORTBY_MODIFIED"		, 5);

# matching modes
define("PMB_MATCH_ANY"				, 1);
define("PMB_MATCH_ALL"				, 2);
define("PMB_MATCH_STRICT"			, 3);

# ranking modes
define("PMB_RANK_PROXIMITY_BM25"	, 1);
define("PMB_RANK_BM25"				, 2);
define("PMB_RANK_PROXIMITY"			, 3);

# grouping modes
define("PMB_GROUPBY_DISABLED"		, 1);
define("PMB_GROUPBY_ATTR"			, 2);

require_once("db_connection.php");

class PickMyBrain
{					
	/* Index specific */
	private $sortmode;
	private $sort_attr;
	private $sortdirection;
	private $rankmode;
	private $groupmode;
	private $group_attr;
	private $group_sort_attr;
	private $group_sort_direction;
	private $filter_by;
	private $filter_by_range;
	private $matchmode;
	private $field_weights;
	private $sentiweight;
	private $keyword_stemming;
	private $keyword_suggestions;
	private $dialect_matching;
	private $dialect_replacing;
	private $quality_scoring;
	private $prefix_minimum_quality;
	private $stem_minimum_quality;
	private $expansion_limit;
	private $separate_alnum;
	private $blend_chars;
	private $ignore_chars;
	private $db_connection;
	private $enable_exec;
	private $indexing_interval;
	private $prefix_mode;
	private $prefix_length;
	private $charset_regexp;
	private $sentiscale;
	private $log_queries;
	private $result;
	private $index_name;
	private $index_id;
	private $index_type;
	private $suffix;
	private $current_index;
	private $dialect_find;
	private $dialect_replace;
	private $mass_find;
	private $mass_replace;
	private $data_columns;
	private $number_of_fields;
	private $field_id_width;
	private $doc_id_distance;
	private $lsbits;
	private $main_sql_attrs;
	private $max_results;
	private $sentiment_analysis;
	private $include_original_data;
	private $use_internal_db;
	
	/* Internal */
	private $allowed_sort_modes;
	private $allowed_grouping_modes;
	private $non_scored_sortmodes;
	private $hex_lookup_decode;
	private $hex_lookup_encode;
	private $pow_lookup;
	private $meta_lookup_encode;
	private $documents_in_collection;
	private $delta_documents;
	private $index_state;
	private $latest_indexing_done;
	private $query_start_time;
	private $temp_matches;
	private $temp_sentiscores;
	private $disabled_documents;
	private $sql_body;
	private $primary_key;
	private $enabled_fields;
	private $final_doc_matches;
	private $min_doc_id;
	private $max_doc_id;
	private $decode_interval;
		
	public function __construct($index_name = "")
	{
		$start = microtime(true);
		$this->db_connection = db_connection();
		$end = microtime(true) - $start;
			
		# hex lookup table for vbdecode	
		for ( $i = 0 ; $i < 256 ; ++$i )
		{
			$bin_val = pack("H*", sprintf("%02x", $i));
			$this->hex_lookup_decode[$bin_val] = $i;
			$this->hex_lookup_encode[$i] = $bin_val;
		}
		
		# precalculated pow(128, n) lookup tabe, where n is between 0 and 7)
		# used for leftshiftin values in 32bit environments beyond the 32bit limit
		$this->pow_lookup = array(	1, 
									128, 
									16384, 
									2097152,
									268435456, 
									34359738368, 
									4398046511104, 
									562949953421312	);
		
		# lookup table for encoding metaphone values
		$this->meta_lookup_encode = array (
									  ' ' => 0,
									  'N' => 1,
									  'K' => 2,
									  'A' => 3,
									  'P' => 4,
									  'T' => 5,
									  'S' => 6,
									  'F' => 7,
									  'L' => 8,
									  'M' => 9,
									  'X' => 10,
									  'R' => 11,
									  'H' => 12,
									  'J' => 13,
									  '0' => 14,
									);
									
		# lookup tables for sorting/grouping modes
		$this->allowed_sort_modes = array();
		$this->allowed_grouping_modes = array();
		
		# web-crawlers
		$this->allowed_sort_modes[1] = array();
		$this->allowed_sort_modes[1][PMB_SORTBY_RELEVANCE] 		= true;
		$this->allowed_sort_modes[1][PMB_SORTBY_ATTR] 			= true;
		$this->allowed_sort_modes[1][PMB_SORTBY_POSITIVITY] 	= true;
		$this->allowed_sort_modes[1][PMB_SORTBY_NEGATIVITY] 	= true;
		$this->allowed_sort_modes[1][PMB_SORTBY_MODIFIED] 		= true;
		
		$this->allowed_grouping_modes[1] = array();
		$this->allowed_grouping_modes[1][PMB_GROUPBY_DISABLED] 	= true;
		$this->allowed_grouping_modes[1][PMB_GROUPBY_ATTR] 		= true;
		
		# database indexes
		$this->allowed_sort_modes[2] = array();
		$this->allowed_sort_modes[2][PMB_SORTBY_RELEVANCE] 		= true;
		$this->allowed_sort_modes[2][PMB_SORTBY_ATTR] 			= true;
		$this->allowed_sort_modes[2][PMB_SORTBY_POSITIVITY] 	= true;
		$this->allowed_sort_modes[2][PMB_SORTBY_NEGATIVITY] 	= true;
		
		$this->allowed_grouping_modes[2] = array();
		$this->allowed_grouping_modes[2][PMB_GROUPBY_DISABLED] 	= true;
		$this->allowed_grouping_modes[2][PMB_GROUPBY_ATTR] 		= true;
				
		# not all sorting modes need document specific scores calculated			
		$this->non_scored_sortmodes[PMB_SORTBY_ATTR] 			= true;
		$this->non_scored_sortmodes[PMB_SORTBY_MODIFIED] 		= true;
		
		# finally, load index if name is defined 
		if ( !empty($index_name) )
		{
			$this->SetIndex($index_name);
		}
	}
	
	# loads the settings defined for this particular index
	private function LoadSettings($index_id)
	{
		# include settings file
		require_once("autoload_settings.php");
		
		# there settings are not index specific
		$this->sortmode				= PMB_SORTBY_RELEVANCE; 	# default
		$this->rankmode				= PMB_RANK_PROXIMITY_BM25; 	# default
		$this->groupmode			= PMB_GROUPBY_DISABLED; 	# default
		$this->matchmode			= PMB_MATCH_ALL; 			# default
		
		# these settings are index specific		
		$this->sentiweight			= $sentiweight;
		$this->keyword_suggestions	= $keyword_suggestions;
		$this->keyword_stemming 	= $keyword_stemming;
		$this->dialect_matching 	= $dialect_matching;
		$this->quality_scoring		= $quality_scoring;
		$this->prefix_minimum_quality = 0;
		$this->stem_minimum_quality	= 0;
		$this->separate_alnum		= $separate_alnum;
		$this->log_queries			= $log_queries;
		$this->prefix_mode			= $prefix_mode;
		$this->prefix_length		= $prefix_length;
		$this->expansion_limit		= $expansion_limit;
		$this->enable_exec			= $enable_exec;
		$this->indexing_interval	= $indexing_interval;
		$this->max_results			= 1000;
		$this->temp_grouper_size	= 10000;
		$this->charset_regexp		= "/[^" . $charset . preg_quote(implode("", $blend_chars)) . "*\"()]/u";
		$this->blend_chars			= array();
		$this->ignore_chars			= $ignore_chars;
		$this->result				= array();
		$this->current_index		= $index_id;
		$this->field_id_width		= $this->requiredBits($number_of_fields);
		$this->lsbits				= pow(2, $this->field_id_width)-1;
		$this->doc_id_distance		= 1 << $this->field_id_width;
		$this->first_of_field		= 1 << $this->field_id_width + 1;
		$this->sentiment_analysis	= $sentiment_analysis;
		$this->sentiment_index		= ($sentiment_analysis) ? 1 : 0 ;
		$this->data_columns			= $data_columns;
		$this->field_weights 		= $field_weights;

		if ( !empty($blend_chars) )
		{
			# filter out blend chars in certain situations
			foreach ( $blend_chars as $blend_char ) 
			{
				$this->blend_chars[] = " $blend_char ";
				$this->blend_chars[] = "$blend_char ";
				
				if ( $blend_char !== "-" )
				{
					$this->blend_chars[] = " $blend_char";
				}
			}
		}
		
		if ( isset($use_internal_db) )
		{
			$this->use_internal_db	= $use_internal_db;
		}
		
		if ( isset($main_sql_attrs) )
		{
			$this->main_sql_attrs	= $main_sql_attrs;
		}
		
		if ( isset($include_original_data) )
		{
			$this->include_original_data = $include_original_data;
		}

		if ( $dialect_matching ) 
		{
			$this->dialect_find 	= array_keys($dialect_replacing);
			$this->dialect_replace 	= array_values($dialect_replacing); 
			$this->mass_find		= array_keys($dialect_array);
			$this->mass_replace		= array_values($dialect_array);
		}
		
		if ( !empty($this->field_weights) )
		{
			foreach ( $this->field_weights as $fi => $field_weight )
			{
				$this->field_weights[$fi] = (int)$field_weight;
			}
		}
		
		if ( !empty($main_sql_query) ) 
		{
			$this->sql_body = $this->RewriteMainSQL($main_sql_query);
		}
		else
		{
			$this->sql_body = array();
			$this->primary_key = "";
		}

		return true;
	}
	
	private function requiredBits($number_of_fields)
	{
		--$number_of_fields;
		if ( $number_of_fields <= 0 ) 
		{
			return 1;
		}
		
		return (int)(log($number_of_fields,2)+1);
	}

	private function CreateFieldWeightLookup()
	{
		if ( empty($this->field_weights) ) return false;
		
		$count = count($this->field_weights);	
		$combinations = pow(2, $count);
		$scores = array();
		
		for ( $i = 0 ; $i < $combinations ; ++$i ) 
		{
			$scores[$i] = 0;
			for ( $j = 0 ; $j < $count ; ++$j ) 
			{
				$scores[$i] += (($i >> $j)&1) * $this->field_weights[$this->data_columns[$j]];
			}
		}
		
		return $scores;
	}

	public function IncludeOriginalData($value)
	{
		$this->include_original_data = ( !empty($value) );
	}
	
	public function SetLogState($value)
	{
		$this->log_queries = ( !empty($value) );
	}
		
	private function execInBackground($cmd) 
	{
		if ( substr(php_uname(), 0, 7) == "Windows" )
		{
			pclose(popen("start /B ". $cmd, "r")); 
		}
		else
		{
			exec($cmd . " > /dev/null &");  
		}
	}
	
	private function execWithCurl($url)
	{
		$url = str_replace("localhost", $_SERVER['SERVER_NAME'], $url);
		$timeout = 1;

		$options = array(
			CURLOPT_RETURNTRANSFER 	=> true,     // return web page as string
			CURLOPT_HEADER         	=> false,    // don't return headers
			CURLOPT_FOLLOWLOCATION 	=> true,     // follow redirects
			CURLOPT_ENCODING       	=> "",       // handle all encodings
			CURLOPT_USERAGENT      	=> "Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/51.0.2704.103 Safari/537.36",    // who am i
			CURLOPT_AUTOREFERER    	=> true,     // set referer on redirect
			CURLOPT_CONNECTTIMEOUT 	=> 10,      // timeout on connect
			CURLOPT_TIMEOUT        	=> $timeout,      // timeout on response
			CURLOPT_FRESH_CONNECT 	=> true		// this is always asynchronous
		);
	
		$ch      = curl_init($url);
		curl_setopt_array( $ch, $options );
		$content = curl_exec( $ch );
		curl_close( $ch );
		
		return $content;
	}
	
	public function ResetDisabledDocuments($indexname)
	{
		if ( empty($indexname) )
		{
			echo "Index not defined";
			return false;
		}
		
		try
		{
			# 1. how many documents total + statistics
			$countpdo = $this->db_connection->prepare("SELECT ID FROM PMBIndexes WHERE name = ?");
			$countpdo->execute(array(mb_strtolower(trim($indexname))));
			
			if ( $row = $countpdo->fetch(PDO::FETCH_ASSOC) )
			{
				$updpdo = $this->db_connection->prepare("UPDATE PMBIndexes SET disabled_documents = NULL WHERE ID = ?");
				$updpdo->execute(array($row["ID"]));
			}
			else
			{
				# error, unknown index
				echo "Unknown index: $indexname";
				return false;	
			}
		}
		catch ( PDOException $e )
		{
			echo "An error occurred when resetting disabled documents: " . $e->getMessage();
			return false;	
		}
		
		return true;
	}
	
	public function DisableDocuments($indexname, $document_ids)
	{
		if ( empty($indexname) )
		{
			echo "Index not defined";
			return false;
		}
		
		if ( empty($document_ids) )
		{
			echo "No document ids provided";
			return false;
		}
		
		if ( !is_array($document_ids) )
		{
			echo "Document ids must be provided as an array";
			return false;
		}
		
		# ensure that all values and indexes are integers
		foreach ( $document_ids as $d_i => $doc_id ) 
		{
			if ( !$this->is_intval($doc_id) || $doc_id < 1 )
			{
				echo "All provided document ids must be positive integer values";	
				return false;
			}
			
			// convert document id to string
			$document_ids[$d_i] = "$doc_id";
		}
		
		$document_ids = array_flip($document_ids);
		
		try
		{
			# 1. how many documents total + statistics
			$countpdo = $this->db_connection->prepare("SELECT ID, disabled_documents FROM PMBIndexes WHERE name = ?");
			$countpdo->execute(array(mb_strtolower(trim($indexname))));
				
			if ( $row = $countpdo->fetch(PDO::FETCH_ASSOC) )
			{
				$index_id = (int)$row["ID"];
				
				# combine allready set disabled documents with the new provided ones
				if ( !empty($row["disabled_documents"]) )
				{
					$delta = 1;
					$len = strlen($row["disabled_documents"]);
					$binary_data = &$row["disabled_documents"];
					$temp = 0;
					$shift = 0;
				
					for ( $i = 0 ; $i < $len ; ++$i )
					{
						$bits = $this->hex_lookup_decode[$binary_data[$i]];

						if ( $bits > 127 )
						{
							$temp += $this->pow_lookup[$shift] * ($bits&127);		
							# 8th bit is set, number ends here ! 
							$delta = $temp+$delta-1;
							$document_ids["$delta"] = 1;
				
							# reset temp variables
							$temp = 0;
							$shift = 0;
						}
						else
						{
							$temp += $this->pow_lookup[$shift] * $bits;
							++$shift;
						}
					}
				}
				
				# sort document ids
				ksort($document_ids);
				
				$encoded_string = "";
				$delta = 1;
				# vbdeltaencode data
				foreach ( $document_ids as $document_id => $value ) 
				{
					$tmp = $document_id-$delta+1;
					do
					{
						$lowest7bits = $tmp&127;
						$tmp = floor($tmp / 128); // same as >> 7
						
						if ( $tmp )
						{
							$encoded_string .= $this->hex_lookup_encode[$lowest7bits];
						}
						else
						{
							$encoded_string .= $this->hex_lookup_encode[128 | $lowest7bits];
						}	
					}
					while ( $tmp );
					
					$delta = $document_id;
				}
				
				try
				{
				
					# after the values have been updated, update the database table
					$updpdo = $this->db_connection->prepare("UPDATE PMBIndexes SET disabled_documents = ? WHERE ID = ?");
					$updpdo->execute(array($encoded_string, $index_id));
				}
				catch ( PDOException $e ) 
				{
					echo "An error occurred when updating disabled documents: " . $e->getMessage();
					return false;	
				}
			}
			else
			{
				# error, unknown index
				echo "Unknown index: $indexname";
				return false;	
			}
		}
		catch ( PDOException $e ) 
		{
			echo "An error occurred when resolving index name: " . $e->getMessage();
			return false;	
		}
		
		return true;
	}
	
	private function GroupTemporaryResults()
	{
		# if grouping is enabled, temporary results will be grouped in this function
		if ( $this->groupmode > 1 && !empty($this->temp_matches) ) 
		{
			# we are this function because grouping is done on some attribute
			# but group sort is done with virual attribute ( score or sentiscore )
			$grouping_attribute = "attr_".$this->group_attr;
			$sql = "";

			foreach ( $this->temp_matches as $doc_id => $score ) 
			{
				$sql .= ",$doc_id";	
			}
			
			$sql[0] = " ";
			
			try
			{
				# disable buffered queries
				$this->db_connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
				
				# query documents
				$pdo = $this->db_connection->query("SELECT ID, $grouping_attribute FROM PMBDocinfo".$this->suffix." WHERE ID IN($sql)");
				
				$temporary_grouper = array();
				$temporary_docids  = array();
				
				while ( $row = $pdo->fetch(PDO::FETCH_ASSOC) )
				{
					$doc_id 		= +$row["ID"];
					$grouping_value = +$row[$grouping_attribute]; 
					$score 			= $this->temp_matches[$doc_id];
					
					if ( !empty($temporary_grouper[$grouping_value]) && $score >= $temporary_grouper[$grouping_value]  )
					{
						# grouping value already exists; but this is better result
						$temporary_grouper[$grouping_value] = $score;
						$temporary_docids[$grouping_value] = $doc_id;
						unset($this->temp_matches[$doc_id]);
					}
					else
					{
						# new grouping value
						$temporary_grouper[$grouping_value] = $score;
						$temporary_docids[$grouping_value] = $doc_id;
					}
				}

				# rewrite temp_matches
				foreach ( $temporary_grouper as $grouping_value => $score ) 
				{
					$doc_id = $temporary_docids[$grouping_value];
					$this->temp_matches[$doc_id] = $score;
				}
			}
			catch ( PDOException $e ) 
			{
				echo $e->getMessage();
			}
		}
	}
	
	private function utf8_to_extended_ascii($str, &$map)
	{
		// find all multibyte characters (cf. utf-8 encoding specs)
		$matches = array();
		if (!preg_match_all('/[\xC0-\xF7][\x80-\xBF]+/', $str, $matches))
			return $str; // plain ascii string
	   
		// update the encoding map with the characters not already met
		foreach ($matches[0] as $mbc)
			if (!isset($map[$mbc]))
				$map[$mbc] = chr(128 + count($map));
	   
		// finally remap non-ascii characters
		return strtr($str, $map);
	}
	
	private function levenshtein_utf8($s1, $s2)
	{
		$charMap = array();
		$s1 = $this->utf8_to_extended_ascii($s1, $charMap);
		$s2 = $this->utf8_to_extended_ascii($s2, $charMap);
	   
		return levenshtein($s1, $s2);
	}
	
	public function CompactLink($text, $max_len = 80)
	{
		if ( mb_strlen($text) > $max_len )
		{		
			$data = parse_url($text);

			if ( !empty($data['path']) )
			{
				$path_parts = array();
				foreach ( explode("/", $data['path']) as $i => $part )
				{
					if ( !empty($part) )
					{
						$path_parts[] = $part;
					}
				}
				
				# sacrifice everything but the last part of path
				$text = $data['host'] . "/.../" . end($path_parts);
				
				# add query
				if ( !empty($data['query']) )
				{
					$text .= "?".$data['query'];
				}
				
				if ( mb_strlen($text) > $max_len )
				{
					$text = mb_substr($text, 0, $max_len) . "...";
				}
			}
		}
		
		return $text;
	}
	
	public function SetIndex($indexname)
	{
		if ( empty($indexname) )
		{
			echo "Index not defined";
			return false;
		}
		
		try
		{
			# 1. how many documents total + statistics
			$countpdo = $this->db_connection->prepare("SELECT * FROM PMBIndexes WHERE name = ?");
			$countpdo->execute(array(mb_strtolower(trim($indexname))));
				
			if ( $row = $countpdo->fetch(PDO::FETCH_ASSOC) )
			{
				if ( $this->LoadSettings((int)$row["ID"]) )
				{
					$this->index_name				= $indexname;
					$this->index_id					= (int)$row["ID"];
					$this->suffix					= "_" .$row["ID"];
					$this->index_type				= (int)$row["type"];
					$this->documents_in_collection 	= (string)$row["documents"];
					$this->index_state 				= (int)$row["current_state"];
					$this->latest_indexing_done 	= (string)$row["updated"];
					$this->delta_documents		 	= 0;
					$this->disabled_documents		= $row["disabled_documents"];
					
					# backwards compatibility
					if ( !isset($row["min_id"]) || !isset($row["max_id"]) || !isset($row["max_delta_id"]) )
					{
						$valpdo = $this->db_connection->query("SELECT MIN(ID) as min_id, MAX(ID) as max_id FROM PMBDocinfo".$this->suffix);
						
						if ( $valrow = $valpdo->fetch() ) 
						{
							$this->min_doc_id = (string)$valrow["min_id"];
							$this->max_doc_id = (string)$valrow["max_id"];
						}
					}
					else
					{
						$this->min_doc_id = (string)$row["min_id"];
						
						if ( !empty($row["max_delta_id"]) && $row["max_delta_id"] > $row["max_id"] )
						{
							$this->max_doc_id = (string)$row["max_delta_id"];
						}
						else
						{
							$this->max_doc_id = (string)$row["max_id"];
						}
					}

					if ( !empty($row["delta_documents"]) )
					{
						$this->documents_in_collection += (string)$row["delta_documents"];
						$this->delta_documents			= (string)$row["delta_documents"];
					}
				}
				else
				{
					echo "Could not open index specific settings file ( settings_".$row["ID"].".php ) ";
					return false;
				}
				
			}
			else
			{
				# error, unknown index
				echo "Unknown index: $indexname";
				return false;	
			}
		}
		catch ( PDOException $e ) 
		{
			echo "An error occurred when resolving index name: " . $e->getMessage();
			return false;	
		}
		
		return true;
		
	}
	
	private function metaphone_to_int16($metaphone)
	{
		# normalize metaphone to 4 characters
		$metaphone = str_pad($metaphone, 4, " ", STR_PAD_LEFT);
		$integer = 0;
		$shift = 0;
		
		for ( $i = 0 ; $i < 4 ; ++$i ) 
		{
			$integer |= ($this->meta_lookup_encode[$metaphone[$i]] << ($shift*4));
			++$shift;
		}
		
		return $integer;
	}

	private function StemKeyword($token, &$min_len = 0, &$keyword_len = 0)
	{
		$stem = "";
		
		# if keyword stemming is disabled, ignore this step 
		if ( $this->keyword_stemming ) 
		{
			$keyword_len = mb_strlen($token);
			$min_len = $keyword_len;
			# stem keyword
			$stem_en = PMBStemmer::stem_english($token);
			$stem_fi = PMBStemmer::stem_finnish($token);
	
			$stem_en_len = mb_strlen($stem_en);
			$stem_fi_len = mb_strlen($stem_fi);
			
			# english stem
			if ( strpos($token, $stem_en) === 0 && $stem_en_len >= $this->prefix_length && $stem_en_len <= $min_len )
			{
				$min_len = $stem_en_len;
				$stem = $stem_en;
			}
					
			# finnish stem ( 
			if ( strpos($token, $stem_fi) === 0 && $stem_fi_len >= $this->prefix_length && $stem_fi_len <= $min_len && $stem_fi_len > $keyword_len/2 )
			{
				$min_len = $stem_fi_len;
				$stem = $stem_fi;
			}
			
			# if there's a minimum stem length defined ( % of the length of the original token )
			if ( $this->stem_minimum_quality ) 
			{
				# minimum length for the stem:
				$min_stem_len = ceil($keyword_len * ($this->stem_minimum_quality/100));

				if ( $min_len < $min_stem_len ) 
				{
					# the stem is too short ! 
					$stem = mb_substr($token, 0, $min_stem_len);
				}
			}
		}
		
		return $stem;
	}
	
	private function CharsetProcessing($query)
	{
		# separate letters & numbers from each other?
		if ( $this->separate_alnum ) 
		{
			$query = preg_replace('/(?<=[a-z])(?=\d)|(?<=\d)(?=[a-z])/u', ' ', $query);
		}
		
		#  remove ignore chars
		if ( !empty($this->ignore_chars) )
		{
			$query = str_replace($this->ignore_chars, "", $query);
		}

		# filter query with the charset regexp ( drops non-defined characters )
		$query = preg_replace($this->charset_regexp, " ", $query);
		
		# filter out blend chars in certain situations
		if ( !empty($this->blend_chars) )
		{
			$query = str_replace($this->blend_chars, " ", $query);
		}
		
		return $query;
	}
	
	public function MetaphoneSearch($query)
	{
		if ( trim($query) === "" )
		{
			$this->result["error"] = "Query not defined";
			return $this->result;
		}
		else if ( empty($this->current_index) )
		{
			$this->result["error"] = "Index is not defined - please call \$YourPMBInstance->SetIndex(\$index_name); before searching";
			return $this->result;	
		}

		# replace all dialect tokens
		$dialect_array = array( 'š'=>'s', 'ž'=>'z', 'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'æ'=>'a', 'ç'=>'c', 'č'=>'c', 'è'=>'e', 'é'=>'e', 
							'ê'=>'e', 'ë'=>'e', 'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ð'=>'d', 'ñ'=>'n', 'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o',
                            'ö'=>'o', 'ø'=>'o', 'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'μ' => 'u', 'ý'=>'y', 'þ'=>'b', 'ÿ'=>'y', '$' => 's', 'ü' => 'u' , 'ş' => 's',
							'ş' => 's', 'ğ' => 'g', 'ı' => 'i', 'ǐ' => 'i', 'ǐ' => 'i', 'ĭ' => 'i', 'ḯ' => 'i', 'ĩ' => 'i', 'ȋ' => 'i' );
										
		$mass_find 		= array_keys($dialect_array);
		$mass_replace 	= array_values($dialect_array);
		
		# just to be sure
		$query = mb_strtolower($query);
		
		# do charset processing
		$query = $this->CharsetProcessing($query);
		
		if ( empty($query) )
		{
			return false;
		}

		$keyword_array = explode(" ", trim($query));
		
		foreach ( $keyword_array as $keyword ) 
		{
			# original keyword's byte length
			$byte_len = strlen($keyword);
			
			# replace dialect tokens
			$stripped_keyword = str_replace($mass_find, $mass_replace, $keyword);
			
			if ( ctype_alpha($stripped_keyword) )
			{
				$keyword_len = strlen($keyword);
				
				if ( $keyword_len >= 8 ) 
				{
					$keyword_max_distances[$keyword] = 3;
				}
				else if ( $keyword_len >= 6 ) 
				{
					$keyword_max_distances[$keyword] = 2;
				}
				else if ( $keyword_len >= 4 ) 
				{
					$keyword_max_distances[$keyword] = 1;
				}
				else
				{
					# keyword is too short
					continue;
				}

				# keyword byte length	
				$byte_len_min = $byte_len-$keyword_max_distances[$keyword];
				$byte_len_max = $byte_len+$keyword_max_distances[$keyword];

				# get metaphones of this keyword
				$metaphones = double_metaphone($stripped_keyword);
				
				# get the encoded 16bit metaphone
				$encoded_metaphone = $this->metaphone_to_int16($metaphones["primary"]);
				
				if ( $encoded_metaphone !== 0 ) 
				{
					# form pairs original keyword => [16bit metaphone]
					$original_keywords[$keyword] = $encoded_metaphone;
					
					# form pairs [16bit metaphone] => original keyword
					$encoded_metaphones[$encoded_metaphone] = $keyword;
					
					# create a stemmed version of the keyword
					$keyword_stems[$encoded_metaphone] = $this->StemKeyword($keyword);
		
					# form sql query
					$metaphone_sql[$encoded_metaphone] = "(metaphone = $encoded_metaphone AND LENGTH(token) >= $byte_len_min AND LENGTH(token) <= $byte_len_max)";
				}
			}
		}
		
		if ( !empty($metaphone_sql) )
		{
			try
			{
				$pdo = $this->db_connection->query("SELECT metaphone, doc_matches, token FROM PMBTokens_" . $this->index_id . " WHERE " . implode(" OR ", $metaphone_sql));

				$sum = 0;
				$count = 0;
				$distance_sum = 0;
				
				$existing_prefix_matches = array();
				
				while ( $row = $pdo->fetch(PDO::FETCH_ASSOC) )
				{
					# original token
					$original_token = $encoded_metaphones[(int)$row["metaphone"]];
					
					if ( !isset($prefix_group_counts[$original_token]) )
					{
						$prefix_group_counts[$original_token] = 0;
					}

					# now calculate levenshtein distance, 2 max ! 
					$distance = levenshtein($row["token"], $original_token);

					# difference is too big; abort ! 
					if ( $distance > $keyword_max_distances[$original_token] ) 
					{
						continue;
					}
					
					if ( $row["token"] === $original_token ) 
					{
						# store original tokens into a specific array
						$original_match_data[$original_token] = (int)$row["doc_matches"];
						
						$existing_prefix_matches[$original_token][0] = 0;
						$existing_prefix_matches[$original_token][1] = $row["token"];
						$existing_prefix_matches[$original_token][2] = (int)$row["doc_matches"];
						
						$prefix_group_counts[$original_token] += (int)$row["doc_matches"];
						
						continue;
					}
					else
					{
						/*
						STEP 1 : pre-filtering for prefix-matches
						         if one of these prefix-matches is better than the matches later 
								 ==> suggest the prefix with smallest levenshtein distance instead
						*/
						
						if ( $this->prefix_mode )
						{
							# if prefixes are enabled, this token can be matched with them ( no need to provide a corrected suggestion ) 
							if ( strpos($row["token"], $original_token) !== false ) 
							{
								if ( isset($existing_prefix_matches[$original_token])  )
								{
									if ( $existing_prefix_matches[$original_token][0] > $distance )
									{
										$existing_prefix_matches[$original_token][0] = $distance;
										$existing_prefix_matches[$original_token][1] = $row["token"];
										$existing_prefix_matches[$original_token][2] = (int)$row["doc_matches"];
									}
									else if ( $existing_prefix_matches[$original_token][0] === $distance && $existing_prefix_matches[$original_token][2] < $row["doc_matches"] )
									{
										# distance is same, compary doc matches
										$existing_prefix_matches[$original_token][0] = $distance;
										$existing_prefix_matches[$original_token][1] = $row["token"];
										$existing_prefix_matches[$original_token][2] = (int)$row["doc_matches"];
									}
								}
								else
								{
									$existing_prefix_matches[$original_token][0] = $distance;
									$existing_prefix_matches[$original_token][1] = $row["token"];
									$existing_prefix_matches[$original_token][2] = (int)$row["doc_matches"];
								}
								
								$prefix_group_counts[$original_token] += (int)$row["doc_matches"];

								continue;
							}
							else
							{
								# no match, try stemming the keyword
								$keyword_stem = $keyword_stems[(int)$row["metaphone"]];
								
								if ( !empty($keyword_stem) && strpos($row["token"], $keyword_stem) !== false )
								{
									# stemmed version available and it is a prefix match ! 
									if ( isset($existing_prefix_matches[$original_token])  )
									{
										if ( $existing_prefix_matches[$original_token][0] > $distance )
										{
											$existing_prefix_matches[$original_token][0] = $distance;
											$existing_prefix_matches[$original_token][1] = $row["token"];
											$existing_prefix_matches[$original_token][2] = (int)$row["doc_matches"];
										}
										else if ( $existing_prefix_matches[$original_token][0] === $distance && $existing_prefix_matches[$original_token][2] < $row["doc_matches"] )
										{
											# distance is same, compary doc matches
											$existing_prefix_matches[$original_token][0] = $distance;
											$existing_prefix_matches[$original_token][1] = $row["token"];
											$existing_prefix_matches[$original_token][2] = (int)$row["doc_matches"];
										}
									}
									else
									{
										$existing_prefix_matches[$original_token][0] = $distance;
										$existing_prefix_matches[$original_token][1] = $row["token"];
										$existing_prefix_matches[$original_token][2] = (int)$row["doc_matches"];
									}
									
									$prefix_group_counts[$original_token] += (int)$row["doc_matches"];
									
									continue;
								}
							}
						}					
					}
					
					/*
						STEP 3: if we are here, the token is not a prefix match ( or prefixing is disabled )  and must still be considered as a valid alternative
					*/
					
					$distance_sum += $distance;
					$sum += (int)$row["doc_matches"];	
					++$count;

					if ( !isset($alt_tokens[$original_token]) ) 
					{
						$alt_tokens[$original_token] = array();
						$alt_tokens[$original_token][] 	= array($distance, $row["token"], (int)$row["doc_matches"]);
					}
					else
					{
						$alt_tokens[$original_token][] 	= array($distance, $row["token"], (int)$row["doc_matches"]);
					}
					
				}
		
				$final_suggestions = array();
				
				# this specific number tells how many "virtual" document matches at least each token has if found in the index
				# prevents suggestions with too small document count difference
				# example: original 1 match, suggestion 3 matches
				$minimum_doc_matches = round(pow($this->documents_in_collection, 1/8));

				foreach ( $original_keywords as $original_token => $metaphone ) 
				{
					if ( !empty($alt_tokens[$original_token]) )
					{
						# use an anonymous function to sort the multidimensional array
						usort($alt_tokens[$original_token], function($a, $b) {
							return $a[0] - $b[0];
						});
						
						# contains expanded matches ( original + all prefix matches )
						if ( isset($this->final_doc_matches[$original_token]) )
						{
							$original_match_count = $this->final_doc_matches[$original_token];
							$max_matches = $original_match_count;
						}
						# contains original token matches 
						else if ( isset($original_match_data[$original_token]) )
						{
							$original_match_count = $original_match_data[$original_token];
							
							# if the original token is a match, but resultcount is very small
							if ( $original_match_count <= $minimum_doc_matches ) 
							{
								$max_matches = $minimum_doc_matches;
							}
							# otherwise act normally
							else
							{
								$max_matches  = $original_match_count;
							}
							
							# metaphone matches that are also prefix matches
							$max_matches += $prefix_group_counts[$original_token];
						}
						else
						{
							# no original matches found, smaller amount of matches suffices
							$original_match_count = 1;
							$max_matches = 1;
							
							# metaphone matches that are also prefix matches
							$max_matches += $prefix_group_counts[$original_token]; 
						}

						$min_distance = 20;

						foreach ( $alt_tokens[$original_token] as $data_values ) 
						{
							$distance 				= $data_values[0];
							$alternative_token 		= $data_values[1];
							$alternative_matchcount = $data_values[2];

							# score_limit_lookup will be dynamically calculated
							if ( !isset($score_limit_lookup[$distance]) )
							{
								$score_limit_lookup[$distance] = pow($max_matches, 1+$distance);
							}

							# compare against prefix matches ( if availabe ) 
							if ( isset($existing_prefix_matches[$original_token]) )
							{
								$p_len 			= $existing_prefix_matches[$original_token][0];
								$p_token 		= $existing_prefix_matches[$original_token][1];
								$p_doc_matches 	= $existing_prefix_matches[$original_token][2];
								
								if ( $p_len <= $min_distance && $p_len <= $distance && $p_doc_matches >= $alternative_matchcount && $p_doc_matches >= $max_matches )
								{
									# the actual doc_matches count is not important, because documents will be matched through prefix matching ( over many keywords ) 
									# only distance is importantken: $p_token current: $alternative_token) ";
									
									# overwrite values 
									if ( $original_token !== $p_token ) 
									{
										$final_suggestions[$original_token] = $p_token;	
									}
									
									continue;
								}
							}
							
							# assign a new better value
							if ( $alternative_matchcount >= $score_limit_lookup[$distance] && $alternative_matchcount > $max_matches ) 
							{
								if ( $original_token !== $alternative_token )
								{
									# maybe use another if clause for these two 
									$min_distance = $distance;
									$max_matches  = $alternative_matchcount;
									
									$final_suggestions[$original_token] = $alternative_token;	
								}
							}
						}		
					}
					else if ( !isset($original_match_data[$original_token]) && isset($existing_prefix_matches[$original_token]) ) 
					{
						# exception 1: 
						# original provided token does not return any results ( as strict match ) 
						# no alternative tokens present
						# prefix matches present ( non-stemmed !!! )
						# even if the keyword would return results, try suggesting a better alternative... ( the best prefix match! ) 
						# distance must not exceed 
						$final_suggestions[$original_token] = $existing_prefix_matches[$original_token][1];	
					}
				}

				return $final_suggestions;
				
			}
			catch ( PDOException $e ) 
			{
				$this->result["error"] = "Something went wrong while investigating metaphones. Error message: ".$e->getMessage();
			}
		}
		
		return false;
	}
	
	public function Search($query, $offset = 0, $limit = 10)
	{
		$this->query_start_time = microtime(true);
		
		# reset query statistics
		$this->result = array();
		$this->result["matches"]		= array();
		$this->result["total_matches"] 	= 0;
		$this->result["error"]			= "";
		$this->result["warning"]		= "";
		$this->final_doc_matches		= array();
		
		if ( trim($query) === "" )
		{
			$this->result["error"] = "Query not defined";
			return $this->result;
		}
		else if ( empty($this->current_index) )
		{
			$this->result["error"] = "Index is not defined - please call \$YourPMBInstance->SetIndex(\$index_name); before searching";
			return $this->result;	
		}
		
		# create a lookup variable for enabled fields
		$i = 0;
		$this->enabled_fields = 0;
		$all_fields 	      = 0;
		foreach ( $this->field_weights as $field_score ) 
		{
			if ( $field_score > 0 ) 
			{
				$this->enabled_fields |= (1 << $i);
			}
			$all_fields |= (1 << $i);
			++$i;
		}
		
		if ( $this->enabled_fields === 0 ) 
		{
			$this->result["error"] = "All data fields are disabled. At least one field must have non-zero field weight.";
			return $this->result;
		}
		
		if ( !empty($this->main_sql_attrs) )
		{
			$main_sql_attrs = array_flip($this->main_sql_attrs);
		}
		else
		{
			$main_sql_attrs = array();
		}
		
		# attribute based grouping enabled
		# check that proper attributes have been provided
		if ( !empty($this->group_attr) )
		{
			$combined_attrs = $main_sql_attrs + array("@sentiscore" => 1, "@score" => 1, "@id" => 1);
			
			$grouping_attributes = $main_sql_attrs;

			if ( $this->index_type === 1 ) 
			{
				$grouping_attributes 	= $combined_attrs + array("domain" => 1, "timestamp" => 1, "category" => 1);
				$combined_attrs 		= $combined_attrs + array("domain" => 1, "timestamp" => 1, "category" => 1);
			}

			# grouping attribute must be external
			if ( !isset($grouping_attributes[$this->group_attr]) )
			{
				$this->result["error"] = "Invalid grouping attribute: " . $this->group_attr;
				return $this->result;
			}
			# group sort attribute can be internal or external
			else if ( !isset($combined_attrs[$this->group_sort_attr]) )
			{
				$this->result["error"] = "Invalid group sort attribute: " . $this->group_sort_attr;
				return $this->result;
			}
		}
		
		# attribute based sorting is enabled
		# check that provided attribute is indeed valid
		if ( !empty($this->sort_attr) )
		{
			$combined_attrs = $main_sql_attrs + array("@count" => 1, "@score" => 1, "@id" => 1);
			
			if ( $this->index_type === 1 ) 
			{
				$combined_attrs = $combined_attrs + array("domain" => 1, "timestamp" => 1, "category" => 1);
			}
			
			# sorting attribute can be either an external attribute or one of the two internal attributes
			if ( !isset($combined_attrs[$this->sort_attr]) )
			{
				$this->result["error"] = "Invalid sorting attribute: " . $this->sort_attr;
				return $this->result;
			}
			
			# special case: sorting by grouped result set virtual @count attribute, but grouping is not enabled
			if ( $this->sort_attr === "@count" && empty($this->group_attr) )
			{
				$this->result["error"] = "group by must be enabled when sorting by @count ";
				return $this->result;
			}
		}
		
		if ( !empty($this->filter_by) )
		{
			foreach ( $this->filter_by as $column => $filter_value_list ) 
			{
				if ( $column[0] === "!" )
				{
					$column = substr($column, 1);
				}
				
				if ( !isset($main_sql_attrs[$column]) )
				{
					$this->result["error"] = "Invalid filter_by column: $column";
					return $this->result;
				}
			}
		}
		
		if ( !empty($this->filter_by_range) )
		{
			foreach ( $this->filter_by_range as $column => $filter_value_list ) 
			{
				if ( $column[0] === "!" )
				{
					$column = substr($column, 1);
				}
				
				if ( !isset($main_sql_attrs[$column]) )
				{
					$this->result["error"] = "Invalid filter_by_range column: $column";
					return $this->result;
				}
			}
		}
		
		# ensure that user has provided a correct sorting mode
		if ( !isset($this->allowed_sort_modes[$this->index_type][$this->sortmode]) )
		{
			# error, unknown sorting mode
			$this->result["error"] = "Unsupported sorting mode: " . $this->sortmode;
			return $this->result;	
		}
		
		# ensure that user has provided a correct grouping mode
		if ( !isset($this->allowed_grouping_modes[$this->index_type][$this->groupmode]) )
		{
			# error, unknown sorting mode
			$this->result["error"] = "Unsupported grouping mode: " . $this->groupmode;
			return $this->result;	
		}
		
		if ( ($this->sortmode === PMB_SORTBY_POSITIVITY 	|| 
			  $this->sortmode === PMB_SORTBY_NEGATIVITY 	|| 
			  $this->sort_attr === "@sentiscore" 			|| 
			  $this->group_sort_attr === "@sentiscore") 	&&
			 !$this->sentiment_analysis ) {
				  
			 # sentiment sorting/grouping mode is enabled
			 # but this index does not support sentiment analysis !
			 $this->result["error"] = "Index " . $this->index_name .  " does not support sorting/grouping results by sentiment";
			 return $this->result;	
		}

		if ( !$this->is_intval($offset) || $offset < 0 ) 
		{
			$offset = 0;
			$this->result["warning"] = "offset must be an integer with minimum value of 0";
		}
		
		if ( !$this->is_intval($limit) || $limit < 1 ) 
		{
			$limit = 10;
			$this->result["warning"] = "limit must be an integer with minimum value of 1";
		}
		
		# create field weight lookup
		$weighted_score_lookup = $this->CreateFieldWeightLookup();
		
		# count set bits for each index of weight_score_lookup -table
		foreach ( $weighted_score_lookup as $wi => $wscore )
		{
			$weighted_bit_counts[$wi] = substr_count(decbin($wscore), "1");
		}

		$query = mb_strtolower($query);

		# if incoming query contains special characters that are not present in the current charset
		# try replacing them with corresponding values provided before
		if ( $this->dialect_matching && !empty($this->mass_find) )
		{
			$query = str_replace($this->mass_find, $this->mass_replace, $query);
		}

		# do charset processing
		$query = $this->CharsetProcessing($query);

		# the amount of double quotes & brackets found from the search query must be an even number
		$quote_count_even 	= ( substr_count($query, "\"") % 2 === 0 );
		$bracket_count_even	= substr_count($query, "(") === substr_count($query, ")");
		$strict_matching_enabled = ( $quote_count_even && $bracket_count_even );
		
		$find_chars = array("\"", "(", ")");
		$repl_chars = array(" \" ", " ( ", " ) ");

		$query_with_quotes = trim(str_replace($find_chars, $repl_chars, $query)); # make sure the double quotes / brackets are separated from other characters
		$query = trim(str_replace(array("\"", "(", ")"), " ", $query));

		# if trimmed query is empty
		if ( $query === "" )
		{
			return $this->result;
		}
		
		# explode the given search query
		$token_array = explode(" ", $query_with_quotes);

		$token_sql = array();
		$token_sql_stem = array();
		$token_escape = array();
		$token_order = array();
		$dialect_tokens = array();
		$duplicate_tokens = array();

		$real_token_order				= array();
		$real_token_order_quote_status 	= array();
		$token_group_pairing_index 		= 0;
		$real_non_wanted_tokens			= array();
		
		$non_wanted_count = 0;
		$tc = 0;
		$tsc = 0;
		$keyword_pairs 	= array();	# [stem] => "original keyword" pairs
		$quoted_area 	= false;	# denotes a "quoted area"
		$bracketed_area = false;	# denotes a (bracketed area)

		# tokenize query
		foreach ( $token_array as $i => $token ) 
		{
			$token = trim($token);
			
			if ( $token === "\"" )
			{
				if ( $strict_matching_enabled )
				{
					if ( !$quoted_area ) 
					{
						# if a quoted area is starting now, increase $token_group_pairing_index counter
						++$token_group_pairing_index;
					}
					
					# switch quoted area on/off state
					$quoted_area = !$quoted_area;
				}
				
				continue;
			}
			
			if ( $token === "(" ) 
			{
				if ( $strict_matching_enabled && !$quoted_area )
				{
					# bracketed area cannot start inside a quoted area 
					# if a bracketed area is starting now, increase $token_group_pairing_index counter
					++$token_group_pairing_index;
					# set bracketed area on
					$bracketed_area = true;
				}
				
				continue;
			}
			
			if ( $token === ")" ) 
			{
				if ( $strict_matching_enabled )
				{
					# set bracketed area off
					$bracketed_area = false;
				}
				
				continue;
			}

			# create both SQL clauses ( tokens + prefixes ) at the same time
			if ( isset($token) && $token !== "" && $token !== "-" )
			{
				$token = "$token"; # ensure the token is in string format
				$non_wanted_temp = false;
				$disable_stemming_temp = false;
				
				# non wanted keyword?
				if ( $token[0] === "-"  ) 
				{
					$token = mb_substr($token, 1); # trim
					$non_wanted_keywords[$token] = 1;
					$non_wanted_temp = true; 
					++$non_wanted_count;
				}
				# keyword that is not to be stemmed
				else if ( mb_substr($token, -1) === "*" ) 
				{
					$token = mb_substr($token, 0, -1); # trim
					$disable_stemming_temp = true; # disable stemming for this keyword
				}
				
				$real_token_order[] = $token;
				$real_token_order_quote_status[] = ( $quoted_area || $bracketed_area ) ? $token_group_pairing_index : 0;
				$real_non_wanted_tokens[] = $non_wanted_temp;

				if ( isset($token_match_count[$token]) ) 
				{
					# already processed
					# update/increase duplicate token count
					++$duplicate_tokens[$token];
					continue;
				}
				
				# if we are inside a quoted area, set the token as an exact word 
				if ( $quoted_area ) 
				{
					$exact_words[$token] = 1;
				}
				
				$token_order[] = $token;
				$token_match_count[$token] = 0; # token matchcount from db
				$keyword_pairs[$token] = $token;

				$token_sql[] = "(checksum = CRC32(:tok$tc) AND token = :tok$tc)";
				$token_escape[":tok$tc"] = $token;
				++$tc;

				# by default, this token doesn't have duplicates
				$duplicate_tokens[$token] = 0;
				
				# if dialect matching is enabled
				if ( $this->dialect_matching && !empty($this->dialect_find) && !is_numeric($token) ) 
				{
					$nodialect = str_replace($this->dialect_find, $this->dialect_replace, $token);
					
					if ( $nodialect !== $token ) 
					{
						$token_sql_stem[] = "CRC32(:sum$tsc)";
						$token_escape_stem[":sum$tsc"] = $nodialect;
						++$tsc;

						$checksum_lookup[$this->uint_crc32_string($nodialect)] = $nodialect;

						$keyword_pairs[$nodialect] = $token;
						$dialect_tokens[$nodialect] = 1;
						
						# non wanted keyword?
						if ( $non_wanted_temp ) 
						{
							$non_wanted_keywords[$nodialect] = 2;
							$token_match_count[$nodialect] = 0;	
						}
					}
				}
				
				$keyword_len = 0;
				$min_len = $keyword_len;
				$stem = "";

				# if keyword stemming is disabled, ignore this step 
				if ( $this->keyword_stemming && !$disable_stemming_temp ) 
				{
					$stem = $this->StemKeyword($token, $min_len, $keyword_len);
				}

				# a stemmed version is available
				if ( !empty($stem) && $min_len < $keyword_len )
				{
					# add both keyword and the stem
					$token_sql_stem[] = "CRC32(:sum$tsc)";
					$token_escape_stem[":sum$tsc"] = $stem;
					++$tsc;
					
					$token_sql_stem[] = "CRC32(:sum$tsc)";
					$token_escape_stem[":sum$tsc"] = $token;
					++$tsc;
					
					$checksum_lookup[$this->uint_crc32_string($stem)] = $stem;
					$checksum_lookup[$this->uint_crc32_string($token)] = $token;
					
					# non wanted keyword?
					if ( $non_wanted_temp ) 
					{
						$non_wanted_keywords[$stem] = 2;
						$token_match_count[$stem] = 0;	
					}
						
					$keyword_pairs[$stem] = $token;
				}
				# no stemmed version available
				else if ( $min_len >= $this->prefix_length && !$disable_stemming_temp )
				{
					# only add the original
					$token_sql_stem[] = "CRC32(:sum$tsc)";
					$token_escape_stem[":sum$tsc"] = $token;
					++$tsc;
					
					$checksum_lookup[$this->uint_crc32_string($token)] = $token;	
				}	
			}
		}
		
		# do we have duplicate keywords present or not?
		$duplicate_keywords_present = max($duplicate_tokens);

		# copy token_escape
		$non_stemmed_keywords = array_unique($token_escape);
		
		$switch_typecase = "(CASE token ";
		$temp = array();
		$c = 0;
		foreach ( $non_stemmed_keywords as $nonstem ) 
		{
			$switch_typecase .= "WHEN :case$c THEN 0 ";
			$token_escape[":case$c"] = $nonstem;
			++$c;
		}
		$switch_typecase .= " ELSE 1 END) as type";

		# no keywords ; incorrect query
		if ( empty($token_escape) )
		{
			return $this->result;
		}

		$find = array("PMBTokens", "PMBPrefixes", "PMBDocinfo", "PMBCategories");
		$repl = array("PMBTokens".$this->suffix, 
						"PMBPrefixes".$this->suffix, 
						"PMBDocinfo".$this->suffix, 
						"PMBCategories".$this->suffix);
			
		$start_end_time = microtime(true) - $this->query_start_time;
				
		try
		{
			# fetch prefix hits
			if ( !empty($token_escape_stem) )
			{
				# later on token groups are stored as chars ( 8bit integers )
				# thats why maximum number of tokens ( originals and expanded ones ) combined is 256
				if ( $this->expansion_limit + $tc > 255 )
				{
					$this->expansion_limit = 255 - $tc;
				}

				$prefix_time_start = microtime(true);
				$prefix_grouper = array();
				$prefix_data = array();
				
				if ( $this->delta_documents > 0 ) 
				{
					$prefix_sql = "(
									SELECT checksum, tok_data FROM PMBPrefixes".$this->suffix." WHERE checksum IN(" . implode(",", $token_sql_stem) . ")
								   )
								   UNION ALL
								   (
								    SELECT checksum, tok_data FROM PMBPrefixes".$this->suffix."_delta WHERE checksum IN(" . implode(",", $token_sql_stem) . ")
								   )";
				}
				else
				{
					$prefix_sql = "SELECT checksum, tok_data FROM PMBPrefixes".$this->suffix." WHERE checksum IN(" . implode(",", $token_sql_stem) . ")";	
				}
				
				$ppdo = $this->db_connection->prepare($prefix_sql);
				$ppdo->execute($token_escape_stem);	
				
				$keyword_len_lookup = array();
				foreach ( $checksum_lookup as $checksum => $token ) 
				{
					$keyword_len_lookup[$checksum] = mb_strlen($token);
				}

				while ( $row = $ppdo->fetch(PDO::FETCH_ASSOC) )
				{
					$prefix_checksum	= $row["checksum"];
					$keyword_len		= $keyword_len_lookup[$prefix_checksum];

					$tok_checksums 	= array();
					$tok_cutlens 	= array();
	
					$len 			= strlen($row["tok_data"]);
					$binary_data 	= &$row["tok_data"];
					$delta 			= 1;
					$temp 			= 0;
					$shift 			= 0;
				
					for ( $i = 0 ; $i < $len ; ++$i )
					{
						$bits = $this->hex_lookup_decode[$binary_data[$i]];

						if ( $bits > 127 )
						{
							// this reads as temp = (bits&127) << shift*7 | temp
							$temp += $this->pow_lookup[$shift] * ($bits&127);		
							# 8th bit is set, number ends here ! 
							$delta = $temp+$delta-1;

							# how many characters were cut (token->prefix)
							$lowest6 = +$delta & 63;
							
							if ( $this->prefix_minimum_quality ) 
							{
								# calculate score for the current prefix match 
								$matching_token_len = $keyword_len + $lowest6;
								$percentage = round($keyword_len / $matching_token_len * 100);

								if ( $percentage >= $this->prefix_minimum_quality ) 
								{
									# shift to right 6 bits
									# checksum of the token that this prefix points to
									$tok_checksums[] = (string)floor($delta / 64); // same as >> 6	
									$tok_cutlens[] = $lowest6;
								}
							}
							else
							{
								# shift to right 6 bits
								# checksum of the token that this prefix points to
								$tok_checksums[] = (string)floor($delta / 64); // same as >> 6	
								$tok_cutlens[] = $lowest6;
							}
				
							# reset temp variables
							$temp = 0;
							$shift = 0;
						}
						else
						{
							$temp += $this->pow_lookup[$shift] * $bits;
							++$shift;
						}
					}

					# sort according to current prefixes cut lengths
					asort($tok_cutlens);
						
					$x = 0;
					foreach ( $tok_cutlens as $i => $cutlen ) 
					{
						# checksum of the token that this prefix points to
						$prefix_data[$tok_checksums[$i]] = $cutlen;
						$prefix_grouper[$tok_checksums[$i]] = $checksum_lookup[$prefix_checksum];
						++$x;	
					}
				}
				
				# sort the combined data again
				asort($prefix_data);
				
				$i = 0;
				foreach ( $prefix_data as $checksum => $cutlen ) 
				{
					if ( $i >= $this->expansion_limit )
					{
						break;
					}
					
					# checksum === checksum of the token that this prefix points to
					$token_sql[] = "(checksum = :sumadd$i AND token LIKE CONCAT('%', :tokadd$i, '%'))";
					
					$token_escape[":sumadd$i"] = $checksum;
					$token_escape[":tokadd$i"] = $prefix_grouper[$checksum];
					
					if ( !empty($non_wanted_keywords[$prefix_grouper[$checksum]]) )
					{
						$non_wanted_checksums[$checksum] = $prefix_grouper[$checksum];
					}

					++$i;
				}
	
				$this->result["stats"]["prefix_time"] = microtime(true) - $prefix_time_start;
			}
			
			# run indexer if conditions allow it
			# auto indexing is enabled, indexer has not been run too recently, indexer is not already running
			if ( $this->indexing_interval && ( time() - ($this->indexing_interval*60) ) > $this->latest_indexing_done && !$this->index_state )
			{
				# run the indexer ! 
				if ( $this->enable_exec ) 
				{
					$this->execInBackground("php " . realpath(dirname(__FILE__)) . "/indexer.php index_id=".$this->index_id);
				}
				else
				{
					# async curl
					$url_to_exec = "http://localhost" . str_replace("PMBApi.php", "indexer.php", $_SERVER['SCRIPT_NAME']) . "?index_id=".$this->index_id;
					$this->execWithCurl($url_to_exec);
				}
			}
	
			$token_order_rev = array_flip($token_order);
			ksort($token_order_rev);

			$exact_group_pairing_lookup = 0;
			$exact_group_pairing_lookup_copy = 0;

			if ( $strict_matching_enabled ) 
			{
				$max_i = count($real_token_order) - 1;
				# we found an even amount of double quotes
				foreach ( $real_token_order_quote_status as $i => $status ) 
				{
					if ( empty($real_non_wanted_tokens[$i])  ) 
					{
						$next_i = $i + 1;
						if ( $next_i <= $max_i && $status && $status === $real_token_order_quote_status[$next_i] ) 
						{
							# this forms a token pair ! 
							# only set the bit denoting the first/earlier group
							$exact_group_pairing_lookup |= (1 << $i);
						}
					}
				}
			}

			$disable_score_calculation = false;
			if ( isset($this->non_scored_sortmodes[$this->sortmode]) && 
				$this->group_sort_attr 	!== "@score" 				 && 
				$this->group_sort_attr 	!== "@sentiscore"			 && 
				$this->sort_attr		!== "@score" ) {
					
				$disable_score_calculation = true;
			}
			
			# sorting by external attribute, no keyword order requirements
			if ( !$exact_group_pairing_lookup && $disable_score_calculation && $this->matchmode !== PMB_MATCH_STRICT && $this->enabled_fields === $all_fields )
			{
				$fast_external_sort = true;
			}
			else
			{
				$fast_external_sort = false;
			}
			
			# sorting by external attribute, keyword order requirements apply
			if ( ($exact_group_pairing_lookup || $this->enabled_fields !== $all_fields) && $disable_score_calculation )
			{
				$external_sort = true;
			}
			else
			{
				$external_sort = false;
			}

			if ( $this->matchmode === PMB_MATCH_STRICT )
			{
				$strict_match_cmp_value = 1;	
			}
			else
			{
				$strict_match_cmp_value = 0;	
			}

			# special case: if sorting by @id and no grouping is enabled, 
			if ( $disable_score_calculation && $this->sort_attr === "@id" && $this->groupmode === 1 )
			{
				if ( $this->sortdirection === "desc" ) 
				{
					# descending order
					$decode_descend = true;
				}
				else
				{
					# ascending order
					$decode_ascend = true;
				}
				
				# in any case, external_sort is true ( score calculation not needed ) 
				$external_sort = true;
				
				# how many results are needed to satisfy the fast sorting mode's requirements
				$fast_ext_sort_req_count = $offset + $limit;
				
				# if we are sorting by internal @id
				$id_sort_enabled = true;			
			}
			else
			{
				# default sorting mode is ascending
				$decode_ascend = true;
				$fast_ext_sort_req_count = PHP_INT_MAX; # fast sorting is not enabled in this normal mode
			}

			$tic = 0;			
			$sumdata = array();
			$sumcounts = array();
			$bin_separator = $bin_sep = pack("H*", "80");
			
			$payload_start = microtime(true);
			
			# switch to unbuffered mode
			$this->db_connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

			if ( !empty($non_wanted_keywords) )
			{
				$token_positions_sql = "(CASE checksum";
				$n = 0;
				foreach ($non_wanted_keywords as $non_wanted_kword => $tval ) 
				{
					$token_positions_sql .= " WHEN :nonw$n THEN ''";
					$token_escape[":nonw$n"] = $this->uint_crc32_string($non_wanted_kword);
					++$n;
				}
				
				if ( !empty($non_wanted_checksums) ) 
				{
					foreach ( $non_wanted_checksums as $nchecksum => $tval ) 
					{
						$token_positions_sql .= " WHEN :nonw$n THEN ''";
						$token_escape[":nonw$n"] = $nchecksum;
						++$n;
					}
				}
						
				$token_positions_sql .= " ELSE SUBSTR(doc_ids FROM LOCATE(:bin_sep, doc_ids)+1) END) AS token_positions";
			}
			else if ( $fast_external_sort ) 
			{
				# no need to return document match position data
				# just return an empty string
				$token_positions_sql = "'' AS token_positions";
			}
			else
			{
				# return full document match position data 
				$token_positions_sql = "SUBSTR(doc_ids FROM LOCATE(:bin_sep, doc_ids)+1) as token_positions";
			}
			
			$token_times_sql = "'' as token_times,";
			if ( $this->sortmode === PMB_SORTBY_MODIFIED )
			{
				# special case: fetch the token_times column from PMBtokens
				$token_times_sql = "token_times,";
			}

			$max_dox_id_column = "";
			if ( isset($decode_descend) )
			{
				# the max doc id value is needed
				$max_dox_id_column = "max_doc_id,";
			}
			
			if ( $this->delta_documents > 0 ) 
			{
				$token_main_sql = "(
									SELECT token, 
									$switch_typecase, 
									doc_matches,
									$max_dox_id_column
									SUBSTR(doc_ids, 1, LOCATE(:bin_sep, doc_ids)-1) as doc_ids, 
									$token_times_sql
									$token_positions_sql
									FROM PMBTokens".$this->suffix." WHERE " . implode(" OR ", $token_sql) . "
								)
								UNION ALL
								(
									SELECT token, 
									$switch_typecase, 
									doc_matches, 
									$max_dox_id_column
									SUBSTR(doc_ids, 1, LOCATE(:bin_sep, doc_ids)-1) as doc_ids, 
									$token_times_sql
									$token_positions_sql
									FROM PMBTokens".$this->suffix."_delta WHERE " . implode(" OR ", $token_sql) . "
								)";
			}
			else
			{
				$token_main_sql = "SELECT token, 
									$switch_typecase, 
									doc_matches,
									$max_dox_id_column
									SUBSTR(doc_ids, 1, LOCATE(:bin_sep, doc_ids)-1) as doc_ids, 
									$token_times_sql
									$token_positions_sql
									FROM PMBTokens".$this->suffix." WHERE " . implode(" OR ", $token_sql);
			}

			$token_escape[":bin_sep"] = $bin_separator;

			$tokpdo = $this->db_connection->prepare($token_main_sql);
			$tokpdo->execute($token_escape);
		
			unset($token_escape, $prefix_data, $token_sql, $tok_checksums, $tok_cutlens);

			# group results by token_id and select the min(distance) as keyword score modifier 
			while ( $row = $tokpdo->fetch(PDO::FETCH_ASSOC) )
			{	
				$token = $row["token"]; 
				$token_source = (int)$row["source"];
				
				# track token source (main vs delta) if we have a delta index
				if ( $this->delta_documents > 0 ) 
				{
					if ( empty($token_source_table[$token]) ) 
					{
						$token_source_table[$token] = 0;
					}
					$token_source_table[$token] |= 1 << $token_source;
				}

				# an exact match 
				if ( $row["type"] == 0 && empty($dialect_tokens[$token]) ) 
				{
					$token_score = 1;
					++$token_match_count[$token];
					$token_order_number = $token_order_rev[$token];

					# group results by the original order number of provided tokens
					if ( empty($sumdata[$token_order_number]) )
					{
						$sumdata[$token_order_number] = array();
						$sumcounts[$token_order_number] = 0;
					}
			
					$sumdata[$token_order_number][] = $token; # store as int because exact match
					$sumcounts[$token_order_number] += $row["doc_matches"]; # store how many doc_matches this token has
				}
				# prefix match
				else
				{	
					# same token already matches
					if ( !empty($token_match_count[$token]) )
					{
						# already matches
						continue;
					}
					
					$token_crc32 = $this->uint_crc32_string($token);
					
					if ( empty($prefix_grouper[$token_crc32]) )
					{
						continue;
					}
					$current_prefix = $prefix_grouper[$token_crc32];
					
					# if defined as an exact keyword, do not add prefix matches for the token
					if ( isset($exact_words[$current_prefix]) )
					{
						continue;
					}

					$token_len 		= mb_strlen($token);
					$min_dist 		= $this->levenshtein_utf8($current_prefix, $token);
					$original_dist 	= $this->levenshtein_utf8($keyword_pairs[$current_prefix], $token);
					
					if ( $original_dist < $min_dist ) 
					{
						$min_dist = $original_dist;
					}
					
					# calculate token score
					$token_score = round(($token_len - $min_dist) / $token_len, 3);
					$keyword_pairs[$token] = $keyword_pairs[$current_prefix];
					$token_order_number = $token_order_rev[$keyword_pairs[$token]];
				
					# compare prefixes against keyword_pairs and find and  match [prefix]
					if ( empty($sumdata[$token_order_number]) )
					{
						$sumdata[$token_order_number] = array();
						$sumcounts[$token_order_number] = 0;
					}
	
					# do not overwrite better results ! 
					$sumdata[$token_order_number][] = $token;
					$sumcounts[$token_order_number] += $row["doc_matches"];	
					
					++$token_match_count[$keyword_pairs[$token]];
					
					# if the search query has duplicate tokens, we need to add this prefix match into the results
					if ( !empty($duplicate_tokens[$current_prefix]) ) 
					{
						# get corrent amount of duplicates directly from the user inputted keyword
						$duplicate_tokens[$token] = $duplicate_tokens[$current_prefix];
					}
				}
				
				# score lookup table for fuzzy matches
				$score_lookup_alt[] = (float)$token_score;

				$doc_id_data_len		= strlen($row["doc_ids"]);			# length of document id data
				$token_pos_data_len		= strlen($row["token_positions"]);  # length of token position data 
				$token_time_data_len	= strlen($row["token_times"]);  	# length of token time data

				$encoded_data[] 	= $row["doc_ids"]; 					# the actual document id data
				$doc_match_data[] 	= $row["token_positions"];			# the actual token position data
				$token_time_data[]	= $row["token_times"];				# individual token timestamps per doc_id
					
				# if we are decoding results in "reverse" mode
				if ( isset($decode_descend) )
				{
					$maximum_doc_ids_vals[] = (string)$row["max_doc_id"];
					$encode_delta[] 	= (string)$row["max_doc_id"];
					$encode_pointers[] 	= $doc_id_data_len-1;
					$doc_pos_pointers[] = $token_pos_data_len;
					
					# predecode the first char from docids & token match data
					$encode_temp_docs[]	= $this->hex_lookup_decode[$row["doc_ids"][$doc_id_data_len-1]]&127;
				}
				# if results are decoded in normal mode
				else
				{
					$encode_delta[] 		= 1;
					$encode_pointers[] 		= 0;
					$doc_pos_pointers[] 	= 0;
					$lengths[] 				= $doc_id_data_len;
					$decode_pointer_time[] 	= 0;
				}

				$avgs[] 				= $token_pos_data_len/+$row["doc_matches"];
				$doc_lengths[] 			= $token_pos_data_len;
				$time_data_lengths[]	= $token_time_data_len;
				$undone_values[]		= 0;
				$db_token_list[]		= $token;

				++$tic;
			}

			# check if there were unwanted keywords that didn't provide any database matches
			if ( !empty($non_wanted_keywords) ) 
			{
				foreach ( $non_wanted_keywords as $unwanted_keyword => $unwanted_type ) 
				{
					# this unwanted keyword doesn't have any db matches and it's not a stem
					# we need to remove if from the original search query & associated arrays/variables
					# for that the keyword groups form correctly
					if ( empty($token_match_count[$unwanted_keyword]) && $unwanted_type === 1 ) 
					{
						foreach ( $real_token_order as $i => $token ) 
						{
							if ( $unwanted_keyword === $token ) 
							{
								unset($real_token_order[$i]);
								unset($real_token_order_quote_status[$i]);
								unset($real_non_wanted_tokens[$i]);
								unset($token_order[$i]);
								unset($token_match_count[$token]);
								unset($token_order_rev[$token]);
								unset($keyword_pairs[$token]);
								unset($duplicate_tokens[$token]);
								
								--$non_wanted_count; # important
							}
						}
							
						# "re-index" linear arrays 
						$real_token_order 				= array_values($real_token_order);
						$real_token_order_quote_status 	= array_values($real_token_order_quote_status);
						$real_non_wanted_tokens 		= array_values($real_non_wanted_tokens);
						$token_order 					= array_values($token_order);
					}
				}
				
				# recreate strict match lookup variable after altering (deleting) keyword group(s)
				if ( $strict_matching_enabled ) 
				{
					$exact_group_pairing_lookup = 0;
					$max_i = count($real_token_order) - 1;
					# we found an even amount of double quotes
					foreach ( $real_token_order_quote_status as $i => $status ) 
					{
						if ( empty($real_non_wanted_tokens[$i])  ) 
						{
							$next_i = $i + 1;
							if ( $next_i <= $max_i && $status && $status === $real_token_order_quote_status[$next_i] ) 
							{
								# this forms a token pair ! 
								# only set the bit denoting the first/earlier group
								$exact_group_pairing_lookup |= (1 << $i);
							}
						}
					}
				}
			}

			# duplicate/repeat the database data for duplicate tokens, because the db returned the data only once per keyword
			if ( $duplicate_keywords_present ) 
			{
				# handle tokens fetched from both PMBTokens & PMBTokens_delta
				if ( !empty($token_source_table) ) 
				{
					foreach ( $token_source_table as $token => $token_source_bits ) 
					{
						# if bits 0 & 1 are set && this is a duplicate token
						if ( $token_source_bits === 3 && !empty($duplicate_tokens[$token]) ) 
						{
							# double the amount of duplicate tokens to accommodate the presence of token match from the delta index
							$duplicate_tokens[$token] += $duplicate_tokens[$token];
						}
					}
				}
				
				do
				{
					$insert_count = 0;
					foreach ( $db_token_list as $pos => $db_token ) 
					{
						if ( !empty($duplicate_tokens[$db_token]) ) 
						{
							# this one needs to be duplicated & added into the end of db_token list
							$score_lookup_alt[] = $score_lookup_alt[$pos];
							$encoded_data[] 	= $encoded_data[$pos]; 		
							$doc_match_data[] 	= $doc_match_data[$pos];
							$token_time_data[]	= $token_time_data[$pos];
							
							if ( isset($decode_descend) )
							{
								$maximum_doc_ids_vals[] = $maximum_doc_ids_vals[$pos];
								$encode_temp_docs[]		= $encode_temp_docs[$pos];
							}
							else
							{
								$lengths[] 				= $lengths[$pos];
								$decode_pointer_time[] 	= $decode_pointer_time[$pos];
							}
							
							$encode_delta[] 		= $encode_delta[$pos];
							$encode_pointers[] 		= $encode_pointers[$pos];
							$doc_pos_pointers[] 	= $doc_pos_pointers[$pos];
							
							$avgs[]					= $avgs[$pos];
							$doc_lengths[] 			= $doc_lengths[$pos];
							$time_data_lengths[]	= $time_data_lengths[$pos];
							$undone_values[]		= $undone_values[$pos];
							$db_token_list[]		= $db_token; 
							
							--$duplicate_tokens[$db_token];
							++$insert_count;
						}
					}
				}
				while ( $insert_count );
			}

			foreach ( $token_order_rev as $token => $order_index )
			{
				if ( isset($sumcounts[$order_index]) )
				{
					$this->final_doc_matches[$token] = $sumcounts[$order_index];
				}
			}
			
			# close cursor
			$tokpdo->closeCursor();
			
			# switch back to buffered mode
			$this->db_connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
			
			$this->result["stats"]["payload_time"] = microtime(true) - $payload_start;

			# keyword suggestions
			if ( !empty($this->keyword_suggestions) )
			{
				$suggestions = $this->MetaphoneSearch($query);
				
				if ( !empty($suggestions) )
				{
					$did_you_mean = $query;
					
					foreach ( $suggestions as $original => $suggestion )
					{
						$did_you_mean = str_replace($original, $suggestion, $did_you_mean);
					}
					
					$this->result["did_you_mean"] = $did_you_mean;
				}
			}

			# reset keyword statistics
			$this->final_doc_matches = array();

			# no matches :( 
			if ( $tic === 0 )
			{
				$this->LogQuery($query, 0); # log query
				return $this->result;
			}
		
			if ( $tic > 1 ) ksort($sumdata);

			# query has duplicate tokens, rewrite phrase proximity query
			if ( $duplicate_keywords_present ) 
			{
				$sumdata_new = array();
				$sumcounts_new = array();
				
				foreach ( $real_token_order as $index => $token ) 
				{
					if ( isset($token_order_rev[$token]) && isset($sumdata[$token_order_rev[$token]]) ) 
					{
						# duplicate results ( get the token db ids )
						$sumdata_new[] = $sumdata[$token_order_rev[$token]];
						$sumcounts_new[] = $sumcounts[$token_order_rev[$token]]; # how many documents matches for this keyword
					}
				}

				# replace old ones
				$sumdata = $sumdata_new;
				$sumcounts = $sumcounts_new;
				
				unset($sumdata_new, $sumcounts_new);
			}

			# make sure each sumcount is less or equal to $this->documents_in_collection
			foreach ( $sumcounts as $si => $sumcount ) 
			{
				if ( $sumcounts[$si] > $this->documents_in_collection )
				{
					$sumcounts[$si] = $this->documents_in_collection;
				}
			}

			asort($sumcounts);

			$sentimode = false;
			if ( $this->sortmode === PMB_SORTBY_POSITIVITY 	|| 
				 $this->sortmode === PMB_SORTBY_NEGATIVITY 	|| 
				 $this->sort_attr === "@sentiscore" 		|| 
				 $this->group_sort_attr === "@sentiscore" )  {
					 
				$sentimode = true;
			}
			
			# initialize token group lookup array
			$token_group_lookup = array();

			# create a lookup array for tokens ( which group they belong ) 
			foreach ( $sumcounts as $k_index => $doc_matches ) 
			{			
				foreach ( $sumdata[$k_index] as $token )
				{
					if ( !isset($token_group_lookup[$token]) ) 
					{
						$token_group_lookup[$token] 			= array();
						$token_group_lookup_pointer[$token] 	= 0; # for reading the values afterwards
					}
					$token_group_lookup[$token][] = $k_index;			
				}
			}

			# ensure that all provided keywords return results
			foreach ( $token_match_count as $token => $match_count ) 
			{
				if ( $match_count === 0 && empty($non_wanted_keywords[$token]) ) 
				{
					# no matches for a certain keyword
					$this->LogQuery($query, 0); # log query
					return $this->result;
				}
			}

			$data_start = microtime(true);
			
			# make a copy of the sumcounts array; will be used for approximate result counts later
			$sumcounts_reference = $sumcounts;
			
			if ( !empty($non_wanted_keywords) )
			{	
				foreach ( $real_non_wanted_tokens as $i => $token_is_unwanted ) 
				{
					if ( $token_is_unwanted ) 
					{
						unset($sumcounts_reference[$i]);
					}
				}
			}
					
			# number of all inputted keywords ( even non-wanted ones )
			$token_count = count($real_token_order);
			
			# if there are disabled documents, create a virtual non-wanted keyword
			if ( !empty($this->disabled_documents) )
			{
				# last group is now marked as non wanted
				# insert "empty" token into the group lookup
				$token_group_lookup[" "] 			= array($token_count);
				$token_group_lookup_pointer[" "] 	= 0; # for reading the values afterwards

				$real_token_order[$token_count] = " ";
				$real_non_wanted_tokens[$token_count] = true;

				++$token_count;
				++$non_wanted_count;	# for correct bm25 scores
				
				$encoded_data[] 	= $this->disabled_documents;
				$lengths[] 			= strlen($this->disabled_documents);
				$encode_pointers[] 	= 0;
				$encode_delta[] 	= 1;
				
				# document match positions
				$doc_match_data[] 	= "";
				$doc_pos_pointers[] = 0;
				$avgs[] 			= 0;
				$doc_lengths[] 		= 0;
				$undone_values[]	= 0;
				
				$db_token_list[]	= " ";
			}

			$required_bits = 0;
			#foreach ( array_unique($real_token_order) as $x => $token )
			foreach ( $real_token_order as $x => $token )
			{
				# do not set the bit if this is an unwanted keyword group
				if ( !$real_non_wanted_tokens[$x] ) 
				{
					$required_bits |= (1 << $x);
				}
			}

			$this->result["total_matches"] = 0;

			$temp_doc_id_sql = "";
			$total_matches	 = 0;
			$tmp_matches 	= 0;
			$t_matches = array();
			
			# special case: we have less than 10000 documents in total: do not splice the document range
			if ( $this->documents_in_collection <= $this->decode_interval ) 
			{
				$min_doc_id = 0;
				$max_doc_id = $this->max_doc_id+1;
				$interval 	= 0;
			}
			# decoding in descending order
			else if ( isset($decode_descend) )
			{
				$interval = -$this->decode_interval;
				$max_doc_id = max($encode_delta)+1;
				$min_doc_id = $max_doc_id + $interval;
			}
			# decoding in ascending order
			else
			{
				$interval = $this->decode_interval;
				$min_doc_id = $this->min_doc_id;
				$max_doc_id = $interval + $min_doc_id;
			}

			$vals = 0;
			$stop = false;
			$total_vals = 0;
			
			/* 
				helper variables + micro-optimization :-) 
			*/ 

			# for BM25 calculation to avoid repetitive function calls
			$IDF_denominator = log(1+$this->documents_in_collection);
			
			foreach ( $sumcounts as $s_ind => $scount ) 
			{
				$IDF_lookup[$s_ind] = log(($this->documents_in_collection - $scount + 1) / $scount) / $IDF_denominator;
			}

			$token_count -= $non_wanted_count; # remember to subtract non_wanted keyword count from the total keyword count
			$bm25_token_count = 2*$token_count;

			# select correct reference bits for choosing whether a document is a match or not		
			if ( $this->matchmode === PMB_MATCH_ANY && !empty($non_wanted_keywords) )
			{
				$reference_bits = ~$required_bits;
				$goal_bits = 0;
			}
			else if ( $this->matchmode === PMB_MATCH_ANY ) 
			{
				$reference_bits = 0; 
				$goal_bits = 0;
			}
			else
			{
				# does not work if non_wanted_keywords not empty
				$reference_bits = $required_bits;
				
				if ( !empty($non_wanted_keywords) || !empty($this->disabled_documents) ) 
				{
					$reference_bits = PHP_INT_MAX; # 31bits 
				}
				
				$goal_bits = $required_bits;	
			}
			
			# create a lookup for bm25 (+sentiscore) field weighting
			foreach ( $weighted_bit_counts as $w_bits => $value ) 
			{
				$bm25_field_scores[$w_bits] = $weighted_score_lookup[$w_bits]-$value;
			}

			$sorted_groups = array();

			foreach ( $db_token_list as $i => $token )
			{
				# alternative option for the current token_group_lookup ? 
				# if read once, value of $token_group_lookup[$token] should increment to the next duplicate token
				$p = &$token_group_lookup_pointer[$token];
				$group_id_new = $token_group_lookup[$token][$p];

				++$p;
				$sorted_groups[$i] = $group_id_new;
			}

			asort($sorted_groups);

			$start 				= microtime(true);
			$total_documents 	= 0;
			$group_count		= count($sorted_groups);

			if ( $this->sortmode === PMB_SORTBY_MODIFIED )
			{
				include("decode_asc_timestamp.php");
			}
			else if ( isset($decode_ascend) )
			{
				include("decode_asc.php");
			}
			else
			{
				include("decode_desc.php");
			}	

			unset($loop_doc_positions, $loop_doc_groups, $t_matches, $t_matches_awaiting);
			
			if ( $tmp_matches ) $total_matches = $tmp_matches;

			$this->result["stats"]["processing_time"] = microtime(true) - $data_start;
			$this->result["total_matches"] = $total_matches;

			if ( $total_matches === 0 ) 
			{
				# no results
				return $this->result;
			}
			else if ( isset($id_sort_enabled) && !isset($id_sort_goal) )
			{
				$max_value = ($tmp_matches > $total_matches) ? $t_matches : $total_matches;

				if ( $max_value <= ($fast_ext_sort_req_count - $limit) )
				{
					$this->result["out_of_bounds"] = 1;
					return $this->result;
				}
			}

			$postprocessing_start = microtime(true);

			/*
				at this point we know all matching document ids ( - minus filterby )
				find out all the external attributes ( columns ) that should be fetched
			*/

			if ( $sentimode || $this->group_sort_attr === "@sentiscore" )
			{
				$wanted_attributes["avgsentiscore"] = 1;
			}
			
			if ( !empty($this->group_attr) )
			{
				$wanted_attributes["attr_".$this->group_attr] = 1;
			}
	
			if ( !empty($this->group_sort_attr) && strpos($this->group_sort_attr, "@") === false ) 
			{
				$wanted_attributes["attr_" . $this->group_sort_attr] = 1;
			}
			
			if ( !empty($this->sort_attr) && strpos($this->sort_attr, "@") === false )
			{
				$wanted_attributes["attr_" . $this->sort_attr] = 1;
			}
			
			$filter_by_sql_parts = array();
			$filter_by_sql = "";
			# single filter values
			if ( !empty($this->filter_by) )
			{
				$filter_by_combined	= array();
				
				foreach ( $this->filter_by as $column => $values ) 
				{
					# if this is negated value
					$comparator = "=";
					if ( $column[0] === "!" )
					{
						$column = substr($column, 1);
						$comparator = "!=";
					}
					
					$filter_by 	= array();
					foreach ( $values as $filter_by_value ) 
					{
						$filter_by[] = "attr_$column $comparator $filter_by_value";
						$wanted_attributes["attr_$column"] = 1;
					}
					
					$filter_by_combined[] =  "(" . implode(" OR ", $filter_by) . ")";
				}
				
				$filter_by_sql_parts[] = "(" . implode(" AND ", $filter_by_combined) . ") ";
			}
			
			# range filter values
			if ( !empty($this->filter_by_range) )
			{
				$filter_by_combined	= array();
				foreach ( $this->filter_by_range as $column => $values ) 
				{
					# if this is negated value
					$comparator_min = ">=";
					$comparator_max = "<=";
					$operator 		= "AND";
					
					if ( $column[0] === "!" )
					{
						$column = substr($column, 1);
						$comparator_min = "<=";
						$comparator_max = ">=";
						$operator 		= "OR";
					}
					
					$filter_by 	= array();
					foreach ( $values as $filter_by_value ) 
					{
						$filter_by[] = "attr_$column $comparator_min " . $filter_by_value[0] . " $operator attr_$column $comparator_max " . $filter_by_value[1];
						$wanted_attributes["attr_$column"] = 1;
					}
					
					$filter_by_combined[] =  "(" . implode(" OR ", $filter_by) . ")";
				}
				
				$filter_by_sql_parts[] = "(" . implode(" AND ", $filter_by_combined) . ") ";
			}

			$filter_by_sql = implode(" AND ", $filter_by_sql_parts);
			if ( !empty($filter_by_sql) )
			{
				$filter_by_sql = " AND " . $filter_by_sql; # prepend AND because the SQL will also have document ids
			}

			# calculate sentiment score for each document
			# if we are sorting by sentiment score or groups are sorted by sentiment score
			if ( $sentimode || $this->group_sort_attr === "@sentiscore" )
			{
				$this->db_connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
				$filtering_done = true;
				
				$temp_sql = "";
				foreach ( $this->temp_matches as $doc_id => $score ) 
				{
					$temp_sql .= ",$doc_id";
				}
				$temp_sql[0] = " ";

				$sentisql = "SELECT ID, avgsentiscore FROM PMBDocinfo WHERE ID IN ($temp_sql) $filter_by_sql";
				$sentipdo = $this->db_connection->query(str_replace($find, $repl, $sentisql));	

				$max_doc_score = (int)(($tc - $non_wanted_count) * array_sum($this->field_weights) * 1000) + 999;
				$sentimode = 1;
				unset($temp_sql, $sentisql);
				# sentiscale === 100 ( document score only ) 
				if ( isset($this->sentiscale) )
				{
					# manual scaling !
					if ( $this->sentiscale === 100 )
					{
						$sentimode = 2;
					}
					else if ( $this->sentiscale > 0 )
					{
						$relevancy_factor = round($this->sentiscale/100, 2);
						$phrase_prox_fctr = abs($relevancy_factor-1.00);
						$sentimode = 3;
					}
					else
					{
						# sentiscale == 0, only context score
						$sentimode = 4;
					}	
				}
				
				while ( $row = $sentipdo->fetch(PDO::FETCH_NUM) )
				{
					$doc_id = (string)$row[0];
					
					switch ( $sentimode ) 
					{
						case 1:
						# points/maxpoints * avgsentiscore + (((maxpoints - points)/maxpoints) * sentiscore )
						$this->temp_sentiscores[$doc_id] 	= (int)($this->temp_matches[$doc_id]/$max_doc_score * $row[1] + ((($max_doc_score-$this->temp_matches[$doc_id])/$max_doc_score) * $this->temp_sentiscores[$doc_id]));
						break;
						
						case 2:
						# only document score
						$this->temp_sentiscores[$doc_id] 	= (int)$row[1]; 
						break;

						case 3:
						# predefined balance between sentence and document score
						$this->temp_sentiscores[$doc_id] 	= (int)($row[1]*$relevancy_factor + $this->temp_sentiscores[$doc_id]*$phrase_prox_fctr); 
						break;
						
						case 4:
						# only sentence score
						# no need to do anything, temp_sentiscores[$doc_id] already set
						break;
					}
				}

				# close cursor
				$sentipdo->closeCursor();
				
				# back to buffered queries
				$this->db_connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
			}
			
			# if grouping is enabled,
			if ( $this->groupmode > 1 ) 
			{
				# disable buffered queries
				$this->db_connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
				
				$group_attr = "attr_".$this->group_attr;
				$group_sort_attr = "";

				# external, not internal group sort attr
				# introduce the column in the mysql query
				if ( $this->group_sort_attr === "@id" )
				{
					# special case: document id is the sort attribute
					$group_sort_attr = "";
				}
				else if ( strpos($this->group_sort_attr, "@") === false ) 
				{
					$group_sort_attr = ",attr_" . $this->group_sort_attr;
				}

				$group_sort_start = microtime(true);
				
				if ( !empty($this->temp_matches) )
				{
					$temp_sql = "";
					foreach ( $this->temp_matches as $doc_id => $score ) 
					{
						$temp_sql .= ",$doc_id";
					}
					$temp_sql[0] = " ";
					$groupsql = "SELECT ID, $group_attr $group_sort_attr FROM PMBDocinfo WHERE ID IN ($temp_sql) $filter_by_sql";
					
				}
				else
				{
					$temp_doc_id_sql[0] = " ";
					$groupsql = "SELECT ID, $group_attr $group_sort_attr FROM PMBDocinfo WHERE ID IN ($temp_doc_id_sql) $filter_by_sql";
					
				}

				$filtering_done = true;
				$grouppdo = $this->db_connection->query(str_replace($find, $repl, $groupsql));	
				unset($groupsql, $temp_sql);
				
				if ( $this->group_sort_attr === "@score" || $this->group_sort_attr === "@sentiscore" )
				{
					while ( $row = $grouppdo->fetch(PDO::FETCH_ASSOC) )
					{
						$doc_id 	= (string)$row["ID"];
						$attr_val 	= (string)$row[$group_attr];
	
						if ( isset($temp_groups[$attr_val]) )
						{	
							if ( $this->group_sort_attr === "@score"  ) 
							{			
								# use document score/weight/rank values		
								$new_ref_value 	= $this->temp_matches[$doc_id];
								$old_ref_value 	= $this->temp_matches[$temp_groups[$attr_val]];
							}
							else
							{
								# use sentiment score values
								$new_ref_value 	= $this->temp_sentiscores[$doc_id];
								$old_ref_value 	= $this->temp_sentiscores[$temp_groups[$attr_val]];
							}
							
							# max attr value first
							if ( $this->group_sort_direction === "desc" && $new_ref_value > $old_ref_value )
							{
								$temp_groups[$attr_val] = $doc_id;
							}
							# min attr value first
							else if ( $this->group_sort_direction === "asc" && $new_ref_value < $old_ref_value )
							{
								$temp_groups[$attr_val] = $doc_id;
							}
							
							++$temp_counter[$attr_val];
						}
						else
						{
							# temporary grouping attribute gets formatted with minimum doc_ids score
							$temp_groups[$attr_val] = $doc_id;
							$temp_counter[$attr_val] = 1;
						}
					}
				}
				else
				{
					# special case: document id is the group sort attribute
					if ( $this->group_sort_attr === "@id" )
					{
						$group_sort_attr = "ID";
					}
					else
					{
						# trim external group sort attr to get a proper column name
						$group_sort_attr = str_replace(",", "", $group_sort_attr);
					}	

					while ( $row = $grouppdo->fetch(PDO::FETCH_ASSOC) )
					{
						$doc_id 		= (string)$row["ID"];
						$attr_val 		= (string)$row[$group_attr];
						$sort_attr_val	= (string)$row[$group_sort_attr];
	
						if ( isset($temp_groups[$attr_val]) )
						{						
							# max attr value first
							if ( $this->group_sort_direction === "desc" && $sort_attr_val > $temp_group_attrs[$attr_val] )
							{
								$temp_groups[$attr_val] 	 	= $doc_id;
								$temp_group_attrs[$attr_val] 	= $sort_attr_val; # update the old reference value
							}
							# min attr value first
							else if ( $this->group_sort_direction === "asc" && $sort_attr_val < $temp_group_attrs[$attr_val] )
							{
								$temp_groups[$attr_val] 	 	= $doc_id;
								$temp_group_attrs[$attr_val] 	= $sort_attr_val;
							}
							
							++$temp_counter[$attr_val];
						}
						else
						{
							# temporary grouping attribute gets formatted with minimum doc_ids score
							$temp_groups[$attr_val] 		= $doc_id;
							$temp_group_attrs[$attr_val] 	= $sort_attr_val;
							$temp_counter[$attr_val] 		= 1;
						}
					}
				}
				
				# close instance cursor
				$grouppdo->closeCursor();
				
				# enable buffered queries
				$this->db_connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
				
				# reformat temporary grouping table ( for group counting )
				if ( empty($temp_groups) )
				{
					$this->result["total_matches"] = 0;
					return $this->result;
				}
				
				$temp_groups = array_flip($temp_groups);
				
				if ( $this->group_sort_attr === "@sentiscore" )
				{
					if ( !empty($this->temp_sentiscores) )
					{
						foreach ( $this->temp_sentiscores as $doc_id => $score ) 
						{
							if ( !isset($temp_groups[$doc_id]) )
							{
								unset($this->temp_sentiscores[$doc_id]);	# unset unnecessary doc_ids
							}
						}
					}
				}
				else 
				{
					# now results have been grouped
					# remove unnecessary indexes from temp_matches array				
					if ( !empty($this->temp_matches) )
					{
						foreach ( $this->temp_matches as $doc_id => $score ) 
						{
							if ( !isset($temp_groups[$doc_id]) )
							{
								unset($this->temp_matches[$doc_id]); # unset unnecessary doc_ids
							}
						}
					}
					else
					{
						# temp_matches is not set => so we are sorting by an external attribute
						# rewrite $temp_doc_id_sql, because it surely has been changed
						$temp_doc_id_sql = "";
						foreach ( $temp_groups as $doc_id => $attr ) 
						{
							$temp_doc_id_sql .= ",$doc_id";
						}
						$temp_doc_id_sql[0] = " ";
					}
				}
				
				$total_matches = count($temp_groups);
				$this->result["total_matches"] = $total_matches;
				$this->result["stats"]["group_sort_time"] = microtime(true) - $group_sort_start;
			}
			
			/*
				At this point, all unwanted document ids have been neutralized by grouping them
			*/
		
			# special sort mode ( sort by grouped result count ) 
			if ( $this->sort_attr === "@count" )
			{
				$grouper_values = array();
				$grouper_sort_values = array();
				
				# rewrite $temp_groups with group match counts
				if ( $this->group_sort_attr === "@score" || $this->group_sort_attr === "@sentiscore" )
				{
					foreach ( $temp_groups as $doc_id => $attr )
					{
						$grouper_values[$doc_id] = $temp_counter[$attr];
					}
				}
				else
				{
					foreach ( $temp_groups as $doc_id => $attr )
					{
						$grouper_values[$doc_id] = $temp_counter[$attr];
						$grouper_sort_values[$doc_id] = $temp_group_attrs[$attr];
					}
				}
				
				# not needed anymore
				unset($temp_counter);
				
				# then, sort according to sorting order
				if ( $this->sortdirection === "desc" )
				{
					arsort($grouper_values);
				}
				else
				{
					asort($grouper_values);
				}

				#$this->temp_matches = $temp_groups;
				unset($temp_group_attrs); # not needed anymore
				# fetch docinfo for documents within the given LIMIT and OFFSET values
				$i = 0;
				$doc_ids = array();
				foreach ( $grouper_values as $doc_id => $count ) 
				{
					if ( $i >= $offset )
					{
						if ( $i === $offset+$limit )
						{
							break;
						}
						
						$this->result["matches"][$doc_id]["@count"] = $count;
						$this->result["matches"][$doc_id][$this->group_attr] = $temp_groups[$doc_id];
						
						if ( isset($grouper_sort_values[$doc_id]) )
						{
							$this->result["matches"][$doc_id][$this->group_sort_attr] = $grouper_sort_values[$doc_id];
						}
						
						if ( isset($this->temp_sentiscores[$doc_id]) )
						{
							$this->result["matches"][$doc_id]["@sentiscore"] = $this->temp_sentiscores[$doc_id];
						}
						
						if ( isset($this->temp_matches[$doc_id]) ) 
						{
							$this->result["matches"][$doc_id]["@score"] = $this->temp_matches[$doc_id];
						}
					}
					
					++$i;
				}
				
				unset($temp_groups, $grouper_values, $grouper_sort_values);

			}
			// special case: results are to be ordered by document id ( and grouping is disabled ) 
			else if ( $disable_score_calculation && $this->sort_attr === "@id" && $this->groupmode === 1 )
			{		
				# parse results from the document id list ( for the sql ) 
				$temp_doc_id_sql[0] = " ";
				$temp_doc_id_sql = trim($temp_doc_id_sql);
				$required_amount = $limit + $offset;

				if ( $this->sortdirection === "asc" ) 
				{
					# results with smallest ids first
					$arr = explode(",", $temp_doc_id_sql);
					sort($arr);
				}
				else
				{
					# results are in reverse order ( biggest @id first ) 
					$arr = explode(",", $temp_doc_id_sql);
					rsort($arr);
				}
				
				$arr = array_slice($arr, $offset, $limit);
				
				foreach ( $arr as $temp_doc_id ) 
				{
					$this->result["matches"][(string)$temp_doc_id] = array();
				}
			}
			else if ( $disable_score_calculation && $this->sortmode !== PMB_SORTBY_MODIFIED )
			{
				$sql_columns = array();
				
				# exception: if sort_attr is @id, replace it with ID
				$replace_count = 0;
				$this->sort_attr = str_replace("@id", "ID", $this->sort_attr, $replace_count);
				
				if ( !$replace_count )
				{
					$db_sort_column = "attr_".$this->sort_attr;
					$sql_columns["attr_".$this->sort_attr] = $this->sort_attr;
				}
				else
				{
					$db_sort_column = $this->sort_attr;
					$sql_columns[$this->sort_attr] = $this->sort_attr;
				}
				
				if ( $this->groupmode > 1 ) 
				{
					$sql_columns["attr_".$this->group_attr] = $this->group_attr;
				}
				
				# fetch external data 
				$sql_columns_textual = implode(",", array_keys($sql_columns));
				$filtering_done = true;
				$temp_doc_id_sql[0] = " ";
				$sortsql = "SELECT ID, $sql_columns_textual FROM PMBDocinfo WHERE ID IN ($temp_doc_id_sql) $filter_by_sql ORDER BY $db_sort_column " . $this->sortdirection . " LIMIT $offset, $limit";

				$sortpdo = $this->db_connection->query(str_replace($find, $repl, $sortsql));	
				unset($this->temp_matches, $temp_groups);
				
				while ( $row = $sortpdo->fetch(PDO::FETCH_ASSOC) )
				{
					$data = array();
					foreach ( $sql_columns as $db_column_name => $pmb_attribute_name ) 
					{
						$data[$pmb_attribute_name] = (string)$row[$db_column_name];
					}
					
					$this->result["matches"][(string)$row["ID"]] = $data;
				}
			}
			else
			{
				if ( !empty($this->temp_sentiscores) )
				{
					if ( count($this->temp_matches) >= count($this->temp_sentiscores) )
					{
						foreach ( $this->temp_matches as $doc_id => $doc_score ) 
						{
							if ( !isset($this->temp_sentiscores[$doc_id]) )
							{
								unset($this->temp_matches[$doc_id]);
							}
						}
					}
					else
					{
						foreach ( $this->temp_sentiscores as $doc_id => $doc_score ) 
						{
							if ( !isset($this->temp_matches[$doc_id]) )
							{
								unset($this->temp_sentiscores[$doc_id]);
							}
						}
					}
				}
				
				if ( !isset($filtering_done) && !empty($filter_by_sql) )
				{
					$temp_sql = "";
					foreach ( $this->temp_matches as $doc_id => $score ) 
					{
						$temp_sql .= ",$doc_id";
					}
					$temp_sql[0] = " ";
					
					# filter_by must be done here
					$this->db_connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
					$sortsql = "SELECT ID FROM PMBDocinfo WHERE ID IN ($temp_sql) $filter_by_sql";
					$sortpdo = $this->db_connection->query(str_replace($find, $repl, $sortsql));	
					unset($temp_groups, $sortsql, $temp_sql);

					$t_count = 0;
					
					# resultcount needs to be recalculated
					while ( $row = $sortpdo->fetch(PDO::FETCH_ASSOC) )
					{
						$t_matches[(string)$row["ID"]] = $this->temp_matches[(string)$row["ID"]]; # copy the scores
						++$t_count;
					}
				
					$sortpdo->closeCursor();
					$this->db_connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
					
					# update result count	
					$this->result["total_matches"] = $t_count;
					
					if ( $t_count === 0 ) 
					{
						# no matches, end now ! 
						$this->LogQuery($query, 0);
						return $this->result;
					}
					
					$this->temp_matches = $t_matches;
					unset($t_matches);
				}
				else if ( !empty($this->sort_attr) && !empty($this->sortdirection) && $this->sort_attr !== $this->group_sort_attr )
				{
					if ( $this->sort_attr === "@id" )
					{
						# just sort the results ! 
						if ( $this->sortdirection === "asc" )
						{
							ksort($this->temp_matches);
						}
						else
						{
							krsort($this->temp_matches);
						}
					}
					else
					{
						# results need to be sorted with an external attribute, even if they have been grouped before this
						$temp_sql = "";
						foreach ( $this->temp_matches as $doc_id => $score ) 
						{
							$temp_sql .= ",$doc_id";
						}
						$temp_sql[0] = " ";
						
						$sort_field_name = "attr_" . $this->sort_attr;
						$wanted_item_count = $offset+$limit;
	
						$this->db_connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
						$sortsql = "SELECT ID, $sort_field_name FROM PMBDocinfo WHERE ID IN ($temp_sql) ORDER BY $sort_field_name " . $this->sortdirection  . " LIMIT $offset, $limit" ;
						$sortpdo = $this->db_connection->query(str_replace($find, $repl, $sortsql));	
						unset( $sortsql, $temp_sql, $t_matches);
						
						# reset offset so the results wont be cut later
						$offset = 0;
	
						# resultcount needs to be recalculated
						while ( $row = $sortpdo->fetch(PDO::FETCH_ASSOC) )
						{
							$grouper_sort_values[(string)$row["ID"]] = (string)$row[$sort_field_name];
							$t_matches[(string)$row["ID"]] = $this->temp_matches[(string)$row["ID"]]; # copy the scores
						}
	
						$sortpdo->closeCursor();
						$this->db_connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
						
						$this->temp_matches = $t_matches;
						unset($t_matches);
					}
					
					# results are already ordered by an external attribute
					$skip_sorting = true;	
				}
				
				$final_results_variable = "temp_matches";

				if ( empty($skip_sorting) )
				{
					switch ( $this->sortmode ) 
					{
						case PMB_SORTBY_RELEVANCE:
						case PMB_SORTBY_MODIFIED:
						arsort($this->temp_matches);
						break;
						
						case PMB_SORTBY_POSITIVITY:
						$final_results_variable = "temp_sentiscores";
						arsort($this->temp_sentiscores);
						break;
							
						case PMB_SORTBY_NEGATIVITY:
						$final_results_variable = "temp_sentiscores";
						asort($this->temp_sentiscores);	
						break;
						
						case PMB_SORTBY_ATTR:
						if ( $this->sortdirection === "desc" )
						{
							arsort($this->temp_matches);
						}
						else
						{
							asort($this->temp_matches);	
						}
						break;
					}
				}

				# fetch docinfo for documents within the given LIMIT and OFFSET values
				$i = 0;
				$doc_ids = array();
				foreach ( $this->$final_results_variable as $doc_id => $score ) 
				{
					if ( $i >= $offset )
					{
						if ( $i === $offset+$limit )
						{
							break;
						}
						
						if ( isset($this->temp_matches[$doc_id]) )
						{
							$this->result["matches"][$doc_id]["@score"] = $this->temp_matches[$doc_id];
						}
								
						if ( isset($temp_groups[$doc_id]) )
						{
							$this->result["matches"][$doc_id][$this->group_attr] = $temp_groups[$doc_id];
						}
						
						if ( isset($grouper_sort_values[$doc_id]) )
						{
							$this->result["matches"][$doc_id][$this->sort_attr] = $grouper_sort_values[$doc_id];
						}
						
						if ( isset($this->temp_sentiscores[$doc_id]) )
						{
							$this->result["matches"][$doc_id]["@sentiscore"] = $this->temp_sentiscores[$doc_id];
						}
					}
					
					++$i;
				}
			}

			$ext_docinfo_start = microtime(true);

			# fetch docinfo separately
			if ( $this->index_type == 1 )
			{	
				$docsql		= "SELECT ID as doc_id, SUBSTRING(field0, 1, 150) AS title, URL, field1 AS content, field3 as meta FROM PMBDocinfo".$this->suffix." WHERE ID IN (".implode(",", array_keys($this->result["matches"])).")";
				$docpdo 	= $this->db_connection->query($docsql);

				while ( $row = $docpdo->fetch(PDO::FETCH_ASSOC) )
				{
					# merge with existing rows
					foreach ( $row as $column => $column_value ) 
					{
						$this->result["matches"][(string)$row["doc_id"]][$column] = $column_value;
					}
				}	
			}
			# if user has requested that original  data must be included with the results
			else if ( $this->include_original_data && !empty($this->sql_body) ) 
			{
				if ( $this->use_internal_db === 0 ) 
				{
					# we are using an external database! 
					include_once "ext_db_connection_".$this->index_id.".php"; 
		
					# create a new instance of the connection
					if ( function_exists("ext_db_connection") )
					{
						$ext_connection = call_user_func("ext_db_connection");
						if ( is_string($ext_connection) )
						{
							$this->result["error"] = "Including original data failed. Following error message was received: ext_connection" ;
						}
						else
						{
							# everything seems to be OK!
							try
							{
								$external_data_sql = $this->sql_body[0] . implode(",", array_keys($this->result["matches"])) . $this->sql_body[1];
								$ext_pdo = $ext_connection->query($external_data_sql);
								
								while ( $row = $ext_pdo->fetch(PDO::FETCH_ASSOC) )
								{
									$this->result["matches"][(string)$row[$this->primary_key]] += $row;
								}
							}
							catch ( PDOException $e ) 
							{
								$this->result["error"] = "Including original data failed. Following error message was received: " . $e->getMessage();
							}	
						}
					}
					else
					{
						# no such connection defined
						$this->result["error"] = "Including original data failed. External data base not defined or defined incorrectly." ;
					}	
				}
				else
				{
					# we are using internal database
					try
					{
						$external_data_sql = $this->sql_body[0] . implode(",", array_keys($this->result["matches"])) . $this->sql_body[1];
						$ext_pdo = $this->db_connection->query($external_data_sql);
						
						while ( $row = $ext_pdo->fetch(PDO::FETCH_ASSOC) )
						{
							$this->result["matches"][(string)$row[$this->primary_key]] += $row;
						}
					}
					catch ( PDOException $e ) 
					{
						$this->result["error"] = "Including original data failed. Following error message was received: " . $e->getMessage();
					}
				}
			}
			
			$this->result["stats"]["ext_docinfo_time"] = microtime(true)-$ext_docinfo_start;
		}
		catch ( PDOException $e ) 
		{
			$this->result["error"] = $e->getMessage();
			return $this->result;	
		}

		$this->result["query_time"] = microtime(true) - $this->query_start_time;
		
		# finally, log query
		$this->LogQuery($query, $this->result["total_matches"]);

		return $this->result;
	}
	
	private function LogQuery($query, $results)
	{
		if ( $this->log_queries && !empty($query) )
		{
			$query_time_ms = (microtime(true) - $this->query_start_time)*1000;
			
			try
			{
				$pdo = $this->db_connection->prepare("INSERT INTO PMBQueryLog".$this->suffix." ( timestamp, ip, query, results, searchmode, querytime ) VALUES (UNIX_TIMESTAMP(), INET_ATON(?), ?, ?, ?, ?)");
				$pdo->execute(array($_SERVER["REMOTE_ADDR"], $query, $results, $this->sortmode, $query_time_ms));
			}
			catch ( PDOException $e ) 
			{
				echo $e->getMessage();
			}
		}
	}
	
	private function RewriteMainSQL($main_sql_query)
	{
		$main_sql_query = trim(str_replace(array("\n\t", "\t\n", "\r\n", "\n"), " ", $main_sql_query));
		$main_sql_query = str_replace(",", ", ", $main_sql_query);

		$parts = explode(" ", $main_sql_query);
		$temp = array();
		foreach ( $parts as $part ) 
		{
			if ( $part != "" )
			{
				$temp[] = $part;
			}
		}
		
		$trimmed_sql = implode(" ", $temp);
		$primary_column  =  trim($temp[1], " \t\n\r\0\x0B,");
		$pre_existing_where = false;
		$secondary_breakpoint = false;
		
		# primary column name
		$primary_parts = explode(".", $primary_column);
		$this->primary_key = end($primary_parts);
	
		# the custom where attribute comes before groupby, having etc..
		$catches = array("where", "group by", "having", "order by", "limit");
	
		foreach ( $catches as $catch ) 
		{
			# find the last occurance of the needle
			$pos = strripos($trimmed_sql, " " . $catch );
				
			if ( $pos !== false ) 
			{
				if ( $catch === "where" ) 
				{
					$pre_existing_where = $pos;
				}
				else
				{
					$secondary_breakpoint = $pos;
					break;
				}
			}
		}
		
		if ( $pre_existing_where )
		{
			# the sql query includes a where condition
			# it needs to be cut out ! 
			$before_where = substr($trimmed_sql, 0, $pre_existing_where);
			
			# query includes a secondary breakpoint
			if ( $secondary_breakpoint ) 
			{
				$from_secondary = substr($trimmed_sql, $secondary_breakpoint);
				
				# the original where-condition gets replaced
				$final_sql[0] = $before_where . " WHERE $primary_column IN(";
				$final_sql[1] = ") $from_secondary";
			}
			else
			{
				$final_sql[0] = $before_where . " WHERE $primary_column IN(";	
				$final_sql[1] = ")";
			}
		}
		else
		{ 
			# no where condition in the sql
			if ( $secondary_breakpoint ) 
			{
				# put the where condition before secondary breakpoint
				$before_secondary = substr($trimmed_sql, 0, $secondary_breakpoint);
				$from_secondary = substr($trimmed_sql, $secondary_breakpoint);
				
				$final_sql[0] = $before_secondary . " WHERE $primary_column IN(";
				$final_sql[1] = ") $from_secondary";
			}
			else
			{
				# just append the where condition
				$final_sql[0] = $trimmed_sql . " WHERE $primary_column IN(";
				$final_sql[1] = ")";
			}
		}

		return $final_sql;
	}
	/*
	checck if the given value is an "integer"
	as in "10", "0" etc. and not "10.1" nor "0.1"
	function does not test variable's actual type (string vs int) but it's contents
	*/
	private function is_intval($val)
	{
		return ( is_numeric($val) && is_int(0+$val) );
	}
	
	/* 
	special implementation for the crc32 function (mysql compatible for 32bit PHP environments) 
	return crc32 value of the provided string as an unsigned integer in string format
	platform independent (works for x86 and x64 PHP environments)
	*/
	private function uint_crc32_string($str)
	{
		if ( PHP_INT_SIZE === 4 ) 
		{
			$checksum = crc32($str);
			if ( $checksum < 0 ) 
			{
				return (string)($checksum+4294967296);
			}
		}
		
		return (string)crc32($str);
	}

	public function SetFieldWeights($input = array())
	{
		if ( !empty($input) && is_array($input) )
		{
			foreach ( $input as $index => $value ) 
			{
				if ( $this->is_intval($value) && $value >= 0 && in_array($index, $this->data_columns) ) 
				{
					$this->field_weights[$index] = $value;
				}
				else
				{
					echo "Invalid field ( $index ) or value ( $value )";
					return false;
				}
			}
			
			return true;
		}
		
		return false;
	}
	
	public function SetSentiScale($mode = false) 
	{
		if ( $mode === false )
		{
			# default, automatic weightning
			$this->sentiscale = NULL;
		}
		else if ( isset($mode) && $this->is_intval($mode) && $mode >= 0 && $mode <= 100 ) 
		{
			# custom weight
			# 0 	= phrase proximity only
			# 100 	= document score only
			# 1 - 99 = an average between these two
			$this->sentiscale = $mode;
		}
		else
		{
			return false;
		}
		
		return true;
	}
	
	private function ParseAttribute($attribute)
	{
		# an attribute is required
		if ( !empty($attribute) )
		{
			$parsed = array();
			$exp = explode(" ", $attribute);
			foreach ( $exp as $word ) 
			{
				if ( !empty($word) ) 
				{
					$parsed[] = $word;
				}
			}
			
			if ( count($parsed) === 2 )
			{
				$attr = $parsed[0];
				$sort_direction = strtolower($parsed[1]);
				# handle different aliases 
				$attr = str_replace(array("@rank", "@weight"), "@score", $attr);
				
				if ( $sort_direction === "desc" || $sort_direction === "asc" )
				{
					return array($attr, $sort_direction);
				}
				else
				{
					echo "Unknown sorting order: $sort_direction";
				}
				
			}
			else
			{
				echo "NOTICE: Invalid attribute - attribute must contain the sort column and sorting order ( desc / asc )";
			}
		}
		else
		{
			echo "NOTICE: An attribute must be provided when using PMB_SORTBY_ATTR sortmode.";
		}
		
		return false;
	}
	
	public function SetGroupBy($mode, $attribute = "", $groupsort = "")
	{
		if ( $this->is_intval($mode) && $mode >= 1 && $mode <= 2) 
		{
			if ( $mode === PMB_GROUPBY_ATTR )
			{
				# an attribute is required
				if ( !empty($attribute) )
				{
					$attribute = trim($attribute);

					# groupsort must also be set
					if ( $parsed = $this->ParseAttribute($groupsort) ) 
					{
						$this->group_attr	 			= $attribute;
						$this->group_sort_attr			= $parsed[0];
						$this->group_sort_direction 	= $parsed[1];
					}
					else
					{
						echo "Parsing error: $groupsort \n";
						return false;
					}
				}
				else
				{
					return false;
				}
			}
			else
			{
				$this->group_attr = NULL;
				$this->group_sort_attr = NULL;
				$this->group_sort_direction = NULL;
			}
			
			$this->groupmode = $mode;
		}
		else
		{
			return false;
		}
		
		return true;
	}
	
	public function ResetFilters()
	{
		$this->filter_by = array();
		$this->filter_by_range = array();	
		
		return true;
	}
	
	public function ResetGroupBy()
	{
		$this->groupmode = PMB_GROUPBY_DISABLED;
		$this->group_attr = NULL;
		$this->group_sort_attr = NULL;
		$this->group_sort_direction = NULL;
		
		return true;
	}
	
	public function SetFilterBy($attribute, $value)
	{
		if ( is_numeric($value) )
		{
			$attribute = trim($attribute);
			$prefix = $attribute[0];
			if ( $prefix === "!" ) 
			{
				# cut the exclamation mark	
				$attribute = substr($attribute, 1);
			}
			else
			{
				$prefix = "";
			}

			# because ParseAttribute needs a sorting/grouping order
			# create a virtual sorting direction ( has no real effect ) 
			$attribute .= " desc";

			if ( $data = $this->ParseAttribute($attribute) )
			{
				# now we know the provided attribute is valid
				$data[0] = $prefix.$data[0];
				if ( empty($this->filter_by[$data[0]]) ) 
				{
					$this->filter_by[$data[0]] = array();
				}

				$this->filter_by[$data[0]][] = $value;
				
				return true;
			}
		}
		
		echo "Error: invalid attribute or value ( integer expected )";
		return false;
	}
	
	public function SetFilterRange($attribute, $min, $max)
	{
		if ( is_numeric($min) && is_numeric($max) )
		{
			$attribute = trim($attribute);
			$prefix = $attribute[0];
			if ( $prefix === "!" ) 
			{
				# cut the exclamation mark	
				$attribute = substr($attribute, 1);
			}
			else
			{
				$prefix = "";
			}
			
			# because ParseAttribute needs a sorting/grouping order
			$attribute .= " desc";
			if ( $data = $this->ParseAttribute($attribute) )
			{
				$value_pair = array($min, $max);
				$data[0] = $prefix.$data[0];
				# now we know the provided attribute is valid
				# now we know the provided attribute is valid
				if ( empty($this->filter_by_range[$data[0]]) ) 
				{
					$this->filter_by_range[$data[0]] = array();
				}
				
				$this->filter_by_range[$data[0]][] = $value_pair;
				
				return true;
			}
		}
		
		echo "Error: invalid attribute or value range ( integers expected )";
		return false;
	}
	
	public function SetMatchMode($mode)
	{
		if ( $this->is_intval($mode) && $mode >= 1 && $mode <= 3 )
		{
			$this->matchmode = $mode;
			return true;
		}
		
		echo "Error: invalid matching mode";
		return false;
	}
	
	public function SetRankingMode($mode)
	{
		if ( $this->is_intval($mode) && $mode >= 1 && $mode <= 3 )
		{
			$this->rankmode = $mode;
			return true;
		}
		
		echo "Error: invalid ranking mode";
		return false;
	}
	
	public function SetSortMode($mode, $attribute = "")
	{
		if ( $this->is_intval($mode) && $mode > 0 && $mode <= 4) 
		{
			if ( $mode === PMB_SORTBY_ATTR )
			{
				if ( $parsed = $this->ParseAttribute($attribute) )
				{
					$this->sort_attr	 = $parsed[0];
					$this->sortdirection = $parsed[1];
				}
				else
				{
					return false;
				}
			}
			else
			{
				$this->sort_attr	 = NULL;
				$this->sortdirection = NULL;
			}
			
			$this->sortmode = $mode;
		}
		else
		{
			echo "Error: invalid sorting mode";
			return false;
		}
		
		return true;
	}

	public function SentiWeightning($input)
	{
		$this->sentiweight = !empty($input) ? 1 : 0 ;
		return true;
	}
	
	public function KeywordStemming($input) 
	{
		$this->keyword_stemming = !empty($input) ? 1 : 0 ;
		return true;
	}
	
	public function DialectMatching($input)
	{
		$this->dialect_matching = !empty($input) ? 1 : 0 ;
		return true;
	}

	public function QualityScoring($input)
	{
		$this->quality_scoring = !empty($input) ? 1 : 0 ;
		return true;
	}
	
	public function QualityScoreLimit($percentage)
	{
		if ( isset($percentage) && $this->is_intval($percentage) && $percentage >= 0 && $percentage <= 100 ) 
		{
			$this->prefix_minimum_quality = $percentage;
		}
	}
	
	public function StemMinimumQuality($percentage)
	{
		if ( isset($percentage) && $this->is_intval($percentage) && $percentage >= 0 && $percentage <= 100 ) 
		{
			$this->stem_minimum_quality = $percentage;
		}
	}
		
	public function ExpansionLimit($input)
	{
		if ( isset($input) && $this->is_intval($input) && $input > 0 ) 
		{
			$this->expansion_limit = $input;	
			return true;
		}
		else
		{
			echo "Error: an integer value bigger than 0 expected, $input provided";
			return false;
		}
	}
	
	public function SearchFocuser($string, $searchstring, $lang = 'fi', $wordwrap = 90, $max_len = 150)
	{
		$min_prefix_len = 4;
		$string_trim_len_limit = 20;
		
		# remove certain non matching chars from searchstring
		$searchstring = str_replace(array(",", ";", "!", "?"), " ", $searchstring);
		
		# half of the searchstrings length
		$stringlen = (int)(strlen(trim($searchstring))/2);
				
		# strip tags and normalize space
		$string = strip_tags(str_replace(array("<", ">", "\n", "&amp;"), array(" <", "> ", " ", "&"), html_entity_decode(str_replace("&nbsp;", " ", htmlentities($string, NULL, 'UTF-8')))));
		
		# string length
		$string_len = strlen($string);
	
		# explode string and remove duplicates
		$keywords = array_flip(array_flip((explode(" ", mb_strtolower($searchstring)))));
		
		$wholecount = 0;
		$new_words_to_highlight = array();
		$positions[0] = false;
		
		#count of keywords
		$wordcount = count($keywords);
		
		# try matching the whole searchstring first
		if ( $wordcount > 1 ) 
		{
			#$tpos = stripos($string, $searchstring." ");
			$pre_match = array();
			preg_match('/\b'.preg_quote($searchstring,"/").'\b/iu', $string, $pre_match, PREG_OFFSET_CAPTURE);
	
			if ( !empty($pre_match[0][1]) )
			{
				$positions[0] = $pre_match[0][1];
			}
		}
	
		# then try finding matches keyword by keyword
		if ( $positions[0] === false )
		{
			# search for partial matches
			foreach ( $keywords as $ki => $chunk )
			{
				if ( isset($chunk) && $chunk !== "" )
				{	
					$chunk = "$chunk"; # ensure string format
					$exact = false;
					if ( $chunk[0] === "=" ) 
					{
						$chunk = substr($chunk, 1);
						$keywords[$ki] = $chunk;
						$exact = true;
					}						
				
					# no partial matches if keyword is too short
					$chunk_len = mb_strlen($chunk);
					if ( $chunk_len < $min_prefix_len )
					{
						$exact = false;
					}
					
					$matches = array();
					
					if ( $exact )
					{
						$quoted_chunk = preg_quote($chunk,"/");
						preg_match_all('/\b'.$quoted_chunk.'\b/iu', $string, $matches, PREG_OFFSET_CAPTURE);
					}
					else
					{
						if ( $lang === 'fi' )
						{
							#$stemmed_keyword = stem_finnish($chunk);
							$stemmed_keyword = PMBStemmer::stem_finnish($chunk);
						}
						else
						{
							#$stemmed_keyword = stem_english($chunk);
							$stemmed_keyword = PMBStemmer::stem_english($chunk);
						}
						
						# ensure that the stemmed keyword is matchable ! 
						if ( strpos($chunk, $stemmed_keyword) === false )
						{
							$stemmed_keyword = $chunk;
						}
						
						$stemmed_chunk_len = mb_strlen($stemmed_keyword);
						$max_chunk_len = $stemmed_chunk_len-1;
						
						# if the allowance is too low
						if ( $max_chunk_len <= $min_prefix_len )
						{
							$max_chunk_len = $chunk_len-1;
						}
						
						$regexp = '/\b'.preg_quote($stemmed_keyword,"/")."\w{0,".$max_chunk_len."}\b/iu";
	
						preg_match_all($regexp, $string, $matches, PREG_OFFSET_CAPTURE);	
					}
					
					# any matches ? 
					if ( !empty($matches[0][0]) )
					{
						$minposlist[$chunk] = $matches[0][0][1];
						foreach ( $matches[0] as $m_i => $match )
						{
							# position already matched ! 
							if ( !empty($poslist[$match[1]]) ) 
							{
								continue;
							}
							
							$match[0] = strtolower($match[0]);
							$maxposlist[$chunk] = $match[1];
							$resultset[$chunk][] = $match[1];
							$poslist[$match[1]] = 1;
							++$wholecount;
							
							# not exact
							if ( !$exact && empty($new_words_to_highlight[$match[0]]) )
							{
								$new_words_to_highlight[$match[0]] = 1;
							}
							
							if ( empty($minposlist[$chunk]) )
							{
								$minposlist[$chunk] = $match[1];
							}
						}
					
					}
				}
			}	
		}	# <!---- end of keyword looping...
			
		# set words to highlight
		$highlight_words = $keywords;		
		
		# new keywords (common stem) to highlight ? 
		if ( !empty($new_words_to_highlight) ) 
		{			
			foreach ( $new_words_to_highlight as $new_word => $true )
			{
				$highlight_words[] = $new_word;
			}	
			
			$highlight_words = array_flip(array_flip($highlight_words));
		}

		# does the searchwindow need to be narrowed ? 
		if ( $string_len > $max_len )
		{
			# ensure that results are found
			if ( !empty($resultset) )
			{
				# every keyword has to show on the searchwindow
				# try selecting a searchwindow on which keywords as close to eachother as possible
				
				# first matches
				$max_minlist = max($minposlist);
				$min_minlist = min($minposlist);
				$min_list_diff = $max_minlist-$min_minlist;
				
				# last matches
				$max_maxlist = max($maxposlist);
				$min_maxlist = min($maxposlist);
				$max_list_diff = $max_maxlist - $min_maxlist ;
				
				# prefer earlier matches
				if ( $min_list_diff <= $max_list_diff || count($minposlist) === 1 )
				{
					$min_key_pos = $min_minlist;
					$max_key_pos = $max_minlist;
				}
				# prefer latter matches
				else
				{
					$min_key_pos = $min_maxlist;
					$max_key_pos = $max_maxlist;
				}
								
				# calculate the smallest window in which all keywords are visible
				foreach ( $resultset as $keyword => $poslist ) 
				{
					$min_diff = 100000;
					$min_pos = 100000;

					foreach ( $poslist as $p_index => $pos )
					{
						$t = abs($pos-$max_key_pos);
						
						if ( $t < $min_diff )
						{
							$min_diff = $t;
							$min_pos = $pos;
						}
					}
										
					$hitpoints[] = $min_pos;
				}
				
				# take min and max points and calculate avg
				# 1. check if sheeeeeeeet can be done with one continuos textarea
				# 2. if not, try with two textwindows and crop some keywords if necessary)
				$minpos = min($hitpoints);
				$maxpos = max($hitpoints);
				$avgpos = array_sum($hitpoints)/count($hitpoints);
				
				# case 1: all keywords can be found in a searchwindow
				if ( $maxpos - $minpos + $stringlen*2 + 20 < $max_len )
				{
					$positions[0] = $avgpos;
				}
				# case 2: use two search windows and combine result !
				# user avg pos as "limiter" 
				else 
				{
					$positions[0] = $minpos;
					$positions[1] = $maxpos;					
				}
	
			}
			else if ( $positions[0] === false ) 
			{
				# no matches: start from the beginning
				$positions[0] = 0;
			}
			
			$poscount = count($positions);
			$max_len = (int)($max_len/$poscount);
			$trim_characters = array(". ", "? ", "! ", " ");
			$trim_character_windows = array(". " => 20, "? " => 20, "! " => 20, " " => 10);
			$forbidden_trim_terms = array("etc." => 1,
										  "ns." => 1);
			
			foreach ( $positions as $i => $position )
			{
				$hitpoints[$i] = $position;
				$startpoints[$i] = (int)($position+$stringlen - $max_len/2);		
				$endpoints[$i] = $max_len;	
				$trim_results_start = array();
				
				$pre[$i] = "";
				$post[$i] = "";
				
				# ensure that range is not "imaginary"
				if ( $startpoints[$i] < 1 )
				{
					$startpoints[$i] = 0;
				}
	
				# START&ENDPOINT OPTIMIZATION
				if ( $position !== 0 )
				{
					foreach ( $trim_characters as $ti => $trim_character )
					{
						# set trim window accordingly
						$string_trim_len_limit = $trim_character_windows[$trim_character];
						
						# STARTPOS
						if ( $trim_character !== " " )
						{	
							$start_pos = strrpos($string, $trim_character, $hitpoints[$i]-$string_len-1);
							
							# special case: do not thread ... or ns. or etc. as clause boundary
							if ( $trim_character === ". " && !empty($string[$start_pos-1]) && $string[$start_pos-1] === "." )
							{
								$start_pos = false;
							}
							
							if ( $trim_character === ". " && !empty($string[$start_pos-3]) )
							{
								$substring = trim(substr($string, $start_pos-3, 4));
								
								if ( !empty($forbidden_trim_terms[$substring]) )
								{
									$start_pos = false;
								}
							}
						}
						else
						{
							$start_pos = strpos($string, $trim_character, $startpoints[$i]); # - string_trim_len_limit ? 
						}
						
						if ( $start_pos !== false && $start_pos < $hitpoints[$i] && $start_pos > $startpoints[$i]-$string_trim_len_limit )
						{
							if ( $trim_character !== " " )
							{
								$trim_results_start[$trim_character] = $start_pos+strlen($trim_character);
							}
							# do not cut by space if stringwindow starts from the beginning of the string
							else if ( $startpoints[$i] !== 0 ) 
							{
								$space_result_start = $start_pos+strlen($trim_character);
							}
						}
					}
				}
				
				# prefer any other break character than space
				if ( !empty($trim_results_start) )
				{
					$startpoints[$i] = max($trim_results_start);
				}
				# last resort: space
				else if ( !empty($space_result_start) )
				{
					$startpoints[$i] = $space_result_start;
					
					if ( $i === 0 ) $pre[$i] = "...";
					
				}
				else if ( $startpoints[$i] !== 0 && $i === 0 )
				{
					# cutmarks
					$pre[$i] = "...";
				}
				
				# ENDPOS
				foreach ( $trim_characters as $ti => $trim_character )
				{
					# set trim window accordingly
					$string_trim_len_limit = $trim_character_windows[$trim_character];
					
					# ENDPOS
					if ( $max_len < $string_len )
					{
						$loop_offset = $startpoints[$i] + $max_len - $string_trim_len_limit;
						$end_pos = strrpos($string, $trim_character, $startpoints[$i] + $max_len + $string_trim_len_limit - $string_len - 1);
						
						if ( $end_pos !== false && $end_pos > $loop_offset )
						{
							if ( $trim_character !== " " )
							{
								$trim_results_end[$trim_character] = $end_pos - $startpoints[$i] + strlen($trim_character);	
							}
							else 
							{
								$space_result_end = $end_pos - $startpoints[$i] + strlen($trim_character);	
							}
						}
					}	
				}
				
				# APPLY ENDPOS
				# prefer any other break character than space
				if ( !empty($trim_results_end) )
				{
					$endpoints[$i] = max($trim_results_end);
				}
				# last resort: space
				else if ( !empty($space_result_end) )
				{
					$endpoints[$i] = $space_result_end;
					
					if ( empty($positions[$i+1]) ) $post[$i] = "...";
				}
				
				else if ( empty($positions[$i+1]) && $endpoints[$i]-$startpoints[$i] > $max_len )
				{
					$post[$i] = "...";
				}
			}
	
			# cut the original string	
			foreach ( $startpoints as $i => $startpoint )
			{
				$stringparts[$i] = $pre[$i] . trim(substr($string, $startpoint, $endpoints[$i])) . $post[$i];
			}
			
			# wrap strings
			if ( count($startpoints) === 1 )
			{
				$string = implode (" ", $stringparts) ;
			}
			else
			{
				$string = implode ( "<b style='font-size:140%;color:#888;'> ... </b>", $stringparts);
			}
		}
		# else no cutting needed ! 
		else
		{
			$string = wordwrap($string, $wordwrap, "<br/>\n") ;
		}

		# highlight keyword
		foreach ( $highlight_words as $chunk )
		{
			if ( !empty($chunk) )
			{
				# message
				$string = preg_replace('/\b'.preg_quote($chunk,"/").'\b/iu', '<b>$0</b>', $string);
			}
		}
	
		return $string;
	}

}

class PMBStemmer
{
	private static $regex_consonant = '(?:[bcdfghjklmnpqrstvwxz]|(?<=[aeiou])y|^y)';
	private static $regex_vowel = '(?:[aeiou]|(?<![aeiou])y)';

	# stems a finnish word by removing the last syllable
	public static function stem_finnish($word)
	{
		$syllables = self::hyphenate_finnish($word);
		$arr = explode("-", $syllables[0]);
		$last_syllable = array_pop($arr); # remove the last syllable
		
		if ( !empty($arr) && count($arr) >= 2 )
		{
			# the stemmed value
			$word = implode("", $arr);
			# length of the stemmed value
			$stem_len = mb_strlen($word);
			# length of the original word
			$len = count($syllables[1]);
			
			$c_stem = 0;
			$c = 0;
			# count consonants
			foreach ( $syllables[2] as $i => $type )
			{
				if ( $type === 2 )
				{
					++$c; 
					if ( $i < $stem_len ) ++$c_stem;
				}
				
				$last_type = $type;
			}

			if ( $c_stem === 1 && $c > 1 ) 
			{
				# if stemmed value has only one consonant, 
				# append original characters until stemmed value has two consonants
				# EXCEPTION: if word ends with a consonant, do nto 
				for ( $i = $stem_len ; $i < $len ; ++$i )
				{
					if ( !isset($syllables[1][$i+1]) && $syllables[2][$i] === 2 )
					{
						# EXCEPTION: this is the last character
						# which also happens to be a consonant
						# do not append the character, but break
						break;
					}
					
					$word .= $syllables[1][$i];
					
					# break if the appended character was a consonant
					if ( $syllables[2][$i] === 2 ) 
					{
						break;
					}
				}
			}
		}
		
		return $word;	
	}
	
	private static function hyphenate_finnish($word)
	{
		$word = trim($word);
		$diphthong 				= array(
								"ai" => 1, 
								"ei" => 1, 
								"oi" => 1, 
								"äi" => 1, 
								"öi" => 1, 
								"ey" => 1, 
								"äy" => 1, 
								"öy" => 1, 
								"au" => 1, 
								"eu" => 1, 
								"ou" => 1, 
								"ui" => 1, 
								"yi" => 1, 
								"iu" => 1, 
								"iy" => 1
								); 
											
		$diphthong_convergent 	= array(
								"ie" => 1, 
								"uo" => 1, 
								"yö" => 1
								);
							
		$vocals 				= array(
								"a" => 1, 
								"e" => 1, 
								"i" => 1, 
								"o" => 1, 
								"u" => 1, 
								"y" => 1, 
								"ø" => 1, 
								"ä" => 1,
								"å" => 1,  
								"ö" => 1
								);
			
		$consonants 			= array(
								"b" => 1,
								"c" => 1,  
								"d" => 1,
								"f"	=> 1,  
								"g"	=> 1,
								"h"	=> 1, 
								"j"	=> 1, 
								"k"	=> 1, 
								"l"	=> 1, 
								"m"	=> 1, 
								"n"	=> 1, 
								"ŋ"	=> 1, 
								"p"	=> 1, 
								"r"	=> 1, 
								"s"	=> 1, 
								"š"	=> 1, 
								"t"	=> 1, 
								"v"	=> 1, 
								"ʃ"	=> 1, 
								"z"	=> 1,
								"ž"	=> 1, 
								"ʒ" => 1
								);
	
		$wparts = array();
		$splitted = preg_split("//u", $word, -1, PREG_SPLIT_NO_EMPTY);
		$back = 0;
		$c = 0;
		$v = 0;
		$t_counter = array();

		foreach ( $splitted as $i => $char ) 
		{
			if ( isset($vocals[$char]) )
			{
				$type[$i] = 1;
			}
			else if ( isset($consonants[$char]) )
			{
				$type[$i] = 2;
			}
			else
			{
				$type[$i] = 3;
			}

			if ( isset($splitted[$i+1]) )
			{
				if ( isset($vocals[$splitted[$i]]) && 
					 isset($vocals[$splitted[$i+1]]) && 
					 !isset($diphthong[$splitted[$i].$splitted[$i+1]]) && 
					 (!isset($diphthong_convergent[$splitted[$i].$splitted[$i+1]]) || !empty($wparts)) ) {
						
					$wparts[] = implode("", array_slice($splitted, $back, $i+1-$back));
					$back = $i+1;
				}
				else if ( $i > 0 && isset($consonants[$splitted[$i]]) && isset($vocals[$splitted[$i+1]]) )
				{
					$wparts[] = implode("", array_slice($splitted, $back, $i-$back));
					$back = $i;	
				}
			}
		}

		if ( !empty($wparts) )
		{
			# add the rest of it ! 
			$wparts[] = implode("", array_slice($splitted, $back));
			
			return array(implode("-", $wparts), $splitted, $type);
		}
		
		return array($word, $splitted, $type);
	}
	
	public static function stem_english($word)
	{
		if (strlen($word) <= 2) {
			return $word;
		}
		
		# remove possessive endings
		$word = str_replace(array("'s'", "'s", "'"), "", $word);
		
		# exception 1
		$exceptions = array(
				'skis' => 'ski',
				'skies' => 'sky',
				'dying' => 'die',
				'lying' => 'lie',
				'tying' => 'tie',
				'idly' => 'idl',
				'gently' => 'gentl',
				'ugly' => 'ugli',
				'early' => 'earli',
				'only' => 'onli',
				'singly' => 'singl',
				'sky' => 'sky',
				'news' => 'news',
				'howe' => 'howe',
				'atlas' => 'atlas',
				'cosmos' => 'cosmos',
				'bias' => 'bias',
				'andes' => 'andes',
				);
		
		# if the word is defined as exception above
		# we are done !
		if ( isset($exceptions[$word]) )
		{
			return $exceptions[$word];
		}
		
		$word = self::step1ab($word);
		$word = self::step1c($word);
		$word = self::step2($word);
		$word = self::step3($word);
		$word = self::step4($word);
		$word = self::step5($word);

		return $word;
	}


	/**
	* Step 1
	*/
	private static function step1ab($word)
	{
		// Part a
		if (substr($word, -1) == 's') {

			   self::replace($word, 'sses', 'ss')
			OR self::replace($word, 'ies', 'i')
			OR self::replace($word, 'ss', 'ss')
			OR self::replace($word, 's', '');
		}
		
		$exceptions = array(
		'inning' => 1,
		'outing' => 1,
		'canning' => 1,
		'herring' => 1,
		'earring' => 1,
		'proceed' => 1,
		'exceed' => 1,
		'succeed' => 1);

		# exception: quit here
		if ( isset($exceptions[$word]) )
		{
			return $word;
		}
		
		// Part b
		if (substr($word, -2, 1) != 'e' OR !self::replace($word, 'eed', 'ee', 0)) { // First rule
			$v = self::$regex_vowel;

			// ing and ed
			if (   preg_match("#$v+#", substr($word, 0, -5)) && self::replace($word, 'ingly', '')
				OR preg_match("#$v+#", substr($word, 0, -3)) && self::replace($word, 'ing', '')
				OR preg_match("#$v+#", substr($word, 0, -4)) && self::replace($word, 'edly', '')
				OR preg_match("#$v+#", substr($word, 0, -2)) && self::replace($word, 'ed', '')) { // Note use of && and OR, for precedence reasons

				// If one of above two test successful
				if (    !self::replace($word, 'at', 'ate')
					AND !self::replace($word, 'bl', 'ble')
					AND !self::replace($word, 'iz', 'ize')) {

					// Double consonant ending
					if (    self::doubleConsonant($word)
						AND substr($word, -2) != 'll'
						AND substr($word, -2) != 'ss'
						AND substr($word, -2) != 'zz') {

						$word = substr($word, 0, -1);

					} else if (self::m($word) == 1 AND self::cvc($word)) {
						$word .= 'e';
					}
				}
			}
		}

		return $word;
	}


	/**
	* Step 1c
	*
	* @param string $word Word to stem
	*/
	private static function step1c($word)
	{
		$v = self::$regex_vowel;

		if (substr($word, -1) == 'y' && preg_match("#$v+#", substr($word, 0, -1))) {
			self::replace($word, 'y', 'i');
		}

		return $word;
	}


	/**
	* Step 2
	*
	* @param string $word Word to stem
	*/
	private static function step2($word)
	{
		switch (substr($word, -2, 1)) {
			case 'a':
				   self::replace($word, 'ational', 'ate', 0)
				OR self::replace($word, 'tional', 'tion', 0);
				break;

			case 'c':
				   self::replace($word, 'enci', 'ence', 0)
				OR self::replace($word, 'anci', 'ance', 0);
				break;

			case 'e':
				self::replace($word, 'izer', 'ize', 0);
				break;

			case 'g':
				self::replace($word, 'logi', 'log', 0);
				break;

			# # also replay li-endings, if preceding letter is one of these: cdeghkmnrt
			case 'l':
					self::replace($word, 'lessli', 'less', 0)
				OR self::replace($word, 'entli', 'ent', 0)  
				OR self::replace($word, 'fulli', 'ful', 0)
				OR self::replace($word, 'ousli', 'ous', 0)
				OR self::replace($word, 'alli', 'al', 0)
				OR self::replace($word, 'bli', 'ble', 0)
				OR self::replace($word, 'cli', 'c', 0)
				OR self::replace($word, 'dli', 'd', 0)	
				OR self::replace($word, 'eli', 'e', 0)
				OR self::replace($word, 'gli', 'g', 0)
				OR self::replace($word, 'hli', 'h', 0)
				OR self::replace($word, 'kli', 'k', 0)
				OR self::replace($word, 'mli', 'm', 0)
				OR self::replace($word, 'nli', 'n', 0)
				OR self::replace($word, 'rli', 'r', 0)
				OR self::replace($word, 'tli', 't', 0);
				break;

			case 'o':
				   self::replace($word, 'ization', 'ize', 0)
				OR self::replace($word, 'ation', 'ate', 0)
				OR self::replace($word, 'ator', 'ate', 0);
				break;

			case 's':
				   self::replace($word, 'iveness', 'ive', 0)
				OR self::replace($word, 'fulness', 'ful', 0)
				OR self::replace($word, 'ousness', 'ous', 0)
				OR self::replace($word, 'alism', 'al', 0);
				break;

			case 't':
				   self::replace($word, 'biliti', 'ble', 0)
				OR self::replace($word, 'aliti', 'al', 0)
				OR self::replace($word, 'iviti', 'ive', 0);
				break;
		}
		
		

		return $word;
	}


	/**
	* Step 3
	*
	* @param string $word String to stem
	*/
	private static function step3($word)
	{
		switch (substr($word, -2, 1)) {
			case 'a':
				self::replace($word, 'ical', 'ic', 0);
				break;

			case 's':
				self::replace($word, 'ness', '', 0);
				break;

			case 't':
				   self::replace($word, 'icate', 'ic', 0)
				OR self::replace($word, 'iciti', 'ic', 0);
				break;

			case 'u':
				self::replace($word, 'ful', '', 0);
				break;

			case 'v':
				self::replace($word, 'ative', '', 0);
				break;

			case 'z':
				self::replace($word, 'alize', 'al', 0);
				break;
		}

		return $word;
	}


	/**
	* Step 4
	*
	* @param string $word Word to stem
	*/
	private static function step4($word)
	{
		switch (substr($word, -2, 1)) {
			case 'a':
				self::replace($word, 'al', '', 1);
				break;

			case 'c':
				   self::replace($word, 'ance', '', 1)
				OR self::replace($word, 'ence', '', 1);
				break;

			case 'e':
				self::replace($word, 'er', '', 1);
				break;

			case 'i':
				self::replace($word, 'ic', '', 1);
				break;

			case 'l':
				   self::replace($word, 'able', '', 1)
				OR self::replace($word, 'ible', '', 1);
				break;

			case 'n':
				   self::replace($word, 'ant', '', 1)
				OR self::replace($word, 'ement', '', 1)
				OR self::replace($word, 'ment', '', 1)
				OR self::replace($word, 'ent', '', 1);
				break;

			case 'o':
				if (substr($word, -4) == 'tion' OR substr($word, -4) == 'sion') {
				   self::replace($word, 'ion', '', 1);
				} else {
					self::replace($word, 'ou', '', 1);
				}
				break;

			case 's':
				self::replace($word, 'ism', '', 1);
				break;

			case 't':
				   self::replace($word, 'ate', '', 1)
				OR self::replace($word, 'iti', '', 1);
				break;

			case 'u':
				self::replace($word, 'ous', '', 1);
				break;

			case 'v':
				self::replace($word, 'ive', '', 1);
				break;

			case 'z':
				self::replace($word, 'ize', '', 1);
				break;
		}

		return $word;
	}


	/**
	* Step 5
	*
	* @param string $word Word to stem
	*/
	private static function step5($word)
	{
		// Part a
		if (substr($word, -1) == 'e') {
			if (self::m(substr($word, 0, -1)) > 1) {
				self::replace($word, 'e', '');

			} else if (self::m(substr($word, 0, -1)) == 1) {

				if (!self::cvc(substr($word, 0, -1))) {
					self::replace($word, 'e', '');
				}
			}
		}

		// Part b
		if (self::m($word) > 1 AND self::doubleConsonant($word) AND substr($word, -1) == 'l') {
			$word = substr($word, 0, -1);
		}

		return $word;
	}


	/**
	* Replaces the first string with the second, at the end of the string. If third
	* arg is given, then the preceding string must match that m count at least.
	*
	* @param  string $str   String to check
	* @param  string $check Ending to check for
	* @param  string $repl  Replacement string
	* @param  int    $m     Optional minimum number of m() to meet
	* @return bool          Whether the $check string was at the end
	*                       of the $str string. True does not necessarily mean
	*                       that it was replaced.
	*/
	private static function replace(&$str, $check, $repl, $m = null)
	{
		$len = 0 - strlen($check);

		if (substr($str, $len) == $check) {
			$substr = substr($str, 0, $len);
			if (is_null($m) OR self::m($substr) > $m) {
				$str = $substr . $repl;
			}

			return true;
		}

		return false;
	}


	/**
	* What, you mean it's not obvious from the name?
	*
	* m() measures the number of consonant sequences in $str. if c is
	* a consonant sequence and v a vowel sequence, and <..> indicates arbitrary
	* presence,
	*
	* <c><v>       gives 0
	* <c>vc<v>     gives 1
	* <c>vcvc<v>   gives 2
	* <c>vcvcvc<v> gives 3
	*
	* @param  string $str The string to return the m count for
	* @return int         The m count
	*/
	private static function m($str)
	{
		$c = self::$regex_consonant;
		$v = self::$regex_vowel;

		$str = preg_replace("#^$c+#", '', $str);
		$str = preg_replace("#$v+$#", '', $str);

		preg_match_all("#($v+$c+)#", $str, $matches);

		return count($matches[1]);
	}


	/**
	* Returns true/false as to whether the given string contains two
	* of the same consonant next to each other at the end of the string.
	*
	* @param  string $str String to check
	* @return bool        Result
	*/
	private static function doubleConsonant($str)
	{
		$c = self::$regex_consonant;

		return preg_match("#$c{2}$#", $str, $matches) AND $matches[0][0] == $matches[0][1];
	}


	/**
	* Checks for ending CVC sequence where second C is not W, X or Y
	*
	* @param  string $str String to check
	* @return bool        Result
	*/
	private static function cvc($str)
	{
		$c = self::$regex_consonant;
		$v = self::$regex_vowel;

		return     preg_match("#($c$v$c)$#", $str, $matches)
			   AND strlen($matches[1]) == 3
			   AND $matches[1][2] != 'w'
			   AND $matches[1][2] != 'x'
			   AND $matches[1][2] != 'y';
	}
}

/**
 * Name:		double_metaphone( $string )
 * Purpose:		Get the primary and secondary double metaphone tokens
 * Return:		Array: if secondary == primary, secondary = NULL
 */

/**
 * VERSION
 * 
 * DoubleMetaphone Functional 1.01 (altered)
 * 
 * DESCRIPTION
 * 
 * This function implements a "sounds like" algorithm developed
 * by Lawrence Philips which he published in the June, 2000 issue
 * of C/C++ Users Journal.  Double Metaphone is an improved
 * version of Philips' original Metaphone algorithm.
 * 
 * COPYRIGHT
 * 
 * Slightly adapted from the class by Stephen Woodbridge.
 * Copyright 2001, Stephen Woodbridge <woodbri@swoodbridge.com>
 * All rights reserved.
 * 
 * http://swoodbridge.com/DoubleMetaPhone/
 * 
 * This PHP translation is based heavily on the C implementation
 * by Maurice Aubrey <maurice@hevanet.com>, which in turn  
 * is based heavily on the C++ implementation by
 * Lawrence Philips and incorporates several bug fixes courtesy
 * of Kevin Atkinson <kevina@users.sourceforge.net>.
 * 
 * This module is free software; you may redistribute it and/or
 * modify it under the same terms as Perl itself.
 * 
 * 
 * CONTRIBUTIONS
 * 
 * 2002/05/17 Geoff Caplan  http://www.advantae.com
 *   Bug fix: added code to return class object which I forgot to do
 *   Created a functional callable version instead of the class version
 *   which is faster if you are calling this a lot.
 * 
 * 2013/05/04 Steen RÃ©mi
 *   New indentation of the code for better readability
 *   Some small alterations
 *   Replace ereg by preg_match
 *     ( ereg : This function has been DEPRECATED as of PHP 5.3.0 )
 *   Improve performance (10 - 20 % faster)
 *
 * 2014/11/07 Ross Kelly
 *   Reported a bug with the oreg_match change that it needed delimiters
 *   around the the regular expressions.
 */
 
function double_metaphone( $string )
{
	$primary = '';
	$secondary = '';
	$current = 0;
	$length = strlen( $string );
	$last = $length - 1;
	$original = strtoupper( $string ).'     ';

	// skip this at beginning of word
	if (string_at($original, 0, 2, array('GN','KN','PN','WR','PS'))){
		$current++;
	}

	// Initial 'X' is pronounced 'Z' e.g. 'Xavier'
	if (substr($original, 0, 1) == 'X'){
		$primary   .= 'S'; // 'Z' maps to 'S'
		$secondary .= 'S';
		$current++;
	}

	// main loop

	while (strlen($primary) < 4 || strlen($secondary) < 4){
		if ($current >= $length){
			break;
		}

		// switch (substr($original, $current, 1)){
		switch ($original[$current]){
			case 'A':
			case 'E':
			case 'I':
			case 'O':
			case 'U':
			case 'Y':
				if ($current == 0){
					// all init vowels now map to 'A'
					$primary   .= 'A';
					$secondary .= 'A';
				}
				++$current;
				break;

			case 'B':
				// '-mb', e.g. "dumb", already skipped over ...
				$primary   .= 'P';
				$secondary .= 'P';

				if (substr($original, $current + 1, 1) == 'B'){
					$current += 2;
				} else {
					++$current;
				}
				break;

			case 'Ã‡':
				$primary   .= 'S';
				$secondary .= 'S';
				++$current;
				break;

			case 'C':
				// various gremanic
				if ($current > 1
				 && !is_vowel($original, $current - 2)
				 && string_at($original, $current - 1, 3, array('ACH'))
				 && (
						(substr($original, $current + 2, 1) != 'I')
					 && (
							(substr($original, $current + 2, 1) != 'E')
						 || string_at($original, $current - 2, 6, array('BACHER', 'MACHER'))
						)
					)
				){
					$primary   .= 'K';
					$secondary .= 'K';
					$current += 2;
					break;
				}

				// special case 'caesar'
				if ($current == 0
				 && string_at($original, $current, 6, array('CAESAR'))
				){
					$primary   .= 'S';
					$secondary .= 'S';
					$current += 2;
					break;
				}

				// italian 'chianti'
				if (string_at($original, $current, 4, array('CHIA'))){
					$primary   .= 'K';
					$secondary .= 'K';
					$current += 2;
					break;
				}

				if (string_at($original, $current, 2, array('CH'))){

					// find 'michael'
					if ($current > 0
					 && string_at($original, $current, 4, array('CHAE'))
					){
						$primary   .= 'K';
						$secondary .= 'X';
						$current += 2;
						break;
					}

					// greek roots e.g. 'chemistry', 'chorus'
					if ($current == 0
					 && (
							string_at($original, $current + 1, 5, array('HARAC', 'HARIS'))
						 || string_at($original, $current + 1, 3, array('HOR', 'HYM', 'HIA', 'HEM'))
						)
					 && !string_at($original, 0, 5, array('CHORE'))
					){
						$primary   .= 'K';
						$secondary .= 'K';
						$current += 2;
						break;
					}

					// germanic, greek, or otherwise 'ch' for 'kh' sound
					if ((
							string_at($original, 0, 4, array('VAN ', 'VON '))
						 || string_at($original, 0, 3, array('SCH'))
						)
						// 'architect' but not 'arch', orchestra', 'orchid'
					 || string_at($original, $current - 2, 6, array('ORCHES', 'ARCHIT', 'ORCHID'))
					 || string_at($original, $current + 2, 1, array('T', 'S'))
					 || (
							(
								string_at($original, $current - 1, 1, array('A','O','U','E'))
							 || $current == 0
							)
							// e.g. 'wachtler', 'weschsler', but not 'tichner'
						 && string_at($original, $current + 2, 1, array('L','R','N','M','B','H','F','V','W',' '))
						)
					){
						$primary   .= 'K';
						$secondary .= 'K';
					} else {
						if ($current > 0){
							if (string_at($original, 0, 2, array('MC'))){
								// e.g. 'McHugh'
								$primary   .= 'K';
								$secondary .= 'K';
							} else {
								$primary   .= 'X';
								$secondary .= 'K';
							}
						} else {
							$primary   .= 'X';
							$secondary .= 'X';
						}
					}
					$current += 2;
					break;
				}

				// e.g. 'czerny'
				if (string_at($original, $current, 2, array('CZ'))
				 && !string_at($original, $current -2, 4, array('WICZ'))
				){
					$primary   .= 'S';
					$secondary .= 'X';
					$current += 2;
					break;
				}

				// e.g. 'focaccia'
				if (string_at($original, $current + 1, 3, array('CIA'))){
					$primary   .= 'X';
					$secondary .= 'X';
					$current += 3;
					break;
				}

				// double 'C', but not McClellan'
				if (string_at($original, $current, 2, array('CC'))
				 && !(
						$current == 1
					 && substr($original, 0, 1) == 'M'
					)
				){
					// 'bellocchio' but not 'bacchus'
					if (string_at($original, $current + 2, 1, array('I','E','H'))
					 && !string_at($original, $current + 2, 2, array('HU'))
					){
						// 'accident', 'accede', 'succeed'
						if ((
								$current == 1
							 && substr($original, $current - 1, 1) == 'A'
							)
						 || string_at($original, $current - 1, 5,array('UCCEE', 'UCCES'))
						){
							$primary   .= 'KS';
							$secondary .= 'KS';
							// 'bacci', 'bertucci', other italian
						} else {
							$primary   .= 'X';
							$secondary .= 'X';
						}
						$current += 3;
						break;
					} else {
						// Pierce's rule
						$primary   .= 'K';
						$secondary .= 'K';
						$current += 2;
						break;
					}
				}

				if (string_at($original, $current, 2, array('CK','CG','CQ'))){
					$primary   .= 'K';
					$secondary .= 'K';
					$current += 2;
					break;
				}

				if (string_at($original, $current, 2, array('CI','CE','CY'))){
					// italian vs. english
					if (string_at($original, $current, 3, array('CIO','CIE','CIA'))){
						$primary   .= 'S';
						$secondary .= 'X';
					} else {
						$primary   .= 'S';
						$secondary .= 'S';
					}
					$current += 2;
					break;
				}

				// else
				$primary   .= 'K';
				$secondary .= 'K';

				// name sent in 'mac caffrey', 'mac gregor'
				if (string_at($original, $current + 1, 2, array(' C',' Q',' G'))){
					$current += 3;
				} else {
					if (string_at($original, $current + 1, 1, array('C','K','Q'))
					 && !string_at($original, $current + 1, 2, array('CE','CI'))
					){
						$current += 2;
					} else {
						++$current;
					}
				}
				break;

			case 'D':
				if (string_at($original, $current, 2, array('DG'))){
					if (string_at($original, $current + 2, 1, array('I','E','Y'))){
						// e.g. 'edge'
						$primary   .= 'J';
						$secondary .= 'J';
						$current += 3;
						break;
					} else {
						// e.g. 'edgar'
						$primary   .= 'TK';
						$secondary .= 'TK';
						$current += 2;
						break;
					}
				}

				if (string_at($original, $current, 2, array('DT','DD'))){
					$primary   .= 'T';
					$secondary .= 'T';
					$current += 2;
					break;
				}

				// else
				$primary   .= 'T';
				$secondary .= 'T';
				++$current;
				break;

			case 'F':
				if (substr($original, $current + 1, 1) == 'F'){
					$current += 2;
				} else {
					++$current;
				}
				$primary   .= 'F';
				$secondary .= 'F';
				break;

			case 'G':
				if (substr($original, $current + 1, 1) == 'H'){
					if ($current > 0
					 && !is_vowel($original, $current - 1)
					){
						$primary   .= 'K';
						$secondary .= 'K';
						$current += 2;
						break;
					}

					if ($current < 3){
						// 'ghislane', 'ghiradelli'
						if ($current == 0){
							if (substr($original, $current + 2, 1) == 'I'){
								$primary   .= 'J';
								$secondary .= 'J';
							} else {
								$primary   .= 'K';
								$secondary .= 'K';
							}
							$current += 2;
							break;
						}
					}

					// Parker's rule (with some further refinements) - e.g. 'hugh'
					if ((
							$current > 1
						 && string_at($original, $current - 2, 1, array('B','H','D'))
						)
					// e.g. 'bough'
					 || (
							$current > 2
						 && string_at($original, $current - 3, 1, array('B','H','D'))
						)
					// e.g. 'broughton'
					 || (
							$current > 3
						 && string_at($original, $current - 4, 1, array('B','H'))
						)
					){
						$current += 2;
						break;
					} else {
						// e.g. 'laugh', 'McLaughlin', 'cough', 'gough', 'rough', 'tough'
						if ($current > 2
						 && substr($original, $current - 1, 1) == 'U'
						 && string_at($original, $current - 3, 1,array('C','G','L','R','T'))
						){
							$primary   .= 'F';
							$secondary .= 'F';
						} else if (
							$current > 0
						 && substr($original, $current - 1, 1) != 'I'
						){
							$primary   .= 'K';
							$secondary .= 'K';
						}
						$current += 2;
						break;
					}
				}

				if (substr($original, $current + 1, 1) == 'N'){
					if ($current == 1
					 && is_vowel($original, 0)
					 && !Slavo_Germanic($original)
					){
						$primary   .= 'KN';
						$secondary .= 'N';
					} else {
						// not e.g. 'cagney'
						if (!string_at($original, $current + 2, 2, array('EY'))
						 && substr($original, $current + 1) != 'Y'
						 && !Slavo_Germanic($original)
						){
							$primary   .= 'N';
							$secondary .= 'KN';
						} else {
							$primary   .= 'KN';
							$secondary .= 'KN';
						}
					}
					$current += 2;
					break;
				}

				// 'tagliaro'
				if (string_at($original, $current + 1, 2,array('LI'))
				 && !Slavo_Germanic($original)
				){
					$primary   .= 'KL';
					$secondary .= 'L';
					$current += 2;
					break;
				}

				// -ges-, -gep-, -gel- at beginning
				if ($current == 0
				 && (
						substr($original, $current + 1, 1) == 'Y'
					 || string_at($original, $current + 1, 2, array('ES','EP','EB','EL','EY','IB','IL','IN','IE','EI','ER'))
					)
				){
					$primary   .= 'K';
					$secondary .= 'J';
					$current += 2;
					break;
				}

				// -ger-, -gy-
				if ((
						string_at($original, $current + 1, 2,array('ER'))
					 || substr($original, $current + 1, 1) == 'Y'
					)
				 && !string_at($original, 0, 6, array('DANGER','RANGER','MANGER'))
				 && !string_at($original, $current -1, 1, array('E', 'I'))
				 && !string_at($original, $current -1, 3, array('RGY','OGY'))
				){
					$primary   .= 'K';
					$secondary .= 'J';
					$current += 2;
					break;
				}

				// italian e.g. 'biaggi'
				if (string_at($original, $current + 1, 1, array('E','I','Y'))
				 || string_at($original, $current -1, 4, array('AGGI','OGGI'))
				){
					// obvious germanic
					if ((
							string_at($original, 0, 4, array('VAN ', 'VON '))
						 || string_at($original, 0, 3, array('SCH'))
						)
					 || string_at($original, $current + 1, 2, array('ET'))
					){
						$primary   .= 'K';
						$secondary .= 'K';
					} else {
						// always soft if french ending
						if (string_at($original, $current + 1, 4, array('IER '))){
							$primary   .= 'J';
							$secondary .= 'J';
						} else {
							$primary   .= 'J';
							$secondary .= 'K';
						}
					}
					$current += 2;
					break;
				}

				if (substr($original, $current +1, 1) == 'G'){
					$current += 2;
				} else {
					++$current;
				}

				$primary   .= 'K';
				$secondary .= 'K';
				break;

			case 'H':
				// only keep if first & before vowel or btw. 2 vowels
				if ((
						$current == 0
					 || is_vowel($original, $current - 1)
					)
				  && is_vowel($original, $current + 1)
				){
					$primary   .= 'H';
					$secondary .= 'H';
					$current += 2;
				} else {
					++$current;
				}
				break;

			case 'J':
				// obvious spanish, 'jose', 'san jacinto'
				if (string_at($original, $current, 4, array('JOSE'))
				 || string_at($original, 0, 4, array('SAN '))
				){
					if ((
							$current == 0
						 && substr($original, $current + 4, 1) == ' '
						)
					 || string_at($original, 0, 4, array('SAN '))
					){
						$primary   .= 'H';
						$secondary .= 'H';
					} else {
						$primary   .= 'J';
						$secondary .= 'H';
					}
					++$current;
					break;
				}

				if ($current == 0
				 && !string_at($original, $current, 4, array('JOSE'))
				){
					$primary   .= 'J';  // Yankelovich/Jankelowicz
					$secondary .= 'A';
				} else {
					// spanish pron. of .e.g. 'bajador'
					if (is_vowel($original, $current - 1)
					 && !Slavo_Germanic($original)
					 && (
							substr($original, $current + 1, 1) == 'A'
						 || substr($original, $current + 1, 1) == 'O'
						)
					){
						$primary   .= 'J';
						$secondary .= 'H';
					} else {
						if ($current == $last){
							$primary   .= 'J';
							// $secondary .= '';
						} else {
							if (!string_at($original, $current + 1, 1, array('L','T','K','S','N','M','B','Z'))
							 && !string_at($original, $current - 1, 1, array('S','K','L'))
							){
								$primary   .= 'J';
								$secondary .= 'J';
							}
						}
					}
				}

				if (substr($original, $current + 1, 1) == 'J'){ // it could happen
					$current += 2;
				} else {
					++$current;
				}
				break;

			case 'K':
				if (substr($original, $current + 1, 1) == 'K'){
					$current += 2;
				} else {
					++$current;
				}
				$primary   .= 'K';
				$secondary .= 'K';
				break;

			case 'L':
				if (substr($original, $current + 1, 1) == 'L'){
					// spanish e.g. 'cabrillo', 'gallegos'
					if ((
							$current == ($length - 3)
						 && string_at($original, $current - 1, 4, array('ILLO','ILLA','ALLE'))
						)
					 || (
							(
								string_at($original, $last-1, 2, array('AS','OS'))
							 || string_at($original, $last, 1, array('A','O'))
							)
						 && string_at($original, $current - 1, 4, array('ALLE'))
						)
					){
						$primary   .= 'L';
						// $secondary .= '';
						$current += 2;
						break;
					}
					$current += 2;
				} else {
					++$current;
				}
				$primary   .= 'L';
				$secondary .= 'L';
				break;

			case 'M':
				if ((
						string_at($original, $current - 1, 3,array('UMB'))
					 && (
							($current + 1) == $last
						 || string_at($original, $current + 2, 2, array('ER'))
						)
					)
				  // 'dumb', 'thumb'
				 || substr($original, $current + 1, 1) == 'M'
				){
					$current += 2;
				} else {
					++$current;
				}
				$primary   .= 'M';
				$secondary .= 'M';
				break;

			case 'N':
				if (substr($original, $current + 1, 1) == 'N'){
					$current += 2;
				} else {
					++$current;
				}
				$primary   .= 'N';
				$secondary .= 'N';
				break;

			case 'Ã‘':
				++$current;
				$primary   .= 'N';
				$secondary .= 'N';
				break;

			case 'P':
				if (substr($original, $current + 1, 1) == 'H'){
					$current += 2;
					$primary   .= 'F';
					$secondary .= 'F';
					break;
				}

				// also account for "campbell" and "raspberry"
				if (string_at($original, $current + 1, 1, array('P','B'))){
					$current += 2;
				} else {
					++$current;
				}
				$primary   .= 'P';
				$secondary .= 'P';
				break;

			case 'Q':
				if (substr($original, $current + 1, 1) == 'Q'){
					$current += 2;
				} else {
					++$current;
				}
				$primary   .= 'K';
				$secondary .= 'K';
				break;

			case 'R':
				// french e.g. 'rogier', but exclude 'hochmeier'
				if ($current == $last
				 && !Slavo_Germanic($original)
				 && string_at($original, $current - 2, 2,array('IE'))
				 && !string_at($original, $current - 4, 2,array('ME','MA'))
				){
					// $primary   .= '';
					$secondary .= 'R';
				} else {
					$primary   .= 'R';
					$secondary .= 'R';
				}
				if (substr($original, $current + 1, 1) == 'R'){
					$current += 2;
				} else {
					++$current;
				}
				break;

			case 'S':
				// special cases 'island', 'isle', 'carlisle', 'carlysle'
				if (string_at($original, $current - 1, 3, array('ISL','YSL'))){
					++$current;
					break;
				}

				// special case 'sugar-'
				if ($current == 0
				 && string_at($original, $current, 5, array('SUGAR'))
				){
					$primary   .= 'X';
					$secondary .= 'S';
					++$current;
					break;
				}

				if (string_at($original, $current, 2, array('SH'))){
					// germanic
					if (string_at($original, $current + 1, 4, array('HEIM','HOEK','HOLM','HOLZ'))){
						$primary   .= 'S';
						$secondary .= 'S';
					} else {
						$primary   .= 'X';
						$secondary .= 'X';
					}
					$current += 2;
					break;
				}

				// italian & armenian 
				if (string_at($original, $current, 3, array('SIO','SIA'))
				 || string_at($original, $current, 4, array('SIAN'))
				){
					if (!Slavo_Germanic($original)){
						$primary   .= 'S';
						$secondary .= 'X';
					} else {
						$primary   .= 'S';
						$secondary .= 'S';
					}
					$current += 3;
					break;
				}

				// german & anglicisations, e.g. 'smith' match 'schmidt', 'snider' match 'schneider'
				// also, -sz- in slavic language altho in hungarian it is pronounced 's'
				if ((
						$current == 0
					 && string_at($original, $current + 1, 1, array('M','N','L','W'))
					)
				 || string_at($original, $current + 1, 1, array('Z'))
				){
					$primary   .= 'S';
					$secondary .= 'X';
					if (string_at($original, $current + 1, 1, array('Z'))){
						$current += 2;
					} else {
						++$current;
					}
					break;
				}

			  if (string_at($original, $current, 2, array('SC'))){
				// Schlesinger's rule 
				if (substr($original, $current + 2, 1) == 'H')
					// dutch origin, e.g. 'school', 'schooner'
					if (string_at($original, $current + 3, 2, array('OO','ER','EN','UY','ED','EM'))){
						// 'schermerhorn', 'schenker' 
						if (string_at($original, $current + 3, 2, array('ER','EN'))){
							$primary   .= 'X';
							$secondary .= 'SK';
						} else {
							$primary   .= 'SK';
							$secondary .= 'SK';
						}
						$current += 3;
						break;
					} else {
						if ($current == 0
						 && !is_vowel($original, 3)
						 && substr($original, $current + 3, 1) != 'W'
						){
							$primary   .= 'X';
							$secondary .= 'S';
						} else {
							$primary   .= 'X';
							$secondary .= 'X';
						}
						$current += 3;
						break;
					}

					if (string_at($original, $current + 2, 1,array('I','E','Y'))){
						$primary   .= 'S';
						$secondary .= 'S';
						$current += 3;
						break;
					}

					// else
					$primary   .= 'SK';
					$secondary .= 'SK';
					$current += 3;
					break;
				}

				// french e.g. 'resnais', 'artois'
				if ($current == $last
				 && string_at($original, $current - 2, 2, array('AI','OI'))
				){
					// $primary   .= '';
					$secondary .= 'S';
				} else {
					$primary   .= 'S';
					$secondary .= 'S';
				}

				if (string_at($original, $current + 1, 1, array('S','Z'))){
					$current += 2;
				} else {
					++$current;
				}
				break;

			case 'T':
				if (string_at($original, $current, 4, array('TION'))){
					$primary   .= 'X';
					$secondary .= 'X';
					$current += 3;
					break;
				}

				if (string_at($original, $current, 3, array('TIA','TCH'))){
					$primary   .= 'X';
					$secondary .= 'X';
					$current += 3;
					break;
				}

				if (string_at($original, $current, 2, array('TH'))
				 || string_at($original, $current, 3, array('TTH'))
				){
					// special case 'thomas', 'thames' or germanic
					if (string_at($original, $current + 2, 2, array('OM','AM'))
					 || string_at($original, 0, 4, array('VAN ','VON '))
					 || string_at($original, 0, 3, array('SCH'))
					){
						$primary   .= 'T';
						$secondary .= 'T';
					} else {
						$primary   .= '0';
						$secondary .= 'T';
					}
					$current += 2;
					break;
				}

				if (string_at($original, $current + 1, 1, array('T','D'))){
					$current += 2;
				} else {
					++$current;
				}
				$primary   .= 'T';
				$secondary .= 'T';
				break;

			case 'V':
				if (substr($original, $current + 1, 1) == 'V'){
					$current += 2;
				} else {
					++$current;
				}
				$primary   .= 'F';
				$secondary .= 'F';
				break;

			case 'W':
				// can also be in middle of word
				if (string_at($original, $current, 2, array('WR'))){
					$primary   .= 'R';
					$secondary .= 'R';
					$current += 2;
					break;
				}

				if (($current == 0)
				 && (
						is_vowel($original, $current + 1)
					 || string_at($original, $current, 2, array('WH'))
					)
				){
					// Wasserman should match Vasserman 
					if (is_vowel($original, $current + 1)){
						$primary   .= 'A';
						$secondary .= 'F';
					} else {
						// need Uomo to match Womo 
						$primary   .= 'A';
						$secondary .= 'A';
					}
				}

				// Arnow should match Arnoff
				if ((
						$current == $last
					&& is_vowel($original, $current - 1)
					)
				 || string_at($original, $current - 1, 5, array('EWSKI','EWSKY','OWSKI','OWSKY'))
				 || string_at($original, 0, 3, array('SCH'))
				){
					// $primary   .= '';
					$secondary .= 'F';
					++$current;
					break;
				}

				// polish e.g. 'filipowicz'
				if (string_at($original, $current, 4,array('WICZ','WITZ'))){
					$primary   .= 'TS';
					$secondary .= 'FX';
					$current += 4;
					break;
				}

				// else skip it
				++$current;
				break;

			case 'X':
				// french e.g. breaux 
				if (!(
						$current == $last
					 && (
							string_at($original, $current - 3, 3, array('IAU', 'EAU'))
						 || string_at($original, $current - 2, 2, array('AU', 'OU'))
						)
					)
				){
					$primary   .= 'KS';
					$secondary .= 'KS';
				}

				if (string_at($original, $current + 1, 1, array('C','X'))){
					$current += 2;
				} else {
					++$current;
				}
				break;

			case 'Z':
				// chinese pinyin e.g. 'zhao' 
				if (substr($original, $current + 1, 1) == 'H'){
					$primary   .= 'J';
					$secondary .= 'J';
					$current += 2;
					break;

				} else if (
					string_at($original, $current + 1, 2, array('ZO', 'ZI', 'ZA'))
				 || (
						Slavo_Germanic($original)
					 && (
							$current > 0
						 && substr($original, $current - 1, 1) != 'T'
						)
					)
				){
					$primary   .= 'S';
					$secondary .= 'TS';
				} else {
					$primary   .= 'S';
					$secondary .= 'S';
				}

				if (substr($original, $current + 1, 1) == 'Z'){
					$current += 2;
				} else {
					++$current;
				}
				break;

			default:
				++$current;

		} // end switch

	} // end while

	// printf("<br />ORIGINAL:   %s\n", $original);
	// printf("<br />current:    %s\n", $current);
	// printf("<br />PRIMARY:    %s\n", $primary);
	// printf("<br />SECONDARY:  %s\n", $secondary);

	$primary = substr($primary, 0, 4);
	$secondary = substr($secondary, 0, 4);

	if( $primary == $secondary ){
		$secondary = NULL;
	}

	return array(
				'primary'	=> $primary,
				'secondary'	=> $secondary
				);

} // end of function MetaPhone


/**
 * Name:	string_at($string, $start, $length, $list)
 * Purpose:	Helper function for double_metaphone( )
 * Return:	Bool
 */
function string_at($string, $start, $length, $list){
	if ($start < 0
	 || $start >= strlen($string)
	){
		return 0;
	}

	foreach ($list as $t){
		if ($t == substr($string, $start, $length)){
			return 1;
		}
	}

	return 0;
}


/**
 * Name:	is_vowel($string, $pos)
 * Purpose:	Helper function for double_metaphone( )
 * Return:	Bool
 */
function is_vowel($string, $pos){
	return preg_match("/[AEIOUY]/", substr($string, $pos, 1));
}

/**
 * Name:	Slavo_Germanic($string, $pos)
 * Purpose:	Helper function for double_metaphone( )
 * Return:	Bool
 */

function Slavo_Germanic($string){
	return preg_match("/W|K|CZ|WITZ/", $string);
}

?>