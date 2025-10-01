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

// ------------------------------------------------------
//  -----------------------------------------------------
use OpenAI\Client as OpenAIClient;
use OpenAI\Exception\OpenAIException;

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
$GLOBALS['openaiKey'] = file_get_contents(__DIR__ . '/.keys/.openaikey.txt');

$client = OpenAI::client($GLOBALS['openaiKey']);

$arrMessages = [
    ['role' => 'system', 'content' => 'Du bist ein Assistent, der sich erstmal vorstellt und sagt auf welchem LLM basiert.'],
];

$arrMessages[] = ['role' => 'user', 'content' => 'Kannst Du eine Datei erzeugen, zum Beispiel ein Word Dokument mit einem Text darin und hier anhängen?'];

try {
    $chat = $client->chat()->create([
        'model' => 'gpt-4o',
        'messages' => $arrMessages
    ]);
} catch (OpenAIException $err) {
    return '*APItopic Error - Ralf made a bubu - please mail that to him: * ' . $err->getMessage();
}
//return $chat->message->content;
// the prompt asks for a JSON object, so we need to decode it
/* deepseek
$myTextArr = explode("</think>\n", $chat['choices'][0]['message']['content']);
$myTextArr[1] = str_replace("```json", "", $myTextArr[1]);
$myTextArr[1] = str_replace("```", "", $myTextArr[1]);
$arrAnswer = json_decode(trim($myTextArr[1]), true);
return $arrAnswer;
*/
print_r($chat);
