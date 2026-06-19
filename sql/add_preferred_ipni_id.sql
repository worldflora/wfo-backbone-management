
/*
    Adding the field to the names table 
*/
ALTER TABLE `names` 
ADD COLUMN `preferred_ipni_id` INT NULL DEFAULT NULL AFTER `prescribed_id`,
ADD UNIQUE INDEX `preferred_ipni_id_UNIQUE` (`preferred_ipni_id` ASC) VISIBLE;

/*
    And to the name_logs - never forget the name logs! 
*/
ALTER TABLE `names_log` 
ADD COLUMN `preferred_ipni_id` INT NULL DEFAULT NULL AFTER `prescribed_id`;

/* 
    Set the preferred identifier for the names that only have one.
    May take a while
*/

with single_ids as (
select name_id, count(*) as n from identifiers as i 
where kind = 'ipni' 
group by name_id having n = 1)

UPDATE `names` as n
JOIN single_ids as si ON si.name_id = n.id
JOIN identifiers as i ON n.id = i.name_id AND i.kind = 'ipni'
SET n.preferred_ipni_id = i.id
WHERE n.preferred_ipni_id is NULL
AND n.id > 0;