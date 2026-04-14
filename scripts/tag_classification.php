<?php
require_once('../config.php');

/*

    Will create an entry in the previous classifications table for any name
    who's role has changed since the last classification stored in previous classifications.


    In proceedural style this is described as 
    - for each name record
    - Calculate its current role in the taxonomy and parent (or accepted) placement
    - Look to the most recent previous classification for this name record.
    - If the current role or placement are different add a new entry in the previous_placements table.

    We'll see if we can do that in a more efficient way with nested SQL selects!


CREATE TABLE `previous_placements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name_id` int NOT NULL COMMENT 'The id of the name that is placed in the classification.',
  `classification` varchar(8) NOT NULL,
  `role` enum('accepted','synonym','unplaced','deprecated') DEFAULT NULL,
  `placed_in` int DEFAULT NULL COMMENT 'Where the name was placed - either as a synonym or as a child within an accepted name. Null for unplaced names and deprecated names.',
  PRIMARY KEY (`id`),
  UNIQUE KEY `classification_unique` (`classification`,`name_id`) USING BTREE COMMENT 'Each name can only be in a classificaion once.',
  KEY `name_placed` (`name_id`) USING BTREE,
  KEY `placement` (`name_id`,`placed_in`) USING BTREE,
  KEY `classification` (`classification`) USING BTREE,
  KEY `placement_link_idx` (`placed_in`),
  CONSTRAINT `name_link` FOREIGN KEY (`name_id`) REFERENCES `names` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `placement_link` FOREIGN KEY (`placed_in`) REFERENCES `names` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1703913 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;



*/

// date for classification must be passed in.
if(count($argv) < 2 || !preg_match('/^[0-9]{4}-[0-9]{2}$/', $argv[1]) ){
    echo "\nYou must provide a publish date in the format 2023-06\n";
    exit;
}

$classification = $argv[1];

echo "\nTagging with classification called: {$classification}";

$check_sql = "SELECT count(*) as n FROM previous_placements WHERE classification = '{$classification}'";
$response = $mysqli->query($check_sql);
$row = $response->fetch_assoc();

if($row['n'] != 0){
    $count = number_format($row['n'], 0);
    echo "\nThat tag is in use to tag {$count} names.";
    echo "\nCan't overwrite. You'll need to delete them out first.\n";
    exit();
}

echo "\nNo names are tagged with {$classification}";

echo "\nRunning tag ... ";

$update_sql = "INSERT INTO previous_placements (`name_id`, `role`, `placed_in`, `classification`)

WITH 

# get the max placement for each name that has a placement
max_placements as ( select name_id, max(classification) as classification from previous_placements group by name_id ),

# join those maximums to the placements table and the names table to 
# get one row for every name either joined to the latest classification placement
# or with nulls if there is no entry
last_placements as ( 
	SELECT 
		n.id as name_id, 
		pp.`role`,
        pp.`placed_in` as placed_in_id,
        pp.classification,
        pp.id AS previous_placement_id
	FROM `names` AS n
    LEFT JOIN max_placements AS mp ON mp.name_id = n.id
    LEFT JOIN previous_placements AS pp ON pp.name_id = mp.name_id AND pp.classification = mp.classification
),

# build a table of the current placements of all the names
current_placements as (
	SELECT 
		n.id AS 'name_id',
        IF(
			tn.id IS NULL, # not placed in a taxon
			IF(n.`status` = 'deprecated', 'deprecated', 'unplaced'), 
            IF(
				acn.name_id = n.id, # the accepted name of taxon is same as name - it is the accepted name
                'accepted',
                'synonym'
            ) 
		) AS 'role',
		IF (tn.id IS NULL, 
			NULL,
			IF(
				acn.name_id = n.id, # the accepted name of taxon is same as name - it is the accepted name
                ptn.name_id, # placed in the parent taxon
                acn.name_id # is a synonym so placed in the synonym
            ) 
		) AS 'placed_in_id'
    FROM `names` AS n
    LEFT JOIN taxon_names AS tn ON n.id = tn.name_id
    LEFT JOIN taxa AS t ON tn.taxon_id = t.id
    LEFT JOIN taxon_names AS acn ON t.taxon_name_id = acn.id # accepted names for the taxa
    LEFT JOIN taxa AS pt ON t.parent_id = pt.id # parent taxon
    LEFT JOIN taxon_names AS ptn ON pt.taxon_name_id = ptn.id
),

#select * from current_placements where name_id = 1716323;


# compare last placements with current placements
changed_placements as (
	SELECT 
    cp.name_id as name_id, cp.`role` as current_role, cp.placed_in_id as current_placed_in_id,
    lp.name_id as last_name_id, lp.`role` as last_role, lp.placed_in_id as last_placed_in_id, lp.classification as last_classification
    FROM last_placements AS lp
    JOIN current_placements AS cp ON lp.name_id = cp.name_id
    WHERE 
		NOT(lp.`role` <=> cp.`role`) # NULL safe not comparison
	OR
		NOT(lp.`placed_in_id` <=> cp.`placed_in_id`)
)

SELECT name_id, current_role, current_placed_in_id, '{$classification}' FROM changed_placements;";

$response = $mysqli->query($update_sql);

$count = number_format($mysqli->affected_rows);

echo "\nAdded {$classification} to {$count} tagged names.\n";


