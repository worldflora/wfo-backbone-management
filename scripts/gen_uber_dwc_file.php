<?php

require_once("../config.php");
require_once("../include/WfoDbObject.php");
require_once("../include/Name.php");
require_once("../include/Taxon.php");
require_once("../include/Reference.php");
require_once("../include/ReferenceUsage.php");
require_once("../include/UnplacedFinder.php");
require_once("../include/Identifier.php");
require_once("../include/User.php");

// let's just work through the names table

echo "\nStarting Uber File\n";

$downloads_dir = '../www/downloads/dwc/';
$out_file_path =  $downloads_dir . "_uber";

$out = fopen($out_file_path . ".csv", 'w');
$fields = array(
    "taxonID",          // done
    "scientificNameID", // done
    "localID",          // done
    "scientificName",   // done
    "taxonRank",        // done
    "parentNameUsageID",// done
    "scientificNameAuthorship", // done 
    "family",           // done
    "subfamily",        // done
    "tribe",            // done
    "subtribe",         // done
    "genus",                // done
    "subgenus",             // done
    "specificEpithet",      // done
    "infraspecificEpithet", // done
    "verbatimTaxonRank",    // done
    "nomenclaturalStatus",  // done
    "namePublishedIn",      // done
    "taxonomicStatus",      // done
    "acceptedNameUsageID",  // done
    "originalNameUsageID",  // done
    "nameAccordingToID",    // won't do
    "taxonRemarks",         // done
    "created",              // done
    "modified",             // done
    "references",           // done
    "source",               // done
    "majorGroup",           // done
    "tplID",                // done
    "speciesHybridMarker",  // done
    "infraspecificRank",    // done
    "originalID",           // won't do
    "old_t1id",             // won't do
    "tropicosId",           // done
    "references1.0",        // won't do
    "doNotProcess",         // done
    "doNotProcess_reason",  // done
    "OfficialFamily",       // done
    "comments",             // done
    "deprecated"            // done
);
fputcsv($out, $fields);

$out_references = fopen($out_file_path . '_references.csv', 'w');
$fields_references = array(
    "taxonID",
    "identifier",
    "bibliographicCitation",
    "source",
    "relation",
    "doNotProcess",
    "doNotProcess_reason"
);
fputcsv($out_references, $fields_references);


$counter = 0;
$offset = 1; // offset is one because the first one is the root
$start = time();
gc_enable(); // just make sure we have garbage collection on
echo "\nCount\tMemory\tGC Cycles\tElapse Mins";
while(true){

    $response = $mysqli->query("SELECT * from `names` ORDER BY id LIMIT 10000 OFFSET $offset");

    // if we get nothing back then we have finished
    if($response->num_rows == 0) break;

    // if not then increment the offset
    // so next call will get the next page
    $offset += $response->num_rows;

    echo "\n" 
        . number_format($counter, 0) 
        . "\t" 
        . convert(memory_get_usage()) 
        . "\t" 
        . number_format(gc_collect_cycles(), 0)
        . "\t"
        . number_format((time() - $start)/60, 2);

   

    while($row = $response->fetch_assoc()){
        if($row['id'] == 1) continue; // the root (code) name
        process_row($row, $out, $fields ,$out_references, $fields_references);
        $counter++;
    }

    // try and free some memory between pages.
    Taxon::resetSingletons();
    Name::resetSingletons();
    Reference::resetSingletons();

}

fclose($out);
fclose($out_references);


// create the actual zip file for it
echo "\nCreating zip\n";

$creation_datestamp = date('Y-m-d\Th:i:s');
$creation_date = date('Y-m-d');

$zip = new ZipArchive();
$zip_path = $out_file_path . ".zip";

if ($zip->open($zip_path, ZIPARCHIVE::CREATE)!==TRUE) {
    exit("cannot open <$zip_path>\n");
}

// create personalize versions of the provenance and meta files for inclusion.

$meta_path = $out_file_path . ".meta.xml";
$meta = file_get_contents('darwin_core_meta_uber.xml');
$meta = str_replace('{{date}}', $creation_date, $meta);
file_put_contents($meta_path, $meta);

