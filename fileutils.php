<?php
require_once("config.php");

function CheckPermissions()
{
	$filesToCheck=array('nextnodeid.txt','nextchangesetid.txt','nextwayid.txt','db.lock');

	foreach($filesToCheck as $f)
	if(!is_writable($f))
	{
		header('HTTP/1.1 500 Internal Server Error');
		echo $f.' is not writable';
		exit();
	}

}

function GetServerRequestMethod()
{
	global $PROG_ARG_LONG;
	$options = getopt(PROG_ARG_STRING, $PROG_ARG_LONG);
	$out = "GET"; //The default
	if(isset($options["m"]))
	$out = $options["m"];
	if(isset($_SERVER['REQUEST_METHOD']))
	$out = $_SERVER['REQUEST_METHOD'];
	if(isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']))
	$out = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'];
	return $out;
}

function RequireMethod($reqMethod)
{
	if(strcmp(GetServerRequestMethod(),$reqMethod)!=0 and !DEBUG_MODE)
	{
		header('HTTP/1.1 405 Method Not Allowed');
		echo "Only method ".$reqMethod." is supported on this URI";
		exit;						
	}
}

function getDirectory( $path = '.', $level = 0 )
{
$out=array();
// Directories to ignore when listing output.
$ignore = array( '.', '..' );

// Open the directory to the handle $dh
$dh = @opendir( $path );

// Loop through the directory
while( false !== ( $file = readdir( $dh ) ) )
{
// Check that this file is not to be ignored
if( !in_array( $file, $ignore ) )
{
// Show directories only
if(is_dir( "$path/$file" ) )
{
	//echo $file."\n";
	array_push($out,$file);
}
}
}
// Close the directory handle
closedir( $dh );
return $out;
} 

function ReadAndIncrementFileNum($filename)
{
	chdir(dirname(realpath (__FILE__)));
	//This needs to be thread safe
	$fp = fopen($filename, "r+t");
	while (1) 
	{ 
		$wouldblock = null;
		$ret = flock($fp, LOCK_EX, $wouldblock);// do an exclusive lock
		if($ret == false) {throw new Exception('Lock failed.');}
		$out = (int)fread($fp,1024);
		if($out==0) $out = 1; //Disallow changeset to be zero
		fseek($fp,0);
		ftruncate($fp, 0); // truncate file
		fwrite($fp, $out+1);
		flock($fp, LOCK_UN); // release the lock
		fclose($fp);
		return $out;
	}
	return null;
}

function ReadFileNum($filename)
{
	chdir(dirname(realpath (__FILE__)));
	//This needs to be thread safe
	$fp = fopen($filename, "r+t");
	while (1) 
	{ 
		$wouldblock = null;
		$ret = flock($fp, LOCK_EX, $wouldblock);// do an exclusive lock
		if($ret == false) {throw new Exception('Lock failed.');}
		$out = (int)fread($fp,1024);
		if($out==0) $out = 1; //Disallow changeset to be zero
		flock($fp, LOCK_UN); // release the lock
		fclose($fp);
		return $out;
	}
	return null;
}

function SetFileNum($filename, $id)
{
	chdir(dirname(realpath (__FILE__)));
	//This needs to be thread safe
	$fp = fopen($filename, "w+t");

	$wouldblock = null;
	$ret = flock($fp, LOCK_EX, $wouldblock);// do an exclusive lock
	if($ret == false) {throw new Exception('Lock failed.');}
	ftruncate($fp, 0); // truncate file
	fwrite($fp, $id);
	flock($fp, LOCK_UN); // release the lock
	fclose($fp);
}

//http://www.codewalkers.com/c/a/File-Manipulation-Code/Recursive-Delete-Function/
function RecursiveDeleteFolder($dirname)
{ // recursive function to delete
// all subdirectories and contents:
if(is_dir($dirname))$dir_handle=opendir($dirname);
while($file=readdir($dir_handle))
{
if($file!="." && $file!="..")
{
if(!is_dir($dirname."/".$file))unlink ($dirname."/".$file);
else RecursiveDeleteFolder($dirname."/".$file);
}
}
closedir($dir_handle);
rmdir($dirname);
return true;
}

class Lock
{
	var $fp = null;
	var $lockObj = null;
	function __construct($write=0)
	{
		//echo "Getting Lock...\n"; 
		if(!$write)
		{
		chdir(dirname(realpath (__FILE__)));
		//To unlock, let the returned object go out of scope
		$this->fp = fopen("db.lock", "w");
		$this->lockObj = flock($this->fp, LOCK_SH);
		}
		else
		{
		chdir(dirname(realpath (__FILE__)));
		//To unlock, let the returned object go out of scope
		$this->fp = fopen("db.lock", "w");
		$this->lockObj = flock($this->fp, LOCK_EX);
		}
	}

	function __destruct()
	{
		//echo "Releasing Lock...\n"; 
		flock($this->fp, LOCK_UN);
	}
}

function GetReadDatabaseLock()
{
	return new Lock(0);
}

function GetWriteDatabaseLock()
{
	return new Lock(1);
}

function isValidEmail($email){
	return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function ValidateBbox($bbox)
{

  dprint( "ValidateBbox:",$bbox);

  if(!is_array($bbox) or count($bbox) != 4) return "invalid-bbox";

  if($bbox[0] > $bbox[2]){
    // swap em!
    $tmp = $bbox[0];
    $bbox[2]=$bbox[0];
    $bbox[0]=$tmp;
  }    
	if($bbox[1] > $bbox[3])
	{
		// swap em!
		$tmp = $bbox[1];
		$bbox[3]=$bbox[1];
		$bbox[1]=$tmp;
	}

	if($bbox[0] < -180.0 or $bbox[0] > 180.0) return "invalid-bbox";
	if($bbox[2] < -180.0 or $bbox[2] > 180.0) return "invalid-bbox";
	if($bbox[1] < -90.0 or $bbox[1] > 90.0) return "invalid-bbox";
	if($bbox[3] < -90.0 or $bbox[3] > 90.0) return "invalid-bbox";

	$area = abs((float)$bbox[2] - (float)$bbox[0]) * ((float)$bbox[3] - (float)$bbox[1]);
	global $PROG_ARG_LONG;
	$options = getopt(PROG_ARG_STRING, $PROG_ARG_LONG);
	if($area > MAX_QUERY_AREA and !isset($options['big-query']))
	{
		return "bbox-too-large";
	}

	return $bbox;
}

function UpdateBbox(&$original,$new)
{
	//Expand bbox to contain new area
	if(!is_array($new)) return;
	if(!is_array($original)) {$original = $new; return;}
	if($new[0] < $original[0]) $original[0] = $new[0];
	if($new[1] < $original[1]) $original[1] = $new[1];
	if($new[2] > $original[2]) $original[2] = $new[2];
	if($new[3] > $original[3]) $original[3] = $new[3];
}

function GetRequestPath()
{
	//Convert path to internally usable format
	if(isset($_SERVER['PATH_INFO']))
	{
		$pathInfo = $_SERVER['PATH_INFO'];
	}
	if(!isset($pathInfo) and isset($_SERVER['REDIRECT_URL'])) 
	{
		$pathInfo = $_SERVER['REDIRECT_URL'];
		$pathInfoExp = explode("/",$pathInfo);
		//TODO is there a better way then using INSTALL_FOLDER_DEPTH?
		$pathInfo = "/".implode("/",array_slice($pathInfoExp,INSTALL_FOLDER_DEPTH));
	}

	global $PROG_ARG_LONG;
	$options = getopt(PROG_ARG_STRING, $PROG_ARG_LONG);
	if(!isset($pathInfo))
	{
		$pathInfo= $options["p"];
	}

	if(!isset($pathInfo))
	{
		//print_r($_SERVER);
		die("Could not determine URL path, no -p option on the command line.\n" );
	}

	return $pathInfo;
}

function dprint($name,$value)
{
	//  debug_print_backtrace();
	error_log("DPRINT:". $name. ":". print_r($value,true) . "\n<",0);
}

//Allow GET args to be set by command line
function CommandLineOptionsSetVar($opts, $existVars)
{
	if($existVars === Null) $out = array();
	else $out = $existVars;

	if($opts !== Null)
	{
		if(!is_array($opts)) $opts = array($opts);
		foreach ($opts as &$value)
		{
			$kv = explode("=",$value);
			if (isset($kv[1]))
				$out[$kv[0]]=$kv[1];
			else
				$out[$kv[0]]="";
		}
	}
	return $out;
}

?>
