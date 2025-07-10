<?php

require_once('../config.php');
require_once('../include/WfoDbObject.php');
require_once('../include/Name.php');
require_once('../include/User.php');
require_once('../include/UpdateResponse.php');
require_once('../include/Reference.php');
require_once('../include/ReferenceUsage.php');
require_once('../include/NameMatcher.php');
require_once('../include/NameMatcherPlantList.php');
require_once('../include/NameMatches.php');

echo "\nK Specimen importer.\n";

// we need to have a mock session  
$_SESSION['user'] = serialize(User::loadUserForDbId(1));

if(count($argv) > 1){
    $offset = $argv[1];
}else{
    $offset = 0;
}

$in = fopen('../data/sources/TypesForRBGE.csv', 'r');
$header = fgetcsv($in);

$not_found_out = fopen('../data/sources/TypesForRBGE_not_found.csv', $offset > 0 ? 'a' : 'w');
if($offset == 0) fputcsv($not_found_out, $header); // same as we got 

$found_out = fopen('../data/sources/TypesForRBGE_found.csv', $offset > 0 ? 'a' : 'w');
if($offset == 0){
    array_unshift($header, 'wfo_id');
    fputcsv($found_out, $header, escape: "\\"); // we'll prepend the wfo ID to the found file.
}


$matcher = new NameMatcherPlantList();

$counter = 0;
while($line = fgetcsv($in)){

    if($counter < $offset){
        $counter++;
        continue;
    }

    // "Barcode","Thumbnail","IpniId","FullName","VerbatimTaxonomicName","TypeofType","LabelString","GBIFSpecimen"
    $display = "Kew Gardens {$line[0]}:  {$line[6]}";
    $comment = "Link to {$line[5]} provided by Kew";
    $thumb_uri = $line[1];
    $link_uri = $line[7];
    $ipni_id = $line[2];
    $full_name = $line[3];
    if(!$full_name) $full_name = $line[4];

    // now we have all but the WFO ID!
    echo "$counter\t$ipni_id\t$full_name\n";


    // try and match the name.
    $matches = $matcher->stringMatch($full_name);
    if(count($matches->names) != 1){
       
       
        echo "\t" . count($matches->names) . " matches found.\n";
        echo "\tChecking IPNI ID $ipni_id\n";
        // see if we can get it by IPNI ID
        if($ipni_id){
            $ipni_lsid = 'urn:lsid:ipni.org:names:' . $ipni_id;
            $response = $mysqli->query("SELECT distinct(name_id) FROM `identifiers` WHERE kind = 'ipni' AND `value` = '$ipni_lsid'");
            $rows = $response->fetch_all(MYSQLI_ASSOC);
            if(count($rows) == 1){
                $name = Name::getName($rows[0]['name_id']);
                echo "\tGot name by IPNI ID.\n";
            }else{
                echo "\tNo IPNI ID found - skipping.\n";
                fputcsv($not_found_out,$line, escape: "\\");
                continue; // next row from csv please
            }

        }

    }else{
        $name = $matches->names[0];
    }// not one name

    $wfo_id = $name->getPrescribedWfoId();

    echo "+\t$wfo_id\n";

    // keep a record of our find
    array_unshift($line, $wfo_id);
    fputcsv($found_out, $line, escape: "\\");

    // OK we are good to go and create the reference.
     // do we have a reference for this uri?
    $ref = Reference::getReferenceByUri($link_uri);

    if(!$ref){

         // no ref so create it
        $ref = Reference::getReference(null);
        $ref->setKind('specimen');
        $ref->setLinkUri($link_uri);
        if($thumb_uri) $ref->setThumbnailUri($thumb_uri);
        $ref->setDisplayText($display);
        $ref->setUserId(1);
        $update_response = $ref->save();
        if(!$update_response->success){
            print_r($update_response);
            exit;
        }
        echo "\tCreated ref:\t" . $ref->getId() . "\n";
    }else{
        echo "\tExists ref:\t" . $ref->getId() . "\n";
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

    $counter++;



}
echo $counter;

fclose($not_found_out);
fclose($found_out);
fclose($in);