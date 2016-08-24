Pickmybrain
-----------

Version:   0.95 BETA 
Published: 25.08.2016

copyright 2015-2016 Henri Ruutinen 

email: henri.ruutinen@pickmybra.in
website: http://www.pickmybra.in


Overview
--------

Pickmybrain is an open source search engine implemented with PHP
programming language and MySQL as data storage solution. 
Pickmybrain enables users to index databases, websites and
PDF files. 

Pickmybrain does not rely on the existing full-text search feature 
of MySQL but uses it's proprietary compressed inverted index 
architecture. This provides fast searching times even for large search 
indexes consisting of millions of documents. Pickmybrain ranks 
documents with phrase proximity AND bm25 algorithms ( although this 
is user-configurable ), which is also a clear improvement over 
the MySQL's default full-text search feature. 

License
-------

Pickmybrain is licensed under the GNU General Public License version 3 
(GPLv3). 

The Pickmybrain package includes the GPLv3 license: 
LICENSE.TXT

If you are redistributing unmodified copies of Pickmybrain,
you need to include README.txt ( this file ) and LICENSE.txt
( the GPLv3 license )

If you want to incorporate the Pickmybrain source code into another 
program (or create a modified version of Pickmybrain), and you are 
distributing that program, you have two options: release your program 
under the GPLv3, or purchase a commercial Pickmybrain source license.

If you're interested in commercial licensing, please see the Pickmybrain 
web site:

    http://www.pickmybra.in


Compatibility
-------------

In short:
- PHP >= 5.3 (x64 built)
- MySQL >= 5.1 ( or equivalent )

Pickmybrain is developed and tested on Linux. More specifically
on 64-bit version of Ubuntu 14.04.1 LTS. Pickmybrain requires 64bit
built of the PHP programming language and a MySQL database ( or a database 
that supports similar SQL syntax and table structures ). Pickmybrain 
should work on other operating systems as well.

In theory, Pickmybrain should work with PHP version of 5.3 and MySQL version
of 5.1, but this has not been tested. Pickmybrain is developed with PHP
version of 5.4.6 and MySQL version of 5.6.10.

The 64bit requirement is mostly due to CRC32 checksums which behave 
differently in PHP's 32bit and 64bit versions. 


Getting Pickmybrain
-------------------

The latest version is available from:
http://www.pickmybra.in


Installation
------------

To install Pickmybrain, please unzip the contents and place them into
a new folder. 


Upgrading from previous versions
--------------------------------

Unzip the new files and simply overwrite the old ones. Please delete 
deprecated configuration files ( settings_x.php ) as they have 
been replaced with more approachable .txt files ( settings_x.txt ).
 
NOTICE: If you updated to version 0.90 BETA from a previous version,
you need to truncate old indexes since the compressed data format
is now different.


How to configure
----------------

You have two options:

1. Open the file named control.php with a web browser, log in
with default user credentials ( defaultuser , defaultpass ) and then
follow the instructions. Do not forget to change the password in 
password.php, if you do not intend to implement any extra security.

OR

2. Run the file clisetup.php from the command line. First create a
new index and then manually edit the automatically created 
configuration file ( settings_INDEXID.txt ). See MANPAGES.txt for
detailed instructions.

For database indexes: 
After you have finished configuring the settings, please open the 
clisetup.php again and run 'Compile index settings' to finish
the process.


How to run the indexer
----------------------

You have three options:

1. In the web-based control panel ( control.php ), press 'Run indexer'.

OR

2. From the command line: php /path/to/indexer.php index_id=myindexid 

extra parameters (optional):
	usermode ( ignores indexing intervals )
	OR 
	testmode ( checks index configuration for errors )
	
	purge	 ( removes all existing indexed data from current index,
		   starts from scratch )

	replace  ( keeps the old indexed data intact until the new indexer
		   run is 100% completed, starts from scratch ) 

	merge    ( for delta index grow method only; merges the delta index
		   and the main index together to maintain indexing speed
		   advantages over time )

OR

3. Run indexer automatically by setting an appropiate indexing interval.
When PMBApi is used, an indexing process will be launched if enough
time has passed since the last indexing.

Tip:
Pickmybrain supports gradually growing indexes. This means you can add 
new documents into your search index simply by running the indexer again.
In cases like these Pickmybrain will merge the old already compressed
data with the new data OR create a parallel delta-index which is also
queried while searching. If you wish to merge new data into existing data, 
do not invoke the indexer with purge or replace parameter.

Unfortunately Pickmybrain does not support modifying of already indexed data.
At some point I will probably implement a kill-list feature which maintains 
a list of non-wanted document ids and reduces them from search results if 
necessary.


How to search
-------------

Basically you have two options for embedding Pickmybrain
into your own application:

1. For PHP applications, use the Pickmybrain API.

For detailed instructions, please view the Pickmybrain API documentation 
in http://www.pickmybra.in/api.php

2. You can also search from the command line and thus use Pickmybrain
with other programming languages as well. Please run the file
clisearch.php and follow the instructions.

The Pickmybrain API has been already implemented into the web-based control
panel and it allows you to search pre-configured indexes. Open the 
index you want to search and press the 'Search' button or alternatively 
Ctrl+Q from your keyboard.


