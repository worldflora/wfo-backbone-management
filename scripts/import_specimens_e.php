<?php

require_once('../config.php');
require_once('../include/WfoDbObject.php');
require_once('../include/Name.php');
require_once('../include/User.php');
require_once('../include/UpdateResponse.php');
require_once('../include/Reference.php');
require_once('../include/ReferenceUsage.php');

echo "\nE Specimen importer.\n";

// we need to have a mock session  
$_SESSION['user'] = serialize(User::loadUserForDbId(1));

// open Robyn's list to work through it

$in = fopen('../data/sources/Robyns_type_list_matched.csv', 'r');
$out = fopen('../data/sources/Robyns_returns.csv', 'w');

$header = fgetcsv($in);
$header = array_unshift($header, 'issue');

$counter = 0;
while($line = fgetcsv($in)){

    $wfo = $line['0'];
    if(!preg_match('/^wfo-[0-9]{10}$/', $wfo)){
        array_unshift($line, 'no match');
        fputcsv($out, $line, escape: "\\");
        continue;
    }
    $barcode = trim($line['3']);
    if(!preg_match('/^E[0-9]{8}$/', $barcode)){
        array_unshift($line, 'no barcode');
        fputcsv($out, $line, escape: "\\");
        continue;
    } 

    $uri = 'https://data.rbge.org.uk/herb/' . $barcode;

    // we know the pattern so we use that rather than getting the RDF and manifest
    $thumb = "https://iiif.rbge.org.uk/herb/iiif/$barcode/full/219,/0/default.jpg";

    // but need to check that it exits
    $ch = curl_init($thumb);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_exec($ch);
    $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Check the response code
    if($responseCode != 200){
        array_unshift($line, 'no image');
        fputcsv($out, $line, escape: "\\");
        $thumb = null;
    }else{
        // add a message to the line 
        // just so they are all the same
        array_unshift($line, 'OK');
    }

    // OK we are good to go and create the reference.
     // do we have a reference for this uri?
    $ref = Reference::getReferenceByUri($uri);
    
    echo "\n$wfo\t$barcode";

    if(!$ref){

        $display = "Type specimen at Edinburgh (E). {$line['8']}: {$line['9']}. Barcode: $barcode.";

        // no ref so create it
        $ref = Reference::getReference(null);
        $ref->setKind('specimen');
        $ref->setLinkUri($uri);
        if($thumb) $ref->setThumbnailUri($thumb);
        $ref->setDisplayText($display); // truncate at 1000  
        $ref->setUserId(1);
        $update_response = $ref->save();
        if(!$update_response->success){
            print_r($update_response);
            exit;
        }
        echo "\n\tCreated:\t" . $ref->getId();
    }else{
        echo "\n\tExists:\t" . $ref->getId();
    }

    // we must have a reference now - fresh or old.
    // get the name it is joined to
    $name = Name::getName($wfo);
    echo "\n\t" . $name->getPrescribedWfoId();

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
        $name->addReference($ref, "Specimen link provided by Royal Botanic Garden Edinburgh.", false);
        echo "\n\tAdded";
    }else{
        echo "\n\tAlready present";
    }

    $counter++;

}
echo $counter;

fclose($out);
fclose($in);