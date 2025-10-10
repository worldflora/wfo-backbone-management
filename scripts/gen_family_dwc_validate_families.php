<?php

/*

    Check that each name occurs in only one family
    even if it occurs in multiple family dwc files.

    Loads the whole thing in memory!

    php -d memory_limit=10G gen_family_dwc_validate_families.php

*/

// get a list of all the files in the 
$downloads_dir = '../www/downloads/dwc/';
$file_list = glob($downloads_dir . "*.zip");

$families = array(); // this is going to get big!

$first_file = true;
foreach($file_list as $zip_path){

    // only do the family files - not the _uber files
    if(!preg_match('/_wfo-/', $zip_path)){
        echo "Skipping $zip_path \n";
        continue;
    }

    echo $zip_path;
    echo "\n";

    $zip = new ZipArchive;
    $zip->open(realpath($zip_path));
    $in = $zip->getStream('classification.csv');

    // if this is the first file we need to find out 
    // the column for the family name
    if($first_file){
        $first_file = false;
        $header = fgetcsv($in, escape: "\\");
        $wfo_col_index = (int)array_search('taxonID', $header);
        $family_col_index = (int)array_search('family', $header);
    }else{
        fgetcsv($in); // just skip the header
    }

    // work through all the lines
    while($line = fgetcsv($in, escape: "\\")){

        $wfo = $line[$wfo_col_index];
        $family = $line[$family_col_index];

        if(isset($families[$wfo])){

            if($families[$wfo] != $family){
                echo "wfo: $wfo has family $family when previously it had $families[$wfo]\n";
                print_r($line);
                exit;
            }

        }else{
            $families[$wfo] = $family;
        }

    }

}

echo "\nSaving family map.\n";

$out = fopen('../www/downloads/dwc/_emonocot_family_placement_current.csv', 'w');

// pop in a header
fputcsv($out, array('wfo', 'family'), escape: "\\");

foreach($families as $wfo => $family){
    fputcsv($out, array($wfo, $family), escape: "\\");
}

fclose($out);

// if there is a previous list there then build the differences
$prev_path = '../www/downloads/dwc/_emonocot_family_placement_previous.csv';
if(file_exists($prev_path)){

    echo "\nComparing with previous family placements.\n";

    $out = fopen('../www/downloads/dwc/_emonocot_family_moved.csv', 'w');

    $in = fopen($prev_path, 'r');
    $header = fgetcsv($in, escape: "\\");

    fputcsv($out, array('wfo', 'family_previous', 'family_current'), escape: "\\");

    $counter = 0;
    while($line = fgetcsv($in, escape: "\\")){
        if(!isset($families[$line[0]]) || $families[$line[0]] != $line[1]){
            $family_previous = $line[1];
            $family_current = isset($families[$line[0]]) ? $families[$line[0]] : "DEDUPLICATED";
            fputcsv($out, array($line[0], $family_previous, $family_current), escape: "\\" );
            $counter++;
            echo "$counter\t{$line[0]}\t{$family_previous}\t{$family_current}\n";
        }
    }

    fclose($in);
    fclose($out);

}else{
    echo "\nNo previous family placement file so not doing comparisons.\n";
}

echo "\nAll complete\n";