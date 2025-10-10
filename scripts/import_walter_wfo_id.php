<?php

/*

    This script will bulk import names
    that were created with their own WFO IDs
    as created under Walter's dispensation.

    It assumes an import CSV file that has the first four columns as 
    WFO_ID
    rank
    name
    genus
    species
    So you need to have parsed out the name parts beforehand.

*/
require_once('../config.php');
require_once('../include/WfoDbObject.php');
require_once('../include/Name.php');
require_once('../include/User.php');
require_once('../include/UpdateResponse.php');

echo "\nWalter WFO ID Importer\n";

// we need to have a mock session  
$_SESSION['user'] = serialize(User::loadUserForDbId(1));

if(count($argv) != 2){
    echo "\nYou need to pass the path to the input file.\n";
    exit;
}

$input_path = $argv[1];

if(!file_exists($input_path)){
    echo "\nThat path does not exist.\n";
    exit;
}

$in = fopen($input_path, 'r');

$header = fgetcsv($in, escape: "\\");

while($line = fgetcsv($in, escape: "\\")){
    
    $wfo_id = $line[0];
    $rank = $line[1];
    $name_string = $line[2];
    $genus_string = $line[3];
    $species_string = $line[4];

    echo "$wfo_id\t";
    
    // first see if it exists
    $name = Name::getName($wfo_id);

    if($name->getId()){
        echo "exists\n";
        continue;
    }
    
    echo "new\t";

    if(!isset($ranks_table[$rank])){
        echo "UNRECOGNISED RANK: '$rank'\n";
        exit;
    }

    // add the name parts
    $name->setRank($rank);
    $name->setNameString($name_string);
    $name->setGenusString($genus_string);
    $name->setSpeciesString($species_string);
    $name->setStatus('unknown');

    $name->save();
    
    echo strip_tags($name->getFullNameString());
    
    echo "\n";

}