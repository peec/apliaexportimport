# Aplia Export Import

Toolkit that contains classes to deal with export and import of content. It's very generic, it can export EZ data, import the content to another install.

You can ALSO IMPORT REGUALR HTML to ez, it will resolve images etc etc as XML tags, even inline <img> tags.


## INSTALL

- Add the extension in your project `git submodule add git@git.aplia.no:library/apliaexportimport.git extension/apliaexportimport`.
- Go into the extension folder ( extension/apliaexportimport )
- Run command: `composer install`, this will install  all the required dependencies.



## GENERAL NOTE

- Can import an XML file.
- Exports EZ Objects to XML.
- Can import XML files extracted from e.g. wordpress, etc.... (You must create the wordpress export script yourself).



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



#### ApliaHtmlToXmlFieldParser

This class can create XML-Fields from regular HTML. It deals with image tags, etc. downloads images creates eZ images
out of it etc. It also converts the HTML to valid XML that should be inserted to the XML-field on the contentclass.




