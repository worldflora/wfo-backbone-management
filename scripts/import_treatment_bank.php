<?php
/*

    This is a run once script to import the initial treatment back stuff

    - completely changed from first implementation
    // work backwards day at a time until we get to something we already have?

    https://tb.plazi.org/GgServer/srsStats/stats?outputFields=doc.uuid+doc.uploadDate+doc.updateDate+lnk.doi+lnk.httpUri+pubLnk.articleDoi+tax.name+tax.rank+tax.kingdomEpithet+tax.phylumEpithet+tax.classEpithet+tax.orderEpithet+tax.familyEpithet+tax.genusEpithet+tax.speciesEpithet+tax.authority+tax.colId&groupingFields=doc.uuid+doc.uploadDate+doc.updateDate+lnk.doi+lnk.httpUri+pubLnk.articleDoi+tax.name+tax.rank+tax.kingdomEpithet+tax.phylumEpithet+tax.classEpithet+tax.orderEpithet+tax.familyEpithet+tax.genusEpithet+tax.speciesEpithet+tax.authority+tax.colId&orderingFields=tax.name&FP-doc.uploadDate=%222024-01-12%22&FP-tax.kingdomEpithet=Plantae&format=TSV

    nohup php import_treatment_bank.php &


    To watch progress...
    egrep -i '^[0-9]{4}' nohup.out


*/

require_once('../config.php');
require_once('../include/WfoDbObject.php');
require_once('../include/Name.php');
require_once('../include/User.php');
require_once('../include/UpdateResponse.php');
require_once('../include/Reference.php');
require_once('../include/ReferenceUsage.php');
require_once('../include/NameMatcher.php');
require_once('../include/NameMatcherPlantList.php');
require_once('../include/NameMatches.php');


// we need to have a mock session  
$_SESSION['user'] = serialize(User::loadUserForDbId(1));

$treatment_bank_uri = "https://tb.plazi.org/GgServer/srsStats/stats?outputFields=doc.uuid+doc.uploadDate+doc.updateDate+lnk.doi+lnk.httpUri+pubLnk.articleDoi+tax.name+tax.rank+tax.kingdomEpithet+tax.phylumEpithet+tax.classEpithet+tax.orderEpithet+tax.familyEpithet+tax.genusEpithet+tax.speciesEpithet+tax.authority+tax.colId&groupingFields=doc.uuid+doc.uploadDate+doc.updateDate+lnk.doi+lnk.httpUri+pubLnk.articleDoi+tax.name+tax.rank+tax.kingdomEpithet+tax.phylumEpithet+tax.classEpithet+tax.orderEpithet+tax.familyEpithet+tax.genusEpithet+tax.speciesEpithet+tax.authority+tax.colId&orderingFields=-doc.uploadDate&FP-tax.kingdomEpithet=Plantae&limit=10000&format=json&FP-doc.uploadDate=";

// first ever upload is 2009-05-26
//$day = new DateTime();
$day = new DateTime('2020-05-09');
$stop = new DateTime('2009-05-25');

$out = fopen('../data/treatments/treatment_bank_no_match.csv', 'w'); // FIXME - in production set this to append?

$matcher = new NameMatcherPlantList();

