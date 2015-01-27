<?php
// Where is the folder that contains the export.xml located ?
$exported_folder = dirname(__FILE__) . "/../../../../export_news_article";

require 'autoload.php';
require dirname(__FILE__) . '/../../../apliaexportimport/vendor/autoload.php';


// Create a new importer
$apliaCliIE = new ApliaCliImporter();


// Cli involved.. Without this, we can run it by a http request if we'd want.
$options = $apliaCliIE->useCLI();

$apliaCliIE->importFromCli($options);


