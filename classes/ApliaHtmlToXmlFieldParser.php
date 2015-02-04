<?php
/**
 *
 * Requires Htmlpurifier library to be loaded.
 * Requires DomCrawler symfony component ( install by composer ).
 * Requires CssSelector symfony component ( install by composer ).
 *
 */

use Symfony\Component\DomCrawler\Crawler;

/**
 * Class ApliaHtmlToXmlFieldParser
 *
 *
 */
class ApliaHtmlToXmlFieldParser {

    /**
     * These are the DEFAULT tags that will be left. All other tags are stripped...
     * The tags are eventually converted to EZ-XML-STYLE e.g. p => paragraph etc.
     */
    const ALLOWED_TAGS = 'p,img[src],a[href],h1,h2,h3,h4,h5,h6,b,strong';

    const SUPPORTED_EZ_TAGS = '<paragraph>,<section>,<header>,<embed>,<link>,<strong>,<b>';


    /**
     * Instance of purifier..
     * @var HTMLPurifier
     */
    public $purifier;

    /**
     * @var null
     */
    public $imageSrcResolverCallback  = null;

    public $fileResolverCallback = null;

    /**
     * @var null
     */
    public $creator = null;

    /**
     * @var null
     */
    public $imageStorageNodeLocation = null;

    public $messages = array();

    /**
     * Dont insert the same image to EZ many times, instead we keep track of a cache based on the URL of the image in the html..
     * @var array
     */
    static $imageCache = array();


    /**
     * @var array Multi dimensional array of tag => callable, callable should return FALSE if it should be excluded.
     */
    public $excludeTagHandlers = array();


    public $xmlTagHandler;



    /**
     * @param callable $imageSrcResolverCallback A FUNCTION TO RESOLVE IMG's tags src location..
     *      U should download it and the return of this function should be a valid path (string) to the same
     *      image that we gave as an argument. e.g. http://test.com/test.png becomes DL'ed to a cache dir and path
     *      returned from this function can then be /tmp/test.png forexample. eZ can then know to insert the img.
     * @param eZContentObjectTreeNode $imageStorageNodeLocation
     */
    public function __construct(
        $imageSrcResolverCallback,
        eZContentObjectTreeNode $imageStorageNodeLocation,
        $allowedTags = self::ALLOWED_TAGS
    ) {


        $this->setImageSrcResolverCallback($imageSrcResolverCallback);
        $this->setImageStorageNodeLocation($imageStorageNodeLocation);


        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', $allowedTags);
        $this->purifier = new HTMLPurifier($config);
    }

    /**
     * @param callable $callable
     * @return $this
     */
    public function setImageSrcResolverCallback ($callable) {
        $this->imageSrcResolverCallback = $callable;
        return $this;
    }

    /**
     * @param eZUser $node
     * @return $this
     */
    public function setDefaultNodeCreator (eZUser $node) {
        $this->creator = $node;
        return $this;
    }

    /**
     * @param eZContentObjectTreeNode $node
     * @return $this
     */
    public function setImageStorageNodeLocation (eZContentObjectTreeNode $node) {
        $this->imageStorageNodeLocation = $node;
        return $this;
    }


    public function addExcludeTagHandler($tagName, $callable) {
        if (!isset($this->excludeTagHandlers[$tagName])) {
            $this->excludeTagHandlers[$tagName] = array();
        }
        $this->excludeTagHandlers[$tagName][] = $callable;
    }

