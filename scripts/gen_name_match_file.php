<?php

// run periodically to generate a list of names for name matching against.
require_once('../config.php');
require_once('../include/DownloadFile.php');

$downloads_dir = '../www/downloads/lookup/';
if (!file_exists($downloads_dir)) {
    mkdir($downloads_dir, 0777, true);
}

$out_path = $downloads_dir . '020_name_matching.csv';

// header rows for the csv file
$header = array(
    "WFO_ID",
    "scientificName",
    "scientificNameAuthorship",
    "taxonrank",
    "nomenclaturalStatus",
    "taxonomicStatus"
);


// destroy it if it exists
if(file_exists($out_path)) unlink($out_path);

// get the file
$out = fopen($out_path, 'w');

// write the headers
fputcsv($out, $header, escape: "\\");

// get the rows
$sql = "SELECT 
	i.`value` as WFO_ID,
    n.name_alpha as scientificName,
    n.authors as scientificNameAuthorship,
    n.`rank` as taxonrank,
    n.`status` as nomenclaturalStatus,
    if (	
		tn.taxon_id is not null, 
		if(t.id is not null, 'accepted'  , 'synonym'),
        'unplaced'
        ) as 'taxonomicStatus'
    
	FROM `names` as n
	join identifiers as i on n.prescribed_id = i.id
    left join taxon_names as tn on tn.name_id = n.id
    left join taxa as t on t.taxon_name_id = tn.id
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
$meta['title'] = "Name Matching Lookup Table";
$meta['description'] = "A list of all the names in the database along with their WFO IDs, nomenclatural and taxonomic status. This is intended to be useful for people who want to run name matching or lookup systems locally.";
$meta['size_bytes'] = filesize($out_path . '.gz');
$meta['size_human'] = DownloadFile::humanFileSize($meta['size_bytes']);
file_put_contents($out_path . '.gz.json', json_encode($meta, JSON_PRETTY_PRINT));

// job done.


function human_filesize($bytes, $decimals = 2) {
  $sz = 'BKMGTP';
  $factor = floor((strlen($bytes) - 1) / 3);
  return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$sz[$factor];
}