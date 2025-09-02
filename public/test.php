<?php
set_time_limit(0);

// OpenAI-compatibility test for Synaplan public/api.php
// Usage:
//   SYNAPLAN_API_BASE="https://your.host" SYNAPLAN_API_KEY="<paste-key>" php test.php
// or: php test.php https://your.host <api_key>

function env(string $key, string $default = ''): string {
    $val = getenv($key);
    return ($val === false || $val === null) ? $default : $val;
}

$base = $argv[1] ?? env('SYNAPLAN_API_BASE', 'http://localhost');
$apiKey = $argv[2] ?? env('SYNAPLAN_API_KEY', '');

if ($apiKey === '') {
    fwrite(STDERR, "Missing API key. Set SYNAPLAN_API_KEY or pass as 2nd arg.\n");
    exit(1);
}

function http_get(string $url, array $headers): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $body];
}

function http_post_json(string $url, array $headers, array $payload): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $headers[] = 'Content-Type: application/json';
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $body];
}

function print_section(string $title): void {
    echo "\n=== $title ===\n";
}

$authHeaders = [
    'Authorization: Bearer ' . $apiKey,
    'Accept: application/json'
];

// 1) Models list
print_section('GET /v1/models');
[$st, $body] = http_get(rtrim($base, '/') . '/v1/models', $authHeaders);
echo "HTTP $st\n";
echo $body . "\n";

// 2) Chat completions across several models
$chatModels = [
    'gpt-5',
    'deepseek-r1-distill-llama-70b',
    'openai/gpt-5',
    'ollama/llama3.3:70b',
    'google/gemini-2.5-pro-preview-06-05',
    'anthropic/claude-opus-4-20250514'
];

foreach ($chatModels as $model) {
    print_section('POST /v1/chat/completions model=' . $model);
    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'user', 'content' => 'Say a short hello and include the provider name if you know it.']
        ]
    ];
    [$st, $body] = http_post_json(rtrim($base, '/') . '/v1/chat/completions', $authHeaders, $payload);
    echo "HTTP $st\n";
    echo $body . "\n";
}

// 3) Image generation on a supported model
$imageModels = [
    'openai/gpt-image-1',
    'openai/dall-e-3'
];

foreach ($imageModels as $model) {
    print_section('POST /v1/images/generations model=' . $model);
    $payload = [
        'model' => $model,
        'prompt' => 'A colorful parrot wearing sunglasses'
    ];
    [$st, $body] = http_post_json(rtrim($base, '/') . '/v1/images/generations', $authHeaders, $payload);
    echo "HTTP $st\n";
    $decoded = json_decode($body, true);
    if (isset($decoded['data'][0]['b64_json'])) {
        $b64 = $decoded['data'][0]['b64_json'];
        echo substr($b64, 0, 200) . "...\n";
    } else {
        echo $body . "\n";
    }
}

echo "\nDone.\n";

