<?php

// this generates a csv file describing the impact of a proposed taxonomic import.
// called repeatedly for each child

/*

1 process taxon
2 if it has children add it to the queue to be processed in another call

*/

$headers = array(
        'wfo',
        'name',
        'in bounds',
        'consequence',
        'structure ok',
        'rank ok',
        'status ok',
        'parent name',
        'current rank',
        'current status',
        'current placement',
        'current placement wfo',
        'current placement name',
        'new placement',
        'new placement wfo',
        'new placement name',
        'new rank',
        'new status'
);

// keep track of how many names we are processing
// or we will run out of memory in some tree topologies.
$name_process_count = 0; 
$name_process_max = 5000;

// are we called with the root taxon?
if(@$_GET['root_taxon_wfo']){

    // keep a handle on which root we are using
    $_SESSION['import_root'] = $_GET['root_taxon_wfo'];

    // the boundary taxon specifies where names can be moved from
    // typically it will be the root but it could be above the root
    // if, for example the a genus is being imported and it is OK to
    // move taxa within the family
    $_SESSION['import_boundary'] = $_GET['boundary_taxon_wfo'];

    // initialize the queue
    $_SESSION['impact_queue'] = array();
 
    // initialize name tracker - to compare with file at end
    $_SESSION['impact_track'] = array();


    // root name
    $name = Name::getName($_GET['root_taxon_wfo']);

    // initialize the csv 
    $file_name = "../bulk/csv/{$name->getNameString()}_{$_GET['root_taxon_wfo']}_impact.csv";
    $_SESSION['impact_file'] = $file_name;
    $out = fopen($file_name, 'w');

    // on first open we write the headers
    fputcsv($out, $headers, escape: "\\");

    // start it off
    process_name($name, $out, $headers);

}else{

    // open csv file to append rows
    $out = fopen($_SESSION['impact_file'], 'a');

    // if the queue is empty then stop
    if(count($_SESSION['impact_queue']) == 0){

        // we have reached the end of the taxonomy in rhakhis
        // what about the names in the data table that didn't
        // fall under the taxonomy?

        $track = $_SESSION['impact_track'];

        // look at all the names in the table 
        // below the root taxon and check they are not
        // out of bounds in rhakhis.
        $root_wfo = $_SESSION['import_root'];
        crawl_table_tree($root_wfo, $out, $headers);

        echo "<p>Impact report is complete and can be downloaded under the files tab.</p>";
        $uri = "index.php?action=view&phase=csv";
        echo "<script>window.location = \"$uri\"</script>";
        fclose($out);
        exit;
    
    }else{
    
        // get the next wfo to process off the queue
        $wfo = array_shift($_SESSION['impact_queue']);

        // this will prevent all the children and
        // all the unplaced names being loaded at once
        process_children($wfo, $out, $headers);
        process_unplaced($wfo, $out, $headers);

    }

}

fclose($out);
// call self to process the stuff that will have been put in the queue
$uri = "index.php?action=taxonomy_impact_report&table={$_GET['table']}";

echo "<h2>Generating Impact Report</h2>";
echo "<p>Working ... </p>";
$queue_size = count($_SESSION['impact_queue']);
echo "<p>Queue size: ". number_format($queue_size, 0). "</p>";
$tracker_size = count($_SESSION['impact_track']);
echo "Names processed: ". number_format($tracker_size, 0). "</p>";
echo "<p>Queue size will go up and down as we do a widthwise crawl of the taxonomic tree. Names with children or associated unplaced names are added to the list then processed with another call.</p>";
//render_queue($_SESSION['impact_queue']);
echo "<script>window.location = \"$uri\"</script>";


/**
 * We use this to crawl the tree in the 
 * data table to be sure we have accounted
 * for all the names in it.
 * Some of them may have been out of bounds
 * i.e. we crawled the tree in rhakhis before
 * if the name in the tree in the table i not below
 * the root in rhakhis but belongs to some other family 
 * it won't have been found.
 * 
 */

