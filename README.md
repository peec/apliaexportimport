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





