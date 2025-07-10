<?php

/*

    The UI (both Rhakhis and the backend) try to enforce some data integrity rules.
    The DB Schema also tries to prevent bad things happening.
    That doesn't mean bad things don't happen or haven't been imported before.

    This is a selection of scripts that check for integrity failures in the data
    and create CSV files reporting on them in the downloads folder.

    FIXME: you shouldn't be able to have a type specimen and also a basionym.

·         Missing species name for accepted infraspecies
·         Missing species name for synonym infraspecies
·         Missing species name for other infraspecies
·         Missing accepted names in taxon names
·         Duplicate name–author combinations

*/

require_once('../config.php');
require_once('../include/DownloadFile.php');

echo "\nIntegrity Checks\n";

// check the output folder we all use exists
$downloads_dir = '../www/downloads/integrity_reports/';
if (!file_exists($downloads_dir)) {
    mkdir($downloads_dir, 0777, true);
}

// either call a named check or call all of them
if(count($argv) > 1){
    $argv[1]($downloads_dir);
}else{
    echo "Calling all checks.\n";
    check_no_deprecated_in_classification($downloads_dir);
    check_names_of_taxa_correct_status($downloads_dir);
    check_basionyms_not_chained($downloads_dir);
    check_full_name_string_unique($downloads_dir);
    check_homotypic_names_in_same_taxon($downloads_dir);
    check_genus_name_part_matches_parent($downloads_dir);
    check_missing_authors($downloads_dir);
    check_missing_species_name_part($downloads_dir);
    check_missing_children($downloads_dir);
    check_author_string_contains_in($downloads_dir);
    check_author_string_characters($downloads_dir);
    check_name_string_characters($downloads_dir);
    check_mixed_rank_children($downloads_dir);
}


/*
    We should not have names that have the nomenclatural status 'deprecated' in the
    classification.
*/
function check_no_deprecated_in_classification($downloads_dir){

    $sql = "SELECT 
        n.id as name_id, i.`value` as wfo_id, n.`name_alpha` as 'name', n.`authors`, n.`rank`, n.`status`
        FROM `names` as n
        JOIN taxon_names as tn on tn.name_id = n.id
        JOIN taxa AS t ON t.id = tn.taxon_id
        JOIN identifiers as i on n.prescribed_id = i.id
        where n.`status` = 'deprecated';";

    run_sql_check(
        "check_no_deprecated_in_classification", // $name,
        "Deprecated names in classification.", //$title,
        "No deprecated names were found in the classification", // $success,
        "Names with status 'deprecated' should not appear in the classification as either accepted names of taxa or synonyms. ## found.", // $failure,
        $sql,
        $downloads_dir);
}

/*

    Names of taxa should be valid, conserved or sanctioned

*/
function check_names_of_taxa_correct_status($downloads_dir){

    $sql = "SELECT n.id as name_id, i.`value` as wfo_id, n.name_alpha as 'name', n.`status`, n.`year`
            FROM `names` as n
            JOIN taxon_names as tn on tn.name_id = n.id
            JOIN taxa AS t ON t.taxon_name_id = tn.id
            JOIN identifiers as i ON n.prescribed_id = i.id
            where n.`status` not in ('valid', 'conserved', 'sanctioned')
            order by n.`status`";

    run_sql_check(
        "check_names_of_taxa_correct_status", // $name,
        "Names of taxa are correct status.", //$title,
        "All taxon names are valid, conserved or sanctioned.", // $success,
        "Names of taxa should have a nomenclatural status of valid, conserved or sanctioned. Found ## that are not one of these statuses.", // $failure,
        $sql,
        $downloads_dir);
}