function crawl_table_tree($wfo, $out, $headers){

    global $mysqli;

    // if we haven't processed it already then process it
    if(!in_array($wfo, $_SESSION['impact_track']) && preg_match('/wfo-[0-9]{10}/', $wfo)){
        $name = Name::getName($wfo);
        if($name->getId()) process_name($name, $out, $headers);
        else echo "<p>$wfo not found it Rhakhis</p>";
    }

    // we now process its children
    $table = $_GET['table'];
    $response = $mysqli->query("SELECT rhakhis_wfo FROM `rhakhis_bulk`.`$table` where rhakhis_parent = '$wfo' AND length(`rhakhis_wfo`) = 14");
    while($row = $response->fetch_assoc()){
        crawl_table_tree($row['rhakhis_wfo'], $out, $headers);
    }


}

function render_queue($queue){

    echo "<ul>";
    if(!$queue) echo "<li>~ empty ~</li>";
    foreach($queue as $wfo){
        $name = Name::getName($wfo);
        echo "<li><strong>$wfo:</strong> {$name->getFullNameString()}</li>";
    }
    echo "</ul>";

}

/**
 * 
 * Given a WFO ID it gets the children
 * of that taxon and puts their names through
 * and their 
 * 
 */
function process_children($wfo, $out, $headers){

    //global $name_process_count;
    //global $name_process_max;

    $name = Name::getName($wfo);
    $taxon = Taxon::getTaxonForName($name);
    $children = $taxon->getChildren();

   
    $processed_this_call = 0;
    foreach($children as $kid){

        // if we haven't done it before we consider doing it
        if(!in_array($kid->getAcceptedName()->getPrescribedWfoId(),  $_SESSION['impact_track'])){

            // if we haven't reached the max
            //if($name_process_count <= $name_process_max){
                process_name($kid->getAcceptedName(), $out, $headers);
            /*
            }else{
                // we have a name to process but have run out of memory
                // add the parent back to the queue 
                $queue = $_SESSION['impact_queue'];
                if(!in_array($name->getPrescribedWfoId(), $queue)){
                    $queue[] = $name->getPrescribedWfoId();
                }
                $_SESSION['impact_queue'] = $queue;
                break;
            }
            */
            
        }      
        
    }

}

function process_unplaced($wfo, $out, $headers){

    //global $name_process_count;
    //global $name_process_max;

    $name = Name::getName($wfo);
    // if the name is a genus or species then we process each
    if( $name->getRank() == 'genus' || $name->getRank() == 'species' ){
        $finder = new UnplacedFinder($name->getPrescribedWfoId(), 0, 5000, true); // just a page of 5,000 and include deprecated names

        foreach($finder->unplacedNames as $unplaced){
            // if we haven't done it before we consider doing it            
            if(!in_array($unplaced->getPrescribedWfoId(),  $_SESSION['impact_track'])){
                process_name($unplaced, $out, $headers);
            } 
        }
    }
}

