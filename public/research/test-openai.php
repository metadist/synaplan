<?php

$root = '../';
require_once($root . 'vendor/autoload.php');
require_once($root . 'inc/_confkeys.php');

$apiKey = ApiKeys::getOpenAI();

if (!$apiKey) {
    die("OpenAI API key not found. Please set OPENAI_API_KEY environment variable.\n");
}

echo 'API Key found: ' . substr($apiKey, 0, 10) . "...\n";

$client = OpenAI::client($apiKey);

// Test with a simple chat completion first
try {
    echo "Testing API connection with a simple chat completion...\n";

    $response = $client->chat()->create([
        'model' => 'gpt-3.5-turbo',
        'messages' => [
            ['role' => 'user', 'content' => 'Hello, this is a test.']
        ],
        'max_tokens' => 10
    ]);

    echo '✅ Chat API works! Response: ' . $response->choices[0]->message->content . "\n";
} catch (Exception $e) {
    echo '❌ Chat API failed: ' . $e->getMessage() . "\n";
    exit(1);
}

// Now test the Responses API
try {
    echo "\nTesting Responses API...\n";

    $response = $client->responses()->create([
        'model' => 'gpt-4o-mini',
        'tools' => [
            [
                'type' => 'computer_use_preview',
                'container' => [
                    'type' => 'auto'
                ]
            ]
        ],
        'input' => [
            [
                'role' => 'user',
                'content' => 'Create a simple text file with "Hello World" in it.'
            ]
        ],
        'store' => true
    ]);

    echo '✅ Responses API works! Response ID: ' . $response->id . "\n";
    echo 'Status: ' . $response->status . "\n";
} catch (Exception $e) {
    echo '❌ Responses API failed: ' . $e->getMessage() . "\n";
    exit(1);
}
