<?php
/**
 * Created by PhpStorm.
 * User: pk
 * Date: 28.01.15
 * Time: 10:25
 */

class ApliaExportDatatypeEzXML extends ApliaExportDataType{


    const SUPPORTED_XMLTYPE_TAGS = "<paragraph>,<embed>,<link>";
    const SUPPORTED_OUTPUT_HTML_TAGS = "<a>,<img>,<p>";


    public function handle_contentclass_image (DOMElement $node, eZContentObject $object) {
        $dataMap = $object->dataMap();
        /** @var eZImageType $imageAttribute */
        $imageAttribute = $dataMap[$this->configuration['image']['attribute']];
        /** @var eZImageAliasHandler $content */
        $content = $imageAttribute->content();
        $arrInfo = $content->attribute( 'original' );

        $src = $arrInfo['url'];
        $imagePath = eZSys::rootDir() . '/' . $arrInfo['full_path'];


        if (file_exists($imagePath) && is_file($imagePath) && !is_dir($imagePath)) {
            $img = ApliaExportImportUtil::domRenameElement($node, 'img', true);
            $exportPath = "{$this->exportFolder}/{$arrInfo['dirpath']}";
            if (!file_exists($exportPath)) {
                mkdir($exportPath, 0777, true);
            }
            copy($imagePath, "$exportPath/{$arrInfo['filename']}");
            $img->setAttribute('src', $src);
            $img->setAttribute('data-ez_name', $object->name());
        } else {
            throw new UnresolvedTagException($node, "'$imagePath' could not be resolved on the filesystem. Ignored this tag.");
        }
    }
    public function handle_contentclass_file (DOMElement $node, eZContentObject $object) {
        $dataMap = $object->dataMap();
        /** @var eZImageType $imageAttribute */
        $fileAttribute = $dataMap[$this->configuration['file']['attribute']];
        /** @var eZBinaryFile $content */
        $content = $fileAttribute->content();


        $filePath = eZSys::rootDir() . "/" . $content->filePath();
        if (file_exists($filePath)) {
            $a = ApliaExportImportUtil::domRenameElement($node, 'a', true);
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


    public function handleString ($xmlSource) {
        $xmlSource = strip_tags($xmlSource, self::SUPPORTED_XMLTYPE_TAGS);
        $xmlSource = "<root>$xmlSource</root>";
        $dom = new DOMDocument('1.0', 'utf-8');
        if (!@$dom->loadXML($xmlSource)) {
        }
        $unresolvedNodes = array();

        // Paragraphs

        $xpath = new DOMXPath($dom);
        $paragraphs = $xpath->query('//paragraph');
        foreach($paragraphs as $p) {
            $paragraph = ApliaExportImportUtil::domRenameElement($p, 'p');
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
                $a= ApliaExportImportUtil::domRenameElement($l, 'a', true);

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

    public function handle (eZContentObjectAttribute $attr) {
        return $this->handleString($attr->toString());
    }
} 