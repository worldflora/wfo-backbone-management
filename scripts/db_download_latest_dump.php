<?php

/*

    This will call the live (top copy) of Rhakhis for the last database dump and
    download it to the ../data/db_dumps/ directory so that it can be picked up
    by the db_restore_latest_dump.sh script. 

*/

require_once('../config.php');

echo "\nFetching list of available db dumps\n";

$headers = array();
$headers[] = 'Content-Type: application/json';
$headers[] = 'Authorization: Bearer '. RHAKHIS_BEARER_TOKEN;

$curl = curl_init(RHAKHIS_TOP_COPY_URL . 'download_backup.php');
curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($curl, CURLOPT_USERAGENT, 'World Flora Online: Rhakhis');
curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
$json = curl_exec($curl);
$result = json_decode($json);
if (curl_errno($curl)) {
    $this->error = curl_error($curl);
}else{
    print_r($result);
}


echo "\nDone\n";