/*

    Basionyms shouldn't have basionyms.

*/
function check_basionyms_not_chained($downloads_dir){

    $sql = "SELECT
        com_novs.id as name_id, 
        cni.`value` as 'com_nov_id',
        com_novs.name_alpha as com_novs_name, com_novs.authors as com_novs_authors,
        bi.`value` as basionym_id,
        basionyms.name_alpha as basionym_name, 
        basionyms.authors as basionym_authors,
        cbi.`value` as 'chained_basionym_id',
        chained_basionyms.name_alpha as 'chained_basionym_name',
        chained_basionyms.authors as 'chained_basionym_authors'
        FROM `names` as com_novs 
        JOIN `names` as basionyms on com_novs.basionym_id = basionyms.id
        JOIN `names` AS chained_basionyms on basionyms.basionym_id = chained_basionyms.id
        JOIN identifiers as bi ON basionyms.prescribed_id = bi.id
        JOIN identifiers as cni ON com_novs.prescribed_id = cni.id
        JOIN identifiers as cbi ON chained_basionyms.prescribed_id = cbi.id
        where basionyms.basionym_id is not null";

    run_sql_check(
        "check_basionyms_not_chained", // $name,
        "Basionyms should not have basionyms.", //$title,
        "No basionyms were found to have basionyms.", // $success,
        "Names that are basionyms should not have basionyms themselves. Found ## chained basionyms.", // $failure,
        $sql,
        $downloads_dir);
}

/*

    Homotypic names should be in the same taxon.

*/

function check_homotypic_names_in_same_taxon($downloads_dir){

    $sql = "SELECT 
        com_nov.id as name_id,
        com_nov_i.`value` as com_nov_id,
        com_nov.name_alpha as com_nov_name,
        basionym_i.`value` as basionym_id,
        basionym.name_alpha as basionym_name
        FROM `names` AS basionym
        JOIN `names` AS com_nov on com_nov.basionym_id = basionym.id
        JOIN taxon_names as basionym_tn on basionym_tn.name_id = basionym.id
        JOIN taxon_names as com_nov_tn ON com_nov_tn.name_id = com_nov.id
        JOIN identifiers as basionym_i on basionym.prescribed_id = basionym_i.id
        JOIN identifiers as com_nov_i on com_nov.prescribed_id = com_nov_i.id
        WHERE basionym_tn.taxon_id != com_nov_tn.taxon_id";

    run_sql_check(
        "check_homotypic_names_in_same_taxon", // $name,
        "Homotypic names should be in the same taxon.", //$title,
        "No homotypic name pairs were split across taxa were found.", // $success,
        "Names that share a type should be in the same taxon as their placement is based on the type. Found ## conflicting homotypic placements.", // $failure,
        $sql,
        $downloads_dir);
}

/*

    full name strings should be unique

*/
function check_full_name_string_unique($downloads_dir){

    $sql = "SELECT replace(deduplication, '~', ' ') as duplication_string, count(*) as 'number_of_instances'
        from `names` 
        group by deduplication
        having count(*) > 1 
        order by count(*) desc, deduplication";


    run_sql_check(
        "check_full_name_string_unique", // $name,
        "Full name strings should be unique.", //$title,
        "No repeating full name strings were found.", // $success,
        "Full name strings (including authors and rank) should be unique or made unique by tweaking authors string. Found ## that repeat.", // $failure,
        $sql,
        $downloads_dir);

}

function check_genus_name_part_matches_parent($downloads_dir){

    $sql = "SELECT 
        n.id as name_id, 
        i.`value` as wfo_id,
        n.name_alpha, n.genus,
        parent_n.name_alpha as parent_name, parent_n.genus as parent_genus_part
        FROM `names` AS n 
        JOIN taxon_names as tn on tn.name_id = n.id
        JOIN taxa as t on t.taxon_name_id = tn.id
        JOIN taxa as parent_t on parent_t.id = t.parent_id
        JOIN taxon_names as parent_tn on parent_tn.id = parent_t.taxon_name_id
        JOIN `names` as parent_n on parent_n.id = parent_tn.name_id
        JOIN identifiers as i on i.id = n.prescribed_id 
        WHERE length(n.genus) > 0
        AND parent_n.`name` != n.genus AND (parent_n.genus is null OR parent_n.genus != n.genus);";

    run_sql_check(
        "check_genus_name_part_matches_parent", // $name,
        "Genus name part should match parent name.", //$title,
        "No names were found it taxonomy that have the wrong genus part for their placement.", // $success,
        "The genus part of a species (or other lower rank) should be appropriate for the taxon in which it is placed e.g. the genus. Found ## that don't match.", // $failure,
        $sql,
        $downloads_dir);

}

