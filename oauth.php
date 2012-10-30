<?php
require_once('OAuth.php');
require_once('fileutils.php');
require_once('system.php');

class OAuthMicrocosm
{
	function __construct()
	{

	}	 	

	function lookupConsumer($provider)
	{
		//Hard coded JOSM keys
		if($provider != "AdCRxTpvnbmfV8aPqrTLyA")
			return OAUTH_CONSUMER_KEY_UNKNOWN;
		//return OAUTH_CONSUMER_KEY_REFUSED;
		$provider->consumer_secret = "XmYOiGY9hApytcBC3xCec3e28QBqOWz5g6DSb5UpE";

		return OAUTH_OK;
	}
	
	function timestampNonceChecker()
	{

	}

	function tokenHandler()
	{

	}

	function Process($pathInfo)
	{
		try
		{
			$this->provider = new OAuthProvider();
			$this->provider->consumerHandler(array($this,'lookupConsumer'));	
			$this->provider->timestampNonceHandler(array($this,'timestampNonceChecker'));
			$this->provider->tokenHandler(array($this,'tokenHandler'));
			$this->provider->setRequestTokenPath('/request_token');  // No token needed for this end point
			$this->provider->checkOAuthRequest();
		} 
		catch (OAuthException $E)
		{
			echo OAuthProvider::reportProblem($E);
			return true;
		}
		return false;
	}



}

//Split URL for processing
$pathInfo = GetRequestPath();
$urlExp = explode("/",$pathInfo);

$fi = fopen("log.txt","wt");
fwrite($fi,print_r($_POST,True));
fwrite($fi,print_r($pathInfo,True));


if($urlExp[1] == "request_token")
{
	$oam = new OAuthMicrocosm();
	$error = $oam->Process($pathInfo);
	//if(!$error)
	//	echo "login_url=https://localhost/m/oauth.php/authorize&oauth_token=".uniqid(mt_rand(), true).
	//		'&oauth_token_secret='.uniqid(mt_rand(), true).
	//		'&oauth_callback_confirmed=true';
}

fclose($fi);

?>
