<?php

/**
 * 
 * Thing with the functionality to suggest 
 * names with a confidence level
 * 
 */
class NameMatcher{

    protected String $queryString;
    protected Array $name_parts = array();
    protected ?String $rank = null;
    protected ?String $authors = null;

    function alphaMatch($queryString){

        global $mysqli;
    
        // sanitize queryString
        $queryString = preg_replace('/[^A-Za-z- ]/', '', $queryString);

        $matches = new NameMatches();
        $matches->queryString = $queryString;
        $matches->nameParts = array();
        $matches->rank = null;
        $matches->authors = null;
        $matches->distances = array();
        $matches->names = array();

        $response = $mysqli->query("SELECT id, `name_alpha` FROM `names` WHERE `name_alpha` LIKE '$queryString%' ORDER BY `name_alpha` LIMIT 50");

        while($row = $response->fetch_assoc()){
            $matches->names[] = Name::getName($row['id']);
            $matches->distances[] = levenshtein($row['name_alpha'], $queryString);
        }

        return $matches;

    }

    // n.b. this method is duplicated in the cli tool
    function darwinCoreMatch($nameString, $authorsString, $rankString, $familyString){

        global $mysqli;

        // clean up the name first
        $nameString = trim($nameString);

        // the name may include a rank abbreviation
        $nameParts = explode(' ', $nameString);
        $newNameParts = array();
        foreach($nameParts as $part){
            if(Name::isRankWord($part)){
                // if we find a part that looks like a rank word
                // we don't add it to the new version of the name
                // but if we don't have a rank designation we use it for that.
                if(!$rankString) $rankString = $part;
            }else{
                $newNameParts[] = $part;
            }
        }
        $nameString = implode(' ', $newNameParts);

        // clean the authors - not much to do here
        $authorsString = trim($authorsString);

        // normalize the rank if we can.
        $rankString = Name::isRankWord($part);

        // clean the family - not much to do here
        $familyString = preg_replace('/[^A-Za-z]/', '', $familyString);

        // ready to start matching
        $matches = new NameMatches();
        $matches->queryString = "$nameString $authorsString $rankString $familyString";
        $matches->nameParts = $newNameParts;
        $matches->rank = $rankString;
        $matches->authors = $authorsString;
        $matches->distances = array(); // to be filled in below
        $matches->names = array(); // to be filled in below

        // perfect scores are 100% match of name and authors with no homonyms.
        $response = $mysqli->query("SELECT `id`, `authors`  FROM `names` WHERE `name_alpha` = '$nameString' AND `authors` = '$authorsString'");
        
        // if we only have a single match that is perfect - unless we have homonyms!
        if($response->num_rows == 1){
            $row = $response->fetch_assoc();
            // bingo single match so return it with a perfect score
            $matches->names[] = Name::getName($row['id']);

            // do we have any that match the name but not the author string. That would be a downgrade.
            $othersResponse = $mysqli->query("SELECT `id` FROM `names` WHERE `name_alpha` = '$nameString' AND (`authors` != '$authorsString' OR `authors` is null)");
            if($othersResponse->num_rows > 0){
                
                // down a point as there are homonyms
                $matches->distances[] = 1;

                // add the other names in for reference
                while($othersRow = $othersResponse->fetch_assoc()){
                    $matches->names[] = Name::getName($othersRow['id']);
                    $matches->distances[] = 2;
                }

                $othersResponse->close();

            }else{
                // perfect score as no homonyms
                $matches->distances[] = 0;
            }

            $response->close();
            return $matches;
        }

        // this really shouldn't happen as we control for homonyms with same author strings!
        if($response->num_rows > 1){
            while($row = $response->fetch_assoc()){
                $matches->names[] = Name::getName($row['id']);
                $matches->distances[] = 2;
            }
            $response->close();
            return $matches;
        }

        // got to here so there is no name & authors matches
        // try just name match - these get a score of 
        $response = $mysqli->query("SELECT `id` FROM `names` WHERE `name_alpha` = '$nameString'");
        if($response->num_rows > 0){
            while($row = $response->fetch_assoc()){
                $matches->names[] = Name::getName($row['id']);
                $matches->distances[] = 2;
            }
            $response->close();
            return $matches;
        }

        // no name matches at all so fuzz up the names and try that.
        $fuzzed = '';
        foreach ($newNameParts as $part) {
            $fuzzed .= $this->fuzzWord($part);
            $fuzzed .= ' ';
        }
        $fuzzed = trim($fuzzed);

        $sql = "SELECT `id` FROM `names` WHERE `name_alpha` LIKE '$fuzzed' ORDER BY `name_alpha` LIMIT 100";
        $response = $mysqli->query($sql);
        error_log($sql);

        if($response->num_rows > 0){
            while($row = $response->fetch_assoc()){
                $matches->names[] = Name::getName($row['id']);
                $matches->distances[] = 3;
            }
            $response->close();
            return $matches;
        }

        // well we didn't find anything!
        return $matches;

    }

