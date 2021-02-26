<?php

/* Copyright (C) 2017 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@gmail.com
 * or visit: http://www.pickmybra.in
 */

mb_internal_encoding("UTF-8");

class PMBSentiment 
{
	private $dictionaryPath;
	private $ignoreDictionary;
	private $prefixDictionary;
	private $negPrefixReplacement;
	private $dictionary;
	private $phraseDictionary;
	private $smileyDictionary;
	private $smileyFind;
	private $smileySubstitute;
	private $classes;
	private $hitlist;
	private $tokenCounts;
	private $enablehitlist;
	private $scoreReference;
	private $tokens;
	private $hitCounts;
	private $find;
	private $replace;
	private $scoreContextAverage;
	
	public function getHitList()
	{
		return $this->hitlist;
	}
	
	public function getTokens()
	{
		return $this->tokens;
	}
	
	public function getHitCounts()
	{
		return $this->hitCounts;
	}
	
	public function disableHitlist()
	{
		# flip dictionaries
		foreach ( $this->classes as $class_id => $class_name )
		{
			if ( !empty($this->smileyDictionary[$class_id]) )
			{
				$this->smileyDictionary[$class_id] = array_keys($this->smileyDictionary[$class_id]);
			}
			
			if ( !empty($this->phraseDictionary[$class]) )
			{
				$this->phraseDictionary[$class] = array_keys($this->phraseDictionary[$class]);
			}
		}

		$this->enablehitlist = false;
	}
	
	public function enableHitlist()
	{
		# flip dictionaries
		foreach ( $this->classes as $class )
		{
			if ( !empty($this->smileyDictionary[$class]) )
			{
				$this->smileyDictionary[$class] = array_flip($this->smileyDictionary[$class]);
			}
			
			if ( !empty($this->phraseDictionary[$class]) )
			{
				$this->phraseDictionary[$class] = array_flip($this->phraseDictionary[$class]);
			}
		}
		
		$this->enablehitlist = true;
	}

	public function __construct($language = 'en') 
	{	
		if ( $language === 'fi' )
		{
			# finnish
			$this->dictionaryPath = realpath(dirname(__FILE__)) . '/finnish/';
		}
		else
		{
			# default: english
			$this->dictionaryPath = realpath(dirname(__FILE__)) . '/english/';
		}
		
		# format member variables
		$this->ignoreDictionary 	= array();
		$this->prefixDictionary 	= array();
		$this->negPrefixReplacement = array();
		$this->hitlist 				= array();
		$this->tokenCounts 			= array();
		$this->tokens 				= array();
		$this->hitCounts 			= array();
		$this->enablehitlist		= true;
		$this->phraseDictionary 	= array();
		$this->smileyDictionary		= array();
		$this->smileyFind			= array();
		$this->smileySubstitute		= array();
		$this->dictionary			= array();
		$this->classes 		  		= array(
									-1 => 'neg',  
									 0 => 'neu', 
									 1 => 'pos'
									 );
											 
		$this->tokenCounts 			= array(
									'pos' => 0, 
									'neg' => 0, 
									'neu' => 0
									);
									
		$this->scoreReference		 = array(
									'pos' => 0.333333333333,
									'neg' => 0.333333333333,
									'neu' => 0.333333333334
									);

		# load all three main dictionaries here
		foreach ($this->classes as $class) 
		{
			if ( !$this->loadDictionary($class) ) 
			{
				echo "Error: Dictionary for class $class could not be loaded";
			}
		}

		if ( !isset($this->dictionary) || empty($this->dictionary) )
		{
			echo 'Error: Dictionaries not set';
		}

		# load words to be ignored
		if ( !$this->loadList("ignore") )
		{
			echo 'Error: ignoreDictionary is empty';
		}

		# load prefixes
		if ( !$this->loadList("prefix") )
		{
			echo 'Error: prefixDictionary is empty';
		}
		
		# load list of shorthand expressions to be replaced
		if ( !$this->loadList("replace") )
		{
			echo 'Error: replaceDictionary is empty';
		}
	}
	
	# returns average sentiment score for current document
	public function scoreContextAverage()
	{
		return $this->scoreContextAverage;
	}
	
