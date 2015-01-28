<?php
/**
 * Created by PhpStorm.
 * User: pk
 * Date: 23.01.15
 * Time: 12:26
 */

class ApliaCliImporter {

    public $cli;

    public $mapping;

    /**
     * @var ApliaHtmlToXmlFieldParser
     */
    public $htmlParser;

    public $nodeCreator;

    public $parentNode;

    public $stopAfterEntries = 0;

    public $startAtIndex = 0;

    private $parseTimes = array();



    public function __construct () {

    }

    public function setRequiredDependencies (array $mapping,  $nodeCreator,  $parentNode) {
        $that = $this;

        $this->mapping = $mapping;
        $this->nodeCreator = $nodeCreator;
        $this->parentNode = $parentNode;

        $this->outputHandler = function ($message) use ($that) {
            if ($that->cli) {
                $that->cli->output($message);
            } else {
                echo $message, "\n";
            }
        };
    }


    public function setHtmlFieldParser(ApliaHtmlToXmlFieldParser $htmlParser) {
        $this->htmlParser = $htmlParser;
        $htmlParser->setDefaultNodeCreator($this->nodeCreator);
    }

    /**
     * @return ApliaHtmlToXmlFieldParser
     */
    public function getHtmlParser () {
        return $this->htmlParser;
    }


    public function createNodeByMapping ($xmlNode) {
        $parent_node = $this->parentNode;
        $creator = $this->nodeCreator;

        $params = array();
        $params['class_identifier'] = $this->mapping['contentclass']; //class name (found within setup=>classes in the admin if you need it
        $params['creator_id'] = $creator->attribute( 'contentobject_id' ); //using the user created above
        $params['parent_node_id'] = $parent_node->attribute( 'node_id' ); //pulling the node id out of the parent
        $params['section_id'] = $parent_node->attribute( 'object' )->attribute( 'section_id' );

        $attributesData = array ();

        foreach($this->mapping['fields'] as $attributeName => $xmlAttributeResolver) {
            if (is_callable($xmlAttributeResolver)) {
                $xmlAttributeValue = call_user_func_array($xmlAttributeResolver, array($xmlNode, $this));
            } else {
                $xmlAttributeValue = $xmlNode->{$xmlAttributeResolver};
            }
            $attributesData[$attributeName] = $xmlAttributeValue;
        }


        $params['attributes'] = $attributesData;

        /** @var eZContentObject $contentObject */
        $contentObject = eZContentFunctions::createAndPublishObject( $params );


        if (isset($this->mapping['publishTimeResolver'])) {
            $publish_time = call_user_func_array($this->mapping['publishTimeResolver'], array($xmlNode, $this));
            if ($publish_time) {
                $this->out("Publish time $publish_time");
                /** @var eZContentObjectVersion $version */
                $version = $contentObject->currentVersion();

                $contentObject->setAttribute('published', $publish_time);
                $version->setAttribute('created', $publish_time);
                $version->store();
                $contentObject->store();
            } else {
                $this->out("Could not convert publish time");
            }
        }

        if (isset($this->mapping['modificationTimeResolver'])) {
            $mod_time = call_user_func_array($this->mapping['modificationTimeResolver'], array($xmlNode, $this));
            if ($mod_time) {
                /** @var eZContentObjectVersion $version */
                $contentObject->setAttribute('modified', $mod_time);
                $version = $contentObject->currentVersion();
                $version->setAttribute('modified', $mod_time);
                $version->store();
                $contentObject->store();
            } else {
                $this->out("Could not convert modification time");
            }
        }



        if ( !$contentObject ) {
            throw new Exception("Could not create article.");
        }
        return $contentObject;
    }



    /**
     * Parses the file.
     */
    public function parse ($xmlString) {

        $xml = simplexml_load_string($xmlString, null, LIBXML_NOCDATA);
        $count = count($xml);
        $toBeAdded = $this->stopAfterEntries == 0 ? $count : $this->stopAfterEntries;
        $i = 0;
        $added = 0;
        $this->parseTimes = array();

        foreach($xml as $xmlNode) {
            $start = time();
            if ($i < $this->startAtIndex) {
                $i++;
                continue;
            }

            $this->createNodeByMapping($xmlNode);


            if ($this->htmlParser) {
                foreach($this->htmlParser->messages as $m) {
                    $this->out($m);
                }
            }

            $end = time() - $start;
            $this->parseTimes[] = $end;
            $leftSeconds = ( ($toBeAdded - $added)  * $this->getAvgParseTime() );
            $leftMinutes = $leftSeconds / 60;

            $this->out("Finished importing node ".($i+1)." of $count... in: $end seconds");
            $this->out("ABOUT $leftMinutes minutes left ....");

            gc_collect_cycles();
            if ($this->stopAfterEntries && $this->stopAfterEntries == $added) {
                return;
            }

            $i++;
            $added++;
        }
    }


    public function getAvgParseTime () {
        $s = 0;
        foreach($this->parseTimes as $t) {
            $s += $t;
        }
        return $s / count($this->parseTimes);
    }

