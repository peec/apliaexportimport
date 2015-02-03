# Aplia Export Import for eZ Publish legacy

Lets you import any XML/HTML to ez content objects, meaning import and export of data in ez.

Tested on:

- eZ Publish 4.4 - 5.3


## Explained

*Aplia Export Import* contains two routines. These are completely seprated, and does not nessecary need to be used together. Export is only for exporting eZ classes to html. Import can import any html to EZ objects.


#### Export: EZ -> XML

Useful for converting some old installations content into a newer installation.

Exports all attributes on a contentclass but some edge cases you should know:

- For object relation attribute it only exports image and files. Since we dont want to export a large tree.
- For XML Fields, embeds also just exports files and images.


Export contains a folder with images and a XML file that can be imported with the import routine.


#### Import: XML -> EZ

Imports XML file with both HTML and attributes to eZ. A config handler is created per structure of the xml file you want to import.

A Configuration file must be created, see the "IMPORTING DATA" section.


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

Importing requires you to create a simple php file, it can be created in the EZ ROOT directory. The extension comes with a sample php config file. See "CONFIG FILE"
section for all the options for the config file.

See `extension/apliaexportimport/import.config.sample.php` its pretty straight forward.


Run this command to import after you have customized the config file for your contentclass!

```
php extension/apliaexportimport/bin/php/importcontentclass.php extension/apliaexportimport/import.config.sample.php export_news_article export_news_article/export.xml
```

#### Requirements for xml file

- One root node
- One node for each object to be inserted.
- HTML fields must be escaped or used with CDATA

Sample below:

```
<?xml version="1.0" ?>
<export>
        <node>
            <title>Hello</title>
            <content>&lt;FONT size=2&gt;
            &lt;P&gt;&lt;IMG src=&quot;http://www.ewqwqwd.no/nettavis/bilder/eqw.jpg&quot;
            align=left&gt;wdqwe ewqewqJahr.&lt;/P&gt;&lt;/FONT&gt;</content>
        </node>
        <node>
            <title>Hello 2</title>
            <content>&lt;FONT size=2&gt;
            &lt;P&gt;&lt;IMG src=&quot;http://www.ew.no/nettavis/bilder/ewweq.jpg&quot;
            align=left&gt;wdqwe ewqewqJahr.&lt;/P&gt;&lt;/FONT&gt;</content>
        </node>
        <node>
            <title>Hello 3</title>
            <content>&lt;FONT size=2&gt;
            &lt;P&gt;&lt;IMG src=&quot;http://www.qw.no/nettavis/bilder/ewq.jpg&quot;
            align=left&gt;wdqwe ewqewqJahr.&lt;/P&gt;&lt;/FONT&gt;</content>
        </node>
</export>
```



### SYNTAX:


```
 php extension/apliaexportimport/bin/php/importcontentclass.php [Relative path to config.php file] [Relative path to the export archive, where images and files are found] [Relative path to the xml file is located]
```



## CONFIG FILE

Config file can be quite advanced based on the XML you are importing. Here is a sample config containing some of the options.

This config file in short:

- Maps XML fields to eZ Content class attribute. It gives you the power to import xml files with other formats then the
one we use in the export.
- Allows filtering HTML before inserting it.
- Allows to create custom image resolvers for images. For example if <img src="http://googleimages.com/..."> you want to
DL the image and return the internal path to it from a callback using the `image_resolver` config.
- Configure where to put the imported data in the EZ Content structure.
- Configure where to put images/files.



Here is a custom exported XML (not from the exportcontentclass.php):


```
<?xml version="1.0" ?>
<export>
        <node>
                <heading>eqwwe med rom for eqw</heading>
                <ingress>dqwd dqwdqw qwd qwdqwd qw</ingress>
                <content>&lt;FONT size=2&gt;
&lt;P&gt;&lt;IMG src=&quot;http://www.ewqwqwd.no/nettavis/bilder/Hernes3.jpg&quot;
align=left&gt;wdqwe ewqewqJahr.&lt;/P&gt;&lt;/FONT&gt;</content>
                <header>ewqqwe fag</header>
                <epost>eqw.ewqwqe@ewq.no</epost>
                <dato>2003-09-05 00:00:00</dato>
                <endretdato>2003-09-05</endretdato>
                <idag>05-09-03</idag>
        </node>
</export>
```

We can then create a config file like this:

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


    'remove_tag_handlers' => array(
        'a' => array(
            function (Crawler $node) {
                if (
                    stripos($node->attr('href'), 'http://wedontwantthishost') === 0 ||
                    stripos($node->attr('href'), 'http://neitherthis') === 0

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

    // Mapping is based on how the XML file is and the content class.
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



