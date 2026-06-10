<?php

require_once('../config.php');

// if this is run as itself then generate a file in the data director
if($argv[0] == "gen_coldp_metadata_yaml.php"){

    // date of dump must be passed in.
    if(count($argv) < 2 || !preg_match('/[0-9]{4}-[0-9]{2}-[0-9]{2}/', $argv[1]) ){
        echo "\nYou must provide a publish date in the format 2023-06-21\n";
        exit;
    }

    $pub_date = $argv[1];
    $version = substr($pub_date, 0, 7);

    generate_metadata("../data/coldp_metadata.yaml", $pub_date, $version);
}

function generate_metadata($file_path, $pub_date, $version){

    global $mysqli;
    global $argv;

    // get the editors in Rhakhis
    $sql = "SELECT u.`name` as user_name, u.orcid_id as orcid, n.name_alpha as taxon_name  
    FROM users_taxa as ut
    join taxa as t on ut.taxon_id = t.id
    join users as u on u.id = ut.user_id
    join taxon_names as tn on tn.id = t.taxon_name_id
    join `names` as n on tn.name_id = n.id
    order by u.orcid_id";
    $response = $mysqli->query($sql);
    $users = array();
    while($row = $response->fetch_assoc()){

        if(isset($users[$row['orcid']])){
            $users[$row['orcid']]['taxa'][] = $row['taxon_name'];
        }else{

            $parts = explode(' ', $row['user_name']);
            $family = mb_ucfirst(mb_strtolower(array_pop($parts)));
            $given =  mb_ucfirst(mb_strtolower(implode(' ', $parts)));

            $users[$row['orcid']] = array(
                'sort_name' => $family . ' ' . $given,
                'orcid' => $row['orcid'],
                'given' => $given,
                'family' => $family,
                'taxa' => array($row['taxon_name'])
            );
        }
    }

    // sort them alphabetically - this destroys the orcids as keys
    usort($users, function ($a, $b) {
    return strcmp(strtolower($a['sort_name']), strtolower($b['sort_name']));
    } );

    // now we have a list of users write them out in yaml style
    $editors = array();
    foreach($users as $user){

        $editor = $user;
        unset($editor['sort_name']);

        sort($user['taxa']);
        $last = array_pop($user['taxa']);
        if ($user['taxa']) {
            $taxon_list = implode(', ', $user['taxa']) . ' and ' . $last;
        }else{
            $taxon_list = $last;
        }
        $editor['note'] = "Curator of $taxon_list in the Rhakhis editor.";

        unset($editor['taxa']);
        $editors[] = $editor;

    }

    // manually generated YAML rather than just use JSON in a YAML file that doesn't seem to work
    // this syntax is based on the successful yaml downloaded from the last release
    $editors_yaml = "";
    foreach ($editors as $editor) {
        $editors_yaml .= "\n -";
        foreach($editor as $key => $val){
            $editors_yaml .= "\n  $key: $val";
        }
    }

    // get a list of all the TENs to add to the metadata file.
    $sql = "SELECT link_uri as `url`, display_text as `organisation`
    FROM `references` AS r
    JOIN `name_references` AS nr ON nr.reference_id = r.id AND nr.`role` = 'taxonomic'
    WHERE r.kind = 'person'
    group by link_uri, display_text";

    $response = $mysqli->query($sql);
    $contributors_yaml = "";
    while($row = $response->fetch_assoc()){
        $contributors[] = $row;
        $contributors_yaml .= "\n -";
        $contributors_yaml .= "\n  url: {$row['url']}";
        $contributors_yaml .= "\n  organisation: {$row['organisation']}";
    }

    // write the metadata.yaml file
    $meta = file_get_contents('coldp.yaml');
    $meta = str_replace('{{editors}}', $editors_yaml, $meta);
    $meta = str_replace('{{contributors}}', $contributors_yaml, $meta);
    $meta = str_replace('{{date}}',$pub_date, $meta);
    $meta = str_replace('{{version}}',$version, $meta);

    // we can pass a variable to indicate this is not a final release
    if(count($argv) > 2) $release = $argv[2];
    else $release = "";
    $meta = str_replace('{{release}}',$release, $meta);

    file_put_contents($file_path, $meta);
}

// needed to handle multibyte chars and upper casing the first
if (!function_exists('mb_ucfirst')) {
    function mb_ucfirst($str) {
        $fc = mb_strtoupper(mb_substr($str, 0, 1));
        return $fc.mb_substr($str, 1);
    }
}