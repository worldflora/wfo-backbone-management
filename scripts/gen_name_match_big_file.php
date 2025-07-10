<?php

// run periodically to generate a list of names for name matching against ** including extra files

require_once('../config.php');
require_once('../include/DownloadFile.php');

$downloads_dir = '../www/downloads/lookup/';
if (!file_exists($downloads_dir)) {
    mkdir($downloads_dir, 0777, true);
}

$out_path = $downloads_dir . '030_name_matching_big.csv';

/*
From Williams email of 11th May 2022


1. ScientificNameId/IpniId - It contains the IPNI/Tropicos ID
2. localId: - contains the Providers database taxon ID.
3. DoNotProcess - indicates if the taxon is excluded.
4. DoNotProcessReason - text with reason for exclusion.
5. VerbatimTaxonRank/InfraspecificRank - Contains the infraspecific rank.
6. deprecated - gives the information about the taxon excluded/deleted. It is similar to donotProcess but deprecated field indicates that taxon is deleted and that the WFO ID is not useful (dummy).
7. NameAccordingToId: Contains taxon citation, a reference ID.

*/


// header rows for the csv file
$header = array(
    "taxonID",
    "scientificNameID",
    "localId",
    "scientificName",
    "scientificNameAuthorship",
    "taxonRank",
    "verbatimTaxonRank",
    "nomenclaturalStatus",
    "taxonomicStatus",
    "doNotProcess",
    "doNotProcess_reason",
    "deprecated",
    "nameAccordingToID"

);

foreach($ranks_table as $rank_name => $rank){
    $header[] = $rank_name;
    if($rank_name == 'genus') break;
}

$header[] = 'majorGroup';

// destroy it if it exists
if(file_exists($out_path)) unlink($out_path);

// get the file
$out = fopen($out_path, 'w');

// write the headers
fputcsv($out, $header, escape: "\\");

// get the rows
$sql = "SELECT 
    n.id,
	i.`value` as taxonID,
    n.name_alpha as scientificName,
    n.authors as scientificNameAuthorship,
    n.`rank` as taxonRank,
    n.`status` as nomenclaturalStatus,
    if (	
		tn.taxon_id is not null, 
		if(t.id is not null, 'accepted'  , 'synonym'),
        'unplaced'
        ) as 'taxonomicStatus',
    t.id as taxon_id
	FROM `names` as n
	join identifiers as i on n.prescribed_id = i.id
    left join taxon_names as tn on tn.name_id = n.id
    left join taxa as t on t.taxon_name_id = tn.id
    ;
    ";

$response = $mysqli->query($sql);

// throw a wobbly if we get an error
if($mysqli->error){
    echo $mysqli->error;
    echo $sql;
    $response->close();
    exit;
}

// work through the dataset and write it to the csv file
$row_count = 1;
while($row = $response->fetch_assoc()){

    $out_row = $row;

    // convert the status to something they like
    switch ($row['nomenclaturalStatus']) {
        case 'invalid':
            $nomStatus = 'Invalid';
            break;
        case 'valid':
            $nomStatus = 'Valid';
            break;
        case 'illegitimate':
            $nomStatus = 'Illegitimate';
            break;
        case 'superfluous':
            $nomStatus = 'Superfluous';
            break;
        case 'conserved':
            $nomStatus = 'Conserved';
            break;
        case 'rejected': 
            $nomStatus = 'Rejected';
            break;
        default:
            $nomStatus = '';
            break;
    }
    $out_row['nomenclaturalStatus'] = $nomStatus;

    // do the stupid verbatimRank things
    $above_species = true;
    foreach($ranks_table as $rank_name => $rank){

        // we aren't interested in things above species
        if($above_species){
            if($rank_name == 'species') $above_species = false;
            continue;
        }

        // we are below species
        if($rank_name == $row['taxonRank']){
            $out_row['verbatimTaxonRank'] = $rank['abbreviation'];
        }
        

    }

    // add in all the other identifiers
    $ids = getIdentifiers($row['id']);
    $out_row['localId'] = @$ids['ten'];
    $out_row['tropicos']  = @$ids['tropicos'];

    // add in the ipni id from the references
    $refs = getReferences($row['id']);
    foreach($refs as $uri){
       
        if(preg_match('/^http:\/\/www.theplantlist.org\/tpl1.1\/record\/(.*)$/', $uri)){
            $out_row['tpl1.1'] = $uri;
        }
        if(preg_match('/^http:\/\/www.theplantlist.org\/tpl\/record\/(.*)$/', $uri)){
            $out_row['tpl1.0'] = $uri;
        }
         $matches = array();
        if(preg_match('/^https:\/\/www.ipni.org\/n\/(.*)$/', $uri, $matches)){
            $out_row['ipni'] = 'urn:lsid:ipni.org:names:' . $matches[1];
        }

    }
    
    // run through the ranks and add the values
    foreach($ranks_table as $rank_name => $rank){

        if(isset($ancestors[$rank_name])){
            $out_row[$rank_name] = $ancestors[$rank_name]['name'];
        }else{
            $out_row[$rank_name] = "";
        }
        if($rank_name == 'genus') break;

    }

    if(!$out_row['kingdom'] || !$out_row['family']){
        $hints = getHints($row['id']);
        if(!$out_row['phylum'] && in_array('Angiosperm', $hints)) $out_row['phylum'] = "Angiosperms";
        if(!$out_row['phylum'] && in_array('Bryophyte', $hints)) $out_row['phylum'] = "Bryophytes";
        if(!$out_row['phylum'] && in_array('Gymnosperm', $hints)) $out_row['phylum'] = "Gymnosperms";
        if(!$out_row['phylum'] && in_array('Pteridophyte', $hints)) $out_row['phylum'] = "Pteridophytes";

        if(!$out_row['family']){
            foreach ($hints as $hint) {
                if(preg_match('/aceae$/', $hint)){
                    $out_row['family'] = $hint;
                    break;
                }
            }
        }

    }

    // majorGroup 
    switch ($out_row['phylum']) {
        case 'Angiosperms':
            $out_row[] = 'A';
            break;
        case 'Bryophytes':
            $out_row[] = 'B';
            break;
        case 'Gymnosperms':
            $out_row[] = 'G';
            break;
        case 'Pteridophytes':
            $out_row[] = 'P';
            break;
        default:
            $out_row[] = '';
            break;
    }

    // get the cols from the botalista dump and use those in the short term.
    // only copy the things we need as don't want to overwrite our versions
    $botalista_row = getBotalistaRow($row['taxonID']);
    if($botalista_row){
        $out_row["doNotProcess"] = $botalista_row["doNotProcess"];
        $out_row["doNotProcess_reason"] = $botalista_row["doNotProcess_reason"];
        $out_row["deprecated"] = $botalista_row["deprecated"];
        $out_row["nameAccordingToID"] = $botalista_row["nameAccordingToID"];
    }


    // build the actual line so it agrees with the header
    $line = array();
    foreach ($header as $head) {
        

        if(isset($out_row[$head])){
            $line[] = $out_row[$head];
        }else{
            // not found exact match - blank entry
            if($head = 'scientificNameID'){
                if(isset($out_row['ipni'])){
                    $line[] = $out_row['ipni'];
                }elseif (isset($out_row['tropicos'])) {
                    $line[] = $out_row['tropicos'];
                }else{
                    $line[] = "";
                }
            }else{

                // nothing to add
                $line[] = "";
            }
            
        }


    }

    fputcsv($out, $line, escape: "\\");

    $row_count++;

    if($row_count % 1000 == 0) echo "\n" . number_format($row_count, 0) . "\t" . $out_row['taxonID'];

}

