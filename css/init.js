// include this file after your </body> tag
(function() {
  
	if ( !document.getElementById('pmblivesearch') ) { 
	
		var index_name = "";
		if ( document.getElementById('pmb-index-name') )
		{
			var elem = document.getElementById('pmb-index-name');
			if ( elem.hasAttribute("data-index-name") )
			{
				var index_name = elem.dataset.indexName;
			}
		}
	
		// create the search box and set id
		var elem = document.createElement('div');
		elem.setAttribute('id', 'pmblivesearch');
		elem.setAttribute('class', 'hidden');
		// then create content
		elem.innerHTML = "<form id='pmblivesearchform'><input type='hidden' name='index_name' id='index_name' value='"+index_name+"'><input type='text' id='pmblivesearchinput' onkeyup='pmbsearch(this.value, event)' /></form><hr class='pmbline'/><span id='pmbresultarea' data-checksum=''></span>";
		document.body.appendChild(elem);
	}
	
	// searchbox opens when user presses control-key and q simultaneously
	document.onkeydown = function(evt) {
		evt = evt || window.event;
		if (evt.ctrlKey && evt.keyCode == 81) {
			TogglePMBSearch();

			/*
			alternative keycodes
			70 = f
			71 = g
			66 = b
			69 = e
			*/
		}
	};

})();