	# analyzes documents by splitting them into individual sentences
	# and giving each token an individual sentiment score 
	public function scoreContext($text)
	{
		# hitlist must be enabled for this feature to work
		if ( !$this->enablehitlist ) 
		{
			$this->enableHitlist();
		}
		
		$finnish_abbreviations = array(
		" alk.",
		" alv.", 
		" ao.",  
		" esim.", 
		" eaa.",
		" em.", 
		" ed.",
		" eo.",
		" ekr.",
		" huom.", 
		" i.e", 
		" i.v.", 
		" jaa.", 
		" jkr.", 
		" jms.", 
		" jne.", 
		" k.",
		" ko.",
		" ks.",
		" kts.", 
		" l.", 
		" ma.", 
		" ml.", 
		" mm.", 
		" mrd.",
		" nk.",
		" n.n.",
		" no.",
		" nro.",
		" n.", 
		" ns.", 
		" os.",
		" o.s.",
		" oto.",
		" po.",
		" puh.", 
		" pvm.", 
		" s.", 
		" so.", 
		" tel.", 
		" t.", 
		" tjsp.", 
		" ts.",
		" tm.",
		" tms.",
		" tmv.",
		" v.",
		" va.",
		" vrt.",
		" vs.", 
		" vt.", 
		" yms.", 
		" ym.", 
		" yo."
		);
		
		$english_abbreviations = array(
		" a.d",
		" al.",
		" a.d", 
		" a.m", 
		" c.", 
		" ca.",
		" c.v", 
		" etc.", 
		" e.g.", 
		" e.g", 
		" et al.", 
		" i.e.", 
		" p.a",
		" p.m",  
		" p.s", 
		" r.i.p",  
		" b.a",
		" b.sc",
		" m.a",
		" m.b.a",
		" dr.",
		" dr.mud",
		" dr.med",
		" gen.", 
		" hon.", 
		" tel.",
		" mr.", 
		" mrs.",
		" ms.", 
		" prof.", 
		" ph.d", 
		" rev.", 
		" jr.",
		" sr.", 
		" rms.", 
		" st.", 
		" assn.", 
		" ave.", 
		" dept.", 
		" est.", 
		" fig.", 
		" hrs.", 
		" inc.", 
		" mt.", 
		" no.", 
		" oz.",
		" sq.",
		" st.",
		" vs.",
		" abbr.",
		" adj.",
		" adv.",
		" obj.",
		" pl.",
		" poss.",
		" prep.",);
		
		$text = mb_strtolower(str_replace(array("?", "!", "\n", "\t", ";"), ".", $text));
		$text = str_replace($this->smileyFind, $this->smileySubstitute, $text);
		
		$text = str_replace($finnish_abbreviations, " ", $text);
		$text = str_replace($english_abbreviations, " ", $text);
	 
		$sentence_min_len 			= 7;			# minumim sentence length; otherwise the sentence will be combined with previous one
		$prev_sentence 				= "";			# temporary variable for concatenating sentences
		$word_sentiment_scores 		= array();		# sentiment scores for individual words
		$this->scoreContextAverage 	= 0;			# average sentiment score for this document
			
		# split incoming document to sentences	
		foreach ( explode(".", $text) as $sentence ) 
		{
			$sentence = trim($sentence);

			# empty sentence
			if ( empty($sentence) )
			{
				continue;
			}
			# too short: combine with next sentence
			else if ( !isset($sentence[$sentence_min_len]) )
			{
				$prev_sentence .= " $sentence";
				continue;
			}
			
			# combine current sentence and previous sentence	
			if ( !empty($prev_sentence) )
			{
				$sentence = trim($prev_sentence . " " . $sentence); 
			}
				
			$prev_sentence = "";
				
			# do a sentiment analysis for each sentence
			$this->score($sentence);
			$word_data = $this->getHitList();
				
			if ( !empty($word_data) ) 
			{
				$hitcounts 			= $this->getHitCounts();
				
				$hitcounts["pos"] 	-= 1;
				$hitcounts["neu"] 	-= 1;
				$hitcounts["neg"] 	-= 1;
					
				# half of neutral points
				if ( $hitcounts["neu"] > 0 )
				{
					$amount_to_reduce = $hitcounts["neu"]/2;
				}
				else
				{
					$amount_to_reduce = 0;
				}
					
				# remove neutral score from both pos and neg scores
				$hitcounts["pos"] -= $amount_to_reduce;
				$hitcounts["neg"] -= $amount_to_reduce;
					
				if ( $hitcounts["pos"] < 0 ) $hitcounts["pos"] = 0;
				if ( $hitcounts["neg"] < 0 ) $hitcounts["neg"] = 0;
	
				$avg						= (int)($hitcounts["pos"]-$hitcounts["neg"]);
				$this->scoreContextAverage += $avg;
								
				foreach ( $this->getTokens() as $token => $tok_count ) 
				{
					if ( empty($token) ) continue;
					
					if ( !isset($word_sentiment_scores[$token]) )
					{
						$word_sentiment_scores[$token] = $avg;
					}
					else
					{
						$word_sentiment_scores[$token] += $avg;
					}
				}
			}
		}

		return $word_sentiment_scores;
	}

