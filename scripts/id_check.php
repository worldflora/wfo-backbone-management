<?php
require_once('../config.php');

$sql = "SELECT distinct(parent_id) as parent_id from taxa";
$result = $mysqli->query($sql);
$rows = $result->fetch_all(MYSQLI_ASSOC);

$out = fopen("id_check.csv", 'w');

foreach($rows as $row){

    if(!$row['parent_id']) continue;

    $parent_id = $row['parent_id'];

    $sql = "SELECT 
        i.`value`,
        t.*
        FROM taxa AS t 
        JOIN `taxon_names` AS tn ON tn.taxon_id = t.id
        JOIN `names` AS n ON tn.name_id = n.id
        JOIN identifiers AS i ON n.prescribed_id = i.id
        WHERE parent_id = $parent_id
        AND t.id NOT IN (
            # get the children with 
            SELECT taxon_id FROM taxa as t 
            JOIN `taxon_names` as tn on t.`taxon_name_id` = tn.id 
            WHERE `parent_id` = $parent_id 
    )";

    $result = $mysqli->query($sql);

    if($result->num_rows){
        while($issue = $result->fetch_assoc()){
            print_r($issue);
            fputcsv($out, $issue, escape: "\\");
        }
    }else{
        echo "$parent_id\n";
    }

}

fclose($out);