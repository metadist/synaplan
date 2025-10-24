<?php

// delete this line when splitting the code
use Netflie\WhatsAppCloudApi\WhatsAppCloudApi;
use Netflie\WhatsAppCloudApi\Message\Media\LinkID;
use Netflie\WhatsAppCloudApi\Message\Media\MediaObjectID;

class waSender
{
    public $waApi;

    public function __construct($waDetailsArr) {
        //error_log("WAtoken: " . $GLOBALS['WAtoken'] . " waDetailsArr: ". print_r($waDetailsArr, true));
        $this->waApi = new WhatsAppCloudApi([
            'from_phone_number_id' => $waDetailsArr['BWAPHONEID'], // Phone ID from the DB
            'access_token' => $GLOBALS['WAtoken'],
        ]);
    }

    public function sendText($phoneNumber, $message): mixed {
        $resp = $this->waApi->sendTextMessage($phoneNumber, $message);
        return $resp->decodedBody();
    }

    // send image
    public function sendImage($phoneNumber, $msgArr): mixed {
        $link_id = new LinkID($GLOBALS['baseUrl'] . 'up/' . $msgArr['BFILEPATH']);
        $resp = $this->waApi->sendImage($phoneNumber, $link_id, $msgArr['BTEXT']);
        //print "resp:";
        //print_r($resp);
        return $resp->decodedBody();
    }

    // send doc
    public function sendDoc($phoneNumber, $msgArr): mixed {
        $link_id = new LinkID($GLOBALS['baseUrl'] . 'up/' . $msgArr['BFILEPATH']);
        $resp = $this->waApi->sendDocument($phoneNumber, $link_id, basename($msgArr['BFILEPATH']), substr($msgArr['BTEXT'], 0, 64).'...');
        //print "resp:";
        //print_r($resp);
        return $resp->decodedBody();
    }

    // send audio
    public function sendAudio($phoneNumber, $msgArr): mixed {
        $link_id = new LinkID($GLOBALS['baseUrl'] . 'up/' . $msgArr['BFILEPATH']);
        $resp = $this->waApi->sendAudio($phoneNumber, $link_id);
        return $resp->decodedBody();
    }
}
