<?php		

            while ( true ) 
			{
				$finished = 0;
				$skipped = 0;
				$end = 0;

				# pre-insert max document ids
				if ( !empty($maximum_doc_ids_vals) )
				{
					foreach ( $maximum_doc_ids_vals as $t_index => $doc_id )
					{
						if ( $doc_id >= $min_doc_id && $doc_id <= $max_doc_id )
						{
							$group_bits = 1 << $sorted_groups[$t_index];
							$t_matches_awaiting[$doc_id][$t_index] = $group_bits;
							$temp_doc_ids_storage[$t_index][$doc_id] = 1;
							
							// store value only if we are in the current seek-range
							if ( isset($t_matches[$doc_id]) ) 
							{
								$t_matches[$doc_id] |= $group_bits;	
							}
							else
							{
								$t_matches[$doc_id] = $group_bits;
							}
							
							++$undone_values[$t_index];
							
							unset($maximum_doc_ids_vals[$t_index]);
						}
					}
				}

				foreach ( $sorted_groups as $group => $token_group ) 
				{
					$original_token_group = $group;
					
					if ( isset($duplicate_groups[$group]) ) 
					{
						# overwrite group/token group values if this is a duplicate keyword
						if ( $fast_external_sort ) 
						{
							++$skipped;
							continue;
						}
						
						$group = $duplicate_groups[$group];
						$token_group = $sorted_groups[$group];
					}
					else
					{
						$group_bits = 1 << $token_group; # this is a reference to the keyword order number
					}
					
					$encoded_group = $this->hex_lookup_encode[$group];
					
					if ( $encode_delta[$group] < $min_doc_id )  
					{
						++$skipped;
						continue; // skip this group
					}
					else if ( $encode_pointers[$group] <= 0 && !$undone_values[$group] )
					{
						++$skipped;
						++$end;
						continue;
					}

					$delta 			= $encode_delta[$group];
					$temp 			= $encode_temp_docs[$group];
					$bin_data 		= &$encoded_data[$group];	# reference to document id data
					$i 				= $encode_pointers[$group]; # string pointer of compressed document id data
					$vals 			= $undone_values[$group];	# how many match position values waiting to be decoded ( for this group )
					$matchpos_data	= &$doc_match_data[$group];	# reference to keyword match position data
					$matchpos_len	= $doc_lengths[$group];		# keyword match position data length
					
					if ( isset($temp_doc_ids_storage[$group]) )
					{
						$temp_doc_ids = $temp_doc_ids_storage[$group];
					}
					else
					{
						$temp_doc_ids = array();
					}
		
					# reset undone values
					$undone_values[$group] = 0;
	
					// decode first (min) doc_id of each result group
					while ( $i ) 
					{
						--$i;

						if ( ($next_bits = $this->hex_lookup_decode[$bin_data[$i]]) < 128 ) 
						{
							$temp = ($temp << 7) | $next_bits; # the new bits get added as LSBs	
						}
						else
						{
							$delta = $delta - $temp + 1; 
							$temp = $next_bits-128;

							if ( $delta >= $min_doc_id )
							{
								$temp_doc_ids[$delta] = 1;
								++$vals;
					
								// store value only if we are in the current seek-range
								if ( isset($t_matches[$delta]) ) 
								{
									$t_matches[$delta] |= $group_bits;	
								}
								else
								{
									$t_matches[$delta] = $group_bits;
								}
							}
							else
							{
								++$finished;
								
								if ( empty($t_matches_awaiting[$delta][$group]) ) 
								{
									$t_matches_awaiting[$delta][$group] = $group_bits;
								}
								else
								{
									$t_matches_awaiting[$delta][$group] |= $group_bits;
								}
					
								break;
							}
						}
					}

					$encode_delta[$group] 		= $delta;
					$encode_temp_docs[$group] 	= $temp;
					$encode_pointers[$group] 	= $i;

					if ( $vals && $matchpos_len ) 
					{
						$travel = (int)($avgs[$group]*$vals);
						$p = $doc_pos_pointers[$group]-$travel; # maybe something funny here ?  len 100  , travel 4 => start 96
 
						if ( $p < 0 ) 
						{
							$p = 0; #  cannot be smaller than 0
							$travel = $doc_pos_pointers[$group]; # to get to 0 from doc_pos_pointers, doc_pos_pointers chars must be travelled
							if ( $travel < 1 ) 
							{
								$travel = 1;
							}
						}

						$got = substr_count($doc_match_data[$group], $bin_sep, $p, $travel);
						$balance = $got-$vals;

						if ( $balance >= 0 ) 
						{	
							# increment the pointer ( forward towards end of the string) 
							do
							{
								if ( $matchpos_data[$p] === $bin_sep ) 
								{
									--$balance;
								}
								++$p;
								
							} while ( $balance !== -1 && $p !== $matchpos_len );
						}
						else
						{	
							# balance is negative, we got less values than needed
							if ( $p ) 
							{
								do
								{
									--$p;
									if ( $p !== -1 && $matchpos_data[$p] === $bin_sep ) 
									{
										++$balance;
									}
								} while ( $balance !== 0 && $p !== -1 );
								
								# go forward to the first non binary separator char
								++$p;
							}
						}
	
						$travel_len = $doc_pos_pointers[$group] - $p;
						if ( !$travel_len ) $travel_len = 1;

						$data = explode($bin_sep, substr($doc_match_data[$group], $p, $travel_len));
						$doc_pos_pointers[$group] = $p-1;

						$l = $vals-1;
						foreach ( $temp_doc_ids as $doc_id => $string ) 
						{	
							if ( !empty($loop_doc_positions[$doc_id]) ) 
							{
								$loop_doc_positions[$doc_id] .= $bin_sep.$encoded_group.$data[$l];
							}
							else
							{
								$loop_doc_positions[$doc_id] = $encoded_group.$data[$l];
							}
							
							--$l;
						}
						
						unset($temp_doc_ids, $data);
						$temp_doc_ids_storage[$group] = array();						
					}
					
					# this group is done
					if ( $i <= 0 )
					{
						++$end;
					}
				} # <---- foreach group ends 
						
				if ( $end >= $group_count ) $stop = true;
								
				# all groups have finished, lets check the results
				if ( $finished >= $group_count || $skipped >= $group_count || $stop ) 
				{
					if ( $stop && !empty($t_matches_awaiting) ) 
					{
						foreach ( $t_matches_awaiting as $doc_id => $data ) 
						{
							if ( $doc_id >= $min_doc_id && $doc_id <= $max_doc_id )
							{
								foreach ( $t_matches_awaiting[$doc_id] as $group => $bits ) 
								{
									$undone_values[$group] = 1;
									$temp_doc_ids_storage[$group][$doc_id] = $bits;
									
									if ( !empty($t_matches[$doc_id]) )
									{
										$t_matches[$doc_id] |= $bits;
									}
									else
									{
										$t_matches[$doc_id] = $bits;
									}
								}
								
								unset($t_matches_awaiting[$doc_id]);
							}
						}
					}
					
					$t = 0;
					$total_documents += count($t_matches);
				
					# get documents match position data
					foreach ( $t_matches as $doc_id => $bits ) 
					{
						if ( ($bits & $reference_bits) === $goal_bits )
						{
							# skip the whole score calculation phase if we are sorting by an external attribute
							# and there is no strict keyword order lookup
							if ( $fast_external_sort )
							{
								$temp_doc_id_sql .= ",$doc_id";
								++$tmp_matches;	
								continue;
							}
							else if ( $exact_group_pairing_lookup ) 
							{
								$exact_group_pairing_lookup_copy = $exact_group_pairing_lookup;
							}

							# reset old variables
							unset($best_match_score, $phrase_data, $document_count, $sentiment_data);
							
							$match_position_string = &$loop_doc_positions[$doc_id];
							$data_len		 	= strlen($loop_doc_positions[$doc_id]);
							$phrase_score 		= 0;
							$bm25_score 		= 0;
							$maxscore_total 	= 0;
							$sentiscore			= 0;
							$strict_match 		= 0;
							$tmp_position_storage 	= array();
							$prev_position_storage	= array();
							$forbidden_position_storage = array();
							
							$t_group 			= $this->hex_lookup_decode[$match_position_string[0]];
							$qind				= $sorted_groups[$t_group];
							$prev_group 		= $qind; # we need to set this equal to $qind ( the current main group )
							$skip_group_id		= 0; # skip this group id ($qind), 0 by default (no pair matching for the first group)						
							$document_field_hits = 0; 	# holds individual field bits found from this document
							$group_field_hits	= 0; 	# holds group specific field bits  => if/when this is equal to $this->all_field_bits_set => skip rest of the group ($qind)
	
							# initialize temporary array variables for each token group
							$phrase_data[$qind] 		= 0; # for phrase score bits
							$document_count[$qind] 		= 0; # how many documents for this token group
							$best_match_score[$qind] 	= $score_lookup_alt[$t_group]; # maxscore ( token quality )
							
							$temp = 0;
							$shift = 0;
							$delta = 1;
							$x = 0;
	
							for ( $i = 1 ; $i < $data_len ; ++$i )
							{
								$bits = $this->hex_lookup_decode[$match_position_string[$i]];
								
								if ( $bits === 128 )
								{
									# increase document match count for the previous token group ( if sentiment analysis is on, decrement the count by one ) 
									$document_count[$qind] += $x - $this->sentiment_index;
									
									# zero, as in binary separator
									# token changes (as in token subgroup changes)
									
									++$i; # first char will be the group
									$t_group 	= $this->hex_lookup_decode[$match_position_string[$i]];
									$tmp_group	= $sorted_groups[$t_group];
									
									# check if main token group_id changes
									# this should execute only when $qind > 0 
									if ( $qind !== $tmp_group ) 
									{
										$prev_group = $qind;
										$qind = $tmp_group;
										$prev_position_storage 	= $forbidden_position_storage + $tmp_position_storage;
										$tmp_position_storage 	= array();
	
										# store field_bits from the old group
										$phrase_data[$prev_group] = $group_field_hits;

										# reset group specific field bit flags
										$group_field_hits = 0; 
									}

									if ( !isset($best_match_score[$qind]) )
									{
										# initialize temporary array variables for each token group
										$document_count[$qind] 		= 0; # how many documents for this token group
										$best_match_score[$qind] 	= $score_lookup_alt[$t_group]; # maxscore ( token quality )
									}
									# better quality score for this result group
									else if ( $score_lookup_alt[$t_group] > $best_match_score[$qind] )
									{
										$best_match_score[$qind] 	= $score_lookup_alt[$t_group];
									}
											
									# reset temporary variables		
									$temp = 0;
									$shift = 0;
									$delta = 1;
									$x = 0;
								}
								else if ( $bits < 128 ) 
								{
									# number is yet to end
									# check also gere if shift is === 0 ( then temp = bits; )
									$temp |= $bits << $shift*7;
									++$shift;
								}
								else
								{
									# 8th bit is set, number ends here ! 
									
									if ( $x < $this->sentiment_index )
									{
										$sentiscore += ($temp|($bits-128 << $shift*7))-128;
										$temp = 0;
										$shift = 0;
									}
									else
									{
										# otherwise this value is keyword position in document
										if ( $shift ) 
										{
											$delta = ($temp|($bits-128 << $shift*7))+$delta-1;
											$shift = 0;
											$temp = 0;
										}
										else
										{
											$delta = $bits-129+$delta;
										}

										$field_id_bit = 1 << ($delta & $this->lsbits);

										if ( $field_id_bit & $this->enabled_fields )
										{
											# which field contain the current keyword ? 
											$document_field_hits |= $field_id_bit;

											# skip token pair lookup if: current group is 0, or the group is set to be skipped
											if ( $qind !== $skip_group_id ) 
											{
												# if previous group matched the previous field position
												if ( !empty($prev_position_storage[$delta]) ) 
												{
													# we have a matching pair ! 
													# check if there's a match already for this field 
													if ( !($group_field_hits & $field_id_bit) ) 
													{
														# store group field hits into a temporary variable
														$group_field_hits |= $field_id_bit;
														
														# if exact pair matching is enabled
														if ( $exact_group_pairing_lookup_copy ) 
														{
															# reset the bit denoting previous group 
															$exact_group_pairing_lookup_copy &= ~(1 << $prev_group);
														}

														# if all possible/enabled fields have already been matched, skip the rest of the group $qind
														if ( $group_field_hits === $this->enabled_fields ) 
														{
															$skip_group_id = $qind;
														}
														
														# do not rematch same positions (applies for queries with multiple identical keywords)
														$forbidden_position_storage[$delta] = 0;
													}
												}
											}
											# if delta value is below $this->first_of_field, the token's field_pos is 1 
											else if ( $delta < $this->first_of_field ) 
											{
												# this token is in the first possible position in it's field
												$strict_match = 1;
											}
											
											$tmp_position_storage[$delta + $this->doc_id_distance] = 1;
										}
									}
									
									++$x;
								}
							}

							if ( !$document_field_hits ) 
							{
								# self_score is zero => none of the keywords were found on enabled fields
								# this document is not a match
								continue;	
							}
							else if ( $exact_group_pairing_lookup_copy )
							{
								# exact mode is on but document does not 
								# satisfy strict keyword order conditions
								continue;
							}
							else if ( $strict_match_cmp_value > $strict_match )
							{
								# strict matchmode's requirements not satisfied
								continue;
							}

							++$total_matches;

							# skip rest of the score calculation
							# documents are ranked by an external attribute
							if ( $external_sort )
							{
								$temp_doc_id_sql .= ",$doc_id";	
								continue;
							}
							
							# set phrase score for the final group
							$phrase_data[$qind] = $group_field_hits;
							
							# how many matches for this keyword
							$document_count[$qind] += $x - $this->sentiment_index;
							$bm25_score 		= 0;
							$phrase_score 		= 0;
							$maxscore_total 	= 0;
							
							foreach ( $phrase_data as $vind => $value ) 
							{
								$phrase_score 	+= $weighted_score_lookup[$value];
								$maxscore_total += $best_match_score[$vind];

								if ( $sentimode && $this->sentiweight ) 
								{
									# if field_weights are applied also sentiment scores
									$sentiscore	+= $bm25_field_scores[$value];
								}
		
								$effective_match_count = $bm25_field_scores[$value] + $document_count[$vind];

								$bm25_score 	+= $effective_match_count * $IDF_lookup[$vind] / ($effective_match_count+1.2);
							}

							# calculate self_score
							$final_self_score = $weighted_score_lookup[$document_field_hits];
							
							# is quality scoring enabled ? 
							if ( $this->quality_scoring )
							{
								$score_multiplier = $maxscore_total/count($phrase_data);
							}
							else
							{
								$score_multiplier = 1;
							}
							
							switch ( $this->rankmode )
							{
								case PMB_RANK_PROXIMITY_BM25:
								$this->temp_matches[$doc_id] = (int)((($phrase_score + $final_self_score) * 1000 + round((0.5 + $bm25_score / $bm25_token_count) * 999)) * $score_multiplier);
								break;
								
								case PMB_RANK_BM25:
								$this->temp_matches[$doc_id] = (int)(round((0.5 + $bm25_score / $bm25_token_count) * 999) * $score_multiplier);
								break;
								
								case PMB_RANK_PROXIMITY:
								$this->temp_matches[$doc_id] = (int)((($phrase_score + $final_self_score) * 1000) * $score_multiplier);
								break;
							}
							
							
							# special case: store sentiment score if sorting/grouping by sentiment score
							if ( $sentimode )
							{
								$this->temp_sentiscores[$doc_id] = $sentiscore;
							}
							
							/*
							at this point, check how many temp_matches we have
							if count(temp_matches) > 10000, sort and keep only 1000 best matches
							*/
							
							if ( $total_matches % $this->temp_grouper_size === 0 )
							{	
								# sort results
								arsort($this->temp_matches);
								
								/* if grouping is enabled, it should be done at this point*/
								if ( $this->groupmode > 1 ) 
								{
									$this->GroupTemporaryResults();
								}
								else
								{
									# keep only $this->max_results 
									$this->temp_matches = array_slice($this->temp_matches, 0, $this->max_results, true);
								}
	
								if ( $sentimode ) 
								{
									$t_sentiscores = array();
									# rewrite sentiment score data
									foreach ( $this->temp_matches as $t_doc_id => $doc_score ) 
									{
										$t_sentiscores[$t_doc_id] = $this->temp_sentiscores[$t_doc_id];
										unset($this->temp_sentiscores[$t_doc_id]);
									}
									$this->temp_sentiscores = $t_sentiscores;
									unset($t_sentiscores);
								}
							}
							
						}
					}

					# if sorting by @id is enabled and we have enough results 
					if ( $tmp_matches >= $fast_ext_sort_req_count || $total_matches >= $fast_ext_sort_req_count )
					{
						# we have found $fast_ext_sort_req_count results
						$id_sort_goal = true;
						
						if ( $total_matches ) $tmp_matches = $total_matches;
						
						# very approximate number of results
						$approximate_docs = round(($tmp_matches / $total_documents) * $this->documents_in_collection);

						# set the flag on for approximate result count
						$this->result["approximate_count"] = 1;
						
						# the maximum amount of matches is the
						$match_sum = array_sum($sumcounts_reference);

						# any keyword === match
						if ( $this->matchmode === 1 ) 
						{
							$minimum_matches = max($sumcounts_reference);
							
							if ( $approximate_docs > $match_sum )
							{
								$approximate_docs = $match_sum * 0.9;
							}
							else if ( $approximate_docs < $minimum_matches )
							{
								# any keyword matches will do
								$approximate_docs = $minimum_matches;
							}
						}
						# all keywords === match
						else
						{
							$maximum_matches = min($sumcounts_reference);
							# all keywords must match
							if ( $approximate_docs < $tmp_matches ) 
							{
								$approximate_docs = $tmp_matches;
							}
							else if ( $approximate_docs > $maximum_matches )
							{
								$approximate_docs = $maximum_matches;
							}
						}

						if ( $approximate_docs >= 100 )
						{
							$tmp_matches = (int)round($approximate_docs, -2);
						}
						else
						{
							$tmp_matches = $approximate_docs;
						}

						break;
					}
								
					$min_doc_id += $interval;
					$max_doc_id += $interval;
					
					unset($t_matches, $loop_doc_groups, $loop_doc_positions);
					$t_matches = array();
					$loop_doc_positions = array();
					$loop_doc_groups = array();

					if ( !empty($t_matches_awaiting) )
					{
						foreach ( $t_matches_awaiting as $doc_id => $data ) 
						{
							if ( $doc_id >= $min_doc_id && $doc_id <= $max_doc_id )
							{
								foreach ( $t_matches_awaiting[$doc_id] as $group => $bits ) 
								{
									$undone_values[$group] = 1;
									$temp_doc_ids_storage[$group][$doc_id] = $bits;
										
									if ( !empty($t_matches[$doc_id]) )
									{
										$t_matches[$doc_id] |= $bits;
									}
									else
									{
										$t_matches[$doc_id] = $bits;
									}
								}
									
								unset($t_matches_awaiting[$doc_id]);
							}
							else if ( $doc_id > $max_doc_id )
							{
								unset($t_matches_awaiting[$doc_id]);
							}	
						}
					}
					else if ( $stop ) 
					{
						break;
					}
				} # <------- # (all groups have finished, lets check the results) if block ends

			} # <---------# while ( true ) ends
		
?>