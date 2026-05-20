# script to get all the people who have edited names within a taxon since a date
SET @since = '2025-07-01 00:00:00';
SET @taxon_id = 158492; # the db id get it from Rhakhis. This saves a bunch of joining.
 
# build a list of all the names in the taxon
with RECURSIVE the_taxa AS(
	# seed the recursion
    SELECT id, parent_id from taxa where id = @taxon_id
    UNION ALL
    SELECT t.id, t.parent_id 
    from taxa as t
    join the_taxa as tt on t.parent_id = tt.id
),
the_names AS(
	select distinct(tn.name_id) as id
    from taxon_names as tn 
    join the_taxa as tt on tt.id = tn.taxon_id
),
editors AS(
	select nl.user_id as id
    from `names_log` as nl
    join the_names as ns on ns.id = nl.id
    union all
    select n.user_id
    from `names` as n
    join the_names as ns on ns.id = n.id
),
distinct_editors AS(
	select id, count(*) as changes from editors group by id
)
 
select u.id, u.`name`, de.changes
from distinct_editors as de
join users as u on de.id = u.id;

