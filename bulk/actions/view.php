<!doctype html>
<?php

    $table = @$_GET['table'];
    $phase = @$_GET['phase'];
    if(!$phase) $phase = 'intro';

    // we use paging in all parts
    $page = @$_GET['page'];
    if(!$page) $page = 0;

?>
<html>

<head>
    <title>Rhakhis Bulk</title>
    <style>
    body {
        font-family: sans-serif;
    }

    table,
    td,
    th {
        text-align: left;
        border: 1px solid black;
        border-collapse: collapse;
        padding: 0.5em;
    }

    tr:hover {
        background-color: lightgray;
    }
    </style>
</head>

<body>
    <?php
    if($system_message){
        echo "<p style=\"background-color: black; color:white; padding: 0.3em; border: solid 1px gray; margin: 0px;\"><strong>&nbsp;⚠️&nbsp;System Message:&nbsp;</strong>{$system_message[0]}</p>";
        echo "<hr/>";
    }
?>

    <form style="display: 'inline'">
        <div>

            <strong>Rhakhis Bulk: </strong>
            <a href="index.php?action=view&phase=intro">Introduction</a>
            |
            <a href="index.php?action=view&phase=csv">Files</a>
            |
            <a href="index.php?action=view&phase=kew">Kew</a>
            |
            <a href="index.php?action=view&phase=tables">Tables</a>
            |
            <a href="index.php?action=view&phase=matching">Matching</a>
            |
            <a href="index.php?action=view&phase=created">Created</a>
            |
            <a href="index.php?action=view&phase=linking">Linking</a>
            |
            <a href="index.php?action=view&phase=references">References</a>
            |
            <a href="index.php?action=view&phase=nomenclature">Nomenclature</a>
            |
            <a href="index.php?action=view&phase=taxonomy">Taxonomy</a>
            |
            <a href="index.php?action=view&phase=duplicates">Duplicates</a>
            |
            <a href="index.php?action=view&phase=examples">Examples</a>
            |
            <a href="index.php?action=view&phase=update_user_name">User Names</a>
            |
            <a href="index.php?action=view&phase=switch_user">su</a>

            &nbsp;
            <span style="border: solid 1px black; padding: 0.2em">
                <?php
                echo @$_SESSION['selected_table'] ? "Active Table: " . $_SESSION['selected_table'] : "No Table Selected";
            ?>
            </span>



        </div>
    </form>
    <hr />
    <?php include("../bulk/views/$phase.php") ?>

</body>

</html>

<?php

// functions used in possibly all tools

function get_name_parts($nameString){
    
    // clean up the name first
    $nameString = trim($nameString);

    // U+00D7 = multiplication sign
    // U+2715 ✕ MULTIPLICATION X
    // U+2A09 ⨉ N-ARY TIMES OPERATOR

    // hybrid symbol be gone
    $json = '["\u00D7","\u2715","\u2A09"]';
    $hybrid_symbols = json_decode($json);
    foreach ($hybrid_symbols as $symbol) {
        $nameString = str_replace($symbol, '', $nameString);
    }

    // the name may include a rank abbreviation
    $nameParts = explode(' ', $nameString);
    $newNameParts = array();
    foreach($nameParts as $part){
        // strip out the rank parts.
        if(!Name::isRankWord($part)){
            $newNameParts[] = $part;
        }
    }

    return $newNameParts;
}

?>