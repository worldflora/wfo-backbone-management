<?php

/*
    
    This saves having to rsync files around.
    It gives access to the database backups to anyone with an api key in the config file.

    An Airflow DAG can also check that the database has been backed up correctly

*/

require_once('../config.php');
require_once('../include/BearerToken.php');

// they have to pass a bearer token access this page.
// we don't want anyone downloading database dumps
if(!BearerToken::authorized()){
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode((object)array(
        'success' => false,
        'message' => 'You need to pass a bearer token to be able to access this resource.'
    ));
    exit;
}

$db_dumps_dir = '../data/db_dumps/';

if(!isset($_GET['filename'])){
    // if they haven't set the file name then return a json 
    // array containing a list of the backup files available.
    
    $out = array();

    $files = glob($db_dumps_dir . "*");

    $files = array_reverse($files);

    foreach ($files as $file_path) {
        $out[] = (object)array(
            'name' => basename($file_path),
            'size_bytes' => filesize($file_path),
            'modified_timestamp' => filemtime($file_path)
        );
    }

    header('Content-Type: application/json');
    echo json_encode((object)array(
        'success' => true,
        'message' => 'Here is a list of the backup files.',
        'files' => $out
    ));
    exit;


}else{

    // they passed a file name so return that file
    $filename = $_GET['filename'];

    set_time_limit(60*60); // maximum an hour to download

    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Content-Encoding: gzip');
    
    $out = fopen($db_dumps_dir . $filename, 'rb');
    fpassthru($out);

}




