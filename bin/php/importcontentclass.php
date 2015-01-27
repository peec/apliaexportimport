<?php
// Where is the folder that contains the export.xml located ?
$exported_folder = dirname(__FILE__) . "/../../../../export_news_article";

/// VARIABLES ///
define('STOP_AFTER_ENTRIES', 0);
define('START_AT_INDEX', 0);
define('IMAGE_UPLOAD_FOLDER_NODEID', 8224);
define('ARTICLE_UPLOAD_FOLDER_NODEID', 8224);


$mapping = array(

    'contentclass' => 'news_article',

    'fields' => array(

        'title' => 'title', // <-- no call back, no filtering needed. just set the attribute of the xml field.

        'body' => function ($xmlNode, ApliaCliImporter $apliaCliImporter) {
                return $apliaCliImporter->htmlParser->parse((string)$xmlNode->body);
        },
        'intro' => function ($xmlNode, ApliaCliImporter $apliaCliImporter) {
            return $apliaCliImporter->htmlParser->parse((string)$xmlNode->intro);
        }
    )
);



require 'autoload.php';
require dirname(__FILE__) . '/../../../apliaexportimport/vendor/autoload.php';


// What user we use to publish new objects. 'bot' might be a good username..
$creator_user = eZUser::fetchByName('admin');
// Where to put 'image' contentclass objects.
$image_parent_node = eZContentObjectTreeNode::fetch(IMAGE_UPLOAD_FOLDER_NODEID);
// Get WHERE to place new objects.
$import_parent_node = eZContentObjectTreeNode::fetch(ARTICLE_UPLOAD_FOLDER_NODEID);

// Create a new importer
$apliaCliIE = new ApliaCliImporter($mapping, $creator_user, $import_parent_node);
// Set start
$apliaCliIE->startAtIndex = START_AT_INDEX;
$apliaCliIE->stopAfterEntries = STOP_AFTER_ENTRIES;

// Set the custom Image resolver.
$apliaCliIE->setHtmlFieldParser(new ApliaHtmlToXmlFieldParser(
    ApliaHtmlToXmlFieldParser::defaultImageSrcResolver('/tmp/import-images-ez', $exported_folder),
    $image_parent_node
));

$apliaCliIE->getHtmlParser()->setFileResolverDirectory($exported_folder);

// Cli involved.. Without this, we can run it by a http request if we'd want.
$apliaCliIE->useCLI();

$str = file_get_contents("$exported_folder/export.xml");

// Import it.
$apliaCliIE->import(
// The xml does not have charset, should have, so replace that.
    $str
);




