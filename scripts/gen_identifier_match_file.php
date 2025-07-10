<?php

// run periodically to generate a list of names for name matching against.
require_once('../config.php');
require_once('../include/DownloadFile.php');

// this is the temp file - will be compressed if successful

$downloads_dir = '../www/downloads/lookup/';
if (!file_exists($downloads_dir)) {
    mkdir($downloads_dir, 0777, true);
}

$out_path = $downloads_dir . '010_identifier_matching.csv';

// header rows for the csv file
$header = array(
    "identifier",
    "kind",
    "wfo_id",
    "scientificName",
    "scientificNameAuthorship"
);


// destroy it if it exists
if(file_exists($out_path)) unlink($out_path);

// get the file
$out = fopen($out_path, 'w');

// write the headers
fputcsv($out, $header, escape: "\\");

// get the rows
$sql = "SELECT 
	i.`value` as identifier,
    i.kind as kind,
    i2.`value` as wfo_id,
    n.name_alpha as scientificName,
    n.authors as scientificNameAuthorship
from identifiers as i
join `names` as n on n.id = i.name_id
join identifiers as i2 on n.prescribed_id = i2.id
    ";

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
while($row = $response->fetch_assoc()){
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
$meta['title'] = "Identifier Matching Lookup Table";
$meta['description'] = "A list of all the external identifiers in the database matched to the prescribed WFO ID that should be used for that name. Scientific name strings and authors are included for debugging. This is intended to be useful for people doing data knitting between different sources.";
$meta['size_bytes'] = filesize($out_path . '.gz');
$meta['size_human'] = DownloadFile::humanFileSize($meta['size_bytes']);
file_put_contents($out_path . '.gz.json', json_encode($meta, JSON_PRETTY_PRINT));
