<?php

/*
    Central is the include for distribution and handling of the message.
    It is simple class, that will be used by the input handler.
    And defines the use of the message, logging and conversation flow.
*/

use Codewithkyrian\Whisper\Whisper;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpPresentation\IOFactory as PresentationIOFactory;
use PhpOffice\PhpPresentation\Shape\RichText;

use function Codewithkyrian\Whisper\readAudio;
use function Codewithkyrian\Whisper\toTimestamp;

// Ensure UniversalFileHandler and its dependencies are available
if (!class_exists('\\UniversalFileHandler')) {
    require_once __DIR__ . '/../domain/files/universal_file_handler.php';
}
if (!class_exists('\\TikaClient')) {
    require_once __DIR__ . '/../domain/files/tika_client.php';
}
if (!class_exists('\\Rasterizer')) {
    require_once __DIR__ . '/../domain/files/rasterizer.php';
}


class Central
{
    // enrich the message with user information and other data
    // save it to the DB
    public static function handleInMessage($arrMessage): array|string|bool
    {
        // check, if there is a conversation open with this trackid
        // set conversion group ID, if so
        //error_log('Central::handleInMessage '.print_r($arrMessage, true));

        $retArray = [];
        $retArray['error'] = '';
        $retArray['lastId'] = 0;
        // -------------------------------------------------------
        foreach ($arrMessage as $field => $val) {
            // Collect the field names
            $fields[] = $field;

            if ($field == 'BID') {
                $values[] = 'DEFAULT';
            } else {
                // Escape or sanitize $val as necessary. Here, just simple addslashes()
                if (is_numeric($val)) {
                    $values[] = $val;
                } else {
                    if (is_string($val)) {
                        $values[] = "'" . db::EscString($val) . "'";
                    } else {
                        $values[] = 0;
                    }
                }
            }
        }
        $newSQL = 'insert into BMESSAGES (' . implode(',', $fields) . ') values (' . implode(',', $values) . ')';
        $newRes = db::Query($newSQL);
        // --
        $retArray['error'] = '';
        $retArray['lastId'] = 0;
        // --
        if ($newRes) {
            $retArray['lastId'] = db::LastId();
        } else {
            $retArray['error'] = 'Could not save message to DB';
        }
        return $retArray;
    }

    // Status updates
    public static function handleStatus($arrStatus): array|string|bool
    {

        return $arrStatus;
    }
    // ******************************************************************************************************
    // handle the prompt id for the message
    // ******************************************************************************************************
    public static function handlePromptIdForMessage($arrMessage): string
    {
        $messPromptId = 'tools:sort';
        if (isset($_REQUEST['promptId'])) {
            $messPromptId = db::EscString($_REQUEST['promptId']);
        }
        // update the message itself
        if ($messPromptId != 'tools:sort') {
            //set the prompt id per message
            $metaSQL = 'insert into BMESSAGEMETA (BID, BMESSID, BTOKEN, BVALUE) values (DEFAULT, '.(0 + $arrMessage['BID']).", 'PROMPTID', '".$messPromptId."');";
            $metaRes = db::Query($metaSQL);
            // update the message itself
            $updateSQL = "update BMESSAGES set BTOPIC = '".db::EscString($messPromptId)."' where BID = ".intval($arrMessage['BID']);
            db::Query($updateSQL);
        }
        return $messPromptId;
    }

    // ******************************************************************************************************
    // complete the message set with user information
    // get user by phone number
    // return user arr
    public static function getUserByPhoneNumber($phoneNumber, $createNew = true): array|null|bool
    {
        $arrUser = [];
        $getSQL = "select * from BUSER where BPROVIDERID = '".(db::EscString($phoneNumber))."' AND BINTYPE = 'WA'";
        $res = db::Query($getSQL);
        $arrUser = db::FetchArr($res);

        // creates the user if not exists, only if $createNew is true
        if (!$arrUser and $createNew) {
            $userDetails = [];
            $userDetails['MAIL'] = '';
            $userDetails['MAILCHECKED'] = dechex(rand(1025, 64000)).date('s');
            $userDetails['PHONE'] = $phoneNumber;
            $userDetails['CREATED'] = date('YmdHi');

            $newSQL = "insert into BUSER (BID, BCREATED, BINTYPE, BMAIL, BPW, BPROVIDERID, BUSERLEVEL, BUSERDETAILS) 
                values (DEFAULT, '".date('YmdHis')."', 'WA', '', '', '".(db::EscString($phoneNumber))."', 'NEW', '".(db::EscString(json_encode($userDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))."')";

            db::Query($newSQL);
            $getSQL = "select * from BUSER where BPROVIDERID = '".(db::EscString($phoneNumber))."'";
            $res = db::Query($getSQL);
            $arrUser = db::FetchArr($res);
            return $arrUser;
        }
        return $arrUser;
    }
    // get user by mail
    public static function getUserByMail($mail, $phoneNumberOrTag, $createNew = true): array|null|bool
    {
        $arrUser = [];
        // first look in the user kinds
        $escapedProvId = db::EscString(Tools::idFromMail($mail));
        $escapedMail = db::EscString($mail);
        $getSQL = sprintf(
            'SELECT * FROM BUSER WHERE ' .
            "(BPROVIDERID = '%s' AND BINTYPE = 'MAIL') OR " .
            "(BINTYPE = 'WA' AND BUSERDETAILS LIKE '%%:\"%s\"%%') OR " .
            "(BMAIL = '%s' AND BINTYPE = 'OIDC')",
            $escapedProvId,
            $escapedMail,
            $escapedMail
        );
        $res = db::Query($getSQL);
        $arrUser = db::FetchArr($res);

        // creates the user if not exists, only if $createNew is true
        if (!$arrUser and $createNew) {
            $userDetails = [];
            $userDetails['MAIL'] = $mail;
            $userDetails['MAILCHECKED'] = dechex(rand(1025, 64000)).date('s');
            $userDetails['PHONE'] = ''; //$phoneNumberOrTag;
            $userDetails['CREATED'] = date('YmdHi');
            $newSQL = "insert into BUSER (BID, BCREATED, BINTYPE, BMAIL, BPW, BPROVIDERID, BUSERLEVEL, BUSERDETAILS) 
                values (DEFAULT, '".date('YmdHis')."', 'MAIL', '".(db::EscString($mail))."', '', '".$escapedProvId."', 'NEW', '".(db::EscString(json_encode($userDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))."')";
            db::Query($newSQL);
            // --
            $getSQL = "select * from BUSER where BPROVIDERID = '".$escapedProvId."'";
            $res = db::Query($getSQL);
            $arrUser = db::FetchArr($res);
            $arrUser['DETAILS'] = json_decode($arrUser['BUSERDETAILS'], true);
            return $arrUser;
        }

        $arrUser['DETAILS'] = json_decode($arrUser['BUSERDETAILS'], true);
        return $arrUser;
    }