	# analyzes document
	# parameter @sentence text to analyze
	public function score($sentence)
	{		
		# combined score for all classes
		$total_score = 0;

		# reset scores for each class
		$scores = array();
		
		# reset hitlist
		$hitlist = NULL;
		
		# pre-trim for smiley detection
		$sentence = " " . mb_strtolower(str_replace(array("\r\n", "?", "!", ".", ","), " ", $sentence)) . " ";
		
		$token_count = array();
								
		# smileys		
		if ( $this->enablehitlist )
		{
			foreach ( $this->classes as $class_id => $class_name ) 
			{
				$scores[$class_id] = 1;		
				$hitcount[$class_id] = 1;
				
				if ( !empty($this->smileyDictionary[$class_id]) )
				{
					foreach ( $this->smileyDictionary[$class_id] as $smiley => $int ) 
					{	
						# try replacing the phrase with space and see if we've got any hits
						$hits = 0;
						$sentence = str_replace($smiley, " ", $sentence, $hits);
							
						if ( $hits )
						{
							$scores[$class_id] += $hits;
							$hitlist[$class_id][trim($smiley)] = $hits;
						}
					}
				}
			} 
		}
		else
		{
			# hitlist not enabled, use faster matching
			foreach ( $this->classes as $class_id => $class_name ) 
			{
				$scores[$class_id] = 1;		
				$hitcount[$class_id] = 1;
				
				if ( !empty($this->smileyDictionary[$class_id]) )
				{
					$hits = 0;
					$sentence = str_replace($this->smileyDictionary[$class_id], " ", $sentence, $hits);
							
					if ( $hits )
					{
						$scores[$class_id] += $hits;
					}
				}
			}
			
		}
		
		# replace shorthand expressions ( if defined ) 
		if ( !empty($this->find) )
		{
			$sentence = str_replace($this->find, $this->replace, $sentence);
		}
		
		# post-trim for word-detection
		$sentence = str_replace($this->prefixDictionary, 
								$this->negPrefixReplacement, 
								str_replace($this->ignoreDictionary, " ", str_replace(array(":", "\"", "/", "(", ")", "_", "[", "]", ";", " -", "- ", "&", "^", "@", "<", ">", "\\", "´", "”"), " ", $sentence)));
				
		# phrase detection
		if ( $this->enablehitlist )
		{
			$phrase_string = "";
			
			foreach ($this->classes as $class_id => $class_name) 
			{
				# check for phrases
				if ( !empty($this->phraseDictionary[$class_id]) )
				{
					foreach ( $this->phraseDictionary[$class_id] as $phrase => $int ) 
					{
						# try replacing the phrase with space and see if we've got any hits
						$hits = 0;
						$sentence = str_replace($phrase, " ", $sentence, $hits);
						
						if ( $hits )
						{						
							$scores[$class_id] += $hits;
							$hitlist[$class_id][trim($phrase)] = $hits;
							
							$phrase_string .= $phrase;
						}
					}
				}
			}

			if ( !empty($phrase_string) ) 
			{
				$token_count = array_flip(explode(" ", str_replace("  ", " ", trim($phrase_string))));
			}
		}
		else
		{
			# hitlist is disabled, user faster method
			foreach ($this->classes as $class_id => $class_name) 
			{
				# check for phrases
				if ( !empty($this->phraseDictionary[$class_id]) )
				{
					$hits = 0;
					$sentence = str_replace($this->phraseDictionary[$class_id], " ", $sentence, $hits);
						
					if ( $hits )
					{
						$scores[$class_id] += $hits;
					}
				}
			}
		}

		# count individual words
		$token_count = $token_count + array_count_values(explode(" ", $sentence));
				
		if ( !empty($token_count) )
		{
			foreach ( $token_count as $token => $count ) 
			{
				if ( !empty($token) )
				{
					if ( isset($this->dictionary[$token]) )
					{
						$class_id = $this->dictionary[$token];
						$scores[$class_id] += $count;
						$hitlist[$class_id][$token] = $count;
					}
				}
			}
			
			$hitcounts = array();
			foreach ( $scores as $class_id => $score ) 
			{
				$hitcounts[$this->classes[$class_id]] = $scores[$class_id];
			}
			$this->hitCounts = $hitcounts;

			//Score for this class is the scoreReference probability multiplyied by the score for this class
			$scores[1]  = $this->scoreReference["pos"] * $scores[1];
			$scores[0]  = $this->scoreReference["neu"] * $scores[0];
			$scores[-1] = $this->scoreReference["neg"] * $scores[-1];
		}
		else
		{
			$hitcounts = array();
			foreach ( $scores as $class_id => $score ) 
			{
				$hitcounts[$this->classes[$class_id]] = $scores[$class_id];
			}
			$this->hitCounts = $hitcounts;
		}
		# total score of all classes
		$total_score = $scores[1] + $scores[0] + $scores[-1];

		foreach ($this->classes as $class_id => $class_name) 
		{
			$scores[$class_id] = $scores[$class_id] / $total_score;
		}
		
		foreach ( $scores as $class_id => $score ) 
		{
			$scores[$this->classes[$class_id]] = $scores[$class_id];
			unset($scores[$class_id]);
			
			if ( isset($hitlist[$class_id]) )
			{
				$hitlist[$this->classes[$class_id]] = $hitlist[$class_id];
				unset($hitlist[$class_id]);
			}
		}
		
		$this->hitlist = $hitlist;	
		$this->tokens = $token_count;

		arsort($scores);

		return $scores;
	}

