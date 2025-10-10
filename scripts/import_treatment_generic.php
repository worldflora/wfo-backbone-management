<?php

/*

    Generic script for importing treatments

    Expects a CSV file with the first three columns
    - WFO ID
    - Label for new reference
    - uri of reference

    The first row is ignored

    Second param is the comment used when joining reference to name



*/

require_once('../config.php');
require_once('../include/WfoDbObject.php');
require_once('../include/Name.php');
require_once('../include/User.php');
require_once('../include/UpdateResponse.php');
require_once('../include/Reference.php');
require_once('../include/ReferenceUsage.php');

// we need to have a mock session  
$_SESSION['user'] = serialize(User::loadUserForDbId(1));

echo "\nImporting Treatments from CSV file.\n";

if(count($argv) != 3){
    echo "You need to supply two parameters to the script.\n";
    echo "\t1. the path to the CSV file.\n";
    echo "\t2. The comment for joining.\n";
    exit;
}

$file_path = $argv[1];
$comment = $argv[2];

if(!file_exists($file_path)){
    echo "Can't find file $file_path";
    exit;
}

$in = fopen($file_path, 'r');

fgetcsv($in); // drop header

while($line = fgetcsv($in, escape: "\\")){

    $wfo = $line[0];
    $label = $line[1];
    $uri = $line[2];


    $name = Name::getName($wfo);
    echo "\n$wfo\t" . strip_tags($name->getFullNameString()) . "\n";

    if(!preg_match('/^wfo-[0-9]{10}$/', $wfo) || !$label || !$uri ){
        echo "\nFailed row";
        print_r($line);
    }

    // we can make a record using the LnkHttpUri
    // do we have a reference for this uri?
    $ref = Reference::getReferenceByUri($uri);
    if(!$ref){
        // no ref so create it
        $ref = Reference::getReference(null);
        $ref->setKind('treatment');
        $ref->setLinkUri($uri);
        $ref->setDisplayText($label);  
        $ref->setUserId(1);
        $ref->save();
        echo "\tCreated:\t" . $ref->getId() . "\n";
    }else{
        echo "\tExists:\t" . $ref->getId() . "\n";
    }

    // is it already attached?
    $already_there = false; 
    foreach($name->getReferences() as $usage){
        if($usage->reference->getId() == $ref->getId()){
            $already_there = true;
            break;
        }
    }

    // do we need to attach it to the name?
    if(!$already_there){
        $name->addReference($ref, $comment, false);
        echo "\tAdded\n";
    }else{
        echo "\tAlready present\n";
    }

}

fclose($in);