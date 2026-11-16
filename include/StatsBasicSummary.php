<?php

/*

    Wrapper class for calls to get summary statistics

*/

class StatsBasicSummary{

    public ?String $id;
    public ?String $phylum;
    public ?String $phylumWfo = null;
    public ?String $order;
    public ?String $orderWfo = null;
    public ?String $family;
    public ?String $familyWfo = null;
    public ?int $taxa;
    public ?int $withEditors;
    public ?int $synonyms;
    public ?int $unplaced;
    public ?int $genera;
    public ?int $species;
    public ?int $subspecies;
    public ?int $varieties;
    public ?int $gbifGapSpecies;
    public ?int $gbifGapOccurrences;
    
    private function __construct($row){   
        $this->id = $row['id'];
        $this->phylum = $row['phylum'];
        if($this->phylum) $this->phylumWfo = $row['phylum_wfo'];
        $this->order = $row['order'];
        if($this->order) $this->orderWfo = $row['order_wfo'];
        $this->family = $row['family'];
        if($this->family) $this->familyWfo = $row['family_wfo'];
        $this->taxa = $row['taxa'];
        $this->withEditors = $row['with_editors'];
        $this->synonyms = $row['synonyms'];
        $this->unplaced = $row['unplaced'];
        $this->genera = $row['genera'];
        $this->species = $row['species'];
        $this->subspecies = $row['subspecies'];
        $this->varieties = $row['varieties'];
        $this->gbifGapSpecies = $row['gbif_gap_species'] == null ? 0 :  $row['gbif_gap_species'];
        $this->gbifGapOccurrences = $row['gbif_gap_total_occurrences'] == null ? 0 :  $row['gbif_gap_total_occurrences'];
    }

    /**
     * 
     * @return List of Stats objects with the data in
     */
    public static function getStats(){

        global $mysqli;

        $out = array();

        $sql = "SELECT 
            md5(concat_ws('-', `phylum`,`order`,`family` ) ) as id,
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
            group by `phylum`, `order`, `family` WITH ROLLUP;";

        $response = $mysqli->query($sql);

        while($row = $response->fetch_assoc()){
            $out[] = new StatsBasicSummary($row);
        }

        return $out;

    }

}
