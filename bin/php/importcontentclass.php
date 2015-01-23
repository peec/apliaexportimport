<?php
$contentclass = 'news_article';

/// VARIABLES ///
define('STOP_AFTER_ENTRIES', 3);
define('START_AT_INDEX', 0);
define('IMAGE_UPLOAD_FOLDER_NODEID', 8224);
define('ARTICLE_UPLOAD_FOLDER_NODEID', 8224);


$mapping = array(

    'contentclass' => 'news_article',

    'fields' => array(

        'title' => 'title', // <-- no call back, no filtering needed. just set the attribute of the xml field.

        'body' => function ($xmlNode, ApliaCliImporter $apliaCliImporter) {
                return $apliaCliImporter->htmlParser->parse((string)$xmlNode->content);
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
$apliaCliIE->setHtmlFieldParser(ApliaHtmlToXmlFieldParser::defaultImageSrcResolver('/tmp/import-images-ez'));

// Cli involved.. Without this, we can run it by a http request if we'd want.
$apliaCliIE->useCLI();

// Import it.
$apliaCliIE->import(
// The xml does not have charset, should have, so replace that.
    file_get_contents("$contentclass-export.xml")
);




