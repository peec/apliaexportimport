<?php
/**
 * Created by PhpStorm.
 * User: pk
 * Date: 28.01.15
 * Time: 10:32
 */

class ApliaExportImportUtil {

    /**
     * Renames a node to another name.
     * @param DOMElement $node
     * @param $name
     * @param bool $skipAttributeCopy
     * @return DOMElement
     */
    static public function domRenameElement(DOMElement $node, $name, $skipAttributeCopy=false) {
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