    // get the user by phone or tag, that means that the BINTYPE CAN VARY
    // NOT READY YET!
    public static function getUserByPhoneNumberOrTag($phoneNumberOrTag, $createMail = true): array|null|bool
    {
        $arrUser = [];

        if (intval($phoneNumberOrTag) > 0) {
            $getSQL = "select * from BUSER where BPROVIDERID = '".(db::EscString($phoneNumberOrTag))."' AND BINTYPE = 'WA'";
        } else {
            $getSQL = "select * from BUSER where BPROVIDERID = '".(db::EscString($phoneNumberOrTag))."' AND BINTYPE = 'MAIL'";
        }

        $res = db::Query($getSQL);
        $arrUser = db::FetchArr($res);

        // creates the user if not exists, only if $createNew is true
        if (intval($phoneNumberOrTag) == 0 and strlen($phoneNumberOrTag) > 1) {
            // create the user
            if (!$arrUser and $createMail) {
                if (strlen($phoneNumberOrTag) < 5) {
                    $phoneNumberOrTag = '';
                }

                $newSQL = "insert into BUSER (BID, BCREATED, BINTYPE, BMAIL, BPW, BPROVIDERID) 
                    values (DEFAULT, '".date('YmdHis')."', 'MAIL', '', '', '".(db::EscString($phoneNumberOrTag))."')";
                db::Query($newSQL);
                $getSQL = "select * from BUSER where BPROVIDERID = '".(db::EscString($phoneNumberOrTag))."'";
                $res = db::Query($getSQL);
                $arrUser = db::FetchArr($res);
                return $arrUser;
            }
        }
        return $arrUser;
    }
    // get the seconds since the last message of the user
    public static function getSecondsSinceLastMessage($arrMessage): int
    {
        $searchSQL = 'select * from BMESSAGES where BUSERID = '.$arrMessage['BUSERID'].' and BUNIXTIMES < '.$arrMessage['BUNIXTIMES'].' 
            order by BID desc limit 1';
        $res = db::Query($searchSQL);
        $arrConv = db::FetchArr($res);
        $lastTime = 0;

        if ($arrConv) {
            $lastTime = intval($arrMessage['BUNIXTIMES']) - intval($arrConv['BUNIXTIMES']);
        }

        return $lastTime;
    }
    // search for a conversation with the user and message details
    public static function searchConversation($arrMessage): array|string|bool
    {
        $arrConv = [];
        $arrConv['BID'] = 0;
        // search for the last message of the user
        $searchSQL = 'select * from BMESSAGES where BUSERID = '.$arrMessage['BUSERID'].' and BUNIXTIMES < '.$arrMessage['BUNIXTIMES'].' 
            AND BUNIXTIMES > '.($arrMessage['BUNIXTIMES'] - 360).' order by BID desc limit 1';

        // error_log("searchConversation: ".$searchSQL);

        $res = db::Query($searchSQL);
        $arrConv = db::FetchArr($res);

        if ($arrConv) {
            if ($arrConv['BLANG'] != 'NN' and strlen($arrConv['BLANG']) == 2) { // $arrConv['BTOPIC'] != 'UNKNOWN' AND
                $arrMessage['BTOPIC'] = $arrConv['BTOPIC'];
                $arrMessage['BLANG'] = $arrConv['BLANG'];
                $arrMessage['BTRACKID'] = $arrConv['BTRACKID'];
                if (isset($arrMessage['BID']) and intval($arrMessage['BID']) > 0) {
                    $updateSQL = "update BMESSAGES set BTOPIC = '".$arrMessage['BTOPIC']."', BLANG = '".$arrMessage['BLANG']."', 
                        BTRACKID = ".intval($arrMessage['BTRACKID']).' where BID = '.intval($arrMessage['BID']);
                    db::Query($updateSQL);
                }
                return $arrConv;
            }
        }
        return false;
    }
    // language by country code
    public static function getLanguageByCountryCode($phoneNumber): string
    {
        $arrLang = [
            '1' => 'en',
            '49' => 'de',
            '44' => 'en',
            '34' => 'es',
            '33' => 'fr',
            '31' => 'nl',
            '41' => 'de',
            '45' => 'da',
            '46' => 'sv',
            '47' => 'no',
            '43' => 'de',
            '39' => 'it',
            '351' => 'pt',
            '352' => 'fr',
            '356' => 'en',
        ];
        foreach ($arrLang as $code => $lang) {
            $len = strlen($code);
            $phoneCode = substr($phoneNumber, 0, $len);
            if ($phoneCode == $code) {
                return $lang;
            }
        }
        return 'en';
    }
    // mime types allowed
    public static function checkMimeTypes($extension, $mimeType): bool
    {
        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'mp3', 'mp4','svg','ppt','pptx','csv','txt','md','html','htm'];
        $allowedMimeTypes = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/gif',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'audio/mpeg',
            'video/mp4',
            'image/svg+xml',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/csv',
            'text/html',
            'text/markdown',
            'text/x-markdown',
            'text/plain'
        ];
        if (in_array($extension, $allowedExtensions) or in_array($mimeType, $allowedMimeTypes)) {
            return true;
        }
        return false;
    }

    /**
     * Check MIME types for anonymous widget users (restricted file types)
     *
     * @param string $extension File extension
     * @param string $mimeType MIME type
     * @return bool True if file type is allowed for anonymous users
     */
    public static function checkMimeTypesForAnonymous($extension, $mimeType): bool
    {
        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'gif'];
        $allowedMimeTypes = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/gif'
        ];
        if (in_array($extension, $allowedExtensions) or in_array($mimeType, $allowedMimeTypes)) {
            return true;
        }
        return false;
    }
    // language by the browser settings
    public static function getLanguageByBrowser(): string
    {
        if (!isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            return 'en'; // fallback
        }

        $acceptLang = $_SERVER['HTTP_ACCEPT_LANGUAGE'];

        // Split the header by comma to get individual language entries
        $languages = explode(',', $acceptLang);

        if (count($languages) === 0) {
            return 'en';
        }

        // Take the first language preference (e.g., "en-DE;q=0.9")
        $primary = trim($languages[0]);

        // Extract the language code before the dash or semicolon (e.g., "en" from "en-DE" or "en;q=0.9")
        if (preg_match('/^([a-zA-Z]{2})[-;]/', $primary, $matches)) {
            return strtolower($matches[1]);
        }

        // Fallback: check if it's just a 2-letter language code (e.g., "fr")
        if (preg_match('/^([a-zA-Z]{2})$/', $primary, $matches)) {
            return strtolower($matches[1]);
        }

        return 'en'; // ultimate fallback
    }

    // parse the file
    public static function parseFile($arrMessage, $streamOutput = false): array|string|bool
    {
        $fileType = 0;
        if ($streamOutput) {
            $update = [
                'msgId' => $arrMessage['BID'],
                'status' => 'pre_processing',
                'message' => basename($arrMessage['BFILEPATH']).' '
            ];
            Frontend::printToStream($update);
        }

        // image file
        // ********************************************** AI_PIC2TEXT **********************************************
        if ($arrMessage['BFILETYPE'] == 'jpeg' or $arrMessage['BFILETYPE'] == 'jpg' or $arrMessage['BFILETYPE'] == 'png') {
            // resize the image, if needed
            $fileType = 1;

            $AIV2T = $GLOBALS['AI_PIC2TEXT']['SERVICE'];
            $arrMessage = $AIV2T::explainImage($arrMessage);

            if ($arrMessage['BLANG'] != 'en' and strlen($arrMessage['BFILETEXT']) > 30) {
                if (strlen($arrMessage['BTEXT']) < 2) {
                    $arrMessage['BTEXT'] = 'Please summarize this in **'.$arrMessage['BLANG']."**, use category '**general**':\n\n".$arrMessage['BFILETEXT'];
                }

                $AISUMMARIZE = $GLOBALS['AI_SUMMARIZE']['SERVICE'];
                $translatedArr = $AISUMMARIZE::translateTo($arrMessage, $arrMessage['BLANG'], 'BFILETEXT');
                $arrMessage = $translatedArr;
            }

            if ($arrMessage['BID'] > 0) {
                $updateSQL = "update BMESSAGES set BFILETEXT = '".db::EscString($arrMessage['BFILETEXT'])."' where BID = ".($arrMessage['BID']);
                db::Query($updateSQL);
            }

            // ------------------------------------------------------------
            // write to stream
            if ($streamOutput) {
                $update = [
                    'msgId' => $arrMessage['BID'],
                    'status' => 'pre_processing',
                    'message' => $AIV2T . ': image processed. '
                ];
                Frontend::printToStream($update);
            }
            // ------------------------------------------------------------
        }

        // sound/video file
        // ********************************************** SOUND2TEXT **********************************************
        if ($arrMessage['BFILETYPE'] == 'mp3') {  // $arrMessage['BFILETYPE'] == "mp4" not supported yet
            // Transcribe Audio https://huggingface.co/ggerganov/whisper.cpp
            // LOCAL Whisper
            /*
            $fileType = 2;
            $whisper = Whisper::fromPretrained('medium', baseDir: __DIR__.'/models');
            $audio = readAudio('./up/'.$arrMessage['BFILEPATH']);
            $segments = $whisper->transcribe($audio, 4);
            $fullText = "";
            foreach ($segments as $segment) {
                if(strlen($fullText)>2) $fullText .= " ";
                $fullText .= $segment->text;
            }
            */
            $fileType = 2;
            $AIS2T = $GLOBALS['AI_SOUND2TEXT']['SERVICE'];
            $fullText = $AIS2T::mp3ToText($arrMessage);
            $arrMessage['BFILETEXT'] = trim($fullText);

            if ($arrMessage['BTEXT'] == '' and $arrMessage['BMESSTYPE'] == 'WA') {
                $arrMessage['BTEXT'] = $arrMessage['BFILETEXT'];
                $updateSQL = "update BMESSAGES set BTEXT = '".db::EscString($fullText)."', BFILETEXT = '".db::EscString($fullText)."' where BID = ".($arrMessage['BID']);
            } else {
                $updateSQL = "update BMESSAGES set BFILETEXT = '".db::EscString($fullText)."' where BID = ".($arrMessage['BID']);
            }
            db::Query($updateSQL);
            // ------------------------------------------------------------
            // write to stream
            if ($streamOutput) {
                $update = [
                    'msgId' => $arrMessage['BID'],
                    'status' => 'pre_processing',
                    'message' => 'sound converted to text '
                ];
                Frontend::printToStream($update);
            }
            // ------------------------------------------------------------
        }

        // mp4 needs to be converted to mp3
        // ********************************************** MP42MP3 **********************************************
        if ($arrMessage['BFILETYPE'] == 'mp4') {
            $fileType = 2;
            $saveTo = './up/'.substr($arrMessage['BFILEPATH'], 0, -3) . 'mp3';
            //--
            // print "Converting mp4 to mp3: ".$saveTo."\n";
            //--
            // write to stream
            if ($streamOutput) {
                $update = [
                    'msgId' => $arrMessage['BID'],
                    'status' => 'pre_processing',
                    'message' => 'Converting mp4 to mp3: '.$saveTo.' '
                ];
                Frontend::printToStream($update);
            }

            if (file_exists($saveTo)) {
                unlink($saveTo);
            }

            // First, check if the MP4 has audio track
            $audioCheckCmd = 'ffprobe -v quiet -select_streams a -show_entries stream=codec_type -of csv=p=0 "./up/'.$arrMessage['BFILEPATH'].'"';
            $audioCheck = exec($audioCheckCmd);

            if (empty($audioCheck)) {
                // No audio track found, skip processing
                $arrMessage['BFILETEXT'] = 'This video file contains no audio track.';
                if ($arrMessage['BID'] > 0) {
                    $updateSQL = "update BMESSAGES set BFILETEXT = '".db::EscString($arrMessage['BFILETEXT'])."' where BID = ".($arrMessage['BID']);
                    db::Query($updateSQL);
                }

                if ($streamOutput) {
                    $update = [
                        'msgId' => $arrMessage['BID'],
                        'status' => 'pre_processing',
                        'message' => 'No audio track found in video '
                    ];
                    Frontend::printToStream($update);
                }
            } else {
                // Check audio duration and volume to detect silent audio
                $durationCmd = 'ffprobe -v quiet -show_entries format=duration -of csv=p=0 "./up/'.$arrMessage['BFILEPATH'].'"';
                $duration = floatval(exec($durationCmd));

                // Check if audio has any volume (not silent)
                $volumeCheckCmd = 'ffmpeg -i "./up/'.$arrMessage['BFILEPATH']."\" -af volumedetect -f null - 2>&1 | grep -E 'mean_volume|max_volume'";
                $volumeInfo = exec($volumeCheckCmd);

                // Extract mean volume level
                preg_match('/mean_volume: ([-\d.]+) dB/', $volumeInfo, $matches);
                $meanVolume = isset($matches[1]) ? floatval($matches[1]) : -100; // Default to very low if not found

                // If audio is very quiet (below -50dB) or duration is very short, consider it silent
                if ($meanVolume < -50 || $duration < 0.5) {
                    $arrMessage['BFILETEXT'] = 'This video file contains silent or very quiet audio.';
                    if ($arrMessage['BID'] > 0) {
                        $updateSQL = "update BMESSAGES set BFILETEXT = '".db::EscString($arrMessage['BFILETEXT'])."' where BID = ".($arrMessage['BID']);
                        db::Query($updateSQL);
                    }

                    if ($streamOutput) {
                        $update = [
                            'msgId' => $arrMessage['BID'],
                            'status' => 'pre_processing',
                            'message' => 'Silent audio detected in video '
                        ];
                        Frontend::printToStream($update);
                    }
                } else {
                    // Proceed with normal conversion
                    //error_log("ffmpeg -loglevel panic -hide_banner -i \"./up/".$arrMessage['BFILEPATH']."\" -acodec libmp3lame -ab 96k \"".$saveTo."\"");
                    $exRes = exec('ffmpeg -loglevel panic -hide_banner -i "./up/'.$arrMessage['BFILEPATH'].'" -acodec libmp3lame -ab 96k "'.$saveTo.'"');

                    // Check if conversion was successful and file exists with content
                    if (file_exists($saveTo) && filesize($saveTo) > 0) {
                        try {
                            // converted, now whisper transcribe it
                            $whisper = Whisper::fromPretrained('medium', baseDir: __DIR__.'/../whispermodels');
                            $audio = readAudio($saveTo);
                            $segments = $whisper->transcribe($audio, 4);
                            $fullText = '';
                            foreach ($segments as $segment) {
                                if (strlen($fullText) > 2) {
                                    $fullText .= ' ';
                                }
                                $fullText .= $segment->text;
                            }
                            $arrMessage['BFILETEXT'] = trim($fullText);

                            // If transcription is empty, it might be silent
                            if (empty($fullText)) {
                                $arrMessage['BFILETEXT'] = '';
                                // ------------------------------------------------------------
                                // write to stream
                                if ($streamOutput) {
                                    $update = [
                                        'msgId' => $arrMessage['BID'],
                                        'status' => 'pre_processing',
                                        'message' => 'No speech detected in the audio. '
                                    ];
                                    Frontend::printToStream($update);
                                }
                                // ------------------------------------------------------------
                            }
                        } catch (\Exception $e) {
                            $arrMessage['BFILETEXT'] = 'Error processing audio: ' . $e->getMessage();
                        }
                    } else {
                        $arrMessage['BFILETEXT'] = 'Failed to extract audio from video file.';
                    }

                    if ($arrMessage['BID'] > 0) {
                        $updateSQL = "update BMESSAGES set BFILETEXT = '".db::EscString($arrMessage['BFILETEXT'])."' where BID = ".($arrMessage['BID']);
                        db::Query($updateSQL);
                    }

                    // clean up
                    if (file_exists($saveTo)) {
                        unlink($saveTo);
                    }

                    // ------------------------------------------------------------
                    // write to stream
                    if ($streamOutput) {
                        $update = [
                            'msgId' => $arrMessage['BID'],
                            'status' => 'pre_processing',
                            'message' => 'Sound handling done '
                        ];
                        Frontend::printToStream($update);
                    }
                    // ------------------------------------------------------------
                }
            }
        }

        // documents (tika-first, with pdf rasterize→vision fallback via UniversalFileHandler)
        if ($arrMessage['BFILETYPE'] == 'pdf' || $arrMessage['BFILETYPE'] == 'doc' || $arrMessage['BFILETYPE'] == 'docx' || $arrMessage['BFILETYPE'] == 'xls' || $arrMessage['BFILETYPE'] == 'xlsx' || $arrMessage['BFILETYPE'] == 'ppt' || $arrMessage['BFILETYPE'] == 'pptx' || $arrMessage['BFILETYPE'] == 'csv' || $arrMessage['BFILETYPE'] == 'html' || $arrMessage['BFILETYPE'] == 'htm' || $arrMessage['BFILETYPE'] == 'txt' || $arrMessage['BFILETYPE'] == 'md') {
            $fileType = ($arrMessage['BFILETYPE'] == 'pdf') ? 3 : (($arrMessage['BFILETYPE'] == 'doc' || $arrMessage['BFILETYPE'] == 'docx') ? 4 : (($arrMessage['BFILETYPE'] == 'txt') ? 5 : (($arrMessage['BFILETYPE'] == 'html' || $arrMessage['BFILETYPE'] == 'htm') ? 6 : (($arrMessage['BFILETYPE'] == 'md') ? 7 : 0))));
            list($extractedText, $meta) = \UniversalFileHandler::extract($arrMessage['BFILEPATH'], $arrMessage['BFILETYPE']);
            if (!empty($GLOBALS['debug']) && !empty($meta['strategy'])) {
                @error_log('DocExtract strategy=' . $meta['strategy'] . ' type=' . $arrMessage['BFILETYPE'] . ' file=' . basename($arrMessage['BFILEPATH']));
            }
            $safeText = is_string($extractedText) ? $extractedText : '';
            $arrMessage['BFILETEXT'] = $safeText;
            if ($arrMessage['BID'] > 0) {
                $updateSQL = "update BMESSAGES set BFILETEXT = '" . db::EscString($arrMessage['BFILETEXT']) . "' where BID = " . ($arrMessage['BID']);
                db::Query($updateSQL);
            }
            if ($streamOutput) {
                $strategy = is_array($meta ?? null) && isset($meta['strategy']) ? $meta['strategy'] : '';
                $msg = 'text extracted';
                if ($strategy === 'rasterize_vision') {
                    $msg = 'image OCR extracted text';
                } elseif ($arrMessage['BFILETYPE'] == 'pdf') {
                    $msg = 'text extracted from PDF';
                } elseif ($arrMessage['BFILETYPE'] == 'doc' || $arrMessage['BFILETYPE'] == 'docx') {
                    $msg = 'text extracted from DOC';
                } elseif ($arrMessage['BFILETYPE'] == 'txt') {
                    $msg = 'text imported';
                }
                $update = [
                    'msgId' => $arrMessage['BID'],
                    'status' => 'pre_processing',
                    'message' => $msg.' '
                ];
                Frontend::printToStream($update);
            }
        }

        // check, if there was a file text and create the vector entry
        // now reference the vector
        // ********************************************** VECTORIZE **********************************************
        if (strlen($arrMessage['BFILETEXT']) > 0) {
            $myChunks = BasicAI::chunkify($arrMessage['BFILETEXT']);
            foreach ($myChunks as $chunk) {
                $AIVEC = $GLOBALS['AI_VECTORIZE']['SERVICE'];
                $myVector = $AIVEC::embed($chunk['content']);
                // Skip vector insert if embedding failed/empty
                if (!is_array($myVector) || count($myVector) === 0) {
                    if ($streamOutput && !empty($GLOBALS['debug'])) {
                        $update = [
                            'msgId' => $arrMessage['BID'],
                            'status' => 'pre_processing',
                            'message' => 'Embedding unavailable; skipping vectorization '
                        ];
                        Frontend::printToStream($update);
                    }
                    continue;
                }
                $updateSQL = 'insert into BRAG (BID, BUID, BMID, BGROUPKEY, BTYPE, BSTART, BEND, BEMBED) 
                                values (DEFAULT, '.$arrMessage['BUSERID'].', '.$arrMessage['BID'].", 'DEFAULT', ".($fileType).',
								'.intval($chunk['start_line']).', '.intval($chunk['end_line']).", 
								VEC_FromText('[".implode(', ', $myVector)."]'))";

                db::Query($updateSQL);
                // ------------------------------------------------------------
                // write to stream
                if ($streamOutput) {
                    $update = [
                        'msgId' => $arrMessage['BID'],
                        'status' => 'pre_processing',
                        'message' => '. '
                    ];
                    Frontend::printToStream($update);
                }
                // ------------------------------------------------------------
            }
            // ------------------------------------------------------------
            // write to stream
            if ($streamOutput) {
                $update = [
                    'msgId' => $arrMessage['BID'],
                    'status' => 'pre_processing',
                    'message' => 'file vectorized '
                ];
                Frontend::printToStream($update);
            }
            // ------------------------------------------------------------
        }
        // --
        return $arrMessage;
    }

    // get the message thread up to 15 messages back
    public static function getThread($arrMsg, $timeSeconds = 86400): array|string|bool
    {
        $arrThread = [];

        // Handle anonymous widget sessions
        if (isset($_SESSION['is_widget']) && $_SESSION['is_widget'] === true) {
            // For anonymous widget sessions, use BTRACKID to get messages from the same session
            if (isset($_SESSION['anonymous_session_id'])) {
                $trackingHash = $_SESSION['anonymous_session_id'];
                $numericTrackId = crc32($trackingHash);

                $getSQL = 'select * from BMESSAGES where BUSERID = '.$arrMsg['BUSERID'].' and BTRACKID = '.$numericTrackId.' and BUNIXTIMES < '.$arrMsg['BUNIXTIMES'].' and BUNIXTIMES > '.($arrMsg['BUNIXTIMES'] - $timeSeconds).' order by BID desc limit 5';
            } else {
                // Fallback to regular query if no session ID
                $getSQL = 'select * from BMESSAGES where BUSERID = '.$arrMsg['BUSERID'].' and BUNIXTIMES < '.$arrMsg['BUNIXTIMES'].' and BUNIXTIMES > '.($arrMsg['BUNIXTIMES'] - $timeSeconds).' order by BID desc limit 5';
            }
        } else {
            // Regular user sessions
            $getSQL = 'select * from BMESSAGES where BUSERID = '.$arrMsg['BUSERID'].' and BUNIXTIMES < '.$arrMsg['BUNIXTIMES'].' and BUNIXTIMES > '.($arrMsg['BUNIXTIMES'] - $timeSeconds).' order by BID desc limit 5';
        }

        $res = db::Query($getSQL);

        while ($oneMsg = db::FetchArr($res)) {
            if (strlen($oneMsg['BFILETEXT']) > 0) {
                $oneMsg['BFILETEXT'] = substr($oneMsg['BFILETEXT'], 0, 100);
            }
            $arrThread[] = $oneMsg;
        }
        $arrThread = array_reverse($arrThread);
        return $arrThread;
    }

    // get topic filtered thread
    public static function getTopicThread($arrMsg, $timeSeconds = 86400): array|string|bool
    {
        $arrThread = [];
        $getSQL = 'select * from BMESSAGES where BUSERID = '.$arrMsg['BUSERID'].' and BUNIXTIMES < '.$arrMsg['BUNIXTIMES'].' and BUNIXTIMES > '.($arrMsg['BUNIXTIMES'] - $timeSeconds)." and BTOPIC = '".$arrMsg['BTOPIC']."' order by BID desc limit 5";
        $res = db::Query($getSQL);

        while ($oneMsg = db::FetchArr($res)) {
            if (strlen($oneMsg['BFILETEXT']) > 0) {
                $oneMsg['BFILETEXT'] = substr($oneMsg['BFILETEXT'], 0, 100);
            }
            $arrThread[] = $oneMsg;
        }
        $arrThread = array_reverse($arrThread);
        return $arrThread;
    }

    // get message by ID
    public static function getMsgById($msgId): array|string|bool
    {
        $getSQL = 'select * from BMESSAGES where BID = '.$msgId;
        $res = db::Query($getSQL);
        $msgArr = db::FetchArr($res);
        return $msgArr;
    }

    // get user by ID
    public static function getUsrById($usrId): array|string|bool
    {
        $getSQL = 'select * from BUSER where BID = '.$usrId;
        $res = db::Query($getSQL);
        $usrArr = db::FetchArr($res);
        $usrArr['DETAILS'] = json_decode($usrArr['BUSERDETAILS'], true);
        return $usrArr;
    }

    /**
     * Update user details in database
     */
    public static function updateUserDetails($userId, $details)
    {
        $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $detailsEscaped = db::EscString($detailsJson);

        $updateSQL = "UPDATE BUSER SET BUSERDETAILS = '" . $detailsEscaped . "' WHERE BID = " . $userId;
        return db::Query($updateSQL);
    }

    // ******************************************************************************************************
    // RAG FILE PROCESSING - specifically for file manager uploads with custom group keys
    // ******************************************************************************************************
    public static function processRAGFiles($filesArray, $userId, $groupKey = 'DEFAULT', $streamOutput = false): array
    {
        $results = [];
        $processedCount = 0;

        foreach ($filesArray as $fileData) {
            // Create message array for this file
            $arrMessage = [];
            $arrMessage['BUSERID'] = $userId;
            $arrMessage['BID'] = $fileData['BID'] ?? 0;
            $arrMessage['BFILEPATH'] = $fileData['BFILEPATH'];
            $arrMessage['BFILETYPE'] = $fileData['BFILETYPE'];
            $arrMessage['BFILE'] = 1;
            $arrMessage['BTEXT'] = $fileData['BTEXT'] ?? '';
            $arrMessage['BUNIXTIMES'] = time();
            $arrMessage['BDATETIME'] = date('YmdHis');
            $arrMessage['BTOPIC'] = 'rag';
            $arrMessage['BLANG'] = 'en';
            $arrMessage['BTRACKID'] = (int) (microtime(true) * 1000000);
            $arrMessage['BPROVIDX'] = session_id();
            $arrMessage['BMESSTYPE'] = 'RAG';
            $arrMessage['BDIRECT'] = 'IN';
            $arrMessage['BSTATUS'] = 'NEW';
            $arrMessage['BFILETEXT'] = '';

            if ($streamOutput) {
                $update = [
                    'fileName' => basename($arrMessage['BFILEPATH']),
                    'status' => 'processing',
                    'message' => 'Processing file: ' . basename($arrMessage['BFILEPATH'])
                ];
                Frontend::printToStream($update);
            }

            // Process file content based on type
            $fileType = 0;
            $processedMessage = self::extractFileContent($arrMessage, $streamOutput);

            if (strlen($processedMessage['BFILETEXT']) > 0) {
                // Create vector entries with custom group key
                $myChunks = BasicAI::chunkify($processedMessage['BFILETEXT']);
                foreach ($myChunks as $chunk) {
                    $AIVEC = $GLOBALS['AI_VECTORIZE']['SERVICE'];
                    $myVector = $AIVEC::embed($chunk['content']);
                    // Skip vector insert if embedding failed/empty
                    if (!is_array($myVector) || count($myVector) === 0) {
                        if ($streamOutput && !empty($GLOBALS['debug'])) {
                            $update = [
                                'fileName' => basename($arrMessage['BFILEPATH']),
                                'status' => 'processing',
                                'message' => 'Embedding unavailable; skipping vectorization'
                            ];
                            Frontend::printToStream($update);
                        }
                        continue;
                    }

                    // Determine file type for BRAG table
                    $fileType = self::getFileTypeNumber($processedMessage['BFILETYPE']);

                    $updateSQL = 'insert into BRAG (BID, BUID, BMID, BGROUPKEY, BTYPE, BSTART, BEND, BEMBED) 
                                    values (DEFAULT, '.$userId.', '.$processedMessage['BID'].", '".db::EscString($groupKey)."', ".($fileType).',
									'.intval($chunk['start_line']).', '.intval($chunk['end_line']).", 
									VEC_FromText('[".implode(', ', $myVector)."]'))";

                    db::Query($updateSQL);
                }

                if ($streamOutput) {
                    $update = [
                        'fileName' => basename($arrMessage['BFILEPATH']),
                        'status' => 'vectorized',
                        'message' => 'Vectorized with key: ' . $groupKey
                    ];
                    Frontend::printToStream($update);
                }

                $processedCount++;
            }

            $results[] = [
                'file' => basename($arrMessage['BFILEPATH']),
                'processed' => strlen($processedMessage['BFILETEXT']) > 0,
                'groupKey' => $groupKey,
                'messageId' => $processedMessage['BID']
            ];
        }

        return [
            'success' => true,
            'processedCount' => $processedCount,
            'totalFiles' => count($filesArray),
            'groupKey' => $groupKey,
            'results' => $results
        ];
    }

    // Helper method to extract file content without vectorization
    private static function extractFileContent($arrMessage, $streamOutput = false): array
    {
        $fileType = 0;

        // image file - Vision to text
        if ($arrMessage['BFILETYPE'] == 'jpeg' or $arrMessage['BFILETYPE'] == 'jpg' or $arrMessage['BFILETYPE'] == 'png') {
            $fileType = 1;
            $AIV2T = $GLOBALS['AI_PIC2TEXT']['SERVICE'];
            $arrMessage = $AIV2T::explainImage($arrMessage);

            if ($arrMessage['BID'] > 0) {
                $updateSQL = "update BMESSAGES set BFILETEXT = '".db::EscString($arrMessage['BFILETEXT'])."' where BID = ".($arrMessage['BID']);
                db::Query($updateSQL);
            }
        }

        // sound file - Speech to text
        elseif ($arrMessage['BFILETYPE'] == 'mp3') {
            $fileType = 2;
            $AIS2T = $GLOBALS['AI_SOUND2TEXT']['SERVICE'];
            $fullText = $AIS2T::mp3ToText($arrMessage);
            $arrMessage['BFILETEXT'] = trim($fullText);

            if ($arrMessage['BID'] > 0) {
                $updateSQL = "update BMESSAGES set BFILETEXT = '".db::EscString($fullText)."' where BID = ".($arrMessage['BID']);
                db::Query($updateSQL);
            }
        }

        // documents (tika-first, with pdf rasterize→vision fallback via UniversalFileHandler)
        elseif ($arrMessage['BFILETYPE'] == 'pdf' || $arrMessage['BFILETYPE'] == 'doc' || $arrMessage['BFILETYPE'] == 'docx' || $arrMessage['BFILETYPE'] == 'xls' || $arrMessage['BFILETYPE'] == 'xlsx' || $arrMessage['BFILETYPE'] == 'ppt' || $arrMessage['BFILETYPE'] == 'pptx' || $arrMessage['BFILETYPE'] == 'csv' || $arrMessage['BFILETYPE'] == 'html' || $arrMessage['BFILETYPE'] == 'htm' || $arrMessage['BFILETYPE'] == 'txt' || $arrMessage['BFILETYPE'] == 'md') {
            $fileType = ($arrMessage['BFILETYPE'] == 'pdf') ? 3 : (($arrMessage['BFILETYPE'] == 'doc' || $arrMessage['BFILETYPE'] == 'docx') ? 4 : (($arrMessage['BFILETYPE'] == 'txt') ? 5 : (($arrMessage['BFILETYPE'] == 'html' || $arrMessage['BFILETYPE'] == 'htm') ? 6 : (($arrMessage['BFILETYPE'] == 'md') ? 7 : 0))));
            list($extractedText, $meta) = \UniversalFileHandler::extract($arrMessage['BFILEPATH'], $arrMessage['BFILETYPE']);
            if (!empty($GLOBALS['debug']) && !empty($meta['strategy'])) {
                @error_log('DocExtract strategy=' . $meta['strategy'] . ' type=' . $arrMessage['BFILETYPE'] . ' file=' . basename($arrMessage['BFILEPATH']));
            }
            $arrMessage['BFILETEXT'] = is_string($extractedText) ? $extractedText : '';
            if ($arrMessage['BID'] > 0) {
                $updateSQL = "update BMESSAGES set BFILETEXT = '" . db::EscString($arrMessage['BFILETEXT']) . "' where BID = " . ($arrMessage['BID']);
                db::Query($updateSQL);
            }
        }


        return $arrMessage;
    }

    // Helper method to get file type number for BRAG table
    private static function getFileTypeNumber($fileExtension): int
    {
        $typeMap = [
            'jpg' => 1, 'jpeg' => 1, 'png' => 1,
            'mp3' => 2, 'mp4' => 2,
            'pdf' => 3,
            'docx' => 4, 'doc' => 4,
            'txt' => 5,
            'html' => 6, 'htm' => 6,
            'md' => 7
        ];

        return $typeMap[strtolower($fileExtension)] ?? 0;
    }

    /**
     * Security: Convert HTML/HTM files to plain text to prevent them from being served as landing pages
     * Strips all HTML tags and converts to .txt file
     * 
     * @param string $tmpFilePath Temporary uploaded file path
     * @param string $fileExtension Original file extension
     * @return array ['converted' => bool, 'newExtension' => string, 'content' => string]
     */
    public static function sanitizeHtmlUpload(string $tmpFilePath, string $fileExtension): array
    {
        $result = [
            'converted' => false,
            'newExtension' => $fileExtension,
            'content' => null
        ];

        // Only process HTML/HTM files
        if (!in_array(strtolower($fileExtension), ['html', 'htm'])) {
            return $result;
        }

        // Read the HTML content
        $htmlContent = @file_get_contents($tmpFilePath);
        if ($htmlContent === false) {
            return $result;
        }

        // Strip all HTML tags and decode entities
        $plainText = strip_tags($htmlContent);
        $plainText = html_entity_decode($plainText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Clean up excessive whitespace
        $plainText = preg_replace('/\s+/', ' ', $plainText);
        $plainText = preg_replace('/\n\s*\n\s*\n/', "\n\n", $plainText);
        $plainText = trim($plainText);

        $result['converted'] = true;
        $result['newExtension'] = 'txt';
        $result['content'] = $plainText;

        return $result;
    }
}