    public function checkExcludeTag ($tag, Crawler $node) {
        if (isset($this->excludeTagHandlers[$tag])) {
            foreach($this->excludeTagHandlers[$tag] as $k => $tagHandler) {
                $result = call_user_func_array($tagHandler, array($node));
                if ($result === false) {
                    $this->log("Tag '$tag' was excluded with tagHandler '$k'. On purpose.'");
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Logic for manipulating --> A <-- tags .
     *
     * @return callable
     */
    public function tagA () {
        $that = $this;
        return function (Crawler $node, $i) use ($that) {
            $href = $node->attr('href');
            $title = $node->attr('data-ez_name');

            if (!$that->checkExcludeTag('a', $node)) {
                return;
            }

            if ($href) {
                $that->log("HREF $href");
                $oldNode = $node->getNode(0);

                $fileLocation = call_user_func_array($that->fileResolverCallback, array($href));

                // If its a file...
                if ($fileLocation) {
                    $that->log("Using file $fileLocation");
                    $fileObjectId = $that->storeOrRetrieveCachedFile($fileLocation, $node->attr('data-ez_name'));
                    $embed = ApliaExportImportUtil::domRenameElement($node->getNode(0), 'embed', true);
                    $embed->setAttribute('view', 'embed');
                    $embed->setAttribute('object_id', $fileObjectId);
                    $that->handleCustomXmlModifications($embed);
                // Its a URL.
                } else {
                    $urlID = eZURL::registerURL( $href );
                    $link = ApliaExportImportUtil::domRenameElement($oldNode, 'link', true);
                    $link->setAttribute('url_id', $urlID);
                }
            } else {
                $that->log("href not found on a tag.");
            }
        };
    }

    /**
     * Logic for manipulating --> P <-- tags .
     *
     * @return callable
     */
    public function tagP () {
        $that = $this;
        return function (Crawler $node, $i) use ($that) {
            $oldNode = $node->getNode(0);

            if (!$that->checkExcludeTag('p', $node)) {
                return;
            }

            ApliaExportImportUtil::domRenameElement($oldNode, 'paragraph', true);
        };
    }


    /**
     * Removes empty tag if NODE is empty.
     * @param DOMElement $node
     */
    public function removeEmptyTags (DOMElement $node) {
        if (!trim(str_replace(array("\r","\n"), "", html_entity_decode((string)$node->nodeValue, null, 'utf-8'))) && !$node->hasChildNodes()) {
            $node->parentNode->removeChild($node);
        }
    }


    public function setCustomXmlTagHandler($xmlTagHandler) {
        $this->xmlTagHandler = $xmlTagHandler;
    }


    public function handleCustomXmlModifications (DOMElement $element) {
        if ($this->xmlTagHandler) {
            call_user_func_array($this->xmlTagHandler, array($element));
        }
    }

    /**
     * Logic for manipulating --> IMG <-- tags .
     *
     * @return callable
     */
    public function tagIMG () {
        $that = $this;
        return function (Crawler $node, $i) use ($that) {
            $imageUrl = $node->attr('src');
            $imageLocation = call_user_func_array($that->imageSrcResolverCallback, array($imageUrl));

            if (!$that->checkExcludeTag('img', $node)) {
                return;
            }


            if ($imageLocation) {

                if (@exif_imagetype($imageLocation)) {
                    if (file_exists($imageLocation)) {
                        $that->log("Using image $imageLocation");
                        $imageObjectId = $that->storeOrRetrieveCachedImage($imageLocation, $node->attr('data-ez_name'));
                        $embed = ApliaExportImportUtil::domRenameElement($node->getNode(0), 'embed', true);
                        $embed->setAttribute('view', 'embed');
                        $embed->setAttribute('size', 'original');
                        $embed->setAttribute('object_id', $imageObjectId);
                        $that->handleCustomXmlModifications($embed);
                    } else {
                        $that->log("Could not find image $imageLocation in folder, skipping...");
                    }
                } else {
                    $that->log("Corrupted image found in image tag: $imageLocation, skipping that one. (tested with exif_imagetype).");
                }
            } else {
                $that->log("src not found in img tag. Was $imageUrl. Create custom handler if you mean this should be checked.");
            }
        };
    }

    public function tagBODY () {
        $that = $this;
        return function (Crawler $node, $i) use ($that) {
            $section = ApliaExportImportUtil::domRenameElement($node->getNode(0), 'section');
            $section->setAttribute('xmlns:image', 'http://ez.no/namespaces/ezpublish3/image/');
            $section->setAttribute('xmlns:xhtml', 'http://ez.no/namespaces/ezpublish3/xhtml/');
            $section->setAttribute('xmlns:custom', 'http://ez.no/namespaces/ezpublish3/custom/');
        };
    }

    public function tagHX () {
        $that = $this;

        return function (Crawler $node, $i) use ($that) {
            $section = ApliaExportImportUtil::domRenameElement($node->getNode(0), 'header');
            preg_match('/h(\d+)/', $node->nodeName(), $matches);
            $level = isset($matches[1]) ? $matches[1] : 6;
            $section->setAttribute('level', $level);
        };
    }

    public function tagB() {
        $that = $this;
        return function (Crawler $node, $i) use ($that) {
            $section = ApliaExportImportUtil::domRenameElement($node->getNode(0), 'strong');
        };
    }


    /**
     * Creates image in EZ if the path was not already uploaded.
     *
     * If you try to upload the same image, again - it will not upload it twice, since it stores a cache as STATIC cache in this class.
     *
     * @param string $image_path A valid absolute path to an image. Should EXIST on the file system.
     * @param string $name
     * @return int OBJECT ID of the image created.
     * @throws Exception
     */
    public function storeOrRetrieveCachedImage ($image_path, $name = null) {
        if (isset(self::$imageCache[$image_path])) {
            return self::$imageCache[$image_path];
        }

        $parent_node = $this->imageStorageNodeLocation;
        $creator = $this->creator;

        $params = array();
        $params['class_identifier'] = 'image'; //class name (found within setup=>classes in the admin if you need it
        $params['creator_id'] = $creator->attribute( 'contentobject_id' ); //using the user created above
        $params['parent_node_id'] = $parent_node->attribute( 'node_id' ); //pulling the node id out of the parent
        $params['section_id'] = $parent_node->attribute( 'object' )->attribute( 'section_id' );
        $params['storage_dir' ] = dirname($image_path) . '/';

        $attributesData = array ();

        $attributesData['title'] = $name ? $name : basename($image_path);
        $attributesData['image'] = basename($image_path);
        $params['attributes'] = $attributesData;

        /** @var eZContentObject $contentObject */
        $contentObject = eZContentFunctions::createAndPublishObject( $params );


        if ( $contentObject ) {
            $this->log( "Image created. Content Object ID: " . $contentObject->attribute( 'id' ) );
        } else {
            throw new Exception("Could not create image.");
        }
        self::$imageCache[$image_path] = $contentObject->attribute( 'id' );
        return self::$imageCache[$image_path];
    }




    /**
     * Creates FILE in EZ if the path was not already uploaded.
     *
     * If you try to upload the same file, again - it will not upload it twice, since it stores a cache as STATIC cache in this class.
     *
     * @param string $image_path A valid absolute path to an image. Should EXIST on the file system.
     * @param string $name
     * @return int OBJECT ID of the image created.
     * @throws Exception
     */
    public function storeOrRetrieveCachedFile ($file_path, $name = null) {
        if (isset(self::$imageCache[$file_path])) {
            return self::$imageCache[$file_path];
        }

        $parent_node = $this->imageStorageNodeLocation;
        $creator = $this->creator;

        $params = array();
        $params['class_identifier'] = 'file'; //class name (found within setup=>classes in the admin if you need it
        $params['creator_id'] = $creator->attribute( 'contentobject_id' ); //using the user created above
        $params['parent_node_id'] = $parent_node->attribute( 'node_id' ); //pulling the node id out of the parent
        $params['section_id'] = $parent_node->attribute( 'object' )->attribute( 'section_id' );
        $params['storage_dir' ] = dirname($file_path) . '/';

        $attributesData = array ();

        $attributesData['title'] = $name ? $name : basename($file_path);
        $attributesData['file'] = basename($file_path);
        $params['attributes'] = $attributesData;

        /** @var eZContentObject $contentObject */
        $contentObject = eZContentFunctions::createAndPublishObject( $params );


        if ( $contentObject ) {
            $this->log( "File created. Content Object ID: " . $contentObject->attribute( 'id' ) );
        } else {
            throw new Exception("Could not create image.");
        }
        self::$imageCache[$file_path] = $contentObject->attribute( 'id' );
        return self::$imageCache[$file_path];
    }



    /**
     * Parses HTML -> XMLField
     * @return DOMElement|null|string
     */
    public function parse ($html) {
        $content = $this->purifier->purify($html);


        $content = "<!doctype html><html><head><meta charset='utf-8' /></head><body>$content</body></html>";
        $crawler = new Crawler();
        $crawler->addContent($content);


        $crawler->filter('a')->each($this->tagA());


        $crawler->filter('p')->each($this->tagP());


        $crawler->filter('img')->each($this->tagIMG());


        $crawler->filter('b')->each($this->tagB());


        $crawler->filter('body')->each($this->tagBODY());

        $crawler->filter('h1,h2,h3,h4,h5,h6')->each($this->tagHX());




        $content = $crawler->filter('section')->getNode(0);

        $tempDom = new DOMDocument('1.0', 'utf-8');
        $tempImported = $tempDom->importNode($content, true);
        $tempDom->appendChild($tempImported);

        $content = $tempDom->saveXML();


        // Regexes DANGER DANGER.
        // Kind of hack, but I found no other way yet. Still, this can't do ANY harm what so ever.
        // Answer to above: Well, if it only where doing the job. It does not work.
        $content = preg_replace('#<paragraph>(\s+|\t+)</paragraph>#is', '', $content);
        $content = preg_replace('#<paragraph>(\s+|\t+)&nbsp;(\s+|\t+)</paragraph>#is', '', $content);

        $content = strip_tags($content, self::SUPPORTED_EZ_TAGS);



        return $content;
    }

    /**
     * Just converts a simple string to a XML field. Strips all the tags in $content.
     * @param $content Note, tags will be stripped.
     * @return string
     */
    static public function stringToXMLField ($content) {
        return '<?xml version="1.0" encoding="utf-8"?><section xmlns:image="http://ez.no/namespaces/ezpublish3/image/" xmlns:xhtml="http://ez.no/namespaces/ezpublish3/xhtml/" xmlns:custom="http://ez.no/namespaces/ezpublish3/custom/"><paragraph>' . strip_tags($content) . '</paragraph></section>';
    }


    /**
     * Parses HTML -> XMLField compatible.
     * Will import images, convert URLS to link tanks etc. Returns a valid XML to put into the data_text of XML Field Type.
     *
     * @param $html
     * @param eZContentObjectTreeNode $creator
     * @param $imageSrcResolverCallback
     * @param eZContentObjectTreeNode $imageStorageNodeLocation
     * @return DOMElement|null|string
     */
    static public function htmlToXMLField ($html, $imageSrcResolverCallback, eZContentObjectTreeNode $imageStorageNodeLocation) {
        $parser = new self($imageSrcResolverCallback, $imageStorageNodeLocation);
        return $parser->parse($html);
    }


    public function log($message) {
        $this->messages[] = $message;
    }



    /**
     * Parses IMG or A tag to relation id ( file or image ).
     * @param $html
     */
    public function imageOrFileToObjectRelationId ($html) {
        if (!trim($html)) {
            return null;
        }

        $xml = $this->parse($html);
        $dom = new DOMDocument('1.0', 'utf-8');
        $dom->loadXML($xml);
        $path = new DOMXPath($dom);
        $embeds = $path->query('//embed');

        if ($embeds->length) {
            $e = $embeds->item(0);
            return $e->getAttribute('object_id');
        } else {
            return null;
        }
    }


    /**
     * Gets defaultImgSrcResolver, downloads images found in src attributes and returns a path to the image.
     * If the image already exist, it does not get downloaded.
     *
     *
     * @param $tempDir A VALID PATH TO A temporary location of where to store downloaded images. e.g. /tmp/myexportedimagestest
     * @param $lookupVarPath A valid filesystem path
     * @return callable
     * @throws Exception
     */
    static public function defaultImageSrcResolver ($tempDir, $lookupVarPath = null) {

        return function ($imageUrl) use ($tempDir, $lookupVarPath) {
            $imageLocation = null;
            // here we can DL the image by $imageUrl.. but we already have all images in a folder so not needed..
            if (stripos($imageUrl, 'http') === 0) {
                if (!is_dir($tempDir)) {
                    mkdir($tempDir, 0777, true);
                }

                $filename = basename($imageUrl);
                $path = "$tempDir/$filename";
                if (!file_exists($path)) {
                    if (copy($imageUrl, $path)) {
                        $imageLocation = $path;
                    } else {
                        // throw Exception ? or to harsh? I mean if the image src does not get found, lets do it silently.
                    }
                } else {
                    $imageLocation = $path;
                }
            } else if (strpos($imageUrl, 'var/') === 0) {
                $loc = "$lookupVarPath/$imageUrl";
                if (file_exists($loc)) {
                    $imageLocation = $loc;
                }
            }
            return $imageLocation;
        };
    }


    public function setFileResolverDirectory($lookupVarPath) {
        if (!is_dir($lookupVarPath)) {
            throw new Exception("\$lookupVarPath ($lookupVarPath) must be a valid folder to put temporary downloaded files.");
        }

        $this->fileResolverCallback = function ($fileUrl) use ($lookupVarPath) {
            $fileLocation = null;
            // here we can DL the image by $imageUrl.. but we already have all images in a folder so not needed..
            if (strpos($fileUrl, 'var/') === 0) {
                $loc = "$lookupVarPath/$fileUrl";
                if (file_exists($loc)) {
                    $fileLocation = $loc;
                }
            }
            return $fileLocation;
        };
    }

}
