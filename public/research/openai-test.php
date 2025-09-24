<?php

$root = '../';
require_once($root . 'inc/_coreincludes.php');

$result = AIOpenAI::createOfficeFile("Create a PowerPoint presentation with 2 slides: 1 cover slide and 1 content slide. The content slide should have a title and a list of items. The list of items should be in a table format. The table should have 3 columns: 'Item', 'Description', and 'Price'. The items should be: 'Item 1', 'Item 2', 'Item 3'. The descriptions should be: 'Description 1', 'Description 2', 'Description 3'. The prices should be: '10', '20', '30'. The cover slide should have the title 'Powerpoint Presentation'.", true);
print_r($result);
exit;
/*
$response = $client->audio()->speech([
    'model' => 'tts-1',
    'input' => 'Als  mir gestern das Taschentuch runtergefallen ist, habe ich gedacht, dass ich es nicht mehr bekomme.',
    'voice' => 'nova',
]);

file_put_contents('speech.mp3', $response);
*/