    public function useCLI () {
        set_time_limit ( 0 );
        $this->cli = eZCLI::instance();


        $this->script = eZScript::instance( array( 'description' => ( "Import routine\n\n" .
                ""),
            'use-session' => false,
            'use-modules' => true,
            'use-extensions' => true,
            'debug-output' => true,
            'debug-message' =>true) );

        $this->script->startup();


        $options = $this->script->getOptions( "[stop-at-index:][start-at-index:]", "[CONFIG_FILE_PATH] [EXPORT_DIRECTORY_PATH] [EXPORT_XML_FILE_PATH]", array(
            'stop-at-index' => 'Stops after import of X nodes.',
            'start-at-index' => 'Starts after X nodes are iterated.'
        ));



        if ($options['stop-at-index']) {
            $this->stopAfterEntries = $options['stop-at-index'];
        }

        if ($options['start-at-index']) {
            $this->startAtIndex = $options['start-at-index'];
        }
        $this->script->initialize();

        return $options;
    }

    public function importFromCli ($options) {

        $config = array(

            /**
             * Custom image resolver, for custom exports of e.g. wordpress or other content.
             * @var callable
             */
            'image_resolver' => null,

            /**
             * Filter the XML file before with a callback
             * @var callable
             */
            'filter_before_xml' => null,

            /**
             * Remove these tags from the HTML.
             * @var array
             */
            'remove_tags_in_html' => null,

            /**
             * Remove tag handlers.
             *
             * Example:
             *
             *
             * 'remove_tag_handlers' => array(
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
             *
             *
             * @var array
             */
            'remove_tag_handlers' => null,


            /**
             * Custom tag handler.
             *
             * @var callable DomElement is passed as argument.
             */
            'custom_tag_handler' => null
        );



        if (!isset($options['arguments'][0])) {
            $this->script->shutdown(1, 'Missing argument 1');
        }
        $confFile = eZSys::rootDir() . '/' . $options['arguments'][0];
        if (!file_exists($confFile)) {
            $this->script->shutdown( 1, 'First argument must point to a valid config file. see extension/apliaexportimport/import.config.sample.php' );
        } else {
            $config =  array_merge($config, require ($confFile));
        }

        if (!isset($options['arguments'][1])) {
            $this->script->shutdown(1, 'Missing argument 2');
        }
        $exported_folder = eZSys::rootDir() . '/' . $options['arguments'][1];
        if (!is_dir($exported_folder)) {
            $this->script->shutdown( 1, 'Failed to find directory ('.$exported_folder.'). Second argument is the directory where the export var dir resides.' );
        }


        if (!isset($options['arguments'][2])) {
            $this->script->shutdown(1, 'Missing argument 3');
        }

        $xmlFile = eZSys::rootDir() . '/' . $options['arguments'][2];

        if (!file_exists($xmlFile)) {
            $this->script->shutdown( 1, "Could not find XML file $xmlFile, RELATIVE TO EZ ROOT." );
        }



        // What user we use to publish new objects. 'bot' might be a good username..
        $creator_user = eZUser::fetchByName($config['creator_user']);
        // Where to put 'image' contentclass objects.
        $image_parent_node = eZContentObjectTreeNode::fetch($config['create_images_inside_node']);
        // Get WHERE to place new objects.
        $import_parent_node = eZContentObjectTreeNode::fetch($config['create_imported_nodes_inside_node']);


        $this->setRequiredDependencies($config['mapping'], $creator_user, $import_parent_node);


        $tmpFolder = $this->createTemporaryDirectory();

        if (!is_dir($tmpFolder)) {
            throw new Exception("Could not create tmp folder, needed for image routine import.");
        }


        $keepTheseHtmlTags = ApliaHtmlToXmlFieldParser::ALLOWED_TAGS;
        if ($config['remove_tags_in_html']) {
            $exp = explode(',', ApliaHtmlToXmlFieldParser::ALLOWED_TAGS);
            foreach($exp as $k => $tagName) {
                foreach($config['remove_tags_in_html'] as $removeThis) {
                    if (substr($tagName, 0, strlen($removeThis)) == $removeThis) {
                        unset ($exp[$k]);
                    }
                }
            }
            $keepTheseHtmlTags = implode(',', $exp);
        }



        // Set the custom Image resolver.
        $this->setHtmlFieldParser(new ApliaHtmlToXmlFieldParser(
            $config['image_resolver'] ? $config['image_resolver']($image_parent_node) : ApliaHtmlToXmlFieldParser::defaultImageSrcResolver($tmpFolder, $exported_folder),
            $image_parent_node,
            $keepTheseHtmlTags
        ));

        $this->getHtmlParser()->setFileResolverDirectory($exported_folder);

        if ($config['remove_tag_handlers']) {
            foreach($config['remove_tag_handlers'] as $tagName => $handlers) {
                foreach($handlers as $handler) {
                    $this->getHtmlParser()->addExcludeTagHandler($tagName, $handler);
                }
            }
        }

        if ($config['custom_tag_handler']) {
            $this->getHtmlParser()->setCustomXmlTagHandler($config['custom_tag_handler']);
        }

        $str = file_get_contents($xmlFile);

        if ($config['filter_before_xml']) {
            $str = call_user_func_array($config['filter_before_xml'], array($str));
        }

        // Import it.
        $this->import(
            // The xml does not have charset, should have, so replace that.
            $str
        );

        rmdir($tmpFolder);
    }

    public function out ($message) {
        call_user_func_array($this->outputHandler, array($message));
    }

    private function createTemporaryDirectory($prefix='apliaexportimportroutine') {
        $tempfile=tempnam(sys_get_temp_dir(),$prefix);
        if (file_exists($tempfile)) { unlink($tempfile); }
        mkdir($tempfile);
        if (is_dir($tempfile)) { return $tempfile; }
    }

    public function import ($xml) {


        $this->parse($xml);

        if ($this->cli) {
            $this->script->shutdown();
        }
    }

} 