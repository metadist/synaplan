<?php

/**
 * AIAnthropic Class
 * 
 * Handles interactions with the Anthropic API for various AI processing tasks
 * including text generation, translation, image analysis, and audio processing.
 * 
 * @package AIAnthropic
 */

class AIAnthropic {
    /** @var string Anthropic API key */
    private static $key;
    /** @var string Anthropic API base URL */
    private static $baseUrl = 'https://api.anthropic.com/v1';

    /**
     * Initialize the Anthropic client
     * 
     * Loads the API key from the centralized configuration
     * 
     * @return bool True if initialization is successful
     */
    public static function init() {
        self::$key = ApiKeys::getAnthropic();
        if(!self::$key) {
            if($GLOBALS["debug"]) error_log("Anthropic API key not configured");
            return false;
        }
        return true;
    }

    /**
     * Make API request to Anthropic
     * 
     * @param string $endpoint API endpoint (without base URL)
     * @param array $data Request data
     * @param string $method HTTP method (GET, POST, etc.)
     * @param bool $stream Whether this is a streaming request
     * @return array|string Response data or error message
     */
    private static function makeRequest($endpoint, $data = [], $method = 'POST', $stream = false) {
        $url = self::$baseUrl . $endpoint;
        
        $headers = [
            'x-api-key: ' . self::$key,
            'anthropic-version: 2023-06-01',
            'content-type: application/json'
        ];

        if ($stream) {
            $headers[] = 'accept: text/event-stream';
        }

        $ch = curl_init();
        
        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Synaplan-Anthropic/1.0'
        ];

        if ($method === 'POST') {
            $curlOptions[CURLOPT_POST] = true;
            if (!empty($data)) {
                $curlOptions[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        } elseif ($method === 'GET') {
            $curlOptions[CURLOPT_HTTPGET] = true;
        }

        curl_setopt_array($ch, $curlOptions);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            return "*API Error - cURL error: " . $error;
        }
        
        if ($httpCode !== 200) {
            return "*API Error - HTTP $httpCode: " . $response;
        }
        
        return $response;
    }

    /**
     * Message sorting prompt handler
     * 
     * Analyzes and categorizes incoming messages to determine their intent and
     * appropriate handling method. This helps in routing messages to the correct
     * processing pipeline.
     * 
     * @param array $msgArr Current message array
     * @param array $threadArr Conversation thread history
     * @return array|string|bool Sorting result or error message
     */
    public static function sortingPrompt($msgArr, $threadArr): array|string|bool {
        $systemPrompt = BasicAI::getAprompt('tools:sort');
        
        $messages = [
            ['role' => 'user', 'content' => $systemPrompt['BPROMPT']]
        ];

        // Build message history
        foreach($threadArr as $msg) {
            if($msg['BDIRECT'] == 'IN') {
                $msg['BTEXT'] = Tools::cleanTextBlock($msg['BTEXT']);
                $msgText = $msg['BTEXT'];
                if(strlen($msg['BFILETEXT']) > 1) {
                    $msgText .= " User provided a file: ".$msg['BFILETYPE'].", saying: '".$msg['BFILETEXT']."'\n\n";
                }
                $messages[] = ['role' => 'user', 'content' => $msgText];
            } 
            if($msg['BDIRECT'] == 'OUT') {
                if(strlen($msg['BTEXT'])>200) {
                    // Truncate at word boundary to avoid breaking JSON or quotes
                    $truncatedText = substr($msg['BTEXT'], 0, 200);
                    // Find the last complete word
                    $lastSpace = strrpos($truncatedText, ' ');
                    if ($lastSpace !== false && $lastSpace > 150) {
                        $truncatedText = substr($truncatedText, 0, $lastSpace);
                    }
                    // Clean up any trailing quotes or incomplete JSON
                    $truncatedText = rtrim($truncatedText, '"\'{}[]');
                    $msg['BTEXT'] = $truncatedText . "...";
                }
                $messages[] = ['role' => 'assistant', 'content' => "[".$msg['BID']."] ".$msg['BTEXT']];
            }
        }

        // Add current message
        $msgText = json_encode($msgArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $messages[] = ['role' => 'user', 'content' => $msgText];

        $requestData = [
            'model' => $GLOBALS["AI_SORT"]["MODEL"],
            'max_tokens' => 1024,
            'messages' => $messages
        ];

        $response = self::makeRequest('/messages', $requestData);
        
        if (is_string($response) && strpos($response, '*API Error') === 0) {
            return $response;
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['content'][0]['text'])) {
            return "*API Error - Invalid response format";
        }

        $answer = $data['content'][0]['text'];
        
        // Clean JSON response
        $answer = str_replace("```json\n", "", $answer);
        $answer = str_replace("\n```", "", $answer);
        $answer = str_replace("```json", "", $answer);
        $answer = str_replace("```", "", $answer);
        $answer = trim($answer);
        
        return $answer;
    }

