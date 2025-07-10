<?php

// this will generate a COLDP suitable for import into
// ChecklistBank

// php -d memory_limit=3G gen_coldp.php 2023-12-22 test-01

require_once("../config.php");
require_once("../include/WfoDbObject.php");
require_once("../include/Name.php");
require_once("../include/Taxon.php");
require_once("../include/Reference.php");
require_once("../include/ReferenceUsage.php");
require_once("../include/UnplacedFinder.php");
require_once("../include/Identifier.php");
require_once("../include/User.php");

echo "\nStarting COLDP File\n";

$start = time();

// date of dump must be passed in.
if(count($argv) < 2 || !preg_match('/[0-9]{4}-[0-9]{2}-[0-9]{2}/', $argv[1]) ){
    echo "\nYou must provide a publish date in the format 2023-06-21\n";
    exit;
}

$pub_date = $argv[1];
$version = substr($pub_date, 0, 7);

echo "Version: $version\n";
echo "Publication: $pub_date\n";
echo "Release: " . @$argv[2] . "\n";

$downloads_dir = '../www/downloads/coldp/';
if(!file_exists($downloads_dir)) mkdir($downloads_dir, 0755, true);

$family_level = array_search('family', array_keys($ranks_table));
$genus_level = array_search('genus', array_keys($ranks_table));
$species_level = array_search('species', array_keys($ranks_table));

// names - set up csv file
$names_file_path = $downloads_dir . "name.tsv";
$names_out = fopen($names_file_path, 'w');
$names_fields = array(
    "ID",
    "alternativeID",
    "basionymID",
    "scientificName",
    "authorship",
    "rank",
    "uninomial",
    "genus",
    "infragenericEpithet",
    "specificEpithet",
    "infraspecificEpithet",
    "code",
    "referenceID",
    "publishedInYear",
    "link"
);
fputcsv($names_out, $names_fields, "\t", escape: "\\");

// refs - set up csv file
$refs_file_path = $downloads_dir . "reference.tsv";
$refs_out = fopen($refs_file_path, 'w');
$refs_fields = array(
    "ID",
    "citation",
    "link",
    "doi",
    "remarks"
);
fputcsv($refs_out, $refs_fields, "\t", escape: "\\");

// taxa - set up csv file
$taxa_file_path = $downloads_dir . "taxon.tsv";
$taxa_out = fopen($taxa_file_path, 'w'); 
$taxon_fields = array(
    "ID",
    "nameID",
    "parentID",
    "accordingToID",
    "scrutinizer",
    "scrutinizerID",
    "scrutinizerDate",
    "referenceID",
    "link"
);
fputcsv($taxa_out, $taxon_fields, "\t", escape: "\\");

// synonyms - set up csv file
$synonyms_file_path = $downloads_dir . "synonym.tsv";
$synonyms_out = fopen($synonyms_file_path, 'w'); 
$synonym_fields = array(
    "ID",
    "taxonID",
    "nameID",
    "accordingToID",
    "referenceID",
    "link"
);
fputcsv($synonyms_out, $synonym_fields, "\t", escape: "\\");

// type material
$types_file_path = $downloads_dir . "typematerial.tsv";
$types_out = fopen($types_file_path, 'w'); 
$types_fields = array(
    "ID",
    "nameID",
    "citation",
    "link"
);
fputcsv($types_out, $types_fields, "\t", escape: "\\");

$counter = 0;
$offset = 1; // offset is one because the first one is the root

