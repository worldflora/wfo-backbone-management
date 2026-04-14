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
if (curl_errno($curl)) {
    echo curl_error($curl);
    exit(1);
}

$result = json_decode($json);
if(json_last_error()){
    echo "JSON error: " . json_last_error() . "\n";
    echo $json;
    exit(1);
}

// work through the files

$latest = $result->files[0];
foreach ($result->files as $file) {
    echo "{$file->name}\t" . human_file_size($file->size_bytes) . "\t" . date('Y-m-d H:i:s', $file->modified_timestamp)  . "\n";
    if($file->modified_timestamp > $latest->modified_timestamp) $latest = $file;
}

echo "\nLatest file:\t{$latest->name}\n";


// where are we going to put the file?
$local_file_path = '../data/db_dumps/' . $latest->name;
if(file_exists($local_file_path)){
    echo "Have a local copy so aborting!\n";
    echo "Delete {$local_file_path} if you want download a fresh copy.";
    exit(1);
}

echo "Downloading ...\n";

$headers = array();
$headers[] = 'Authorization: Bearer '. RHAKHIS_BEARER_TOKEN;

$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, RHAKHIS_TOP_COPY_URL . 'download_backup.php?filename=' . $latest->name); // pass the file name as a parameter will trigger the download
curl_setopt($curl, CURLOPT_USERAGENT, 'World Flora Online: Rhakhis'); // tell them who we are
curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1); // yes we want to data
curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); // bearer token or we will be rejected
$out = fopen($local_file_path, 'w'); // get a handle to write to
curl_setopt($curl, CURLOPT_FILE, $out); // write it to the file

// trigger the download
$result = curl_exec($curl);
curl_close($curl);
fclose($out);

// double check we got it - and set flag to stop import if we can't uncompress it
if(!file_exists($local_file_path)){
    echo "Failed to create file!\n";
    exit(1);
} 

echo "\nCreated file of size: " . human_file_size(filesize($local_file_path)) . "\n";


echo "\nDone\n";

exit(0);

function human_file_size($size,$unit="") {
  if( (!$unit && $size >= 1<<30) || $unit == "GB")
    return number_format($size/(1<<30),2)."GB";
  if( (!$unit && $size >= 1<<20) || $unit == "MB")
    return number_format($size/(1<<20),2)."MB";
  if( (!$unit && $size >= 1<<10) || $unit == "KB")
    return number_format($size/(1<<10),2)."KB";
  return number_format($size)." bytes";
}