<?php
require_once('../config.php');
require_once('../include/WfoDbObject.php');
require_once('../include/Name.php');
require_once('../include/User.php');
require_once('../include/UpdateResponse.php');
require_once('../include/Reference.php');
require_once('../include/ReferenceUsage.php');

/*
    Used to import the data on women who genera are named after

    FIXME - add in the two doubles after import

*/

// we need to have a mock session  
$_SESSION['user'] = serialize(User::loadUserForDbId(1));

echo "\nIMPORTING WOMEN EPONYMS\n";

$in = fopen('../data/sources/WomenPlantGenera_v1-3.csv', 'r');

$header = fgetcsv($in, escape: "\\");
print_r($header);

while($line = fgetcsv($in, escape: "\\")){

    $wfo = $line[6];
    $woman_name = $line[29];
    $woman_q = $line[39];
    $woman_uri = 'http://www.wikidata.org/entity/' . $woman_q;
    $woman_query = "https://www.wikidata.org/w/api.php?action=wbgetentities&format=json&ids=" . $woman_q;
    
    if($line[34] != 'unknown'){
        $occupation = " - {$line[34]}.";
    }else{
        $occupation = ".";
    }
    
    $note = "Named for {$woman_name}{$occupation} [Source 10.3897/BDJ.11.e114408]";
    echo "$wfo\n\t$note\n";

 
    // get the name
    $name = Name::getName($wfo);

    // wikidata
    $json = json_decode(file_get_contents($woman_query));
    $claims = $json->entities->{$woman_q}->claims;

    echo "\t$woman_q\n";

    // do we have an image?
    if(isset($claims->P18)){
        $file_name = $claims->P18[0]->mainsnak->datavalue->value;
        $thumbnail_uri = "https://commons.wikimedia.org/wiki/Special:FilePath/" . $file_name;
        echo "\t$thumbnail_uri\n";
    }else{
        $thumbnail_uri = false;
        echo "\tNo thumbnail.\n";
    }

    // do we have dob
    if(isset($claims->P569[0]->mainsnak->datavalue)){
        $birth = $claims->P569[0]->mainsnak->datavalue->value->time;
        $birth = new DateTime($birth);
        $birth = $birth->format('Y');
    }else{
        $birth = false;
    }

    // do we have dod
    if(isset($claims->P570[0]->mainsnak->datavalue)){
        $death = $claims->P570[0]->mainsnak->datavalue->value->time;
        $death = new DateTime($death);
        $death = $death->format('Y');
    }else{
        $death = false;
    }

    // lifespan
    if($birth && $death){
        $span = " ({$birth}-{$death}) ";
    }elseif($birth){
        $span = " (b.{$birth}) ";
    }elseif($death){
        $span = " (d.{$death}) ";
    }else{
        $span = '';
    }

    $woman_label = "{$woman_name}{$span}";
    echo "\t$woman_label\n";

    $ref = Reference::getReferenceByUri($woman_uri);
    if(!$ref){
        $ref = Reference::getReference(null);
        $ref->setLinkUri($woman_uri);
    }

    $ref->setDisplayText($woman_label);
    if($thumbnail_uri) $ref->setThumbnailUri($thumbnail_uri);
    $ref->setKind('person');
    $ref->setUserId(1);
    $ref->save();

    if(!$ref->getId()){
        print_r($ref);
        exit;
    }

    // check we haven't already got it
    $usages = $name->getReferences();
    foreach($usages as $usage){
        if($usage->reference->getId() == $ref->getId()){
            echo "\t Already got ref.\n";
            continue 2;
        }
    }

    // add the reference to the name
    $name->addReference($ref, $note, false);


}

fclose($in);