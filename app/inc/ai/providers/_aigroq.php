<?php

/**
 * AIGroq Class
 *
 * Handles interactions with the Groq AI service for various AI processing tasks
 * including text generation, translation, and file processing.
 *
 * @package AIGroq
 */

use LucianoTonet\GroqPHP\Groq;
use LucianoTonet\GroqPHP\GroqException;

class AIGroq
{
    /** @var string Groq API key */
    private static $key;

    /** @var Groq Groq client instance */
    private static $client;

    /**
     * Initialize the Groq client
     *
     * Loads the API key from centralized configuration and creates a new Groq client instance
     *
     * @return bool True if initialization is successful
     */
    public static function init() {
        self::$key = ApiKeys::getGroq();
        if (!self::$key) {
            //if($GLOBALS["debug"]) error_log("Groq API key not configured");
            return false;
        }
        // Local debug: output the key only when running on localhost and debug is enabled
        /*
        $isLocalhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', '::1']) ||
                       (isset($_SERVER['SERVER_NAME']) && in_array($_SERVER['SERVER_NAME'], ['localhost', '127.0.0.1', '::1']));
        if (!empty($GLOBALS['debug']) && $isLocalhost) {
            error_log('GROQ DEBUG: Using API key: ' . self::$key);
        }
        */
        self::$client = new Groq(self::$key);
        return true;
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
        // Enhanced debug logging for sorting prompt
        if ($GLOBALS['debug']) {
            error_log('=== GROQ SORTING DEBUG: Starting sortingPrompt ===');
            error_log('Input msgArr: ' . print_r($msgArr, true));
            error_log('Thread count: ' . count($threadArr));
        }

        // prompt builder
        $systemPrompt = BasicAI::getAprompt('tools:sort');

        $client = self::$client;

        $arrMessages = [
            ['role' => 'system', 'content' => $systemPrompt['BPROMPT']],
        ];

        // Build message history
        foreach ($threadArr as $msg) {
            if ($msg['BDIRECT'] == 'IN') {
                $msg['BTEXT'] = Tools::cleanTextBlock($msg['BTEXT']);
                $msgText = $msg['BTEXT'];
                if (strlen($msg['BFILETEXT']) > 1) {
                    $msgText .= ' User provided a file: '.$msg['BFILETYPE'].", saying: '".$msg['BFILETEXT']."'\n\n";
                }
                $arrMessages[] = ['role' => 'user', 'content' => $msgText];
            }
            if ($msg['BDIRECT'] == 'OUT') {
                if (strlen($msg['BTEXT']) > 200) {
                    // Truncate at word boundary to avoid breaking JSON or quotes
                    $truncatedText = substr($msg['BTEXT'], 0, 200);
                    // Find the last complete word
                    $lastSpace = strrpos($truncatedText, ' ');
                    if ($lastSpace !== false && $lastSpace > 150) {
                        $truncatedText = substr($truncatedText, 0, $lastSpace);
                    }
                    // Clean up any trailing quotes or incomplete JSON
                    $truncatedText = rtrim($truncatedText, '"\'{}[]');
                    $msg['BTEXT'] = $truncatedText . '...';
                }
                $arrMessages[] = ['role' => 'assistant', 'content' => '['.$msg['BID'].'] '.$msg['BTEXT']];
            }
        }

        // Add current message
        $msgText = json_encode($msgArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $arrMessages[] = ['role' => 'user', 'content' => $msgText];

        // Enhanced debug logging for request
        if ($GLOBALS['debug']) {
            error_log('=== GROQ SORTING DEBUG: Request details ===');
            error_log('Total messages count: ' . count($arrMessages));
            error_log('System prompt length: ' . strlen($systemPrompt['BPROMPT']));
            error_log('Current message: ' . $msgText);
            error_log('Messages structure: ' . print_r(array_map(function ($msg) {
                return [
                    'role' => $msg['role'],
                    'content_length' => strlen($msg['content']),
                    'content_preview' => substr($msg['content'], 0, 100) . (strlen($msg['content']) > 100 ? '...' : '')
                ];
            }, $arrMessages), true));
        }

        // ------------------------------------------------
        try {
            // set model
            $AIGENERALmodel = $GLOBALS['AI_SORT']['MODEL'];

            if ($GLOBALS['debug']) {
                error_log('=== GROQ SORTING DEBUG: API Request ===');
                error_log('Model: ' . $AIGENERALmodel);
                error_log('Reasoning format: hidden');
            }

            $chat = $client->chat()->completions()->create([
                'model' => $AIGENERALmodel,
                'reasoning_format' => 'hidden',
                'messages' => $arrMessages
            ]);

            if ($GLOBALS['debug']) {
                error_log('=== GROQ SORTING DEBUG: API Response Success ===');
                error_log('Response received successfully');
            }
        } catch (GroqException $err) {
            if ($GLOBALS['debug']) {
                error_log('=== GROQ SORTING DEBUG: API Error Details ===');
                error_log('Error type: ' . get_class($err));
                error_log('Error message: ' . $err->getMessage());
                error_log('Error code: ' . $err->getCode());
                error_log('Error file: ' . $err->getFile() . ':' . $err->getLine());
                error_log('Stack trace: ' . $err->getTraceAsString());
                error_log('Request model: ' . $AIGENERALmodel);
                error_log('Request messages count: ' . count($arrMessages));
            }
            return '*API sorting Error - Ralf made a bubu - please mail that to him: * ' . $err->getMessage();
        } catch (Exception $err) {
            if ($GLOBALS['debug']) {
                error_log('=== GROQ SORTING DEBUG: General Exception ===');
                error_log('Error type: ' . get_class($err));
                error_log('Error message: ' . $err->getMessage());
                error_log('Error code: ' . $err->getCode());
                error_log('Error file: ' . $err->getFile() . ':' . $err->getLine());
                error_log('Stack trace: ' . $err->getTraceAsString());
            }
            return '*API sorting Error - Unexpected error: * ' . $err->getMessage();
        }

        // Enhanced DEBUG: Log raw response before parsing (only if debug enabled)
        if ($GLOBALS['debug']) {
            error_log('=== GROQ SORTING DEBUG: Raw Response Analysis ===');
            error_log('Response type: ' . gettype($chat));
            error_log('Response keys: ' . (is_array($chat) ? implode(', ', array_keys($chat)) : 'Not an array'));

            if (isset($chat['choices'])) {
                error_log('Choices count: ' . count($chat['choices']));
                if (isset($chat['choices'][0])) {
                    error_log('First choice keys: ' . implode(', ', array_keys($chat['choices'][0])));
                    if (isset($chat['choices'][0]['message'])) {
                        error_log('Message keys: ' . implode(', ', array_keys($chat['choices'][0]['message'])));
                        if (isset($chat['choices'][0]['message']['content'])) {
                            $content = $chat['choices'][0]['message']['content'];
                            error_log('Content length: ' . strlen($content));
                            error_log('Content preview (first 200 chars): ' . substr($content, 0, 200));
                            error_log('Content preview (last 200 chars): ' . substr($content, -200));
                        } else {
                            error_log("ERROR: No 'content' key found in message");
                        }
                    } else {
                        error_log("ERROR: No 'message' key found in first choice");
                    }
                } else {
                    error_log('ERROR: No first choice found in choices array');
                }
            } else {
                error_log("ERROR: No 'choices' key found in response");
            }

            error_log('Full response structure: ' . print_r($chat, true));
        }

        // ------------------------------------------------
        // Clean and return response
        if (!isset($chat['choices'][0]['message']['content'])) {
            if ($GLOBALS['debug']) {
                error_log('=== GROQ SORTING DEBUG: Missing Content Error ===');
                error_log('Response structure is invalid - missing content');
            }
            return '*API sorting Error - Invalid response structure from Groq API*';
        }

        $answer = $chat['choices'][0]['message']['content'];

        if ($GLOBALS['debug']) {
            error_log('=== GROQ SORTING DEBUG: Content Processing ===');
            error_log('Raw answer length: ' . strlen($answer));
            error_log('Raw answer: ' . $answer);
        }

        // Clean JSON response - only remove code fences, don't extract BTEXT
        $answer = str_replace("```json\n", '', $answer);
        $answer = str_replace("\n```", '', $answer);
        $answer = str_replace('```json', '', $answer);
        $answer = str_replace('```', '', $answer);
        $answer = trim($answer);

        if ($GLOBALS['debug']) {
            error_log('=== GROQ SORTING DEBUG: Final Result ===');
            error_log('Final answer: ' . $answer);
            error_log('Answer type: ' . gettype($answer));
        }

        return $answer;
    }
    /**
     * Topic-specific response generator
     *
     * Generates responses based on the specific topic of the message.
     * Uses topic-specific prompts to create more focused and relevant responses.
     *
     * @param array $msgArr Message array containing topic information
     * @param array $threadArr Thread context for conversation history
     * @return array|string|bool Topic-specific response or error message
     */
    public static function topicPrompt($msgArr, $threadArr, $stream = false): array|string|bool {
        //error_log('topicPrompt: '.print_r($msgArr, true));

        $systemPrompt = BasicAI::getAprompt($msgArr['BTOPIC'], $msgArr['BLANG'], $msgArr, true);

        if (isset($systemPrompt['TOOLS'])) {
            // call tools before the prompt is executed!
        }
        $client = self::$client;
        $arrMessages = [
            ['role' => 'system', 'content' => $systemPrompt['BPROMPT']],
        ];

        // Build message history
        foreach ($threadArr as $msg) {
            if ($msg['BDIRECT'] == 'IN') {
                $msg['BTEXT'] = Tools::cleanTextBlock($msg['BTEXT']);
                $msgText = $msg['BTEXT'];
                if (strlen($msg['BFILETEXT']) > 1) {
                    $msgText .= ' User provided a file: '.$msg['BFILETYPE'].", saying: '".$msg['BFILETEXT']."'\n\n";
                }
                $arrMessages[] = ['role' => 'user', 'content' => $msgText];
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
                $arrMessages[] = ['role' => 'assistant', 'content' => '['.$msg['BID'].'] '.$msg['BTEXT']];
            }
        }

        // Add current message
        $msgText = json_encode($msgArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $arrMessages[] = ['role' => 'user', 'content' => $msgText];

        // which model on groq?
        $myModel = $GLOBALS['AI_CHAT']['MODEL'];

        try {
            if ($stream) {
                if ($GLOBALS['debug']) {
                    error_log('=== GROQ TOPIC DEBUG: Starting streaming mode ===');
                    error_log('Model: ' . $myModel);
                    error_log('Messages count: ' . count($arrMessages));
                }

                // Use streaming mode
                $chat = $client->chat()->completions()->create([
                    'model' => $myModel,
                    'reasoning_format' => 'raw',
                    'messages' => $arrMessages,
                    'stream' => true
                ]);

                $answer = '';
                $pendingText = '';
                $chunkCount = 0;

                try {
                    foreach ($chat->chunks() as $chunk) {
                        $chunkCount++;

                        if ($GLOBALS['debug'] && $chunkCount <= 5) {
                            error_log("=== GROQ TOPIC DEBUG: Chunk #$chunkCount ===");
                            error_log('Chunk structure: ' . print_r($chunk, true));
                        }

                        $deltaText = '';

                        // Extract delta content with fallbacks
                        if (isset($chunk['choices'][0]['delta']['content'])) {
                            $deltaText = $chunk['choices'][0]['delta']['content'];
                        }

                        // Skip empty chunks
                        if (empty($deltaText)) {
                            if ($GLOBALS['debug'] && $chunkCount <= 5) {
                                error_log('Empty chunk skipped');
                            }
                            continue;
                        }

                        $answer .= $deltaText;
                        $pendingText .= $deltaText;

                        if ($GLOBALS['debug'] && $chunkCount <= 5) {
                            error_log('Delta text: ' . $deltaText);
                            error_log('Accumulated answer length: ' . strlen($answer));
                        }

                        // Throttle ultra-small deltas (whitespace-only)
                        if (trim($pendingText) !== '' || strlen($pendingText) > 10) {
                            Frontend::statusToStream($msgArr['BID'], 'ai', $pendingText);
                            $pendingText = '';
                        }
                    }

                    if ($GLOBALS['debug']) {
                        error_log('=== GROQ TOPIC DEBUG: Streaming completed ===');
                        error_log('Total chunks processed: ' . $chunkCount);
                        error_log('Final answer length: ' . strlen($answer));
                    }

                    // Flush any remaining pending text
                    if (!empty($pendingText)) {
                        Frontend::statusToStream($msgArr['BID'], 'ai', $pendingText);
                    }
                } catch (Exception $streamErr) {
                    if ($GLOBALS['debug']) {
                        error_log('=== GROQ TOPIC DEBUG: Streaming Exception ===');
                        error_log('Error type: ' . get_class($streamErr));
                        error_log('Error message: ' . $streamErr->getMessage());
                        error_log('Error code: ' . $streamErr->getCode());
                        error_log('Error file: ' . $streamErr->getFile() . ':' . $streamErr->getLine());
                        error_log('Stack trace: ' . $streamErr->getTraceAsString());
                        error_log('Chunks processed before error: ' . $chunkCount);
                        error_log('Answer so far: ' . $answer);
                    }
                    return '*API topic Error - Streaming failed: ' . $streamErr->getMessage();
                }

                // Graceful completion fallback
                if (empty($answer)) {
                    return '*API topic Error - Streaming completed with no content';
                }

                // After streaming completes: if the full content is a JSON object, extract BTEXT
                $finalText = $answer;
                $maybeBTEXT = self::extractBTEXTFromJsonString($answer);
                if ($maybeBTEXT !== null) {
                    $finalText = $maybeBTEXT;
                }

                // Return assembled structure for streaming
                $arrAnswer = $msgArr;
                $arrAnswer['BTEXT'] = $finalText;
                $arrAnswer['BDIRECT'] = 'OUT';
                $arrAnswer['BDATETIME'] = date('Y-m-d H:i:s');
                $arrAnswer['BUNIXTIMES'] = time();

                // Clear file-related fields
                $arrAnswer['BFILE'] = 0;
                $arrAnswer['BFILEPATH'] = '';
                $arrAnswer['BFILETYPE'] = '';
                $arrAnswer['BFILETEXT'] = '';

                // Add model information to the response
                $arrAnswer['_USED_MODEL'] = $myModel;
                $arrAnswer['_AI_SERVICE'] = 'AIGroq';

                // avoid double output to the chat window
                $arrAnswer['ALREADYSHOWN'] = true;

                return $arrAnswer;
            } else {
                // Use non-streaming mode (existing logic)
                $chat = $client->chat()->completions()->create([
                    'model' => $myModel,
                    'reasoning_format' => 'raw',
                    'messages' => $arrMessages
                ]);
            }

            // DEBUG: Log raw response before parsing (only if debug enabled)
            if ($GLOBALS['debug']) {
                error_log('=== GROQ TOPIC DEBUG: Raw response structure ===');
                error_log('Full response: ' . print_r($chat, true));
            }
        } catch (GroqException $err) {
            if ($GLOBALS['debug']) {
                error_log('=== GROQ TOPIC DEBUG: GroqException Details ===');
                error_log('Error type: ' . get_class($err));
                error_log('Error message: ' . $err->getMessage());
                error_log('Error code: ' . $err->getCode());
                error_log('Error file: ' . $err->getFile() . ':' . $err->getLine());
                error_log('Stack trace: ' . $err->getTraceAsString());
                error_log('Stream mode: ' . ($stream ? 'true' : 'false'));
                error_log('Model: ' . $myModel);
                error_log('Messages count: ' . count($arrMessages));
            }
            if ($stream) {
                return '*API topic Error - Streaming failed: ' . $err->getMessage();
            }
            return '*APItopic Error - Ralf made a bubu - please mail that to him: * ' . $err->getMessage();
        } catch (Exception $err) {
            if ($GLOBALS['debug']) {
                error_log('=== GROQ TOPIC DEBUG: General Exception ===');
                error_log('Error type: ' . get_class($err));
                error_log('Error message: ' . $err->getMessage());
                error_log('Error code: ' . $err->getCode());
                error_log('Error file: ' . $err->getFile() . ':' . $err->getLine());
                error_log('Stack trace: ' . $err->getTraceAsString());
                error_log('Stream mode: ' . ($stream ? 'true' : 'false'));
            }
            if ($stream) {
                return '*API topic Error - Streaming failed: ' . $err->getMessage();
            }
            return '*APItopic Error - Unexpected error: * ' . $err->getMessage();
        }

        $answer = $chat['choices'][0]['message']['content'];

        // DEBUG: Log answer before parsing (only if debug enabled)
        if ($GLOBALS['debug']) {
            error_log('=== GROQ TOPIC DEBUG: Answer before parsing ===');
            error_log('Raw answer: ' . $answer);
        }

        // Non-streaming: if JSON came back, extract BTEXT before output
        $maybeBTEXT = self::extractBTEXTFromJsonString($answer);
        if ($maybeBTEXT !== null) {
            $answer = $maybeBTEXT;
        }

        // COMMENTED OUT: Parsing logic temporarily disabled
        /*
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
        */

        // DEBUG: Log final answer (only if debug enabled)
        if ($GLOBALS['debug']) {
            error_log('=== GROQ TOPIC DEBUG: Final answer (no parsing) ===');
            error_log('Final answer: ' . $answer);
        }

        // Return final text (plain or extracted from JSON)
        $arrAnswer = $msgArr;
        $arrAnswer['BTEXT'] = $answer;
        $arrAnswer['BDIRECT'] = 'OUT';

        // Add model information to the response
        $arrAnswer['_USED_MODEL'] = $myModel;
        $arrAnswer['_AI_SERVICE'] = 'AIGroq';

        return $arrAnswer;
    }

    /**
     * Attempt to extract BTEXT from a JSON string response.
     * Returns null if no valid BTEXT can be found.
     */
    private static function extractBTEXTFromJsonString(string $content): ?string {
        $candidate = trim($content);

        // Strip BOM and zero-width/invisible characters that break JSON
        $candidate = preg_replace('/^\xEF\xBB\xBF/', '', $candidate);
        $candidate = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}\x{00A0}]/u', '', $candidate);

        // Strip code fences such as ```json ... ``` or ``` ... ```
        if (substr($candidate, 0, 3) === '```') {
            $candidate = preg_replace('/^```(?:json)?\s*/i', '', $candidate);
            $candidate = preg_replace('/\s*```\s*$/', '', $candidate);
        }

        // Locate first JSON object
        $start = strpos($candidate, '{');
        $end = strrpos($candidate, '}');
        if ($start === false || $end === false || $end <= $start) {
            // As a fallback try regex-based extraction on the full content
            return self::extractBTEXTViaRegex($candidate);
        }

        $jsonStr = substr($candidate, $start, $end - $start + 1);

        // Normalize quotes: convert common smart quotes/guillemets/backticks to straight quotes
        $jsonStr = str_replace([
            "\xE2\x80\x9C", // “
            "\xE2\x80\x9D", // ”
            "\xE2\x80\x9E", // „
            "\xC2\xAB",     // «
            "\xC2\xBB",     // »
            '`',              // backtick
            "\xE2\x80\x98", // ‘
            "\xE2\x80\x99"  // ’
        ], '"', $jsonStr);

        // Remove trailing commas before closing braces/brackets
        $jsonStr = preg_replace('/,(\s*[}\]])/', '$1', $jsonStr);

        // Attempt to parse JSON
        $data = json_decode($jsonStr, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            // Fallback: try regex extraction within the normalized JSON string
            return self::extractBTEXTViaRegex($jsonStr);
        }

        if (isset($data['BTEXT']) && is_string($data['BTEXT'])) {
            return trim($data['BTEXT']);
        }
        return null;
    }