function process_name($name, $out, $headers){

    global $mysqli;
    //global $name_process_count;

    //$name_process_count++;
    
    // track the names we have done
    $track = $_SESSION['impact_track'];
    $track[] = $name->getPrescribedWfoId();
    $_SESSION['impact_track'] = $track;

    $out_row = array();

    // actually check the name
    $out_row['consequence'] = ''; // the status to be completed below as 0
    $out_row['rank_ok'] = ''; // rank_ok
    $out_row['status_ok'] = ''; // status_ok
    
    $out_row['wfo'] = $name->getPrescribedWfoId();
    $out_row['name'] = strip_tags($name->getFullNameString());
    $out_row['current rank'] = $name->getRank();
    $out_row['current status'] = $name->getStatus(); 

    // is it an accepted name of a taxon?
    $rhakhis_status = null;
    $taxon = Taxon::getTaxonForName($name);
    if($taxon->getId()>0){

        // it is placed within Rhakhis
        if($taxon->getAcceptedName() == $name){

            // it is accepted within Rhakhis

            $rhakhis_role = "accepted";

            $out_row['current placement'] = "accepted within";
            $out_row['current placement wfo'] = $taxon->getParent()->getAcceptedName()->getPrescribedWfoId();
            $out_row['current placement name'] = strip_tags($taxon->getParent()->getAcceptedName()->getFullNameString());


            // run through the synonyms
            $synonyms = $taxon->getSynonyms();
            foreach ($synonyms as $synonym) {
                process_name($synonym, $out, $headers);
            }

            // if we have children add OURSELVES to the queue
            $queue = $_SESSION['impact_queue'];
            if(count($taxon->getChildren()) > 0) $queue[] = $name->getPrescribedWfoId();
            $_SESSION['impact_queue'] = $queue;

        }else{

            // it is a synonym within Rhakhis

            $rhakhis_role = "synonym";

            $out_row['current placement'] = "synonym of";
            $out_row['current placement wfo'] = $taxon->getAcceptedName()->getPrescribedWfoId();
            $out_row['current placement name'] = strip_tags($taxon->getAcceptedName()->getFullNameString());

        }

        // the name is placed in the taxonomy but is it placed
        // below the root taxon and therefore available to be moved
        // if it isn't then it won't be freed up by the removal part of the 
        // import routine
        $out_row['in bounds'] = "NO";
        $ancestors = $taxon->getAncestors();
        foreach($ancestors as $anc){
            if($anc->getAcceptedName()->getPrescribedWfoId() == $_SESSION['import_boundary']){
                $out_row['in bounds'] = "YES";
                break;
            }
        }

        // edge case - if it is the root then it is in bounds
        if($taxon->getAcceptedName()->getPrescribedWfoId() == $_SESSION['import_root']){
            $out_row['in bounds'] = "YES";
        }

 
    }else{

        // it isn't placed in rhakhis so it is also in bounds
        $rhakhis_role = "unplaced";
        $out_row['in bounds'] = "YES";

        $out_row['current placement'] = "unplaced";
        $out_row['current placement wfo'] = ""; // placement wfo
        $out_row['current placement name'] = ""; // placement name

    }

    // get the row from the database
    $table = $_GET['table'];
    $response = $mysqli->query("SELECT * FROM `rhakhis_bulk`.`$table` WHERE `rhakhis_wfo` = '{$name->getPrescribedWfoId()}';");
    if($mysqli->error){
        echo $mysqli->error;
        exit;
    }
    $rows = $response->fetch_all(MYSQLI_ASSOC);

    if($rows){

        $data_row = $rows[0];

        if($rhakhis_role == "accepted"){

            // accepted in rhakhis

            if($data_row['rhakhis_parent']){
                // an accepted name in the data table
        
                $out_row['new placement'] = "accepted within";

                // are the parents the same?
                if($taxon->getParent()->getAcceptedName()->getPrescribedWfoId() == $data_row['rhakhis_parent']){
                    // parentage has not changed
                    $out_row['consequence'] = "no change";
                    $out_row['new placement wfo'] = $data_row['rhakhis_parent'];
                    $out_row['new placement name'] = strip_tags($taxon->getParent()->getAcceptedName()->getFullNameString());
                    
                }else{
                    // parent has changed
                    $out_row['consequence'] = "move";
                    $out_row['new placement wfo'] = $data_row['rhakhis_parent'];
                    $new_parent_name = Name::getName($data_row['rhakhis_parent']);
                    $out_row['new placement name'] = strip_tags($new_parent_name->getFullNameString());
                }

            }elseif($data_row['rhakhis_accepted']){

                // a synonym in the data table
                $out_row['new placement'] = "synonym of";

                $out_row['consequence'] =  "sunk";
                $out_row['new placement wfo'] = $data_row['rhakhis_accepted'];
                $new_accepted_name = Name::getName($data_row['rhakhis_accepted']);
                $out_row['new placement name'] = strip_tags($new_accepted_name->getFullNameString());


            }else{

                // unplaced in the data table

                if($data_row['rhakhis_wfo'] == @$_GET['root_taxon_wfo']){
                    $out_row['consequence'] = "root";
                    $out_row['new placement'] = "root";
                }else{
                    $out_row['consequence'] = "removed";
                    $out_row['new placement'] = "unplaced";
                }
                $out_row['new placement wfo'] = ""; // new wfo
                $out_row['new placement name'] = ""; // new name

            }

        }elseif($rhakhis_role == "synonym"){

            // synonym in rhakhis

             if($data_row['rhakhis_parent']){

                // an accepted name in the data table
                $out_row['consequence'] = "raised";
                $out_row['new placement'] = "accepted in";
                $out_row['new placement wfo'] = $data_row['rhakhis_parent'];
                $new_parent_name = Name::getName($data_row['rhakhis_parent']);
                $out_row['new placement name'] = strip_tags($new_parent_name->getFullNameString());

            }elseif($data_row['rhakhis_accepted']){

                // a synonym in the data table

                // are both synonyms of the same thing?
                if($taxon->getAcceptedName()->getPrescribedWfoId() != $data_row['rhakhis_accepted']){

                    // Moved to a different accepted taxon 
                    $out_row['consequence'] =  "move";
                    $out_row['new placement'] = "synonym of";
                    $out_row['new placement wfo'] = $data_row['rhakhis_accepted'];
                    $new_accepted_name = Name::getName($data_row['rhakhis_accepted']);
                    $out_row['new placement name'] = strip_tags($new_accepted_name->getFullNameString());

                }else{
                    // no change - same accepted parent
                    $out_row['consequence'] = "no change";
                    $out_row['new placement'] = "synonym of";
                    $out_row['new placement wfo'] = $data_row['rhakhis_accepted'];
                    $out_row['new placement name'] = strip_tags($taxon->getAcceptedName()->getFullNameString());
                }


            }else{

                // unplaced in the data table
                if($data_row['rhakhis_wfo'] == @$_GET['root_taxon_wfo']){
                    $out_row['consequence'] = "root";
                    $out_row['new placement'] = "root";
                }else{
                    $out_row['consequence'] = "removed";
                    $out_row['new placement'] = "unplaced";
                }
                $out_row['new placement wfo'] = ""; // new wfo
                $out_row['new placement name'] = ""; // new name

            }


        }else{
            
            // unplaced in rhakhis
            if($data_row['rhakhis_parent']){

                // an accepted name in the data table
                $out_row['new placement'] = "accepted within";

                $out_row['consequence'] = "placed";
                $out_row['new placement wfo'] = $data_row['rhakhis_parent'];
                $new_parent_name = Name::getName($data_row['rhakhis_parent']);
                $out_row['new placement name'] = strip_tags($new_parent_name->getFullNameString());

            }elseif($data_row['rhakhis_accepted']){

                // a synonym in the data table
                $out_row['new placement'] = "synonym of";

                $out_row['consequence'] = "placed";
                $out_row['new placement wfo'] = $data_row['rhakhis_accepted'];
                $new_accepted_name = Name::getName($data_row['rhakhis_accepted']);
                $out_row['new placement name'] = strip_tags($new_accepted_name->getFullNameString());

            }else{

                // unplaced in the data table
                $out_row['new placement'] = "unplaced";
                $out_row['consequence'] = "no change";
                $out_row['new placement wfo'] = "";
                $out_row['new placement name'] = "";

            }
        }

        // add the rank and status in
        $out_row['new rank'] = $data_row['rhakhis_rank'];
        $out_row['new status'] = $data_row['rhakhis_status'];

        check_structure($out_row, $data_row, $table);

    }else{

        // not in data table
        $out_row['new placement'] = "unplaced";
        $out_row['consequence'] = "missing";
        $out_row['new placement wfo'] = "";
        $out_row['new placement name'] = "";
        $out_row['new rank'] = "";
        $out_row['new status'] = "";
        $out_row['structure ok'] = 'n/a'; 

    }


    // once we have the output row we have all the info we need to check the new row and status will be correct
    check_rank($out_row, $table);
    check_status($out_row, $table);
    check_name_parts($out_row,  $table);

    // finally write the thing out
    $csv_row = array();
    foreach ($headers as $header) {
        // will throw error if value isn't set in 
        // out_row - which is a good thing.
        $csv_row[] = $out_row[$header];
    }

    fputcsv($out, $csv_row, escape: "\\");

}

