<?php
/**
 * Created by PhpStorm.
 * User: pk
 * Date: 28.01.15
 * Time: 10:57
 */

class ApliaExportDataTypeObjectRelation extends ApliaExportDataType{


    /**
     * Handles files and images object relations. does not do anything else then that.
     *
     * @param eZContentObjectAttribute $attr
     */
    public function handle (eZContentObjectAttribute $attr) {
        $html = null;
        $supportedClassIdentifiers = array(
            $this->configuration['image']['class'],
            $this->configuration['file']['class']
        );

        /** @var eZContentObject $content */
        $content = $attr->content();


        if ($content && $objectId = $content->ID) {
            if (eZContentObject::exists($objectId)) {
                // We take use of the XML parser,
                // Just create a fake embed tag, given the object id, the xml parser will parse it to a html tag and put
                // image or file in the export directory.


                $tag = "<embed object_id='{$objectId}'></embed>";

                // Use the XML parser to DL the image to export package and replace with appropriate tag.
                $html = $this->getParser('ezxmltext')->handleString($tag);

            } else {
                $this->log("Could not find relation object id $objectId");
            }
        } else {
            // Relation is empty.. not an error.
        }


        return $html;
    }


} 