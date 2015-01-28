# Aplia Export Import for eZ Publish legacy

Lets you import any HTML to ez content objects, meaning import and export of data in ez.

Tested on:

- eZ Publish 4.4 - 5.3


## Explained

*Aplia Export Import* contains two routines. These are completely seprated, and does not nessecary need to be used together. Export is only for exporting eZ classes to html. Import can import any html to EZ objects.


#### Export: EZ -> HTML

Useful for converting some old installations content into a newer installation.

#### Import: HTML -> EZ 

Imports XML file with both HTML and attributes to eZ. A config handler is created per structure of the xml file you want to import.


## INSTALL

- Add the extension in your project `git submodule add git@git.aplia.no:library/apliaexportimport.git extension/apliaexportimport`.
- Go into the extension folder ( extension/apliaexportimport )
- Run command: `composer install`, this will install  all the required dependencies.


## Exporting All objects in a given contentclass

Here i want to export all "news_article" content objects. Don't worry, images inline and files in xml fields will also be added to the export..

```
php extension/apliaexportimport/bin/php/exportcontentclass.php news_article
```

- This creates a new folder in your EZ INSTALL: `export_{CONTENT_CLASS}`. This folder contains xml file and images and files.
- Copy this folder to the other newer ez installation and you are ready for importing it..



## IMPORTING DATA

Importing requires you to create a simple php file, it can be created in the EZ ROOT directory. The extension comes with a sample php config file.

See `extension/apliaexportimport/import.config.sample.php` its pretty straight forward.


Run this command to import after you have customized the config file for your contentclass!

```
php extension/apliaexportimport/bin/php/importcontentclass.php extension/apliaexportimport/import.config.sample.php export_news_article export_news_article/export.xml
```

### SYNTAX:


```
 php extension/apliaexportimport/bin/php/importcontentclass.php [Relative path to config.php file] [Relative path to the export archive, where images and files are found] [Relative path to the xml file is located]
```



## CONFIG FILE

Config file can be quite advanced based on the XML you are importing. Here is a sample config containing some of the options.


```
<?php return array(
    'filter_before_xml' => function ($xml) {
        return str_replace('<?xml version="1.0"', '<?xml version="1.0" encoding="utf-8"', $xml);
    },

    'image_resolver' => function ($image_parent_node) {
        return function ($imageUrl) {
            $imageLocation = null;
            // here we can DL the image by $imageUrl.. but we already have all images in a folder so not needed..
            if (strstr($imageUrl, 'http://www.hia.no/nettavis')) {
                $imageLocation = str_ireplace('http://www.hia.no/nettavis',  eZSys::rootDir(). '/webarkiv/', $imageUrl);
            }
            return $imageLocation;
        };
    },

    // Remove all links from the export xml
    // 'remove_tags_in_html' => array('a'),

    'remove_tag_handlers' => array(
        'a' => array(
            function (Crawler $node) {
                if (
                    stripos($node->attr('href'), 'http://hia') === 0 ||
                    stripos($node->attr('href'), 'http://uia') === 0

                ) {
                    return false;
                } else {
                    return true;
                }
            }
        )
    ),

    'creator_user' => 'admin',

    'create_images_inside_node' => 8339,

    'create_imported_nodes_inside_node' => 8339,

    'mapping' => array(

        'contentclass' => 'news_article',

        'fields' => array(

            'title' => 'heading', // <-- no call back, no filtering needed. just set the attribute of the xml field.

            'body' => function ($xmlNode, ApliaCliImporter $apliaCliImporter) {
                    return $apliaCliImporter->htmlParser->parse((string)$xmlNode->content);
                },

            'intro' => function ($xmlNode) {
                    return ApliaHtmlToXmlFieldParser::stringToXMLField((string)$xmlNode->ingress);
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
                $publish_time = strtotime((string)$xmlNode->publishDate);
                return $publish_time;
            },
        'modificationTimeResolver' => function ($xmlNode) {
                $mod_time = strtotime((string)$xmlNode->endretdato);
                return $mod_time;
            }
    )
);


```


