<?php

require_once("../config.php");
require_once('../include/DownloadFile.php');

$downloads_dir = '../www/downloads/stats/';
if (!file_exists($downloads_dir)) {
    mkdir($downloads_dir, 0777, true);
}


// Complete dump of genus based stats table

echo "\nGenerating stats_by_genus\n";
generate_stats_by_genus(false, $downloads_dir);

echo "\nGenerating stats_by_genus_history\n";
generate_stats_by_genus(true, $downloads_dir);

function generate_stats_by_genus($include_historic, $downloads_dir){

    global $mysqli;


    $out_path = $downloads_dir . "stats_by_genus.csv";
    if($include_historic) $out_path = $downloads_dir . "stats_by_genus_history.csv";

    $out = fopen($out_path, 'w');
    $result  = $mysqli->query("SELECT * FROM `stats_genera`;");

    // header rows
    $headers = array();
    $finfo = $result->fetch_fields();
    foreach ($finfo as $field) {
        $headers[] = $field->name;
    }
    fputcsv($out, $headers, escape: "\\");

    // every row
    $count = 0;
    while($row = $result->fetch_assoc()){
        fputcsv($out, $row, escape: "\\");
        $count++;
    }

    // if we are including he historic records
    if($include_historic){
        $result->close();
        $result  = $mysqli->query("SELECT * FROM `stats_genera_log`;");
        while($row = $result->fetch_assoc()){
            fputcsv($out, $row, escape: "\\");
            $count++;
        }
    }

    $count = number_format($count, 0);

    fclose($out);

    // gzip it (will remove original)
    exec("gzip -f $out_path");

    // we add a sidecar describing the file
    $meta = array();
    $meta['filename'] = $out_path . '.gz';
    $now = new DateTime();
    $meta['created'] = $now->format(DateTime::ATOM);
    if($include_historic){
        $meta['title'] = "Stats by genus name including historic records";
        $meta['description'] = "Statistics based on counting by genus names and the taxa, synonyms and unplaced names associated with them. There are multiple rows per genus name, one for each time the stats were generated, $count data rows in total plus a header.";
    }else{
        $meta['title'] = "Stats by genus name";
        $meta['description'] = "Statistics based on counting by genus names and the taxa, synonyms and unplaced names associated with them. There is a single row per genus name containing the latest stats, $count data rows in total plus a header.";
    }
     $meta['size_bytes'] = filesize($out_path . '.gz');
    $meta['size_human'] = DownloadFile::humanFileSize($meta['size_bytes']);
    file_put_contents($out_path . '.gz.json', json_encode($meta, JSON_PRETTY_PRINT));


} // stats by genus


// ----------- Genera Summary Table ---------------

echo "\nGenerating stats_by_genus_summary\n";

$out_path = $downloads_dir . "stats_by_genus_summary.csv";
$out = fopen($out_path, 'w');

$sql = "SELECT 
    md5(concat_ws('-', `phylum`,`order`,`family` ) ) as row_id,
    `phylum`,
    max(`phylum_wfo`) as phylum_wfo,
    `order`,
    max(`order_wfo`) as order_wfo,
    `family`,
    max(`family_wfo`) as family_wfo,
    sum(taxa) as taxa,
    sum(taxa_with_editors) as 'with_editors',
    sum(synonyms) as synonyms,
    sum(unplaced) as 'unplaced',
    count(*) as 'genera',
    sum(species) as 'species',
    sum(subspecies) as 'subspecies',
    sum(variety) as 'varieties',
    sum(gbif_gap_species) as 'gbif_gap_species',
    sum(gbif_gap_total_occurrences) as 'gbif_gap_total_occurrences'
    FROM stats_genera
    group by `phylum`, `order`, `family` WITH ROLLUP; ";

$result  = $mysqli->query($sql);

// header rows
$headers = array();
$finfo = $result->fetch_fields();
foreach ($finfo as $field) {
    $headers[] = $field->name;
}
fputcsv($out, $headers, escape: "\\");

// every row
$count = 0;
while($row = $result->fetch_assoc()){
    fputcsv($out, $row, escape: "\\");
    $count++;
}
$count = number_format($count, 0);

fclose($out);

// gzip it (will remove original)
exec("gzip -f $out_path");

// we add a sidecar describing the file
$meta = array();
$meta['filename'] = $out_path . '.gz';
$now = new DateTime();
$meta['created'] = $now->format(DateTime::ATOM);
$meta['title'] = "Summary of stats by genus name";
$meta['description'] = "A summary of statistics based on counting by genus names and the taxa, synonyms and unplaced names associated with them. One row per family plus rollup rows giving totals for orders and phyla, $count rows plus a header.";
$meta['size_bytes'] = filesize($out_path . '.gz');
$meta['size_human'] = DownloadFile::humanFileSize($meta['size_bytes']);
file_put_contents($out_path . '.gz.json', json_encode($meta, JSON_PRETTY_PRINT));


echo "\nAll Done!\n";
