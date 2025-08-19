<?php

class Taxon extends WfoDbObject{


    // We need to be careful we run 
    // singletons on primary objects so only create
    // Taxa using factory methods.
    private ?Name $name = null;
    private ?Taxon $parent = null;

    private ?Array $children = null;
    private ?Array $synonyms = null;

    private ?Array $curatorIds = null;
    private ?Array $editors = null;
    
    private bool $isHybrid = false;
    private bool $isFossil = false;

    private ?int $childCount = null;

    protected static $loaded = array();


    /**
     * Create an instance of a Taxon
     * Don't call this directly use the getTaxonForName or getById or getRootTaxon
     * factory methods only.
     * 
     * @param int $db_id The primary key of the taxon in the database
     * If an array then set of DwC values.
     */
    public function __construct($db_id){
        if($db_id != -1){
            $this->id = $db_id;
            $this->load();
            self::$loaded[$this->id] = $this;
        }
    }

    /**
     * Will load the taxon from the db if it has a db id
     * Will throw error if the taxon doesn't have a db id yet
     * 
     */
    public function load(){

        global $mysqli;
        if(!$this->id) throw new ErrorException("You can't call load on a Taxon that doesn't have an db id yet");

//      $sql = "SELECT * FROM taxa as t left join taxon_names as tn on t.id = tn.taxon_id WHERE t.id = {$this->id}"; // returning multiple rows!!! should join on preferened name
        $sql = "SELECT * FROM taxa as t left join taxon_names as tn on t.taxon_name_id = tn.id WHERE t.id = {$this->id}";
        $result = $mysqli->query($sql);
        if($mysqli->error) echo $mysqli->error; // should only have prepare errors during dev
        // echo $sql; exit;
        $row = $result->fetch_assoc();

        // get the name via the taxonNames table
        if($row['name_id']) $this->name = Name::getName((int)$row['name_id']);

        // This is a good one. Be careful getting the parent because if you are at the root you are the parent. Infinite loop find you here after hours of searching.
        if($row['parent_id'] == $this->id){
            $this->parent = $this;
        }else{
            $this->parent = Taxon::getById($row['parent_id']);
        }

        $this->isHybrid = $row['is_hybrid'] > 0 ? true : false;
        $this->isFossil = $row['is_fossil'] > 0 ? true : false;
        $this->comment = $row['comment'];
        $this->issue = $row['issue'];
        $this->user_id = $row['user_id'];
        $this->created = $row['created'];
        $this->modified = $row['modified'];

        $result->close();

        // fixme - load synonyms?
    
    }

    /**
     * Fetch a taxon by its database id
     * 
     * @param int $taxon_id The primary key of the taxon in the taxa database table.
     * @return Taxon
     */
    public static function getById($taxon_id){
        
        if(isset(self::$loaded[$taxon_id])){
            return self::$loaded[$taxon_id];
        }

        return new Taxon($taxon_id);
        
    }

    /**
     * Returns the taxon associated with a name object
     * the name may be the accepted name or a synonym in this taxon
     *
     * @return Taxon or empty one if name isn't associated with the name.
     * 
     */
    public static function getTaxonForName($name){

        global $mysqli;

        if(!$name || !$name->getId()) throw new ErrorException("You can't call load on a Taxon with a Name that doesn't have an id i.e. has not been saved to the db: " . print_r($name, true) );

        // is it in the names yet (we could possible check if it is in self::loaded before going to the db if this proves slow)
        $result = $mysqli->query("SELECT `taxon_id` FROM `taxon_names` WHERE `name_id` = {$name->getId()}");
        
        if($result->num_rows > 1) throw new ErrorException("Something terrible happened. There are multiple taxa with the same name id {$name->getId()}");
        
        // found a taxon for the name so return it
        if($result->num_rows == 1){
            $row = $result->fetch_assoc();
            return Taxon::getById($row['taxon_id']);
        }

        // can't get a taxon for this name so create an empty one and set this name in it
        // if they do checkIntegrity() or save() it will control for this name not being available
        $taxon = new Taxon(-1);
        $taxon->setAcceptedName($name);
        return $taxon;

    }

    /**
     * 
     * Returns the root taxon of all taxa
     * 
     */
    public static function getRootTaxon(){
        
        global $mysqli;
        
        // root taxon is the one which is its own parent 
        $result = $mysqli->query("SELECT `id` FROM `taxa` WHERE `id` = `parent_id` ");
        if($result->num_rows > 1) throw new ErrorException("Something terrible happened! There are multiple root taxa in the database.");
        if($result->num_rows < 1) throw new ErrorException("Something terrible happened! There is no root taxon in the database");

        $row = $result->fetch_assoc();

        return Taxon::getById($row['id']);

    
    }

    /*

        G E T T E R  &  S E T T E R  M E T H O D S

    */

    public function updateHybridStatus($is_hybrid){
        $this->setHybridStatus($is_hybrid);
        $this->save();

        if($is_hybrid){
            $this->getAcceptedName()->updateChangeLog("Set as hybrid");
        }else{
            $this->getAcceptedName()->updateChangeLog("Set as NOT hybrid");
        }
        
    }

    /**
     * Set if this is a hybrid or not
     * 
     * @param boolean True if it is a hybrid taxon
     */
    public function setHybridStatus($is_hybrid){
        $this->isHybrid = $is_hybrid;
    }

    public function getHybridStatus(){
        return $this->isHybrid;
    }

    public function updateFossilStatus($is_fossil){
        $this->setFossilStatus($is_fossil);
        $this->save();

        if($is_fossil){
            $this->getAcceptedName()->updateChangeLog("Set as fossil");
        }else{
            $this->getAcceptedName()->updateChangeLog("Set as NOT fossil");
        }
        
    }

    /**
     * Set if this is a fossil or not
     * 
     * @param boolean True if it is a fossil taxon
     */
    public function setFossilStatus($is_fossil){
        $this->isFossil = $is_fossil;
    }

    public function getFossilStatus(){
        return $this->isFossil;
    }

