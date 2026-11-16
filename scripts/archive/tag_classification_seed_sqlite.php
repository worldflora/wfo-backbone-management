<?php
require_once('../config.php');

/*

Run once to import from SQLite db of previous classifications

*/

$mysqli->query("TRUNCATE `previous_placements`;");

$db = new SQLite3('../data/previous_classifications.db',    );

$offset = 0;
$page = 100000;
$counter = 0;
$counter_written = 0;
$last_written = null;
while(true){

    echo "\nStarting " . number_format($offset, 0) . " ...";

    $result = $db->query("SELECT * FROM previous_classifications order by wfo_id, classification limit $page offset $offset;");

    while($row = $result->fetchArray(SQLITE3_ASSOC)){
        $counter++;
        if(
            !$last_written
            ||
            $last_written['wfo_id'] != $row['wfo_id']
            ||
            $last_written['role'] != $row['role']
            ||
            $last_written['placement_id'] != $row['placement_id']
        ){
            // inefficient but simple for a run once script

            // get the name_id for the wfo id
            $response = $mysqli->query("SELECT name_id FROM identifiers WHERE `value` = '{$row['wfo_id']}' AND `kind` = 'wfo';");
            $r = $response->fetch_assoc();
            $name_id = $r['name_id'];

            if($row['placement_id']){
                $response = $mysqli->query("SELECT name_id FROM identifiers WHERE `value` = '{$row['placement_id']}' AND `kind` = 'wfo';");
                $r = $response->fetch_assoc();
                $placed_in = $r['name_id'];
            }else{
                $placed_in = 'NULL';
            }

            $sql = "INSERT INTO previous_placements (`name_id`, `classification`, `role`, `placed_in`) VALUES ($name_id, '{$row['classification']}', '{$row['role']}', $placed_in );";
            try{
                $mysqli->query($sql);
            }catch(Exception $e){
                print_r($e);
                print_r($row);
                echo $sql;
            }
            
            $last_written = $row;
            $counter_written++;
        }
    }

    if($counter == 0){
        echo " ALL DONE \n";
        break;
    }else{
        echo " {$counter_written} - next\n";
        $offset = $offset + $page;
        $counter = 0;
        $counter_written = 0;
    }


}