// we have to page because we are doing calls to the identifiers table
// and can't use use-results 
while(true){

    // try and free some memory between pages.
    Taxon::resetSingletons();
    Name::resetSingletons();
    Reference::resetSingletons();

    // debug
    // if($offset > 2000) break;

    $response = $mysqli->query("SELECT * from `names` WHERE `status` != 'deprecated' ORDER BY id LIMIT 10000 OFFSET $offset");
    
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

    // work through the rows in the page
    while($row = $response->fetch_assoc()){

        if($row['id'] == 1) continue; // the root (code) name

        // load the name
        $name = Name::getName($row['id']);
        $name_row = array();

        // fill in some fields
        $name_row['ID'] = $name->getPrescribedWfoId();
        // $name_row['sourceID'] = 'http://www.worldfloraonline.org/'; // FIXME - WHAT IS THIS?

        $identifiers = $name->getIdentifiers();
        $alt_ids = array();
        foreach($identifiers as $identifier){

            foreach($identifier->getValues() as $val){

                // skip the exiting WFO
                if($val == $name->getPrescribedWfoId()) continue;

                // if it is an LSID or http URL we don't escape it
                if(preg_match('/^urn:lsid/', $val) || preg_match('/^http:\/\//', $val) || preg_match('/^https:\/\//', $val) ){
                    $alt_ids[] = $val;
                    continue;
                }

                switch ($identifier->getKind()) {
                    case 'ipni':
                        $alt_ids[] = "ipni:" . $val;
                        break;
                    case 'tpl':
                        $alt_ids[] = "tpl:" . $val;
                        break;
                    case 'wfo':
                        $alt_ids[] = "wfo:" . $val;
                        break;
                    case 'if':
                        $alt_ids[] = "if:" . $val;
                        break;
                    case 'tropicos':
                        $alt_ids[] = "tropicos:" . $val;
                        break;
                    default:
                        //$alt_ids[] = "ten:" . $val;
                        break;
                }

            }

        }

        $name_row["alternativeID"] = implode(',', $alt_ids);
        $name_row["basionymID"] = $name->getBasionym() ? $name->getBasionym()->getPrescribedWfoId() : null;
        $name_row["scientificName"] = trim(strip_tags($name->getFullNameString(false,false)));
        $name_row["authorship"] = $name->getAuthorsString();
        $name_row["rank"] = $name->getRank();

        $this_level = array_search($name->getRank(), array_keys($ranks_table));

        if($this_level > $genus_level){
            
            // we are below genus
            $name_row["uninomial"] = null;
            $name_row["genus"] = $name->getGenusString();

            if($this_level < $species_level){
                // we are above species - e.g. subgenus
                $name_row["infragenericEpithet"] = $name->getNameString();
                $name_row["specificEpithet"] = null;
                $name_row["infraspecificEpithet"] = null;
            }elseif($this_level == $species_level){
                // we are a species
                $name_row["infragenericEpithet"] = null;
                $name_row["specificEpithet"] = $name->getNameString();
                $name_row["infraspecificEpithet"] = null;
            }else{
                // we are below species
                $name_row["infragenericEpithet"] = null;
                $name_row["specificEpithet"] = $name->getSpeciesString();
                $name_row["infraspecificEpithet"] = $name->getNameString();
            }
            
        }else{
            $name_row["uninomial"] = $name->getNameString();
            $name_row["genus"] = null;
            $name_row["infragenericEpithet"] = null;
            $name_row["specificEpithet"] = null;
            $name_row["infraspecificEpithet"] = null;
        }
        
        $name_row["code"] = "botanical";
        $name_row["publishedInYear"] = $name->getYear();
        $name_row["remarks"] = $name->getComment();
        $name_row["link"] = 'https://list.worldfloraonline.org/' . $name->getPrescribedWfoId();

        // is this a taxon or a synonym or unplaced
        $taxon = Taxon::getTaxonForName($name);
        $taxon_row = array();
        $synonym_row = array();
        if($taxon->getId()){

            $qualifier = "-$version";

            // ancestors
            $ancestors = $taxon->getAncestors();
            array_unshift($ancestors,$taxon); // add self



                // accordingToID - same for accepted and synonym

                // "A reference ID to the publication that established the taxonomic concept used by this taxon. 
                // The author & year of the reference will be used to qualify the name with sensu AUTHOR, YEAR. 
                // The ID must refer to an existing Reference.ID within this data package."

                // Work our way up the taxonomy till we find a literature reference
                // Or are above Genus level - we don't assume that a literature ref at family 
                // family and above we a

                // referenceID
                    
                // - for accepted taxon
                // A comma concatenated list of reference IDs supporting the taxonomic concept that has been reviewed 
                // by the scrutinizer. Each ID must refer to an existing Reference.ID 
                // within this data package. 

                // - for synonym
                // A comma concatenated list of reference IDs supporting the synonym status of the name. Each ID must refer to an existing Reference.ID within this data package.
                

            $ref_ids = array();
            $according_to_ref = null;
            $start_level = array_search($name->getRank(), array_keys($ranks_table));
            foreach($ancestors as $anc){


                // we never fill in the code as taxonomic source
                if($anc->getAcceptedName()->getRank() == 'code') break;

                // how far up the ranks are we
                $anc_level = array_search($anc->getAcceptedName()->getRank(), array_keys($ranks_table));

                // get the first taxonomic literature reference if we have one
                $anc_refs = $anc->getAcceptedName()->getReferences();
                foreach($anc_refs as $usage){
                    if($usage->role == 'taxonomic'){

                        // if we have found a literature reference then 
                        // it is the according to
                        if( $usage->reference->getKind() == 'literature'){
                            $according_to_ref = $usage->reference;
                        }

                        // all other references just build up as evidence
                        $ref_ids[] = $usage->reference->getId();

                    }
                }

                if($according_to_ref){
                    break; // our work is done. $according_to_ref will be used below in accepted or synonym row building
                }else{

                    // not found one so we continue up the tree
                    // depending on what rank we started at
                    if($start_level > $genus_level && $anc_level >= $genus_level){
                        // we assume a species or subspecies won't be defined 
                        // by a monograph above the genus level
                        break;
                    }

                    // if we start above genus level but below family level we carry on as far as family
                    // to allow for family monographs
                    if($start_level > $family_level && $start_level < $genus_level && $anc_level >= $family_level){
                        break;
                    }

                    // all other conditions we carry on up till
                    // we get to code when it will stop.

                }
                
            } // end working up ancestors to build refs
            

            if($taxon->getAcceptedName()->getId() == $name->getId()){

                // we are an accepted name - 
                $taxon_row['ID'] = $name->getPrescribedWfoId() . $qualifier;
                $taxon_row['nameID'] =  $name->getPrescribedWfoId();

                // we update the name to the taxon name - because it will contain the hybrid symbol etc - yuck!
                $name_row["scientificName"] = trim(strip_tags($taxon->getFullNameString(false,false)));

                // link
                $taxon_row['link'] = 'https://list.worldfloraonline.org/' .$name->getPrescribedWfoId() . $qualifier;

               // parentage
               if($taxon->getParent()) $taxon_row['parentID'] = $taxon->getParent()->getAcceptedName()->getPrescribedWfoId() . $qualifier;
               else $taxon_row['parentID'] = null;

                // add the refs we collected above
                if($according_to_ref) $taxon_row['accordingToID'] = $according_to_ref->getId();

                $taxon_row['referenceID'] = implode(',',array_unique($ref_ids));
                

                    // Only accepted taxa have scrutinizers

                    // scrutinizer
                    // Name of the person who is the latest scrutinizer who revised or reviewed the taxonomic concept.
                    
                    // scrutinizer_id$scrutinizer_id
                    
                    // Identifier for the scrutinizer. Highly recommended are ORCID ids.

                    // - work up the ancestor tree and find who is responsible for this
                
               $scrutinizer = null;
               $scrutinizer_id = null;
               foreach($ancestors as $anc){

                    // if we reach the root then we default
                    // to wfo plant list 
                    if($anc->getAcceptedName()->getRank() == 'code'){
                        $scrutinizer = "World Flora Online Plant List";
                        $scrutinizer_id = "https://wfoplantlist.org";
                        break;
                    }

                    // do we have a curator yet?
                    $curators = $anc->getCurators();
                    if($curators){
                        // we just take the first as there can only be one
                        $scrutinizer = $curators[0]->getName();
                        $scrutinizer_id = $curators[0]->getOrcidId();
                        break;
                    }

                    // do we have a person taxonomic reference
                    $anc_refs = $anc->getAcceptedName()->getReferences();
                    foreach($anc_refs as $usage){
                        // this is how we flag TENs as being the source
                        if($usage->role == 'taxonomic' && $usage->reference->getKind() == 'person'){
                            $scrutinizer = $usage->reference->getDisplayText() . ": " . $usage->comment;
                            $scrutinizer_id = $usage->reference->getLinkUri();
                        }
                    }

                    if($scrutinizer) break;

               } // work up ancestors looking for scrutinizer

               $taxon_row['scrutinizer'] = $scrutinizer;
               $taxon_row['scrutinizerID'] = $scrutinizer_id;


            }else{
                
                // we are a synonym
                $synonym_row['ID'] = $name->getPrescribedWfoId() . $qualifier;
                $synonym_row['nameID'] =  $name->getPrescribedWfoId();
                $synonym_row['taxonID'] = $taxon->getAcceptedName()->getPrescribedWfoId() . $qualifier;
                $synonym_row['link'] = 'http://list.worldfloraonline.org/' . $name->getPrescribedWfoId() . $qualifier;
                
                // add the refs from above
                if($according_to_ref) $synonym_row['accordingToID'] = $according_to_ref->getId();
                $synonym_row['referenceID'] = implode(',', array_unique($ref_ids));

            }
 
        }

        // now we weave our magic on references
        // we will work through them multiple times
        // pulling out the bits we are interested in
        $refs = $name->getReferences();

        // A name can only have one reference - to the protolog
        //  - if there is a single literature reference we use that
        //  - if there is no literature reference we use the microciation
        $protologues = array();
        $protologue = null;
        $protologue_row = array();
        foreach($refs as $usage){
            if($usage->role == 'nomenclatural' && $usage->reference->getKind() == 'literature'){
               $protologues[] = $usage;
            }
        }
        if(count($protologues) > 1){
            // we have multiple potential ones so just use the first
            // that mentions the word protologue in the comments
            foreach($protologues as $usage){
                if(strpos(strtolower($usage->comment), 'protologue') !== false){
                    $protologue = $usage;
                }
            }
        }elseif(count($protologues) == 1){
            $protologue = $protologues[0]; // only one so assume it is the protologue
        }

        if($protologue){

            // we found a reference usage that is the protologue
            // so we use that 
            $name_row["referenceID"] = $protologue->reference->getId();
            // we don't need to create a reference row 
            // because we dump all references into the references file anyhow.

        }elseif($name->getCitationMicro()){

            // there is no literature reference association with the name 
            // so we use the microcitation - most common case.
            $protologue_row = array();
            $name_row["referenceID"] = $name->getId() . "_mc"; // we cheat and use the names internal ID for the reference
            $protologue_row['ID'] = $name_row["referenceID"]; 
            $protologue_row['citation'] = $name->getCitationMicro();
            $protologue_row['link'] = null;

        }


        // write out the csv rows

        // name out
        $csv_row = array();
        foreach($names_fields as $field){
            if(isset($name_row[$field])){
                $csv_row[] = $name_row[$field];
            }else{
                $csv_row[] = null;
            }
        }
        $csv_row = str_replace("\t", " ", $csv_row); // safety first
        fputcsv($names_out, $csv_row, "\t", escape: "\\");

        // protologue row out if we have created one
        if($protologue_row){
            $csv_row = array();
            foreach($refs_fields as $field){
                if(isset($protologue_row[$field])){
                    $csv_row[] = $protologue_row[$field];
                }else{
                    $csv_row[] = null;
                }
            }
            $csv_row = str_replace("\t", " ", $csv_row); // safety first
            fputcsv($refs_out, $csv_row, "\t", escape: "\\");
        }

        // taxon out
        if($taxon_row){
            $csv_row = array();
            foreach($taxon_fields as $field){
                if(isset($taxon_row[$field])){
                    $csv_row[] = $taxon_row[$field];
                }else{
                    $csv_row[] = null;
                }
            }
            $csv_row = str_replace("\t", " ", $csv_row); // safety first
            fputcsv($taxa_out, $csv_row, "\t", escape: "\\");
        }

        // synonym out
        if($synonym_row){
            $csv_row = array();
            foreach($synonym_fields as $field){
                if(isset($synonym_row[$field])){
                    $csv_row[] = $synonym_row[$field];
                }else{
                    $csv_row[] = null;
                }
            }
            $csv_row = str_replace("\t", " ", $csv_row); // safety first
            fputcsv($synonyms_out, $csv_row, "\t", escape: "\\");
        }

        $counter++;

    } // end row loop


} // paging loop