Bugs
----

If you find a bug in Pickmybrain or you find something that defies logic
and good taste, please send me mail with as detailed explanation as 
possible.


File purpose definitions
------------------------

autoload_settings.php		Loads and parses .txt configuration files
autostop.php			Checks whether any new data was actually indexed and stops execution if necessary 
clisetup.php			command-line control panel
clisearch.php			command-line search utility
control.php             	the web control panel
data_partitioner.php		tells processes which part of the data to read when external linux sort is in use
db_connection.php		this file defines how PHP is supposed to connect to your database
db_tokenizer.php		database indexer
db_tokenizer_ext.php		same as above, but uses external linux sort		
ext_db_connection.php		(optional) this file defines an external read-only database for database indexes
finalization.php		
indexer.php			to launch an indexing process, call this file with correct params
input_value_processor.php	processes parameters for indexer, db_tokenizer and web_tokenizer
livesearch.php			PHP backend file for the live search feature
loginpage.php			Provides additional security for the web-based control panel
password.php			Defines username/password for the web-based control panel
PMBApi.php			PHP Application Programming Interface
prefix_composer.php		fetches all tokens after indexing and creates prefixes if necessary
prefix_compressor.php		fetches temp prefix data, compresses it and inserts again into db
process_listener.php		if multiprocessing is enabled, this file waits for all processes to finish
settings.php			contains default values for every new index
token_compressor.php		fetches temp token position data, compresses it and inserts again into db
token_compressor_ext.php	same as above, but uses external linux sort
token_compressor_merger.php	same as previous + supports index merging
token_compressor_merger_ext.php	same as above, but uses external linux sort
tokenizer_functions.php		Helper functions for control panel / indexer
web_tokenizer.php		web crawler / indexer


Search index data format
------------------------

*****************************
*** tokens and match data ***
*****************************

CREATE TABLE IF NOT EXISTS PMBTokens_X (
 checksum int(10) unsigned NOT NULL,
 token varbinary(40) NOT NULL,
 doc_matches int(8) unsigned NOT NULL,
 doc_ids mediumblob NOT NULL,
 PRIMARY KEY (checksum, token)
) ENGINE=InnoDB 

(The _X postfix indicates the index identification number)

PMBTokens contains all different tokens, statistics on how many documents 
they match and the actual token match data
checksum 	= CRC32(token)
token		= the token
doc_matches	= in how many documents is this token present
doc_ids		= document match/position data in binary


The binary data in the doc_ids column is a compressed array of integers.
The actual data format is like this: 

doc_id_1, doc_id_2, doc_id_3 ... doc_id_n DELIMITER(1)

^ (After document ids end, the first DELIMITER occurs)

(doc_id_1_sentiscore) field_data1 field_data2 field_data3 DELIMITER(2) (doc_id_1_sentiscore) field_data1 field_data2 DELIMITER(3) ...

^ token match data for doc_id_1 is present between the first DELIMITER and the second DELIMITER
^ token match data is stored this way for every doc_id_2 ... doc_id_n and separated by DELIMITERS

TERM EXPLANATIONS ( all values are unsigned integers )
doc_id_x        	matching document id 
DELIMITER		delimiter, 0 (zero) 
(doc_id_x_sentiscore) 	optional sentiment score for this token and document 
field_datax		( (field position << field_bits) | field_id ) 
field_position		position of the token from the beginning of current field ( 1, 2, 3 ... )
field_bits		how many bits are required for storing an arbitrary field_id ( constant value ) 
field_id		which indexed field this token matches to ( 0, 1, 2 ... )  

For decoding the data:
1. run variable byte decode on the whole binary string
2. run deltadecode until the first DELIMITER occurs ( all document ids are deltaencoded )
3. then at document_id specific data between DELIMITERS, skip the first number (doc_id_x_count) but deltadecode the rest


****************
*** Prefixes ***
****************

CREATE TABLE IF NOT EXISTS PMBPrefixes_X (
 checksum int(10) unsigned NOT NULL,
 tok_data mediumblob NOT NULL,
 PRIMARY KEY (checksum)
 ) ENGINE=InnoDB;

(The _X postfix indicates the index identification number)

checksum	= CRC32(prefix)
tok_data	= binary data that contains crc32 checksums of matching tokens

The actual data format is like this:

token_data_1, token_data_2, token_data_3 ... token_data_n

TERM EXPLANATIONS ( all values are unsigned integers )
token_data_x		 (token_crc32_checksum << 6) | number_of_cut_characters
token_crc32_checksum	 crc32 checksum of a token that matches this prefix
number_of_cut_characters number of characters that had to be cut from the original token
			 to get this prefix

For decoding the data:
1. run variable byte decode on the whole binary string
2. run deltadecode for the whole integer array


Third-Party Libraries
---------------------

Pickmybrain uses the following libraries:
* Xpdf ( http://www.foolabs.com/xpdf/ )
* Kreative by Styleshout ( http://www.styleshout.com/ )
* PHP Domain Parser ( https://github.com/jeremykendall/php-domain-parser ) 
* PHP Porter Stemmer for English ( http://tartarus.org/martin/PorterStemmer/php.txt )