function check_structure(&$out_row, $data_row, $table){

    global $mysqli;

    // 'structure ok'
    $out_row['structure ok'] = 'OK';

    // row must only exist once
    $response = $mysqli->query("SELECT count(*) as n FROM `rhakhis_bulk`.`$table` WHERE `rhakhis_wfo` = '{$data_row['rhakhis_wfo']}';");
    if($mysqli->error){
        echo $mysqli->error;
        exit;
    }
    $row = $response->fetch_assoc();
    if($row['n'] > 1){
        $out_row['structure ok'] = "FAIL: Name ({$data_row['rhakhis_wfo']}) must be present in table ONLY ONCE!";
        return;
    }

     // can't have a accepted name and parent name
     if($data_row['rhakhis_accepted'] && $data_row['rhakhis_parent']){
        $out_row['structure ok'] = "FAIL: Both accepted and parent WFO's are set.";
        return;
     }

    // accepted name can't be self
    if($data_row['rhakhis_accepted'] == $data_row['rhakhis_wfo']){
        $out_row['structure ok'] = "FAIL: Accepted name is set as self.";
        return;
    }

    // parent name can't be self
    if($data_row['rhakhis_parent'] == $data_row['rhakhis_wfo']){
        $out_row['structure ok'] = "FAIL: Can't have self as parent taxon.";
        return;
    }

    // parent must exist once in table once
    if($data_row['rhakhis_parent']){
        $response = $mysqli->query("SELECT count(*) as n FROM `rhakhis_bulk`.`$table` WHERE `rhakhis_wfo` = '{$data_row['rhakhis_parent']}';");
        if($mysqli->error){
            echo $mysqli->error;
            exit;
        }
        $row = $response->fetch_assoc();
        if($row['n'] < 1){
            $out_row['structure ok'] = "FAIL: Parent ({$data_row['rhakhis_parent']}) must be present in table.";
            return;
        }
        if($row['n'] >1){
            $out_row['structure ok'] = "FAIL: Parent ({$data_row['rhakhis_parent']}) must be present in table ONLY ONCE!";
            return;
        }
    } // if has parent


    // accepted must exist once in table - or be excepted 
    if($data_row['rhakhis_accepted']){
        $response = $mysqli->query("SELECT * FROM `rhakhis_bulk`.`$table` WHERE `rhakhis_wfo` = '{$data_row['rhakhis_accepted']}';");
        if($mysqli->error){
            echo $mysqli->error;
            exit;
        }
        $rows = $response->fetch_all(MYSQLI_ASSOC);
        if(count($rows) >1){
            $out_row['structure ok'] = "FAIL: Accepted name  ({$data_row['rhakhis_accepted']}) must be present in table ONLY ONCE!";
            return;
        }
        if(count($rows) == 1){

            // is the accepted also a synonym? No chaining!
            if(!$rows[0]['rhakhis_parent'] && $rows[0]['rhakhis_wfo'] != $_SESSION['import_root']){
                $out_row['structure ok'] = "WARNING: Accepted name ({$data_row['rhakhis_accepted']}) for {$data_row['rhakhis_wfo']} isn't an accepted name in the table i.e. doesn't have a wfo_parent or be the root. {$data_row['rhakhis_accepted']} will be unplaced and {$data_row['rhakhis_wfo']} will be ignored. ";
                return;
            }

        }
        if(count($rows) < 1){

            // we haven't found the name in the table. Is it in Rhakhis?
            $accepted_name = Name::getName($data_row['rhakhis_accepted']);
            $accepted_taxon = Taxon::getTaxonForName($accepted_name);

            if($accepted_taxon->getId() && $accepted_taxon->getAcceptedName() == $accepted_name){
                // the name is accepted within Rhakhis
                // is it within bounds?
                $out_of_bounds = true;
                $ancestors = $accepted_taxon->getAncestors();
                array_unshift($ancestors, $accepted_taxon); // add self to the list
                foreach($ancestors as $anc){
                    if($anc->getAcceptedName()->getPrescribedWfoId() == $_SESSION['import_boundary']){
                        $out_of_bounds = false;
                        break;
                    }
                }
                if($out_of_bounds){
                    $out_row['structure ok'] = "FAIL: Accepted name ({$data_row['rhakhis_accepted']}) for {$data_row['rhakhis_wfo']} is in Rhakhis (not the table) but it out of bounds of {$_SESSION['import_boundary']}.";
                    return;
                }

            }else{
                $out_row['structure ok'] = "FAIL: Accepted name ({$data_row['rhakhis_accepted']}) for {$data_row['rhakhis_wfo']} is in Rhakhis (not the table) but it isn't accepted.";
                return;
            }

        }


        // we have one accepted an
    } // if has accepted


}

