<?php

/*

Generates the metadata for upload to Zenodo

*/

require_once("../config.php");
require_once("../include/WfoDbObject.php");
require_once("../include/Name.php");
require_once("../include/Taxon.php");
require_once("../include/User.php");
require_once("../include/UpdateResponse.php");

// we need to have a mock session  
$_SESSION['user'] = serialize(User::loadUserForDbId(1));

$metadata = json_decode(file_get_contents("zenodo_metadata.json"));

$metadata->description = file_get_contents("zenodo_description.html");

echo "\nZenodo Metadata Generation\n";

$contributors = array();

/*
* name: Name of creator in the format Family name, Given names
* type: Contributor type. Controlled vocabulary (ContactPerson, DataCollector, DataCurator, DataManager,Distributor, Editor, HostingInstitution, Producer, ProjectLeader, ProjectManager, ProjectMember, RegistrationAgency, RegistrationAuthority, RelatedPerson, Researcher, ResearchGroup, RightsHolder,Supervisor, Sponsor, WorkPackageLeader, Other)
* affiliation: Affiliation of creator (optional).
* orcid: ORCID identifier of creator (optional).
* gnd: GND identifier of creator (optional).
*/


// get all the users as contributors and calculate their names.
$users = User::getPossibleEditors();
$contributors = array();
foreach($users as $user){
    if($user->isEditor() && $user->getOrcidId() && $user->getTaxaCurated()){
        $meta = array();
       // $meta['type'] = "DataCurator";
        $meta['orcid'] = $user->getOrcidId();
        $parts = explode(' ', trim($user->getName()));
        $family = mb_ucfirst(mb_strtolower(array_pop($parts)));

        // We need to get capitalisation consistent in given names
        // e.g. Ardi, Wisnu handoyo or Govaerts, Rafaël herman anna
        // should be Ardi, Wisnu Handoyo or Govaerts, Rafaël Herman Anna

        // give name might be in the form of initials J.G. or R.R.
        $given = implode(' ', $parts);
        $given = str_replace('.', '. ', $given); // make initials look like words
        $given =  mb_convert_case($given, MB_CASE_TITLE);
        $given = str_replace('. ', '.', $given); // remove space between initials we just added

        // Beware of 'de la' etc.
        $given = preg_replace('/ De La$/', ' de la', $given);
        
        $meta['name'] =   "$family, $given";
        $contributors[$meta['name']] = $meta;
    }
}

// sort them by family
ksort($contributors);

// add them in in the correct order - everyone is a creator these days!
$lead_creators = $metadata->creators;
foreach($contributors as $name => $meta){

    $meta = (object)$meta;

    foreach($lead_creators as $leader){
        if(!isset($leader->orcid)) continue; // wfo doesn't have an ORCID
        if($meta->orcid == $leader->orcid) continue 2;
    }

    $metadata->creators[] = $meta;
}

// now for the TENs
$response = $mysqli->query("
SELECT distinct r.id, r.display_text
FROM `references` AS r
JOIN `name_references` AS nr ON nr.reference_id = r.id
WHERE nr.`role` = 'taxonomic'
AND r.kind = 'person'
");

while($row = $response->fetch_assoc()){

        $meta = array();
        $meta['type'] = "DataCurator";
        $meta['name'] = str_replace('TEN', 'Taxonomic Expert Network', $row['display_text']);
        $metadata->contributors[] = $meta;
}


// write it out
$destination = "../data/versions/zenodo_metadata.json";
file_put_contents($destination, json_encode($metadata, JSON_PRETTY_PRINT));
echo "\nWritten: $destination\n";


// needed to handle multibyte chars and upper casing the first
if (!function_exists('mb_ucfirst')) {
    function mb_ucfirst($str) {
        $fc = mb_strtoupper(mb_substr($str, 0, 1));
        return $fc.mb_substr($str, 1);
    }
}