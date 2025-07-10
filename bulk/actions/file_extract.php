<?php

set_time_limit(60 * 10); // give it ten minutes..

$file_in = $_GET['file_in'];
$zip_path = "../bulk/csv/$file_in";

$file_out_path = "../bulk/csv/" . $_GET['file_new_name'];

// does the file contain an meta.xml
$zip = new ZipArchive;
$zip->open(realpath($zip_path));


if($zip->locateName('meta.xml') !== false){
    extractDarwinCore($zip, $file_out_path);
}else{
    extractPlainCsv($zip, "../bulk/csv/", $_GET['file_new_name']);
}

header('Location: index.php?action=view&phase=csv');

function extractDarwinCore($zip, $new_file_name){


    $in = $zip->getStream('meta.xml');
    $xml_string = stream_get_contents($in);
    fclose($in);
    
    $xml = new SimpleXMLElement($xml_string);
    
    if(strtoupper($xml->core->attributes()['encoding']) != 'UTF-8'){
        echo "Encoding is not UTF-8 it is " . $xml->core->attributes()['encoding'] . ". Go back and get something in the right encoding";
        exit();
    }

    if($xml->core->attributes()['rowType'] != 'http://rs.tdwg.org/dwc/terms/Taxon'){
        echo "The core table is not a tdwg:Taxon it is " . $xml->core->attributes()['rowType'] . ". We only import taxon DwC Archive files";
        exit();
    }

    $taxon_file_name = $xml->core->files->location;
    $lineTerminator = (string)$xml->core->attributes()['linesTerminatedBy'];
    $fieldTerminator = (string)$xml->core->attributes()['fieldsTerminatedBy'];
    $fieldTerminator = str_replace("\\t", "\t", $fieldTerminator);
    $fieldEnclosedBy = (string)$xml->core->attributes()['fieldsEnclosedBy'];
    $fieldEnclosedBy = str_replace('"', "\"", $fieldTerminator); // we don't bother using this as PHP seems to do the right thing when it is supplied erratically.
    $hasHeaders = $xml->core->attributes()['ignoreHeaderLines'];


    // open up the taxon file
    $in = $zip->getStream($taxon_file_name);
   

    if($hasHeaders){
        // discard the first row if we have headers
        fgetcsv($in, null, $fieldTerminator);
    }

    $out = fopen( $new_file_name, 'w');

    // write the header out
    foreach ($xml->core->field as $field) {
        $info = pathinfo((string)$field->attributes()['term']);
        $header[(int)$field->attributes()['index']] = $info['filename'];
//        print_r($field->attributes());
    }
    fputcsv($out, $header, ",", '"', escape: "\\");


    // dump it out
    while($line = fgetcsv($in, null, $fieldTerminator)){

        $out_line = array();

        // we need to build the columns based on the header fields
        // as these may contain default values
        for ($i=0; $i < count($xml->core->field); $i++) { 
            
            $field = $xml->core->field[$i];
            
            if($line[$i]){
                // had val to write in
                $val = $line[$i];
            }else{
                // no val
                // does it have a default?
                if($field->attributes()['default']){
                    $val = $line[$i];
                }else{
                    $val = null;
                }
            }

            $out_line[] = $val;
        }

        fputcsv($out, $out_line, ",", '"', escape: "\\");
    }
    fclose($out);

}

function extractPlainCsv($zip, $file_out_dir, $file_name){

    for ($i = 0; $i < $zip->numFiles; $i++) {

        $compressed_file = $zip->getNameIndex($i);

        if(preg_match('/\.csv$/', $compressed_file)){
            // is this the csv file?
            $zip->renameName($compressed_file,$file_name);
            $zip->extractTo($file_out_dir);
            break;
        }
        
    }

}


    
