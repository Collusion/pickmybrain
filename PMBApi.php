<?php

/* Copyright (C) 2016 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@pickmybra.in
 * or visit: http://www.pickmybra.in
 */

# important
mb_internal_encoding("UTF-8");

# sorting modes
define("PMB_SORTBY_RELEVANCE"		, 1);
define("PMB_SORTBY_POSITIVITY"		, 2);
define("PMB_SORTBY_NEGATIVITY"		, 3);
define("PMB_SORTBY_ATTR"			, 4);

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

# internal
define("SPL_EXISTS", class_exists("SplFixedArray"));

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
	private $dialect_matching;
	private $dialect_replacing;
	private $quality_scoring;
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
	private $lsbits;
	private $main_sql_attrs;
	private $max_results;
	private $sentiment_analysis;
	
	/* Internal */
	private $allowed_sort_modes;
	private $allowed_grouping_modes;
	private $non_scored_sortmodes;
	private $hex_lookup_decode;
	private $documents_in_collection;
	private $index_state;
	private $latest_indexing_done;
	private $query_start_time;
		
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
		}	
			
		# lookup tables for sorting/grouping modes
		$this->allowed_sort_modes = array();
		$this->allowed_grouping_modes = array();
		
		# web-crawlers
		$this->allowed_sort_modes[1] = array();
		$this->allowed_sort_modes[1][PMB_SORTBY_RELEVANCE] 		= true;
		$this->allowed_sort_modes[1][PMB_SORTBY_POSITIVITY] 	= true;
		$this->allowed_sort_modes[1][PMB_SORTBY_NEGATIVITY] 	= true;
		
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
		$this->keyword_stemming 	= $keyword_stemming;
		$this->dialect_matching 	= $dialect_matching;
		$this->quality_scoring		= $quality_scoring;
		$this->separate_alnum		= $separate_alnum;
		$this->log_queries			= $log_queries;
		$this->prefix_mode			= $prefix_mode;
		$this->prefix_length		= $prefix_length;
		$this->expansion_limit		= $expansion_limit;
		$this->enable_exec			= $enable_exec;
		$this->indexing_interval	= $indexing_interval;
		$this->max_results			= 1000;
		$this->charset_regexp		= "/[^" . $charset . preg_quote(implode("", $blend_chars)) . "*\"]/u";
		$this->result				= array();
		$this->current_index		= $index_id;
		$this->number_of_fields		= $number_of_fields;
		$this->lsbits				= pow(2, $number_of_fields)-1;
		$this->sentiment_analysis	= $sentiment_analysis;
		$this->data_columns			= $data_columns;
		$this->field_weights 		= $field_weights;
		
		if ( isset($main_sql_attrs) )
		{
			$this->main_sql_attrs	= $main_sql_attrs;
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

		return true;
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

	
	private function CreatePrefixes($word)
	{
		$prefix_array = array();
		
		# dialect processing: remove dialect ( ä, ö, å + etc) from tokens and add as prefix
		if ( $this->dialect_matching && !empty($this->dialect_find) )
		{
			$nodialect = str_replace($this->dialect_find, $this->dialect_replace, $word);
			if ( $nodialect !== $word ) 
			{
				$prefix_array[$nodialect] = 0;
			}
		}
	
		# if prefix_mode > 0, prefixing is enabled
		if ( $this->prefix_mode ) 
		{
			$min_prefix_len = $this->prefix_length;
			/*
			prefix_mode 1 = prefixes
			prefix_mode 2 = prefixes + postifes
			prefix_mode 3 = infixes
			*/
			
			$wordlen = mb_strlen($word);
			if ( $wordlen > $min_prefix_len ) 
			{
				if ( $this->prefix_mode === 3 ) 
				{
					# infixes
					for ( $i = $wordlen-1 ; $i >= $min_prefix_len ; --$i ) 
					{
						for ( $j = 0 ; ($i + $j) <= $wordlen ; ++$j )
						{
							$prefix_array[mb_substr($word, $j, $i)] = $wordlen - $i;
						}
					}
				}
				else if ( $this->prefix_mode === 2 )
				{
					# prefixes and postfixes
					# prefix
					for ( $i = $wordlen-1 ; $i >= $min_prefix_len ; --$i )
					{
						$prefix_array[mb_substr($word, 0, $i)] = $wordlen - $i;
					}
					
					# postfix
					for ( $i = 1 ; $wordlen-$i >= $min_prefix_len ; ++$i )
					{
						$prefix_array[mb_substr($word, $i)] = $i;
					}
				}
				else
				{
					# default: prefixes only
					for ( $i = $wordlen-1 ; $i >= $min_prefix_len ; --$i )
					{
						$prefix_array[mb_substr($word, 0, $i)] = $wordlen - $i;
					}
				}
				
			}
		}
		
		return $prefix_array;	
	}
	
	public function SetLogState($value)
	{
		if ( !empty($value) )
		{
			$this->log_queries = true;
		}
		else
		{
			$this->log_queries = false;
		}
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
	
	private function execWithCurl($url, $async = true)
	{
		$timeout = 1;
		if ( empty($async) )
		{
			$async 		= false;
			$timeout 	= 10000;
		}
		
		$options = array(
			CURLOPT_RETURNTRANSFER 	=> false,    
			CURLOPT_HEADER         	=> false,   
			CURLOPT_FOLLOWLOCATION 	=> true,    
			CURLOPT_ENCODING       	=> "",      
			CURLOPT_USERAGENT      	=> "localhost",   
			CURLOPT_AUTOREFERER    	=> true,     
			CURLOPT_CONNECTTIMEOUT 	=> 10,      
			CURLOPT_TIMEOUT_MS 		=> $timeout,      
			CURLOPT_FRESH_CONNECT 	=> $async
		);
	
		$ch      = curl_init($url);
		curl_setopt_array( $ch, $options );
		$content = curl_exec( $ch );
		curl_close( $ch );
		
		return $content;
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
	
	private function VBDeltaDecode($hexstring)
	{
		$delta = 1;
		$len = strlen($hexstring);
		$temp = 0;
		$shift = 0;
		$result = array();
	
		for ( $i = 0 ; $i < $len ; ++$i )
		{
			$bits = $this->hex_lookup_decode[$hexstring[$i]];
			$temp |= (($bits & 127) << $shift*7);
			++$shift;
			
			if ( $bits > 127 )
			{
				# 8th bit is set, number ends here ! 
				$delta = $temp+$delta-1;
				$result[] = $delta;
				$temp = 0;
				$shift = 0;
			}
		}
		
		return $result;
	}
	
	private function VBdecode($hexstring)
	{
		$len = strlen($hexstring);
		$temp = 0;
		$shift = 0;
		$result = array();
	
		for ( $i = 0 ; $i < $len ; ++$i )
		{
			$bits = $this->hex_lookup_decode[$hexstring[$i]];
			$temp |= (($bits & 127) << $shift*7);
			++$shift;
			
			if ( $bits > 127 )
			{
				# 8th bit is set, number ends here ! 
				$result[] = $temp;
				$temp = 0;
				$shift = 0;
			}
		}
		
		return $result;
	}
	
	private function DeltaDecode(array $encoded_ints)
	{
		$delta = 1;
		$result = array();
		
		foreach ( $encoded_ints as $integer ) 
		{
			$delta = $integer+$delta-1;
			$result[] = $delta;		
		}
		
		return $result;
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
			$countpdo = $this->db_connection->prepare("SELECT ID, type, documents, current_state, updated FROM PMBIndexes WHERE name = ?");
			$countpdo->execute(array(mb_strtolower(trim($indexname))));
				
			if ( $row = $countpdo->fetch(PDO::FETCH_ASSOC) )
			{
				if ( $this->LoadSettings((int)$row["ID"]) )
				{
					$this->index_id					= (int)$row["ID"];
					$this->suffix					= "_" .$row["ID"];
					$this->index_type				= (int)$row["type"];
					$this->documents_in_collection 	= (int)$row["documents"];
					$this->index_state 				= (int)$row["current_state"];
					$this->latest_indexing_done 	= (int)$row["updated"];
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
	
	public function Search($query, $offset = 0, $limit = 10)
	{
		$this->query_start_time = microtime(true);
		
		# reset query statistics
		$this->result = array();
		$this->result["matches"]		= array();
		$this->result["total_matches"] 	= 0;
		$this->result["error"]			= "";
		
		if ( empty($query) )
		{
			$this->result["error"] = "Query not defined";
			return $this->result;
		}
		else if ( empty($this->current_index) )
		{
			$this->result["error"] = "Index is not defined - please call \$YourPMBInstance->SetIndex(\$index_name); before searching";
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
			$combined_attrs = $main_sql_attrs + array("@sentiscore" => 1, "@score" => 1);

			# grouping attribute must be external
			if ( !isset($main_sql_attrs[$this->group_attr]) )
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
			$combined_attrs = $main_sql_attrs + array("@count" => 1, "@score" => 1);
			
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
			foreach ( $this->filter_by as $column => $filter_value_list ) 
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
			 $this->result["error"] = "Index " . trim($indexname) .  " does not support sorting/grouping results by sentiment";
			 return $this->result;	
		}

		if ( (int)$offset !== $offset || $offset < 0 ) 
		{
			$offset = 0;
		}
		
		if ( (int)$limit !== $limit || $limit < 1 ) 
		{
			$limit = 10;
		}
		
		# create field weight lookup
		$weighted_score_lookup = $this->CreateFieldWeightLookup();
		
		# count set bits for each index of weight_score_lookup -table
		foreach ( $weighted_score_lookup as $wi => $wscore )
		{
			$weighted_bit_counts[$wi] = substr_count(decbin($wscore), "1");
		}

		$query = mb_strtolower($query);

		# separate letters & numbers from each other?
		if ( $this->separate_alnum ) 
		{
			$query = preg_replace('/(?<=[a-z])(?=\d)|(?<=\d)(?=[a-z])/u', ' ', $query);
		}
		
		# if incoming query contains special characters that are not present in the current charset
		# try replacing them with corresponding values provided before
		if ( $this->dialect_matching && !empty($this->mass_find) )
		{
			$query = str_replace($this->mass_find, $this->mass_replace, $query);
		}

		#  remove ignore chars
		if ( !empty($this->ignore_chars) )
		{
			$query = str_replace($this->ignore_chars, "", $query);
		}
		
		# filter query with the charset regexp ( drops non-defined characters )
		$query = preg_replace($this->charset_regexp, " ", $query);

		/* Exact matching ?*/
		if ( substr_count($query, "\"") % 2 === 0 ) 
		{
			if ( preg_match_all('`"([^"]*)"`', $query, $m) )
			{
				#var_dump($m[1]);
				$exact_string = "";
				foreach ( $m[1] as $exact_sentence )
				{
					$exact_sentence = trim($exact_sentence);
					
					$hits = 0;
					str_replace(" ", "", $exact_sentence, $hits);
					if ( $hits )
					{
						# the letters/numbers between quotes form a sentence containing at least 2 words
						$sentence_parts = explode(" ", $exact_sentence);
						foreach ( $sentence_parts as $index => $exact_sentence_part )
						{
							if ( empty($exact_sentence) ) continue;
							# these words must be sequential in the target documents
							if ( !empty($sentence_parts[$index+1]) )
							{
								# this array contains word-pairs
								$exact_pairs[$sentence_parts[$index] . " " . $sentence_parts[$index+1]] = 1;
							}
						}
					}
					
					# this string contains all words that are required to be exactly like this
					$exact_string .= " $exact_sentence";
				}

				# tokenize exact string to create list of keywords to be exactly like provided
				foreach ( explode(" ", trim($exact_string)) as $exact_word )
				{
					#echo "exact word: $exact_word <br>";
					if ( empty($exact_word) )
					{
						continue;
					}
					
					if ( $exact_word[0] === "-" ) 
					{
						# non wanted keyword?
						$exact_word = mb_substr($exact_word, 1);
						$non_wanted_keywords[$exact_word] = 1;
					}
					
					$exact_words[$exact_word] = 1;
				}
			}
		}
		
		$query = trim(str_replace("\"", " ", $query));
		
		# if trimmed query is empty
		if ( empty($query) )
		{
			return $this->result;
		}
		
		$token_array = array_count_values(explode(" ", $query));
		$token_sql = array();
		$token_sql_stem = array();
		$token_escape = array();
		$token_order = array();
		$dialect_tokens = array();
		
		$tc = 0;
		$tsc = 0;
		$non_wanted_keywords = array();
		$keyword_pairs = array(); # [stem] => "original keyword" pairs
		# tokenize query
		foreach ( $token_array as $token => $token_count ) 
		{
			# create both SQL clauses ( tokens + prefixes ) at the same time
			if ( isset($token) && $token !== "" )
			{
				$non_wanted_temp = false;
				$disable_stemming_temp = false;
				
				# non wanted keyword?
				if ( $token[0] === "-" ) 
				{
					$token = mb_substr($token, 1); # trim
					$non_wanted_keywords[$token] = 1;
					$non_wanted_temp = true; 
				}
				# keyword that is not to be stemmed
				else if ( mb_substr($token, -1) === "*" ) 
				{
					$token = mb_substr($token, 0, -1); # trim
					$token_order[] = $token;
					$token_match_count[$token] = 0;	
					$disable_stemming_temp = true; # disable stemming for this keyword
				}
				else
				{
					$token_order[] = $token;
					$token_match_count[$token] = 0;
				}
				
				$keyword_pairs[$token] = $token;

				$token_sql[] = "(checksum = CRC32(?) AND token = ?)";
				$token_escape[] = $token;
				$token_escape[] = $token;
				++$tc;
		
				# if defined as exact keyword, do not stem
				if ( isset($exact_words[$token]) )
				{
					continue;
				}
				
				# if dialect matching is enabled
				if ( $this->dialect_matching && !empty($this->dialect_find) && !is_numeric($token) ) 
				{
					$nodialect = str_replace($this->dialect_find, $this->dialect_replace, $token);
					
					if ( $nodialect !== $token ) 
					{
						$token_sql_stem[] = "CRC32(?)";
						$token_escape_stem[] = $nodialect;
						
						$checksum_lookup[crc32($nodialect)] = $nodialect;

						$keyword_pairs[$nodialect] = $token;
						$dialect_tokens[$nodialect] = 1;
						
						# non wanted keyword?
						if ( $non_wanted_temp ) 
						{
							$non_wanted_keywords[$nodialect] = 1;
						}
					}
				}
				
				$keyword_len = mb_strlen($token);
				$min_len = $keyword_len;
				$stem = "";
				
				# if keyword stemming is disabled, ignore this step 
				if ( $this->keyword_stemming && !$disable_stemming_temp ) 
				{
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
				}
	
				# a stemmed version is available
				if ( !empty($stem) && $min_len < $keyword_len )
				{
					# add both keyword and the stem
					$token_sql_stem[] = "CRC32(?)";
					$token_sql_stem[] = "CRC32(?)";

					$token_escape_stem[] = $stem;
					$token_escape_stem[] = $token;
					
					$checksum_lookup[crc32($stem)] = $stem;
					$checksum_lookup[crc32($token)] = $token;
					
					# non wanted keyword?
					if ( $non_wanted_temp ) 
					{
						$non_wanted_keywords[$stem] = 1;
					}
						
					$keyword_pairs[$stem] = $token;
					++$tsc;
				}
				# no stemmed version available
				else if ( $min_len >= $this->prefix_length )
				{
					# only add the original
					$token_sql_stem[] = "CRC32(?)";
					
					$token_escape_stem[] = $token;
					
					$checksum_lookup[crc32($token)] = $token;
					++$tsc;
				}	
			}
		}

		# copy token_escape
		$non_stemmed_keywords = array_unique($token_escape);
		
		$switch_typecase = "(CASE token ";
		$temp = array();
		foreach ( $non_stemmed_keywords as $nonstem ) 
		{
			$switch_typecase .= "WHEN ? THEN 0 ";
			$temp[] = $nonstem;
		}
		$switch_typecase .= " ELSE 1 END) as type";
		$token_escape = array_merge($temp, $token_escape);

		# for special cases
		# queries like: genesis tonight tonight tonight ( same keyword multiple times )
        # phrase proximity must apply also for queries like this  
		$exploded_query = explode(" ", $query);
		$prev_token = "";
		
		foreach ( $exploded_query as $ind => $special_token ) 
		{
			if ( empty($special_token) ) continue;
			
			# non wanted word
			if ( $special_token[0] === "-" )
			{
				$special_token = mb_substr($special_token, 1);
			}
			
			if ( mb_substr($token, -1) === "*" )
			{
				$special_token = mb_substr($special_token, 0, -1);
			}
			
			# check for valid value and prevent duplicates
			if ( !empty($special_token) && empty($real_token_pairs[$prev_token][$special_token]) ) 
			{
				$real_token_order[] = $special_token;
				
				# create pairs to prevent duplicates
				if ( !empty($prev_token) )
				{
					#$real_token_pairs[$prev_token . " " . $special_token] = 1;
					$real_token_pairs[$prev_token][$special_token] = 1;
				}
				$prev_token = $special_token;
			}
		}

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
				$prefix_time_start = microtime(true);
				$prefix_grouper = array();
				$prefix_data = array();
				
				$ppdo = $this->db_connection->prepare(str_ireplace($find, $repl, "SELECT checksum, tok_data FROM PMBPrefixes WHERE checksum IN(" . implode(",", $token_sql_stem) . ")"));
				$ppdo->execute($token_escape_stem);	
					
				while ( $row = $ppdo->fetch(PDO::FETCH_ASSOC) )
				{
					$prefix_checksum = (int)$row["checksum"];

					$tok_checksums = array();
					$tok_cutlens = array();
		
					$delta = 1;
					$len = strlen($row["tok_data"]);
					$temp = 0;
					$shift = 0;
				
					for ( $i = 0 ; $i < $len ; ++$i )
					{
						$bits = $this->hex_lookup_decode[$row["tok_data"][$i]];
						$temp |= (($bits & 127) << $shift*7);
						++$shift;
						
						if ( $bits > 127 )
						{
							# 8th bit is set, number ends here ! 
							$delta = $temp+$delta-1;
							
							# get the 6 least significant bits
							$lowest6 = $delta & 63;
							# shift to right 6 bits
							$tok_checksums[] = ($delta >> 6);	# checksum of the token that this prefix points to
							$tok_cutlens[] = $lowest6;
							
							# reset temp variables
							$temp = 0;
							$shift = 0;
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
					# checksum === checksum of the token that this prefix points to
					$token_sql[] = "(checksum = ? AND token LIKE CONCAT('%', ?, '%'))";
					
					$token_escape[] = $checksum;
					$token_escape[] = $prefix_grouper[$checksum];

					++$i;
					
					if ( $i >= $this->expansion_limit )
					{
						break;
					}
				}
	
				$this->result["stats"]["prefix_time"] = microtime(true) - $prefix_time_start;
			}
			
			# run indexer if conditions allow it
			# auto indexing is enabled, indexer has not been run too recently, indexer is not already running
			if ( $this->indexing_interval && ( time() - ($this->indexing_interval*60) ) > $this->latest_indexing_done && !$this->index_state )
			{
				#echo "run the indexer ! ";
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
			
			$tic = 0;

			$token_order_rev = array_flip($token_order);
			ksort($token_order_rev);
	
			$non_wanted_doc_ids = array();
			$sumdata = array();
			$sumcounts = array();
			
			$payload_start = microtime(true);
			
			# switch to unbuffered mode
			$this->db_connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
			
			$tokpdo = $this->db_connection->prepare(str_ireplace($find, $repl, "SELECT token, $switch_typecase, doc_matches, ID, doc_ids FROM PMBTokens WHERE " . implode(" OR ", $token_sql)));
			$tokpdo->execute($token_escape);

			$pos_data_p = 0;
			$bin_separator = pack("H*", "80");
			
			unset($token_escape, $prefix_data, $token_sql, $tok_checksums, $tok_cutlens);
			#echo "Memory usage bef payload: " . memory_get_usage()/1024/1024 . " MB <br>";
			#echo "Memory usage bef payload (peak): " . memory_get_peak_usage()/1024/1024 . " MB <br>";
		
			# group results by token_id and select the min(distance) as keyword score modifier
			#foreach ( $final_row_data as $row ) 
			while ( $row = $tokpdo->fetch(PDO::FETCH_ASSOC) )
			{	
				# an exact match 
				if ( $row["type"] == 0 && empty($dialect_tokens[$row["token"]]) ) 
				{
					$token_score = 1;
					
					# non wanted keyword, store id and continue loop
					if ( !empty($non_wanted_keywords[$row["token"]]) )
					{
						$temp_ids = array_flip($this->GetDocumentIds($row["doc_ids"]));
						$non_wanted_doc_ids = $non_wanted_doc_ids + $temp_ids;
						continue;
					}
					
					++$token_match_count[$row["token"]];
					$token_ids[$row["ID"]] = $row["token"];
					
					# group results by the original order number of provided tokens
					# $token_order_rev[keyword] = order_number
					if ( empty($sumdata[$token_order_rev[$row["token"]]]) )
					{
						$sumdata[$token_order_rev[$row["token"]]] = array();
						$summatchcounts[$token_order_rev[$row["token"]]] = array();
					}
			
					$sumdata[$token_order_rev[$row["token"]]][] = (int)$row["ID"]; # store as int because exact match
					$sumcounts[$token_order_rev[$row["token"]]] = $row["doc_matches"]; # store how many doc_matches this token has
					$summatchcounts[$token_order_rev[$row["token"]]][] = $row["doc_matches"];
					
					$keyword_original_pos = $token_order_rev[$row["token"]];
				}
				# prefix match
				else
				{
					# non wanted keyword, store id and continue loop
					if ( !empty($non_wanted_keywords[$row["token"]]) )
					{
						$temp_ids = array_flip($this->GetDocumentIds($row["doc_ids"]));
						$non_wanted_doc_ids = $non_wanted_doc_ids + $temp_ids;
						continue;
					}
					
					if ( !empty($token_ids[$row["ID"]]) )
					{
						# already matches
						continue;
					}
					
					if ( empty($prefix_grouper[crc32($row["token"])]) )
					{
						echo "empty for: " . $row["token"] . " " . crc32($row["token"]) . "<br>";
						continue;
					}
					$current_prefix = $prefix_grouper[crc32($row["token"])];
					
					if ( !empty($non_wanted_keywords[$current_prefix]) )
					{
						$temp_ids = array_flip($this->GetDocumentIds($row["doc_ids"]));
						$non_wanted_doc_ids = $non_wanted_doc_ids + $temp_ids;
						continue;
					}
					
					if ( !empty($exact_words[$current_prefix]) )
					{
						continue;
					}

					$token_len 		= mb_strlen($row["token"]);
					$min_dist 		= $this->levenshtein_utf8($current_prefix, $row["token"]);
					$original_dist 	= $this->levenshtein_utf8($keyword_pairs[$current_prefix], $row["token"]);
					
					if ( $original_dist < $min_dist ) 
					{
						$min_dist = $original_dist;
					}
					
					# calculate token score
					$token_score = round(($token_len - $min_dist) / $token_len, 3);
					$keyword_pairs[$row["token"]] = $keyword_pairs[$current_prefix];
				
					# compare prefixes against keyword_pairs and find and  match [prefix]
					if ( empty($sumdata[$token_order_rev[$keyword_pairs[$row["token"]]]]) )
					{
						$sumdata[$token_order_rev[$keyword_pairs[$row["token"]]]] = array();
						$sumcounts[$token_order_rev[$keyword_pairs[$row["token"]]]] = 0;
						$summatchcounts[$token_order_rev[$keyword_pairs[$row["token"]]]] = array();
					}
	
					# do not overwrite better results ! 
					$sumdata[$token_order_rev[$keyword_pairs[$row["token"]]]][] = $row["ID"];
					$sumcounts[$token_order_rev[$keyword_pairs[$row["token"]]]] += $row["doc_matches"];	
					$summatchcounts[$token_order_rev[$keyword_pairs[$row["token"]]]][] = $row["doc_matches"];
					
					++$token_match_count[$keyword_pairs[$row["token"]]];
					
					$keyword_original_pos = $token_order_rev[$keyword_pairs[$row["token"]]];
				}
				
				# score lookup table for fuzzy matches
				$score_lookup[(int)$row["ID"]] = (float)$token_score;
	
				if ( SPL_EXISTS )
				{
					if ( isset($position_data) )
					{
						$position_data->setSize($pos_data_p + (int)$row["doc_matches"]);
						$document_ids->setSize($pos_data_p + (int)$row["doc_matches"]);
					}
					else
					{
						$position_data = new SplFixedArray((int)$row["doc_matches"]);
						$document_ids  = new SplFixedArray((int)$row["doc_matches"]);
					}
				}

				$token_id = (int)$row["ID"];
				
				# get list of document ids	
				$first_sep_pos = strpos($row["doc_ids"], pack("H*", "80"));

				# store binary data position in the position_data array
				$pointer_array_start[$token_id] = $pos_data_p;
				
				$token_matches = explode($bin_separator, substr($row["doc_ids"], $first_sep_pos+1));
				
				$hexstring = substr($row["doc_ids"], 0, $first_sep_pos);
				$delta = 1;
				$len = strlen($hexstring);
				$temp = 0;
				$shift = 0;
				$x = 0;
			
				for ( $i = 0 ; $i < $len ; ++$i )
				{
					$bits = $this->hex_lookup_decode[$hexstring[$i]];
					$temp |= (($bits & 127) << $shift*7);
					++$shift;
					
					if ( $bits > 127 )
					{
						# 8th bit is set, number ends here ! 
						$delta = $temp+$delta-1;

						$document_ids[$pos_data_p] = $delta;
						$position_data[$pos_data_p++] = $token_matches[$x];
						
						++$x;
						$temp = 0;
						$shift = 0;
					}
				}

				$pointer_array_end[$token_id] = $pos_data_p;

				++$tic;
			}
	
			# close cursor
			$tokpdo->closeCursor();
			
			# switch back to buffered mode
			$this->db_connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
			
			$this->result["stats"]["payload_time"] = microtime(true) - $payload_start;

			# no matches :(  empty($bm25_data) &&
			if ( $tic === 0 )
			{
				$this->LogQuery($query, 0); # log query
				return $this->result;
			}
			
			if ( $tic > 1 ) 
			{				
				ksort($sumdata);
				
				# query has duplicate tokens, rewrite phrase proximity query
				if ( count($token_order_rev) !== count($real_token_order) )
				{
					$sumdata_new = array();
					foreach ( $real_token_order as $index => $token ) 
					{
						if ( isset($token_order_rev[$token])  )
						{
							# duplicate results ( get the token db ids )
							$sumdata_new[] = $sumdata[$token_order_rev[$token]];
						}
					}
					
					$sumdata = $sumdata_new;
				}
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
			
			$token_pairs = array();			
			
			$i = 1;
			foreach ( $sumcounts as $k_index => $doc_matches ) 
			{
				# phrase score
				if ( !empty($sumcounts[$k_index+1]) )
				{
					foreach ( $sumdata[$k_index] as $token_id )
					{
						foreach ( $sumdata[$k_index+1] as $token_id_2 )
						{
							$token_pairs[$token_id][$token_id_2] = 1;
						}
					}
				}
				
				foreach ( $sumdata[$k_index] as $token_id )
				{
					$token_group_lookup[(int)$token_id] = $k_index;
				}

				# strict mode ? 
				if ( !empty($sumdata[$k_index+1]) && empty($sumdata[$k_index][1]) && empty($sumdata[$k_index+1][1]) && !empty($exact_pairs[$token_ids[$sumdata[$k_index][0]] . " " . $token_ids[$sumdata[$k_index+1][0]]]) ) 
				{
					$exact_ids_lookup[$sumdata[$k_index][0] . " " . $sumdata[$k_index+1][0]] = 1;
				}

				++$i;
			}

			if ( !empty($real_token_pairs) )
			{
				$reversed_tok_ids = array_flip($token_ids);
				$real_token_pair_ids = array();
				foreach ( $real_token_pairs as $token => $secondary ) 
				{
					foreach ( $secondary as $token_2 => $value ) 
					{
						if ( !empty($reversed_tok_ids[$token]) && !empty($reversed_tok_ids[$token_2]) )
						{
							$token_id = $reversed_tok_ids[$token];
							$token_id_2 = $reversed_tok_ids[$token_2];
							$token_pairs[$token_id][$token_id_2] = 1;
						}
					}
				}
			}
			
			# ensure that all provided keywords return results
			foreach ( $token_match_count as $token => $match_count ) 
			{
				if ( $match_count === 0  ) 
				{
					# no matches for a certain keyword
					$this->LogQuery($query, 0); # log query
					return $this->result;
				}
			}

			$token_count = count($token_order_rev);
	
			$data_start = microtime(true);
			
			$required_bits = 0;
			for ( $x = 0 ; $x < $token_count ; ++$x ) 
			{
				$required_bits |= (1 << $x);
			}

			if ( $this->matchmode === PMB_MATCH_ANY )
			{
				# any single matched keyword will do if matchmode is PMB_MATCH_ANY
				foreach ( $pointer_array_start as $token_id => $startpos ) 
				{
					# iterate through document ids array
					for ( $i = $startpos ; $i < $pointer_array_end[$token_id] ; ++$i )
					{
						$doc_id = $document_ids[$i];
						if ( empty($non_wanted_doc_ids[$doc_id]) )
						{
							$groups[$doc_id][$token_id] = $i;
						}
					}
				}
			}
			else
			{
				foreach ( $pointer_array_start as $token_id => $startpos ) 
				{
					# iterate through document ids array ( for current token id ) 
					for ( $i = $startpos ; $i < $pointer_array_end[$token_id] ; ++$i )
					{
						$doc_id = $document_ids[$i];
						if ( !isset($non_wanted_doc_ids[$doc_id]) )
						{
							if ( !isset($grouper[$doc_id]) ) $grouper[$doc_id] = 0;
							$grouper[$doc_id] |= 1 << $token_group_lookup[$token_id];
						}
					}
				}

				# otherwise all provided keywords must be found ( PMB_MATCH_ALL , PMB_MATCH_STRICT )
				foreach ( $pointer_array_start as $token_id => $startpos ) 
				{
					# iterate through document ids array
					for ( $i = $startpos ; $i < $pointer_array_end[$token_id] ; ++$i )
					{
						$doc_id = $document_ids[$i];
							
						if ( !isset($non_wanted_doc_ids[$doc_id]) && $grouper[$doc_id] === $required_bits )
						{
							$groups[$doc_id][$token_id] = $i;		
						}
					}
				}
			}
			
			unset($grouper, $document_ids, $pointer_array_start, $pointer_array_end);
	
			$this->result["total_matches"] = 0;

			$temp_doc_id_sql = "";
			$total_matches	 = 0;
			$tmp_matches 	= 0;
				
			if ( !empty($exact_ids_lookup) )
			{
				# we are looking for keywords in certain order
				$exact_mode = true;
			}
			else
			{
				# no certain order required
				$exact_mode = false;
			}

			$disable_score_calculation = false;
			if ( isset($this->non_scored_sortmodes[$this->sortmode]) && 
				$this->group_sort_attr 	!== "@score" 				 && 
				$this->group_sort_attr 	!== "@sentiscore"			 && 
				$this->sort_attr		!== "@score" ) {
					
				$disable_score_calculation = true;
			}
			
			$last_index = $token_count-1;
			$scored_count = 0;

			foreach ( $groups as $doc_id => $token_data ) 
			{
					++$scored_count;
					# skip the whole score calculation phase if we are sorting by an external attribute
					# and there is no strict keyword order lookup
					if ( !$exact_mode && $disable_score_calculation && $this->matchmode !== PMB_MATCH_STRICT)
					{
						if ( $tmp_matches > 0 ) $temp_doc_id_sql .= ",";
						$temp_doc_id_sql .= $doc_id;
						++$tmp_matches;	
						continue;
					}
					
					$tempdata = array();
					$exact_match = false;
					if ( $exact_mode ) $temp_strict_lookup = $exact_ids_lookup; # create a temporary copy 	
					
					foreach ( $groups[$doc_id] as $token_id => $position ) 
					{
						$qind = $token_group_lookup[$token_id];
						
						if ( !isset($tempdata[$qind][0]) )
						{
							# format tempdata array
							$tempdata[$qind][0] = 0;
							$tempdata[$qind][1] = 0;
							$tempdata[$qind][2] = 0;
							$tempdata[$qind][3] = 0;
							$tempdata[$qind][4] = 0;
						}
						
						# better quality score for this result group
						if ( $score_lookup[$token_id] > $tempdata[$qind][2] )
						{
							$tempdata[$qind][2] = $score_lookup[$token_id];
						}
						
						/* 
							due to elimination of identical sequential values
							find first non-empty binary string  
							by traveling backwards if necessary
						*/

						while ( $position_data[$position] === "" )
						{
							--$position;
						}
						
						$len = strlen($position_data[$position]);
						$temp = 0;
						$shift = 0;
						$delta = 1;
						$count = 0;
						$x = 0;
					
						for ( $i = 0 ; $i < $len ; ++$i )
						{
							$bits = $this->hex_lookup_decode[$position_data[$position][$i]];
							$temp |= (($bits & 127) << $shift*7);
							++$shift;
							
							if ( $bits > 127 )
							{
								# 8th bit is set, number ends here ! 
								if ( $x === 0 ) 
								{
									if ( $this->sentiment_analysis ) 
									{
										# get 8 lsb bits for the sentiscore 
										# ( don't forget the unsigned --> signed conversion )
										$tempdata[$qind][4] += (($temp&255)-128);
										
										# shift to right to get number of occurances
										$temp >>= 8;
									}
									
									# count
									$tempdata[$qind][3] += $temp;
								}
								else
								{
									$delta = $temp+$delta-1;
									$token_id_2 = $delta;
								
									# get the field_id bits + token_id_2 bits
									$field_bits = $token_id_2 & $this->lsbits;
									$token_id_2 >>= $this->number_of_fields; # shift to right (number of fields) bits
									
									# if this is the last token of current field(s)
									if ( $token_id_2 === 0 && isset($token_group_lookup[$token_id]) && $token_group_lookup[$token_id] === $last_index ) 
									{
										$exact_match = true;
									}
	
									/* exact_pairs */
									if ( !empty($token_pairs[$token_id][$token_id_2]) )
									{
										# (re)set strict lookup array index as this pair has been found
										$temp_strict_lookup[$token_id . " " . $token_id_2] = 0;
										
										# phrase score match 
										$tempdata[$qind][0] |= $field_bits; # field bits
									}
									
									# self score match
									$tempdata[$qind][1] |= $field_bits;
								}
								
								++$x;
								$temp = 0;
								$shift = 0;
							}
						}
					}
					
					# strict query operators were not satisfied
					if ( $exact_mode && array_sum($temp_strict_lookup) !== 0 ) 
					{
						#echo "no exact match for doc id $doc_id \n";
						continue;
					}
					else if ( $this->matchmode === PMB_MATCH_STRICT && !$exact_match )
					{
						continue;
					}
					
					++$total_matches;
					
					# skip the final score calculation phase if we are sorting by an external attribute
					if ( $exact_mode && $disable_score_calculation )
					{
						if ( $total_matches > 1 ) $temp_doc_id_sql .= ",";
						$temp_doc_id_sql .= $doc_id;	
						continue;
					}

					$phrase_score 	= 0;
					$bm25_score 	= 0;
					$self_score 	= 0;
					$maxscore_total = 0;
					$sentiscore		= 0;
					
					foreach ( $tempdata as $vind => $value ) 
					{
						
						# value[0] phrase score
						# value[1] self_score
						# value[2] maxscore
						# value[3] count
						$phrase_score 	+= $weighted_score_lookup[$value[0]];
						$self_score 	|= $value[1]; 
						$maxscore_total += $value[2];
						
						# calculate sentiment score ?
						if ( $sentimode ) 
						{
							# field weightning enabled
							if ( $this->sentiweight )
							{
								$sentiscore	+= $weighted_score_lookup[$value[0]] + $value[4] - $weighted_bit_counts[$value[0]];
							}
							# field weightning disabled
							else
							{	
								$sentiscore	+= $value[4];
							}
						}

						$effective_match_count = $weighted_score_lookup[$value[0]] + $value[3] - $weighted_bit_counts[$value[0]];

						$bm25_score		+= log(($this->documents_in_collection - $sumcounts[$vind] + 1) / $sumcounts[$vind]) / ((1 + 1.2/$effective_match_count) * log(1+$this->documents_in_collection));
					}
		
					# calculate self_score
					$final_self_score = $weighted_score_lookup[$self_score];
					
					# is quality scoring enabled ? 
					if ( $this->quality_scoring )
					{
						$score_multiplier = $maxscore_total/count($tempdata);
					}
					else
					{
						$score_multiplier = 1;
					}
					
					switch ( $this->rankmode )
					{
						case PMB_RANK_PROXIMITY_BM25:
						$temp_matches[$doc_id] = (int)((($phrase_score + $final_self_score) * 1000 + round((0.5 + $bm25_score / (2*$token_count)) * 999)) * $score_multiplier);
						break;
						
						case PMB_RANK_BM25:
						$temp_matches[$doc_id] = (int)(round((0.5 + $bm25_score / (2*$token_count)) * 999) * $score_multiplier);
						break;
						
						case PMB_RANK_PROXIMITY:
						$temp_matches[$doc_id] = (int)((($phrase_score + $final_self_score) * 1000) * $score_multiplier);
						break;
					}
					
					# special case: store sentiment score if sorting/grouping by sentiment score
					if ( $sentimode )
					{
						$temp_sentiscores[$doc_id] = $sentiscore;
					}
			}
			
			if ( $tmp_matches ) $total_matches = $tmp_matches;

			$this->result["stats"]["processing_time"] = microtime(true) - $data_start;
			$this->result["total_matches"] = $total_matches;
			
			if ( $total_matches === 0 ) 
			{
				# no results
				return $this->result;
			}
			
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
					if ( $column[0] === "!" )
					{
						$column = substr($column, 1);
						$comparator_min = "<=";
						$comparator_max = ">=";
					}
					
					$filter_by 	= array();
					foreach ( $values as $filter_by_value ) 
					{
						$filter_by[] = "attr_$column $comparator_min " . $filter_by_value[0] . " AND attr_$column $comparator_max " . $filter_by_value[1];
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
				foreach ( $temp_matches as $doc_id => $score ) 
				{
					$temp_sql .= ",$doc_id";
				}
				$temp_sql[0] = " ";

				$sentisql = "SELECT ID, avgsentiscore FROM PMBDocinfo WHERE ID IN ($temp_sql) $filter_by_sql";
				$sentipdo = $this->db_connection->query(str_replace($find, $repl, $sentisql));	

				$max_doc_score = (int)(($tc - count($non_wanted_keywords)) * array_sum($this->field_weights) * 1000) + 999;
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
					$doc_id = (int)$row[0];
					
					switch ( $sentimode ) 
					{
						case 1:
						# points/maxpoints * avgsentiscore + (((maxpoints - points)/maxpoints) * sentiscore )
						$temp_sentiscores[$doc_id] = (int)($temp_matches[$doc_id]/$max_doc_score * $row[1] + ((($max_doc_score-$temp_matches[$doc_id])/$max_doc_score) * $temp_sentiscores[$doc_id]));
						break;
						
						case 2:
						# only document score
						$temp_sentiscores[$doc_id] = (int)$row[1]; 
						break;

						case 3:
						# predefined balance between sentence and document score
						$temp_sentiscores[$doc_id] = (int)($row[1]*$relevancy_factor + $temp_sentiscores[$doc_id]*$phrase_prox_fctr); 
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
				if ( strpos($this->group_sort_attr, "@") === false ) 
				{
					$group_sort_attr = ",attr_" . $this->group_sort_attr;
				}
				
				$group_sort_start = microtime(true);
				
				if ( !empty($temp_matches) )
				{
					$temp_sql = "";
					foreach ( $temp_matches as $doc_id => $score ) 
					{
						$temp_sql .= ",$doc_id";
					}
					$temp_sql[0] = " ";
					$groupsql = "SELECT ID, $group_attr $group_sort_attr FROM PMBDocinfo WHERE ID IN ($temp_sql) $filter_by_sql";
					
				}
				else
				{
					$groupsql = "SELECT ID, $group_attr $group_sort_attr FROM PMBDocinfo WHERE ID IN ($temp_doc_id_sql) $filter_by_sql";
				}
				
				$filtering_done = true;
				$grouppdo = $this->db_connection->query(str_replace($find, $repl, $groupsql));	
				unset($groupsql, $temp_sql);
				
				if ( $this->group_sort_attr === "@score" || $this->group_sort_attr === "@sentiscore" )
				{
					while ( $row = $grouppdo->fetch(PDO::FETCH_ASSOC) )
					{
						$doc_id 	= (int)$row["ID"];
						$attr_val 	= (int)$row[$group_attr];
	
						if ( isset($temp_groups[$attr_val]) )
						{	
							if ( $this->group_sort_attr === "@score" ) 
							{			
								# use document score/weight/rank values		
								$new_ref_value 	= $temp_matches[$doc_id];
								$old_ref_value 	= $temp_matches[$temp_groups[$attr_val]];
							}
							else
							{
								# use sentiment score values
								$new_ref_value 	= $temp_sentiscores[$doc_id];
								$old_ref_value 	= $temp_sentiscores[$temp_groups[$attr_val]];
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
					# trim external group sort attr to get a proper column name
					$group_sort_attr = str_replace(",", "", $group_sort_attr);
					
					while ( $row = $grouppdo->fetch(PDO::FETCH_ASSOC) )
					{
						$doc_id 		= (int)$row["ID"];
						$attr_val 		= (int)$row[$group_attr];
						$sort_attr_val	= (int)$row[$group_sort_attr];
	
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
					
					# not needed anymore
					unset($temp_group_attrs);
				}
				
				# close instance cursor
				$grouppdo->closeCursor();
				
				# enable buffered queries
				$this->db_connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
				
				# reformat temporary grouping table ( for group counting )
				#$temp_groups_copy = $temp_groups;
				if ( empty($temp_groups) )
				{
					$this->result["total_matches"] = 0;
					return $this->result;
				}
				
				$temp_groups = array_flip($temp_groups);
				
				if ( $this->group_sort_attr === "@sentiscore" )
				{
					if ( !empty($temp_sentiscores) )
					{
						foreach ( $temp_sentiscores as $doc_id => $score ) 
						{
							if ( !isset($temp_groups[$doc_id]) )
							{
								unset($temp_sentiscores[$doc_id]);	# unset unnecessary doc_ids
							}
						}
					}
				}
				else 
				{
					# now results have been grouped
					# remove unnecessary indexes from temp_matches array				
					if ( !empty($temp_matches) )
					{
						foreach ( $temp_matches as $doc_id => $score ) 
						{
							if ( !isset($temp_groups[$doc_id]) )
							{
								unset($temp_matches[$doc_id]); # unset unnecessary doc_ids
							}
						}
					}
					else
					{
						# temp_matches is not set => so we are sorting by an external attribute
						# rewrite $temp_doc_id_sql, because it surely has been changed
						$temp_doc_id_sql = "";
						foreach ( $temp_groups as $attr => $doc_id ) 
						{
							$temp_sql .= ",$doc_id";
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
				# rewrite $temp_groups with group match counts
				foreach ( $temp_groups as $doc_id => $attr )
				{
					$temp_groups[$doc_id] = $temp_counter[$attr];
				}
				
				# not needed anymore
				unset($temp_counter);
				
				# then, sort according to sorting order
				if ( $this->sortdirection === "desc" )
				{
					arsort($temp_groups);
				}
				else
				{
					asort($temp_groups);
				}
				
				#$temp_matches = $temp_groups;
				unset($temp_group_attrs); # not needed anymore
				# fetch docinfo for documents within the given LIMIT and OFFSET values
				$i = 0;
				$doc_ids = array();
				foreach ( $temp_groups as $doc_id => $count ) 
				{
					if ( $i >= $offset )
					{
						if ( $i === $offset+$limit )
						{
							break;
						}
						
						$this->result["matches"][$doc_id]["@count"] = $count;
						if ( isset($temp_matches[$doc_id]) ) 
						{
							$this->result["matches"][$doc_id]["@score"] = $temp_matches[$doc_id];
						}
					}
					
					++$i;
				}
				
			
			}
			else if ( $disable_score_calculation )
			{
				# fetch external data 
				$column_name = "attr_".$this->sort_attr;
				$filtering_done = true;
				$sortsql = "SELECT ID, $column_name FROM PMBDocinfo WHERE ID IN ($temp_doc_id_sql) $filter_by_sql ORDER BY $column_name " . $this->sortdirection . " LIMIT $offset, $limit";
				$sortpdo = $this->db_connection->query(str_replace($find, $repl, $sortsql));	
				unset($temp_matches, $temp_groups);
				
				while ( $row = $sortpdo->fetch(PDO::FETCH_ASSOC) )
				{
					$data = array($column_name => (int)$row[$column_name]);
					$this->result["matches"][(int)$row["ID"]] = $data;
				}
			}
			else
			{
				if ( !empty($temp_sentiscores) )
				{
					$temp_matches = $temp_sentiscores;
				}
				
				if ( !isset($filtering_done) && !empty($filter_by_sql) )
				{
					$temp_sql = "";
					foreach ( $temp_matches as $doc_id => $score ) 
					{
						$temp_sql .= ",$doc_id";
					}
					$temp_sql[0] = " ";
					
					# filter_by must be done here
					$this->db_connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
					$sortsql = "SELECT ID FROM PMBDocinfo WHERE ID IN ($temp_sql) $filter_by_sql";
					$sortpdo = $this->db_connection->query(str_replace($find, $repl, $sortsql));	
					unset($temp_groups, $sortsql, $temp_sql);
					# , " . implode(",", array_keys($wanted_attributes)) ." # external attributes are not needed this time
					
					$t_count = 0;
					
					# resultcount needs to be recalculated
					while ( $row = $sortpdo->fetch(PDO::FETCH_ASSOC) )
					{
						$t_matches[(int)$row["ID"]] = $temp_matches[(int)$row["ID"]]; # copy the scores
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
					
					$temp_matches = $t_matches;
					unset($t_matches);
				}

				switch ( $this->sortmode ) 
				{
					case PMB_SORTBY_RELEVANCE:
					case PMB_SORTBY_POSITIVITY: 
					arsort($temp_matches);
					break;
						
					case PMB_SORTBY_NEGATIVITY:
					asort($temp_matches);	
					break;
					
					case PMB_SORTBY_ATTR:
					if ( $this->sortdirection === "desc" )
					{
						arsort($temp_matches);
					}
					else
					{
						asort($temp_matches);	
					}
					break;
				}

				# fetch docinfo for documents within the given LIMIT and OFFSET values
				$i = 0;
				$doc_ids = array();
				foreach ( $temp_matches as $doc_id => $score ) 
				{
					if ( $i >= $offset )
					{
						if ( $i === $offset+$limit )
						{
							break;
						}
						
						$this->result["matches"][$doc_id]["@score"] = $score;
						if ( isset($temp_groups[$doc_id]) )
						{
							$this->result["matches"][$doc_id][$this->group_attr] = $temp_groups[$doc_id];
						}
					}
					
					++$i;
				}
			}

			# fetch docinfo separately
			if ( $this->index_type == 1 )
			{	
				$ext_docinfo_start = microtime(true);
			
				$docsql		= "SELECT ID as doc_id, SUBSTRING(field0, 1, 150) AS title, URL, field1 AS content FROM PMBDocinfo WHERE ID IN (".implode(",", array_keys($this->result["matches"])).")";
				$docsql 	= str_replace($find, $repl, $docsql);
				$docpdo 	= $this->db_connection->query($docsql);

				while ( $row = $docpdo->fetch(PDO::FETCH_ASSOC) )
				{
					$row["content"] = $this->SearchFocuser($row["content"], $query, "fi", 40);
					$this->result["matches"][(int)$row["doc_id"]] = $row;
				}	
				
				$this->result["stats"]["ext_docinfo_time"] = microtime(true)-$ext_docinfo_start;
			}
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
	
	public function SetFieldWeights($input = array())
	{
		if ( !empty($input) && is_array($input) )
		{
			foreach ( $input as $index => $value ) 
			{
				if ( (int)$value === $value && $value >= 0 && in_array($value, $this->data_columns) ) 
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
		else if ( isset($mode) && is_numeric($mode) && $mode >= 0 && $mode <= 100  && (int)$mode === $mode ) 
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
		if ( (int)$mode === $mode && $mode >= 1 && $mode <= 2) 
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
		if ( (int)$value === $value )
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
		if ( (int)$min === $min && (int)$max === $max )
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
		if ( (int)$mode === $mode && $mode >= 1 && $mode <= 3 )
		{
			$this->matchmode = $mode;
			
			return true;
		}
		
		echo "Error: invalid matching mode";
		return false;
	}
	
	public function SetRankingMode($mode)
	{
		if ( (int)$mode === $mode && $mode >= 1 && $mode <= 3 )
		{
			$this->rankmode = $mode;
			
			return true;
		}
		
		echo "Error: invalid ranking mode";
		return false;
	}
	
	public function SetSortMode($mode, $attribute = "")
	{
		if ( (int)$mode === $mode && $mode > 0 && $mode <= 4) 
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
		if ( !empty($input) ) 
		{
			$this->sentiweight = 1;
		}
		else
		{
			$this->sentiweight = 0;
		}
		return true;
	}
	
	public function KeywordStemming($input) 
	{
		if ( !empty($input) )
		{
			$this->keyword_stemming = 1;
		}
		else
		{
			$this->keyword_stemming = 0;
		}
		return true;
	}
	
	public function DialectMatching($input)
	{
		if ( !empty($input) )
		{
			$this->dialect_matching = 1;
		}
		else
		{
			$this->dialect_matching = 0;
		}
		return true;
	}
	
	public function QualityScoring($input)
	{
		if ( !empty($input) )
		{
			$this->quality_scoring = 1;
		}
		else
		{
			$this->quality_scoring = 0;
		}
		return true;
	}
		
	public function ExpansionLimit($input)
	{
		if ( isset($input) && (int)$input === $input && $input > 0 ) 
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
	
	private function GetDocumentIds($binarydata)
	{
		$start = 0;
		$pos = 0;
		$doc_ids = array();
					
		# get position of first zero delimiter
		$pos = strpos($binarydata, pack("H*", "80"));
					
		$doc_ids = $this->VBDeltaDecode(substr($binarydata, 0, $pos));
			
		return $doc_ids;		
	}

	public function SearchFocuser($string, $searchstring, $lang = 'fi', $wordwrap = 90, $max_len = 150)
	{
		$min_prefix_len = 4;
		#$max_len = 150;
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
				if ( !empty($chunk) )
				{	
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
							
							#echo $match[0]. " vs $chunk  vs $stemmed_keyword <br>";
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
					#echo "Min!<br>";
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
					#$loop_pos = 110
					foreach ( $poslist as $p_index => $pos )
					{
						$t = abs($pos-$max_key_pos);
						#echo "DIFF: $t POS: ($pos - $max_key_pos)<br>";
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
			
			#echo $max_len;
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
					#echo "FOUND PROPER ENDING! <br>";
					$endpoints[$i] = max($trim_results_end);
				}
				# last resort: space
				else if ( !empty($space_result_end) )
				{
					#echo "FOUND SPACE ENDING! <br>";
					$endpoints[$i] = $space_result_end;
					
					if ( empty($positions[$i+1]) ) $post[$i] = "...";
				}
				
				else if ( empty($positions[$i+1]) && $endpoints[$i]-$startpoints[$i] > $max_len )
				{
					#echo "HARDCUT! $string_len vs $max_len <br>";
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
				#echo "$chunk <br>";
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

		return preg_match("#$c{2}$#", $str, $matches) AND $matches[0]{0} == $matches[0]{1};
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
			   AND $matches[1]{2} != 'w'
			   AND $matches[1]{2} != 'x'
			   AND $matches[1]{2} != 'y';
	}
}

?>