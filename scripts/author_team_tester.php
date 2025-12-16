<?php

require_once('../config.php');
require_once('../config.php');
require_once('../include/AuthorTeam.php');
require_once('../include/AuthorTeamMember.php');
require_once('../include/SPARQLQueryDispatcher.php');


// check out an author string

echo "Enter authors string: ";
$fin = fopen ("php://stdin","r");
$line = trim(fgets($fin));

$team = new AuthorTeam($line);

//echo "Authors string html:\t{$team->getHtmlAuthors()}\n";

echo "\n$line\n";

$members = $team->getMembers();
foreach ($members as $member) {
    echo "\n";
    echo "\t{$member->abbreviation}\n";
    echo "\t{$member->label}\n";
    echo "\t{$member->wikiUri}\n";
    echo "\t{$member->imageUri}\n";
    echo "\t{$member->referencePresent}\n";
}

$doc = new DOMDocument();
$doc->loadXML($team->getHtmlAuthors());
$doc->formatOutput = true;
echo $doc->saveXML();


