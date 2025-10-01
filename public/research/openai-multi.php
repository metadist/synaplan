<?php

$root = '../';
require_once($root . 'inc/_coreincludes.php');

$apiKey = ApiKeys::getOpenAI();

$client = OpenAI::client($apiKey);

/* tools array
[
    'type' => 'web_search_preview'
]
*/
$response = $client->responses()->create([
    'model' => 'gpt-4.1',
    'tools' => [],
    'input' => 'Please create a Powerpoint file with a cover and a simple slide with headline! Title: It is fun to be AI, headline: Downfall of the humans. Provide a download file in PPT or PPTX.',
    'temperature' => 0.7,
    'max_output_tokens' => 50000,
    'tool_choice' => 'auto',
    'parallel_tool_calls' => true,
    'store' => true,
    'metadata' => [
        'user_id' => '123',
        'session_id' => 'abc456'
    ]
]);

$response->id; // 'resp_67ccd2bed1ec8190b14f964abc054267'
$response->object; // 'response'
$response->createdAt; // 1741476542
$response->status; // 'completed'
$response->model; // 'gpt-4o-mini'

foreach ($response->output as $output) {
    $output->type; // 'message'
    $output->id; // 'msg_67ccd2bf17f0819081ff3bb2cf6508e6'
    $output->status; // 'completed'
    $output->role; // 'assistant'

    foreach ($output->content as $content) {
        $content->type; // 'output_text'
        $content->text; // The response text
        $content->annotations; // Any annotations in the response
    }
}

$response->usage->inputTokens; // 36
$response->usage->outputTokens; // 87
$response->usage->totalTokens; // 123

//$response->toArray(); // ['id' => 'resp_67ccd2bed1ec8190b14f964abc054267', ...]

print_r($response->toArray());

exit;
/*
$response = $client->audio()->speech([
    'model' => 'tts-1',
    'input' => 'Als  mir gestern das Taschentuch runtergefallen ist, habe ich gedacht, dass ich es nicht mehr bekomme.',
    'voice' => 'nova',
]);

file_put_contents('speech.mp3', $response);
*/
