<?php

if(isset($_GET['sql_select'])){
    arbitrary_query();
}else{
    common_delta();
}

header("Location: index.php?action=view&phase=csv");

function arbitrary_query(){
    
    global $mysqli;

    $sql = "SELECT * FROM `kew`.`ipni` WHERE {$_GET['sql_select']} ";
    $sql .= " LIMIT 100000";

    $response = $mysqli->query($sql, MYSQLI_USE_RESULT);
    if($mysqli->error){
        echo "<hr/>";
        print_r($_GET);
        echo "<hr/>";
        echo $sql;
        echo "<hr/>";
        echo $mysqli->error;
        echo "<hr/>";
        exit;
    }

    write_results($response);
    
}

function common_delta(){

    global $mysqli;

    $sql = "SELECT * FROM `kew`.`ipni` WHERE ";

    $conditions = array();

    if(@$_GET['created_after']) $conditions[] = "`date_created_date` >= '{$_GET['created_after']}'";
    if(@$_GET['created_before']) $conditions[] = "`date_created_date` <= '{$_GET['created_before']}'";

    if(@$_GET['modified_after']) $conditions[] = "`date_last_modified_date` >= '{$_GET['modified_after']}'";
    if(@$_GET['modified_before']) $conditions[] = "`date_last_modified_date` <= '{$_GET['modified_before']}'";

    if(@$_GET['publication_year']) $conditions[] = "`publication_year_i` = '{$_GET['publication_year']}'";

    if(@$_GET['family']) $conditions[] = "`family_s_lower` = '{$_GET['family']}'";

    if(@$_GET['top_copy']) $conditions[] = "`top_copy_b` = '{$_GET['top_copy']}'";
    if(@$_GET['suppressed']) $conditions[] = "`suppressed_b` = '{$_GET['suppressed']}'";

    $sql .= " " . implode(' AND ', $conditions);
    $sql .= " LIMIT 100000";
/*
    echo "<pre>";
    print_r($_GET);
    echo "</pre>";
    echo $sql;
    exit;
*/
    $response = $mysqli->query($sql, MYSQLI_USE_RESULT);
    if($mysqli->error){
        echo "<hr/>";
        print_r($_GET);
        echo "<hr/>";
        echo $sql;
        echo "<hr/>";
        echo $mysqli->error;
        echo "<hr/>";
        exit;
    }

    write_results($response);

}

function write_results($response){

    // check out the file stuff
    $file_name = @$_GET['file_name'];
    if(!$file_name){
        echo "You must provided a file name";
        exit;
    }

    // must have a .csv ending
    if(!preg_match('/\.csv$/', $file_name)) $file_name .= ".csv";
    
    $file_path = "../bulk/csv/$file_name";

    $out = fopen($file_path, 'w');
    
    // write the header
    $fields = $response->fetch_fields();
    $header = array();
    foreach($fields as $field){
        $header[] = $field->name;
    }
    fputcsv($out, $header, escape: "\\");

    // write all the rows
    while($row = $response->fetch_assoc()){
        fputcsv($out, $row, escape: "\\");
    }

    fclose($out);


}
