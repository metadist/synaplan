<?php

//==================================================================================
/*
 AIprocessor for Ralfs.AI messages
 written by puzzler - Ralf Schwoebel, rs(at)metadist.de

 Tasks of this file:
 . take the message ID handed over and process it
*/
//==================================================================================
set_time_limit(360);

require_once(__DIR__ . '/vendor/autoload.php');

// ------------------------------------------------------ base config
require_once(__DIR__ . '/inc/_confsys.php');
require_once(__DIR__ . '/inc/_confdb.php');
require_once(__DIR__ . '/inc/_mail.php');

// central tool
require_once(__DIR__ . '/inc/_central.php');

// etc.
require_once(__DIR__ . '/inc/_wasender.php');
require_once(__DIR__ . '/inc/_basicai.php');
require_once(__DIR__ . '/inc/_curler.php');
require_once(__DIR__ . '/inc/_tools.php');
require_once(__DIR__ . '/inc/_jsontools.php');

// ------------------------------------------------------
//  -----------------------------------------------------

// ------------------------
// execute the AI handling
// $cmd = "nohup php aiprocessor.php ".$msgArr['BID']." > /dev/null 2>&1 &";
// $pidfile = "pids/m".($msgArr['BID']).".pid";
// exec(sprintf("%s echo $! >> %s", $cmd, $pidfile));
// ------------------------
// Initialize the API
$GLOBALS['WAtoken'] = file_get_contents(__DIR__ . '/.keys/.watoken.txt');
$GLOBALS['theHiveKey'] = file_get_contents(__DIR__ . '/.keys/.thehive.txt');
$GLOBALS['braveKey'] = file_get_contents(__DIR__ . '/.keys/.bravekey.txt');
$GLOBALS['OPENAI'] = file_get_contents(__DIR__ . '/.keys/.openai.txt');

// groq test
use LucianoTonet\GroqPHP\Groq;
use LucianoTonet\GroqPHP\GroqException;

$groqkey = file_get_contents('./.keys/.groqkey.txt');
$client = new Groq($groqkey);

$arrMessages = [
    ['role' => 'system', 'content' => 'You answer questions. You get one JSON object and the user question in the BTEXT field. Replace that text in the BTEXT field with your answer. The other fields are not relevant for you.'],
];

// tell AI the whole thread
//foreach($threadArr as $msg) {
//    $arrMessages[] = ['role' => 'user', 'content' => "[".$msg['BID']."] ".$msg['BTEXT']];
//}
// last message is the JSON block
// last message
$jsonFile = file_get_contents('./test.json');
$msgText = $jsonFile;

$arrMessages[] = ['role' => 'user', 'content' => $msgText];

// print_r($arrMessages);

try {
    $chat = $client->chat()->completions()->create([
        'model' => 'llama-3.3-70b-versatile', //'deepseek-r1-distill-llama-70b',
        'reasoning_format' => 'hidden',
        'messages' => $arrMessages,
        'response_format' => ['type' => 'json_object'],
    ]);
    print_r($chat['choices'][0]['message']);
} catch (GroqException $err) {
    print '*APItopic Error - Ralf made a bubu - please mail that to him: * ' . $err->getMessage();
}
