<?php

/**
 * AIGoogle Class
 *
 * Handles interactions with the Google Gemini AI service for various AI processing tasks
 * including text generation, image analysis, video generation, and audio processing.
 *
 * @package AIGoogle
 */
class AIGoogle
{
    /** @var string Google Gemini API key */
    private static $key;

    /**
     * Initialize the Google Gemini client
     *
     * Loads the API key from the centralized configuration and prepares the client
     * for making requests to Google's Gemini API endpoints.
     *
     * @return bool True on successful initialization
     */
    public static function init()
    {
        self::$key = ApiKeys::getGoogleGemini();
        if (!self::$key) {
            //if($GLOBALS["debug"]) error_log("Google Gemini API key not configured");
            return false;
        }
        return true;
    }

    // ******************************************************************************************************
    // Message sorting prompt handler
    // ******************************************************************************************************
    /**
     * Message sorting prompt handler
     *
     * Analyzes and categorizes incoming messages to determine their intent and
     * appropriate handling method using Google Gemini. This helps in routing messages
     * to the correct processing pipeline by understanding user intentions.
     *
     * @param array $msgArr Current message array containing message data and metadata
     * @param array $threadArr Conversation thread history for context
     * @return array|string|bool Sorting result as JSON string or error message
     */
    public static function sortingPrompt($msgArr, $threadArr): array|string|bool
    {
        // Get prompts from BasicAI
        $systemPrompt = BasicAI::getAprompt('tools:sort');

        // Get the model from configuration
        $myModel = $GLOBALS['AI_SORT']['MODEL'];

        // Prepare the API URL
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $myModel . ':generateContent?key=' . self::$key;
        $headers = [
            'Content-Type: application/json'
        ];

        // Build contents array for conversation history
        $contents = [];

        // Build message history
        foreach ($threadArr as $msg) {
            if ($msg['BDIRECT'] == 'IN') {
                $msg['BTEXT'] = Tools::cleanTextBlock($msg['BTEXT']);
                $msgText = $msg['BTEXT'];
                if (strlen($msg['BFILETEXT']) > 1) {
                    $msgText .= ' User provided a file: '.$msg['BFILETYPE'].", saying: '".$msg['BFILETEXT']."'\n\n";
                }
                $contents[] = [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $msgText]
                    ]
                ];
            }
            if ($msg['BDIRECT'] == 'OUT') {
                if (strlen($msg['BTEXT']) > 1000) {
                    // Truncate at word boundary to avoid breaking JSON or quotes
                    $truncatedText = substr($msg['BTEXT'], 0, 1000);
                    // Find the last complete word
                    $lastSpace = strrpos($truncatedText, ' ');
                    if ($lastSpace !== false && $lastSpace > 800) {
                        $truncatedText = substr($truncatedText, 0, $lastSpace);
                    }
                    // Clean up any trailing quotes or incomplete JSON
                    $truncatedText = rtrim($truncatedText, '"\'{}[]');
                    $msg['BTEXT'] = $truncatedText . '...';
                }
                $contents[] = [
                    'role' => 'model',
                    'parts' => [
                        ['text' => '['.$msg['BID'].'] '.$msg['BTEXT']]
                    ]
                ];
            }
        }

        // Add current message
        $msgText = json_encode($msgArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $contents[] = [
            'role' => 'user',
            'parts' => [
                ['text' => $msgText]
            ]
        ];

        // Prepare request data
        $postData = [
            'system_instruction' => [
                'parts' => [
                    ['text' => $systemPrompt['BPROMPT']]
                ]
            ],
            'contents' => $contents
        ];

        try {
            $arrRes = Curler::callJson($url, $headers, $postData);

            // Extract response text
            if (isset($arrRes['candidates'][0]['content']['parts'][0]['text'])) {
                $answer = $arrRes['candidates'][0]['content']['parts'][0]['text'];

                // Clean JSON response
                $answer = str_replace("```json\n", '', $answer);
                $answer = str_replace("\n```", '', $answer);
                $answer = str_replace('```json', '', $answer);
                $answer = str_replace('```', '', $answer);
                $answer = trim($answer);

                return $answer;
            } else {
                return '*API sorting Error - Google AI response format error*';
            }
        } catch (Exception $err) {
            return '*API sorting Error - Ralf made a bubu - please mail that to him: * ' . $err->getMessage();
        }
    }

    // ******************************************************************************************************
    // Topic-specific response generator
    // ******************************************************************************************************
    /**
     * Topic-specific response generator
     *
     * Generates responses based on the specific topic of the message using Google Gemini.
     * Uses topic-specific prompts to create more focused and relevant responses.
     * Handles both JSON and text responses depending on the prompt requirements.
     *
     * @param array $msgArr Message array containing topic information and metadata
     * @param array $threadArr Thread context for conversation history
     * @return array|string|bool Topic-specific response as array or text, or error message
     */
    public static function topicPrompt($msgArr, $threadArr, $stream = false): array|string|bool
    {
        $systemPrompt = BasicAI::getAprompt($msgArr['BTOPIC'], $msgArr['BLANG'], $msgArr, true);

        if (isset($systemPrompt['TOOLS'])) {
            // call tools before the prompt is executed!
        }

        // Determine the model to use (respect preselected globals)
        $myModel = $GLOBALS['AI_CHAT']['MODEL'];

        // Prepare the API URL
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $myModel . ':generateContent?key=' . self::$key;
        $headers = [
            'Content-Type: application/json'
        ];

        // Build contents array for conversation history
        $contents = [];

        // Build message history
        foreach ($threadArr as $msg) {
            $contents[] = [
                'role' => 'user',
                'parts' => [
                    ['text' => '['.$msg['BID'].'] '.$msg['BTEXT']]
                ]
            ];
        }

        // Add current message
        $msgText = json_encode($msgArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $contents[] = [
            'role' => 'user',
            'parts' => [
                ['text' => $msgText]
            ]
        ];

        // Prepare request data
        $postData = [
            'system_instruction' => [
                'parts' => [
                    ['text' => $systemPrompt['BPROMPT']]
                ]
            ],
            'contents' => $contents
        ];

        try {
            if ($stream) {
                // Streaming via SSE endpoint
                $streamUrl = 'https://generativelanguage.googleapis.com/v1beta/models/' . $myModel . ':streamGenerateContent?alt=sse&key=' . self::$key;
                $streamHeaders = $headers;
                $streamHeaders[] = 'Accept: text/event-stream';

                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $streamUrl,
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => $streamHeaders,
                    CURLOPT_POSTFIELDS => json_encode($postData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_USERAGENT => 'Synaplan-Gemini/1.0',
                ]);

                $answer = '';

                curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$answer, $msgArr) {
                    $lines = explode("\n", $data);
                    foreach ($lines as $line) {
                        if (strpos($line, 'data: ') === 0) {
                            $jsonData = substr($line, 6);
                            if ($jsonData === '' || $jsonData === '[DONE]') {
                                continue;
                            }
                            $event = json_decode($jsonData, true);
                            if (is_array($event)) {
                                // Extract text chunks from Gemini SSE payloads
                                $textChunk = '';
                                if (isset($event['candidates'][0]['content']['parts'])) {
                                    foreach ($event['candidates'][0]['content']['parts'] as $part) {
                                        if (isset($part['text']) && is_string($part['text']) && $part['text'] !== '') {
                                            $textChunk .= $part['text'];
                                        }
                                    }
                                } elseif (isset($event['candidates'][0]['content']['parts'][0]['text'])) {
                                    $textChunk = (string)$event['candidates'][0]['content']['parts'][0]['text'];
                                }
                                if ($textChunk !== '') {
                                    $answer .= $textChunk;
                                    Frontend::statusToStream($msgArr['BID'], 'ai', $textChunk);
                                }
                            }
                        }
                    }
                    return strlen($data);
                });

                curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErr = curl_error($ch);
                curl_close($ch);

                if (!empty($curlErr)) {
                    return '*APItopic Error - Google streaming failed: * ' . $curlErr;
                }
                if ($httpCode !== 200 && $httpCode !== 0) {
                    return '*APItopic Error - Google streaming HTTP ' . $httpCode . '*';
                }

                // Build final answer array (avoid double output)
                $arrAnswer = $msgArr;
                $arrAnswer['BTEXT'] = $answer;
                $arrAnswer['BDIRECT'] = 'OUT';
                $arrAnswer['BDATETIME'] = date('Y-m-d H:i:s');
                $arrAnswer['BUNIXTIMES'] = time();
                $arrAnswer['BFILE'] = 0;
                $arrAnswer['BFILEPATH'] = '';
                $arrAnswer['BFILETYPE'] = '';
                $arrAnswer['BFILETEXT'] = '';
                $arrAnswer['_USED_MODEL'] = $myModel;
                $arrAnswer['_AI_SERVICE'] = 'AIGoogle';
                $arrAnswer['ALREADYSHOWN'] = true;
                return $arrAnswer;
            } else {
                $arrRes = Curler::callJson($url, $headers, $postData);

                // Extract response text
                if (isset($arrRes['candidates'][0]['content']['parts'][0]['text'])) {
                    $answer = $arrRes['candidates'][0]['content']['parts'][0]['text'];

                    // Clean JSON response - only if it starts with JSON markers
                    if (strpos($answer, "```json\n") === 0) {
                        $answer = substr($answer, 8); // Remove "```json\n" from start
                        if (strpos($answer, "\n```") !== false) {
                            $answer = str_replace("\n```", '', $answer);
                        }
                    } elseif (strpos($answer, '```json') === 0) {
                        $answer = substr($answer, 7); // Remove "```json" from start
                        if (strpos($answer, '```') !== false) {
                            $answer = str_replace('```', '', $answer);
                        }
                    }
                    $answer = trim($answer);

                    if (Tools::isValidJson($answer) == false) {
                        // If not valid JSON, return as text response
                        $arrAnswer = $msgArr;
                        $arrAnswer['BTEXT'] = $answer;
                        $arrAnswer['BDIRECT'] = 'OUT';
                    } else {
                        try {
                            $arrAnswer = json_decode($answer, true);

                            // If json_decode returns a string instead of array, wrap it
                            if (is_string($arrAnswer)) {
                                $arrAnswer = [
                                    'BTEXT' => $arrAnswer,
                                    'BDIRECT' => 'OUT'
                                ];
                                // Preserve essential fields from original message
                                if (isset($msgArr['BID'])) {
                                    $arrAnswer['BID'] = $msgArr['BID'];
                                }
                                if (isset($msgArr['BUSERID'])) {
                                    $arrAnswer['BUSERID'] = $msgArr['BUSERID'];
                                }
                                if (isset($msgArr['BTOPIC'])) {
                                    $arrAnswer['BTOPIC'] = $msgArr['BTOPIC'];
                                }
                            }

                            // Ensure $arrAnswer is always an array
                            if (!is_array($arrAnswer)) {
                                $arrAnswer = [
                                    'BTEXT' => is_string($arrAnswer) ? $arrAnswer : 'Invalid response format',
                                    'BDIRECT' => 'OUT'
                                ];
                                // Preserve essential fields from original message
                                if (isset($msgArr['BID'])) {
                                    $arrAnswer['BID'] = $msgArr['BID'];
                                }
                                if (isset($msgArr['BUSERID'])) {
                                    $arrAnswer['BUSERID'] = $msgArr['BUSERID'];
                                }
                                if (isset($msgArr['BTOPIC'])) {
                                    $arrAnswer['BTOPIC'] = $msgArr['BTOPIC'];
                                }
                            }
                        } catch (Exception $err) {
                            return '*APItopic Error - Ralf made a bubu - please mail that to him: * ' . $err->getMessage();
                        }
                    }

                    // Add model information to the response (now safe since $arrAnswer is guaranteed to be an array)
                    $arrAnswer['_USED_MODEL'] = $myModel;
                    $arrAnswer['_AI_SERVICE'] = 'AIGoogle';

                    return $arrAnswer;
                } else {
                    return '*API topic Error - Google AI response format error*';
                }
            }
        } catch (Exception $err) {
            return '*APItopic Error - Ralf made a bubu - please mail that to him: * ' . $err->getMessage();
        }
    }

    // ******************************************************************************************************
    // Welcome message generator
    // ******************************************************************************************************
    /**
     * Welcome message generator
     *
     * Creates a personalized welcome message for new users using Google Gemini.
     * Includes information about available commands and features. Currently not
     * implemented but prepared for future welcome message functionality.
     *
     * @param array $msgArr Message array containing user information and language preferences
     * @return array|string|bool Welcome message or placeholder message
     */
    public static function welcomePrompt($msgArr): array|string|bool
    {
        // TODO: Implement welcome message generation using Google Gemini
        return 'Welcome prompt not implemented for Google AI yet.';
    }

    // ******************************************************************************************************
    // Image content analyzer
    // ******************************************************************************************************
    /**
     * Image content analyzer
     *
     * Analyzes image content and generates a description using Google Gemini Vision API.
     * Handles image processing and returns analysis results. Currently not implemented
     * but prepared for future vision processing functionality.
     *
     * @param array $arrMessage Message array containing image information and metadata
     * @return array|string|bool Image description with updated BFILETEXT field or error message
     */
    public static function explainImage($arrMessage): array|string|bool
    {
        // Check if we have a file path
        if (empty($arrMessage['BFILEPATH'])) {
            $arrMessage['BFILETEXT'] = 'Error: No image file path provided';
            return $arrMessage;
        }

        // Construct full file path
        $imagePath = 'up/' . $arrMessage['BFILEPATH'];

        // Check if file exists
        if (!file_exists($imagePath)) {
            $arrMessage['BFILETEXT'] = 'Error: Image file not found at ' . $imagePath;
            return $arrMessage;
        }

        // Get file info
        $fileInfo = pathinfo($imagePath);
        $fileExtension = strtolower($fileInfo['extension']);
        $fileName = $fileInfo['basename'];

        // Determine MIME type based on file extension
        $mimeType = '';
        switch ($fileExtension) {
            case 'jpg':
            case 'jpeg':
                $mimeType = 'image/jpeg';
                break;
            case 'png':
                $mimeType = 'image/png';
                break;
            case 'gif':
                $mimeType = 'image/gif';
                break;
            case 'webp':
                $mimeType = 'image/webp';
                break;
            case 'svg':
                $mimeType = 'image/svg+xml';
                break;
            case 'bmp':
                $mimeType = 'image/bmp';
                break;
            case 'tiff':
            case 'tif':
                $mimeType = 'image/tiff';
                break;
            default:
                $arrMessage['BFILETEXT'] = 'Error: Unsupported image format: ' . $fileExtension;
                return $arrMessage;
        }

        // Read and encode image as base64
        $imageData = file_get_contents($imagePath);
        if ($imageData === false) {
            $arrMessage['BFILETEXT'] = 'Error: Could not read image file';
            return $arrMessage;
        }

        $base64Image = base64_encode($imageData);

        // Get the model from configuration
        $myModel = $GLOBALS['AI_PIC2TEXT']['MODEL'];

        // Prepare the API URL for Gemini Vision
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $myModel . ':generateContent?key=' . self::$key;
        $headers = [
            'Content-Type: application/json'
        ];

        // Prepare request data with image and analysis prompt
        $postData = [
            'contents' => [[
                'parts' => [
                    [
                        'inline_data' => [
                            'mime_type' => $mimeType,
                            'data' => $base64Image
                        ]
                    ],
                    [
                        'text' => "Please analyze this image and provide a detailed description of what you see. Include any text, objects, people, actions, colors, and overall context. Please use language '".$arrMessage['BLANG']."'."
                    ]
                ]
            ]]
        ];

        try {
            $arrRes = Curler::callJson($url, $headers, $postData);

            // Extract response text
            if (isset($arrRes['candidates'][0]['content']['parts'][0]['text'])) {
                $description = $arrRes['candidates'][0]['content']['parts'][0]['text'];

                // Update the message with the image description
                $arrMessage['BFILETEXT'] = $description;
                return $arrMessage;
            } else {
                $arrMessage['BFILETEXT'] = 'Error: Google AI response format error';
                return $arrMessage;
            }
        } catch (Exception $err) {
            $arrMessage['BFILETEXT'] = 'Error: ' . $err->getMessage();
            return $arrMessage;
        }
    }

    // ******************************************************************************************************
    // Audio to text converter
    // ******************************************************************************************************
    /**
     * Audio to text converter
     *
     * Transcribes MP3 audio files to text using Google Gemini audio processing API.
     * Handles audio file processing and returns the transcription. Currently not
     * implemented but prepared for future audio transcription functionality.
     *
     * @param array $arrMessage Message array containing audio file information and metadata
     * @return array|string|bool Transcription text or placeholder message
     */
    public static function mp3ToText($arrMessage): array|string|bool
    {
        // TODO: Implement audio transcription using Google Gemini
        return 'Audio transcription not implemented for Google AI yet.';
    }

    // ******************************************************************************************************
    // Text translator
    // ******************************************************************************************************
    /**
     * Text translator
     *
     * Translates text content to a specified language using Google Gemini translation capabilities.
     * Supports multiple languages and handles translation errors gracefully. Currently not
     * implemented but prepared for future translation functionality.
     *
     * @param array $msgArr Message array containing text to translate
     * @param string $lang Target language code (optional, defaults to message language)
     * @param string $sourceText Field name containing text to translate (optional, defaults to 'BTEXT')
     * @return array Translated message array with updated content
     */
    public static function translateTo($msgArr, $lang = '', $sourceText = 'BTEXT'): array
    {
        // TODO: Implement translation using Google Gemini
        return $msgArr;
    }

    // ******************************************************************************************************
    // Text summarizer
    // ******************************************************************************************************
    /**
     * Text summarizer
     *
     * Summarizes a given text using Google Gemini's summarization capabilities.
     * Creates concise summaries while preserving key information. Currently not
     * implemented but prepared for future summarization functionality.
     *
     * @param string $text The text content to summarize
     * @return string Summarized text or placeholder message
     */
    public static function summarizePrompt($text): string
    {
        // TODO: Implement text summarization using Google Gemini

        return 'Text summarization not implemented for Google AI yet.';
    }

    // ******************************************************************************************************
    // picture prompt
    // ******************************************************************************************************
    /**
     * Picture generation prompt
     *
     * Generates images based on text prompts using Google Gemini's image generation API.
     * Processes text descriptions and creates corresponding images, saving them to the
     * file system with proper organization and metadata.
     *
     * @param array $msgArr Message array containing the image generation prompt and user information
     * @return array Updated message array with generated image file information or error details
     */
    public static function picPrompt($msgArr): array
    {
        // Load API key
        $usrArr = Central::getUsrById($msgArr['BUSERID']);

        // Prepare prompt
        if (substr($msgArr['BTEXT'], 0, 1) == '/') {
            $picPrompt = substr($msgArr['BTEXT'], strpos($msgArr['BTEXT'], ' '));
        } else {
            $picPrompt = $msgArr['BTEXT'];
        }
        $picPrompt = trim($picPrompt);

        if (strlen($picPrompt) > 1) {
            // Get the model from configuration
            $myModel = $GLOBALS['AI_TEXT2PIC']['MODEL'];

            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $myModel . ':generateContent?key=' . self::$key;
            $headers = [
                'Content-Type: application/json'
            ];
            $postData = [
                'contents' => [[
                    'parts' => [
                        ['text' => $picPrompt]
                    ]
                ]],
                'generationConfig' => [
                    'responseModalities' => ['TEXT', 'IMAGE']
                ]
            ];

            $arrRes = Curler::callJson($url, $headers, $postData);

            // Extract base64 image data
            $base64 = '';
            if (isset($arrRes['candidates'][0]['content']['parts'])) {
                foreach ($arrRes['candidates'][0]['content']['parts'] as $part) {
                    if (isset($part['inlineData']['mimeType']) && strpos($part['inlineData']['mimeType'], 'image/') === 0) {
                        $base64 = $part['inlineData']['data'];
                        $fileType = explode('/', $part['inlineData']['mimeType'])[1];
                        break;
                    }
                }
            }
        }

        // Save file to
        if (!empty($base64)) {
            $fileOutput = substr($usrArr['BID'], -5, 3) . '/' . substr($usrArr['BID'], -2, 2) . '/' . date('Ym');
            $filePath = $fileOutput . '/google_' . time() . '_' . uniqid() . '.' . $fileType;
            if (!is_dir('up/' . $fileOutput)) {
                mkdir('up/' . $fileOutput, 0777, true);
            }
            file_put_contents('up/' . $filePath, base64_decode($base64));

            $msgArr['BFILE'] = 1;
            $msgArr['BFILETEXT'] = json_encode($postData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $msgArr['BFILEPATH'] = $filePath;
            $msgArr['BFILETYPE'] = $fileType;
        } else {
            $msgArr['BFILEPATH'] = '';
            $msgArr['BFILETEXT'] = 'Error: No image data returned';
        }

        return $msgArr;
    }
    // ******************************************************************************************************
    // text to speech
    // ******************************************************************************************************
    /**
     * Text to speech converter
     *
     * Converts text content to speech audio using Google Gemini's text-to-speech API.
     * Generates audio files from text input with configurable voice options. Currently not
     * implemented but prepared for future TTS functionality.
     *
     * @param array $msgArr Message array containing text content and user information
     * @return array Result array with audio file information or empty array if not implemented
     */
    public static function textToSpeech($msgArr): array
    {
        $resArr = [];
        $usrArr = Central::getUsrById($msgArr['BUSERID']);
        return $resArr;
    }

    // ******************************************************************************************************
    // video generation
    // ******************************************************************************************************
    /**
     * Video generation
     *
     * Generates videos based on text prompts using Google Gemini's VEO video generation API.
     * Creates videos from text descriptions using advanced AI models, with support for streaming
     * updates and proper file management. Handles long-running operations with polling.
     *
     * @param array $msgArr Message array containing the video generation prompt and user information
     * @param bool $stream Whether to provide streaming updates during generation (optional, defaults to false)
     * @return array Updated message array with generated video file information or error details
     */
    public static function createVideo($msgArr, $stream = false): array
    {
        $debug = !empty($GLOBALS['debug']) ? true : false;
        $startTime = microtime(true);
        // Load user data
        $usrArr = Central::getUsrById($msgArr['BUSERID']);
        // Prepare prompt
        if (substr($msgArr['BTEXT'], 0, 1) == '/') {
            $videoPrompt = substr($msgArr['BTEXT'], strpos($msgArr['BTEXT'], ' '));
        } else {
            $videoPrompt = $msgArr['BTEXT'];
        }
        $videoPrompt = trim($videoPrompt);

        if (strlen($videoPrompt) > 1) {
            // Get the model from configuration
            $myModel = $GLOBALS['AI_TEXT2VID']['MODEL'];
            if (!empty($GLOBALS['debug'])) {
                $dbgModelId = $GLOBALS['AI_TEXT2VID']['MODELID'] ?? 'n/a';
                error_log("AIGoogle::createVideo using model={$myModel} (id={$dbgModelId})");
                if ($stream) {
                    $update = [
                        'msgId' => $msgArr['BID'],
                        'status' => 'pre_processing',
                        'message' => '[DBG] model ' . $myModel . ' '
                    ];
                    Frontend::printToStream($update);
                }
            }

            // Start video generation
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $myModel . ':predictLongRunning?key=' . self::$key;
            $headers = [
                'Content-Type: application/json'
            ];
            $postData = [
                'instances' => [[
                    'prompt' => $videoPrompt
                ]],
                'parameters' => [
                    'aspectRatio' => '16:9',
                    'personGeneration' => 'allow_adult'
                ]
            ];

            try {
                if ($debug) {
                    error_log('[AIGoogle::createVideo] Starting long-running prediction. Model=' . $myModel . ', PromptLen=' . strlen($videoPrompt));
                }
                // call the API
                $arrRes = Curler::callJson($url, $headers, $postData);
                //
                if ($debug) {
                    error_log('[AIGoogle::createVideo] Response=' . json_encode($arrRes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }

                // If Google immediately returns an error, surface it
                if (isset($arrRes['error'])) {
                    if ($debug) {
                        error_log('[AIGoogle::createVideo] Error=' . json_encode($arrRes['error'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    }
                    $msgArr['BFILEPATH'] = '';
                    $msgArr['BFILETEXT'] = 'Error: ' . json_encode($arrRes['error'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    return $msgArr;
                }

                // Extract operation name
                $operationName = $arrRes['name'] ?? '';

                if (empty($operationName)) {
                    $errDetails = $debug ? (' Response=' . json_encode($arrRes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : '';
                    $msgArr['BFILEPATH'] = '';
                    $msgArr['BFILETEXT'] = 'Error: No operation name returned.' . $errDetails;
                    return $msgArr;
                }

                // Poll for completion
                $maxAttempts = 60; // 5 minutes max (5 seconds * 60)
                $attempt = 0;
                $isDone = false;
                $lastError = '';

                while (!$isDone && $attempt < $maxAttempts) {
                    sleep(5); // Wait 5 seconds

                    $checkUrl = 'https://generativelanguage.googleapis.com/v1beta/' . $operationName . '?key=' . self::$key;
                    $checkRes = [];
                    try {
                        $checkRes = Curler::callJson($checkUrl, $headers, null);
                    } catch (Exception $e) {
                        $lastError = $e->getMessage();
                        if ($debug) {
                            error_log('[AIGoogle::createVideo] Poll attempt=' . ($attempt + 1) . ' error: ' . $lastError);
                        }
                        // Continue polling; transient failures are possible
                        $attempt++;
                        if ($stream) {
                            $update = [
                                'msgId' => $msgArr['BID'],
                                'status' => 'pre_processing',
                                'message' => 'Transient polling error; retrying... '
                            ];
                            Frontend::printToStream($update);
                        }
                        continue;
                    }

                    if ($debug) {
                        // Progress and state if available
                        $progress = isset($checkRes['metadata']['progressPercent']) ? $checkRes['metadata']['progressPercent'] : (isset($checkRes['metadata']['progress_percent']) ? $checkRes['metadata']['progress_percent'] : null);
                        $state = isset($checkRes['metadata']['state']) ? $checkRes['metadata']['state'] : (isset($checkRes['metadata']['state_message']) ? $checkRes['metadata']['state_message'] : null);
                        error_log('[AIGoogle::createVideo] Poll attempt=' . ($attempt + 1) . ', done=' . (isset($checkRes['done']) && $checkRes['done'] ? 'true' : 'false') . (is_null($progress) ? '' : ', progress='.$progress) . (is_null($state) ? '' : ', state='.$state));
                        // Only log full response in debug to avoid noise
                        error_log('[AIGoogle::createVideo] Check Res: ' . json_encode($checkRes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    }

                    $isDone = $checkRes['done'] ?? false;
                    $attempt++;

                    if ($stream) {
                        $progress = isset($checkRes['metadata']['progressPercent']) ? $checkRes['metadata']['progressPercent'] : (isset($checkRes['metadata']['progress_percent']) ? $checkRes['metadata']['progress_percent'] : null);
                        $stateMsg = '';
                        if (isset($checkRes['metadata']['statusMessage'])) {
                            $stateMsg = $checkRes['metadata']['statusMessage'];
                        }
                        if ($stateMsg === '' && isset($checkRes['metadata']['state'])) {
                            $stateMsg = $checkRes['metadata']['state'];
                        }
                        $update = [
                            'msgId' => $msgArr['BID'],
                            'status' => 'pre_processing',
                            'message' => (is_null($progress) ? strval((5 * $attempt)) : (strval($progress).'%')) . (strlen($stateMsg) ? (' '.$stateMsg) : '') . ' '
                        ];
                        Frontend::printToStream($update);
                    }

                    if ($isDone) {
                        // Check if there's an error
                        if (isset($checkRes['error'])) {
                            $msgArr['BFILEPATH'] = '';
                            $msgArr['BFILETEXT'] = 'Error: ' . json_encode($checkRes['error'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            return $msgArr;
                        }

                        // Extract video data
                        $videoData = '';
                        if (isset($checkRes['response']['generateVideoResponse']['generatedSamples'][0]['video']['uri'])) {
                            $videoUri = $checkRes['response']['generateVideoResponse']['generatedSamples'][0]['video']['uri'];
                            $videoUri .= '&key=' . self::$key; // Add API key for authentication

                            // Download the video from the URI
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $videoUri);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 180);
                            $videoData = curl_exec($ch);
                            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            $curlErr = curl_error($ch);
                            curl_close($ch);

                            if ($httpCode != 200 || $videoData === false) {
                                if ($debug) {
                                    error_log('[AIGoogle::createVideo] Video download failed. HTTP=' . $httpCode . ', error=' . $curlErr);
                                }
                                $videoData = '';
                            }
                        }

                        if (!empty($videoData)) {
                            // Save video file
                            $fileOutput = substr($usrArr['BID'], -5, 3) . '/' . substr($usrArr['BID'], -2, 2) . '/' . date('Ym');
                            $filePath = $fileOutput . '/google_video_' . time() . '_' . uniqid() . '.mp4';

                            if (!is_dir('up/' . $fileOutput)) {
                                mkdir('up/' . $fileOutput, 0777, true);
                            }

                            file_put_contents('up/' . $filePath, $videoData);

                            $msgArr['BFILE'] = 1;
                            $msgArr['BFILETEXT'] = json_encode($postData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $msgArr['BFILEPATH'] = $filePath;
                            $msgArr['BFILETYPE'] = 'mp4';
                            $msgArr['BTEXT'] = 'Video generated successfully: ' . $videoPrompt;
                            if ($debug) {
                                $took = round((microtime(true) - $startTime), 2);
                                error_log('[AIGoogle::createVideo] Success. Took=' . $took . 's, Attempts=' . $attempt);
                            }
                        } else {
                            // Try to capture Google-provided reasons from response payload
                            $diagnostics = [];
                            $gvr = isset($checkRes['response']['generateVideoResponse']) ? $checkRes['response']['generateVideoResponse'] : [];
                            if (isset($gvr['blockReason'])) {
                                $diagnostics['blockReason'] = $gvr['blockReason'];
                            }
                            if (isset($gvr['reason'])) {
                                $diagnostics['reason'] = $gvr['reason'];
                            }
                            if (isset($gvr['status'])) {
                                $diagnostics['status'] = $gvr['status'];
                            }
                            if (isset($gvr['error'])) {
                                $diagnostics['error'] = $gvr['error'];
                            }
                            if (isset($gvr['safetyRatings'])) {
                                $diagnostics['safetyRatings'] = $gvr['safetyRatings'];
                            }
                            if (isset($gvr['safetyFeedback'])) {
                                $diagnostics['safetyFeedback'] = $gvr['safetyFeedback'];
                            }
                            // Also inspect generatedSamples entries for per-sample status
                            if (isset($gvr['generatedSamples'][0])) {
                                $sample = $gvr['generatedSamples'][0];
                                foreach (['status','blockReason','reason','errorMessage'] as $k) {
                                    if (isset($sample[$k])) {
                                        $diagnostics['sample_'.$k] = $sample[$k];
                                    }
                                }
                            }
                            // Fallback: include metadata state/status
                            if (isset($checkRes['metadata']['statusMessage'])) {
                                $diagnostics['statusMessage'] = $checkRes['metadata']['statusMessage'];
                            }
                            if (isset($checkRes['metadata']['state'])) {
                                $diagnostics['state'] = $checkRes['metadata']['state'];
                            }

                            $msgArr['BFILEPATH'] = '';
                            $reason = (count($diagnostics) > 0) ? (' Google reason: ' . json_encode($diagnostics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : '';
                            $detail = '';
                            if ($debug && $reason === '') {
                                $detail = ' Details: Response keys=' . implode(',', array_keys($checkRes));
                                if (isset($checkRes['response'])) {
                                    $detail .= '; responseKeys=' . implode(',', array_keys($checkRes['response']));
                                }
                            }
                            $msgArr['BFILETEXT'] = 'Error: No video data returned.' . $reason . $detail;
                        }
                        break;
                    }
                }

                if (!$isDone) {
                    $msgArr['BFILEPATH'] = '';
                    $extra = $debug ? (' (lastError=' . ($lastError !== '' ? $lastError : 'none') . ')') : '';
                    $msgArr['BFILETEXT'] = 'Error: Video generation timeout after ' . ($maxAttempts * 5) . ' seconds.' . $extra;
                }

            } catch (Exception $err) {
                $msgArr['BFILEPATH'] = '';
                $extra = '';
                if ($debug) {
                    $extra = ' Response=' . (isset($arrRes) ? json_encode($arrRes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'n/a');
                }
                $msgArr['BFILETEXT'] = 'Error: ' . $err->getMessage() . $extra;
            }
        } else {
            $msgArr['BFILEPATH'] = '';
            $msgArr['BFILETEXT'] = 'Error: No prompt provided';
        }

        return $msgArr;
    }

    // ******************************************************************************************************
    // file and document processing
    // ******************************************************************************************************
    /**
     * File analysis and processing
     *
     * Analyzes files (PDF or text) using Google Gemini's inline data processing API.
     * Supports document analysis, content extraction, and intelligent processing of uploaded files.
     * Uses inline data approach for reliable processing without complex file uploads.
     *
     * @param array $msgArr Message array containing file information and user data
     * @param bool $stream Whether to provide streaming updates during processing (optional, defaults to false)
     * @return array Updated message array with file analysis results or error details
     */
    public static function analyzeFile($msgArr, $stream = false): array
    {
        // Check if file exists and is actually a file
        $filePath = rtrim(UPLOAD_DIR, '/').'/' . $msgArr['BFILEPATH'];

        $errorStop = '';
        // Get absolute path to avoid any path issues
        $absolutePath = realpath($filePath);
        if (!$absolutePath) {
            $errorStop .= '*API File Error - Cannot resolve file path: ' . $msgArr['BFILEPATH'];
        }

        if ($errorStop != '') {
            if ($GLOBALS['debug']) {
                error_log($errorStop);
            }
            if ($stream) {
                $update = [
                    'msgId' => $msgArr['BID'],
                    'status' => 'pre_processing',
                    'message' => 'Error: ' . $errorStop.' '
                ];
                Frontend::printToStream($update);
            }
            $msgArr['BFILETEXT'] = $errorStop;
            return $msgArr;
        }

        // Get file information
        $fileInfo = pathinfo($absolutePath);
        $fileExtension = strtolower($fileInfo['extension']);
        $fileName = $fileInfo['basename'];
        if ($stream) {
            $update = [
                'msgId' => $msgArr['BID'],
                'status' => 'pre_processing',
                'message' => 'Go for: ' . $fileName .' '
            ];
            Frontend::printToStream($update);
        }
        // Determine MIME type based on file extension
        $mimeType = '';
        switch ($fileExtension) {
            case 'pdf':
                $mimeType = 'application/pdf';
                break;
            case 'txt':
                $mimeType = 'text/plain';
                break;
            case 'jpg':
            case 'jpeg':
                $mimeType = 'image/jpeg';
                break;
            case 'png':
                $mimeType = 'image/png';
                break;
            case 'gif':
                $mimeType = 'image/gif';
                break;
            case 'webp':
                $mimeType = 'image/webp';
                break;
            case 'mp3':
                $mimeType = 'audio/mpeg';
                break;
            case 'wav':
                $mimeType = 'audio/wav';
                break;
            case 'mp4':
                // Check if it's video or audio based on context
                $mimeType = 'video/mp4';
                break;
            case 'webm':
                $mimeType = 'video/webm';
                break;
            case 'avi':
                $mimeType = 'video/avi';
                break;
            case 'aac':
                $mimeType = 'audio/aac';
                break;
            case 'm4a':
                $mimeType = 'audio/mp4';
                break;
            case 'm4v':
                $mimeType = 'video/mp4';
                break;
            case 'mov':
                $mimeType = 'video/quicktime';
                break;
            case 'svg':
                $mimeType = 'image/svg+xml';
                break;
            case 'bmp':
                $mimeType = 'image/bmp';
                break;
            case 'tiff':
            case 'tif':
                $mimeType = 'image/tiff';
                break;
            default:
                $errorStop .= '*API File Error - Unsupported file type: ' . $fileExtension;
                break;
        }

        if ($errorStop != '') {
            if ($GLOBALS['debug']) {
                error_log($errorStop);
            }
            if ($stream) {
                $update = [
                    'msgId' => $msgArr['BID'],
                    'status' => 'pre_processing',
                    'message' => 'Error: ' . $errorStop.' '
                ];
                Frontend::printToStream($update);
            }
            $msgArr['BFILETEXT'] = $errorStop;
            return $msgArr;
        }

        try {
            if ($stream) {
                $update = [
                    'msgId' => $msgArr['BID'],
                    'status' => 'pre_processing',
                    'message' => 'Reading and analyzing file... '
                ];
                Frontend::printToStream($update);
            }

            // Read file content
            $fileContent = file_get_contents($absolutePath);
            if ($fileContent === false) {
                throw new Exception('Could not read file content');
            }

            // Check file size (limit to 10MB for inline processing)
            $fileSize = strlen($fileContent);
            if ($fileSize > 10 * 1024 * 1024) { // 10MB limit
                throw new Exception('File too large for inline processing. Maximum size is 10MB.');
            }

            // Encode file content as base64
            $base64Content = base64_encode($fileContent);

            // Get the model from configuration
            $myModel = $GLOBALS['AI_CHAT']['MODEL'];

            // Prepare the API URL for Gemini
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $myModel . ':generateContent?key=' . self::$key;
            $headers = [
                'Content-Type: application/json'
            ];

            // Prepare analysis prompt based on file type
            $analysisPrompt = 'Please analyze this ' . $fileExtension . ' file and provide a comprehensive summary. ';
            $analysisPrompt .= 'Include key points, main topics, important details, and any notable insights. ';
            $analysisPrompt .= 'If this is a document, extract the main content and structure. ';
            $analysisPrompt .= "Please provide the analysis in language '" . $msgArr['BLANG'] . "'.";

            // Prepare request data with file content and analysis prompt
            $postData = [
                'contents' => [[
                    'parts' => [
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data' => $base64Content
                            ]
                        ],
                        [
                            'text' => $analysisPrompt
                        ]
                    ]
                ]]
            ];

            // Set up progress updates if streaming is enabled
            $progressPid = null;
            if ($stream) {
                $update = [
                    'msgId' => $msgArr['BID'],
                    'status' => 'pre_processing',
                    'message' => 'Sending file to Google AI for analysis... '
                ];
                Frontend::printToStream($update);

                // Start a background process for progress updates (if pcntl is available)
                if (function_exists('pcntl_fork')) {
                    $pid = pcntl_fork();
                    if ($pid == 0) {
                        // Child process - send status updates every 10 seconds
                        $updateCount = 0;
                        while (true) {
                            sleep(10);
                            $updateCount++;
                            $update = [
                                'msgId' => $msgArr['BID'],
                                'status' => 'pre_processing',
                                'message' => '(' . ($updateCount * 10) . ') '
                            ];
                            Frontend::printToStream($update);

                            // Optional: break if parent likely gone (POSIX only)
                            if (function_exists('posix_getppid')) {
                                $ppid = posix_getppid();
                                if ($ppid <= 1) {
                                    break;
                                }
                            }
                        }
                        exit(0);
                    }
                    $progressPid = $pid;
                } else {
                    // Fallback for systems without pcntl (like Windows)
                    // Send a single update indicating the process may take time
                    $update = [
                        'msgId' => $msgArr['BID'],
                        'status' => 'pre_processing',
                        'message' => 'Analysis in progress... (this may take up to 2 minutes) '
                    ];
                    Frontend::printToStream($update);
                }
            }

            try {
                $analyzeResponse = Curler::callJson($url, $headers, $postData);
            } catch (Exception $err) {
                $errorMessage = 'File analysis error: ' . $err->getMessage();
                if ($GLOBALS['debug']) {
                    error_log($errorMessage);
                }
                $msgArr['BFILETEXT'] = $errorMessage;
                $msgArr['BTEXT'] = 'Error analyzing file: ' . $fileName;
                if ($stream) {
                    $update = [
                        'msgId' => $msgArr['BID'],
                        'status' => 'ai_processing',
                        'message' => 'Error: ' . $errorMessage . ' '
                    ];
                    Frontend::printToStream($update);
                }
            }

            // Clean up background progress process if it was started
            if ($progressPid !== null && function_exists('posix_kill')) {
                $childStatus = 0;
                posix_kill($progressPid, SIGTERM);
                pcntl_waitpid($progressPid, $childStatus);
            }

            // Extract analysis result
            if (isset($analyzeResponse['candidates'][0]['content']['parts'][0]['text'])) {
                $analysisResult = $analyzeResponse['candidates'][0]['content']['parts'][0]['text'];

                // Update message with analysis results
                $msgArr['BFILETEXT'] = $analysisResult;
                $msgArr['BTEXT'] = 'File analysis completed successfully for: ' . $fileName;
                $msgArr['BFILE'] = 0;
                $msgArr['BFILEPATH'] = '';
                $msgArr['BFILETYPE'] = '';
                if ($stream) {
                    $update = [
                        'msgId' => $msgArr['BID'],
                        'status' => 'pre_processing',
                        'message' => 'Analysis completed successfully. '
                    ];
                    Frontend::printToStream($update);
                }

            } else {
                throw new Exception('Analysis failed: ' . json_encode($analyzeResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

        } catch (Exception $err) {
            $errorMessage = 'File analysis error: ' . $err->getMessage();
            if ($GLOBALS['debug']) {
                error_log($errorMessage);
            }

            $msgArr['BFILETEXT'] = $errorMessage;
            $msgArr['BTEXT'] = 'Error analyzing file: ' . $fileName;

            if ($stream) {
                $update = [
                    'msgId' => $msgArr['BID'],
                    'status' => 'ai_processing',
                    'message' => 'Error: ' . $errorMessage . ' '
                ];
                Frontend::printToStream($update);
            }
        }

        return $msgArr;
    }

    /**
     * Simple prompt execution
     *
     * Executes a simple prompt with system and user messages, returning a structured response.
     * This provides a clean interface for basic AI interactions.
     *
     * @param string $systemPrompt The system prompt/instruction
     * @param string $userPrompt The user's input/prompt
     * @return array Response array with success status and result/error
     */
    public static function simplePrompt($systemPrompt, $userPrompt): array
    {
        // Get the model from configuration
        $myModel = $GLOBALS['AI_CHAT']['MODEL'];

        // Prepare the API URL
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $myModel . ':generateContent?key=' . self::$key;
        $headers = [
            'Content-Type: application/json'
        ];

        // Build the complete prompt with system context and user input
        $fullPrompt = $systemPrompt . "\n\n" . $userPrompt;

        $postData = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $fullPrompt]
                    ]
                ]
            ]
        ];

        try {
            $response = Curler::callJson($url, $headers, $postData);

            if (isset($response['candidates'][0]['content']['parts'][0]['text'])) {
                $result = $response['candidates'][0]['content']['parts'][0]['text'];

                return [
                    'success' => true,
                    'summary' => $result
                ];
            } else {
                throw new Exception('Invalid response format: ' . json_encode($response));
            }

        } catch (Exception $err) {
            return [
                'success' => false,
                'summary' => '*API Simple Prompt Error - Google Gemini error: * ' . $err->getMessage()
            ];
        }
    }
}

$test = AIGoogle::init();
