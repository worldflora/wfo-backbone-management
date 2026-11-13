<?php

/**
 * 
 * Thing to help find basionyms for a name
 * 
 */
class BasionymFinder{

    public $name = null;
    public array $possibleBasionyms = array();
    public int $limit = 0;
    public string $filter = "";

    private string $possibleRanks = '';

     /**
     * Initializing sets it up and does all the work
     * properties are then populated to be called.
     * 
     * 
     */
    public function __construct($id, $filter = false, $limit = 30){

        global $ranks_table;

        $this->name = Name::getName($id);
        if(!$this->name) return; // cop out if we can't load a name

        // set our params
        $this->limit = intval($limit);
        $this->filter = preg_replace('/[^A-Za-z- ]/', '', $filter);

        // possible ranks - all names must be species level or below


        // this should probably be done centrally somewhere.
        // 2026-11-04 - changing to ranks in bands above and below species
        $ranks_above_species = array();
        $ranks_species_and_below = array();
        $species_and_below = false;
        foreach ($ranks_table as $rank_name => $rank) {
            if($rank_name == 'species') $species_and_below = true;
            if($species_and_below){
                $ranks_species_and_below[] = $rank_name;
            }else{
                $ranks_above_species[] = $rank_name;
            }
        }

        if(in_array($this->name->getRank(),$ranks_species_and_below)) $restrict_to_ranks = $ranks_species_and_below;
        else  $restrict_to_ranks = $ranks_above_species;

        /*
        $hit_level = false;
        $ranks = array();
        foreach ($ranks_table as $rank => $values) {
            if($rank == 'species') $hit_level = true;
            if($hit_level) $ranks[] = $rank;
        }
        */
        $this->possibleRanks = "'" . implode("', '", $restrict_to_ranks) . "'";

        if($filter){
            $this->alphabeticSearch();
        }else{
            $this->suggestionSearch();
        }

    }

    private function alphabeticSearch(){

        global $mysqli;

        $sql = "SELECT id FROM `names` WHERE `name_alpha` LIKE '{$this->filter}%' AND `rank` IN ({$this->possibleRanks}) ";
        
        // restrict the year if we have a year
        if($this->name->getYear()){
            $sql .= " AND (`year` <= {$this->name->getYear()} OR `year` is null) ";
        }

        // we should never return ourselves
        $sql .= " AND id != {$this->name->getId()} ";
        
        $sql .= " ORDER BY `name_alpha` LIMIT {$this->limit}";
        
        $result = $mysqli->query($sql);
        while($row = $result->fetch_assoc()){
            $this->possibleBasionyms[] = Name::getName($row['id']);
        }

    }

    /**
     * Uses the author string and name
     * to look for possibilities
     * 
     */
    private function suggestionSearch(){

        global $mysqli;

        // remove the ending (unless it is a really short name)
        if(strlen($this->name->getNameString()) > 5){
            $name_string = substr($this->name->getNameString(), 0, -3);
        }else{
            $name_string = $this->name->getNameString();
        }

        $sql = "SELECT id FROM `names` WHERE `rank` IN ({$this->possibleRanks}) AND `name` LIKE '$name_string%'  ";

        // add in the paranthetical authors if they exist
        $matches = array();
        if(preg_match('/\((.+)\)/', $this->name->getAuthorsString(), $matches)){
            $paranthetical = trim($matches[1]);
            $sql .= " AND `authors` LIKE '$paranthetical%' ";
        }

        // restrict the year if we have a year
        if($this->name->getYear()){
            $sql .= " AND (`year` <= {$this->name->getYear()} OR `year` is null) ";
        }

        // we should never return ourselves
        $sql .= " AND id != {$this->name->getId()} ";
        
        // limit it and order by year with null last
        $sql .= " ORDER BY -year DESC LIMIT {$this->limit}";

        $result = $mysqli->query($sql);
        while($row = $result->fetch_assoc()){
            $this->possibleBasionyms[] = Name::getName($row['id']);
        }

//        echo $sql;

    }








}