<?php

/*

    This will perform a depth first crawl of the taxonomic
    heirachy to generate a spatial map of where the taxa fall in "taxanomic" space.
    This is based on their listing order and tab depth but 
    then projected into a circular shape to make the aspect ration more appropriate
    and stretch out the leaf nodes.
*/

require_once('../config.php');

echo "Kicking off taxon tree build.\n\n";

// count the taxa - we need it for the projection calculation.
$response = $mysqli->query("SELECT count(*) as n FROM taxa as t JOIN taxon_names as tn on t.taxon_name_id = tn.id");
$max_index = $response->fetch_assoc()['n'];
$response->close();

// count the maximum depth possible
$max_depth = count($ranks_table);


// get the root taxon ID
$response = $mysqli->query("SELECT t.id, i.`value` as wfo, n.`rank` FROM taxa as t 
	JOIN taxon_names as tn on t.taxon_name_id = tn.id 
    JOIN `names` as n on n.id = tn.name_id
    JOIN `identifiers` as i on n.prescribed_id = i.id and i.kind = 'wfo'
WHERE t.parent_id = t.id");
$root_data= $response->fetch_assoc();
$response->close();

$csv = fopen('../www/downloads/taxonomic_tree.csv', 'w');
fputcsv($csv, ['wfo', 'id', 'path', 'index', 'depth', 'x', 'y'], escape: "\\");

$svg = fopen('../www/images/taxonomic_tree.svg', 'w');

$svg_box = round($max_index * 3.5);
$svg_box_x = round($svg_box/2) + 200;
$svg_origin = round($svg_box / -2);

fwrite($svg, "<svg width=\"1000\" height=\"1000\" viewBox=\"-10 {$svg_origin} {$svg_box_x} {$svg_box}\" xmlns=\"http://www.w3.org/2000/svg\">\n");
fwrite($svg, '  <link xmlns="http://www.w3.org/1999/xhtml" rel="stylesheet" href="taxonomic_tree.css" type="text/css" />');

$index = 0;
$depth = 0;

process_taxon($root_data['id'], $root_data['wfo'], $index, $root_data['rank'], '');

fwrite($svg,"</svg>");
fclose($csv);

function process_taxon($taxon_id, $wfo, &$index, $rank, $path, $parent_x = 0, $parent_y = 0, $parent_rank = 'code'){

    global $mysqli;
    global $ranks_table;
    global $max_index;
    global $max_depth;
    global $csv;
    global $svg;

    // calculate the x/y projection for the taxon position
    // the percentage round the circle we are plus the distance from the centre
    $angle = ($index/$max_index) * (1*pi()) ; // in radians

    // rotate it 90 degrees left 
    $angle = $angle - (2*pi() / 4);

    //  from centre is proportion of radius - assuming the circum is some proportion of the number of taxa
    $depth = array_search($rank, array_keys($ranks_table));
    $hypot = ($depth/$max_depth) * (($max_index * 1.22) / 2 * pi()); // radians

    // x/y from the angle and distance
    $x = $hypot * cos($angle);
    $y = $hypot * sin($angle);

    $my_path = $path . '/' . $wfo;

    // write out this taxon
    fputcsv($csv, [$wfo, $taxon_id, $my_path, $index, $depth, $x, $y], escape: "\\");

    // now to the SVG Stuff
    // we draw an arc round from the parent then a line out to the taxon
    // then we add a circle for that taxon
    
    // we open a graphics object that carries the classes for css styling the structure
    fwrite($svg, "\n<g class=\"{$wfo} {$rank}\">"); // label it with rank and id
  
    // location of taxon
    $svg_x = round($x);
    $svg_y = round($y);

    // location of parent
    $svg_parent_x = round($parent_x);
    $svg_parent_y = round($parent_y);

     fwrite($svg, "<polyline points=\"{$svg_parent_x},{$svg_parent_y} {$svg_x},{$svg_y}\" />");

    
    /*


    // location of the bend in the path where we move from arc to line
    $parent_depth = array_search($parent_rank, array_keys($ranks_table));
    $parent_hypot = ($parent_depth/$max_depth) * (($max_index * 1.22) / 2 * pi());
    $bend_x = $hypot * cos($angle); // that's the parent distance from centre but the taxon angle from centre
    $bend_y = $hypot * sin($angle);


     https://developer.mozilla.org/en-US/docs/Web/SVG/Tutorials/SVG_from_scratch/Paths

      <path
    d="M 100 315 A 150 150 0 0 1 150 150"
    stroke="black"
    fill="green"
    stroke-width="2"
    fill-opacity="0" />

    $path = "M {$svg_parent_x},{$svg_parent_y}"; // move cursor to parent location
    
    /*
        draw an arc
        radius_x - the radius is the size of the circle the parent is on
        radius_y - ditto, only different if we are doing elipses
        x-axis-rotation - only relevant if we are rotating an elipse
        large_arc_flag - Do we go the long way around the circle - no
        sweep_flag - ? It determines if the arc should begin moving at positive angles or negative ones, which essentially picks which of the two circles will be traveled around.
        bend_x - finishing coordinates of the arc
        bend_y - finishing coordinates of the arc

    $path .= " A {$parent_hypot} {$parent_hypot} 0 0 1 {$bend_x} {$bend_y}"; 

    // draw a line to the taxon position
    $path .= " L {$svg_x} {$svg_y}"; 

    // write it out
    fwrite($svg, "<path d=\"{$path}\" />");

    */
    // draw a circle for the taxon
    fwrite($svg, "<circle cx=\"{$svg_x}\" cy=\"{$svg_y}\" r=\"30\" />");

    print_r([$wfo, $taxon_id, $my_path, $index, $depth, $x, $y]);

    // work through its kids
    $sql = "SELECT t.id, i.`value` as wfo, n.`rank` FROM taxa as t 
        JOIN taxon_names as tn on t.taxon_name_id = tn.id 
        JOIN `names` as n on n.id = tn.name_id
        JOIN `identifiers` as i on n.prescribed_id = i.id and i.kind = 'wfo'
        WHERE t.parent_id = $taxon_id
        AND t.parent_id != t.id
        ORDER BY n.name_alpha
        ";
    $response = $mysqli->query($sql);

    while($kid = $response->fetch_assoc()){
        $index++;
        process_taxon($kid['id'], $kid['wfo'], $index, $kid['rank'], $my_path, $x, $y, $rank);
    }

    // close the taxon after its kids
    fwrite($svg, "</g>");

    $response->close();

}