fclose($names_out);
fclose($taxa_out);
fclose($synonyms_out);



echo "\nWriting References\n";

// we export the people and databases who are associated with taxa
$response = $mysqli->query("SELECT DISTINCT r.* FROM `references` AS r JOIN `name_references` AS nr ON r.id = nr.reference_id WHERE r.kind in ('person', 'database') AND nr.`role` = 'taxonomic';");
while($row = $response->fetch_assoc()){
    
    $ref = array();
    $ref["ID"] = $row['id'];
    $ref["citation"] = $row['display_text'];
    $ref["link"] = $row['link_uri'];
    $ref["remarks"] = ucfirst($row['kind']);

    $csv_row = array();
    foreach($refs_fields as $field){
        if(isset($ref[$field])){
            $csv_row[] = $ref[$field];
        }else{
            $csv_row[] = null;
        }
    }
    $csv_row = str_replace("\t", " ", $csv_row); // safety first
    fputcsv($refs_out, $csv_row, "\t", escape: "\\");

}

// we export all the literature references - may be some extras but will get all protologues
$response = $mysqli->query("SELECT r.* FROM `references` AS r  WHERE r.kind = 'literature';", MYSQLI_USE_RESULT);
while($row = $response->fetch_assoc()){
    
    $ref = array();
    $ref["ID"] = $row['id'];
    $ref["citation"] = $row['display_text'];
    $ref["link"] = $row['link_uri'];
    $ref["remarks"] = ucfirst($row['kind']);

    // match dois and break them out
    $matches = array();
    if(preg_match('/^https:\/\/doi.org\/(.*)/', $row['link_uri'], $matches)){
        $ref["doi"] = $matches[1];
    }

    $csv_row = array();
    foreach($refs_fields as $field){
        if(isset($ref[$field])){
            $csv_row[] = $ref[$field];
        }else{
            $csv_row[] = null;
        }
    }
    $csv_row = str_replace("\t", " ", $csv_row); // safety first
    fputcsv($refs_out, $csv_row, "\t", escape: "\\");

}
$response->close();
fclose($refs_out);


