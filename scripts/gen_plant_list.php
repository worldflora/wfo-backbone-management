<?php

/*
    This will generate a file suitable for import
    into SOLR as an instance of the WFO Plant List.
    Basically it is a flattened dump of the entire dataset

    // php -d memory_limit=3G gen_plant_list.php

    import the output into solr
    curl -X POST -H 'Content-type:application/json' 'http://localhost:8983/solr/wfo/update?commit=true' --data-binary @plant_list_2022-12.json  --user wfo:****

    // this version doesn't run out of memory
    curl -H 'Content-type:application/json' 'http://localhost:8983/solr/wfo/update?commit=true' -X POST -T plant_list_2025-06.json --user wfo:****

    clear down solr
    curl -X POST -H 'Content-Type: application/json' 'http://localhost:8983/solr/wfo/update' --data-binary '{"delete":{"query":"classification_id_s:9999-04"} }' --user wfo:****
    curl -X POST -H 'Content-Type: application/json' 'http://localhost:8983/solr/wfo/update' --data-binary '{"commit":{} }' --user wfo:****

*/

require_once("../config.php");
require_once("../include/WfoDbObject.php");
require_once("../include/Name.php");
require_once("../include/Taxon.php");
require_once("../include/Identifier.php");
require_once("../include/User.php");
require_once("../include/Reference.php");
require_once("../include/ReferenceUsage.php");
require_once("../include/AuthorTeam.php");

$start = time();

// we build the output on the basis of the month and year
$version_name = date('Y-m');

// date of dump must be passed in.
if(count($argv) < 2 || !preg_match('/[0-9]{4}-[0-9]{2}/', $argv[1]) ){
    echo "\nYou must provide a version name  in the format 2023-06\n";
    exit;
}

$version_name = $argv[1];

$parts = explode('-', $version_name);
$version_year = $parts[0];
$version_month = $parts[1];

$json_file_name = "plant_list_$version_name.json";
$json_file_path = "../data/versions/$json_file_name";

$fields_file_name = "plant_list_{$version_name}_fields.json";
$fields_file_path = "../data/versions/$fields_file_name";

// open the file to dump it to
$out = fopen($json_file_path, "w");
fwrite($out, "[\n");

// we dynamically track the fields
$fields = array(); 

$counter = 0;
$offset = 0; 

while(true){

    // try and free some memory between pages.
    Taxon::resetSingletons();
    Name::resetSingletons();
    Reference::resetSingletons();

    // debug
    // if($offset >= 100000) break;

    $response = $mysqli->query("SELECT id, name_alpha FROM `names` limit 10000 OFFSET $offset");
    
    // if we get nothing back then we have finished
    if($response->num_rows == 0) break;

    // if not then increment the offset
    // so next call will get the next page
    $offset += $response->num_rows;

    // report on progress
    echo "\nNames"
        . "\t" 
        . number_format($counter, 0) 
        . "\t" 
        . convert(memory_get_usage()) 
        . "\t"
        . number_format((time() - $start)/60, 2);


    while($row = $response->fetch_assoc()){

        $name = Name::getName($row['id']);
        $solr_doc = process_name($name, $version_name);

        // get the fields in the right order 
        // and write them to the csv
        if($solr_doc){

            // add common fields
            $solr_doc["classification_id_s"] = $version_name;
            $solr_doc["classification_year_i"] = $version_year;
            $solr_doc["classification_month_i"] = $version_month;
            $solr_doc["full_name_string_alpha_s"] = $row['name_alpha']; // name object doesn't know about this
            $solr_doc["full_name_string_alpha_t_sort"] = $row['name_alpha'];
            $solr_doc['solr_import_dt'] = date('Y-m-d\Th:i:s\Z');

            if($counter > 0) fwrite($out, ",\n");
            fwrite($out, json_encode($solr_doc));

            // check we are up to date on the fields
            $fields = array_unique(array_merge($fields,array_keys($solr_doc)));

        }

        $counter++;

    } // rows loop

} // paging loop

fwrite($out, "\n]");
fclose($out);

