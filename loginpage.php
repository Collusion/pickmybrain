<?php

/* Copyright (C) 2017 Henri Ruutinen - All Rights Reserved
 * You may use, distribute and modify this code under the
 * terms of the GNU GPLv3 license
 *
 * You should have received a copy of the GNU GPLv3 license 
 * with this file. If not, please write to: henri.ruutinen@gmail.com
 * or visit: http://www.pickmybra.in
 */

session_start();
require_once("password.php");

if ( isset($_GET["logout"]) )
{
	# user wishes to log out
	$_SESSION = array();;
	session_destroy();
	header("Location: loginpage.php");
	return;
}

if ( isset($_SESSION["pmb_logged_in"]) && (PMB_SESSIONLEN == 0 || time() < $_SESSION["pmb_logged_in"] + PMB_SESSIONLEN) )
{
	# session is still valid --> redirect to control panel
	header("Location: control.php");
	return;
}
else
{
	if ( !empty($_POST) )
	{
		$username = "";
		$password = "";
		
		# wait for 400ms
		usleep(400000);
		
		# require login
		if ( !empty($_POST["username"])  )
		{
			$username = $_POST["username"];
		}
		
		if ( !empty($_POST["password"])  )
		{
			$password = $_POST["password"];
		}
		
		if ( $username == PMB_USERNAME && $password == PMB_PASSWORD )
		{
			$_SESSION["pmb_logged_in"] = time();
			
			# redirect user
			header("Location: control.php");
			return;
		}
		else
		{
			$incorrect_credentials = true;
		}
	}
	else if ( isset($_SESSION["pmb_logged_in"]) && PMB_SESSIONLEN != 0 && time() > $_SESSION["pmb_logged_in"] + PMB_SESSIONLEN ) 
	{
		$session_expired = true;
	}
}

?>


<!DOCTYPE html>
<!--[if lt IE 8 ]><html class="ie ie7" lang="en"> <![endif]-->
<!--[if IE 8 ]><html class="ie ie8" lang="en"> <![endif]-->
<!--[if (gte IE 8)|!(IE)]><!--><html lang="en"> <!--<![endif]-->
<head>


   <!--- Basic Page Needs
   ================================================== -->
	<meta charset="utf-8">
	<title>Pickmybrain Control Panel - Login</title>
	<meta name="description" content="">
	<meta name="author" content="">

   <!-- Mobile Specific Metas
  ================================================== -->
	<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">

	<!-- CSS
   ================================================== -->
   <link rel="stylesheet" href="css/base.css">
   <link rel="stylesheet" href="css/layout.css">
   <link rel="stylesheet" href="css/pmb.css">

	<!--[if lt IE 9]>
		<script src="http://html5shim.googlecode.com/svn/trunk/html5.js"></script>
	<![endif]-->

   <!-- Favicons
	================================================== -->
	<link rel="shortcut icon" href="images/favicon.ico">


</head>

<body>

   <!-- Header
   ================================================== -->
   <header id="top" class="static" >

      <div class="row">

         <div class="col full">

            <div class="logo">
               <a href="index.php"><img alt="" src="images/logo.png"></a>
            </div>

            <nav id="nav-wrap">

               <a class="mobile-btn" href="#nav-wrap" title="Show navigation">Show navigation</a>
	            <a class="mobile-btn" href="#" title="Hide navigation">Hide navigation</a>

               <ul id="nav" class="nav">
	               <li><a href="control.php">Control Panel</a></li>   
               </ul>

            </nav>

         </div>

      </div>

   </header> <!-- Header End -->


   <!-- Container
   ================================================== -->
   <section class="container">

      <div class="row section-head add-bottom">
         <div class="col full">
         
         	<?php
			
			if ( isset($session_expired) )
			{
				echo "<div class='errorbox'>
					<h3 style='color:#ff9c00;'>Notice: session has expired</h3>
					<p>Please login again to continue.</p>
				  </div>";
			}
			else if ( isset($incorrect_credentials) )
			{
				echo "<div class='errorbox'>
					<h3 style='color:#ff9c00;'>Notice: incorrect username or password</h3>
					<p>Please provide correct credentials to continue.</p>
				  </div>";
			}
			
			?>

            <h2>Login</h2>
            <form action='' method='post'>
            <label for='username'>Username</label>
            <input name='username' type='text' placeholder='username' id='username'/>
            
            <label for='password'>Password</label>
            <input name='password' type='password' placeholder='password' id='password'/>
            
            <input type='submit' value='Login' />
            </form>
         </div>
      </div>

    
    
   </section> <!-- Container End -->

   <!-- footer
   ================================================== -->
   <footer>

      <div class="row">

         <div class="col g-7">
            <ul class="copyright">
               <li>&copy; 2016 Pickmybrain</li>
               <li>Design by <a href="http://www.styleshout.com/" title="Styleshout">Styleshout</a></li>               
            </ul>
         </div>


      </div>

   </footer> <!-- Footer End-->

</body>

</html>