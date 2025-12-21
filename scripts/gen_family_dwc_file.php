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

// php -d memory_limit=10G gen_family_dwc_file.php
// php -d memory_limit=10G gen_family_dwc_file.php wfo-7000000041

// is the memory thing because we are doing multiples. Should we call it in batches?

// get the family and do a crawl down it to load all the name ids
// do we do this by loading objects?

// get all the ones that are genera
// load all the names that have that genus id

// Aquifoliaceae wfo-7000000041
// Ericaceae wfo-7000000218
// Asteraceae wfo-7000000146
// $family_wfo = 'wfo-7000000218';

$downloads_dir = '../www/downloads/dwc/';
if (!file_exists($downloads_dir)) {
    mkdir($downloads_dir, 0777, true);
}

// get a list of all the families in the taxonomy
$response = $mysqli->query("SELECT i.`value` as wfo, n.`name` 
FROM `names` as n 
JOIN `identifiers` as i on n.prescribed_id = i.id
JOIN `taxon_names` as tn on n.id = tn.name_id
JOIN `taxa` as t on t.taxon_name_id = tn.id
where n.`rank` = 'family'
order by n.name_alpha
");

// We only process 20 per run to keep the memory low
// this script can then be run frequently and will
// add new families if they are added plus update all of 
// them within a finite time

if(isset($argv[1]) && preg_match('/wfo-[0-9]{10}/', $argv[1])){

    $name = Name::getName($argv[1]);
    $file_path = $downloads_dir . $name->getNameString() . '_'. $argv[1];
    process_family($argv[1], $file_path);

}else{

    $counter = 0;

    while($row = $response->fetch_assoc()){

        // before we start a family we clear the links to old taxa 
        // so we don't run out of memory 
        Taxon::resetSingletons();
        Name::resetSingletons();
        Reference::resetSingletons();

        $file_path = $downloads_dir . $row['name'] . '_'. $row['wfo'];

        // if the file is less than a day old then skip it
        if(
            file_exists($file_path . ".zip")
            &&
            filemtime($file_path . ".zip") > time() - (3 * 24 * 60 * 60)
        ){
            continue;
        }

        // file is more than a day old or doesn't exist so lets create it

        echo "\n*** ". $row['name'] ." ***\n";
        process_family($row['wfo'], $file_path);
        $counter++;
        
        if($counter > 20) break;
    }

}

function process_family($family_wfo, $file_path){

        global $ranks_table;

        $family_name = Name::getName($family_wfo);
        $family_taxon = Taxon::getTaxonForName($family_name);

        $creation_datestamp = date('Y-m-d\Th:i:s');
        $creation_date = date('Y-m-d');

        // all the descendants
        $descendants = $family_taxon->getDescendants();

        // don't forget ourselves
        array_unshift($descendants, $family_taxon); 

        // get all the linked in synonyms
        $synonyms = array();
        foreach ($descendants as $descendant) {
            $synonyms = array_merge($synonyms, $descendant->getSynonyms());
        }

        echo convert(memory_get_usage());
        echo "\n";
        echo "Total descendants: " . count($descendants);
        echo "\n";
        echo "Total synonyms: " .count($synonyms);

        // now all the unplaced stuff
        $unplaced_names = array();
        $link_index = array(); // will deduplicate on the way.

        echo "\nUnplaced names from taxa";
        foreach ($descendants as $taxon) {

            // put it in the link index for later
            $link_index[$taxon->getAcceptedName()->getPrescribedWfoId()] = $taxon;

            // find the associated unplaced names
            $finder = new UnplacedFinder($taxon->getAcceptedName()->getId(), 0, 1000000, false); // don't include deprecated.
            $unplaced_names = array_merge($unplaced_names, $finder->unplacedNames);

            // debug
            /*
            foreach($finder->unplacedNames as $u){
                $n = trim(strip_tags($u->getFullNameString(false,false)));
                echo "\n\tUnplaced from TAXON {$u->getId()} {$n}:";
            }
            */
        }
                

        echo "\nUnplaced names from synonyms";
        foreach ($synonyms as $name) {

            // put it in the link index for later
            $link_index[$name->getPrescribedWfoId()] = $name;

            // find associated unplaced names
            $finder = new UnplacedFinder($name->getId(), 0, 1000000, false); // no deprecated
            $unplaced_names = array_merge($unplaced_names, $finder->unplacedNames);

            // debug
            /*
            foreach($finder->unplacedNames as $u){
                $n = trim(strip_tags($u->getFullNameString(false,false)));
                echo "\n\tUnplaced from SYNONYM {$u->getId()} {$n}:";
            }
            */
        }

        // add the unplaced names that are in this family to the link_index as well
        foreach ($unplaced_names as $name) {
            if($family_name->getNameString() == guesstimate_family($name))
                $link_index[$name->getPrescribedWfoId()] = $name;
        }

        echo "\n";
        echo convert(memory_get_usage());
        echo "\n";
        echo "Total unplaced: " .count($unplaced_names);
        echo "\nTotal links: " .count($link_index);

        // now work through all the names and check there are no pointers to things outside our ken
        $original_link_index = $link_index; // we work on a copy so we aren't changing the array we iterate over.
        foreach($original_link_index as $item){
            check_name_links($item, $link_index);
        }
        echo "\nTotal links: " .count($link_index);

        echo "\n";

        // now we can write it out to file :)
        $out = fopen($file_path . '.csv', 'w');
        $out_references = fopen($file_path . '_references.csv', 'w');
        

        $fields = array(
            "taxonID",
            "scientificNameID",
            "localID",
            "scientificName",
            "taxonRank",
            "parentNameUsageID",
            "scientificNameAuthorship",
            "family",
            "subfamily",
            "tribe",
            "subtribe",
            "genus",
            "subgenus",
            "specificEpithet",
            "infraspecificEpithet",
            "verbatimTaxonRank",
            "nomenclaturalStatus",
            "namePublishedIn",
            "taxonomicStatus",
            "acceptedNameUsageID",
            "originalNameUsageID",
            "nameAccordingToID",
            "taxonRemarks",
            "created",
            "modified",
            "references",
            "source",
            "majorGroup",
            "tplID"
        );

        fputcsv($out, $fields, escape: "\\");

        $fields_references = array(
            "taxonID",
            "identifier",
            "bibliographicCitation",
            "source",
            "relation",
            "doNotProcess",
            "doNotProcess_reason"
        );

        fputcsv($out_references, $fields_references, escape: "\\");

        foreach ($link_index as $wfo => $item) {

            if(!$wfo) continue;// strangely we get this from time to time?
            
            $row = array();

            // we could have been passed either
            if(is_a($item, 'Taxon')){
                $name = $item->getAcceptedName();
                $taxon = $item;
            }else{
                $name = $item;
                $taxon = null;
            }
            
            // we do the name specific stuff first
            // there is always a name.

            // taxonID = prescribed wfo ID
            $row["taxonID"] = $name->getPrescribedWfoId();

            // scientificName = 
            $row["scientificName"] = trim(strip_tags($name->getFullNameString(false,false)));

            // rank
            $row["taxonRank"] = $name->getRank();

            // scientificNameAuthorship = authorship field
            $row["scientificNameAuthorship"] = $name->getAuthorsString();

            // genus = the genus name part or the name if this is of rank genus
            if($name->getRank() == 'genus'){
                $row["genus"] = $name->getNameString();
            }else{
                $row["genus"] = $name->getGenusString(); // will be empty above genus level
            }
            
            // specificEpithet = species name part if set or the species name if this is of rank species
            if($name->getRank() == 'species'){
                // we are an actual species
                $row["specificEpithet"] = $name->getNameString();
                $row["infraspecificEpithet"] = ''; // nothing in the infraspecificEpithet
            }else{
                if($name->getSpeciesString()){
                    // we are below species level so will have an infraspecific epithet
                    $row["specificEpithet"] = $name->getSpeciesString(); // specificEpithet
                    $row["infraspecificEpithet"] = $name->getNameString(); // infraspecificEpithet
                }else{
                    // we are species or above so these are empty
                    $row["specificEpithet"] = ""; // specificEpithet
                    $row["infraspecificEpithet"] = ""; // infraspecificEpithet
                }
            }
            
            // nomenclaturalStatus = name status field
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
                default:
                    $nomStatus = '';
                    break;
            }

            $row["nomenclaturalStatus"] = $nomStatus;

            // namePublishedIn = citation
            $row["namePublishedIn"] = $name->getCitationMicro();

            // originalNameUsageID = basionym WFO ID
            if($name->getBasionym()){

                // double check the basionym is in the list.
                $basionym_wfo = $name->getBasionym()->getPrescribedWfoId();
                if( isset($link_index[$basionym_wfo]) ){
                    $row["originalNameUsageID"] = $basionym_wfo;
                }else{
                    echo "\n BROKEN BASIONYM LINK FOUND \n";
                    print_r($name);
                    echo "\n-- basionym --\n";
                    print_r($name->getBasionym());
                    exit;
                }

            }else{
                $row["originalNameUsageID"] = null;
            }
            
            // now fields that only taxa == accepted names
            if($taxon){

                // scientificName is changed as it may include hybrid flags
                $row["scientificName"] = trim(strip_tags($taxon->getFullNameString(false,false)));
                
                // parentNameUsageID = For accepted names of taxa only the parent taxon wfo_ID
                // catch if we are adding code that doesn't have a parent
                if($taxon->getParent()){
                    $row["parentNameUsageID"] = $taxon->getParent()->getAcceptedName()->getPrescribedWfoId();
                }else{
                    $row["parentNameUsageID"] = '';
                }
                
                // taxonomicStatus
                $row["taxonomicStatus"] = 'Accepted';

                // acceptedNameUsageID = synonyms only is accepted taxon WFO ID
                $row["acceptedNameUsageID"] = null;

            }else{

                // parentNameUsageID = For accepted names of taxa only the parent taxon wfo_ID
                $row["parentNameUsageID"] = null;

                // we are a name but are we placed or unplaced.
                $placement = Taxon::getTaxonForName($name);

                if(!$placement->getId()){
                    // no taxon in database for the name so unplaced
                    $row["taxonomicStatus"] = 'Unchecked'; // taxonomicStatus
                    $row["acceptedNameUsageID"] = null; // acceptedNameUsageID 
                }else{
                    $row["taxonomicStatus"] = 'Synonym'; // taxonomicStatus
                    $row["acceptedNameUsageID"] = $placement->getAcceptedName()->getPrescribedWfoId(); // acceptedNameUsageID 
                }

            }

            // taxonRemarks	= comments from name field
            if($name->getComment()){
                $row["taxonRemarks"] = str_replace("\n", " ", substr($name->getComment(), 0, 254));
            }else{
                $row["taxonRemarks"] = '';
            }

             // a hack to assure compatibility

            // now any identifiers we can think of
            $identifiers = $name->getIdentifiers();

            foreach($identifiers as $identifier) {

                switch ($identifier->getKind()) {
                    case 'ipni':
                        $row['scientificNameID'] = $identifier->getValues()[0];
                        break;
                    case 'tropicos':
                        $row['scientificNameID'] = $identifier->getValues()[0];
                        break;
                    case 'ten':
                        $row['localID'] = $identifier->getValues()[0];
                        break;
                    case 'tpl':
                        $row['tplID'] = $identifier->getValues()[0];
                        break;
                }

            }

            // the reference is the taxonomy database source - if it has one
            // get the references for the name
            $refs = $name->getReferences();
            foreach($refs as $usage){
                if($usage->role == 'taxonomic' && $usage->reference->getKind() == 'database'){
                    $row["references"] = $usage->reference->getLinkUri();
                }
                if($usage->role == 'taxonomic' && $usage->reference->getKind() == 'person'){
                    $row["source"] = $usage->reference->getDisplayText();
                }
            }
            if(!isset($row["references"])) $row["references"] = "";

            // if we haven't got the source from the current name try working 
            // up through the ancestors to family level

            // if the name is a synonym we use the accepted name.
            $ancestors = null;
            if($taxon){
                $ancestors = $taxon->getAncestors();
            }else{
                $t = Taxon::getTaxonForName($name);
                $ancestors = $t->getAncestors(); // maybe nothing if taxon is empty because this is unplaced name.
            }

            if(!isset($row["source"])){
                foreach ($ancestors as $anc) {
                    if($anc->getAcceptedName()->getRank() == 'family'){
                        $fam_refs = $anc->getAcceptedName()->getReferences();
                        foreach ($fam_refs as $usage) {
                            if($usage->role == 'taxonomic' && $usage->reference->getKind() == 'person'){
                                $row["source"] = $usage->reference->getDisplayText();
                            }
                        }
                    }
                }
            }

            // if we still haven't got the source use the one in the source field - from original botalista dump
            if(!isset($row["source"])) $row["source"] = $name->getSource();

            // but if it has defaulted to rhakhis we try and overwrite with ipni or tropicos
            if($row["source"] == 'rhakhis'){
                foreach($identifiers as $identifier) {
                    switch ($identifier->getKind()) {
                        case 'ipni':
                            $row["source"] = 'ipni';
                            break 2;
                        case 'tropicos':
                            $row["source"] = 'tropicos';
                            break 2;
                    }
                }
            }

            // work through the refs a second time and add them all to the
            // references file
            foreach($refs as $usage){
                $ref = array();
                $ref['taxonID'] = $row["taxonID"];
                $ref["identifier"] = $usage->id . "-" . $usage->reference->getKind();
                $ref["bibliographicCitation"] = $usage->reference->getDisplayText(); 
                $ref["source"] = $row["references"]; // for some reason this is the "references" field in the main table
                $ref["relation"] =  $usage->reference->getLinkUri();// the actual link to the reference
                $ref["doNotProcess"] = "0"; // just for compatibility with botalista version
                $ref["doNotProcess_reason"] = ""; // just for compatibility with botalista version

                // then we write it out in the order specified in the fields array
                $ref_out = array();
                foreach($fields_references as $rfield){
                    if(isset($ref[$rfield])) $ref_out[] = $ref[$rfield];
                    else $ref_out[] = "";
                }

                fputcsv($out_references, $ref_out, escape: "\\");

            }

            // source is a string giving the name of where it came from
            //  This will be from the botalista dump originally then defaults to 'rhakhis' for all blank and new names
            $row["source"] = $name->getSource();
            
            // created = from name ? or earliest from name/taxon
            $row["created"] =  date("Y-m-d", strtotime($name->getCreated()));
            // modified	= from name ? 
            $row["modified"] = date("Y-m-d", strtotime($name->getModified()));

            // now we have the extra rows columns

            // add the major group first
            // we can't just use the major group of the family because this may be
            // a homonym or something from another major group
            $major_group = "?";
            if($ancestors){
                // we look up the tree if we are attached to the tree
                $major_group_ancestors = $ancestors;
            }else{
                // we look up the tree of the family that is being exported
                // if we are unplaced.
                $major_group_ancestors = $family_taxon->getAncestors();
            }
            foreach($major_group_ancestors as $an){
                // FIXME: These names have to match names in data for this to work
                if($an->getRank() == 'phylum'){
                    switch ($an->getAcceptedName()->getNameString()) {
                        
                        case 'Angiosperms':
                            $major_group = "A";
                            break;
                        
                        case 'Bryobiotina':
                            $major_group = "B";
                            break;

                        case 'Pinophyta':
                        case 'Ginkgophyta':
                        case 'Cycadophyta':
                        case 'Lycopodiophyta':
                            $major_group = "G";
                            break;

                        case 'Polypodiophyta':
                            $major_group = "P";
                            break;
                        
                        default:
                            $major_group = $an->getAcceptedName()->getNameString();
                            break;
                    }
                    break;
                }
            }
            $row["majorGroup"] = $major_group;

            // family and other ranks
            
            if($name->getRank() == 'family'){

                $t = Taxon::getTaxonForName($name);

                // if this is a family and a synonym of another family
                // then the ancestors will be above accepted-family 
                // so we need to do something special
                if($t && $t->getAcceptedName() != $name && $t->getAcceptedName()->getRank() == 'family'){
                    $row['family'] = $t->getAcceptedName()->getNameString();
                }else{
                    // it is a family but not a synonym of a family
                    // this will be overwritten if it is a family but a synonym of another taxon below family
                    $row['family'] = $name->getNameString();
                }

            }


            if(!$ancestors){
                // We don't have a placement so how do we work out what family to 
                // put it in?   

                // try and guess
                $row['family'] = guesstimate_family($name);

                // if we still don't have anything use the family we are working on now
                if(!$row['family']) $row['family'] = $family_name->getNameString();

            }

            foreach($ancestors as $an){
                switch ($an->getAcceptedName()->getRank()) {
                    case "family":
                        $row["family"] = $an->getAcceptedName()->getNameString();
                        break;
                    case "subfamily":
                        $row["subfamily"] = $an->getAcceptedName()->getNameString();
                        break;
                    case "tribe":
                        $row["tribe"] = $an->getAcceptedName()->getNameString();
                        break;
                    case "subtribe":
                        $row["subtribe"] = $an->getAcceptedName()->getNameString();
                        break;
                    case "subgenus":
                        $row["subgenus"] = $an->getAcceptedName()->getNameString();
                        break;
                }
            }

            // super control over family placement to make sure code is not assigned a family
            // not sure how this is happening but we can fix it at this point
            $t = Taxon::getTaxonForName($name);
            if($t && $t->getId()){
                // yes it is an accepted name
                $rank = $name->getRank();
                $family_level = array_search('family', array_keys($ranks_table));
                $our_level =  array_search($rank, array_keys($ranks_table));
                if ($our_level <= $family_level) $row['family'] = null;
            }


            // verbatimTaxonRank
            $above_species = true;
            $rank_abbreviation = "";
            foreach($ranks_table as $rank_name => $rank){

                // we aren't interested in things above species
                if($above_species){
                    if($rank_name == 'species') $above_species = false;
                    continue;
                }

                // we are below species
                if($rank_name == $name->getRank()){
                    $rank_abbreviation = $rank['abbreviation'];
                }
            
            }
            $row["verbatimTaxonRank"] = $rank_abbreviation;

            // write it out to the file
            $csv_row = array();
            foreach($fields as $field){

                if(isset($row[$field])){
                    $csv_row[] = $row[$field];
                }else{
                    $csv_row[] = null;
                }
            }
            fputcsv($out, $csv_row, escape: "\\");

        }

        fclose($out);
        fclose($out_references);

        // we need to make a personalized attribution string for the eml file.

        // set up the default values
        $eml_organisation = "World Flora Online (WFO)";
        $eml_organisation_uri = "http://www.worldfloraonline.org/organisation/" . $family_name->getNameString();
        $eml_comment = "This taxonomic group is not currently curated by a WFO TEN (Taxonomic Expert Network).  Pending the inclusion of the first classification update from this TEN, nomenclatural and classification data are managed by the WFO Taxonomic Working Group using information derived from various sources. The initial data ingestion into the World Flora Online taxonomic backbone was from The Plant List Version 1.1 (TPL, http://theplantlist.org, September 2013), with the full list of contributing datasets given here: http://theplantlist.org/1.1/about/#collaborators. New taxonomic names have been incorporated into WFO from nomenclators: International Plant Name Index (IPNI, https://www.ipni.org) for vascular plants, and Tropicos (https://www.tropicos.org) for bryophytes. Taxonomic and nomenclatural updates have been incorporated from the World Checklist of Vascular Plants version 2.0 (WCVP, http://wcvp.science.kew.org), facilitated by the Royal Botanic Gardens, Kew.";

        // look for a person taxon reference - they are the organisation we use
        $refs = $family_name->getReferences();
        foreach($refs as $usage){
            if($usage->role == 'taxonomic' && $usage->reference->getKind() == 'person'){
                $eml_organisation = $usage->reference->getDisplayText();
                $eml_organisation_uri = $usage->reference->getLinkUri();
                $eml_comment = $usage->comment;
            }
        }
 
        // let's create the zip file with metadata in it

        echo "\nCreating zip\n";
        $zip = new ZipArchive();
        $zip_path = $file_path . ".zip";

        if ($zip->open($zip_path, ZIPARCHIVE::CREATE)!==TRUE) {
            exit("cannot open <$zip_path>\n");
        }

        // create personalize versions of the provenance and meta files for inclusion.

        $meta_path = $file_path . ".meta.xml";
        $meta = file_get_contents('darwin_core_meta.xml');
        $meta = str_replace('{{family}}', $family_name->getNameString(), $meta);
        $meta = str_replace('{{date}}', $creation_date, $meta);
        file_put_contents($meta_path, $meta);

        $eml_path = $file_path . ".eml.xml";
        $eml = file_get_contents('darwin_core_eml.xml');
        $eml = str_replace('{{family}}', $family_name->getNameString(), $eml);
        $eml = str_replace('{{date}}', $creation_date, $eml);
        $eml = str_replace('{{datestamp}}', $creation_datestamp, $eml);
        $eml = str_replace('{{organisation}}', $eml_organisation, $eml);
        $eml = str_replace('{{organisation_uri}}', $eml_organisation_uri, $eml);
        $eml = str_replace('{{comment}}', $eml_comment, $eml);

        file_put_contents($eml_path, $eml);

        $zip->addFile($file_path . ".csv", "classification.csv");
        $zip->addFile($file_path . "_references.csv", "references.csv");
        $zip->addFile($eml_path, "eml.xml");
        $zip->addFile($meta_path, "meta.xml");

        if ($zip->close()!==TRUE) {
            exit("cannot close <$zip_path>\n". $zip->getStatusString());
        }

        unset($zip);

        echo "Removing temp files\n";
        unlink($file_path . ".csv");
        unlink($file_path . "_references.csv");
        unlink($meta_path);
        unlink($eml_path);
}