    /**
     * Topic-specific response generator
     * 
     * Generates responses based on the specific topic of the message.
     * Uses topic-specific prompts to create more focused and relevant responses.
     * Supports both streaming and non-streaming modes.
     * 
     * @param array $msgArr Message array containing topic information
     * @param array $threadArr Thread context for conversation history
     * @param bool $stream Whether to use streaming mode
     * @return array|string|bool Topic-specific response or error message
     */
    public static function topicPrompt($msgArr, $threadArr, $stream = false): array|string|bool {
        set_time_limit(3600);
        $systemPrompt = BasicAI::getAprompt($msgArr['BTOPIC'], $msgArr['BLANG'], $msgArr, true);

        if(isset($systemPrompt['TOOLS'])) {
            // call tools before the prompt is executed!
        }

        if ($stream) {
            $messages = [
                ['role' => 'user', 'content' => 'You are the Synaplan.com AI assistant. Please answer in the language of the user.']
            ];
        } else {
            $messages = [
                ['role' => 'user', 'content' => $systemPrompt['BPROMPT']]
            ];
        }

        // Build message history
        foreach($threadArr as $msg) {
            $role = 'user';
            if($msg['BDIRECT'] == 'OUT') {
                $role = 'assistant';
            }
            if ($stream) {
                $messages[] = ['role' => $role, 'content' => "[".$msg['BDATETIME']."]: ".$msg['BTEXT']];
            } else {
                $messages[] = ['role' => 'user', 'content' => "[".$msg['BID']."] ".$msg['BTEXT']];
            }
        }

        // Add current message
        if($stream) {
            $msgText = $msgArr['BTEXT'];
            if(strlen($msgArr['BFILETEXT']) > 1) {
                $msgText .= "\n\n\n---\n\n\nUser provided a file: ".$msgArr['BFILETYPE'].", saying: '".$msgArr['BFILETEXT']."'\n\n";
            }
            $messages[] = ['role' => 'user', 'content' => $msgText];
        } else {
            $msgText = json_encode($msgArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $messages[] = ['role' => 'user', 'content' => $msgText];
        }
        
        // Determine model (respect upstream selection in globals)
        $myModel = $GLOBALS["AI_CHAT"]["MODEL"];

        $requestData = [
            'model' => $myModel,
            'max_tokens' => 4096,
            'messages' => $messages
        ];

        if ($stream) {
            return self::handleStreamingRequest($requestData, $msgArr);
        } else {
            $response = self::makeRequest('/messages', $requestData);
            
            if (is_string($response) && strpos($response, '*API Error') === 0) {
                return $response;
            }

            $data = json_decode($response, true);
            if (!$data || !isset($data['content'][0]['text'])) {
                return "*API Error - Invalid response format";
            }

            $answer = $data['content'][0]['text'];

            // Clean JSON response - only if it starts with JSON markers
            if (strpos($answer, "```json\n") === 0) {
                $answer = substr($answer, 8); // Remove "```json\n" from start
                if (strpos($answer, "\n```") !== false) {
                    $answer = str_replace("\n```", "", $answer);
                }
            } elseif (strpos($answer, "```json") === 0) {
                $answer = substr($answer, 7); // Remove "```json" from start
                if (strpos($answer, "```") !== false) {
                    $answer = str_replace("```", "", $answer);
                }
            } elseif (strpos($answer, "```") === 0) {
                $answer = substr($answer, 3); // Remove "```" from start
                if (strpos($answer, "```") !== false) {
                    $answer = str_replace("```", "", $answer);
                }
            }
            
            $answer = trim($answer);

            if(Tools::isValidJson($answer) == false) {
                $arrAnswer = $msgArr;
                $arrAnswer['BTEXT'] = $answer;
                $arrAnswer['BDIRECT'] = 'OUT';
            } else {
                try {
                    $arrAnswer = json_decode($answer, true);
                } catch (Exception $err) {
                    return "*API topic Error - JSON decode failed: " . $err->getMessage();
                }    
            }

            // Add model information to the response
            $arrAnswer['_USED_MODEL'] = $myModel;
            $arrAnswer['_AI_SERVICE'] = 'AIAnthropic';

            return $arrAnswer;
        }
    }

    /**
     * Handle streaming request for topic prompt
     * 
     * @param array $requestData Request data
     * @param array $msgArr Message array
     * @return array Response array
     */
    private static function handleStreamingRequest($requestData, $msgArr) {
        $requestData['stream'] = true;
        
        $url = self::$baseUrl . '/messages';
        $headers = [
            'x-api-key: ' . self::$key,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
            'accept: text/event-stream'
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($requestData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Synaplan-Anthropic/1.0'
        ]);

        $answer = '';
        
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$answer, $msgArr) {
            $lines = explode("\n", $data);
            foreach ($lines as $line) {
                if (strpos($line, 'data: ') === 0) {
                    $jsonData = substr($line, 6);
                    if ($jsonData === '[DONE]') {
                        continue;
                    }
                    
                    $eventData = json_decode($jsonData, true);
                    if ($eventData && isset($eventData['type']) && $eventData['type'] === 'content_block_delta') {
                        if (isset($eventData['delta']['text'])) {
                            $textChunk = $eventData['delta']['text'];
                            $answer .= $textChunk;
                            Frontend::statusToStream($msgArr["BID"], 'ai', $textChunk);
                        }
                    }
                }
            }
            return strlen($data);
        });

