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



    public function __construct (array $mapping,  $nodeCreator,  $parentNode) {
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
                $version->setAttribute('created', $publish_time);
                $version->store();
            } else {
                $this->out("Could not convert publish time");
            }
        }

        if (isset($this->mapping['modificationTimeResolver'])) {
            $mod_time = call_user_func_array($this->mapping['modificationTimeResolver'], array($xmlNode, $this));
            if ($mod_time) {
                /** @var eZContentObjectVersion $version */
                $version = $contentObject->currentVersion();
                $version->setAttribute('modified', $mod_time);
                $version->store();
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
    }

    public function out ($message) {
        call_user_func_array($this->outputHandler, array($message));
    }

    public function import ($xml) {

        if ($this->cli) {
            $script = eZScript::instance( array( 'description' => ( "Import routine\n\n" .
                    ""),
                'use-session' => false,
                'use-modules' => true,
                'use-extensions' => true,
                'debug-output' => true,
                'debug-message' =>true) );

            $script->startup();
            $script->initialize();

            $this->out( 'Parent node for imported objects: '. $this->parentNode->attribute( 'name' ) );

            $this->out("==== PRESS CTRL+C IF THIS IS NOT CORRECT, STARTING IN :::: 4 seconds :::: ====");
            //sleep();


        }

        $this->parse($xml);

        if ($this->cli) {
            $script->shutdown();
        }
    }

} 