function check_name_links($item, &$link_index){

    global $ranks_table;

    if(is_a($item, 'Taxon')) $name = $item->getAcceptedName();
    else $name = $item;

    if($name->getPrescribedWfoId() == "wfo-0001044126"){
        echo "\n checking name links \n";
    }

    // basionyms first
    $basionym = $name->getBasionym();
    if($basionym){
        // if the basionym isn't in the list add it
        // and check it's links are in the db
        if(!isset($link_index[$basionym->getPrescribedWfoId()])){
            echo "\nBasionym of: " . strip_tags($name->getFullNameString(false)) . " " . $name->getPrescribedWfoId() . " is missing from file.";
            echo "\n\tBasionym is: " . strip_tags($basionym->getFullNameString(false)) . " " . $basionym->getPrescribedWfoId();
            
            $link_index[$basionym->getPrescribedWfoId()] = $basionym;

            // if the basionym has been place it is in a different family
            $basionym_taxon = Taxon::getTaxonForName($basionym);
            if($basionym_taxon->getId()){

                // add the basionym taxon just incase the basionym is a synonym
                $link_index[$basionym_taxon->getAcceptedName()->getPrescribedWfoId()] = $basionym_taxon;

                // the accepted name might have a basionym
                if($basionym_taxon->getAcceptedName()->getBasionym()){
                    $link_index[$basionym_taxon->getAcceptedName()->getBasionym()->getPrescribedWfoId()] = $basionym_taxon->getAcceptedName()->getBasionym(); 
                    echo "\n\tAccepted Name has Basionym: " . strip_tags($basionym_taxon->getAcceptedName()->getBasionym()->getFullNameString(false)) . " " . $basionym_taxon->getAcceptedName()->getBasionym()->getPrescribedWfoId();
                }

                // add  all its parents up to family
                $ancestors = $basionym_taxon->getAncestors();
                foreach ($ancestors as $ans) {
                    echo "\n\tAncestor of basionym: " . strip_tags($ans->getAcceptedName()->getFullNameString(false)) ." " . $ans->getAcceptedName()->getPrescribedWfoId();
                    $link_index[$ans->getAcceptedName()->getPrescribedWfoId()] = $ans;
                    check_name_links($ans, $link_index);

                    // are we at or above family level
                    //if($ans->getAcceptedName()->getRank() == 'family') break;
                    // rare instance of there not being a family in the hierarchy
                   //$family_level = array_search('family', array_keys($ranks_table));
                   //$our_level =  array_search($ans->getAcceptedName()->getRank(), array_keys($ranks_table));
                   //if ($our_level <= $family_level) break;

                }

            }

            check_name_links($basionym, $link_index);

        }
    }

}