        curl_exec($ch);
        curl_close($ch);

        $arrAnswer = $msgArr;
        $arrAnswer['BTEXT'] = $answer;
        $arrAnswer['BDIRECT'] = 'OUT';
        $arrAnswer['BDATETIME'] = date('Y-m-d H:i:s');
        $arrAnswer['BUNIXTIMES'] = time();
        
        // Clear file-related fields since there's no valid JSON
        $arrAnswer['BFILE'] = 0;
        $arrAnswer['BFILEPATH'] = '';
        $arrAnswer['BFILETYPE'] = '';
        $arrAnswer['BFILETEXT'] = '';

        // Add model information to the response
        $arrAnswer['_USED_MODEL'] = $requestData['model'];
        $arrAnswer['_AI_SERVICE'] = 'AIAnthropic';
        
        // avoid double output to the chat window
        $arrAnswer['ALREADYSHOWN'] = true;

        return $arrAnswer;
    }

    /**
     * Welcome message generator
     * 
     * Creates a personalized welcome message for new users.
     * Includes information about available commands and features.
     * 
     * @param array $msgArr Message array containing user information
     * @return array|string|bool Welcome message or error message
     */
    public static function welcomePrompt($msgArr): array|string|bool {
        $arrPrompt = BasicAI::getAprompt('tools:help');
        $systemPrompt = $arrPrompt['BPROMPT'];
        
        $messages = [
            ['role' => 'user', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => '{"BCOMMAND":"/list","BLANG":"'.$msgArr['BLANG'].'"}']
        ];

        $requestData = [
            'model' => $GLOBALS["AI_CHAT"]["MODEL"],
            'max_tokens' => 1024,
            'messages' => $messages
        ];

        $response = self::makeRequest('/messages', $requestData);
        
        if (is_string($response) && strpos($response, '*API Error') === 0) {
            return $response;
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['content'][0]['text'])) {
            return "*API Error - Invalid response format";
        }

        return $data['content'][0]['text'];
    }

    /**
     * Text to speech converter
     * 
     * Converts text content to speech using Anthropic's text-to-speech API.
     * Saves the generated audio file and returns the file information.
     * 
     * @param array $msgArr Message array containing text to convert
     * @param array $usrArr User array containing user information
     * @return array|bool Message array with file information or false on error
     */
    public static function textToSpeech($msgArr, $usrArr): array | bool {
        // Anthropic doesn't have a direct TTS API like OpenAI
        // This would need to be implemented using a different service
        // For now, return false to indicate not supported
        return false;
    }

    /**
     * Image prompt handler
     * 
     * Generates images based on text prompts using Anthropic's image generation API.
     * Saves the generated image and returns the file information.
     * 
     * @param array $msgArr Message array containing image prompt
     * @param bool $stream Whether to stream the response
     * @return array Message array with image file information
     */
    public static function picPrompt($msgArr, $stream = false): array {
        // Anthropic doesn't have image generation like DALL-E
        // This would need to be implemented using a different service
        // For now, return an error message
        $msgArr['BFILE'] = 0;
        $msgArr['BFILETEXT'] = "Image generation not supported by Anthropic API";
        return $msgArr;
    }

    /**
     * Image content analyzer
     * 
     * Analyzes image content and generates a description using Anthropic's vision API.
     * Handles image resizing for large files and returns the analysis results.
     * 
     * @param array $arrMessage Message array containing image information
     * @return array|string|bool Image description or error message
     */
    public static function explainImage($arrMessage): array|string|bool {
        // Resize image if too large
        if(filesize(rtrim(UPLOAD_DIR, '/').'/'.$arrMessage['BFILEPATH']) > intval(1024*1024*3.5)) {
            $imageFile = Tools::giveSmallImage($arrMessage['BFILEPATH'], false, 1200);
            $tmpAbs = rtrim(UPLOAD_DIR, '/').'/'."tmp_del_".$arrMessage['BID'].".png";
            $savedFile = imagepng($imageFile, $tmpAbs);
            chmod($tmpAbs, 0755);
            $imagePath = $tmpAbs;
        } else {
            $imagePath = rtrim(UPLOAD_DIR, '/').'/'.$arrMessage['BFILEPATH'];
        }

        // Read and encode image
        $imageData = file_get_contents($imagePath);
        $base64Image = base64_encode($imageData);
        
        // Determine MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $imagePath);
        finfo_close($finfo);

        $messages = [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $mimeType,
                            'data' => $base64Image
                        ]
                    ],
                    [
                        'type' => 'text',
                        'text' => isset($GLOBALS["AI_PIC2TEXT"]["PROMPT"]) 
                            ? $GLOBALS["AI_PIC2TEXT"]["PROMPT"] 
                            : 'Describe this image in detail. Be comprehensive and accurate.'
                    ]
                ]
            ]
        ];

        $requestData = [
            'model' => $GLOBALS["AI_PIC2TEXT"]["MODEL"],
            'max_tokens' => 1024,
            'messages' => $messages
        ];

        $response = self::makeRequest('/messages', $requestData);
        
        if (is_string($response) && strpos($response, '*API Error') === 0) {
            $arrMessage['BFILETEXT'] = $response;
        } else {
            $data = json_decode($response, true);
            if ($data && isset($data['content'][0]['text'])) {
                $arrMessage['BFILETEXT'] = $data['content'][0]['text'];
            } else {
                $arrMessage['BFILETEXT'] = "*API Image Error - Invalid response format";
            }
        }

        return $arrMessage;
    }

    /**
     * Audio to text converter
     * 
     * Transcribes MP3 audio files to text using Anthropic's audio transcription API.
     * Handles audio file processing and returns the transcription.
     * 
     * @param array $arrMessage Message array containing audio file information
     * @return array|string|bool Transcription text or error message
     */
    public static function mp3ToText($arrMessage): array|string|bool {
        // Anthropic doesn't have audio transcription like Whisper
        // This would need to be implemented using a different service
        // For now, return an error message
        return "Audio transcription not supported by Anthropic API";
    }

    /**
     * Text translator
     * 
     * Translates text content to a specified language using Anthropic's translation capabilities.
     * Supports multiple languages and handles translation errors gracefully.
     * 
     * @param array $msgArr Message array containing text to translate
     * @param string $lang Target language code (optional)
     * @param string $sourceText Field containing text to translate (optional)
     * @return array Translated message array
     */
    public static function translateTo($msgArr, $lang='', $sourceText='BTEXT'): array {    
        $targetLang = $msgArr['BLANG'];
        
        if(strlen($lang) == 2) {
            $targetLang = $lang;
        }

        $qTerm = $msgArr[$sourceText];
        
        if(substr($qTerm, 0, 1) != '/') {
            $qTerm = "/translate ".$lang." ".$qTerm;
        }

        $tPrompt = BasicAI::getAprompt('tools:lang');
        $messages = [
            ['role' => 'user', 'content' => $tPrompt],
            ['role' => 'user', 'content' => $qTerm]
        ];

        $requestData = [
            'model' => $GLOBALS["AI_CHAT"]["MODEL"],
            'max_tokens' => 1024,
            'messages' => $messages
        ];

        $response = self::makeRequest('/messages', $requestData);
        
        if (is_string($response) && strpos($response, '*API Error') === 0) {
            $msgArr['BTEXT'] = $response;
        } else {
            $data = json_decode($response, true);
            if ($data && isset($data['content'][0]['text'])) {
                $msgArr[$sourceText] = $data['content'][0]['text'];
            } else {
                $msgArr['BTEXT'] = "*API translate Error - Invalid response format";
            }
        }

        return $msgArr;
    }

    /**
     * Summarize text using Anthropic's summarization API
     * 
     * Summarizes a given text using Anthropic's summarization capabilities.
     * 
     * @param string $text Text to summarize
     * @return string Summarized text
     */
    public static function summarizePrompt($text): string {
        $messages = [
            [
                'role' => 'user', 
                'content' => 'You summarize the text of the user to a short 2-3 sentence summary. Do not add any other text, just the essence of the text. Stay under 128 characters. Answer in the language of the text.'
            ],
            ['role' => 'user', 'content' => $text]
        ];

        $requestData = [
            'model' => $GLOBALS["AI_SUMMARIZE"]["MODEL"],
            'max_tokens' => 256,
            'messages' => $messages
        ];

        $response = self::makeRequest('/messages', $requestData);
        
        if (is_string($response) && strpos($response, '*API Error') === 0) {
            return $response;
        }

        $data = json_decode($response, true);
        if ($data && isset($data['content'][0]['text'])) {
            return $data['content'][0]['text'];
        }

        return "*API summarize Error - Invalid response format";
    }

    /**
     * Create Office file using Anthropic API
     * 
     * Creates PowerPoint, Word, or Excel files using Anthropic's API.
     * 
     * @param array $msgArr Message array containing creation prompt
     * @param array $usrArr User array containing user information
     * @param bool $stream Whether to stream progress updates
     * @return array Result array with file information or error message
     */
    public static function createOfficeFile($msgArr, $usrArr, $stream = false): array {
        // Anthropic doesn't have file generation like OpenAI's code interpreter
        // This would need to be implemented using a different service
        return [
            'success' => false,
            'error' => 'Office file creation not supported by Anthropic API'
        ];
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
    public static function simplePrompt($systemPrompt, $userPrompt): array {
        $messages = [
            ['role' => 'user', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ];

        $requestData = [
            'model' => $GLOBALS["AI_SUMMARIZE"]["MODEL"],
            'max_tokens' => 1024,
            'messages' => $messages
        ];

        $response = self::makeRequest('/messages', $requestData);
        
        if (is_string($response) && strpos($response, '*API Error') === 0) {
            return [
                'success' => false,
                'summary' => $response
            ];
        }

        $data = json_decode($response, true);
        if ($data && isset($data['content'][0]['text'])) {
            return [
                'success' => true,
                'summary' => $data['content'][0]['text']
            ];
        }

        return [
            'success' => false,
            'summary' => "*API Simple Prompt Error - Invalid response format"
        ];
    }

    /**
     * Analyze uploaded file using Anthropic
     * 
     * Uploads a file to Anthropic and analyzes it using their API.
     * Supports office documents, PDFs, code files, and other document types.
     * 
     * @param array $msgArr Message array containing file information
     * @param bool $stream Whether to stream progress updates
     * @return array|string|bool Analysis result or error message
     */
    public static function analyzeFile($msgArr, $stream = false): array|string|bool {
        // Check if file exists and is actually a file
        $filePath = __DIR__ . '/../up/' . $msgArr['BFILEPATH'];

        $errorStop = '';       
        // Get absolute path to avoid any path issues
        $absolutePath = realpath($filePath);
        if (!$absolutePath) {
            $errorStop .= "*API File Error - Cannot resolve file path: " . $msgArr['BFILEPATH'];
        }

        if ($errorStop != '') {
            if($GLOBALS["debug"]) error_log($errorStop);
            if ($stream) {
                $update = [
                    'msgId' => $msgArr['BID'],
                    'status' => 'pre_processing',
                    'message' => 'Error: ' . $errorStop.' '
                ];
                Frontend::printToStream($update);
            }
            return $errorStop;
        }

        // Read and encode file
        $fileData = file_get_contents($absolutePath);
        $base64File = base64_encode($fileData);
        
        // Determine MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $absolutePath);
        finfo_close($finfo);

        $messages = [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'file',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $mimeType,
                            'data' => $base64File
                        ]
                    ],
                    [
                        'type' => 'text',
                        'text' => 'Please analyze this file and provide a comprehensive summary of its contents.'
                    ]
                ]
            ]
        ];

        $requestData = [
            'model' => $GLOBALS["AI_CHAT"]["MODEL"],
            'max_tokens' => 4096,
            'messages' => $messages
        ];

        $response = self::makeRequest('/messages', $requestData);
        
        if (is_string($response) && strpos($response, '*API Error') === 0) {
            return $response;
        }

        $data = json_decode($response, true);
        if ($data && isset($data['content'][0]['text'])) {
            return $data['content'][0]['text'];
        }

        return "*API File Analysis Error - Invalid response format";
    }
}

// Initialize the Anthropic client
AIAnthropic::init();

