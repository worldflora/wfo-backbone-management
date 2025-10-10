<?php

/*

    Run once utility script.

*/

require_once('../config.php');

$in = fopen('../data/sources/rods_final_matches.csv', 'r');

// throw out the header
$header = fgetcsv($in, escape: "\\");

$counter = 0;

while($line = fgetcsv($in, escape: "\\")){

    $wfo_id = $line[0];
    $ipni_id = $line[3];
    if(!$wfo_id || !$ipni_id) continue;

    if($wfo_id == 'SKIPPED') continue;
    
    $counter++;
    echo "$counter\t$wfo_id\t$ipni_id\n";

    $sql = "UPDATE kew.rod_dois SET `wfo_id` = '$wfo_id' WHERE `ipni_id` = '$ipni_id' AND `wfo_id` is null;";
    $mysqli->query($sql);

    if($mysqli->error){
        echo $mysqli->error;
        exit();
    }


}

fclose($in);