<?php

require_once('../config.php');
require_once('../include/WfoDbObject.php');
require_once('../include/Name.php');
require_once('../include/User.php');
require_once('../include/UpdateResponse.php');

// we need to have a mock session  
$_SESSION['user'] = serialize(User::loadUserForDbId(1));

// get a list of names with multiple ipni IDs but no preferred one.
$sql = "SELECT n.id, count(*) as c
from `names` as n
join identifiers as i on i.name_id = n.id and n.preferred_ipni_id is null and i.kind = 'ipni'
group by n.id having c > 1 and c < 10
order by c desc";

$response = $mysqli->query($sql);
$rows = $response->fetch_all(MYSQLI_ASSOC);
$response->close();

$out = fopen('../data/unpreferred_ipni_ids.csv', 'w');

fputcsv($out, array('wfo_id', 'name', 'ipni_id', 'suppressed', 'top_copy'), escape: "\\");

foreach($rows as $row){

    $name_id = $row['id'];

    // get all the ipni IDs for this name
    $response = $mysqli->query("SELECT i.id as identifier_id, i.name_id, i.`value` as ipni_id, suppressed_b, top_copy_b FROM `identifiers` as i JOIN kew.ipni as k ON i.`value` = k.id WHERE i.kind = 'ipni' and name_id = $name_id;");
    $dupes = $response->fetch_all(MYSQLI_ASSOC);
    $response->close();

    $candidates = array();
    foreach($dupes as $dupe){
        if($dupe['suppressed_b'] == 't') continue; // never have a suppressed record
        if($dupe['top_copy_b'] == 'f') continue; // never have a bottom copy record
        $candidates[] = $dupe;
    }

    $name = Name::getName($row['id']);

    echo strip_tags($name->getPrescribedWfoId());
    echo "\t";
    echo strip_tags($name->getFullNameString());

    if(count($candidates) == 1){
        // we have a preferred one
        // fixme - update the name
        echo "\t" . $candidates[0]['ipni_id'];
        if($name->setPreferredIpniId($candidates[0]['ipni_id'])){
            echo "\tSUCCESS";
            $name->save();
        }else{
            echo "\tFAILED - IN USE ELSEWHERE?";
        }
        
    }else{
        foreach($dupes as $dupe){
            fputcsv($out, array(
                $name->getPrescribedWfoId(), 
                strip_tags($name->getFullNameString()),
                $dupe['ipni_id'],
                $dupe['suppressed_b'] == 't' ? 'suppressed' : 'not suppressed',
                $dupe['top_copy_b'] == 't' ? 'top copy' : 'bottom copy'
            ), escape: "\\");
        }
        
        echo "\t no match";
    }

    echo "\n";

}
fclose($out);