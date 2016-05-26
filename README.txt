Pickmybrain
-----------

Version:   0.81 BETA 
Published: 19.05.2016

copyright 2015-2016 Henri Ruutinen 

email: henri.ruutinen@pickmybra.in
website: http://www.pickmybra.in


Overview
--------

Pickmybrain is an open source search engine implemented with PHP
programming language and MySQL as data storage solution. 
Pickmybrain enables users to index databases and websites. 


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

Pickmybrain is developed and tested on Linux. More specifically
on 64-bit version of Ubuntu 14.04.1 LTS. Pickmybrain requires 64bit
built of the PHP programming language, a MySQL database ( or a database 
that supports similar SQL syntax and table structures ) and a web server
( because how the user interface is implemented ) to work. Pickmybrain 
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
a new folder. Open the file named control.php with a web browser, log in
with default user credentials ( defaultuser , defaultpass ) and then
follow the instructions. 

Do not forget to change the password in password.php, if you do not 
intend to implement any extra security.


How to use
----------

For detailed instructions, please view the Pickmybrain API documentation 
in http://www.pickmybra.in/api.php

The indexer can also be invoked from command line in the following manner:

php /path/to/pickmybrain/indexer.php index_id=myindexnumber 

extra parameters:
	usermode ( ignores indexing intervals )
	OR 
	testmode ( checks index configuration for errors )


Upgrading from previous versions
--------------------------------

Unzip the new files and simply overwrite the old ones. Deleting the
index-specific configuration files ( settings_x.php ) is not necessary.


Bugs
----

If you find a bug in Pickmybrain or you find something that defies logic
and good taste, please send me mail with as detailed explanation as 
possible.


File purpose definitions
------------------------

control.php             	the web control panel
db_connection.php		this file defines how PHP is supposed to connect to your database
db_tokenizer.php		database indexer		
ext_db_connection.php		(optional) this file defines an external read-only database for database indexes
indexer.php			to launch an indexing process, call this file with correct params
input_value_processor.php	processes parameters for indexer, db_tokenizer and web_tokenizer
livesearch.php			PHP backend file for the live search feature
loginpage.php			Provides additional security for the web-based control panel
password.php			Defines username/password for the web-based control panel
prefix_composer.php		fetches all tokens after indexing and creates prefixes if necessary
prefix_compressor.php		fetches temp prefix data, compresses it and inserts again into db
process_listener.php		if multiprocessing is enabled, this file waits for all processes to finish
settings.php			contains default values for every new index
token_compressor.php		fetches temp token position data, compresses it and inserts again into db
token_compressor_merger.php	same as previous + supports index merging
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
 ID mediumint(10) unsigned NOT NULL,
 doc_ids mediumblob NOT NULL,
 PRIMARY KEY (checksum, token, doc_matches, ID)
) ENGINE=InnoDB 

(The _X postfix indicates the index identification number)

PMBTokens contains all different tokens, statistics on how many documents 
they match and the actual token match data
checksum 	= CRC32(token)
token		= the token
doc_matches	= in how many documents is this token present
ID		= unique ID for this token
doc_ids		= document match/position data in binary


The binary data in the doc_ids column is a compressed array of integers.
The actual data format is like this: 

doc_id_1, doc_id_2, doc_id_3 ... doc_id_n DELIMITER(1)

^ (After document ids end, the first DELIMITER occurs)

doc_id_1_count pair_data_1 pair_data_2 DELIMITER(2) doc_id_2_count pair_data_1 pair_data_2 DELIMITER(3) ...

^ token match data for doc_id_1 is present between the first DELIMITER and the second DELIMITER
^ token match data is stored this way for every doc_id_2 ... doc_id_n and separated by DELIMITERS

TERM EXPLANATIONS ( all values are unsigned integers )
doc_id_x        matching document id 
DELIMITER	delimiter, 0 (zero) 
doc_id_x_count	how many matches of current token in this document (SENTIMENT INDEXES: (count<<8) | sentiscore ) 
pair_data_x	( next_token_id << field_bits ) 
next_token_id	unique ID of the immediately following token, zero if current token is last of the field
field_bits	in which fields this token pair is present, number of field bits = number of indexable columns

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
 ) ENGINE=InnoDB DEFAULT;

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


