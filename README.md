# Aplia Export Import

Toolkit that contains classes to deal with export and import of content.

You will have to write some code yourself in order to import some data, but we try to keep this to a minimum.

## INSTALL

- Add the extension in your project `git submodule add git@git.aplia.no:library/apliaexportimport.git extension/apliaexportimport`.
- Go into the extension folder ( extension/apliaexportimport )
- Run command: `composer install`, this will install  all the required dependencies.



## GENERAL NOTE

- Can import an XML file.
- Exports EZ Objects to XML.
- Can import XML files extracted from e.g. wordpress, etc.... (You must create the wordpress export script yourself).



## IMPORTING DATA

You will need to create a script in `bin/php` of your *site extension*. So first create a new php file named `importarticlesfromwordpressinstall.php`.

You then create a script that uses the tools inside this extension.


Sample script: Here is the script we use to import data from UiA's old web archive:

```
<?php
/// VARIABLES ///
define('STOP_AFTER_ENTRIES', 3);
define('START_AT_INDEX', 0);
define('IMAGE_UPLOAD_FOLDER_NODEID', 8224);
define('ARTICLE_UPLOAD_FOLDER_NODEID', 8224);


$mapping = array(

    'contentclass' => 'news_article',

    'fields' => array(

        'title' => 'heading',

        'body' => function ($xmlNode, ApliaCliImporter $apliaCliImporter) {
            return $apliaCliImporter->htmlParser->parse((string)$xmlNode->content);
        },

        'intro' => function ($xmlNode) {
            return ApliaHtmlToXmlFieldParser::stringToXMLField($xmlNode->ingress);
        },

        'author' => function ($xmlNode) {
            $email = (string)$xmlNode->epost;
            if ($email) {
                $e = explode('@', $email);
                if ($e[0]) {
                    $ps = explode('.', $e[0]);
                    if (count($ps)) {
                        $name = '';
                        foreach($ps as $p) {
                            $name .= ucfirst($p) . ' ';
                        }
                        return "$name|$email|-1";
                    }
                }
            }
        }
    ),

    'publishTimeResolver' => function ($xmlNode) {
        $publish_time = strtotime($xmlNode->publishDate);
        return $publish_time;
    }
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
    function ($imageUrl) {
        $imageLocation = null;
        // here we can DL the image by $imageUrl.. but we already have all images in a folder so not needed..
        if (strstr($imageUrl, 'http://www.hia.no/nettavis')) {
            $imageLocation = str_ireplace('http://www.hia.no/nettavis', dirname(__FILE__) . '/webarkiv/', $imageUrl);
        }
        return $imageLocation;
    },
    $image_parent_node
));


// Cli involved.. Without this, we can run it by a http request if we'd want.
$apliaCliIE->useCLI();

// Import it.
$apliaCliIE->import(
    // The xml does not have charset, should have, so replace that.
    str_replace('<?xml version="1.0"', '<?xml version="1.0" encoding="utf-8"', file_get_contents(dirname(__FILE__) . '/webarkiv/webarkiv_export.xml'))
);



```



#### ApliaHtmlToXmlFieldParser

This class can create XML-Fields from regular HTML. It deals with image tags, etc. downloads images creates eZ images
out of it etc. It also converts the HTML to valid XML that should be inserted to the XML-field on the contentclass.




