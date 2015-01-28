<?php
/**
 * Created by PhpStorm.
 * User: pk
 * Date: 28.01.15
 * Time: 10:26
 */

abstract class ApliaExportDataType {

    private $messages;
    public $configuration;
    public $exportFolder;
    public $parsers;

    public function __construct (&$messages, &$parsers) {
        $this->messages = $messages;
        $this->parsers = $parsers;
    }


    public function handleUnresolvedTag (UnresolvedTagException $e) {
        $this->messages[] = $e->getMessage();
    }


    public function setConfiguration ($configuration) {
        $this->configuration = $configuration;
    }

    public function setExportFolder ($exportFolder) {
        $this->exportFolder = $exportFolder;
    }

    abstract public function handle (eZContentObjectAttribute $attr);


    /**
     * @param $type
     * @return ApliaExportDataType
     * @throws Exception
     */
    public function getParser($type) {
        if (!isset($this->parsers[$type])) {
            throw new Exception("Parser $type not found in parsers, available are: " . implode(',',array_keys($this->parsers)));
        }
        return $this->parsers[$type];
    }


    public function log($message) {
        $this->messages[]  = $message;
    }
} 