function check_name_parts(&$out_row, $table){

    global $mysqli;
    global $ranks_table;

    $out_row["parent name"] = "OK"; // defaults to OK

    // if the new placement is not as an accepted name continue
    if($out_row['new placement'] != 'accepted within') return;

    // if there is no parent there is nothing to do
    $parent_wfo = $out_row['new placement wfo'];
    if(!$parent_wfo) return;

    // get a handle on its new rank
    $new_rank = $out_row['new rank'];

    $genus_level = array_search('genus', array_keys($ranks_table));
    $species_level = array_search('species', array_keys($ranks_table));
    $new_level = array_search($new_rank, array_keys($ranks_table));

    // if the name is below species level we check the 
    // parent name is OK
    if($new_level > $genus_level){

        $name = Name::getName($out_row['wfo']);
        $new_parent_name = Name::getName($parent_wfo);

        if($new_parent_name->getRank() == 'genus'){
            if($new_parent_name->getNameString() != $name->getGenusString()){
                $out_row["parent name"] = "FAIL: Name has genus string {$name->getGenusString()} but parent has name string {$new_parent_name->getNameString()}";
            }
        }else{
            // parent is a species or possibly a subgenus

            // for starters they should have the genus part the same because they are in the same genus
            if($new_parent_name->getGenusString() != $name->getGenusString()){
                // actually going to die here because this should be fixed in incoming data.
                $out_row["parent name"] = "FAIL: Name has genus string {$name->getGenusString()} but parent has genus string {$new_parent_name->getGenusString()}";
            }

            // for seconds they should have the correct species part if they are below species level
            if($new_level > $species_level){
                if($new_parent_name->getNameString() != $name->getSpeciesString()){
                    // actually going to die here because this should be fixed in incoming data.
                    $out_row["parent name"] = "FAIL: Name has species string {$name->getSpeciesString()} but parent species has name string {$new_parent_name->getNameString()}";
                }
            }

        }
        
    }


}


