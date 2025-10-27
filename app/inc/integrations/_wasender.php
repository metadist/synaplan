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
        try {
            $resp = $this->waApi->sendTextMessage($phoneNumber, $message);
            $decoded = $resp->decodedBody();

            // Log response for debugging
            if (!empty($GLOBALS['debug'])) {
                error_log("waSender::sendText SUCCESS to $phoneNumber - Response: " . json_encode($decoded));
            }

            return $decoded;
        } catch (\Exception $e) {
            $errorMsg = "WhatsApp API Error (sendText to $phoneNumber): " . $e->getMessage();
            error_log($errorMsg);

            // Check if it's a specific WhatsApp error
            if (method_exists($e, 'getResponse')) {
                $response = $e->getResponse();
                error_log('WhatsApp API Response: ' . print_r($response, true));
            }

            throw new \Exception($errorMsg, 0, $e);
        }
    }

    // send image
    public function sendImage($phoneNumber, $msgArr): mixed {
        try {
            $link_id = new LinkID($GLOBALS['baseUrl'] . 'up/' . $msgArr['BFILEPATH']);
            $resp = $this->waApi->sendImage($phoneNumber, $link_id, $msgArr['BTEXT']);
            $decoded = $resp->decodedBody();

            if (!empty($GLOBALS['debug'])) {
                error_log("waSender::sendImage SUCCESS to $phoneNumber");
            }

            return $decoded;
        } catch (\Exception $e) {
            $errorMsg = "WhatsApp API Error (sendImage to $phoneNumber): " . $e->getMessage();
            error_log($errorMsg);
            throw new \Exception($errorMsg, 0, $e);
        }
    }

    // send doc
    public function sendDoc($phoneNumber, $msgArr): mixed {
        try {
            $link_id = new LinkID($GLOBALS['baseUrl'] . 'up/' . $msgArr['BFILEPATH']);
            $resp = $this->waApi->sendDocument($phoneNumber, $link_id, basename($msgArr['BFILEPATH']), substr($msgArr['BTEXT'], 0, 64).'...');
            $decoded = $resp->decodedBody();

            if (!empty($GLOBALS['debug'])) {
                error_log("waSender::sendDoc SUCCESS to $phoneNumber");
            }

            return $decoded;
        } catch (\Exception $e) {
            $errorMsg = "WhatsApp API Error (sendDoc to $phoneNumber): " . $e->getMessage();
            error_log($errorMsg);
            throw new \Exception($errorMsg, 0, $e);
        }
    }

    // send audio
    public function sendAudio($phoneNumber, $msgArr): mixed {
        try {
            $link_id = new LinkID($GLOBALS['baseUrl'] . 'up/' . $msgArr['BFILEPATH']);
            $resp = $this->waApi->sendAudio($phoneNumber, $link_id);
            $decoded = $resp->decodedBody();

            if (!empty($GLOBALS['debug'])) {
                error_log("waSender::sendAudio SUCCESS to $phoneNumber");
            }

            return $decoded;
        } catch (\Exception $e) {
            $errorMsg = "WhatsApp API Error (sendAudio to $phoneNumber): " . $e->getMessage();
            error_log($errorMsg);
            throw new \Exception($errorMsg, 0, $e);
        }
    }
}