/**
 * Try and provided a consistent family 
 * for unplaced names
 * 
 */
function guesstimate_family($name){

    global $mysqli;

    // If name is unplaced but it has a genus name see if the genus name is placed - if it is use that family.
    if($name->getGenusString()){

        $response = $mysqli->query("SELECT id FROM `names` WHERE `name` = '{$name->getGenusString()}' AND `rank` = 'genus' ORDER BY id;");
        $rows = $response->fetch_all(MYSQLI_ASSOC);
        $response->close();
        
        // we take the first genus name that is placed in the taxonomy if there are multiples
        foreach($rows as $row){
            $genus_name = Name::getName($row['id']);
            $genus_taxon = Taxon::getTaxonForName($genus_name);
            $family_name = get_family_name_for_taxon($genus_taxon);
            if($family_name) return $family_name;
        }

    }

    // If the genus name isn't placed does it have a basionym?
    $basionym= $name->getBasionym();
    if($basionym){
        $basionym_taxon = Taxon::getTaxonForName($basionym);
        $family_name = get_family_name_for_taxon($basionym_taxon);
        if($family_name) return $family_name;
    }

    // If that doesn't work is it the basionym of something else?
    $response = $mysqli->query("SELECT id FROM `names` WHERE `basionym_id` = {$name->getId()} ORDER BY id;");
    $rows = $response->fetch_all(MYSQLI_ASSOC);
    $response->close();

    foreach($rows as $row){
        $homotypic_name = Name::getName($row['id']);
        $homotypic_taxon = Taxon::getTaxonForName($homotypic_name);
        $family_name = get_family_name_for_taxon($homotypic_taxon);
        if($family_name) return $family_name;
    }


    // If none of those work look at the hints.
    $hints = $name->getHints();
    asort($hints);
    foreach ($hints as $hint) {
        if(preg_match('/aceae$/', $hint)){
            return $hint;
        }
    }

}

function get_family_name_for_taxon($taxon){

    global $ranks_table;

    if(!$taxon->getId()) return null;

    // taxa above the family level aren't in families
    $family_level = array_search('family', array_keys($ranks_table));
    $our_level =  array_search($taxon->getAcceptedName()->getRank(), array_keys($ranks_table));
    if ($our_level <= $family_level) return null;

    // genus is placed in the taxonomy
    $ancestors = $taxon->getAncestors();
    foreach($ancestors as $anc) {
        if($anc->getAcceptedName()->getRank() == 'family'){
            return $anc->getAcceptedName()->getNameString();
        }
    }

    return null;

}



function convert($size){
    $unit=array('b','kb','mb','gb','tb','pb');
    return @round($size/pow(1024,($i=floor(log($size,1024)))),2).' '.$unit[$i];
}