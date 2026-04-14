<?php
require_once('../config.php');

/*

// run once script to seed the previous_placements table from the indexes of old classifications
// this one just downloads changes to csv file other script acutally imports

// this recreates some curl functions to call the index as I don't want Rhakhis code to know about
// the solr index in general - only for special, one off lookups like this

*/

$classification = '2025-12';

$solr_uri = 'http://localhost:8983/solr/wfo/query';
//$solr_uri = 'https://list.worldfloraonline.org/solr/wfo/query';
$out_file = "../data/previous_classification_{$classification}.csv";

// need to be able to restart if it fails 
// without wiping out old stuff
if(file_exists($out_file)){
    $line = exec("wc -l $out_file");
    $matches = array();
    preg_match('/ ([0-9]+) /', $line, $matches);
    $offset = (int)$matches[1];
    $offset--; // take the header line away
}else{
    $offset = 0;
}

// file to put the results in
$out = fopen($out_file, 'a');
if($offset == 0) fputcsv($out, array('wfo_id', 'classification', 'role', 'placement_id'), escape: "\\");

$page_size = 10000;
$last_written = null;
while(true){

        echo "\nStarting at ". number_format($offset, 0) ." ...";

        $query = (object)array(
            'query' => 'classification_id_s:[* TO *]',
            'fields' => array('wfo_id_s', 'classification_id_s', 'role_s', 'parent_id_s', 'accepted_id_s'),
            'filter' => array("classification_id_s:" . $classification),
            'offset' => $offset,
            'limit' => $page_size
        );

        $back = curlPostJson($solr_uri, json_encode($query));

        if($back->info['http_code'] == 502){
            $delay = rand(10, 60);
            echo "got a 502 - waiting $delay seconds to retry\n";
            sleep($delay);
            continue;
        }

        $body = json_decode($back->body);

        if(isset($body->response->docs) && $body->response->docs){

            foreach ($body->response->docs as $doc) {

                $placement_id = null;
                $placement_id = isset($doc->parent_id_s) ? $doc->parent_id_s : null;
                $placement_id = !$placement_id && isset($doc->accepted_id_s) ? $doc->accepted_id_s : $placement_id;
                if ($placement_id) $placement_id = substr($placement_id, 0, 14);

                $new_row = array( 
                        $doc->wfo_id_s, //'wfo_id'
                        $doc->classification_id_s, // 'classification' 
                        $doc->role_s, // 'role'
                        $placement_id // 'placement_id'
                    );

                    fputcsv($out, $new_row, escape: "\\");
                    $last_written = $new_row;

            }

        }else{
            print_r($back);
            echo "all done.\n\n";
            break;
        }

        sleep(1);

        echo " next\n";

        $offset = $offset + $page_size;

}

fclose($out);

function getCurlHandle($uri){
    global $argv;
    $ch = curl_init($uri);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Roger scraping himself slowly!');
    curl_setopt($ch, CURLOPT_HEADER, 1);
    curl_setopt($ch, CURLOPT_USERPWD, $argv[1]);
    return $ch;
}

function runCurlRequest($curl){

    $out['response'] = curl_exec($curl);  
    $out['error'] = curl_errno($curl);
    
    if(!$out['error']){
        // no error
        $out['info'] = curl_getinfo($curl);
        $out['headers'] = getHeadersFromCurlResponse($out);
        $out['body'] = trim(substr($out['response'], $out['info']["header_size"]));

    }else{
        // we are in error
        $out['error_message'] = curl_error($curl);
    }
    
    // we close it down after it has been run
    curl_close($curl);
    
    return (object)$out;
    
}

function curlPostJson($uri, $json){
    $ch = getCurlHandle($uri);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json')); 
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    $response = runCurlRequest($ch);
    return $response;
}

function getHeadersFromCurlResponse($out){
    
    $headers = array();
    
    // may be multiple header blocks - we want the last
    $headers_block = substr($out['response'], 0, $out['info']["header_size"]-1);
    $blocks = explode("\r\n\r\n", $headers_block);
    $header_text = trim($blocks[count($blocks) -1]);

    foreach (explode("\r\n", $header_text) as $i => $line){
        if ($i === 0){
            $headers['http_code'] = $line;
        }else{
            list ($key, $value) = explode(': ', $line);
            $headers[$key] = $value;
        }
    }

    return $headers;
}