while($day > $stop){

    $day_string = $day->format('Y-m-d');
    echo "\n$day_string";
    $query_uri = $treatment_bank_uri . '%22'. $day_string .'%22';
    //echo "\t$query_uri\n";
    $json = file_get_contents($query_uri);
    $response = json_decode($json);
    echo "\t" . count($response->data) . " treatments uploaded this day.\n\n";

    // work through the data and try and match them
    foreach($response->data as $treatment){

        echo "\n\t$treatment->TaxName\n";
        echo "\t" . $treatment->LnkHttpUri . "\n";
        echo "\t" . $treatment->PubLnkArticleDoi . "\n";

        // they include the microcitation in the authority
        // separated by comma. This will mess up names
        // with multiple authors that contain commas will be truncated
        // but overall will get better matches? 
        $name_string = trim(explode(",", $treatment->TaxName)[0]);
        echo "\t$name_string\n";

        // there is the issue with the spaces after dots in author strings
        $name_string = preg_replace('/( [A-Z]\.) /', '$1', $name_string);

        // sometimes they abbreviate the genus in the taxname
        if(preg_match('/^[A-Z]{1}\./ ', $name_string)){
            $name_string =  $treatment->TaxGenusEpithet . substr($name_string, 2);
        }

        // try and match the name.
        $matches = $matcher->stringMatch($name_string);
        if(count($matches->names) != 1){
            echo "-\t" . count($matches->names) . " names found.\n";
            fputcsv($out, array($day_string, $treatment->TaxName, count($matches->names), $treatment->LnkHttpUri, $treatment->PubLnkArticleDoi), escape: "\\");
            continue;
        }

        // we have a name match to play with
        $name = $matches->names[0];
        echo "\t" . strip_tags($name->getFullNameString()) . "\n";
        echo "+\t" . $name->getPrescribedWfoId() . "\n";

        echo "\t--- doing treatmentbank ref ---\n";
        $treatment_xml_uri = "https://tb.plazi.org/GgServer/xml/" . $treatment->DocUuid;
        $treatment_xml = file_get_contents($treatment_xml_uri);
        $treatment_doc = new SimpleXMLElement($treatment_xml);
        $treatment_doc->registerXPathNamespace("mods", "http://www.loc.gov/mods/v3");

        if(isset($treatment_doc->xpath("/document/mods:mods/mods:classification")[0])) $treatment_kind = $treatment_doc->xpath("/document/mods:mods/mods:classification")[0];
        else $treatment_kind = '';
        echo "\tKind: " . $treatment_kind . "\n";

        if(isset($treatment_doc->xpath("/document/mods:mods/mods:titleInfo/mods:title")[0])) $treatment_title = $treatment_doc->xpath("/document/mods:mods/mods:titleInfo/mods:title")[0];
        else $treatment_title = '';

        echo "+\t" . $treatment_title . "\n";

        // we can make a record using the LnkHttpUri
        // do we have a reference for this uri?
        $ref = Reference::getReferenceByUri($treatment->LnkHttpUri);
        if(!$ref){
            // no ref so create it
            $ref = Reference::getReference(null);
            $ref->setKind('treatment');
            $ref->setLinkUri($treatment->LnkHttpUri);
            $ref->setDisplayText("Parsed text from $treatment_kind \"{$treatment_title}\" uploaded to TreatmentBank on $day_string.");  
            $ref->setUserId(1);
            $ref->save();
            echo "\tCreated:\t" . $ref->getId() . "\n";
        }else{
            echo "\tExists:\t" . $ref->getId() . "\n";
        }

        // is it already attached?
        $already_there = false; 
        foreach($name->getReferences() as $usage){
            if($usage->reference->getId() == $ref->getId()){
                $already_there = true;
                break;
            }
        }

        // do we need to attach it to the name?
        if(!$already_there){
            $name->addReference($ref, "From Plazi TreatmentBank", false);
            echo "\tAdded\n";
        }else{
            echo "\tAlready present\n";
        }


        // now do we have a doi for this thing?
        $doi_match = array();
        if($treatment->PubLnkArticleDoi && preg_match('/(10\.[0-9]+\/.+)/', $treatment->PubLnkArticleDoi, $doi_match)){

            echo "\t--- doing doi ref ---";

            // our doi uris look like this 'https://doi.org/10.11646/phytotaxa.19.1.3'

            $doi = 'doi:' . $doi_match[0];
            echo "\n\t{$doi}";
            $uri = 'https://doi.org/' . $doi_match[0];
            echo "\n\t{$uri}";

            // as before we create the reference if needed
            $ref = Reference::getReferenceByUri($uri);

            if(!$ref){
                // no ref so create it
                $display = fetch_citation($uri, $doi);

                if(!$display){
                    echo "\n\tNo citation retrieved.";
                    continue;
                }

                echo "\n$display";

                $ref = Reference::getReference(null);
                $ref->setKind('treatment');
                $ref->setLinkUri($uri);
                $ref->setDisplayText($display); // truncated at 1000  
                $ref->setUserId(1);
                $update_response = $ref->save();
                if(!$update_response->success){
                    print_r($update_response);
                    exit;
                }
                echo "\n\tDOI Created:\t" . $ref->getId();
            }else{
                echo "\n\tDOI Exists:\t" . $ref->getId();
            }

            // is it already attached?
            $already_there = false; 
            foreach($name->getReferences() as $usage){
                if($usage->reference->getId() == $ref->getId()){
                    $already_there = true;
                    break;
                }
            }

            // do we need to attach it to the name?
            if(!$already_there){
                $name->addReference($ref, "DOI link from Plazi TreatmentBank.", false);
                echo "\n\tDOI Added";
            }else{
                echo "\n\tDOI Already present";
            }

        } // if DOI present


    } // loop through treatments of day


/*

a treatment looks like this

            [DocCount] => 1
            [DocUuid] => FAA807C5B8245B66B9A35FC386317F3D
            [DocUploadDate] => 2024-03-02
            [DocUpdateDate] => 2024-03-02
            [LnkDoi] => 
            [LnkHttpUri] => https://treatment.plazi.org/id/FAA807C5-B824-5B66-B9A3-5FC386317F3D
            [PubLnkArticleDoi] => http://dx.doi.org/10.3897/phytokeys.239.113017
            [TaxName] => Ridsdalea daweishanensis (Y.M.Shui & W.H.Chen) J.T.Pereira (in W
            [TaxRank] => species
            [TaxKingdomEpithet] => Plantae
            [TaxPhylumEpithet] => Tracheophyta
            [TaxClassEpithet] => Magnoliopsida
            [TaxOrderEpithet] => Gentianales
            [TaxFamilyEpithet] => Rubiaceae
            [TaxGenusEpithet] => Ridsdalea
            [TaxSpeciesEpithet] => daweishanensis
            [TaxAuthority] => (Y. M. Shui & W. H. Chen) J. T. Pereira (in Wong and Pereira 201
            [TaxColId] => 8X4P8

*/

    // go back a day and loop again
    $day->modify('-1 day');

} // looping through days.

fclose($out);

function fetch_citation($uri, $doi){

    // curl -LH "Accept: text/x-bibliography; style=apa" https://doi.org/10.9735/0976-9889.5.1.35-38
    $ch = curl_init($uri);

    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); 
    curl_setopt($ch, CURLOPT_TIMEOUT, 300); //timeout in seconds

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Accept: text/x-bibliography; style=apa"
    ));

    //curl_setopt($ch, CURLOPT_HEADER, 1);
    $citation = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    // filter out things that don't look good    
    if(!$citation) return null;
    if($code == 200){

        // they send json we use the doi
        if(preg_match('/^{/', $citation)) return $doi;

        // they send HTML we use the doi
        if(preg_match('/<html/', $citation)) return $doi;

        // clean up the string 
        $citation = str_replace('&Apos;', '&apos;', $citation);
        $citation = html_entity_decode($citation, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $citation = strip_tags($citation);

        // max length
        if(strlen($citation) > 1000) $citation = substr($citation, 0, 995) . " ...";
        
        // OK we have a string that looks good return that
        return $citation;
    
    }else{
        return null;
    }


}