// type material is just exported as a separate job

echo "\nWriting Types\n";

/*
    coldp expects the id of the specimens (link_uri for us) to be unique (poss primary key)
    a type might apply to multiple names so we need to have multivalued
    nameID field.

    The rows are ordered by link_uri so we only right one out when it changes.
*/

$response = $mysqli->query(
    "SELECT i.`value` as wfo, r.* 
    FROM `references` AS r 
    JOIN `name_references` AS nr ON nr.reference_id = r.id
    JOIN `names` AS n ON n.id = nr.name_id AND n.`status` != 'deprecated'
    JOIN `identifiers` AS i ON i.id = n.prescribed_id
    WHERE r.kind = 'specimen'
    AND nr.`role` = 'nomenclatural'
    AND i.kind = 'wfo'
    ORDER BY r.`link_uri`", 
    MYSQLI_USE_RESULT);

$cohort = array();
$last_row = null;
while($row = $response->fetch_assoc()) {

    // if we don't have a cohort we are on the first row so just add it in and start over
    // if we have an ongoing cohort and our link is the same then just continue
    if(!$cohort || $cohort[0]['link_uri'] == $row['link_uri']){
        $cohort[] = $row;
        continue;
    }

    // we get to here so we have a cohort but it is out of date
    // because we have moved on to another link_uri

    // write it out
    write_out_type_row($types_out, $cohort, $types_fields);

    // start a new one
    $cohort = array();
    $cohort[] = $row;

}// while row