	public function loadDictionary($class) 
	{	
		switch ( $class )
		{
			case 'neu':
			$filename = "neutral";
			$class_id = 0;
			break;
			
			case 'neg':
			$filename = "negative";
			$class_id = -1;
			break;
			
			case 'pos':
			$filename = "positive";
			$class_id = 1;
			break;
			
			default:
			return false;
		}

		$filepath = $this->dictionaryPath.$filename.".php";
		$data = array();

		if ( file_exists($filepath) ) 
		{
			include($filepath);
		} 
		else 
		{
			echo "error: dictionary does not exist: " . $filepath;
			return false;
		}
		
		$tokenCounts[$class] 	= 0;
		$phraseDictionary 		= $this->phraseDictionary;
		$smileyDictionary 		= $this->smileyDictionary;
		$dictionary 			= $this->dictionary;
		$tokenCounts			= $this->tokenCounts;
		$smileyFind				= $this->smileyFind;
		$smileySubstitute		= $this->smileySubstitute;
		$find 	 				= array(":", "\"", "/", "(", ")", "_", "[", "]", ";", " -", "- ", "&", "^", "@", "<", ">", "\\", "´", "=", "|", "}", "{");
		
		//Loop through all of the entries
		foreach ($data as $word => $points) 
		{
			if ( !isset($word[1]) ) continue;	# word too short
	
			$hits = 0;
			str_replace($find, "", $word, $hits);
			if ( $hits === 0 )
			{
				# check if the term consists on two or more words
				if ( substr_count($word, " ") > 0 )
				{
					$phraseDictionary[$class_id][" $word "] = 1;
				}
				else if ( !isset($dictionary[$word]) ) 
				{
					$dictionary[$word] = $class_id;
				}
			}
			else
			{
				# non alpha ( smileys + other expressions ) 
				$smileyDictionary[$class_id][" $word "] = 1;
				$smileyFind[] 		= $word;
				$smileySubstitute[] = "$word .";
			}

			# token count per class / total
			++$tokenCounts[$class];
		}

		# merge into existing data
		$this->phraseDictionary = $phraseDictionary;
		$this->smileyDictionary = $smileyDictionary;
		$this->smileyFind		= $smileyFind;
		$this->smileySubstitute = $smileySubstitute;
		$this->dictionary 		= $dictionary;
		$this->tokenCounts 		= $tokenCounts;
		return true;
	}

	# load and return a
	public function loadList($filename) 
	{
		$tokens = array();

		$filepath = $this->dictionaryPath.$filename."php";
		
		if ( file_exists($filepath) ) 
		{
			include($filepath);
		} 
		else 
		{
			return "File does not exist: " . $filepath;
			return false;
		}

		# Loop through results
		if ( $filename === 'ignore' )
		{
			foreach ( $data as $token ) 
			{
				# trim the word and add preceding and trailing spaces
				$wordList[] = " " . trim($token) . " ";
			}
			
			$this->ignoreDictionary = $wordList;	
			
		}
		else if ( $filename === 'prefix' )
		{
			foreach ( $data as $token ) 
			{
				# remove slashes and trim the word
				# and preceding and trailing spaces
				$word 		= trim($token);
				$wordList[] = " " . $word . " ";
				$tempList[] = " " . str_replace(" ", "", $token);
			}

			$this->negPrefixReplacement = $tempList;	# for mass replacing of prefixes
			$this->prefixDictionary 	= $wordList;
		}
		else if ( $filename === "replace" )
		{	
			if ( !empty($data) )
			{
				$this->find 	= array_keys($data);
				$this->replace  = array_values($data);
			}
		}
		else
		{
			echo "error: unknown list: $filename";
			return false;
		}

		return true;
	}
}

?>