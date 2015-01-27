<?php


require 'autoload.php';
require dirname(__FILE__) . '/../../vendor/autoload.php';

$cli = eZCLI::instance();
$exporter = new ApliaContentClassNodeExporter();
$exporter->useCLI($cli);
$exporter->export('news_article');

