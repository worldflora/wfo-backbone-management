<?php
/*
    The logic required to safely move or all the children of a taxon
    See also NamePlacer that can do one at a time.
    Better to have this separate than make the NamePlacer logic even more complex!
*/
class ChildMover{

    public Name $name;
    public ?Taxon $taxon;
    public string $filter = '';
    public int $limit = 0;
    public ?string $rank = null; // the rank of the children to be moved
    public array $possibleTaxa = array();
    public ?string $requiredGenusString = null; // The new parent must be this genus or have this genus string.
    public ?string $requiredSpeciesString = null; // The new parent must be this species string or be this species.

    /**
     * Will return a list of taxa that the children could be moved to!
     * 
     * @param $name_id is name of the existing name so we don't return that
     * @param $filter is the starting letters of the name
     */
    public function __construct($name_id, $filter = '', $rank = null, $limit = 0){

        global $mysqli;
        global $ranks_table;

        // get a handle on the taxon the names are a children of
        $this->name = Name::getName($name_id);
        $taxon = Taxon::getTaxonForName($this->name);
        if($taxon->getId()) $this->taxon = $taxon;
        else $this->taxon = null;

        // calculate restrictions based on name parts
        $genus_level = array_search('genus', array_keys($ranks_table));
        $species_level = array_search('species', array_keys($ranks_table));
        $our_level =  array_search($this->taxon->getRank(), array_keys($ranks_table));

        // if we are at or below genus level then all the 

        // if we are a genus or below then all the children will have a genus name part that needs to be matched
        if($our_level == $genus_level) $this->requiredGenusString = $this->name->getNameString(); 
        if($our_level > $genus_level) $this->requiredGenusString = $this->name->getGenusString(); 

        // if we are a species or below then all the children will have a genus name part that needs to be matched
        if($our_level == $species_level) $this->requiredSpeciesString = $this->name->getNameString(); 
        if($our_level > $species_level) $this->requiredSpeciesString = $this->name->getSpeciesString(); 
        

        $this->filter = $mysqli->real_escape_string($filter ? $filter : '');

        $this->rank = $rank;
        $this->limit = $limit;


    }

    /**
     * A list of the ranks that will that will accept taxa at the rank
     * of the children to be moved 
     */
    public function getPossibleRanks(){

        global $ranks_table;

        $possible_ranks = array();
        // run through the table of all ranks
        foreach($ranks_table as $rank_name => $rank){
            // if a rank will accept this child then add it to the list
            if(in_array($this->rank,$rank['children'])) $possible_ranks[] = $rank_name;
        }
        return $possible_ranks;

    }


    /**
     * A list of the possible taxa that the synonyms
     * could be moved to. This includes taxa the
     * user might not have permission to move things to.
     * 
     */
    public function getPossibleTaxa(){

        global $mysqli;

        // limit is set to zero don't bother with the query
        if($this->limit < 1) return array();

        // possible taxa have to be at a rank that will accept taxa at the ranks
        // we are trying to move
        $poss_ranks = $this->getPossibleRanks();

        // get out of here if there are no possible taxa
        if(count($poss_ranks) < 1) return array();

        $possible_ranks_string = "'" . implode("', '", $poss_ranks)  ."'";

        $sql = "SELECT n.id AS name_id, t.id AS taxon_id FROM `names` AS n
            JOIN taxon_names AS tn ON tn.name_id = n.id
            JOIN taxa AS t ON t.taxon_name_id = tn.id
            WHERE n.`name_alpha` LIKE '{$this->filter}%' 
            AND n.`rank` in ($possible_ranks_string)
            AND tn.taxon_id != {$this->taxon->getId()}";

        if($this->requiredGenusString){
            $sql .= " AND ((n.`name` = '{$this->requiredGenusString}' AND n.`rank` = 'genus') || n.`genus` = '{$this->requiredGenusString}')";
        }

        if($this->requiredSpeciesString){
            $sql .= " AND ((n.`name` = '{$this->requiredSpeciesString}' AND n.`rank` = 'species') || n.`species` = '{$this->requiredSpeciesString}')";
        }

        $sql .= " ORDER BY `name_alpha` LIMIT $this->limit;";


        $response = $mysqli->query($sql);
        $rows = $response->fetch_all(MYSQLI_ASSOC);
        $response->close();

        $out = array();
        foreach ($rows as $row) {
            $out[] = Taxon::getById($row['taxon_id']);
        }
        return $out;

    }

    public function moveChildrenTo($new_parent_id, $child_wfos){

        // firstly check we have a source taxon
        if(!$this->taxon){
            return new UpdateResponse('MoveChildren', false, "Trying to move children from non-existent taxon.");
        }

        // can we edit it?
        if(!$this->taxon->canEdit()){
            return new UpdateResponse('MoveChildren', false, "You don't have permission to edit the source taxon {$this->taxon->getAcceptedName()->getPrescribedWfoId()}");
        }

        // does it have any children
        $children_all = $this->taxon->getChildren();

        // chop them down to just the ones at the rank we are interested in
        // and with the WFO IDs in the list provided
        $children = array();
        foreach($children_all as $kid){
            if($kid->getRank() == $this->rank && in_array($kid->getAcceptedName()->getPrescribedWfoId(), $child_wfos)) $children[] = $kid;
        }

        // no children then nothing to do
        if(!$children){
            return new UpdateResponse('MoveChildren', false, "No children to move. Did you already do it?");
        }

        // if we don't have a destination set then we can't do anything
        // because we don't do bulk unplacing
        if(!$new_parent_id){
            $r = new UpdateResponse('MoveChildren', false, "Can not bulk unplace $n children from {$this->taxon->getAcceptedName()->getPrescribedWfoId()}.");
            return $r;
        }else{

            // we are in the land of moving the children

            // Check the destination is OK and we can edit it.
            $destination_name = Name::getName($new_parent_id);
            $destination_taxon = Taxon::getTaxonForName($destination_name);

            // need a destination to move it to
            if(!$destination_taxon->getId()){
                return new UpdateResponse('MoveChildren', false, "Trying to move synonyms to non-existent taxon.");
            }

            // must be able to edit destination taxon
            if(!$destination_taxon->canEdit()){
                return new UpdateResponse('MoveChildren', false, "You don't have permission to edit the destination taxon {$destination_taxon->getAcceptedName()->getPrescribedWfoId()}");
            }

            // check destination taxon is at a rank and with the correct name parts.

            // FIXME!

            // OK we have a destination and we can edit it so do the move
            foreach($children as $kid){
                $kid->setParent($destination_taxon);
                $kid->save();
            }
            
            $n = count($children);
            $r = new UpdateResponse('MoveChildren', true, "Moved $n children from {$this->name->getPrescribedWfoId()} to {$destination_taxon->getAcceptedName()->getPrescribedWfoId()}.");
            $r->taxonIds[] = $this->name->getId();
            $r->taxonIds[] = $destination_taxon->getId();
            return $r;
            
        }




    }


}