    /**
     * Parse a string to find the best matches
     * 
     * @param string $query Presumed to be a reasonably well formed botanical name
     * @param bool $incomplete Flag if the name might be truncated. Used to respond to someone typing a name in.
     */
    function stringMatch($queryString, $incomplete=false){

        // remove any html crap
        $this->queryString = strip_tags($queryString);

        // FIXME: More sanitization of input?

        // tokenize it
        $words = preg_split('/\s+/', $this->queryString, -1, PREG_SPLIT_NO_EMPTY);

        foreach($words as $word){

            // if we haven't found a rank yet and this is one
            // then we take that as being the rank
            $rank = Name::isRankWord($word);
            if($rank){
                $this->rank = $rank;
                continue;
            } 

            // if it is the third word and it starts with a capital then it will be an author
            if(count($this->name_parts) == 2 && preg_match('/^[A-Z]/', $word)) break;

            // is this a word that has been used in a taxon name?
            if($this->isNameWord($word)) $this->name_parts[] = $word;

            // If we have three words that are used in taxon names then we give up
            if(count($this->name_parts) == 3) break; 
            
        }

        // everything beyond the last name word has to be the author string
        $last_part = "";
        if(count($this->name_parts)){
            $last_part = array_slice($this->name_parts, -1)[0];
        }

        $this->authors = trim( substr($this->queryString, strripos($this->queryString,$last_part) + strlen($last_part)) );

        // we are primed and ready to rock.

        return $this->match();

    }

    /**
     * check if the word is used as a 
     * name in the taxonomy
     */
    function isNameWord($word){

        global $mysqli;

        $fuzz = $this->fuzzWord($word, 1);

        $result = $mysqli->query(
            "SELECT count(*) as n
            FROM `names`
            WHERE `name` LIKE '$fuzz'
            OR `genus` LIKE '$fuzz'
            OR `species` LIKE '$fuzz'"
        );
        $row = $result->fetch_assoc();
        if($row['n']) return true;
        else return false;

    }

    // function duplicated in cli matching_by_name
    function isRankWord($word){

        global $ranks_table;

        $word = strtolower($word);
        foreach($ranks_table as $rank => $rankInfo){

            // does it match the rank name
            if(strtolower($word) == $rank) return $rank;

            // does it match the official abbreviation
            if($word == strtolower($rankInfo['abbreviation'])) return $rank;

            // does it match one of the known alternatives
            foreach($rankInfo['aka'] as $aka){
                if($word == strtolower($aka)) return $rank;
            }

        }

        // no luck so it isn't a rank word we know of
        return false;

    }