function check_species_name_part_matches_parent($downloads_dir){

    $sql = "SELECT 
        n.id as name_id, 
        i.`value` as wfo,
        n.name_alpha, 
        n.species,
        parent_n.name_alpha as parent_name, parent_n.species as parent_species_part
        FROM `names` AS n 
        JOIN taxon_names as tn on tn.name_id = n.id
        JOIN taxa as t on t.taxon_name_id = tn.id
        JOIN taxa as parent_t on parent_t.id = t.parent_id
        JOIN taxon_names as parent_tn on parent_tn.id = parent_t.taxon_name_id
        JOIN `names` as parent_n on parent_n.id = parent_tn.name_id
        JOIN identifiers as i on i.id = n.prescribed_id 
        WHERE length(n.species) > 0
        AND parent_n.`name` != n.species AND (parent_n.species is null OR parent_n.species != n.species);";

    run_sql_check(
        "check_species_name_part_matches_parent", // $name,
        "Species name part should match parent name.", //$title,
        "No names were found it taxonomy that have the wrong species part for their placement.", // $success,
        "The species part of a subspecies (or other lower rank) should be appropriate for the species in which it is placed. Found ## that don't match.", // $failure,
        $sql,
        $downloads_dir);

}

function check_missing_authors($downloads_dir){

    $sql = "SELECT n.id as name_id, i.`value` as 'wfo_id', n.name_alpha as 'name', n.`rank` as 'rank', n.`status` as 'nomenclatural_status', 
            if(
                tn.id, 
                if(t.taxon_name_id = tn.id, 'accepted', 'synonym'),
                'unplaced'
            )
            as 'taxonomic_role', n.authors
            FROM `names` as n 
            join identifiers as i on n.prescribed_id = i.id and i.kind = 'wfo'
            left JOIN taxon_names as tn on n.id = tn.name_id
            left join taxa as t on t.id = tn.taxon_id
            where length(n.authors) < 2 
            and n.`name` != n.`species`
            and n.`status` != 'deprecated';";

    run_sql_check(
        "check_missing_authors", // $name,
        "Names should have an author string of at least two characters unless they are autonyms or deprecated.", //$title,
        "No names were found that lack author strings when they should have them.", // $success,
        "Names were found that lack author strings.", // $failure,
        $sql,
        $downloads_dir);

}

function check_missing_species_name_part($downloads_dir){

    $sql = "SELECT n.id as name_id, i.`value` as wfo_id, n.genus, n.species, n.`name` as `infraspecific_name`, n.`rank`, n.`status` as 'nomenclatural_status', 
            if(
                tn.id, 
                if(t.taxon_name_id = tn.id, 'accepted', 'synonym'),
                'unplaced'
            )
            as 'taxonomic_role', n.authors
            FROM `names` as n 
            join identifiers as i on n.prescribed_id = i.id and i.kind = 'wfo'
            left JOIN taxon_names as tn on n.id = tn.name_id
            left join taxa as t on t.id = tn.taxon_id
            where n.`rank` in ('subspecies', 'prole', 'variety', 'subvariety', 'form', 'subform', 'lusus')
            and length(n.`species`) < 2
            and n.`status` != 'deprecated';";

    run_sql_check(
        "check_missing_species_name_part", // $name,
        "Names below the rank of species should have a species part to their name.", //$title,
        "No infraspecific names were found that lacked a species part.", // $success,
        "Infraspecific names were found that lack a species part.", // $failure,
        $sql,
        $downloads_dir);

}

