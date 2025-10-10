<?php

// This runs through the latest uber dump file to see if the families have changed
// since the table in botalista.

require_once('../config.php');

echo "\nFamilies and changed since Botalista\n";

$in = fopen('zip://../www/downloads/dwc/_uber.zip#classification.csv', 'r');

echo "Header\n";
$line = fgetcsv($in, escape: "\\");
print_r($line);

while($line = fgetcsv($in, escape: "\\")){

    $wfo = $line[0];
    $current_family = $line[7];

    // get the family from botalista
    $mysqli->query("UPDATE botalista_dump_2 SET current_family = '$current_family' WHERE taxonID = '$wfo'");

    echo "$wfo\t$current_family\n";

}
    
fclose($in);