    /**
     * 
     * This is a wrapper around the same
     * method in the accepted name but adds in the hybrid status
     * 
     */
    public function getFullNameString($italics = true, $authors = true, $abbreviate_rank = true, $abbreviate_genus = false){

        global $ranks_table;

        // no name if we have no name
        if(!$this->getAcceptedName()) return "no name";

        // Insert X if any of our name parts are hybrid
        $fns = $this->getAcceptedName()->getFullNameString($italics, $authors, $abbreviate_rank, $abbreviate_genus);

        $genus_is_hybrid = false;
        $species_is_hybrid = false;
        $genus_is_fossil = false;
        $species_is_fossil = false;

        // look to the name parts needing flagging with symbols
        if($this->name->getGenusString()){
            $ancestor = $this;
            while($ancestor = $ancestor->getParent()){

                if($ancestor->getRank() == 'species'){
                    $species_is_hybrid = $ancestor->getHybridStatus();
                    $species_is_fossil = $ancestor->getFossilStatus();
                }

                if($ancestor->getRank() == 'genus'){
                    $genus_is_hybrid = $ancestor->getHybridStatus();
                    $genus_is_fossil = $ancestor->getFossilStatus();
                    break;
                }

            }
        }

        /* ADDING THE HYBRID SYMBOL */
        $hybrid_symbol = '× ';
        if($italics) $hybrid_symbol = "</i>$hybrid_symbol<i>";

        if($this->isHybrid){
            $n = preg_quote($this->name->getNameString(), '/');

            // if this is an autonym then add it to the last 
            if(substr_count($fns, $this->name->getNameString()) > 1 ){

                // replace the first one with a holder
                $fns = preg_replace("/$n/", 'PLACE_HOLDER', $fns, 1);

                // insert the symbol
                $fns = preg_replace("/$n/", $hybrid_symbol . $n, $fns, 1);

                // put the name back in 
                $fns = preg_replace("/PLACE_HOLDER/", $n, $fns, 1);

            }else{
                $fns = preg_replace("/$n/", $hybrid_symbol . $n, $fns, 1);
            }

        }

        if($genus_is_hybrid && !$abbreviate_genus){ 
            // preg replace the first instance but beware if the genus is 
            // abbreviated and the name is an autonym then it will add it to 
            // the authonym
            $n = preg_quote($this->name->getGenusString(), '/');
            // $fns = str_replace($n, $hybrid_symbol . $n, $fns);
            $fns = preg_replace("/$n/", $hybrid_symbol . $n, $fns, 1);
        }

        if($species_is_hybrid){

            $n = preg_quote($this->name->getSpeciesString(), '/');
            // $fns = str_replace($n, $hybrid_symbol . $n, $fns);
            $fns = preg_replace("/$n/", $hybrid_symbol . $n, $fns, 1);

            // we are a subspecific taxon so our rank has notho put in front of it
            $fns = str_replace($this->name->getRank(), "notho" . $this->name->getRank(), $fns);
            $fns = str_replace($ranks_table[$this->name->getRank()]["abbreviation"], "notho" . $ranks_table[$this->name->getRank()]["abbreviation"], $fns);

        }

        /* ADDING THE FOSSIL SYMBOL */
        $fossil_symbol = '† ';
        if($this->isFossil){
            $n = preg_quote($this->name->getNameString(), '/');

            // if this is an autonym then add it to the last 
            if(substr_count($fns, $this->name->getNameString()) > 1 ){

                // replace the first one with a holder
                $fns = preg_replace("/$n/", 'PLACE_HOLDER', $fns, 1);

                // insert the symbol
                $fns = preg_replace("/$n/", $fossil_symbol . $n, $fns, 1);

                // put the name back in 
                $fns = preg_replace("/PLACE_HOLDER/", $n, $fns, 1);

            }else{
                $fns = preg_replace("/$n/", $fossil_symbol . $n, $fns, 1);
            }

        }

        if($genus_is_fossil && !$abbreviate_genus){ 
            // preg replace the first instance but beware if the genus is 
            // abbreviated and the name is an autonym then it will add it to 
            // the authonym
            $n = preg_quote($this->name->getGenusString(), '/');
            // $fns = str_replace($n, $hybrid_symbol . $n, $fns);
            $fns = preg_replace("/$n/", $fossil_symbol . $n, $fns, 1);
        }

        if($species_is_fossil){
            $n = preg_quote($this->name->getSpeciesString(), '/');
            // $fns = str_replace($n, $hybrid_symbol . $n, $fns);
            $fns = preg_replace("/$n/", $fossil_symbol . $n, $fns, 1);
        }

        // a side effect of using preg_quote above is that 
        // it will escape "-" when it occurs in species epithets (yoyoyoy do they allow that!)
        // so here we remove that if it occurs
        $fns = str_replace('\\', '', $fns); // never have a back slash in a name.

        // if we are a child of a subspecific name (e.g. a var of a subsp)
        $ancestor = $this;
        $species_level = array_search('species', array_keys($ranks_table));
        $additions = '';
        while($ancestor = $ancestor->getParent()){

            // if the ancestor is below species then we need 
            // to add it to the name
            if(array_search($ancestor->getRank(), array_keys($ranks_table)) > $species_level){

                // rank - abbreviated if need be
                if($abbreviate_rank){
                    $rank = $ranks_table[$ancestor->getAcceptedName()->getRank()]['abbreviation'];
                }else{
                    $rank = ucfirst($ancestor->getAcceptedName()->getRank());
                }
                $additions .= " <span class=\"wfo-name-rank\">$rank</span>";

                // the ancestor might be a hybrid
                if($ancestor->getHybridStatus()) $anc_hybrid_symbol = $hybrid_symbol;
                else $anc_hybrid_symbol = '';
                
                // name part
                if($italics){
                    $additions .= " <i>$anc_hybrid_symbol{$ancestor->getAcceptedName()->getNameString()}</i>";
                }else{
                    $additions .= " $anc_hybrid_symbol{$ancestor->getAcceptedName()->getNameString()}";
                }
            }else{
                break;
            }
        }
        $fns = str_replace('<span class="wfo-name-rank">', $additions  . ' <span class="wfo-name-rank">', $fns);

        // we sometimes end up with double spaces = bad
        $fns = preg_replace('/  /', ' ', $fns);

        return $fns;


    }

    /**
     * Sets the name of this taxon.
     * 
     * @param Name A name object
     */

    public function setAcceptedName($name){
        $this->name = $name;
    }

    /**
     * Gets the accepted name of this taxon
     * 
     * @return Name the name object
     */
    public function getAcceptedName(){
        return $this->name;
    }

    public function setParent($parent){

        if($this->getId() == $parent->getId()){
            throw new ErrorException("A taxon can't have itself set as a parent. We only have one root! Parent id: {$parent->getId()}.");
            return;
        }
        $this->getAcceptedName()->updateChangeLog("Child of: " . $parent->getAcceptedName()->getPrescribedWfoId());
        $this->parent = $parent;
    }
    
