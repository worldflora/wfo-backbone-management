<?php

require_once('../config.php');

// $zenodo_file_map is pulled from config

// get the id of the latest version of the concept
$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, $zenodo_access_uri . "/api/records/$zenodo_concept_id?access_token=" . $zenodo_access_token);
curl_setopt($curl, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Content-Type: application/json'));
curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($curl);
curl_close($curl);
$response = json_decode($response, true);
$latest_id = $response['id'];


// create a new version - this seems to only make a new version if the latest version has been published.
$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, "$zenodo_access_uri/api/deposit/depositions/$latest_id/actions/newversion?access_token=$zenodo_access_token");
curl_setopt($curl, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Content-Type: application/json'));
curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($curl, CURLOPT_POST, true); // post creates
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);   
curl_setopt($curl, CURLOPT_POSTFIELDS, "{}");
$response = curl_exec($curl);
// curl_close($curl); deprecated since 8.5, as it has no effect since PHP 8.0

$response = json_decode($response, true);

// get the details of the new version
$version_uri = $response['links']['latest_draft'] . "?access_token=$zenodo_access_token";
$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, $version_uri);
curl_setopt($curl, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Content-Type: application/json'));
curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($curl);
// curl_close($curl); deprecated since 8.5, as it has no effect since PHP 8.0
$version = json_decode($response, true);

//print_r($version);

// delete files on server that aren't in list or who's checksums don't match
$files_to_upload = array_keys($zenodo_file_map);

foreach ($version['files'] as $file) {
    
    if(!isset($zenodo_file_map[$file['filename']])){
        // it isn't in the list so should be removed
        delete_file($zenodo_access_uri, $zenodo_access_token, $version['id'], $file['id']);
    }else{
        // it is in the list but does it have the same checksum
        $local_checksum = md5_file($zenodo_file_map[$file['filename']]);

        if($local_checksum != $file['checksum']){
            // checksums don't match so delete uploaded file
            delete_file($zenodo_access_uri, $zenodo_access_token, $version['id'], $file['id']);
        }else{
            // it is already there and has the same checksum
            // so remove it from the list of things to upload
            $files_to_upload = array_diff( $files_to_upload, array($file['filename']));
        }
    }
   
}

// upload the files we need to
foreach($files_to_upload as $filename){

    $bucket = $version['links']['bucket'];

    echo "\n\tUploading file $filename";

    $file_path = $zenodo_file_map[$filename];

    // upload the file
    $curl = curl_init();
    $put_uri = $bucket . '/' . urlencode($filename) . "?access_token=" . $zenodo_access_token;
    curl_setopt($curl, CURLOPT_URL, $put_uri);
    curl_setopt($curl, CURLOPT_PUT, true);
    $in = fopen( $file_path , 'r');
    curl_setopt($curl, CURLOPT_INFILE,$in);
    curl_setopt($curl, CURLOPT_INFILESIZE, filesize($file_path));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec ($curl);
    fclose($in);
    // curl_close($curl); deprecated since 8.5, as it has no effect since PHP 8.0

    //print_r($response);

}

// upload the metadata

$metadata = file_get_contents('../data/versions/zenodo_metadata.json');
$post_data = array("metadata" => json_decode($metadata)); // one php object
$post_json = json_encode($post_data);

$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, "$zenodo_access_uri/api/deposit/depositions/{$version['id']}?access_token=$zenodo_access_token");
curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Content-Length: ' . strlen($post_json)));
curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
curl_setopt($curl, CURLOPT_POSTFIELDS, $post_json);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);   
$response = curl_exec($curl);
// curl_close($curl); deprecated since 8.5, as it has no effect since PHP 8.0

//print_r(json_decode($response));

echo "\n--------------------------\n";
echo "The draft is here: " . $version['links']['latest_draft_html'];
echo "\n--------------------------\n";

function delete_file($zenodo_access_uri, $zenodo_access_token, $deposit_id, $file_id){

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, "$zenodo_access_uri/api/deposit/depositions/$deposit_id/files/$file_id?access_token=$zenodo_access_token");
    curl_setopt($curl, CURLOPT_HTTPHEADER, array('Accept: application/json', 'Content-Type: application/json'));
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "DELETE");
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);   
    $response = curl_exec($curl);
    // curl_close($curl); deprecated since 8.5, as it has no effect since PHP 8.0

}