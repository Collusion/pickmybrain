// JavaScript Document
function pmbsearch(str, e, offset) {
	
	if (offset === undefined) {
    	offset = 0;
    } 
	
	var checksum = document.getElementById("pmbresultarea").dataset.checksum;
	checksum = checksum.concat("_", offset);
	
	if ( checksum == str )
	{
		return;
	}
	
	e = e || window.event;

    if (e.keyCode != '38' && e.keyCode != '40' && e.keyCode != '37' && e.keyCode != '39') {
		document.getElementById("pmbresultarea").style.opacity = "0.3";
    }
	else
	{
		return;
	}

	setTimeout(function(){ 
	// check if input value has changed during waiting perioid
	if ( document.getElementById("pmblivesearchinput").value != str ) 
	{
		return;	
	}
	
	if (str.length==0) {
		document.getElementById("pmbresultarea").innerHTML="";
		return;
	}
	
	var index_name = document.getElementById("index_name").value;

	var h = window.innerHeight
			|| document.documentElement.clientHeight
			|| document.body.clientHeight;
	  
	if (window.XMLHttpRequest) {
	// code for IE7+, Firefox, Chrome, Opera, Safari
		xmlhttp=new XMLHttpRequest();
	} else {  // code for IE6, IE5
		xmlhttp=new ActiveXObject("Microsoft.XMLHTTP");
	}
	
	xmlhttp.onreadystatechange=function() {
		if (xmlhttp.readyState==4 && xmlhttp.status==200) {
			document.getElementById("pmbresultarea").innerHTML=xmlhttp.responseText;
			document.getElementById("pmbresultarea").style.opacity = "1";	
			document.getElementById("pmbresultarea").dataset.checksum = str.concat("_", offset);		
		}
	}
	
	xmlhttp.open("GET","livesearch.php?q="+encodeURIComponent(str)+"&h="+h+"&o="+offset+"&index_name="+index_name,true);
	xmlhttp.send();

	}, 250);

}

function TogglePMBSearch() {
		
	var myvar = document.getElementById("pmblivesearch").className;
		
	if ( myvar == 'hidden' || myvar == 'realhidden' )
	{
		document.getElementById("pmblivesearch").className = 'hidden';
		setTimeout(function(){
			document.getElementById("pmblivesearch").className = 'visible';
		}, 0);
		document.getElementById("pmblivesearchinput").select();
	}
	else
	{
		document.getElementById("pmblivesearch").className = 'hidden';
		setTimeout(function(){
			// after transition hide element properly with display attribute
			document.getElementById("pmblivesearch").className = 'realhidden';
		}, 250);
	}
}