    public function getParent(){
        if($this->parent == $this){
            // we are the root
            return null;
        }else{
            return $this->parent;
        }
    }

    /**
     * Checks the overall integrity of the values set in the
     * Taxon. Will call other integrity check methods
     * 
     * @return Array An array of data about the integrity of the current values set in the Taxon
     * 
     */
    public function checkIntegrity(){

        global $mysqli;

        $integrity = new UpdateResponse('taxon', true, "Taxon integrity check");
        $integrity->status = WFO_INTEGRITY_OK;

        $integrity->children[] = $this->checkRank();
        $integrity->children[] = $this->checkAutonym();

        // Call integrity check on the accepted name?

        // is the name in use as the accepted name of another taxon?

        // I should be a correct lower rank to my parent

        // I should be same rank as my siblings

        
        foreach ($integrity->children as $check) {

            // one fails we all fail
            if(!$check->status == WFO_INTEGRITY_FAIL){
                $integrity->status = WFO_INTEGRITY_FAIL;
                $integrity->success = false;
                $integrity->message = "Failed on {$check->name}";
                break;
            }

            // if one is not success then we add a warning
            if(!$check->status != WFO_INTEGRITY_OK){
                $integrity->status = WFO_INTEGRITY_WARN;
                $integrity->success = true;
                $integrity->message = "Warning on {$check->name}";
            }

        }


        return $integrity;
    }

    /**
     * Checks the integrity of the rank set in the taxon.
     * and updates the supplied integrity array
     * Is this rank appropriate for the parent taxon?
     * Is this rank appropriate for the siblings of the taxon?
     * 
     * @param Array $integrity a summary of integrity so far
     * @return Array An updated version of the integrity
     */

    public function checkRank(){

        global $ranks_table;

        $integrity = new UpdateResponse('rank', true, 'Rank OK');
        $integrity->status = WFO_INTEGRITY_OK;

        // no parent no go
        if (!$this->parent){
            $integrity->success = false;
            $integrity->message = "No parent is defined so correct rank can't be ascertained.";
            return $integrity;
        }

        // we are root it is OK
        if($this->parent == $this){
            $integrity->message = "This is the root taxon so no rank evaluation needed.";
            return $integrity;
        }

        // we have a parent how does their rank compare to ours
        $parent_r = $this->parent->getRank();
        $my_rank = $this->getRank();

        // if the parent is the root then all ranks are permissible
        // otherwise we have to do some checking
        if($this->parent->getParent() != null){

                if(!$parent_r || !$my_rank){
                    //print_r($this->parent);
                    throw new ErrorException("No rank found for rank comparison. Parent rank: $parent_r. Taxon rank $my_rank.");
                    $integrity->status = WFO_INTEGRITY_FAIL;
                    $integrity->success = false;
                    $integrity->message = "No rank found for rank comparison. Parent rank: $parent_r. Taxon rank $my_rank.";
                    
                    return $integrity;
                }

                // check if we are of permissible rank to be a child of our parent
                $permissable  = $ranks_table[$parent_r]['children'];
                if(!in_array($my_rank, $permissable)){
                    $perms = implode(',', $permissable);
                    $integrity->status = WFO_INTEGRITY_FAIL;
                    $integrity->success = false;
                    $integrity->message = "You can't add a taxon of rank $my_rank to parent of rank $parent_r. Permissible ranks are $perms.";
                    return $integrity;
                }

        }        

        // got to here so we have good ranks
        $integrity->message = "Adding taxon of rank $my_rank to parent of rank $parent_r is permissible.";
        
        // we should be the same rank as our siblings
        $siblings = $this->parent->getChildren();
        $my_level = array_search($my_rank, array_keys($ranks_table));
        $higher_level_siblings = array();
        foreach ($siblings as $bro) {
            $bro_level = array_search($bro->getRank(), array_keys($ranks_table));
            if($bro_level < $my_level){
                $higher_level_siblings[$bro_level][] = $bro;
            }
        }
        // sort them so they are in kingdom -> form
        ksort($higher_level_siblings);
        $potential_parents = array_pop($higher_level_siblings);

        // we only need to worry about the lowest level (highest number) siblings 
        // imagine is we were a subform being added to a species and there were already subspecies and varieties
        // we'd be added to the autonym of the varieties. An autonym at subspecies level would be created 
        // when the autonym at variety level was created - so we don't need to worry about that here.
        // as being potential parents 
        // the act of creating a new parent will sort out anything beyond that.

        // if there are siblings with higher rank then throw a wobbly
        if(count($higher_level_siblings)){
            $integrity->status = WFO_RANK_REBALANCE;
            $integrity->success = false;
            $integrity->message = "There is an imbalance of ranks at this point in the hierarchy.";
            $integrity->taxa = $potential_parents;
        }

        return $integrity;

    }

    /**
     * 
     * Taxa have a rank based on their name
     * 
     */
    public function getRank(){

        // we are a normal taxon
        if($this->name){
            return $this->name->getRank();
        }

        // we don't know what we are - probably an error!
        return null;

    }

    public function setRank($rank){
        if($this->name){
            return $this->name->setRank($rank);
        }
    }

    /**
     * An integrity check of whether an autonym is needed 
     * alongside this taxon and whether one is present or not.
     * 
     * 
     */
    public function checkAutonym(){

        global $ranks_table;
        global $mysqli;

        $integrity = new UpdateResponse('autonym', true, 'Autonym OK');
        $integrity->status = WFO_INTEGRITY_OK;

        // is this a subdivision of a genus or species?
        // if not return that autonym stuff is N/A
        $genus_index = array_search('genus', array_keys($ranks_table));
        $species_index = array_search('species', array_keys($ranks_table));
        $rank_index = array_search($this->getRank(), array_keys($ranks_table));
        
        if($rank_index <= $genus_index || $rank_index == $species_index){
            $integrity->status = WFO_AUTONYM_NA;
            $integrity->success = true;
            $integrity->message = "Autonyms are not applicable at the rank " . $this->getRank() . ".";
            return $integrity;
        }

        // Am I the autonym?
        if($this->isAutonym()){
            $integrity->status = WFO_AUTONYM;
            $integrity->success = true;
            $integrity->message = "This taxon is an autonym";
            return $integrity;
        }


        // if we are here then it has been established 
        // we are at the rank that autonyms occur and 
        // we are not an autonym 

        // does the autonym exist?
        $siblings = $this->parent->getChildren();
   
        foreach ($siblings as $bro) { 
            
            if($bro->isAutonym()){
                  // we have found the autonym amongst our siblings
                $integrity->status = WFO_AUTONYM_EXISTS;
                $integrity->success = true;
                $integrity->message = "There is an autonym at this level in the hierarchy.";
                $integrity->taxa[] = $bro;
                return $integrity;
              }
        }

        // can't find an autonym but there should be one
        $integrity->status = WFO_AUTONYM_REQUIRED;
        $integrity->success = true;
        $integrity->message = "Can't find an autonym but there should be one";
        $integrity->names[] = $this->findAutonymNames(
            $this->getAcceptedName()->getRank(), 
            $this->getAcceptedName()->getGenusString(), 
            $this->getAcceptedName()->getSpeciesString()
        );
        return $integrity;

    }

