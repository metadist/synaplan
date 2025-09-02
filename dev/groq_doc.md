# Groq PHP

![Groq PHP](https://raw.githubusercontent.com/lucianotonet/groq-php/main/art.png)

[![Version](https://img.shields.io/github/v/release/lucianotonet/groq-php)](https://packagist.org/packages/lucianotonet/groq-php) [![Total Downloads](https://img.shields.io/packagist/dt/lucianotonet/groq-php)](https://packagist.org/packages/lucianotonet/groq-php) [![Tests](https://github.com/lucianotonet/groq-php/actions/workflows/tests.yml/badge.svg)](https://github.com/lucianotonet/groq-php/actions/workflows/tests.yml) [![License](https://img.shields.io/packagist/l/lucianotonet/groq-php)](https://packagist.org/packages/lucianotonet/groq-php)

**High-performance PHP client for GroqCloud API**

A comprehensive PHP SDK that simplifies interaction with **the world's fastest LLM inference platform**, allowing PHP developers to easily integrate high-performance models (DeepSeek r1, Llama 3.3, Mixtral, Gemma, and more) into any PHP application.

Using on Laravel? Check this out: [GroqLaravel](https://github.com/lucianotonet/groq-laravel?tab=readme-ov-file#readme)

## Features

- [x] [Chat Completions](#2-chat-completions)
- [x] [Tool Calling](#3-tool-calling)
- [x] [Audio Transcription and Translation](#4-audio-transcription-translation-and-text-to-speech)
- [x] [Text-to-Speech](#4-audio-transcription-translation-and-text-to-speech)
- [x] [Vision](#5-vision)
- [x] [Reasoning](#6-reasoning)
- [x] [Files and Batch Processing](#7-files-and-batch-processing)

## Installation

```bash
composer require lucianotonet/groq-php
```

## Configuration

1. **Get your API Key:**
   - Go to [GroqCloud Console](https://console.groq.com/keys)
   - Create a new API key

2. **Configure your API Key:**
   - Using environment variables:

    ```bash
    export GROQ_API_KEY=your_key_here
    ```

    - Or using a `.env` file:

    ```bash
    GROQ_API_KEY=your_key_here
    GROQ_API_BASE=https://api.groq.com/openai/v1  # (Optional, if different from default)
    ```

## Usage

### 1. Listing Models

List available models.

```php
$models = $groq->models()->list();
print_r($models['data']);
```

### 2. Chat (Completions)

Generate interactive chat responses.

```php
<?php

use LucianoTonet\GroqPHP\Groq;

$groq = new Groq(getenv('GROQ_API_KEY'));

try {
    $response = $groq->chat()->completions()->create([
        'model' => 'llama3-8b-8192', // Or another supported model
        'messages' => [
            ['role' => 'user', 'content' => 'Explain the importance of low latency in LLMs'],
        ],
    ]);

    echo $response['choices'][0]['message']['content'];
} catch (\LucianoTonet\GroqPHP\GroqException $e) {
    echo 'Error: ' . $e->getMessage();
}
```

**Streaming:**

```php
$response = $groq->chat()->completions()->create([
    'model' => 'llama3-8b-8192',
    'messages' => [
        ['role' => 'user', 'content' => 'Tell me a short story'],
    ],
    'stream' => true
]);

foreach ($response->chunks() as $chunk) {
    if (isset($chunk['choices'][0]['delta']['content'])) {
        echo $chunk['choices'][0]['delta']['content'];
        ob_flush(); // Important for real streaming
        flush();
    }
}
```

**JSON Mode:**

```php
$response = $groq->chat()->completions()->create([
    'model' => 'llama3-70b-8192',
    'messages' => [
        ['role' => 'system', 'content' => 'You are an API and must respond only with valid JSON.'],
        ['role' => 'user', 'content' => 'Give me information about the current weather in London'],
    ],
    'response_format' => ['type' => 'json_object']
]);

$content = $response['choices'][0]['message']['content'];
echo json_encode(json_decode($content), JSON_PRETTY_PRINT); // Display formatted JSON
```

**Additional Parameters (Chat Completions):**

- `temperature`: Controls randomness (0.0 - 2.0)
- `max_completion_tokens`: Maximum tokens in response
- `top_p`: Nucleus sampling
- `frequency_penalty`: Penalty for repeated tokens (-2.0 - 2.0)
- `presence_penalty`: Penalty for repeated topics (-2.0 - 2.0)
- `stop`: Stop sequences
- `seed`: For reproducibility

### 3. Tool Calling

Allows the model to call external functions/tools.

```php

// Example function (simulated)
function getNbaScore($teamName) {
    // ... (simulated logic to return score) ...
    return json_encode(['team' => $teamName, 'score' => 100]); // Example
}

$messages = [
    ['role' => 'system', 'content' => "You must call the 'getNbaScore' function to answer questions about NBA game scores."],
    ['role' => 'user', 'content' => 'What is the Lakers score?']
];

$tools = [
    [
        'type' => 'function',
        'function' => [
            'name' => 'getNbaScore',
            'description' => 'Get the score for an NBA game',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'team_name' => ['type' => 'string', 'description' => 'NBA team name'],
                ],
                'required' => ['team_name'],
            ],
        ],
    ]
];

$response = $groq->chat()->completions()->create([
    'model' => 'llama3-groq-70b-8192-tool-use-preview', // Model that supports tool calling
    'messages' => $messages,
    'tool_choice' => 'auto',
    'tools' => $tools
]);

if (isset($response['choices'][0]['message']['tool_calls'])) {
    // ... (process tool call, call function, and send response) ...
    $tool_call          = $response['choices'][0]['message']['tool_calls'][0];
    $function_args      = json_decode($tool_call['function']['arguments'], true);
    $function_response  = getNbaScore($function_args['team_name']);
            
    $messages[] = [
        'tool_call_id'  => $tool_call['id'],
        'role'          => 'tool',
        'name'          => 'getNbaScore',
        'content'       => $function_response,
    ];

    // Second call to the model with tool response:
    $response = $groq->chat()->completions()->create([
        'model' => 'llama3-groq-70b-8192-tool-use-preview',
        'messages' => $messages
    ]);
    echo $response['choices'][0]['message']['content'];
} else {
    // Direct response, no tool_calls
    echo $response['choices'][0]['message']['content'];
}
```

**Advanced Tool Calling (with multiple tools and parallel calls):**

See `examples/tool-calling-advanced.php` for a more complete example, including:

- Definition of multiple tools (e.g., `getCurrentDateTimeTool`, `getCurrentWeatherTool`)
- `parallel_tool_calls`: Controls whether tool calls can be made in parallel (currently must be forced `false` in code)

### 4. Audio (Transcription, Translation and Text-to-Speech)

#### Transcription and Translation

```php
use LucianoTonet\GroqPHP\Groq;

$groq = new Groq(getenv('GROQ_API_KEY'));

try {
    $transcription = $groq->audio()->transcriptions()->create([
        'file' => 'audio.mp3', /* Your audio file */
        'model' => 'whisper-large-v3',
        'response_format' => 'verbose_json', /* Or 'text', 'json' */
        'language' => 'en', /* ISO 639-1 code (optional but recommended) */
        'prompt' => 'Audio transcription...' /* (optional) */
    ]);

    echo json_encode($transcription, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (\LucianoTonet\GroqPHP\GroqException $e) {
    echo "Error: " . $e->getMessage();
}

// (Similar to transcription, but uses ->translations()->create() and always translates to English)

// Target language for translation is always English
$translation = $groq->audio()->translations()->create([
    'file' => 'audio_in_spanish.mp3',
    'model' => 'whisper-large-v3'
]);
```

- **Response formats:** `'json'`, `'verbose_json'`, `'text'`. The `vtt` and `srt` formats are *not* supported.
- **`language`:** ISO 639-1 code of the *source language* (optional but recommended for better accuracy). See `examples/audio-transcriptions.php` for a complete list of supported languages.
- `temperature`: Controls variability.

#### Text-to-Speech (TTS)

Convert text to speech using GroqCloud's Text-to-Speech API.

```php
use LucianoTonet\GroqPHP\Groq;

$groq = new Groq(getenv('GROQ_API_KEY'));

try {
    // Method 1: Save to file
    $result = $groq->audio()->speech()
        ->model('playai-tts')  // 'playai-tts' for English, 'playai-tts-arabic' for Arabic
        ->input('Hello, this text will be converted to speech')
        ->voice('Bryan-PlayAI')  // Voice identifier
        ->responseFormat('wav')  // Output format
        ->save('output.wav');
    
    if ($result) {
        echo "Audio file saved successfully!";
    }
    
    // Method 2: Get as stream
    $audioStream = $groq->audio()->speech()
        ->model('playai-tts')
        ->input('This is another example text')
        ->voice('Bryan-PlayAI')
        ->create();
    
    // Use the stream (e.g., send to browser)
    header('Content-Type: audio/wav');
    header('Content-Disposition: inline; filename="speech.wav"');
    echo $audioStream;
    
} catch (\LucianoTonet\GroqPHP\GroqException $e) {
    echo "Error: " . $e->getMessage();
}
```

- **Models:** `'playai-tts'` (English), `'playai-tts-arabic'` (Arabic)
- **Parameters:**
  - `model()`: The TTS model to use
  - `input()`: Text to convert to speech
  - `voice()`: Voice identifier (e.g., "Bryan-PlayAI")
  - `responseFormat()`: Output format (default: "wav")
- **Methods:**
  - `create()`: Returns audio content as stream
  - `save($filePath)`: Saves audio to a file and returns success boolean

### 5. Vision

Analyze images using Groq's vision models.

```php
use LucianoTonet\GroqPHP\Groq;

$groq = new Groq(getenv('GROQ_API_KEY'));

try {
    // Analyze a local image
    $response = $groq->vision()->analyze('path/to/image.jpg', 'What do you see in this image?');
    
    // Analyze an image from URL
    $response = $groq->vision()->analyze('https://example.com/image.jpg', 'Describe this image');
    
    // Custom options
    $response = $groq->vision()->analyze('path/to/image.jpg', 'What colors do you see?', [
        'temperature' => 0.7,
        'max_completion_tokens' => 100
    ]);
} catch (\LucianoTonet\GroqPHP\GroqException $e) {
    echo 'Error: ' . $e->getMessage();
}
```

**Vision Model:**
The vision functionality uses the `meta-llama/llama-4-scout-17b-16e-instruct` model by default, which supports:
- Local image analysis (up to 4MB)
- URL image analysis (up to 20MB)
- Multi-turn conversations
- Tool use
- JSON mode

### 6. Reasoning

Enables step-by-step reasoning tasks.

```php
use LucianoTonet\GroqPHP\Groq;

$groq = new Groq(getenv('GROQ_API_KEY'));

try {
    $response = $groq->reasoning()->analyze(
        'Explain the process of photosynthesis.',
        [
            'model' => 'deepseek-r1-distill-llama-70b',
            'reasoning_format' => 'raw', // 'raw' (default), 'parsed', 'hidden'
            'temperature' => 0.6,
            'max_completion_tokens' => 10240
        ]
    );

    echo $response['choices'][0]['message']['content'];
} catch (\LucianoTonet\GroqPHP\GroqException $e) {
    echo "Error: " . $e->getMessage();
}
```

- **`analyze()`:** Takes the prompt (question/problem) and an options array.
- **`reasoning_format`:**
  - `'raw'`: Includes reasoning with `<think>` tags in content (default)
  - `'parsed'`: Returns reasoning in a separate `reasoning` field
  - `'hidden'`: Returns only the final answer
- **`system_prompt`:** Additional instructions for the model (optional). Added as a `system` message *before* the user message.
- Must use `'parsed'` or `'hidden'` format when using JSON mode
- Optional parameters: `temperature`, `max_completion_tokens`, `top_p`, `frequency_penalty`, etc.

#### Reasoning Formats

The reasoning feature supports three output formats:

1. **Raw Format (Default)**
   - Includes reasoning steps within `<think>` tags in the content
   - Best for debugging and understanding the model's thought process

   ```php
   $response = $groq->reasoning()->analyze(
       "Explain quantum entanglement.",
       [
           'model' => 'deepseek-r1-distill-llama-70b',
           'reasoning_format' => 'raw'
       ]
   );
   // Response includes: <think>First, let's understand...</think>
   ```

2. **Parsed Format**
   - Separates reasoning into a dedicated field
   - Ideal for applications that need to process reasoning steps separately

   ```php
   $response = $groq->reasoning()->analyze(
       "Solve this math problem: 3x + 7 = 22",
       [
           'model' => 'deepseek-r1-distill-llama-70b',
           'reasoning_format' => 'parsed'
       ]
   );
   // Response structure:
   // {
   //     "reasoning": "Step 1: Subtract 7 from both sides...",
   //     "content": "x = 5"
   // }
   ```

3. **Hidden Format**
   - Returns only the final answer without showing reasoning steps
   - Best for production applications where only the result matters

   ```php
   $response = $groq->reasoning()->analyze(
       "What is the capital of France?",
       [
           'model' => 'deepseek-r1-distill-llama-70b',
           'reasoning_format' => 'hidden'
       ]
   );
   // Response includes only: "The capital of France is Paris."
   ```

### 7. Files and Batch Processing

Process large volumes of data asynchronously using Groq's Files and Batch Processing API.

#### File Management

```php
use LucianoTonet\GroqPHP\Groq;

$groq = new Groq(getenv('GROQ_API_KEY'));
$fileManager = $groq->files();

// Upload a file
$file = $fileManager->upload('path/to/your/file.jsonl', 'batch');

// List files
$files = $fileManager->list('batch', [
    'limit' => 10,
    'order' => 'desc'
]);

// Retrieve file info
$file = $fileManager->retrieve('file_id');

// Download file content
$content = $fileManager->download('file_id');

// Delete file
$fileManager->delete('file_id');
```

#### Batch Processing

```php
$batchManager = $groq->batches();

// Create a batch
$batch = $batchManager->create([
    'input_file_id' => 'file_id',
    'endpoint' => '/v1/chat/completions',
    'completion_window' => '24h',
    'metadata' => [
        'description' => 'Processing customer queries'
    ]
]);

// List batches
$batches = $batchManager->list([
    'limit' => 10,
    'order' => 'desc',
    'status' => 'completed'
]);

// Get batch status
$batch = $batchManager->retrieve('batch_id');
$summary = $batch->getSummary();

// Cancel batch
$batch = $batchManager->cancel('batch_id');
```

**File Requirements:**
- Format: JSONL (JSON Lines)
- Size: Up to 100MB
- Content: Each line must be a valid JSON object with required fields:
    - `custom_id`: Your unique identifier for tracking the batch request
    - `method`: The HTTP method (currently POST only)
    - `url`: The API endpoint to call (one of: /v1/chat/completions, /v1/audio/transcriptions, or /v1/audio/translations)
    - `body`: The parameters of your request matching to [any synchronous API format](#2-chat-completions) like `messages` for chat, `url` for audio, etc.

**Example JSONL file:**
```jsonl
{"custom_id": "chat-request-1", "method": "POST", "url": "/v1/chat/completions", "body": {"model": "llama-3.1-8b-instant", "messages": [{"role": "system", "content": "You are a helpful assistant."}, {"role": "user", "content": "What is quantum computing?"}]}}
{"custom_id": "audio-request-1", "method": "POST", "url": "/v1/audio/transcriptions", "body": {"model": "whisper-large-v3", "language": "en", "url": "https://github.com/voxserv/audio_quality_testing_samples/raw/refs/heads/master/testaudio/8000/test01_20s.wav", "response_format": "verbose_json", "timestamp_granularities": ["segment"]}}
{"custom_id": "chat-request-2", "method": "POST", "url": "/v1/chat/completions", "body": {"model": "llama-3.3-70b-versatile", "messages": [{"role": "system", "content": "You are a helpful assistant."}, {"role": "user", "content": "Explain machine learning in simple terms."}]}}
{"custom_id":"audio-request-2","method":"POST","url":"/v1/audio/translations","body":{"model":"whisper-large-v3","language":"en","url":"https://console.groq.com/audio/batch/sample-zh.wav","response_format":"verbose_json","timestamp_granularities":["segment"]}}
```

**Supported Features:**
- File management with upload, file type and content validations
- Batch creation and management
- Progress tracking
- Error handling
- Metadata support
- Caching for downloaded files

**Completion Windows:**
- Available options: 24h, 48h, 72h, 96h, 120h, 144h, 168h, 7d
- Default: 24h

**Batch Statuses:**
- validating
- in_progress
- completed
- failed
- expired
- cancelled
- cancelling
- finalizing

### 8. Error Handling

The library throws `GroqException` for API errors. The exception contains:

- `getMessage()`: Descriptive error message
- `getCode()`: HTTP status code (or 0 for invalid API key)
- `getType()`: Error type (see `GroqException::ERROR_TYPES` for possible types)
- `getHeaders()`: HTTP response headers
- `getResponseBody()`: Response body (as object if JSON)
- `getError()`: Returns array with error details (message, type, code)
- `getFailedGeneration()`: If error type is `failed_generation`, returns the invalid JSON that caused the issue

```php
try {
    // ... API call ...
} catch (\LucianoTonet\GroqPHP\GroqException $e) {
    echo "Groq Error: " . $e->getMessage() . "\n";
    echo "Type: " . $e->getType() . "\n";
    echo "Code: " . $e->getCode() . "\n";
    if ($e->getFailedGeneration()) {
        echo "Invalid JSON: " . $e->getFailedGeneration();
    }
}
```

The `GroqException` class provides static methods for creating specific exceptions like `invalidRequest()`, `authenticationError()`, etc., following a factory pattern.

## Examples

The `examples/` folder contains complete, working PHP scripts demonstrating each library feature. You can run them directly to see the library in action and interact with on your browser.

First, you need to copy your `.env` file from the root of the project to the examples folder.

```bash
cp .env examples/.env
```

Then, in the examples folder, you need to install the dependencies with:

```bash
cd examples
composer install
```

Now, you can start the server with:

```bash
php -S 127.0.0.1:8000
```

Finally, you can access the examples in your browser at `http://127.0.0.1:8000`.

## Tests

The `tests/` folder contains unit tests. Run them with `composer test`. Tests require the `GROQ_API_KEY` environment variable to be set.

> **Note:** Tests make real API calls to Groq and consume API credits. For this reason, our CI pipeline runs tests only on PHP 8.2. If you need to test with different PHP versions, please do so locally and be mindful of API usage.

## Requirements

[![PHP version](https://img.shields.io/packagist/dependency-v/lucianotonet/groq-php/php)](https://packagist.org/packages/lucianotonet/groq-php)

- PHP >= 8.1
- `fileinfo` extension
- `guzzlehttp/guzzle`

## Contributing

Contributions are welcome! If you find a bug, have a suggestion, or want to add functionality, please open an issue or submit a pull request.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full changelog.

## About Semantic Versioning

This package follows [SemVer](https://semver.org/spec/v2.0.0.html) conventions. However, breaking changes may be released in minor versions in the following cases:

1. Changes that only affect static types and not runtime behavior.
2. Modifications to internal library components that are technically public but not intended for external use. *(Please open a GitHub issue if you depend on these internals)*.
3. Changes that should not affect most users in practical scenarios.

## License

[MIT](LICENSE)