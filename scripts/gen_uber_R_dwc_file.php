<?php

/*

    This cuts down the _uber darwin core file 
    to make a file that is suitable for use by 
    the R package

*/

echo "\nCreating Uber R file\n";

// if the field is in here it will be dropped
// from the output
$fields_to_drop = array(
    "speciesHybridMarker",
    "infraspecificRank",
    "originalID",
    "old_t1id",
    "tropicosId",
    "references1.0",
    "doNotProcess",
    "doNotProcess_reason",
    "OfficialFamily",
    "comments",
    "deprecated"
);

$uber_zip_path = "../www/downloads/dwc/_uber.zip";
$uber_R_csv_path = "../www/downloads/dwc/_uber_R.csv";
$uber_R_zip_path = "../www/downloads/dwc/_DwC_backbone_R.zip";

// get a handle on the file
$uber_zip = new ZipArchive();
if (!$uber_zip->open($uber_zip_path)) {
    echo "\nFailed to open $uber_zip_path";
    exit;
}
$fp = $uber_zip->getStream('classification.csv');

if(!$fp){
    echo "\nFailed to get stream for 'classification.csv'";
    exit;
}

$header = fgetcsv($fp);
$deprecated_field_index = array_search('deprecated', $header); 

$drop_field_indexes = array();
foreach($fields_to_drop as $field){
    $drop_field_indexes[] = array_search($field, $header);
}

// create a file to write to
$out = fopen($uber_R_csv_path, 'w');

// build a new header
$new_header = array();
for ($i=0; $i < count($header); $i++) { 
    if(in_array($i, $drop_field_indexes)) continue;
    $new_header[] = $header[$i];
}
fputcsv($out, $new_header, "\t", escape: "\\");

while($row = fgetcsv($fp)){

    // don't do the deprecated rows
    if($row[$deprecated_field_index] == 1) continue;

    $new_row = array();
    for ($i=0; $i < count($row); $i++) { 
        if(in_array($i, $drop_field_indexes)) continue;
        $new_row[] = $row[$i];
    }

    $new_row = str_replace("\t", " ", $new_row); // safety first
    fputcsv($out, $new_row, "\t", escape: "\\");

}

fclose($fp);
$uber_zip->close();
fclose($out);

// wrap it up in a zip file
echo "\nZipping Up\n";
$zip = new ZipArchive();
if ($zip->open($uber_R_zip_path, ZIPARCHIVE::CREATE)!==TRUE) {
    exit("cannot open <$uber_R_zip_path>\n");
}
$zip->addFile($uber_R_csv_path, "classification.csv");
$zip->close();

unlink($uber_R_csv_path);

echo "\nFinnished!\n";

