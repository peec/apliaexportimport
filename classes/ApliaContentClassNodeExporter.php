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


    const SUPPORTED_XMLTYPE_TAGS = "<paragraph>,<embed>,<link>";
    const SUPPORTED_OUTPUT_HTML_TAGS = "<a>,<img>,<p>";


    public function __construct () {
        $this->tpl = eZTemplate::factory();

    }

    public $messages = array();

    public function handle_contentclass_image (DOMElement $node, eZContentObject $object) {
        $img = $this->dom_rename_element($node, 'img', true);
        $dataMap = $object->dataMap();
        /** @var eZImageType $imageAttribute */
        $imageAttribute = $dataMap[$this->configuration['image']['attribute']];
        /** @var eZImageAliasHandler $content */
        $content = $imageAttribute->content();
        $arrInfo = $content->attribute( 'original' );

        $src = $arrInfo['url'];
        $imagePath = eZSys::rootDir() . '/' . $arrInfo['full_path'];

        if (file_exists($imagePath) && is_file($imagePath) && !is_dir($imagePath)) {
            $exportPath = "{$this->exportFolder}/{$arrInfo['dirpath']}";
            if (!file_exists($exportPath)) {
                mkdir($exportPath, 0777, true);
            }
            copy($imagePath, "$exportPath/{$arrInfo['basename']}");
            $img->setAttribute('src', $src);
            $img->setAttribute('data-ez_name', $object->name());
        } else {
            throw new UnresolvedTagException($node, "'$imagePath' could not be resolved on the filesystem. Ignored this tag.");
        }
    }
    public function handle_contentclass_file (DOMElement $node, eZContentObject $object) {
        $a = $this->dom_rename_element($node, 'a', true);
        $dataMap = $object->dataMap();
        /** @var eZImageType $imageAttribute */
        $fileAttribute = $dataMap[$this->configuration['file']['attribute']];
        /** @var eZBinaryFile $content */
        $content = $fileAttribute->content();

        //print_r($content);
        /*
        eZBinaryFile Object
        (
            [ContentObjectAttributeID] => 60160
            [Filename] => a3e1d474bbad368715eba8b02b478e75.docx
            [OriginalFilename] => KUF-komitéen - 2 videoer til Simon Goodchilds innlegg.docx
            [MimeType] => application/vnd.openxmlformats-officedocument.wordprocessingml.document
            [PersistentDataDirty] =>
            [Version] => 1
            [DownloadCount] => 50
        )
        */


        $filePath = eZSys::rootDir() . "/" . $content->filePath();
        if (file_exists($filePath)) {
            $src =  eZSys::storageDirectory() . "/original/application/{$content->Filename}";
            $exportPath = "{$this->exportFolder}/" . eZSys::storageDirectory() . "/original/application";
            if (!file_exists($exportPath)) {
                mkdir($exportPath, 0777, true);
            }
            copy($filePath, "$exportPath/{$content->Filename}");
            $a->setAttribute('href', $src);
            $a->setAttribute('data-ez_name', $object->name());

        } else {
            throw new UnresolvedTagException($node, "'$filePath' could not be resolved on the filesystem. Ignored this tag.");
        }
    }

    public function handle_ezxmltext (eZContentObjectAttribute $attr) {
        $xmlSource = strip_tags($attr->toString(), self::SUPPORTED_XMLTYPE_TAGS);
        $xmlSource = "<root>$xmlSource</root>";
        $dom = new DOMDocument('1.0', 'utf-8');
        if (!@$dom->loadXML($xmlSource)) {
        }
        $unresolvedNodes = array();

        // Paragraphs

        $xpath = new DOMXPath($dom);
        $paragraphs = $xpath->query('//paragraph');
        foreach($paragraphs as $p) {
            $paragraph = $this->dom_rename_element($p, 'p');
        }

        // Embeds ( images etc. )
        $xpath = new DOMXPath($dom);
        $embeds = $xpath->query('//embed');
        foreach($embeds as $embed) {
            try {
                $object_id = $embed->getAttribute('object_id');
                if ($object_id) {

                    if (eZContentObject::exists($object_id)) {
                        $relatedObject = eZContentObject::fetch($object_id);
                        $identifier = $relatedObject->attribute('class_identifier');

                        switch($identifier) {

                            case $this->configuration['file']['class']:
                                $this->handle_contentclass_file($embed, $relatedObject);
                                break;

                            case $this->configuration['image']['class']:
                                $this->handle_contentclass_image($embed, $relatedObject);
                                break;

                            default:
                                throw new UnresolvedTagException($embed, "'$identifier' can not be resolved by the parser ( related embed tag in xml ).");
                                break;
                        }

                    } else {
                        throw new UnresolvedTagException($embed, 'Could not fetch object by id.');
                    }

                } else {
                    throw new UnresolvedTagException($embed);
                }
            } catch(UnresolvedTagException $e) {
                $this->handleUnresolvedTag($e);
            }
        }

        // Links
        $xpath = new DOMXPath($dom);
        $links = $xpath->query('//link');
        foreach($links as $l) {
            try {
                $url_id = $l->getAttribute('url_id');
                $a= $this->dom_rename_element($l, 'a', true);

                if ($url_id) {
                    $url = eZURL::fetch($url_id);
                    $a->setAttribute('href', $url->URL);
                } else {
                    throw new UnresolvedTagException($l);
                }
            } catch(UnresolvedTagException $e) {
                $this->handleUnresolvedTag($e);
            }

        }


        $html = $dom->saveHTML();
        $html = str_replace(array('<root>', '</root>'), '', $html);
        $html = strip_tags($html, self::SUPPORTED_OUTPUT_HTML_TAGS);



        return $html;
    }


    private function handleUnresolvedTag (UnresolvedTagException $e) {
        $this->messages[] = $e->getMessage();
    }


    public function nodeToElement(array $attributesToExport, eZContentObjectTreeNode $node, DOMElement $rootElement) {
        $dataMap = $node->dataMap();


        $rootElement->setAttribute("original_nodeid", $node->attribute('node_id'));

        foreach($attributesToExport as $k => $attributeName) {
            /** @var eZContentObjectAttribute $attribute */
            $attribute = $dataMap[$attributeName];
            $type = $attribute->attribute('data_type_string');


            if (is_numeric($k)) {
                if (method_exists($this, "handle_$type")) {
                    $value = call_user_func_array(array($this, "handle_$type"), array($attribute));
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


    /**
     * Renames a node to another name.
     * @param DOMElement $node
     * @param $name
     * @param bool $skipAttributeCopy
     * @return DOMElement
     */
    public function dom_rename_element(DOMElement $node, $name, $skipAttributeCopy=false) {
        $renamed = $node->ownerDocument->createElement($name);

        if (!$skipAttributeCopy) {
            foreach ($node->attributes as $attribute) {
                $renamed->setAttribute($attribute->nodeName, $attribute->nodeValue);
            }
        }

        while ($node->firstChild) {
            $renamed->appendChild($node->firstChild);
        }

        $node->parentNode->replaceChild($renamed, $node);
        return $renamed;
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