// because we held the query open be sure to close it
$response->close();

// close down the csv file.
fclose($out);

// gzip it (will remove original)
exec("gzip -f $out_path");

// we add a sidecar describing the file
$meta = array();
$meta['filename'] = $out_path . '.gz';
$now = new DateTime();
$meta['created'] = $now->format(DateTime::ATOM);
$meta['title'] = "Name Matching Lookup Table BIG - includes extra fields";
$meta['description'] = "A list of all the names in the database along with their WFO IDs, nomenclatural and taxonomic status plus extra fields. This is intended to be useful for people who want to run name matching or lookup systems locally and need extra info above what is offered by the regular name match file.";
$meta['size_bytes'] = filesize($out_path . '.gz');
$meta['size_human'] = DownloadFile::humanFileSize($meta['size_bytes']);
file_put_contents($out_path . '.gz.json', json_encode($meta, JSON_PRETTY_PRINT));

// job done.

function getBotalistaRow($taxon_id){

    global $mysqli;

    $result = $mysqli->query("SELECT * from botalista_dump_2 where taxonID = '$taxon_id'");
    if($result->num_rows) return $result->fetch_assoc();
    else return array();

}

function getHints($name_id){
    
    global $mysqli;

    $out = array();

    $result = $mysqli->query("SELECT hint from matching_hints where name_id = $name_id");
    
    while($row = $result->fetch_assoc()){
        $out[] = $row['hint'];
    }
    $result->close();

    return $out;

}

function getAncestors($taxon_id, &$ancestors){

    global $mysqli;

    if(!$taxon_id) return;

    $result = $mysqli->query("SELECT i.`value`, n.`name`, n.`rank`, t.parent_id 
        from taxa as t
        join taxon_names as tn on t.taxon_name_id = tn.id
        join `names` as n on tn.name_id = n.id
        join `identifiers` as i on n.prescribed_id = i.id 
        where t.id = $taxon_id");
    
    // should only be one!
    while($row = $result->fetch_assoc()){
        $ancestors[$row['rank']] = $row;

        if($row['parent_id'] == $taxon_id) return; // we are at the root.

        if($row['parent_id']) getAncestors($row['parent_id'], $ancestors);
    
    }
    $result->close();

}

function getReferences($name_id){

    global $mysqli;

    $out = array();

    $result = $mysqli->query("SELECT link_uri 
                from name_references as nr 
                join `references` as r on  nr.reference_id = r.id
                where r.kind = 'database'
                and nr.name_id = $name_id");
    while($row = $result->fetch_assoc()){
        $out[] = $row['link_uri'];
    }
    $result->close();

    return $out;

}


function getIdentifiers($name_id){

    global $mysqli;

    $out = array();

    $result = $mysqli->query("SELECT `kind`, `value` FROM `identifiers` WHERE `name_id` = $name_id");
    while($row = $result->fetch_assoc()){
        $out[$row['kind']] = $row['value'];
    }
    $result->close();

    return $out;

}

function human_filesize($bytes, $decimals = 2) {
  $sz = 'BKMGTP';
  $factor = floor((strlen($bytes) - 1) / 3);
  return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$sz[$factor];
}