    /**
     * A wrapper around the function in the name
     * 
     */
    public function isAutonym(){
        if(!$this->name) return false;
        return $this->name->isAutonym();
    }

    /**
     * Does the work or save but should 
     * always be called via save (from WfoDbObject ) which wraps it in a db transaction.
     */
    protected function saveDangerously(){

        global $mysqli;
        global $ranks_table;

        if(!$this->canEdit()){
            throw new ErrorException("User does not have permission to save changes to Taxon");
            return;
        }

        // check validity and refuse to proceed if we aren't valid
        $integrity = $this->checkIntegrity();
        if($integrity->status == WFO_INTEGRITY_FAIL){
            $integrity->success = false;
            return $integrity;
        } 

        // Integrity checks out so it is OK to proceed

        // note setting of accepted name is done separately at the end
        // of the process to be sure we have a db id and that we can make
        // any other changes necessary in taxon_names

        if($this->id){

            // UPDATING
            // we have a db id so we are updating
            
            $stmt = $mysqli->prepare("UPDATE `taxa` 
                SET 
                `parent_id` = ?,
                `user_id` = ?,
                `is_hybrid` = ?,
                `is_fossil` = ?,
                `comment` = ?, 
                `issue` = ?,
                `source` = ? 
                WHERE 
                `id` = ?"
            );
            if($mysqli->error) echo $mysqli->error; // should only have prepare errors during dev
            $parent_id = $this->parent->getId();
             $stmt->bind_param("iiiisssi",
                $parent_id,
                $this->user_id,
                $this->isHybrid,
                $this->isFossil,
                $this->comment,
                $this->issue,
                $this->source,
                $this->id
            );
            if(!$stmt->execute()){
                throw new ErrorException($mysqli->error);
                $integrity->success = false;
                return $integrity;
            }

        }else{

            // CREATING
            // we don't have a db id so we are creating

             $stmt = $mysqli->prepare("INSERT 
                INTO `taxa` (`parent_id`, `user_id`, `is_hybrid`,  `is_fossil`, `comment`,`issue`,`source`) 
                VALUES (?,?,?,?,?,?,?)");
            if($mysqli->error) echo $mysqli->error; // should only have prepare errors during dev
            $parent_id = $this->parent->getId();

            $stmt->bind_param("iiiisss",
                $parent_id,
                $this->user_id,
                $this->isHybrid,
                $this->isFossil,
                $this->comment,
                $this->issue,
                $this->source
            );
            if(!$stmt->execute()){
                throw new ErrorException($mysqli->error);
                $integrity->success = false;
                return $integrity;
            }else{
                // get our db id
                $this->id = $mysqli->insert_id;
            }

        }

        // close the statement we opened in one of the legs above
        $stmt->close();

        // assign the accepted name whether we have created or updated
        $this->assignAcceptedName($this->name);

        // do we need to create an associated autonym?

        /* fixme - removed for import */
        /*
        $autonym_integrity = null;
        foreach ($integrity->children as $check) {
            if($check->name = 'autonym') $autonym_integrity = $check;
        }
        if($autonym_integrity && $autonym_integrity->status == WFO_AUTONYM_REQUIRED){
            
            // create a name to base the taxon on
            $autonym = $this->createAutonym(
                $this->parent,
                $this->getRank()
            );
        
        } // end autonym
        */


        // do we need to rebalance the tree at this point?
/*
        $rank_integrity = null;
        foreach ($integrity->children as $check) {
            if($check->name = 'rank'){
                $rank_integrity = $check;
                break;
            }
        }


        if($rank_integrity && $rank_integrity->status == WFO_RANK_REBALANCE){

            $potential_parents = $rank_integrity->taxa;

            // are any of them suitable parents?
            $new_parent = null;
            foreach ($potential_parents as $pot){
                if($pot->isAutonym()){
                    // we have a suitable parent.
                    $new_parent = $pot;
                    break;
                }
            }

            // if we haven't found a parent we need to create one
            if(!$new_parent){

                // different things above and below species level
                $genus_level = array_search('genus', array_keys($ranks_table));
                $my_level = array_search($this->getRank(), array_keys($ranks_table));

                if($my_level > $genus_level){
                    // below species level
                    $new_parent = $this->createAutonym($this->parent,  $potential_parents[0]->getRank());
                }

            }

            // OK we have the new parent - let's set it
            if($new_parent){
                $this->setParent($new_parent);
                $this->save();
                $this->load(); // will update the parents and synonyms
            }else{
                throw new ErrorException("Unable to set new parent for taxon {$this->id} in order to balance the tree.");
            }

        }
*/

        $integrity->success = true;
        return $integrity;

    }

    /**
     * Looks for suitable autonym names in the names table
     * 
     * @param String $rank The rank of the autonym name
     * @param String $genus The name string of the genus
     * @param String $species The (optional) species name string
     * @return Name[] An array of names found (we hope just one!)
     */
    private function findAutonymNames($rank, $genus, $species){

        global $mysqli;
        global $ranks_table;

        $out = array();

        $genus_index = array_search('genus', array_keys($ranks_table));
        $species_index = array_search('species', array_keys($ranks_table));
        $rank_index = array_search($rank, array_keys($ranks_table));

        if($rank_index > $species_index){
            
            // we are a subdivision of a species therefore name has to == species
            // it also has to be in this genus
            $sql = "SELECT id
                    from `names` 
                    where (length(`authors`) = 0 OR `authors` is null)
                    and `name` = `species`
                    and `genus` = '$genus'
                    and `species` = '$species'
                    and `rank` = '$rank'";
            $result = $mysqli->query($sql);
            while($row = $result->fetch_assoc()){
                $out[] = Name::getName($row['id']);
            }

        }else{
            
            // we are subdivision of a genus
            // the name is the same as the genus
            $result = $mysqli->query(
                "SELECT id
                    from `names` 
                    where (length(`authors`) = 0 OR `authors` is null)
                    and `name` = `genus`
                    and `genus` = '$genus'
                    and `rank` = '$rank'
            ");
            while($row = $result->fetch_assoc()){
                $out[] = Name::getName($row['id']);
            }

        }

        return $out;
    
    }

    /**
     * 
     * Will look up the taxonomy and return the 
     * genus for this taxon if there is one
     * 
     */
    public function getGenus(){
        $ancestor = $this;
        while($ancestor->getRank() != 'genus'){
            // if we have reached the top of the tree stop
            if($ancestor->getParent() == $ancestor) return null;
            // step up a layer
            $ancestor = $ancestor->getParent();
        }
        return $ancestor;
    }

    /**
     * 
     * Will look up the taxonomy and return the 
     * species for this taxon if there is one
     * 
     */
    public function getSpecies(){
        $ancestor = $this;
        while($ancestor->getRank() != 'species'){
            // if we have reached the top of the tree stop
            if($ancestor->getParent() == $ancestor) return null;
            // step up a layer
            $ancestor = $ancestor->getParent();
        }
        return $ancestor;
    }

    /**
     * Creates a new autonym (Taxon) and possibly associated Name 
     * 
     * @param Taxon $parent The taxon that will be the parent of this autonym
     * @param String $rank The rank the autonym will be created at
     * 
     */
    private function createAutonym($parent, $rank){


        /*
            If we are below species level then the name of the autonym will be the species
            If we are above species level then the name of the autonym will be the genus.
        */

        $genus = $this->getGenus();
        if($genus){
            // the genus part is the name of the genus we are in
            $auto_genus = $genus->getAcceptedName()->getNameString();
        }else{
            // if we don't have a genus we can't create an autonym. They always have a genus part!
            return null;
        }

        $species = $this->getSpecies();
        if($species){
            // the name of the autonym will be the name of the species
            $auto_name = $species->getAcceptedName()->getNameString();
        }else{
            // there is no species. We are between species and genus
            // so the name of the autonym will be the name of the genus
            // 22.1. The name of ANY subdivision of a genus that includes the type of the adopted, legitimate name of the genus to which it is assigned is to repeat that generic name unaltered as its epithet, not followed by an author citation (see Art. 46). Such names are autonyms (Art. 6.8; see also Art. 7.7).
            $auto_name = $genus->getAcceptedName()->getNameString();
        }

        $names = $this->findAutonymNames($rank, $auto_genus, $auto_name);

        if(count($names) == 0){
            // We didn't find a name so create on
            $name = $this->createAutonymName($rank, $auto_genus, $auto_name);
        }elseif(count($names) == 1){
            // we found a single name so can use that
            $name = $names[0];
        }else{
            // we found multiple names so throw a wobbly

            // FIXME - for import we just pick the first one!
            /*
            $c_ids = array();
            foreach($names as $c){
                $c_ids[] = $c->getId();
            }
            $c_ids = implode(",", $c_ids);

            throw new ErrorException("Searching for autonym name for {$this->parent->getId()}, {$this->getRank()} and found multiple candidates. These names need to be deduplicated. Name IDs are: $c_ids");
            return null;
            */
            error_log("Searching for autonym name for {$this->parent->getId()}, {$this->getRank()} and found multiple candidates. These names need to be deduplicated. Name IDs are: $c_ids");
            error_log("Picking first one");
            $name = $names[0];
        }

        // we have got to here so we must have a name and we know the parent
        // so we can return the autonym
        if($name){
            return $this->createAutonymTaxon($name, $parent);  
        }

    }

    /**
     * Create a new Name object as an autonym
     * Used in conjunction with createAutonym and creatAutonymTaxon
     * 
     * @param String $rank the rank of the new name
     * @param String $genus the genus name string of the new Name
     * @param String $name The name string of the new Name (will be used as name string and/or species string depending on rank)
     * @return Name The new name or null on failure.
     */
    private function createAutonymName($rank, $genus, $name){

            global $ranks_table;
            global $mysqli;

            // if there is no rank then something bad is happening. Refuse to create
            if(!$rank){
                error_log("Trying to create an autonym for '$name' in genus '$genus' but no rank given.");
                return null;
            }

            // refuse if there is no genus
            if(!$genus){
                error_log("Trying to create an autonym for '$name' at rank '$rank' but no genus name given.");
                return null;
            }

            // double check we are not creating a homonym - we made 6,000 Aizoon subgenera once!
            $result = $mysqli->query("SELECT id FROM `names` WHERE `rank` = '$rank' and `genus` = '$genus' and `name` = '$name';");
            if($result->num_rows > 0){
                $row = $result->fetch_assoc();
                error_log("Trying to create autonym `rank` = '$rank' and `genus` = '$genus' and `name` = '$name' when there is a homonym with id {$row['id']} ");
                return Name::getName($row['id']);
            }

            $autonym_name = Name::getName(-1);

            // meta fields are quite simply copies or ours
            $autonym_name->setSource('Auto generated'); 
            $autonym_name->setUserId($this->getUserId());
            $autonym_name->setComment("Name automatically created to support autonym taxon.");

            // it has to be the same rank as us because it is an autonym next to us on the hierarchy
            $autonym_name->setRank($rank);
            $autonym_name->setGenusString($genus);

            // the other two strings depend on if we are above or below species level
            $species_level = array_search('species', array_keys($ranks_table));
            $our_level = array_search($rank, array_keys($ranks_table));

            if($our_level > $species_level){
                
                // we are below species - rank levels count up from Kingdom

                // the species is the same as ours as we are in this species
                $autonym_name->setSpeciesString($name);
            
                // the name of the autonym is the same as the species name
                $autonym_name->setNameString($name);
                

            }else{

                // we are above species level
                // the species isn't set - we are not in a species this is a subgenus or something.
                // the name is the same as the genus name
                $autonym_name->setNameString($name);


            } 

            // save the name
            $autonym_name->save();

            return $autonym_name;

    }

    /**
     * Creates a taxon based on an autonym name
     * 
     * @param Name $name  object that the autonym taxon will be based on (have as its accepted name)
     * @param Taxon $parent object that will be the parent of the new taxon
     * @return Taxon The new autonym taxon
     */
    private function createAutonymTaxon($name, $parent){

        $autonym = Taxon::getTaxonForName($name);

        // we can get it a fangle and create an autonym that already exists if the ranks are wrong - then we loop.
        // So don't save the taxon if it already exists. Only if it is new.
        if($autonym->getId() > 0) return null;

        $autonym->setSource($this->getSource());
        $autonym->setUserId($this->getUserId());
        $autonym->setComment("Taxon automatically created as autonym.");
        $autonym->setParent($parent);
        $autonym->save();
        $parent->load();
        return $autonym;
    }

    /**
     * Not to be confused associated public functions
     * 
     * This makes changes to the taxa table to map
     * a taxon_names row to the taxa.taxon_name_id field
     * 
     * This will call assignName first to make sure name is in table
     * 
     * 
     */
    private function assignAcceptedName($name){

        global $mysqli;

        if(!$this->canEdit()){
            throw new ErrorException("User does not have permission to save changes to Taxon");
            return;
        }

        $taxon_names_id = $this->assignName($name);

        if($taxon_names_id){
            $result = $mysqli->query("UPDATE taxa SET taxon_name_id = {$taxon_names_id} WHERE id = {$this->id}");
            return true;
        }else{
            // exceptions will have been thrown by assignName
            return false;
        }

    }


    public function addSynonym($name){
        // we might do some checking in the future
        $name->updateChangeLog("Synonym of: " . $this->getAcceptedName()->getPrescribedWfoId());
        return $this->assignName($name);
    }

    public function removeSynonym($name){

        // check the name isn't the accepted name
        if($this->getAcceptedName() == $name){
            throw new ErrorException("Trying to remove accepted name as if it were a synonym. name_id {$name->id} and taxon_id {$this->id}.");
            return false;
        }

        $name->updateChangeLog("Removed from taxonomy");

        // actually do it
        $this->unassignName($name);
    
    }

    public function delete(){

        global $mysqli;

        $name = $this->getAcceptedName();

        // we should have no children
        if(count($this->getChildren()) > 0){
            throw new ErrorException("Trying to delete a taxon that has children. name_id {$name->id} and taxon_id {$this->id}.");
            return false;
        }

        // we should have no synonyms        
        if(count($this->getSynonyms()) > 0){
            throw new ErrorException("Trying to delete a taxon that has synonyms. name_id {$name->id} and taxon_id {$this->id}.");
            return false;
        }

        // unplace my name
        $name = $this->getAcceptedName();
        $name->updateChangeLog("Removed from taxonomy");
        $this->unassignName($name);

        // delete my row
        $result = $mysqli->query("DELETE FROM taxa WHERE id = {$this->id}");
        if($mysqli->affected_rows == 1){
            return true;
        }else{
            throw new ErrorException("Failed to remove taxon {$this->id} no rows affected.");
            return false;
        }

    }

    /**
     * This will recursively remove all children
     * and synonyms from this taxon leaving it
     * as a leaf node. Beware memory issues 
     * or of completely destroying the whole taxonomy!
     * 
     */
    public function prune(){

        // recurse the children
        $children = $this->getChildren();
        foreach($children as $kid){
            $kid->prune();
            $kid->delete();
        }

        // remove the synonyms
        $synonyms = $this->getSynonyms();
        foreach($synonyms as $syn){
            $this->removeSynonym($syn);
        }

    }

    /**
     * 
     * Makes necessary changes to taxon_names table 
     * to remove a name. Could be called when removing 
     * taxon or synonym
     * 
     */
    private function unassignName($name){

        global $mysqli;

        if(!$this->canEdit()){
            throw new ErrorException("User does not have permission to save changes to Taxon");
            return;
        }

        // we are extra cautious only remove a name if we own it and we do it by primary key
        $result = $mysqli->query("SELECT id FROM taxon_names WHERE name_id = {$name->id} AND taxon_id = {$this->id}");
        if($result->num_rows > 1) throw new ErrorException("Something terrible happened! There are multiple entries in taxon_names for name_id {$name->id} and taxon_id {$this->id}.");
        if($result->num_rows == 0){
            throw new ErrorException("Trying to remove name {$name->id} from taxon {$this->id} when that name isn't assigned to that taxon.");
        }else{
            $result = $mysqli->query("DELETE FROM taxon_names WHERE name_id = {$name->id} AND taxon_id = {$this->id}");
            if($mysqli->affected_rows == 1){
                return true;
            }else{
                throw new ErrorException("Failed to remove {$name->id} from taxon {$this->id} no rows affected.");
                return false;
            }
        }
    }

    /**
     * Not to be confused with public method setAcceptedName
     * This actually does the work of joining the names
     * up.
     * 
     * This can be used to assign accepted names and synonyms 
     * as it just makes necessary changes to the taxon_names table
     * 
     * @return int the id of the row in the taxon_names table or false on failure
     * 
     */
    private function assignName($name){
        
        global $mysqli;

        // we do nothing if the user doesn't have rights to change this taxon
        // They should never get here because interface should stop them
        if(!$this->canEdit()){
            throw new ErrorException("User does not have permission to save changes to Taxon");
            return;
        }

        if(!$name || !$name->getId()){
            throw new ErrorException("Trying to assign non-name to taxon_id {$this->id}. " . print_r($name, true));
            return false;
        }

        error_log("**** assignName **********");

        // is the name already in use in the taxon_names table?
        $result = $mysqli->query("SELECT * FROM taxon_names WHERE name_id = {$name->getId()}");
        if($result->num_rows > 1) throw new ErrorException("Something terrible happened! There are multiple entries in taxon_names for name_id {$name->getId()}.");
        if($result->num_rows == 0){
            // the name is not assigned to any taxon we can go ahead and create the row
            $sql = "INSERT INTO taxon_names (taxon_id, name_id) VALUES ({$this->id}, {$name->getId()})";
            error_log("Not assigned");
            $result = $mysqli->query($sql);
            if($mysqli->affected_rows == 1){
                return $mysqli->insert_id;
            }else{
                throw new ErrorException("Failed to create taxon_names row for taxon_id {$this->id} and name_id {$name->getId()}. {$mysqli->error} . $sql");
                return false;
            }

        }else{

            // the name is assigned to something.
           // print_r($this);
            $row = $result->fetch_assoc();
            
            error_log("Assigned");

            // is it us? If so nothing to do
            if($row['taxon_id'] == $this->id) return $row['id'];

            // it is not so we need to highjack it - but first we double check it isn't in use as an accepted name of another taxon
            $sql = "SELECT * FROM taxa WHERE taxon_name_id = {$row['id']} AND id != {$this->id}";
            error_log($sql);
            $result2 = $mysqli->query($sql);
            if($result2->num_rows > 0){
                error_log("Trying to assign taxon_name {$row['id']} to {$this->id} when it is already in use as an accepted taxon.");
                throw new ErrorException("Trying to assign taxon_name {$row['id']} to {$this->id} when it is already in use as an accepted taxon.");
            }else{

                // now the highjack
                $sql = "UPDATE taxon_names SET taxon_id = {$this->id} WHERE id = {$row['id']}";
                $mysqli->query($sql);
                error_log($sql);
                if($mysqli->error) error_log($mysqli->error);

                if($mysqli->affected_rows == 1){
                    error_log("one row affected");
                    return $row['id'];
                }else{
                    throw new ErrorException("Failed to update taxon_names row {$row['id']} for taxon_id {$this->id} and name_id {$name->id}");
                    return false;
                }

            }

        }


    }

    // ------------ R E L A T I O N S ----------------

    /**
     * We could do this in a more efficient manner but get it working
     * first. Just load all the kids
     * 
     */
    public function getChildren(){

        global $mysqli;

        // currently make no attempt to cache this list as 
        // when we are adding and removing kids it causes havoc
        // this call is quite cheap and objects may already be loaded.
        $this->children = array();

        // we don't have children if we have never been saved.
        if(!$this->getId()) return $this->children;

        // load children based on our id
        $sql = "SELECT t.id FROM taxa as t
            join `taxon_names` as tn on t.`taxon_name_id` = tn.id
            join `names` as n on n.id = tn.`name_id`
            WHERE `parent_id` = {$this->id} 
            AND t.`id` != {$this->id}
            order by n.`name`";
        $result = $mysqli->query($sql); 
        if($mysqli->error){
            echo $mysqli->error;
            echo "\n$sql\n";
            //print_r($this);
        }
        while($row = $result->fetch_assoc()){
            $this->children[] = Taxon::getById($row['id']);
        }

        return $this->children;

    }

    /**
     * Gets the count of children without
     * loading them all
     */
    public function getChildCount(){

        global $mysqli;

        // only run it the first time we are asked
        if($this->childCount == null){
            $sql = "SELECT count(*) as n FROM taxa as t WHERE `parent_id` = {$this->id} AND t.`id` != {$this->id}";
            $result = $mysqli->query($sql); 
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            $result->close();
            $this->childCount = (int)$rows[0]['n'];
        }
 
        return $this->childCount;

    }


    /**
     * Count all the descendants without
     * loading them all
     */
    public function getDescendantCount($taxon_id = false){

        global $mysqli;

        if(!$taxon_id) $taxon_id = $this->getId();

        $sql = "SELECT id FROM taxa as t WHERE `parent_id` = {$taxon_id} AND t.`id` != {$taxon_id}";
        $result = $mysqli->query($sql); 
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->close();

        $count = count($rows);

        foreach($rows as $row){
            $count += $this->getDescendantCount($row['id']);
        }

        return (int)$count;

    }

    public function getDescendants(){

        $descendants = array();

        $kids = $this->getChildren();

        foreach ($kids as $kid) {
            $descendants[] = $kid;
            $descendants = array_merge($descendants, $kid->getDescendants());
        }

        return $descendants;

    }

    public function getAncestors(){
        $ancestors = array();
        $dad = $this;
        while($dad = $dad->getParent()){
            $ancestors[] = $dad;
        }
        return $ancestors;
    }

    public function getAncestorAtRank($rank){
        $dad = $this;
        while($dad = $dad->getParent()){
            if($dad->getRank() == $rank) return $dad;
        }
        return null;
    }

    /**
     * Return the path for a name
     * 
     */
    public static function getPath($name){

        // is this name placed 
        $taxon = Taxon::getTaxonForName($name);
        if($taxon->getId()){

            // it is placed in the taxonomy so we need to
            // build a proper path
            $parts = array();
            $ancestors = array_reverse($taxon->getAncestors());
            foreach ($ancestors as $anc) {
                $parts[] = $anc->getAcceptedName()->getPrescribedWfoId();
            }

            $path =  "/" . implode('/', $parts) . "/" . $taxon->getAcceptedName()->getPrescribedWfoId();

            if($taxon->getAcceptedName() != $name){
                // the name is a synonym so it is on the end of the taxon path 
                // with a dollar
                $path .= $path . "$" . $name->getPrescribedWfoId();
            }

            return $path;

        }else{
            // it is unplaced so has no path but itself!
            return $name->getPrescribedWfoId();
        }

    }

    /**
     * Return the paths of all the descendants
     * of the  without loading all the 
     * taxon and name objects for efficiency
     * @param name is the 
     * @param absolute will include path from root of tree
     */
    public static function getDescendantPaths($name, $absolute = false){

        $paths = array();

        $taxon = Taxon::getTaxonForName($name);
        if($taxon->getId()){
            if($taxon->getAcceptedName() == $name){

                // we might have children so start work
                if($absolute) $current_path = Taxon::getPath($name);
                else $current_path = '';

                $wfo = $name->getPrescribedWfoId();

                // recursively build the paths
                Taxon::add_paths_elements($wfo, $current_path, $paths);

            }
        }

        return $paths;

    }

    private static function add_paths_elements($wfo, $current_path, &$paths){

        global $mysqli;

        // get all the taxa below the taxon with this wfo
        // and all the names associated with them.
        $sql = "SELECT 
            i.`value` as 'wfo', tn.name_id, atn.name_id as accepted_name_id,  atn.name_id = tn.name_id as 'accepted'
            FROM 
            taxon_names as tn 
            join `names` as n on tn.name_id = n.id 
            join identifiers as i on n.prescribed_id = i.id
            join taxa as t on tn.taxon_id = t.id
            join taxon_names as atn on t.taxon_name_id = atn.id
            WHERE i.kind = 'wfo'
            AND t.parent_id in 
            (
                SELECT t.id FROM 
                taxon_names as tn 
                join `names` as n on tn.name_id = n.id 
				join identifiers as i on n.prescribed_id = i.id
                join taxa as t on tn.taxon_id = t.id
                WHERE i.kind = 'wfo'
                AND i.`value` = '$wfo'
            )
            order by accepted_name_id, accepted desc";
                
        $response = $mysqli->query($sql);
        if($response->num_rows > 0){

            $current_path_accepted = $current_path;
            $last_name_id = null; // names may occur multiple times
            while($row = $response->fetch_assoc()){

                // accepted names will come before any synonyms

                if($row['accepted']){

                    $current_path_accepted = $current_path;
                    $current_path_accepted .= $current_path ? "/": "";
                    $current_path_accepted .= $row['wfo'];
                    $paths[$row['wfo']] = $current_path_accepted;
   
                    // we might have children so aren't the end of a path      
                    //if($current_path && substr($current_path, -1) != "/") $current_path .= "/";
                  
                    Taxon::add_paths_elements($row['wfo'], $current_path_accepted, $paths);
                }else{
                    // it is a synonym so has to be the end of the path
                    //if($current_path && substr($current_path, -1) != "/") $current_path .= "/";
                    // finally add it
                    $paths[$row['wfo']]= $current_path_accepted . "$" . $row['wfo'];
                }

            }

        }else{
            // no children so this is the end of the line
            // but it might also have synonyms
            //if($current_path) $current_path .= "/";
            //$paths[$wfo] =  $current_path . $wfo;
            $paths[$wfo] =  $current_path;
        }

    } // add_path_element


    public function isRoot(){
        return $this->parent == $this;
    }

    public function getSynonyms(){

        global $mysqli;

        $this->synonyms = array();

        // no synonyms if we don't have an id yet
        if(!$this->getId()) return $this->synonyms;

        $sql = "SELECT name_id FROM taxon_names AS tn JOIN `names` AS n on tn.name_id = n.id WHERE tn.taxon_id = {$this->getId()}";

        // if we aren't root we exclude ourselves
        if($this->getAcceptedName()){
            $sql .= " and tn.name_id != {$this->getAcceptedName()->getId()} ";
        }

        // we need them in alphabetical order by default
        $sql .= " ORDER BY n.name_alpha";

        $result = $mysqli->query($sql); // FIXME should be in some order
        while($row = $result->fetch_assoc()){
            $this->synonyms[] = Name::getName($row['name_id']);
        }


        return $this->synonyms;

    }

    public function addCurator($user){

        global $mysqli;

        if(!$this->canEdit()){
            throw new ErrorException("User does not have permission to save changes to Taxon");
            return;
        }

        $response = new UpdateResponse('AddCurator', true, "Adding a curator id {$user->getId()} to taxon with id {$this->getId()}.");

        $sql = "INSERT INTO `users_taxa` (`user_id`, `taxon_id`) VALUES ( {$user->getId()}, {$this->getId()} );";
        $mysqli->query($sql);
        if($mysqli->error){
            error_log($mysqli->error);
            error_log($sql);
            $response->children[] = new UpdateResponse('AddCurator', false, $mysqli->error);
            $response->children[] = new UpdateResponse('AddCurator', false, $sql);
        } 

        // All curators are editors
        if(!$user->isEditor()){
            $user->setRole('editor');
            $user->save();
        }

        // force refresh of editors on next call
        $this->editors = null;
        $this->curatorIds = null;


        $response->consolidateSuccess();

        return $response;

    }

    public function removeCurator($user){

        global $mysqli;

        if(!$this->canEdit()){
            throw new ErrorException("User does not have permission to save changes to Taxon");
            return;
        }

        $response = new UpdateResponse('RemoveCurator', true, "Removing a curator id {$user->getId()} to taxon with id {$this->getId()}.");

        $sql = "DELETE FROM `users_taxa` WHERE `user_id` = {$user->getId()} AND `taxon_id` =  {$this->getId()};";
        $mysqli->query($sql);
        if($mysqli->error){
            error_log($mysqli->error);
            error_log($sql);
            $response->children[] = new UpdateResponse('RemoveCurator', false, $mysqli->error);
            $response->children[] = new UpdateResponse('RemoveCurator', false, $sql);
        }

        // if the user is no longer a curator of anything then they can't be an editor (unless they are a god)
        if(count($user->getTaxaCurated()) < 1 && !$user->isGod()){
                $user->setRole('nobody');
                $user->save();
        }

        // force refresh of editors on next call
        $this->editors = null;
        $this->curatorIds = null;

        $response->consolidateSuccess();
        return $response;

    }

    /**
     * This returns the ids of the curators
     *  these are specifically assigned to this taxon
     */
    public function getCuratorIds(){

        global $mysqli;

        if(!$this->curatorIds){
            $this->curatorIds = array();
            $sql = "SELECT `user_id` FROM `users_taxa` WHERE `taxon_id` = {$this->getId()}";
            $result = $mysqli->query($sql);
            while($row = $result->fetch_assoc()){
                $this->curatorIds[] = $row['user_id'];
            }
        } 

        return $this->curatorIds;
    
    }

    public function getCurators(){

        // if we haven't been saved then there are none
        if(!$this->getId()) return array();

        // this should used the cached lists if they are there
        $all = $this->getEditors();
        $curatorIds = $this->getCuratorIds();

        $out = array();
        foreach ($all as $e) {
            if(in_array($e->getId(), $curatorIds)) $out[] = $e;
        }
        return $out;


    }

    /**
     * This returns users who can edit this taxon
     * including the curators (owners) of the taxon
     */
    public function getEditors(){

        // if we haven't been saved then there are none
        if(!$this->getId()) return array();

        // editors are anyone who is a curator of this
        // taxon or any of its parent taxa

        if(!$this->editors){
            $ancestors = $this->getAncestors();
            array_unshift($ancestors, $this);  // we are not in our ancestors by default

            $curatorIds = array();
            foreach ($ancestors as $anc) {
                $curatorIds = array_merge($curatorIds, $anc->getCuratorIds());
            }
            $curatorIds = array_unique($curatorIds);

            $this->editors = array();
            foreach ($curatorIds as $id) {
                $this->editors[] = User::loadUserForDbId($id);
            }

            // be nice and sort alphabetically
            usort($this->editors, function($a, $b){
                $al = strtolower($a->getName());
                $bl = strtolower($b->getName());
                if ($al == $bl) return 0;
                return ($al > $bl) ? +1 : -1;
            });
        } 
        
        return $this->editors;

    }


    /**
     * Whether the user passed in is a curator of this
     * taxon or not.
     * 
     */
    public function isCurator($user){

        // if we haven't been saved to the db yet then answer is no
        if(!$this->getId()) return false;

        return in_array($user->getId(), $this->getCuratorIds());
    }

    public function canEdit(){

        $user = unserialize($_SESSION['user']);

        if($user->isGod()) return true; // gods can do anything.

        // if we haven't been saved to the db yet then answer is yes
        if(!$this->getId()) return true;

        // we can't be sure the user is the same object 
        // so we do it on ids
        $editors = $this->getEditors();
        foreach ($editors as $ed) {
            if($ed->getId() == $user->getId()) return true;
        }
        return false;

    }


} // Taxon