function check_missing_children($downloads_dir){

    $sql = "SELECT n.id as name_id, i.`value` as wfo_id, n.name_alpha, n.`rank`
            FROM `names` as n 
            join identifiers as i on n.prescribed_id = i.id and i.kind = 'wfo'
            left JOIN taxon_names as tn on n.id = tn.name_id
            left JOIN taxa as t on t.id = tn.taxon_id
            left JOIN taxa as kid on kid.parent_id = t.id
            where n.`rank` not in ('species', 'subspecies', 'prole', 'variety', 'subvariety', 'form', 'subform', 'lusus')
            and t.taxon_name_id = tn.id
            and kid.id is null";

    run_sql_check(
        "check_missing_children", // $name,
        "Accepted taxa above the rank of species should have child taxa or there is no reason for them to exist.", //$title,
        "No taxa were found above species level that lack child taxa.", // $success,
        "Taxa above species level were found that lack children.", // $failure,
        $sql,
        $downloads_dir);

}

function check_author_string_contains_in($downloads_dir){

    $sql = "SELECT
            n.id as name_id,
            i.`value`,
            authors,
            `status`
            FROM `names` as n
            JOIN identifiers as i on n.prescribed_id = i.id and i.kind = 'wfo'
            WHERE  
                authors like '%.in %'
                or authors like '%,in %'
                or authors like '% in %'
                or authors like '% in,%'
                or authors like '% in.%'
                and `status` != 'deprecated'";

    run_sql_check(
        "check_author_string_contains_in", // $name,
        "Author strings should not contain ' in '.", //$title,
        "No names were found with in in their author strings.", // $success,
        "Names were found with in in their author strings.", // $failure,
        $sql,
        $downloads_dir);

}


function check_author_string_characters($downloads_dir){

    $sql = "SELECT
        n.id as name_id,
        i.`value`,
        authors,
        if( authors like '%.ex %', 'dot-ex-space', if(authors like '% ex.%', 'space-ex-dot',  if(authors like '%. ,%', 'dot-space-comma', 'dot-ampersand') ) ) as 'error'
        FROM `names` as n
        JOIN identifiers as i on n.prescribed_id = i.id and i.kind = 'wfo'
        WHERE  
            authors like '%.ex %'
            or authors like '% ex.%'
            or authors like '%. ,%'
            or authors like '%.&%'";

    run_sql_check(
        "check_author_string_characters", // $name,
        "Certain combinations of characters in author strings are errors e.g. a dot before an ampersand.", //$title,
        "No names were found with incorrect character combinations.", // $success,
        "Names were found with suspicious character combinations.", // $failure,
        $sql,
        $downloads_dir);

}


function check_name_string_characters($downloads_dir){

    $sql = "SELECT
        n.id as name_id,
        i.`value`,
        n.`genus`,
        n.`species`,
        n.`name`,
        n.`status`
        FROM `names` as n
        JOIN identifiers as i on n.prescribed_id = i.id and i.kind = 'wfo'
        WHERE  
			(
			regexp_like(`name`, '[^A-Za-z-]')
            or
            regexp_like(`species`, '[^A-Za-z-]')
            or
            regexp_like(`genus`, '[^A-Za-z-]')
            )
            and n.`status` != 'deprecated'
		order by n.name_alpha";

    run_sql_check(
        "check_name_string_characters", // $name,
        "Only a limited number of characters (alpha numerics plus -) are permitted in the name parts of non-deprecated names.", //$title,
        "No names were found with incorrect characters.", // $success,
        "Names were found with suspicious characters.", // $failure,
        $sql,
        $downloads_dir);

}