function check_rank(&$out_row, $table){

    global $mysqli;
    global $ranks_table;

    // if the rank isn't set in the data file
    // we use the one in rhakhis
    $new_rank = $out_row['new rank'];
    if(!$new_rank) $new_rank = $out_row['current rank'];

    // rank must be appropriate for what the parent will have
    if($out_row['new placement'] == 'accepted within'){

        // get the parent and find out their rank
        $parent_wfo = $out_row['new placement wfo'];

        // is it in the data file?
        $response = $mysqli->query("SELECT * FROM `rhakhis_bulk`.`$table` WHERE `rhakhis_wfo` = '$parent_wfo';");
        if($mysqli->error){
            echo $mysqli->error;
            exit;
        }
        $rows = $response->fetch_all(MYSQLI_ASSOC);
        if($rows){
            // we have it in the data file
            $parent_rank = $rows[0]['rhakhis_rank'];
        }

        // if we haven't got it from the data file then load it from rhakhis
        if(!$parent_rank){
            $parent_name = Name::getName($parent_wfo);
            $parent_rank = $parent_name->getRank();
        }

        if(in_array($new_rank, $ranks_table[$parent_rank]['children'])){
            // we are ok to be a child of that parent
            $out_row['rank ok'] = "OK";
        }else{
            // we are not OK to be a child of that parent
            $out_row['rank ok'] = "NO: $new_rank can't be a child of $parent_rank";
        }

    }else{
        // rank can be anything if they are not an accepted name
        $out_row['rank ok'] = "OK";
    }
 

}


function check_status(&$out_row, $table){

    // if the status isn't set in the data file
    // we use the one in rhakhis
    $new_status = $out_row['new status'];
    if(!$new_status) $new_status = $out_row['current status'];

    // is the new placement as an accepted name
    if($out_row['new placement'] == 'accepted within'){

        // it is going to be an accepted name
         if($new_status == 'valid' || $new_status == 'conserved' || $new_status == 'sanctioned'){
            $out_row['status ok'] = 'OK';
         }else{
            $out_row['status ok'] = 'NO: You must have a status of valid, conserved or sanctioned to be the accepted name of a taxon';
         }

    }elseif($out_row['new placement'] == 'synonym of'){

        // it is going to be a synonym so can be anything but deprecated
        if($new_status == 'deprecated'){
            $out_row['status ok'] = "NO: You can't have a status of 'deprecated' and be placed in the taxonomy";
        }else{
            $out_row['status ok'] = 'OK';
        }

    }else{
        // it can be anything
        $out_row['status ok'] = 'OK';
    }

    

}


