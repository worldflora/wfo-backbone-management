<?php

require_once('../config.php');

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

echo "Latest ID: $latest_id\n";

// start editing
echo "Setting it to edit\n";

$post_json = "";
$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, "$zenodo_access_uri/api/deposit/depositions/{$latest_id}/actions/edit?access_token=$zenodo_access_token");
curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Content-Length: ' . strlen($post_json)));
curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
curl_setopt($curl, CURLOPT_POSTFIELDS, $post_json);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($curl);
// curl_close($curl); deprecated since 8.5, as it has no effect since PHP 8.0

print_r(json_decode($response));


// upload the metadata
echo "Uploading metadata\n";

$metadata = file_get_contents('../data/versions/zenodo_metadata.json');
$post_data = array("metadata" => json_decode($metadata)); // one php object

$post_json = json_encode($post_data);

$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, "$zenodo_access_uri/api/deposit/depositions/{$latest_id}?access_token=$zenodo_access_token");
curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Content-Length: ' . strlen($post_json)));
curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
curl_setopt($curl, CURLOPT_POSTFIELDS, $post_json);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($curl);
curl_close($curl);

print_r(json_decode($response));

echo "Publishing\n";

$post_json = "";
$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, "$zenodo_access_uri/api/deposit/depositions/{$latest_id}/actions/publish?access_token=$zenodo_access_token");
curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Content-Length: ' . strlen($post_json)));
curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
curl_setopt($curl, CURLOPT_POSTFIELDS, $post_json);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($curl);
// curl_close($curl); deprecated since 8.5, as it has no effect since PHP 8.0

print_r(json_decode($response));

echo "\n--------------------------\n";
echo "Metdata updated";
echo "\n--------------------------\n";