// write out the last cohort 
write_out_type_row($types_out, $cohort, $types_fields);

// bad practice defining a function on the fly in global space...
function write_out_type_row($types_out, $cohort, $types_fields){

    // cohort is a batch of rows with the same link_uri (and other data)
    $name_ids = array();
    foreach($cohort as $member) $name_ids[] = $member['wfo'];

    $type = array();
    $type["ID"] = $cohort[0]['link_uri'];
    $type["nameID"] = implode(',', $name_ids);
    $type["citation"] = $cohort[0]['display_text'];
    $type["link"] = $cohort[0]['link_uri'];

    $csv_row = array();
    foreach($types_fields as $field){
        if(isset($type[$field])){
            $csv_row[] = $type[$field];
        }else{
            $csv_row[] = null;
        }
    }
    $csv_row = str_replace("\t", " ", $csv_row); // safety first
    fputcsv($types_out, $csv_row, "\t", escape: "\\");

}

$response->close();
fclose($types_out);

// build the metadata file
require_once('gen_coldp_metadata_json.php');
generate_metadata($downloads_dir . "metadata.json", $pub_date, $version);

// require_once('gen_coldp_metadata_yaml.php');
// generate_metadata($downloads_dir . "metadata.yaml", $pub_date, $version);

echo "\nZipping Up\n";