$eml_path = $out_file_path . ".eml.xml";
$eml = file_get_contents('darwin_core_eml_uber.xml');
$eml = str_replace('{{date}}', $creation_date, $eml);
$eml = str_replace('{{datestamp}}', $creation_datestamp, $eml);
file_put_contents($eml_path, $eml);

$zip->addFile($out_file_path . ".csv", "classification.csv");
$zip->addFile($out_file_path . "_references.csv", "references.csv");
$zip->addFile($eml_path, "eml.xml");
$zip->addFile($meta_path, "meta.xml");

if ($zip->close()!==TRUE) {
    exit("cannot close <$zip_path>\n". $zip->getStatusString());
}

unset($zip);

echo "Removing temp files\n";
unlink($out_file_path . ".csv");
unlink($out_file_path . "_references.csv");
unlink($eml_path);
unlink($meta_path);


function process_row($row, $out, $fields ,$out_references, $fields_references){

    global $ranks_table;

    $dwc = array();

    // load the name for the row
    $name = Name::getName($row['id']);
    $taxon = Taxon::getTaxonForName($name);
    if(!$taxon->getId()) $taxon = null;

    $dwc["taxonID"] = $name->getPrescribedWfoId();
    if($taxon){
        // placed
        if($taxon->getAcceptedName() == $name){

            // accepted taxon
            $dwc["scientificName"] = trim(strip_tags($taxon->getFullNameString(false,false)));
            $dwc["parentNameUsageID"] = $taxon->getParent()->getAcceptedName()->getPrescribedWfoId();
            $dwc["taxonomicStatus"] = 'Accepted';
            $dwc["acceptedNameUsageID"] = null;
            $dwc["speciesHybridMarker"] = $taxon->getHybridStatus() && $name->getRank() == 'species' ? "x":"";

            // we are an accepted name so we can fill in all relevant ranks we can find
            $ancestors = $taxon->getAncestors();
            $ancestors[] = $taxon; // add ourselves incase we are one of the ranks they require

        }else{

            // synonym
            $dwc["scientificName"] = trim(strip_tags($name->getFullNameString(false,false)));
            $dwc["parentNameUsageID"] = null;
            $dwc["taxonomicStatus"] = 'Synonym'; // taxonomicStatus
            $dwc["acceptedNameUsageID"] = $taxon->getAcceptedName()->getPrescribedWfoId(); // acceptedNameUsageID 

            // we are a synonym so only fill in family and major taxon
            $ancestors = $taxon->getAncestors();

        }


    }else{

        // unplaced
        $dwc["scientificName"] = trim(strip_tags($name->getFullNameString(false,false)));
        $dwc["taxonomicStatus"] = 'Unchecked';
        $dwc["parentNameUsageID"] = null;
        $dwc["acceptedNameUsageID"] = null;

        $ancestors = ancestors_for_unplaced_name($name);

    }


    // look for the family in the ancestors
    foreach($ancestors as $an){

        switch ($an->getAcceptedName()->getRank()) {
            case 'family':
                $dwc['family'] = $an->getAcceptedName()->getNameString();
                $dwc['OfficialFamily'] = $an->getAcceptedName()->getNameString();
                break;
            case 'subfamily':
                $dwc['subfamily'] = $an->getAcceptedName()->getNameString();
                break;
            case "tribe":
                $dwc["tribe"] = $an->getAcceptedName()->getNameString();
                break;
            case "subtribe":
                $dwc["subtribe"] = $an->getAcceptedName()->getNameString();
                break;
            case "subgenus":
                $dwc["subgenus"] = $an->getAcceptedName()->getNameString();
                break;
            case "phylum":

                // damn you, you have driven me to switch within switch!

                $major_group = 'A'; // default to angiosperms - why not!
                switch ($an->getAcceptedName()->getNameString()) {
                    // FIXME - this is dependant on names which isn't good
                    case 'Angiosperms':
                        $major_group = "A";
                        break;
                    case 'Bryophytes':
                        $major_group = "B";
                        break;
                    case 'Gymnosperms':
                        $major_group = "G";
                        break;
                    case 'Pteridophytes':
                        $major_group = "P";
                        break;
                    default:
                        $major_group = $an->getAcceptedName()->getNameString();
                        break;
                } // end inner switch
                $dwc["majorGroup"] = $major_group;

                break;
            default:
                break;
        }
    }
    
    $dwc["taxonRank"] = $name->getRank();

    $species_level = array_search('species', array_keys($ranks_table));
    $this_level = array_search($name->getRank(), array_keys($ranks_table));
    $dwc["infraspecificRank"] = $this_level > $species_level ?  $name->getRank() : "";

    $dwc["verbatimTaxonRank"]  = $name->getRank();
    $dwc["scientificNameAuthorship"] = $name->getAuthorsString();

    if($name->getRank() == 'genus'){
        $dwc["genus"] = $name->getNameString();
    }else{
        $dwc["genus"] = $name->getGenusString(); // will be empty above genus level
    }

    if($name->getRank() == 'species'){
        // we are an actual species
        $dwc["specificEpithet"] = $name->getNameString();
        $dwc["infraspecificEpithet"] = ''; // nothing in the infraspecificEpithet
    }else{
        if($name->getSpeciesString()){
            // we are below species level so will have an infraspecific epithet
            $dwc["specificEpithet"] = $name->getSpeciesString(); // specificEpithet
            $dwc["infraspecificEpithet"] = $name->getNameString(); // infraspecificEpithet
        }else{
            // we are above so these are empty
            $dwc["specificEpithet"] = ""; // specificEpithet
            $dwc["infraspecificEpithet"] = ""; // infraspecificEpithet
        }
    }

    $dwc["namePublishedIn"] = $name->getCitationMicro();

    // FIXME: these will throw deprecation error on null comments
    $dwc["taxonRemarks"] = str_replace("\n", " ", substr($name->getComment(), 0, 254)); // a hack to assure compatibility
    $dwc["comments"] = str_replace("\n", " ", substr($name->getComment(), 0, 254)); // a hack to assure compatibility

    // originalNameUsageID = basionym WFO ID
    if($name->getBasionym()) $dwc["originalNameUsageID"] = $name->getBasionym()->getPrescribedWfoId();
    
    $dwc["created"] =  date("Y-m-d", strtotime($name->getCreated()));
    $dwc["modified"] = date("Y-m-d", strtotime($name->getModified()));

    // now any identifiers we can think of
    $identifiers = $name->getIdentifiers();
    $wfo_ids = null;
    foreach($identifiers as $identifier) {

        switch ($identifier->getKind()) {
            case 'ipni':
                $dwc['scientificNameID'] = $identifier->getValues()[0];
                break;
            case 'tropicos':
                $dwc['scientificNameID'] = $identifier->getValues()[0];
                $dwc['tropicosId'] = $identifier->getValues()[0];
                break;
            case 'ten':
                $dwc['localID'] = $identifier->getValues()[0];
                break;
            case 'tpl':
                $dwc['tplID'] = $identifier->getValues()[0];
                break;
            case 'wfo':
                // get a handle on multiple $wfo_ids
                $wfo_ids = $identifier->getValues();
                break;
        }

    }
    
    // nomenclaturalStatus
    switch ($name->getStatus()) {
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
        case 'deprecated':
            $dwc["deprecated"] = 1;
            $nomStatus = '';
            break;
        default:
            $nomStatus = '';
            break;
    }
    $dwc["nomenclaturalStatus"] = $nomStatus;

    if(preg_match('/^[^A-Za-z]/', $dwc['scientificName'])){
        $dwc["doNotProcess"] = 1;
        $dwc["doNotProcess_reason"] = "Junk non-alphanumeric name";
    }else{
        $dwc["doNotProcess"] = 0;
        $dwc["doNotProcess_reason"] = null;
    }

    // references
    $refs = $name->getReferences();
    foreach($refs as $usage){
        if($usage->role == 'taxonomic' && $usage->reference->getKind() == 'database'){
            $dwc["references"] = $usage->reference->getLinkUri();
        }
        if($usage->role == 'taxonomic' && $usage->reference->getKind() == 'person'){
            $dwc["source"] = $usage->reference->getDisplayText();
        }
    }
    if(!isset($dwc["references"])) $dwc["references"] = "";

    // if we haven't got the source from the current name try working 
    // up through the ancestors to family level
    if(!isset($dwc["source"])){
        foreach ($ancestors as $anc) {
            if($anc->getAcceptedName()->getRank() == 'family'){
                $fam_refs = $anc->getAcceptedName()->getReferences();
                foreach ($fam_refs as $usage) {
                   if($usage->role == 'taxonomic' && $usage->reference->getKind() == 'person'){
                        $dwc["source"] = $usage->reference->getDisplayText();
                    }
                }
            }
        }
    }

    // if we still haven't got the source use the one in the source field - from original botalista dump
    if(!isset($dwc["source"])) $dwc["source"] = $name->getSource();

    // but if it has defaulted to rhakhis we try and overwrite with ipni or tropicos
    if($dwc["source"] == 'rhakhis'){
        foreach($identifiers as $identifier) {
            switch ($identifier->getKind()) {
                case 'ipni':
                    $dwc["source"] = 'ipni';
                    break 2;
                case 'tropicos':
                    $dwc["source"] = 'tropicos';
                    break 2;
            }
        }
    }

    // work through the refs a second time and add them all to the
    // references file
    foreach($refs as $usage){
        $ref = array();
        $ref['taxonID'] = $name->getPrescribedWfoId();
        $ref["identifier"] = $usage->id . "-" . $usage->reference->getKind();
        $ref["bibliographicCitation"] = $usage->reference->getDisplayText(); 
        $ref["source"] = $dwc["references"]; // for some reason this is the "references" field in the main table
        $ref["relation"] =  $usage->reference->getLinkUri();// the actual link to the reference
        $ref["doNotProcess"] = "0"; // just for compatibility with botalista version
        $ref["doNotProcess_reason"] = ""; // just for compatibility with botalista version

        // then we write it out in the order specified in the fields array
        $ref_out = array();
        foreach($fields_references as $rfield){
            if(isset($ref[$rfield])) $ref_out[] = $ref[$rfield];
            else $ref_out[] = "";
        }

        fputcsv($out_references, $ref_out);

    }

    $csv_row = array();
    foreach($fields as $field){
        if(isset($dwc[$field])){
            $csv_row[] = $dwc[$field];
        }else{
            $csv_row[] = null;
        }
    }
    fputcsv($out, $csv_row);

    // do we have multiple WFO IDs?
    // If so add an extra doNotProcess row
    foreach ($wfo_ids as $wfo) {
        
        // don't do the actual name
        if($wfo == $name->getPrescribedWfoId()) continue;

        // create a duplicate row
        $dupe = $dwc; // php arrays are cloned when changed...
        $dupe['taxonID'] = $wfo;
        $dupe["deprecated"] = 1;
        $dupe["doNotProcess"] = 1;
        $dupe["doNotProcess_reason"] = "Duplicate of {$name->getPrescribedWfoId()}";

        // write it out
        $csv_row = array();
        foreach($fields as $field){
            if(isset($dupe[$field])){
                $csv_row[] = $dupe[$field];
            }else{
                $csv_row[] = null;
            }
        }
        fputcsv($out, $csv_row);

    }

} // process row