    /**
     * Regex-based fallback to extract BTEXT from malformed JSON-like strings.
     */
    private static function extractBTEXTViaRegex(string $content): ?string {
        // Try to find BTEXT:"..." or BTEXT:'...' with various quote styles
        $pattern = '/[\"\'\x{201C}\x{201D}\x{201E}]?BTEXT[\"\'\x{201C}\x{201D}\x{201E}]?\s*:\s*([\"\'\x{201C}\x{201D}\x{201E}])(.*?)\1/su';
        if (preg_match($pattern, $content, $m)) {
            $raw = $m[2];
            // Decode common escape sequences
            $decoded = stripcslashes($raw);
            return trim($decoded);
        }
        return null;
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

        $client = self::$client;

        $arrMessages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        $msgText = '{"BCOMMAND":"/list","BLANG":"'.$msgArr['BLANG'].'"}';
        $arrMessages[] = ['role' => 'user', 'content' => $msgText];

        try {
            $chat = $client->chat()->completions()->create([
                'model' => $GLOBALS['AI_CHAT']['MODEL'],
                'messages' => $arrMessages
            ]);
        } catch (GroqException $err) {
            return '*APIwelcome Error - Ralf made a bubu - please mail that to him: * ' . $err->getMessage();
        }
        return $chat['choices'][0]['message']['content'];
    }
    /**
     * Image content analyzer
     *
     * Analyzes image content and generates a description using Groq's vision API.
     * Handles image resizing for large files and returns the analysis results.
     *
     * @param array $arrMessage Message array containing image information
     * @return array|string|bool Image description or error message
     */
    public static function explainImage($arrMessage): array|string|bool {
        // Resize image if too large
        if (filesize('./up/'.$arrMessage['BFILEPATH']) > intval(1024 * 1024 * 3.5)) {
            $imageFile = Tools::giveSmallImage($arrMessage['BFILEPATH'], false, 1200);
            $savedFile = imagepng($imageFile, './up/tmp_del_'.$arrMessage['BID'].'.png');
            chmod('./up/tmp_del_'.$arrMessage['BID'].'.png', 0755);
            $imagePath = 'up/tmp_del_'.$arrMessage['BID'].'.png';
        } else {
            $imagePath = 'up/'.$arrMessage['BFILEPATH'];
        }

        $imageURL = 'https://app.s/'.($imagePath);
        $client = self::$client;

        // Use the global prompt if available, otherwise use default
        $imgPrompt = isset($GLOBALS['AI_PIC2TEXT']['PROMPT'])
        ? $GLOBALS['AI_PIC2TEXT']['PROMPT']
        : 'Describe this image in detail. Be comprehensive and accurate.';

        try {
            $analysis = $client->vision()->analyze(
                $imagePath,
                $imgPrompt,
                ['model' => $GLOBALS['AI_PIC2TEXT']['MODEL']]
            );
            $arrMessage['BFILETEXT'] = $analysis['choices'][0]['message']['content'];
        } catch (GroqException $err) {
            $arrMessage['BFILETEXT'] =  '*API Image Error - Ralf made a bubu - please mail that to him: * ' . $err->getMessage();
        }
        return $arrMessage;
    }
    /**
     * Audio to text converter
     *
     * Transcribes MP3 audio files to text using Groq's audio processing API.
     * Handles audio file processing and returns the transcription.
     *
     * @param array $arrMessage Message array containing audio file information
     * @return array|string|bool Transcription text or error message
     */
    public static function mp3ToText($arrMessage): array|string|bool {
        $client = self::$client;
        try {
            $transcription = $client->audio()->transcriptions()->create([
                'file' => rtrim(UPLOAD_DIR, '/').'/'.$arrMessage['BFILEPATH'],
                'model' => $GLOBALS['AI_SOUND2TEXT']['MODEL'],
                'response_format' => 'text', /* Or 'text', 'json' */
                /*'language' => 'en', /* ISO 639-1 code (optional but recommended) */
                /* 'prompt' => 'Audio transcription...' /* (optional) */
            ]);

            $fullText = $transcription;
        } catch (\LucianoTonet\GroqPHP\GroqException $e) {
            $fullText = 'Error: ' . $e->getMessage();
        }
        return $fullText;
    }
    /**
     * Text translator
     *
     * Translates text content to a specified language using Groq's translation capabilities.
     * Supports multiple languages and handles translation errors gracefully.
     *
     * @param array $msgArr Message array containing text to translate
     * @param string $lang Target language code (optional)
     * @param string $sourceText Field containing text to translate (optional)
     * @return array Translated message array
     */
    public static function translateTo($msgArr, $lang = 'en', $sourceText = 'BTEXT'): array {
        $targetLang = $msgArr['BLANG'];

        if (strlen($lang) == 2) {
            $targetLang = $lang;
        }

        $qTerm = $msgArr[$sourceText];

        if (substr($qTerm, 0, 1) != '/') {
            $qTerm = '/translate '.$lang.' '.$qTerm;
        }

        $client = self::$client;

        $arrPrompt = BasicAI::getAprompt('tools:lang');
        $systemPrompt = is_array($arrPrompt) ? ($arrPrompt['BPROMPT'] ?? '') : (string)$arrPrompt;
        if ($GLOBALS['debug']) {
            $len = strlen((string)$systemPrompt);
            error_log('translateTo: systemPrompt.len='.$len);
        }
        // Normalize message contents: Groq API expects string or array of content parts, not objects
        $normalizeContent = function ($content) {
            if (is_string($content)) {
                return $content;
            }
            if (is_array($content)) {
                // If it's already an array of parts with type/content, keep it
                $looksLikeParts = true;
                foreach ($content as $part) {
                    if (!is_array($part) || !isset($part['type'])) {
                        $looksLikeParts = false;
                        break;
                    }
                }
                if ($looksLikeParts) {
                    return $content;
                }
                // Otherwise, stringify array
                return json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            // Objects or other types -> stringify safely
            return json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        };

        $arrMessages = [
            ['role' => 'system', 'content' => $normalizeContent($systemPrompt)]
        ];

        $arrMessages[] = ['role' => 'user', 'content' => $normalizeContent($qTerm)];


        try {
            $chat = $client->chat()->completions()->create([
                'model' => $GLOBALS['AI_CHAT']['MODEL'],
                'messages' => $arrMessages
            ]);
        } catch (GroqException $err) {
            // Enhanced debug output: return structured details with stringified contents
            $toText = function ($value) {
                if (is_string($value)) {
                    return $value;
                }
                if (is_array($value) || is_object($value)) {
                    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($json !== false && $json !== null) {
                        return $json;
                    }
                    return print_r($value, true);
                }
                return (string)$value;
            };
            $sysContent = $arrMessages[0]['content'] ?? '';
            $usrContent = $arrMessages[1]['content'] ?? '';
            $sysType = gettype($sysContent);
            $usrType = gettype($usrContent);
            if ($sysType === 'object' && function_exists('get_class')) {
                $sysType .= ':' . get_class($sysContent);
            }
            if ($usrType === 'object' && function_exists('get_class')) {
                $usrType .= ':' . get_class($usrContent);
            }
            $debug = "DEBUG translate failure GROQ\n"
                . 'error: ' . $err->getMessage() . "\n"
                . 'model: ' . ($GLOBALS['AI_CHAT']['MODEL'] ?? '') . "\n"
                . 'systemPrompt.type: ' . $sysType . "\n"
                . 'systemPrompt.text: ' . $toText($sysContent) . "\n"
                . 'userCommand.type: ' . $usrType . "\n"
                . 'userCommand.text: ' . $toText($usrContent) . "\n"
                . 'messages.json: ' . $toText($arrMessages) . "\n";
            if (strlen($debug) > 8000) {
                $debug = substr($debug, 0, 8000) . '...';
            }
            $msgArr['BTEXT'] = $debug;
            return $msgArr;
        }

        $msgArr[$sourceText] = $chat['choices'][0]['message']['content'];
        return $msgArr;
    }
    /**
     * Summarize text using Groq's summarization API
     *
     * Summarizes a given text using Groq's summarization capabilities.
     *
    */
    public static function summarizePrompt($text): string {
        $client = self::$client;
        $arrMessages = [
            ['role' => 'system', 'content' => 'You summarize the text of the user to a short 2-3 sentence summary. Do not add any other text, just the essence of the text. Stay under 128 characters. Answer in the language of the text.'],
        ];
        $arrMessages[] = ['role' => 'user', 'content' => $text];

        $chat = $client->chat()->completions()->create([
            'model' => $GLOBALS['AI_SUMMARIZE']['MODEL'],
            'messages' => $arrMessages
        ]);
        return $chat['choices'][0]['message']['content'];
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
        $client = self::$client;

        $arrMessages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ];

        try {
            $chat = $client->chat()->completions()->create([
                'model' => $GLOBALS['AI_SUMMARIZE']['MODEL'],
                'reasoning_format' => 'hidden',
                'messages' => $arrMessages
            ]);

            $result = $chat['choices'][0]['message']['content'];

            return [
                'success' => true,
                'summary' => $result
            ];
        } catch (GroqException $err) {
            return [
                'success' => false,
                'summary' => '*API Simple Prompt Error - Ralf made a bubu - please mail that to him: * ' . $err->getMessage()
            ];
        }
    }
}

// Initialize the Groq client
AIGroq::init();