// wrap them in a zip file
$zip = new ZipArchive();
$zip_path = $downloads_dir . "wfo_plantlist_$version.zip";

if ($zip->open($zip_path, ZIPARCHIVE::CREATE)!==TRUE) {
    exit("cannot open <$zip_path>\n");
}

$zip->addFile($downloads_dir . 'name.tsv', "name.tsv");
$zip->addFile($downloads_dir . 'reference.tsv', "reference.tsv");
$zip->addFile($downloads_dir . 'synonym.tsv', "synonym.tsv");
$zip->addFile($downloads_dir . 'taxon.tsv', "taxon.tsv");
$zip->addFile($downloads_dir . 'typematerial.tsv', "typematerial.tsv");
if(file_exists($downloads_dir . 'metadata.yaml')) $zip->addFile($downloads_dir . 'metadata.yaml', "metadata.yaml");
if(file_exists($downloads_dir . 'metadata.json')) $zip->addFile($downloads_dir . 'metadata.json', "metadata.json");

if ($zip->close()!==TRUE) {
    exit("cannot close <$zip_path>\n". $zip->getStatusString());
}

unset($zip);

echo "Removing temp files\n";
unlink($downloads_dir . 'name.tsv');
unlink($downloads_dir . 'reference.tsv');
unlink($downloads_dir . 'synonym.tsv');
unlink($downloads_dir . 'taxon.tsv');
unlink($downloads_dir . 'typematerial.tsv');
@unlink($downloads_dir . 'metadata.yaml');
@unlink($downloads_dir . 'metadata.json');


// cut and paste utility function
function convert($size){
    $unit=array('b','kb','mb','gb','tb','pb');
    return @round($size/pow(1024,($i=floor(log($size,1024)))),2).' '.$unit[$i];
}