function ancestors_for_unplaced_name($name){

    global $mysqli;


    // we can only do anything if we have a genus string
    if($name && $name->getGenusString()){

            $genus = $name->getGenusString();

            // get the first in a list of placed names
            // that use this genus name.
            $sql = "SELECT 
                    n.id,
                    n.`rank`
                FROM `names` as n
                JOIN `taxon_names` as tn on n.id = tn.name_id
                WHERE 
                    ( `name` = '$genus' AND `rank` = 'genus')
                OR
                    ( `genus` = '$genus' )
                ORDER BY FIND_IN_SET(n.`rank`, 'genus,species,subspecies,variety,form' )
                LIMIT 1;";
            $response = $mysqli->query($sql);

            if($response->num_rows > 0){
                $row = $response->fetch_assoc();
                $placed_name = Name::getName($row['id']);
                $taxon = Taxon::getTaxonForName($placed_name);
                $ancestors = $taxon->getAncestors();
                $ancestors[] = $taxon;
                return $ancestors;
            }

    } 
    
    // no joy so return empty array
    return array();


} // ancestors_for_unplaced_name

function convert($size){
    $unit=array('b','kb','mb','gb','tb','pb');
    return @round($size/pow(1024,($i=floor(log($size,1024)))),2).' '.$unit[$i];
}