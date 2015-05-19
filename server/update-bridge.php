<?php
	
	ini_set("output_buffering", "Off");
	/**
	 * Update-Bridge
	 * @author darita
	 * @version 0.2 Direwolf
	 *
	 * This script is used to push HotFixes and Daily Updates of files to an webpage
	 */


	/**
	 * Update-Bridge class
	 *
	 * Base class for Update-Bridge
	 */
	class UpdateBridge{

		/** @var Array  Contains the credentials for a logon */
		private $credentials;
		/** @var Array  Contains all rewrite rules */
		private $rewriteRules;
		/** @var Bool  Are the credentials OK? */
		private $credentialsOK;
		/** @var String  Contains the error code */
		public $errorCode;
		/** @var bool  Must the file be extraced before flash? */
		public $extractFile;

		/**
		 * Init the object
		 * 
		 * Call loading functions
		 */
		function __construct(){
			$this -> errorCode = "";
			$this -> extractFile = false;
			$this -> credentialsOK = false;
			$this -> credentials = Array();
			$this -> rewriteRules = Array();
			$this -> loadCredentials();
			$this -> loadRewriteRules();
		}

		/**
		 * Throw an error
		 *
		 * @param string $code  Errorcode for further review
		 * @return void
		 */
		function throwError($code){
			$this -> errorCode .= $code . "\n";
		}

		/**
		 * Set the User/Password credentials for those who will get access
		 *
		 * Add User and Password pairs to the credentials element 
		 * Every User/PW pair will get access to modify things
		 *
		 * @example $this -> credentials[] = Array('user' => 'USERNAME', 'pw' => 'PASSWORD');
		 *
		 * @return void
		 */
		function loadCredentials(){
			$this -> credentials[] = Array('user' => 'sample', 'pw' => 'xxx');
		}


		/**
		 * Set the Rewrite Rules
		 *
		 * Add Searchpattern and Replacepattern to the rewriteRules element
		 * Search/Replacepatterns will be parsed with regex (preg_replace)
		 *
		 * @example $this -> rewriteRules[] = Array('search' => 'SEARCHPATTERN', 'replace' => 'REPLACEPATTERN');
		 * 
		 * @return void
		 */
		function loadRewriteRules(){
			// $this -> rewriteRules[] = Array('search' => '/^@vpl$/', 'replace' => '/content/vpl.php');
		}


		/**
		 * Check the given credentials
		 *
		 * Check if the given credentials compare to the stored ones
		 *
		 * @return bool  true if credentials are correct, false if credentials are wrong
		 */
		function checkCredentials($user, $pw){
			$access = false;
			foreach($this -> credentials as $credential){
				if(($credential['user'] == $user)&&($credential['pw'] == $pw)){
					$access = true;
				}
			}
			if($access){
				$this -> credentialsOK = true; // The Credentials are OK
			}else{
				$this -> credentialsOK = false;
			}
			return $access;
		}

		/**
		 * Rewrites a given path based on rewriteRules
		 *
		 * Rewrites the given path with preg_replace and the rewriteRules
		 *
		 * @param string $path  The given path
		 * @return string  The rewritten path
		 */
		function rewritePath($path){
			$retpath = $path;
			foreach ($this -> rewriteRules as $rewriteRule) {
				if(preg_match($rewriteRule['search'], $retpath)){
					$retpath = preg_replace($rewriteRule['search'], $rewriteRule['replace'], $retpath);
				}
			}
			return $retpath;
		}


		/**
		 * Flash the new update
		 *
		 * Flash the uploaded files to their destination
		 *
		 * @param string $path  Path to flash the file in
		 *
		 * @return bool  true if the file could be flashed, file if not
		 */
		function flash($path){
			if(!$this -> credentialsOK){
				$this -> throwError("0x1  Credentials were wrong");
				return false;
			}
			$path = getcwd() . '/' . $this -> rewritePath($path);

			if(count($_FILES) == 1){
				// There is only one file, let's use the $path var as the whole filename
				
				foreach($_FILES as $file){



					if($this -> extractFile){
						$this -> flashExtract($path, $file);
					}else{
						// Don't Extract the file

						$flashScript = $this -> loadFlashScript($path);

						if($flashScript != null){
							if($flashScript -> preFlash($path) == false){ // Start preFlash
								// Returned false: Quit flashing
								continue;						
							}
						}

						if(($flashScript != null)&&($flashScript -> overrideFlash)){
							// Override flash function
							$flashScript -> flash($file['tmp_name'], $path);
						}else{
							move_uploaded_file($file['tmp_name'], $path); 
						}

						if($flashScript != null){
							$flashScript -> postFlash($path); // Start postFlash
						}
					}
				}
			}else{
				// There is more than one file, let's use the $path as root path and use the given filenames
				foreach($_FILES as $file){
					if($this -> extractFile){
						$this -> flashExtract($path . '/' . $file['name'], $file);
					}else{
						move_uploaded_file($file['tmp_name'], $path . '/' . $file['name']);
					}
				}
			}
		}


		/**
		 * Extract an flash file
		 *
		 * Extracts an archived file
		 *
		 * @param String $path  Path to which the flashfile should be extracted
		 * @param FILE $file  Uploaded file to extract (from $_FILES[] arry)
		 * @return bool  true if everything went well, false if not
		 */
		function flashExtract($path, $file){
			$tmppath = tempnam(sys_get_temp_dir(), 'ota_flash_');
			if($tmppath == false){
				$this -> throwError('0x2  Could not create temporary path');
				$this -> throwError('0x3  Could not extract file');
				return false;
			}
			move_uploaded_file($file['tmp_name'], $tmppath);
			$unzippath = sys_get_temp_dir() . '/ota_flash_' . md5(time());
			mkdir($unzippath);
			if($unzippath == false){
				$this -> throwError('0x2  Could not create temporary path');
				$this -> throwError('0x3  Could not extract file');
				return false;
			}
			exec("tar -zxvf $tmppath -C $unzippath");
			$this -> recurse_copy($unzippath, $path . '/');

			unlink($tmppath);
			$this -> rrmdir($unzippath);
			return true;
		}

		/** 
		 * Loads the flash script for the specified file
		 *
		 * @param String $path  Path to the file, into which the file should be flashed
		 * @return Object  null if no flash script exists, otherwise the script object
		 */
		function loadFlashScript($path){
			$dir = dirname($path);
			$flashfilename = '_' . basename($path) . '.ota.php';
			if(file_exists($dir . '/' . $flashfilename)){
				include($dir . '/' . $flashfilename);
				$objectname = str_replace('.', '_', '_' . basename($path) . '_ota');
				$object = new $objectname();
				return $object;
			}
			return null;
		}


		/** 
		 * Copy files/folders recoursiveley
		 *
		 * @param string $src  Source directory
		 * @param string $dst  Destination directory
		 * @return void
		 */
		function recurse_copy($src, $dst) { 
		    $dir = opendir($src); 
		    @mkdir($dst); 
		    while(false !== ( $file = readdir($dir)) ) { 
		        if (( $file != '.' ) && ( $file != '..' )) { 
		            if ( is_dir($src . '/' . $file) ) { 
		                $this -> recurse_copy($src . '/' . $file,$dst . '/' . $file); 
		            } 
		            else { 
		                copy($src . '/' . $file,$dst . '/' . $file); 
		            } 
		        } 
		    } 
		    closedir($dir); 
		} 

		/**
		 * Remove dir recursive
		 *
		 * @param string $dir  Directory to remove
		 * @return bool  true if dir could be removed, false if not
		 */
		function rrmdir($dir) {
			$files = array_diff(scandir($dir), array('.','..'));
			foreach ($files as $file) {
				(is_dir("$dir/$file")) ? $this -> rrmdir("$dir/$file") : unlink("$dir/$file");
			}
			return rmdir($dir);
		}

		/**
		 * Close the Connection
		 *
		 * We need this to return immediately to the user
		 * @param string $message  the Message we should send
		 */
		function closeConnection($message){

			ob_start();
			
			echo $message;
			echo "closed at " . microtime(true) . "\n";
			echo(str_repeat(' ', 65537)); // We fill the buffer
			session_write_close();

			header("Content-Encoding: none");//send header to avoid the browser side to take content as gzip format
			header("Content-Length: " . ob_get_length());//send length header
			header("Connection: close");//or redirect to some url: header('Location: http://www.google.com');
			ob_end_flush();flush();//really send content, can't change the order:1.ob buffer to normal buffer, 2.normal buffer to output
		}

	}

	


	// Check if we are on the update-bridge.php
	if($_SERVER['REQUEST_URI'] == '/update-bridge.php'){
		// We are on update-bridge.php
		// so let's invoke the update-bridge class

		$path = isset($_POST['path']) ? $_POST['path'] : '';
		$user = isset($_POST['user']) ? $_POST['user'] : '';
		$pw = isset($_POST['pw']) ? $_POST['pw'] : '';

		$__log =  "received at " . microtime(true) . "\n";


		if(($user != '')&&($pw != '')){

			$_UB = new UpdateBridge();

			// We check if we should extract the file
			if(isset($_POST['extract'])){
				$_UB -> extractFile = true;
			}

			// Push the credentials to the UpdateBridge
			$credOK = $_UB -> checkCredentials($user, $pw);
			if(!$credOK){ // and exit if they were wrong
				echo $_UB -> errorCode;
				exit;
			}

			// We close the connection to the client
			// so we could do time-critical jobs
			$_UB -> closeConnection($__log . ': OK');


			ob_start(); // we buffer the output
			sleep(5); // sleep 5 seconds - time for the client to quit the connection

			echo "continued at " . microtime(true) . "\n";


			// Now - lastly - we'll flash the file
			$ret = $_UB -> flash($path);

			if($ret == false){
				echo $_UB -> errorCode;
			}else{
				echo ": OK";
			}

			echo "finished at " . microtime(true) . "\n";

			$logfile = '/tmp/update-bridge.log';
			$logcontent = file_get_contents($logfile);
			file_put_contents($logfile, $logcontent . "\n" . ob_get_contents()); // Write into the log
			ob_end_clean(); // Clean up
		}

	}else{
		// We are not on update-bridge.php - maybe we are included by another script?
		// so let's invoke nothing
	}

	// If requested with /update-bridge.php?ui we display a simple ui
	if($_SERVER['REQUEST_URI'] == '/update-bridge.php?ui'){
?>
<!DOCTYPE html>
<html>
<head>
	<title>Update-Bridge</title>
	<style type="text/css">
		input{
			display: block;
			float: left;
		}
		label{
			display: block;
			float: left;
			clear: both;
			width: 200px;
		}
	</style>
</head>
<body>
	<form action='/update-bridge.php' method='POST' enctype='multipart/form-data'>
		<h1>Flash a file with the Update-Bridge</h1>
		<label>Path</label><input type='text' name='path' />
		<label>User</label><input type='text' name='user' />
		<label>Password</label><input type='password' name='pw' />
		<label>Extract</label><input type='checkbox' name='extract' />
		<label>File</label><input type='file' name='file' />
		<label>&nbsp;</label><input type='submit' value='Flash' />
	</form>
</body>
</html>
<?php
	}
?>