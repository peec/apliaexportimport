<?php
/**
 * Created by PhpStorm.
 * User: pk
 * Date: 23.01.15
 * Time: 16:02
 */

class ApliaContentClassNodeExporter {

    public $cli, $script, $tpl;

    public function __construct () {
        $this->tpl = eZTemplate::factory();

    }

    public function nodeToElement(array $attributesToExport, eZContentObjectTreeNode $node, DOMElement $element) {
        $dataMap = $node->dataMap();

        foreach($attributesToExport as $k => $attributeName) {
            /** @var eZContentObjectAttribute $attribute */
            $attribute = $dataMap[$attributeName];

            $datatypeString = $attribute->attribute( 'data_type_string' );

            if (is_numeric($k)) {
                $this->tpl->setVariable( 'attribute', $attribute );
                $value =  $this->tpl->fetch( 'design:content/datatype/view/' . $datatypeString . '.tpl');
            } else {
                $value = call_user_func_array($attributeName, array($attribute, $datatypeString));
            }

            $escapedValue = htmlspecialchars($value, ENT_QUOTES, 'UTF-8', false);
            $xmlNode = $element->ownerDocument->createElement($attributeName, $escapedValue);

            $element->appendChild($xmlNode);
        }
    }


    public function export ($contentClass, array $attributesToExport) {

        $nodes = eZContentObjectTreeNode::subTreeByNodeID(array(
            'ClassFilterType' => 'include',
            'ClassFilterArray' => array($contentClass)
        ), 2);

        $dom = new DOMDocument('1.0', 'utf-8');
        $root = $dom->createElement('export');
        $dom->appendChild($root);

        $i = 0;
        foreach($nodes as $node) {
            $element = $dom->createElement($contentClass);
            $this->nodeToElement($attributesToExport, $node, $element);
            $root->appendChild($element);

            if ($i == 5) {
                break; // HACK REMOVE .
            }
            $i++;
        }

        $xmlSource = $dom->saveXML();



        $this->cli->output($xmlSource);


        if ($this->cli) {
            $this->script->shutdown();

            file_put_contents("$contentClass-export.xml", $xmlSource);
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
        $this->script->initialize();
    }


} 