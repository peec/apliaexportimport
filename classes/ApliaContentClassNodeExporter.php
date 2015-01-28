<?php
/**
 * Created by PhpStorm.
 * User: pk
 * Date: 23.01.15
 * Time: 16:02
 */

class ApliaContentClassNodeExporter {

    public $cli, $script, $tpl;

    public $stopAt = 0;

    public $startAt = 0;

    public $rootNode = 2;

    private $exportFolder;

    public $datatypeHandlers = array();


    public $messages = array();

    public $configuration = array(
        'image' => array(
            'class' => 'image',
            'attribute' => 'image'
        ),
        'file' => array(
            'class' => 'file',
            'attribute' => 'file'
        )
    );



    public function __construct () {
        $this->tpl = eZTemplate::factory();
    }



    public function addDatatypeHandler ($type, $handler) {
        $this->datatypeHandlers[$type] = $handler;
    }



    public function nodeToElement(array $attributesToExport, eZContentObjectTreeNode $node, DOMElement $rootElement) {
        $dataMap = $node->dataMap();


        $rootElement->setAttribute("original_nodeid", $node->attribute('node_id'));

        foreach($attributesToExport as $k => $attributeName) {
            /** @var eZContentObjectAttribute $attribute */
            $attribute = $dataMap[$attributeName];
            $type = $attribute->attribute('data_type_string');


            if (is_numeric($k)) {
                if (isset($this->datatypeHandlers[$type])) {
                    if (!($this->datatypeHandlers[$type] instanceof ApliaExportDataType)) {
                        throw new Exception("Datatype handler must be instanceof ApliaExportDataType");
                    }
                    $value = call_user_func_array(array($this->datatypeHandlers[$type], "handle"), array($attribute));
                } else {
                    $value = $attribute->toString();
                }
            } else {
                $value = call_user_func_array($attributeName, array($attribute, $type));
            }


            $escapedValue = html_entity_decode($value);

                $xmlNode = $rootElement->ownerDocument->createElement($attributeName);
                $escapedValue = $rootElement->ownerDocument->createCDATASection($escapedValue);
                $xmlNode->appendChild($escapedValue);


            $xmlNode->setAttribute('type', $type);

            $rootElement->appendChild($xmlNode);
        }

        /** @var eZContentObjectVersion $o */
        $o = $node->contentObjectVersionObject();

        $el = $rootElement->ownerDocument->createElement('created', $o->attribute('created'));
        $rootElement->appendChild($el);
        $el = $rootElement->ownerDocument->createElement('modified', $o->attribute('modified'));
        $rootElement->appendChild($el);
    }


    public function export ($contentClass) {
        if (!eZContentClass::fetchByIdentifier($contentClass)) {
            throw new Exception("Content class identifier: '$contentClass' does not exist. It must exist to be exported.");
        }



        $nodes = eZContentObjectTreeNode::subTreeByNodeID(array(
            'ClassFilterType' => 'include',
            'ClassFilterArray' => array($contentClass)
        ), $this->rootNode);


        $dom = new DOMDocument('1.0', 'utf-8');
        $root = $dom->createElement('export');
        $dom->appendChild($root);


        $folder = eZSys::rootDir() . "/export_$contentClass";
        $this->exportFolder = $folder;
        $xmlFile = "$folder/export.xml";
        $logFile = "$folder/log.txt";

        if (file_exists($folder)) {
            passthru("rm -rf $folder");
        }

        mkdir($folder);


        $cls = eZContentClass::fetchByIdentifier($contentClass);
        $rawAttributes = $cls->fetchAttributes();
        $attributesToExport = array();
        foreach($rawAttributes as $attr) {
            $attributesToExport[] = $attr->Identifier;
        }


        // Add datatype resolver for XML fields.
        $xmle = new ApliaExportDatatypeEzXML($this->messages, $this->datatypeHandlers);
        $xmle->setConfiguration($this->configuration);
        $xmle->setExportFolder($this->exportFolder);
        $this->addDatatypeHandler('ezxmltext', $xmle);


        $or = new ApliaExportDataTypeObjectRelation($this->messages, $this->datatypeHandlers);
        $this->addDatatypeHandler('ezobjectrelation', $or);


        $i = 0;
        foreach($nodes as $node) {
            if ($i < $this->startAt) {
                $i++;
                continue;
            }
            $element = $dom->createElement($contentClass);
            $this->nodeToElement($attributesToExport, $node, $element);
            $root->appendChild($element);

            $i++;

            if ($this->stopAt && $i == $this->stopAt) {
                break;
            }
        }

        $xmlSource = $dom->saveXML();



        file_put_contents($xmlFile, $xmlSource);
        file_put_contents($logFile, implode("\r\n", $this->messages));


        if ($this->cli) {
            $this->cli->output("DONE, see {$xmlFile}, the folder is your export, put the folder in the install you want to import it too.");
            $this->script->shutdown();
        }
        return $xmlSource;
    }

    public function useCLI (eZCLI $cli) {
        set_time_limit ( 0 );
        $this->cli = $cli;
        $this->script = eZScript::instance( array( 'description' => ( "Export routine\n\n" .
                ""),
            'use-session' => false,
            'use-modules' => true,
            'use-extensions' => true,
            'debug-output' => true,
            'debug-message' =>true) );

        $this->script->startup();


        $options = $this->script->getOptions( "[stop-at-index:][start-at-index:][subtree-nodeid:]", "[CLASS_IDENTIFIER]", array(
            'stop-at-index' => 'Stops after import of X nodes.',
            'start-at-index' => 'Starts after X nodes are iterated.',
            'subtree-nodeid' => 'Exports from the given root node. Default is 2 (ALL objects).'
        ));

        if ( count( $options['arguments'] ) != 1 )
        {
            eZLog::write( "Wrong arguments count", 'publishqueue.log' );
            $this->script->shutdown( 1, 'wrong argument count' );
        }



        $this->script->initialize();


        if ($options['stop-at-index']) {
            $this->stopAt = $options['stop-at-index'];
        }

        if ($options['start-at-index']) {
            $this->startAt = $options['start-at-index'];
        }


        if ($options['subtree-nodeid']) {
            $this->rootNode = (int)$options['subtree-nodeid'];
        }

        return $options;
    }


}


class UnresolvedTagException extends Exception {
    private $domNode;

    public function __construct(DOMElement $domNode, $message = '') {
        $this->domNode = $domNode;

        $newdoc = new DOMDocument();
        $cloned = $domNode->cloneNode(TRUE);
        $newdoc->appendChild($newdoc->importNode($cloned,TRUE));
        $elementAsString = $newdoc->saveXML();

        parent::__construct("Unresolved node '$elementAsString'  ... Removing this node. $message");
    }

    public function getNode () {
        return $this->domNode;
    }
}