<?php

require_once('../config.php');

echo "\nRemoving escaped characters from references.\n";

    $sql = "SELECT *
    FROM `names` 
    WHERE REGEXP_LIKE(citation_micro, '&[^ ]+;')
    OR citation_micro LIKE '%<%';";

$response = $mysqli->query($sql);
$rows = $response->fetch_all(MYSQLI_ASSOC);

echo "\t ". count($rows) . " references selected.\n";

$count = 0;

foreach($rows as $row){
    $id = $row['id'];
    $old = $row['citation_micro'];
    // weird error 
    $new = str_replace('&Apos;', '&apos;', $old);
    $new = str_replace('&nbsp;', ' ', $new);
    $new = html_entity_decode($new, ENT_QUOTES, 'UTF-8');
    $new = strip_tags($new);
    $new_safe = $mysqli->real_escape_string($new);
    $mysqli->query("UPDATE `names` SET citation_micro = '$new_safe' WHERE id = $id ");
    echo "$count\t$id\n\t$old\n\t$new\n";
    if($mysqli->error){
        echo $mysqli->error;
        exit;
    }
    $count++;
}