function check_mixed_rank_children($downloads_dir){

    $sql = "with  child_ranks as
            (
            SELECT 
                p.id, n.`rank`
            FROM
                `taxa` as p
            JOIN `taxa` as c on c.parent_id = p.id
            JOIN `taxon_names` as tn on c.taxon_name_id = tn.id
            JOIN `names` as n on tn.name_id = n.id
            group by p.id, n.`rank`),

            messy_taxa as (
            SELECT id as taxon_id, count(*) as rank_count 
            FROM child_ranks
            group by id
            having rank_count > 1
            order by rank_count desc)

            select  n.id as name_id,
                    i.`value`,
                    n.name_alpha,
                    mt.rank_count
            from messy_taxa as mt
            JOIN taxa as t on mt.taxon_id = t.id
            JOIN taxon_names as tn on t.taxon_name_id = tn.id
            JOIN `names` as n on n.id = tn.name_id
            JOIN `identifiers` as i on n.prescribed_id = i.id and i.kind = 'wfo'";

    run_sql_check(
        "check_mixed_rank_children", // $name,
        "Taxa that have child taxa that are of mixed ranks. e.g. a genus with species and subgenera as direct children.", //$title,
        "No taxa were found with mixed rank children.", // $success,
        "Taxa were found with mixed rank children.", // $failure,
        $sql,
        $downloads_dir);

}

/*
    Run a check that expects an empty result set on success.
    It looks for a 'name_id' field in the results and if there is 
    one it replaces it with phylum and family columns.
*/
function run_sql_check($name, $title, $success, $failure, $sql, $downloads_dir){

    global $mysqli;

    echo "Calling: $name\n";

    $response = $mysqli->query($sql);

    // header for the csv
    $header = array();
    foreach ($response->fetch_fields() as $field) $header[] = $field->name;

    // if we have a name_id column replace it with two new ones
    $first_is_name_id = false;
    if($header[0] == 'name_id'){
        $first_is_name_id = true;
        array_shift($header);
        array_unshift($header, 'family');
        array_unshift($header, 'phylum');
    }

    // rows in the csv
    $rows = $response->fetch_all(MYSQLI_ASSOC);
    
    // we write the csv even if it only has the header in it
    $out_file_path = $downloads_dir . $name . '.csv';
    $out = fopen($out_file_path, 'w');
    fputcsv($out, $header, escape: "\\");
    foreach ($rows as $row){

        if($first_is_name_id){
            $name_id = array_shift($row);
            $higher_taxa = get_higher_taxa_for_name_id($name_id);
            array_unshift($row, $higher_taxa['family']);
            array_unshift($row, $higher_taxa['phylum']);
        }

        fputcsv($out, $row, escape: "\\");
    } 
    fclose($out);

    // output the json describing the test
    $meta = array();
    $meta['filename'] = $out_file_path;
    $now = new DateTime();
    $meta['created'] = $now->format(DateTime::ATOM);
    $meta['title'] = $title;
    if(count($rows) > 0){
        $failure = str_replace('##', number_format(count($rows), 0), $failure);
        $meta['description'] = $failure;
    }else{
        $meta['description'] = $success;
    }
    $meta['size_bytes'] = filesize($out_file_path);
    $meta['size_human'] = DownloadFile::humanFileSize($meta['size_bytes']);
    file_put_contents($out_file_path . '.json', json_encode($meta, JSON_PRETTY_PRINT));


}

function get_higher_taxa_for_name_id($name_id){
    
    global $mysqli;

    $out = array('family' => 'unplaced', 'phylum' => 'unplaced'); // set them to null incase we don't find them.

    $sql = "WITH RECURSIVE parentage AS(
		SELECT n.name_alpha, n.`rank`, t.parent_id as parent_id
		FROM `names` as n 
		JOIN taxon_names as tn on tn.name_id = n.id
		JOIN taxa as t on t.taxon_name_id = tn.id
        WHERE n.id = $name_id
    UNION ALL
		SELECT n.name_alpha, n.`rank`, t.parent_id as parent_id
        FROM `names` as n 
		JOIN taxon_names as tn on tn.name_id = n.id
		JOIN taxa as t on t.taxon_name_id = tn.id
        JOIN parentage as p on p.parent_id = t.id
        WHERE t.parent_id is not null AND t.parent_id != t.id
        )
        SELECT * FROM parentage WHERE `rank` in ('family', 'phylum');";

    $response = $mysqli->query($sql);
    $rows = $response->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $row) {
        $out[$row['rank']] = $row['name_alpha'];
    }

    return $out;

}