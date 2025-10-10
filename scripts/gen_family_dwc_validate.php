<?php

    // this will validate the links in a darwin core file
    // and print the missing ones in some meaningful way.
    // expects a header row and doesn't parse the meta file.

    echo "\nCHECK LINKS IN DARWIN CORE FILES\n";

    if(count($argv) != 2){
        // didn't pass a path so check all files in ../www/downloads/dwc/
        echo "\nChecking Links in all files in www/downloads/dwc/";
        $zips = glob('../www/downloads/dwc/*.zip');
        foreach ($zips as $zip_path) {
           check_file($zip_path);
        }

    }else{
        $zip_path = $argv[1];
        echo "\nChecking Links in: $zip_path";
        check_file($zip_path);
    }

    function check_file($zip_path){

            if(!file_exists($zip_path)){
                echo "\nCan't find file.\n";
                exit;
            }

            echo "\nProcessing: $zip_path";

            $in = fopen('zip://' . $zip_path . '#classification.csv' , 'r');

            if(!$in){
                echo "\nFailed to read classification.csv in zip file\n";
                exit;
            }

            $header = fgetcsv($in, escape: "\\");

            $taxon_id_index = array_search('taxonID', $header);
            $parent_index = array_search('parentNameUsageID', $header);
            $basionym_index = array_search('originalNameUsageID', $header);
            $accepted_index = array_search('acceptedNameUsageID', $header);
            $rank_index = array_search('taxonRank', $header);
            $name_index = array_search('scientificName', $header);
            

            // load the taxon id's in memory
            $taxon_ids = array();
            while($line = fgetcsv($in, escape: "\\")){
                $taxon_ids[] = $line[$taxon_id_index];
            }

            // go back to the beginning of the file.
            fclose($in);
            $in = fopen('zip://' . $zip_path . '#classification.csv' , 'r');

            // discard the header again
            $header = fgetcsv($in, escape: "\\");

            // work through the lines for real and check
            // the values are in the taxon_ids
            while($line = fgetcsv($in, escape: "\\")){

                // parent
                if($line[$rank_index] != 'family'){
                    if($line[$parent_index] && !in_array($line[$parent_index], $taxon_ids)){
                        echo "\n\tParent missing:\t{$line[$taxon_id_index]}\t{$line[$parent_index]}\t{$line[$name_index]}"; 
                    }
                }

                // accepted
                if($line[$accepted_index] && !in_array($line[$accepted_index], $taxon_ids)){
                    echo "\n\tAccepted missing:\t{$line[$taxon_id_index]}\t{$line[$accepted_index]}\t{$line[$name_index]}"; 
                }

                // basionym
                if($line[$basionym_index] && !in_array($line[$basionym_index], $taxon_ids)){
                    echo "\n\tBasionym missing:\t{$line[$taxon_id_index]}\t{$line[$basionym_index]}\t{$line[$name_index]}"; 
                }

            }

    }

   


?>