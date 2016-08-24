<?php

/* Copyright (C) 2016 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@pickmybra.in
 * or visit: http://www.pickmybra.in
 */

/*
	DO NOT MODIFY THESE VALUES
	THESE ACT AS DEFAULT VALUES FOR NEW INDEXES
*/

# indexer
$seed_urls			= array ();
$indexing_interval 	= 0;
$update_interval 	= 1440;
$allow_subdomains	= 0;
$honor_nofollows	= 1;
$use_localhost		= 0;
$custom_address		= "";
$use_internal_db	= 1;
$main_sql_query		= "";
$main_sql_attrs		= array();
$use_buffered_queries = 0;
$delta_indexing		= 0;
$delta_merge_interval = 2880;
$ranged_query_value = 0;
$html_strip_tags	= 0;
$html_remove_elements	= "";
$html_index_attrs	= array();
$sentiment_analysis	= 0;
$trim_page_title	= array ();
$url_keywords		= "";
$prefix_mode 		= 1;
$prefix_length		= 3;
$dialect_processing	= 1;
$index_pdfs 		= 1;
$xpdf_folder		= "";
$charset 			= "0-9a-zöäå#$";
$blend_chars 		= array (
  0 => '.',
  1 => '-',
  2 => '&',
);
$ignore_chars 		= array (
  0 => '\'',
);
$separate_alnum		= 0;
						
# runtime
$field_weights      = array();
$sentiweight 		= 0;
$keyword_stemming 	= 1;
$dialect_matching	= 1;
$quality_scoring	= 1;
$forgive_keywords	= 1;
$expansion_limit 	= 32;
$log_queries	 	= 0;

# general settings
$data_columns		= array();
$number_of_fields	= 4;
$index_type			= 1;
$enable_exec		= 1;
$max_token_list_size = 100000;
$admin_email		= "";
$mysql_data_dir		= "";
$dist_threads		= 1;
$innodb_row_format  = 0;
$document_root		= "";
								
	?>