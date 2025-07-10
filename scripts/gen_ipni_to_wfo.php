<?php

// run periodically to generate a map of ipni IDs to WFO IDs
require_once('../config.php');
require_once('../include/DownloadFile.php');

// this is the temp file - will be compressed if successful

$downloads_dir = '../www/downloads/lookup/';
if (!file_exists($downloads_dir)) {
    mkdir($downloads_dir, 0777, true);
}

$out_path = $downloads_dir . '015_ipni_to_wfo.csv';

// header rows for the csv file
$header = array(
    "ipni_id",
    "wfo_id"
);


// destroy it if it exists
if(file_exists($out_path)) unlink($out_path);

// get the file
$out = fopen($out_path, 'w');

// write the headers
fputcsv($out, $header, escape: "\\");

// get the rows
$sql = "SELECT distinct ipni.`value` as ipni_id, wfo.`value` as wfo_id
FROM identifiers as ipni
JOIN `names` as n on n.id = ipni.name_id
JOIN identifiers as wfo on n.prescribed_id = wfo.id 
WHERE ipni.kind = 'ipni'
AND wfo.kind = 'wfo'
AND n.`status` != 'deprecated'
ORDER BY ipni.`value`;";

// don't store the result because this is a big one
$response = $mysqli->query($sql, MYSQLI_USE_RESULT);

// throw a wobbly if we get an error
if($mysqli->error){
    echo $mysqli->error;
    echo $sql;
    $response->close();
    exit;
}

// work through the dataset and write it to the csv file
$row_count = 1;
$last_ipni = null;
while($row = $response->fetch_assoc()){
    if($row['ipni_id'] == $last_ipni) continue; // no doubles
    if(!preg_match('/^urn\:lsid\:ipni\.org\:names\:[0-9]+-[0-9]+$/', $row['ipni_id'])) continue; //no blanks
    $last_ipni = $row['ipni_id'];
    fputcsv($out, $row, escape: "\\");
    $row_count++;
}

// because we held the query open be sure to close it
$response->close();

// close down the csv file.
fclose($out);

// gzip it (will remove original)
exec("gzip -f $out_path");

// we add a sidecar describing the file
$meta = array();
$meta['filename'] = $out_path . '.gz';
$now = new DateTime();
$meta['created'] = $now->format(DateTime::ATOM);
$meta['title'] = "IPNI ID to WFO ID mapping table";
$meta['description'] = "A list of all the IPNI IDs we are tracking and the WFO IDs they map to.";
$meta['size_bytes'] = filesize($out_path . '.gz');
$meta['size_human'] = DownloadFile::humanFileSize($meta['size_bytes']);
file_put_contents($out_path . '.gz.json', json_encode($meta, JSON_PRETTY_PRINT));
