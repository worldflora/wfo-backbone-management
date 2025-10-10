<?php

    // Given two DwC Uber zip files this will
    // create a list of all the names that have moved

    // run with php -d memory_limit=10G gen_uber_moved_families.php


    $data_dir = '../data/families_moved/'; // we'll store files here along with the results
    $zenodo_uri  = 'https://zenodo.org/doi/10.5281/zenodo.7460141'; // this is to the root of all the versions in zenodo.

    echo "\nDwC Moved families check.\n\n";

    echo "\nComparing current to previous data release.\n";

    // check we have a data directory to mess with
    if(!file_exists('../data/families_moved/')){
        mkdir('../data/families_moved/', 0777, true);
    }

    $current_path = $data_dir . 'current_uber.zip';
    $previous_path = $data_dir . 'previous_uber.zip';

    if(!file_exists($current_path) || !file_exists($previous_path)){
        echo "\nThis script expects to DwC.zip files to exist in the data directory ($data_dir).";
        echo "\nThese files should be called current_uber.zip and previous_uber.zip.";
        echo "\nYou can download _uber.zip files for all versions from Zenodo ($zenodo_uri).";
        echo "\nThere is no need to unzip the files.";
        exit;
    }

    // read the whole of old file into an array - needs lots of memory
    echo "\tReading previous file into memory.\n";

    $in = fopen('zip://' . $previous_path . '#classification.csv' , 'r');
   
    if(!$in){
        echo "\tFailed to read classification.csv in zip file\n";
        exit;
    }else{
        echo "\tOpened in classification.csv in zip file\n";
    }

    $prev_lookup = array();

    $header = fgetcsv($in, escape: "\\");

    echo "\tRead header\n";

    $taxon_id_index = array_search('taxonID', $header);
    $family_index = array_search('family', $header);
    $rank_index = array_search('taxonRank', $header);
    $name_index = array_search('scientificName', $header);

    while($line = fgetcsv($in, escape: "\\")){
        $prev_lookup[$line[$taxon_id_index]] = $line[$family_index];
        echo number_format(count($prev_lookup), 0) . "\t" . $line[$name_index] . "\n";
    }

    fclose($in);
    
    echo "\nDone reading old file. \n\nStarting reading new file.\n";

    $in = fopen('zip://' . $current_path . '#classification.csv' , 'r');
   
    if(!$in){
        echo "\tFailed to read classification.csv in zip file\n";
        exit;
    }else{
        echo "\tOpened in classification.csv in zip file\n";
    }

    $header = fgetcsv($in, escape: "\\");

    echo "\tRead header\n";

    $taxon_id_index = array_search('taxonID', $header);
    $family_index = array_search('family', $header);
    $rank_index = array_search('taxonRank', $header);
    $name_index = array_search('scientificName', $header);

    $out = fopen($data_dir . 'moved_names.csv', 'w');
    array_unshift($header, 'old_family');
    fputcsv($out, $header, escape: "\\");

    $counter = 0;
    $moved_count = 0;
    while($line = fgetcsv($in, escape: "\\")){

        $wfo = $line[$taxon_id_index];
        $new_family = $line[$family_index];

        if(isset($prev_lookup[$wfo]) && $prev_lookup[$wfo] != $new_family){
            array_unshift($line,$prev_lookup[$wfo]);
            fputcsv($out, $line, escape: "\\");
            $moved_count++;
        }
        $counter++;
        echo number_format($counter, 0) . "\t" . number_format($moved_count, 0) . "\t" . $line[$name_index] . "\n";
    }

    fclose($in);
    fclose($out);
    
    echo "\n\nAll done!\n";

?>