    function match(){

        global $mysqli;
        $out = array();

        // the object we will return
        $matches = new NameMatches();
        $matches->queryString = $this->queryString;
        $matches->nameParts = $this->name_parts;
        $matches->rank = $this->rank;
        $matches->authors = $this->authors;
        $matches->distances = array();
        $matches->names = array();

        // do nothing if we have nothing to work with
        if(!trim($this->queryString)) return $matches;


        // if the query string ends in a space then they may be looking for more
        // e.g they have typed a genus name and are looking for a list of species in the genus
        $want_more = substr($this->queryString, -1, 1) == ' ' ? true : false;

        // next stage we need a bunch of hits to look at

        $sql = "SELECT * FROM `names` ";

        switch (count($this->name_parts)) {

            // we are below species level
            case '3':
                $sql .= " WHERE `genus` LIKE '{$this->name_parts[0]}'";
                $sql .= " AND `species` LIKE '{$this->fuzzWord($this->name_parts[1])}'";
                $sql .= " AND `name` LIKE '{$this->fuzzWord($this->name_parts[2])}'";
                break;
            
            // we are below genus level
            case '2':
                if($want_more){
                    // will find species or subspecies
                    $sql .= " WHERE `genus` LIKE '{$this->name_parts[0]}'";
                    $sql .= " AND ( `name` LIKE '{$this->fuzzWord($this->name_parts[1])}' OR `species` LIKE '{$this->name_parts[1]}' )";
                }else{
                    $sql .= " WHERE `genus` LIKE '{$this->fuzzWord($this->name_parts[0])}'";
                    $sql .= " AND `name` LIKE '{$this->fuzzWord($this->name_parts[1])}'";
                }

                break;
            
            // we are a monomial genus level
            case '1':
                if($want_more){
                    // return species where the genus matches
                    $sql .= " WHERE `genus` LIKE '{$this->name_parts[0]}' ";
                }else{
                    // just return where the actual name matches
                    $sql .= " WHERE `name` LIKE '{$this->fuzzWord($this->name_parts[0])}'";
                }
                
                break;

            // desperation just look for author string!
            default:
                $sql .= " WHERE `authors` LIKE '{$this->authors}'";
                break;
        }

        $sql .= " LIMIT 1000";


        $result = $mysqli->query($sql);


        // Score them all
        $candidates = array();
        while($row = $result->fetch_assoc()){
            $candidates[] = $this->scoreRow($row);
        }

        // sort them all
        usort($candidates, function($a, $b){
                if ($a['distance'] == $b['distance']) return 0;
                return ($a['distance'] < $b['distance']) ? -1 : 1;
            });

        // we return a subset maximum of 50 objects
        foreach(array_slice($candidates, 0, 50) as $m){
            $matches->names[] =  Name::getName($m['row']['id']);
            $matches->distances[] = $m['distance'];
        }
        
        return $matches;

    }

    function scoreRow($row){

        $out = array();
        $out['row'] = $row;

        switch (count($this->name_parts)) {

            // we are below species level
            case '3':
                $out['genus'] = levenshtein($row['genus'] ?? '', $this->name_parts[0]);
                $out['species'] = levenshtein($row['species'] ?? '', $this->name_parts[1]);
                $out['name'] = levenshtein($row['name'] ?? '', $this->name_parts[2]);
                $out['authors'] = levenshtein($row['authors'] ?? '', $this->authors);
                break;
            
            // we are below genus level
            case '2':
                $out['genus'] = levenshtein($row['genus'] ?? '', $this->name_parts[0]);
                $out['name'] = levenshtein($row['name'] ?? '', $this->name_parts[1]);
                $out['species'] = levenshtein($row['species'] ?? '', "");
                $out['authors'] = levenshtein($row['authors'] ?? '', $this->authors);
                break;
            
            // we are a monomial genus level
            case '1':
                $out['name'] = levenshtein($row['name'] ?? '', $this->name_parts[0]);
                $out['genus'] = levenshtein($row['genus'] ?? '', "");
                $out['species'] = levenshtein($row['species'] ?? '', "");
                $out['authors'] = levenshtein($row['authors'] ?? '', $this->authors);
                break;

            // desperation just look for author string!
            default:
                $out['name'] = levenshtein($row['name'] ?? '', "");
                $out['genus'] = levenshtein($row['genus'] ?? '', "");
                $out['species'] = levenshtein($row['species'] ?? '', "");
                $out['authors'] = levenshtein($row['authors'] ?? '', $this->authors);
                break;
        }

        $out['distance'] = $out['name'] + $out['genus'] +$out['species']+ $out['authors'];

        return $out;

    }

    function fuzzWord($word, $strength = 2){
        
        // don't double fuzz a word
        if(substr($word, -1) == '%') return $word;

        // don't over shorten words
        if(strlen($word) < 5) return "$word%";

        // chop off a few chars an and SQL wild card
        $shorter = substr($word, 0, -$strength);
        return "$shorter%";

    }

}