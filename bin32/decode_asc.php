<?php		
			/* 32_BIT_VERSION (DO NOT MODIFY OR REMOVE THIS COMMENT) */
			
            while ( true ) 
			{
				$finished = 0;
				$skipped = 0;
				$end = 0;

				foreach ( $sorted_groups as $group => $token_group ) 
				{
					if ( $encode_delta[$group] > $max_doc_id )  
					{
						++$skipped;
						continue; // skip this group
					}
					else if ( $encode_pointers[$group] > $lengths[$group] && !$undone_values[$group] )
					{
						++$skipped;
						++$end;
						continue;
					}

					$group_bits 	= 1 << $token_group; # this is a reference to the keyword order number
					$delta 			= $encode_delta[$group];
					$temp 			= -1;
					$shift 			= 0;
					$bin_data 		= &$encoded_data[$group];	# reference to document id data
					$docids_len 	= $lengths[$group];			# length of compressed document id data
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
					for ( ; $i < $docids_len ; ++$i )
					{
						if ( ($bits = $this->hex_lookup_decode[$bin_data[$i]]) < 128 ) 
						{
							# number is yet to end
							$temp += $this->pow_lookup[$shift] * $bits;
							++$shift;
						}
						else
						{
							# number ends
							$delta = (string)($this->pow_lookup[$shift] * ($bits&127) + $temp + $delta);

							if ( $delta <= $max_doc_id )
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
							
							$shift 	= 0;
							$temp 	= -1;
						}
					}
					
					$encode_delta[$group] = $delta;
					$encode_pointers[$group] = $i+1;

					if ( $vals && $matchpos_len ) 
					{
						$r = 0;
						$travel = (int)($avgs[$group]*$vals);
						$p = $doc_pos_pointers[$group]+$travel;
						if ( $p >= $matchpos_len ) 
						{
							$p = $matchpos_len-1;
							$travel = $matchpos_len-$doc_pos_pointers[$group];
						}

						$got = substr_count($matchpos_data, $bin_sep, $doc_pos_pointers[$group], $travel);
						
						if ( $got < $vals ) 
						{
							$vals -= $got;
							while ( true ) 
							{
								if ( $matchpos_data[$p] === $bin_sep )
								{
									++$p;
									++$r;
									if ( $r === $vals )
									{
										break;
									}
								}
								++$p;
								if ( $p >= $matchpos_len )
								{
									++$p;
									break;
								}
							}
							--$p;
						}
						else 
						{
							$vals = ( $got === $vals ) ? 1 : $got - $vals + 1;
							if ( $matchpos_data[$p] === $bin_sep ) ++$vals;
							
							while ( true ) 
							{
								if ( $matchpos_data[$p] === $bin_sep )
								{
									--$p;
									++$r;
									if ( $r === $vals )
									{
										break;
									}
								}
								--$p;
								if ( $p <= 0 )
								{
									$p=-1;
									break;
								}
							}
							++$p;
						}
						
						$encoded_group = $this->hex_lookup_encode[$group];
						$data = explode($bin_sep, substr($matchpos_data, $doc_pos_pointers[$group], $p-$doc_pos_pointers[$group]));
						$doc_pos_pointers[$group] = $p+1;
		
						$l = 0;
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
							
							++$l;
						}

						unset($temp_doc_ids, $data);
						$temp_doc_ids_storage[$group] = array();
					}
					
					# this group is done
					if ( $i >= $docids_len )
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

							$temp 	= -1;
							$shift 	= 0;
							$delta 	= 1;
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
									$temp 	= -1;
									$shift 	= 0;
									$delta 	= 1;
									$x 		= 0;
								}
								else if ( $bits < 128 ) 
								{
									# number is yet to end
									# check also gere if shift is === 0 ( then temp = bits; )
									$temp += $this->pow_lookup[$shift] * $bits;
									++$shift;
								}
								else
								{
									# 8th bit is set, number ends here ! 	
									if ( $x < $this->sentiment_index )
									{
										// reads as: sentiscore += ((bits&127 << shift*7) | temp)-128
										$sentiscore += $this->pow_lookup[$shift] * ($bits&127) + $temp - 128;
									}
									else
									{
										$delta = $this->pow_lookup[$shift] * ($bits&127) + $temp + $delta;

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
									
									// reset temp variables
									$temp 	= -1;
									$shift 	= 0;
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
							else if ( $doc_id < $min_doc_id )
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
				
			}  # <---------# while ( true ) ends

?>