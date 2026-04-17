<?php

/*

    This script is the callback script that 
    ORCID will use

*/

require_once('../config.php');
require_once('../include/User.php');

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: http://localhost:3000"); // FIXME THIS SHOULD BE CONFIGURABLE
header("Access-Control-Allow-Credentials: true");
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, wfo_access_token');

// will be called with the access code for authentication
$access_code = $_GET['code'];

$post_fields = array(
    'client_id' => ORCID_CLIENT_ID,
    'client_secret' => ORCID_CLIENT_SECRET,
    'grant_type' => 'authorization_code',
    'code' => $access_code,
    'redirect_uri' => ORCID_REDIRECT_URI
);

// var_dump($post_fields);

// we need to use this code to get the access token
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, ORCID_TOKEN_URI);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields));

$data = curl_exec($ch);

$orcid_response = json_decode($data);

//echo $data;

// what kind of response is it?
if(isset($orcid_response->access_token) ){

    // we are in 
    echo 'we are here;';

    $user = User::loadUserForOrcidId($orcid_response->orcid);

    // user does not exist so create one
    if(!$user){

        echo "<p>No loaded user for {$orcid_response->access_token}</p>";
        $user = new User();

        // we are creating a new user for and orcid id so we assume they are a "nobody rather than annonymous 
        $user->setRole('nobody');
    
    } 

    $user->setOrcidId($orcid_response->orcid);
    $user->setOrcidAccessToken($orcid_response->access_token);
    $user->setOrcidRefreshToken($orcid_response->refresh_token);
    $user->setOrcidExpiresIn($orcid_response->expires_in);
    $user->setName($orcid_response->name);
    $user->setOrcidRaw($data);
    $user->save();

   if($user->getId()){
        
        // we are safely logged in so add the $user_id and name to the session
        $_SESSION['user'] = serialize($user);
        $message = '<h2>Success!</h2>';
        $message .= "<button onclick=\"closeWindowAndRefresh()\">Close</button>";
        render_html_page($message, true);

    }else{
        
        // failed to log in
        session_unset();
        $message = '<h2>Failure :(</h2>';
        $message .= "<p>Unable to create user to match ORCID ID</p>";
        $message .= "<button onclick=\"closeWindowAndRefresh()\">Close</button>";
        render_html_page($message, false);
    }


}else{

    // failed to log in
    session_unset();

    $message = '<h2>Failed</h2><dl>';

    foreach ($orcid_response as $key => $value) {
        $message .= "<dt>$key<dt><dd>$value</dd>";
    }
    $message .= '</dl>';
    $message .= "<button onclick=\"closeWindowAndRefresh()\">Close</button>";

    render_html_page($message, false);

}

/*
object(stdClass)#2 (7) {
  ["access_token"]=>
  string(36) "a2e741bc-2c81-4039-be59-eaa252e6f603"
  ["token_type"]=>
  string(6) "bearer"
  ["refresh_token"]=>
  string(36) "fe41dd4b-0a34-48aa-b5fd-b839dd0f49d1"
  ["expires_in"]=>
  int(631138518)
  ["scope"]=>
  string(13) "/authenticate"
  ["name"]=>
  string(10) "Roger Hyam"
  ["orcid"]=>
  string(19) "0000-0003-4581-1379"
}

*/


/*
curl -i -L -k -H 'Accept: application/json' --data '
client_id=APP-M2OKX0CJNXE9CBMO
&client_secret=18aae478-1e6b-40c1-8492-5ffd1ca66b77
&grant_type=authorization_code
&redirect_uri=http://127.0.0.1:3100/data/orcid_redirect.php
&code=REPLACE WITH OAUTH CODE' 
https://orcid.org/oauth/token
*/

// very simple page
function render_html_page($message, $auto_close){
    $autoclose = false;
    if($auto_close){ echo "<script>closeWindowAndRefresh();</script>"; }
    echo "<h1>ORCiD Sign In Response</h1>";
    echo $message;
}
?>