<?php

/**
 * 
 * Thing with the functionality to suggest 
 * names with a confidence level
 * 
 * 
 */
class UnplacedFinder{


    public $name = null;
    public array $unplacedNames = array();
    public int $totalUnplacedNames = 0;
    public int $offset = 0;
    public int $limit = 0;
    public bool $includeDeprecated = false;

    /**
     * Initializing sets it up and does all the work
     * properties are then populated to be called.
     * 
     */
    public function __construct($id, $offset = 0, $limit = 1000, $include_deprecated = false){

        // load the name
        
        $this->name = Name::getName($id);
        if(!$this->name) return; // cop out if we can't load a name


        // set our params
        $this->offset = $offset;
        $this->limit = $limit;
        $this->includeDeprecated = $include_deprecated;

        // only populate if we are genus below or a family
        if($this->name->getRank() == 'genus' || $this->name->getRank() == 'species' ){
            $this->unplacedBelowGenus();
        }else if($this->name->getRank() == 'family' ){
            $this->unplacedFamily();
        }

    }

    private function unplacedBelowGenus(){

        global $mysqli;

        $sql = " FROM `names` AS n LEFT JOIN `taxon_names` AS tn ON n.id = tn.name_id LEFT JOIN `gbif_occurrence_count` as g on n.id = g.`name_id` WHERE tn.id IS NULL ";

        // add genus 
        if($this->name->getRank() == 'genus'){
            // we are a genus so list names with our name in their genus part
           $sql .= " AND n.genus = '{$this->name->getNameString()}'";
        }else{
            // we are a species so list names with our name in their species part
            // and our genus part in their genus part
            $sql .= " AND n.genus = '{$this->name->getGenusString()}'";
            $sql .= " AND n.species = '{$this->name->getNameString()}'";
        }
 

        // filter for deprecated
        if(!$this->includeDeprecated){
            $sql .= " AND n.`status` != 'deprecated'";
        }

        // do the count
        $count_sql = "SELECT count(*) as num " . $sql;
        $response = $mysqli->query($count_sql);
        if($mysqli->error) error_log($mysqli->error . "\n". $count_sql);
        $row = $response->fetch_assoc();
        $this->totalUnplacedNames = $row['num'];
        $response->close();

        // actually fetch the list - if we have more than 0 in it
        if($this->totalUnplacedNames > 0){
            $sql = "SELECT n.id as id " . $sql . " ORDER BY g.`count` DESC, name_alpha LIMIT " . preg_replace('/[^0-9]/', '', $this->limit) . " OFFSET " . preg_replace('/[^0-9]/', '', $this->offset);
            $response = $mysqli->query($sql);
            if($mysqli->error) error_log($mysqli->error . "\n". $sql);
            while ($row = $response->fetch_assoc()) {
                $this->unplacedNames[] = Name::getName($row['id']);
            }
        }

    }

    public function unplacedFamily(){
       
        global $mysqli;

        if(!$this->name || $this->name->getRank() != 'family'){
            $this->unplacedNames[] = array();
            return;
        }
        
        // we are going to do a recursion down from the family taxon 
        // to get all the generic names that have been placed (either as accepted or as synonyms)
        // we will then get all the unplaced names that have those names as genus parts in their
        // names
        $taxon = Taxon::getTaxonForName($this->name);

        // allow for exclusion of deprecated names.
        $deprecated_clause = '';
        if(!$this->includeDeprecated){
            $deprecated_clause = " AND n.`status` != 'deprecated'";
        }

        $sql_cte = "
            WITH RECURSIVE possible_taxa  AS
            (
                SELECT t.id, n.`name`, n.`rank` 
                FROM taxa as t 
                JOIN taxon_names as tn on t.id = tn.taxon_id
                JOIN `names` as n on tn.name_id = n.id and n.`rank` in ('subfamily', 'supertribe', 'tribe', 'subtribe', 'genus')
                WHERE t.parent_id = {$taxon->getId()}
                UNION ALL
                SELECT t.id, n.`name`, n.`rank` 
                FROM taxa as t 
                JOIN taxon_names as tn on t.id = tn.taxon_id
                JOIN `names` as n on tn.name_id = n.id and n.`rank` in ('subfamily', 'supertribe', 'tribe', 'subtribe', 'genus')
                JOIN possible_taxa as pt on t.parent_id = pt.id
            ),
            just_genera as (
                SELECT distinct(`name`) as 'genus_name' FROM possible_taxa where `rank` = 'genus'
            )
            ";
         
        // count the total number first
        $sql = $sql_cte . "SELECT count(*) as num
                FROM `names` as n
                JOIN just_genera as jg on n.genus = jg.genus_name
                LEFT JOIN `taxon_names` as tn on n.id = tn.name_id
                LEFT JOIN `gbif_occurrence_count` as g on n.id = g.`name_id`
                WHERE tn.id is null
                {$deprecated_clause}";

        $response = $mysqli->query($sql);
        if($mysqli->error) error_log($mysqli->error . "\n". $sql);
        $this->totalUnplacedNames = $response->fetch_assoc()['num'];
        $response->close();

        // fetch just this page or results
        $sql = $sql_cte . "SELECT n.id
                FROM `names` as n
                JOIN just_genera as jg on n.genus = jg.genus_name
                LEFT JOIN `taxon_names` as tn on n.id = tn.name_id
                LEFT JOIN `gbif_occurrence_count` as g on n.id = g.`name_id`
                WHERE tn.id is null
                {$deprecated_clause} 
                ORDER BY n.name_alpha
                LIMIT " . preg_replace('/[^0-9]/', '', $this->limit) . " OFFSET " . preg_replace('/[^0-9]/', '', $this->offset);

        $response = $mysqli->query($sql);
        if($mysqli->error) error_log($mysqli->error . "\n". $sql);
        while ($row = $response->fetch_assoc()) {
            $this->unplacedNames[] = Name::getName($row['id']);
        }

    }

}

