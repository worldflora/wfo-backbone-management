<?php 
require_once("../config.php");
require_once("../include/User.php");

require_once("../include/WfoDbObject.php");
require_once("../include/Name.php");
require_once("../include/UnplacedFinder.php");
require_once("../include/Taxon.php");
require_once("../include/Identifier.php");
require_once("../include/Reference.php");
require_once("../include/ReferenceUsage.php");
require_once("../include/UpdateResponse.php");
require_once("../bulk/include/functions.php");


// only gods are allowed to see anything in the bulk loading place.
// even that it exists!

if(@$_SESSION['user']){
    $user = unserialize(@$_SESSION['user']);
}else{
    $user = null;
}



if(!$user || $user->getRole() != 'god'){
    header('HTTP/1.0 403 Forbidden');
    echo "<p>You are not permitted here unless you are logged into Rhakhis and have the role of 'god'.</p>";
    echo "<p>Go away and come back later.</p>";
    exit;
}

$action = @$_REQUEST['action']; // use request because we may get posts as well for file uploads
if(!$action) $action = 'view';

require_once('../bulk/actions/' . $action . '.php');

function get_rhakhis_uri($wfo){

    // the name created an link to view it in new tab.
    // this is a bit hacky on which server we are on 
    // FIXME
    switch ($_SERVER['SERVER_NAME']) {

        // in dev
        case 'localhost':
            $uri = 'http://localhost:3000/#' . $wfo;
            break;

        // staging
        case 'rhakhis-test.rbge.info':
            $uri = 'https://rhakhis-test.rbge.info/index.html#' . $wfo;
            break;
    
        // live
        case 'rhakhis-live.rbge.info':
            $uri = 'https://rhakhis-live.rbge.info/index.html#' . $wfo;
            break;
        
        // unknown
        default:
            $uri = null;
            break;
    }

    return $uri;

}