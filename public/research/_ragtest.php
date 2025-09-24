<?php

//==================================================================================
/*
 Simple RAG example to create a knight story about Dusseldorf
 written by puzzler - Ralf Schwoebel, rs(at)metadist.de
*/
//==================================================================================
require_once(__DIR__ . '/vendor/autoload.php');


// ------------------------------------------------------ base config
require_once(__DIR__ . '/inc/_confsys.php');
require_once(__DIR__ . '/inc/_confdb.php');
require_once(__DIR__ . '/inc/_mail.php');
require_once(__DIR__ . '/inc/_xscontrol.php');

// central tool
require_once(__DIR__ . '/inc/_central.php');
// etc.
require_once(__DIR__ . '/inc/_wasender.php');
require_once(__DIR__ . '/inc/_basicai.php');

require_once(__DIR__ . '/inc/_curler.php');
require_once(__DIR__ . '/inc/_tools.php');

// groq test
use LucianoTonet\GroqPHP\Groq;
use LucianoTonet\GroqPHP\GroqException;

$readIn = 0;
// ------------------------------------------------------------
// read in the text files and add them as system messages
// ------------------------------------------------------------
if ($readIn == 1) {
    $files = glob(__DIR__ . '/_ragtext*.txt');
    foreach ($files as $file) {
        print $file."\n";
        $text = file_get_contents($file);
        // add the file as a system message
        $newSQL = "insert into BMESSAGES (BID, BUSERID, BTRACKID, BMESSTYPE, BFILE, BFILETYPE, BFILEPATH, BDIRECT) 
            values (DEFAULT, 0, 100, 'SYS', 1, 'txt', '".$file."', 'IN')";
        db::Query($newSQL);
        $lastId = db::LastId();
        $chunks = BasicAI::chunkify($text);
        foreach ($chunks as $chunk) {
            print $chunk['content']."\n";
            $myVector = AIOllama::embed($chunk['content']);

            $updateSQL = 'insert into BRAG (BID, BUID, BMID, BTYPE, BSTART, BEND, BEMBED) 
            values (DEFAULT, 0, '.($lastId).', 1,
            '.intval($chunk['start_line']).', '.intval($chunk['end_line']).", 
            VEC_FromText('[".implode(', ', $myVector)."]'))";

            db::Query($updateSQL);
        }
    }
}
// ------------------------------------------------------------
// get the text according to the prompt
/*
SELECT title, url, content,
              VEC_DISTANCE_EUCLIDEAN(embedding, VEC_FromText(%s)) AS distance
       FROM kb_rag.content
       ORDER BY distance ASC
       LIMIT %s;
*/
// ------------------------------------------------------------
$prompt = 'Bitte erstelle eine Ritter-Geschichte über die Region Düsseldorf und Umgebung. Nutze die anhängenden Texte als Quellen. Füge eigene Informationen hinzu. Antworte immer in deutscher Sprache.';

$embedPrompt = AIOllama::embed($prompt);

$distanceSQL = "SELECT BMESSAGES.BFILEPATH, 
                    VEC_DISTANCE_EUCLIDEAN(BRAG.BEMBED, VEC_FromText('[".implode(', ', $embedPrompt)."]')) AS distance
                    from BMESSAGES, BRAG 
                    where BMESSAGES.BID = BRAG.BMID AND BMESSAGES.BUSERID=0
                    ORDER BY distance ASC
                    LIMIT 5";
//print $distanceSQL."\n";

$res = db::Query($distanceSQL);
while ($one = db::FetchArr($res)) {
    print json_encode($one)."\n";
}

// ------------------------------------------------------------
$groqkey = file_get_contents('.keys/.groqkey.txt');
$client = new Groq($groqkey);

// $client = \ArdaGnsrn\Ollama\Ollama::client('http://localhost:11434');
$arrMessages = [
    ['role' => 'system', 'content' => 'Du bist ein Experte für Rittergeschichten. Antworte immer in deutscher Sprache. Es werden Dir Informationen aus Dateien zur Verfügung gestellt.'],
];

$files = glob(__DIR__ . '/_ragtext*.txt');
foreach ($files as $file) {
    print $file."\n";
    $text = file_get_contents($file);
    $arrMessages[] = ['role' => 'user', 'content' => 'In Datei **'.$file.'** steht: '.$text];
}

$arrMessages[] = ['role' => 'user', 'content' => $prompt];


$chat = $client->chat()->completions()->create([
    'model' => 'llama-3.3-70b-versatile', //'deepseek-r1-distill-llama-70b',
    'messages' => $arrMessages
]);

print trim($chat['choices'][0]['message']['content']);
