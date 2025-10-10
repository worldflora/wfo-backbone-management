<?php

set_time_limit(0);

// safety check the table name
$table_name = trim($_GET['table_name']);
$table_name = preg_replace('/[^a-zA-Z0-9_]/', '_', $table_name);
$table_name = "`rhakhis_bulk`.`$table_name`"; // qualify it with the db so we don't do anything to rhakhis!!

// drop the table if it already exists
$mysqli->query("DROP TABLE IF EXISTS $table_name");

// open the file for input
$in = fopen('../bulk/csv/' . $_GET['file_in'] , 'r');

// read the header in and clean it up
$header_row = sanitize_header(fgetcsv($in, escape: "\\"));


// create the table with all text fields
$sql = "CREATE TABLE $table_name (";

$first = true;
foreach($header_row as $col_name){
    
    if($first) $first = false;
    else $sql .= ',';

    // if any of these column names are recognised as ours
    // we set their type appropriately if not they get TEXT NULL
    switch ($col_name) {
        case 'rhakhis_pk':
            $sql .= "\n\t`rhakhis_pk` int NOT NULL AUTO_INCREMENT";
            break;
        case 'rhakhis_wfo':
            $sql .= "\n\t`rhakhis_wfo` VARCHAR(15) NULL";
            break;
        case 'rhakhis_skip':
            $sql .= "\n\t`rhakhis_skip` TINYINT NULL";
            break;
        case 'rhakhis_rank':
            $sql .= "\n\t`rhakhis_rank` VARCHAR(15) NULL";
            break;
        case 'rhakhis_status':
            $sql .= "\n\t`rhakhis_status` VARCHAR(15) NULL";
            break;
        case 'rhakhis_parent':
            $sql .= "\n\t`rhakhis_parent` VARCHAR(15) NULL";
            break;
        case 'rhakhis_accepted':
            $sql .= "\n\t`rhakhis_accepted` VARCHAR(15) NULL";
            break;
        case 'rhakhis_basionym':
            $sql .= "\n\t`rhakhis_basionym` VARCHAR(15) NULL";
            break;
        default:
            // All other fields are TEXT
            $sql .= "\n\t`$col_name` TEXT NULL";
            break;
    }
    
}
// add the rhakhis_* fields if they don't already exist
if(!in_array('rhakhis_pk', $header_row)) $sql .= ",\n\t`rhakhis_pk` int NOT NULL AUTO_INCREMENT";
if(!in_array('rhakhis_wfo', $header_row)) $sql .= ",\n\t`rhakhis_wfo` VARCHAR(15) NULL";
if(!in_array('rhakhis_skip', $header_row)) $sql .= ",\n\t`rhakhis_skip` TINYINT NULL";
if(!in_array('rhakhis_rank', $header_row)) $sql .= ",\n\t`rhakhis_rank` VARCHAR(15) NULL";
if(!in_array('rhakhis_status', $header_row)) $sql .= ",\n\t`rhakhis_status` VARCHAR(15) NULL";
if(!in_array('rhakhis_parent', $header_row)) $sql .= ",\n\t`rhakhis_parent` VARCHAR(15) NULL";
if(!in_array('rhakhis_accepted', $header_row)) $sql .= ",\n\t`rhakhis_accepted` VARCHAR(15) NULL";
if(!in_array('rhakhis_basionym', $header_row)) $sql .= ",\n\t`rhakhis_basionym` VARCHAR(15) NULL";

$sql .= ",\n\tPRIMARY KEY (`rhakhis_pk`)";

// we have indexes on the other rhakhis fields
$sql .= ",\n\tINDEX `wfo` USING BTREE (`rhakhis_wfo`)";
$sql .= ",\n\tINDEX `parent` USING BTREE (`rhakhis_parent`)";
$sql .= ",\n\tINDEX `accepted` USING BTREE (`rhakhis_accepted`)";
$sql .= ",\n\tINDEX `basionym` USING BTREE (`rhakhis_basionym`)";

$sql .= "\n)";

$sql .= "\n DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci";

// echo $sql;

$mysqli->query($sql);
if($mysqli->error){
    echo $mysqli->error;
    echo '<hr/>';
    echo $sql;
    exit;
}

// read the rest of the lines into the table with insert statements.
while($row = fgetcsv($in, escape: "\\")){

    $sql = "INSERT INTO $table_name (";

    $first = true;
    foreach($header_row as $col_name){
      
        if($first) $first = false;
        else $sql .= ',';
        
        $sql .= "\n\t`$col_name`";
    }

    $sql .= "\n) VALUES (";

    $first = true;
    foreach($row as $val){

        if($first) $first = false;
        else $sql .= ',';

        if(is_numeric($val)){
            $sql .= "\n\t$val";
        }elseif(strlen($val) == 0) {
            $sql .= "\n\tNULL";
        }else{
            $safe_val = $mysqli->real_escape_string($val);
            $sql .= "\n\t'$safe_val'";
        }
        
    }

    $sql .= ")";

    $mysqli->query($sql);
    if($mysqli->error){
        echo $mysqli->error;
        exit;
    }

}

// redirect back to tables page
header('Location: index.php?action=view&phase=tables');

function sanitize_header($row){
    $header_row = array();
    foreach ($row as $col_name) {
        


        // remove non alphanumeric characters because of the MS bang 
        $col_name = remove_utf8_bom($col_name);
        $col_name = preg_replace('/[^a-zA-Z0-9] /', '', strtolower(trim($col_name)));

        // replace spaces with _
        $col_name = str_replace(" ", "_", $col_name);

        $header_row[] = $col_name;
    }
    return $header_row;
}

function remove_utf8_bom($text){
    $bom = pack('H*','EFBBBF');
    $text = preg_replace("/^$bom/", '', $text);
    return $text;
}