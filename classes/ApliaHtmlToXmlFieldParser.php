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
     * These are the tags that will be left. All other tags are stripped...
     */
    const ALLOWED_TAGS = 'p,img[src],a[href],h1,h2,h3,h4,h5,h6,b,strong';


    /**
     * Instance of purifier..
     * @var HTMLPurifier
     */
    private $purifier;

    /**
     * @var string
     */
    private $html;

    /**
     * @var null
     */
    private $imageSrcResolverCallback  = null;

    /**
     * @var null
     */
    private $creator = null;

    /**
     * @var null
     */
    private $imageStorageNodeLocation = null;


    /**
     * Dont insert the same image to EZ many times, instead we keep track of a cache based on the URL of the image in the html..
     * @var array
     */
    static $imageCache = array();

    /**
     * @param $html
     * @param eZContentObjectTreeNode $creator
     * @param callable $imageSrcResolverCallback A FUNCTION TO RESOLVE IMG's tags src location..
     *      U should download it and the return of this function should be a valid path (string) to the same
     *      image that we gave as an argument. e.g. http://test.com/test.png becomes DL'ed to a cache dir and path
     *      returned from this function can then be /tmp/test.png forexample. eZ can then know to insert the img.
     * @param eZContentObjectTreeNode $imageStorageNodeLocation
     */
    public function __construct($html, eZUser $creator, $imageSrcResolverCallback, eZContentObjectTreeNode $imageStorageNodeLocation) {


        $this->setImageSrcResolverCallback($imageSrcResolverCallback);
        $this->setImageStorageNodeLocation($imageStorageNodeLocation);
        $this->setDefaultNodeCreator($creator);


        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', self::ALLOWED_TAGS);
        $this->purifier = new HTMLPurifier($config);
        $this->html = $this->purifier->purify($html);
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


    /**
     * Logic for manipulating --> A <-- tags .
     *
     * @return callable
     */
    public function tagA () {
        $that = $this;
        return function (Crawler $node, $i) use ($that) {
            $href = $node->attr('href');
            if ($href) {
                out("HREF $href");
                $oldNode = $node->getNode(0);

                $urlID = eZURL::registerURL( $href );
                $link = $this->dom_rename_element($oldNode, 'link', true);
                $link->setAttribute('url_id', $urlID);
            } else {
                out("href not found on a tag.");
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

            $that->dom_rename_element($oldNode, 'paragraph', true);
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


    /**
     * Logic for manipulating --> IMG <-- tags .
     *
     * @return callable
     */
    public function tagIMG () {
        $that = $this;
        return function (Crawler $node, $i) use ($that) {
            $imageUrl = $node->attr('src');
            $imageLocation = call_user_func_array($this->imageSrcResolverCallback, array($imageUrl));
            if ($imageUrl && $imageLocation) {
                if (file_exists($imageLocation)) {
                    out("Using image $imageLocation");
                    $imageObjectId = $this->storeOrRetrieveCachedImage($imageLocation);
                    $embed = $that->dom_rename_element($node->getNode(0), 'embed', true);
                    $embed->setAttribute('view', 'embed');
                    $embed->setAttribute('size', 'halfwidth');
                    $embed->setAttribute('object_id', $imageObjectId);
                } else {
                    out("Could not find image $imageLocation in folder, skipping...");
                }
            } else {
                out("src not found in img tag. Was $imageUrl , must be prefixed with: " . WEBARKIV_IMAGE_PREFIX_IN_HTML . ' to be valid.');
            }
        };
    }

    public function tagBODY () {
        $that = $this;
        return function (Crawler $node, $i) use ($that) {
            $section = $that->dom_rename_element($node->getNode(0), 'section');
            $section->setAttribute('xmlns:image', 'http://ez.no/namespaces/ezpublish3/image/');
            $section->setAttribute('xmlns:xhtml', 'http://ez.no/namespaces/ezpublish3/xhtml/');
            $section->setAttribute('xmlns:custom', 'http://ez.no/namespaces/ezpublish3/custom/');
        };
    }

    public function tagHX () {
        $that = $this;

        return function (Crawler $node, $i) use ($that) {
            $section = $that->dom_rename_element($node->getNode(0), 'header');
            preg_match('/h(\d+)/', $node->nodeName(), $matches);
            $level = isset($matches[1]) ? $matches[1] : 6;
            $section->setAttribute('level', $level);
        };
    }

    public function tagB() {
        $that = $this;
        return function (Crawler $node, $i) use ($that) {
            $section = $that->dom_rename_element($node->getNode(0), 'strong');
        };
    }


    /**
     * Creates image in EZ if the path was not already uploaded.
     *
     * If you try to upload the same image, again - it will not upload it twice, since it stores a cache as STATIC cache in this class.
     *
     * @param string $image_path A valid absolute path to an image. Should EXIST on the file system.
     * @return int OBJECT ID of the image created.
     * @throws Exception
     */
    public function storeOrRetrieveCachedImage ($image_path) {
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
        $attributesData['title'] = basename($image_path);
        $attributesData['image'] = basename($image_path);
        $params['attributes'] = $attributesData;

        /** @var eZContentObject $contentObject */
        $contentObject = eZContentFunctions::createAndPublishObject( $params );


        if ( $contentObject ) {
            out( "Image created. Content Object ID: " . $contentObject->attribute( 'id' ) );
        } else {
            throw new Exception("Could not create image.");
        }
        self::$imageCache[$image_path] = $contentObject->attribute( 'id' );
        return self::$imageCache[$image_path];
    }

    /**
     * Parses HTML -> XMLField
     * @return DOMElement|null|string
     */
    public function parse () {
        $content = $this->html;


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




        return $content;
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
    static public function htmlToXMLField ($html, eZUser $creator, $imageSrcResolverCallback, eZContentObjectTreeNode $imageStorageNodeLocation) {
        $parser = new self($html, $creator, $imageSrcResolverCallback, $imageStorageNodeLocation);
        return $parser->parse();
    }


}
