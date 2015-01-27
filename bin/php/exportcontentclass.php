<?php


require 'autoload.php';
require dirname(__FILE__) . '/../../vendor/autoload.php';

$cli = eZCLI::instance();
$exporter = new ApliaContentClassNodeExporter();
$options = $exporter->useCLI($cli);

$exporter->export($options['arguments'][0]);


