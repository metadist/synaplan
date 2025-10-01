<?php

class AITheHive
{
    private static $key;

    // ******************************************************************************************************
    public static function init()
    {
        self::$key = ApiKeys::getTheHive();
        if (!self::$key) {
            //if($GLOBALS["debug"]) error_log("TheHive API key not configured");
            return false;
        }
        return true;
    }

    // ******************************************************************************************************
    // picture prompt
    // ******************************************************************************************************
    public static function picPrompt($msgArr): array
    {
        $usrArr = Central::getUsrById($msgArr['BUSERID']);

        if (substr($msgArr['BTEXT'], 0, 1) == '/') {
            $picPrompt = substr($msgArr['BTEXT'], strpos($msgArr['BTEXT'], ' '));
        } else {
            $picPrompt = $msgArr['BTEXT'];
        }
        $picPrompt = trim($picPrompt);

        //error_log($picPrompt);

        if (strlen($picPrompt) > 1) {
            $arrRes = Curler::callJson(
                'https://api.thehive.ai/api/v3/stabilityai/sdxl',
                ['authorization: Bearer ' . self::$key, 'Content-Type: application/json'],
                ['input' => [
                    'prompt' => $picPrompt,
                    'image_size' => ['width' => 1024, 'height' => 1024],
                    'num_inference_steps' => 15,
                    'num_images' => 1,
                ]]
            );
        }

        // save file to
        $fileUrl = $arrRes['output'][0]['url'];
        $fileType = $arrRes['input']['output_format'];
        $fileOutput = substr($usrArr['BPROVIDERID'], -5, 3) . '/' . substr($usrArr['BPROVIDERID'], -2, 2) . '/' . date('Ym');
        $filePath = $fileOutput . '/hive_' . $arrRes['id'] . '.' . $fileType;
        // create the directory if it doesn't exist
        if (!is_dir('up/'.$fileOutput)) {
            mkdir('up/'.$fileOutput, 0777, true);
        }

        $msgArr['BFILE'] = 1;
        $msgArr['BFILETEXT'] = json_encode($arrRes['input'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $msgArr['BFILEPATH'] = $filePath;
        $msgArr['BFILETYPE'] = $fileType;

        try {
            copy($fileUrl, 'up/'.$filePath);
        } catch (Exception $e) {
            $msgArr['BFILEPATH'] = '';
            $msgArr['BFILETEXT'] = 'Error: ' . $e->getMessage();
        }
        // return the message array
        return $msgArr;
    }
}

// ******************************************************************************************************
// init
// ******************************************************************************************************
AITheHive::init();
