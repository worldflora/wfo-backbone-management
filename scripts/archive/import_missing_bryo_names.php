<?php


/*
    One off script to import some bryophyte names.

*/

require_once('../config.php');
require_once('../include/WfoDbObject.php');
require_once('../include/Name.php');
require_once('../include/User.php');
require_once('../include/UpdateResponse.php');

$_SESSION['user'] = serialize(User::loadUserForDbId(4));

$in = fopen('../data/Bryophytes_NOIDS_Roger.csv', 'r');
$out = fopen('../data/Bryophytes_NOIDS_Roger_OUT.csv', 'w');

// throw out header
$header = fgetcsv($in);
$header[0] = str_replace("\xEF\xBB\xBF",'', $header[0]);
array_unshift($header, "\xEF\xBB\xBF" . 'wfo_id');
fputcsv($out, $header, escape: "\\");


while($row = fgetcsv($in)){

    $name = Name::getName(-1);
    $name->setStatus('unknown');

    $name->setRank('species');
    $name->setGenusString($row[4]);
    $name->setNameString($row[5]);
    $name->setAuthorsString($row[2]);
    $name->setCitationMicro($row[6]);
    $name->setYear($row[7]);
    $name->save();

    array_unshift($row, $name->getPrescribedWfoId());
    fputcsv($out, $row, escape: "\\");
    print_r($row);

}


fclose($in);
fclose($out);
exit;