// write the fields out so we have a record
file_put_contents($fields_file_path, json_encode($fields, JSON_PRETTY_PRINT));


/*

we no longer zip it up because we will
trigger an import immediately then zip it in the shell script

echo "\nCreating zip\n";
$zip = new ZipArchive();
$zip_path = $json_file_path . ".zip";

if ($zip->open($zip_path, ZIPARCHIVE::CREATE)!==TRUE) {
    exit("cannot open <$zip_path>\n");
}

$zip->addFile($json_file_path, $json_file_name);
$zip->addFile($fields_file_path, $fields_file_name);

if ($zip->close()!==TRUE) {
    exit("cannot close <$zip_path>\n". $zip->getStatusString());
}


unlink($json_file_path);
unlink($fields_file_path);

*/

function process_name($name, $version_name){

    $out = array();

    // name fields 
    $out['id'] = $name->getPrescribedWfoId() . '-' . $version_name;
    $out['wfo_id_s'] = $name->getPrescribedWfoId();
    $out['full_name_string_html_s'] = $name->getFullNameString();
    $out['full_name_string_plain_s'] = strip_tags($name->getFullNameString());
    $out['full_name_string_no_authors_plain_s'] = strip_tags($name->getFullNameString(false, false, true,false));
    $out["name_string_s"] = $name->getNameString();
    $out["genus_string_s"] = $name->getGenusString();
    $out["species_string_s"]  = $name->getSpeciesString();
    $out["nomenclatural_status_s"] = $name->getStatus();
    $out["rank_s"] = $name->getRank();
    $out["citation_micro_s"] = $name->getCitationMicro();
    $out["publication_year_i"] = $name->getYear();
    $out["citation_micro_t"] = $name->getCitationMicro();
    $out["comment_t"] = $name->getComment();
    

    // identifiers
    $out['identifiers_other_kind_ss'] = array();
    $out['identifiers_other_value_ss'] = array();
    $out['wfo_id_deduplicated_ss'] = array();
    $identifiers = $name->getIdentifiers();
    foreach($identifiers as $identifier){

        foreach($identifier->getValues() as $val){

            if($identifier->getKind() == 'wfo'){
                // skip the exiting WFO
                if($val != $name->getPrescribedWfoId()){
                    $out['wfo_id_deduplicated_ss'] = $val;
                }
            }else{
                // simply map what we have got into the index
                $out['identifiers_other_kind_ss'][] = $identifier->getKind();
                $out['identifiers_other_value_ss'][] = $val;
            }

        }

    }

    // set a preferred ipni id
    $out['ipni_preferred_id_s'] = $name->getPreferredIpniId();

    // basionym!
    $basionym = $name->getBasionym();
    if($basionym){
        $out['basionym_id_s'] = $basionym->getPrescribedWfoId()  . '-' . $version_name;
    }

    // author team stuff
    $authorTeam = new AuthorTeam($name->getAuthorsString());
    $out['authors_string_s'] = $name->getAuthorsString();
    $out['authors_string_html_s'] = $authorTeam->getHtmlAuthors();
    $out['authors_string_labels_ss'] = $authorTeam->getAuthorLabels();
    $out['authors_string_ids_ss'] = $authorTeam->getAuthorIds();

    // references
    $out["reference_kinds_ss"] = array();
    $out["reference_uris_ss"] = array();
    $out["reference_thumbnail_uris_ss"] = array();
    $out["reference_labels_ss"] = array();
    $out["reference_comments_ss"] = array();

    $ref_usages = $name->getReferences();
    foreach($ref_usages as $usage){
        $out["reference_kinds_ss"][] = $usage->reference->getKind();
        $out["reference_uris_ss"][] = $usage->reference->getLinkUri();
        $out["reference_thumbnail_uris_ss"][] = $usage->reference->getThumbnailUri() ? $usage->reference->getThumbnailUri() : "-";
        $out["reference_labels_ss"][] = $usage->reference->getDisplayText();
        $out["reference_comments_ss"][] = $usage->comment ? $usage->comment: "-";
        $out["reference_contexts_ss"][] = $usage->role;
    }


    // taxonomy
    $taxon = Taxon::getTaxonForName($name);
    if($taxon->getId()){
        // we are placed in the taxonomy
        if($taxon->getAcceptedName()->getId() == $name->getId()){

            // because we are a taxon we use the taxon version of our full name strings
            // will include hybrids and multi level subspecifics
            $out['full_name_string_html_s'] = $taxon->getFullNameString();
            $out['full_name_string_plain_s'] = strip_tags($taxon->getFullNameString());
            $out['full_name_string_no_authors_plain_s'] = strip_tags($taxon->getFullNameString(false, false, true,false));

            // we are an accepted name
            $out['role_s'] = "accepted";
            
            // flag if this is a hybrid taxon
            $out['hybrid_taxon_b'] =  $taxon->getHybridStatus();

            // looking up the way - building links 
            $parent = $taxon->getParent();

            // code has no parent
            if($parent){
                $out['parent_id_s'] = $parent->getAcceptedName()->getPrescribedWfoId()  . '-' . $version_name;
            }

            // some useful stats for rendering the tree
            $out['child_taxon_count_i'] = $taxon->getChildCount();

        }else{

            // we are a synonym
            $out['role_s'] = "synonym";
            $out['accepted_id_s'] = $taxon->getAcceptedName()->getPrescribedWfoId()  . '-' . $version_name; 

            // couple of useful things to help build links without fetching the other record
            $out['accepted_full_name_string_html_s'] = $taxon->getAcceptedName()->getFullNameString();
            $out['accepted_full_name_string_plain_s'] = strip_tags($taxon->getAcceptedName()->getFullNameString());

        }

        // add the placement path so we can find it in interesting ways
        $ancestors = $taxon->getAncestors();
        array_unshift($ancestors,$taxon); // add self
        $name_path = array();
        foreach ($ancestors as $anc) {
            $rank = $anc->getAcceptedName()->getRank();
            $out["placed_in_{$rank}_s"] = $anc->getAcceptedName()->getNameString();
            $name_path[] = $anc->getAcceptedName()->getNameString();
        }
        $name_path = array_reverse($name_path);
        $path = implode('/', $name_path);
        $out['name_descendent_path'] = $path;
        $out['name_ancestor_path'] = $path;
        $out['name_path_s'] = $path;

        // add some credit where it is due

        // editors
        $out['editors_name_ss'] = array();
        $out['editors_orcid_ss'] = array();
        $editors = $taxon->getEditors();
        foreach ($editors as $editor) {
            if($editor->getOrcidId()){
                // real person
                $out['editors_name_ss'][] = $editor->getName();
                $out['editors_orcid_ss'][] = $editor->getOrcidId();
            }
        }

        // TENs
        $out['ten_name_s'] = array();
        $out['ten_uri_s'] = array();
        $out['ten_comment_s'] = array();
        foreach($ancestors as $anc){

            // give up at the root
            if($anc->getAcceptedName()->getRank() == 'code') break;

            // do we have a person taxonomic reference
            $anc_refs = $anc->getAcceptedName()->getReferences();
            foreach($anc_refs as $usage){
                // this is how we flag TENs as being the source
                if($usage->role == 'taxonomic' && $usage->reference->getKind() == 'person'){
                    $out['ten_name_s'] = $usage->reference->getDisplayText();
                    $out['ten_uri_s'] = $usage->reference->getLinkUri();
                    $out['ten_comment_s'] = $usage->comment;
                    break; // found a TEN so stop
                }
            }

        } // work up ancestors and find a TEN


    }else{
        // unplaced name
        if($name->getStatus() == "deprecated") $out['role_s'] = "deprecated";
        else $out['role_s'] = "unplaced";
    }

    return $out;

}

// cut and paste utility function
function convert($size){
    $unit=array('b','kb','mb','gb','tb','pb');
    return @round($size/pow(1024,($i=floor(log($size,1024)))),2).' '.$unit[$i];
}