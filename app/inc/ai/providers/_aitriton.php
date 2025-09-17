<?php
/**
 * AITriton Class
 * 
 * Handles interactions with the NVIDIA Triton Inference Server for various AI processing tasks
 * including text generation, translation, and file processing using gRPC.
 * 
 * @package AITriton
 */

use Grpc\ChannelCredentials;
use Grpc\BaseStub;
use Inference\ModelInferRequest;
use Inference\ModelInferRequest\InferInputTensor;
use Inference\ModelInferRequest\InferRequestedOutputTensor;
use Inference\InferTensorContents;

class GRPCInferenceServiceClient extends BaseStub {

    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    public function ModelStreamInfer(ModelInferRequest $argument,
                                    $metadata = [], $options = []) {
        return $this->_serverStreamRequest('/inference.GRPCInferenceService/ModelStreamInfer',
                                         $argument,
                                         ['\Inference\ModelStreamInferResponse', 'decode'],
                                         $metadata, $options);
    }
}

class AITriton {
    /** @var string Triton server URL */
    private static $serverUrl;
    
    /** @var GRPCInferenceServiceClient Triton gRPC client instance */
    private static $client;
    
    /** @var string Model name for Triton */
    private static $modelName = 'mistral-streaming';

    /**
     * Initialize the Triton client
     * 
     * Loads the server URL from centralized configuration and creates a new Triton gRPC client instance
     * 
     * @return bool True if initialization is successful
     */
    public static function init() {
        self::$serverUrl = ApiKeys::getTritonServer();
        if(!self::$serverUrl) {
            if($GLOBALS["debug"]) error_log("Triton server URL not configured");
            return false;
        }
        
        // Create gRPC client
        self::$client = new GRPCInferenceServiceClient(
            self::$serverUrl,
            ['credentials' => ChannelCredentials::createInsecure()]
        );
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

        // prompt builder
        $systemPrompt = BasicAI::getAprompt('tools:sort');

        $client = self::$client;
        
        // Build the complete prompt with system context and message history
        $fullPrompt = $systemPrompt['BPROMPT']."\n\n";
        
        // Add conversation history
        $fullPrompt .= "Conversation History:\n";
        foreach($threadArr as $msg) {
            if($msg['BDIRECT'] == 'IN') {
                $msg['BTEXT'] = Tools::cleanTextBlock($msg['BTEXT']);
                $msgText = $msg['BTEXT'];
                if(strlen($msg['BFILETEXT']) > 1) {
                    $msgText .= " User provided a file: ".$msg['BFILETYPE'].", saying: '".$msg['BFILETEXT']."'\n\n";
                }
                $fullPrompt .= "User: " . $msgText . "\n";
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
                $fullPrompt .= "Assistant: [".$msg['BID']."] ".$msg['BTEXT'] . "\n";
            }
        }

        // Add current message
        $msgText = json_encode($msgArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $fullPrompt .= "\nCurrent message to analyze: " . $msgText;


        // ------------------------------------------------
        try {
            // Use Triton streaming inference for sorting (collect all output)
            $answer = self::streamInference($fullPrompt, 512, false); // false = collect all output
            
            
        } catch (Exception $err) {
            if($GLOBALS["debug"]) {
                error_log("Triton sorting error: " . $err->getMessage());
            }
            return "*API sorting Error - Triton error: * " . $err->getMessage();
        }
        
        
        // ------------------------------------------------
        // Clean and return response
        if (empty($answer)) {
            return "*API sorting Error - Empty response from Triton API*";
        }

        // Clean JSON response - only remove code fences, don't extract BTEXT
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
     * 
     * @param array $msgArr Message array containing topic information
     * @param array $threadArr Thread context for conversation history
     * @param bool $stream Whether to use streaming mode
     * @return array|string|bool Topic-specific response or error message
     */
    public static function topicPrompt($msgArr, $threadArr, $stream = false): array|string|bool {
        //error_log('topicPrompt: '.print_r($msgArr, true));

        $systemPrompt = BasicAI::getAprompt($msgArr['BTOPIC'], $msgArr['BLANG'], $msgArr, true);

        if(isset($systemPrompt['TOOLS'])) {
            // call tools before the prompt is executed!
        }
        $client = self::$client;
        
        // Build the complete prompt with system context and message history
        $fullPrompt = $systemPrompt['BPROMPT'] . "\n\n";
        
        // Add conversation history
        $fullPrompt .= "Conversation History:\n";
        foreach($threadArr as $msg) {
            if($msg['BDIRECT'] == 'IN') {
                $msg['BTEXT'] = Tools::cleanTextBlock($msg['BTEXT']);
                $msgText = $msg['BTEXT'];
                if(strlen($msg['BFILETEXT']) > 1) {
                    $msgText .= " User provided a file: ".$msg['BFILETYPE'].", saying: '".$msg['BFILETEXT']."'\n\n";
                }
                $fullPrompt .= "User: " . $msgText . "\n";
            } 
            if($msg['BDIRECT'] == 'OUT') {
                if(strlen($msg['BTEXT'])>1000) {
                    // Truncate at word boundary to avoid breaking JSON or quotes
                    $truncatedText = substr($msg['BTEXT'], 0, 1000);
                    // Find the last complete word
                    $lastSpace = strrpos($truncatedText, ' ');
                    if ($lastSpace !== false && $lastSpace > 800) {
                        $truncatedText = substr($truncatedText, 0, $lastSpace);
                    }
                    // Clean up any trailing quotes or incomplete JSON
                    $truncatedText = rtrim($truncatedText, '"\'{}[]');
                    $msg['BTEXT'] = $truncatedText . "...";
                }
                $fullPrompt .= "Assistant: [".$msg['BID']."] ".$msg['BTEXT'] . "\n";
            }
        }

        // Add current message
        $msgText = json_encode($msgArr,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $fullPrompt .= "\nCurrent message: " . $msgText;
        
        // which model on triton?
        $myModel = $GLOBALS["AI_CHAT"]["MODEL"];

        try {
            if ($stream) {
                
                // Use streaming mode
                $answer = '';
                $pendingText = '';
                
                try {
                    $call = $client->ModelStreamInfer(self::createInferRequest($fullPrompt, 1024));
                    
                    foreach ($call->responses() as $response) {
                        // Check for errors first
                        $errorMessage = $response->getErrorMessage();
                        if (!empty($errorMessage)) {
                            if ($GLOBALS["debug"]) {
                                error_log("=== TRITON TOPIC DEBUG: Stream Error ===");
                                error_log("Error message: " . $errorMessage);
                            }
                            continue;
                        }

                        $inferResponse = $response->getInferResponse();
                        if (!$inferResponse) {
                            continue; // Skip if no infer response
                        }

                        $textChunk = '';
                        $isFinal = false;


                        // Try structured output parsing first (more reliable)
                        $outputs = $inferResponse->getOutputs();
                        foreach ($outputs as $output) {
                            if ($output->getName() === 'text_output') {
                                $contents = $output->getContents();
                                if ($contents) {
                                    $bytesContents = $contents->getBytesContents();
                                    if (!empty($bytesContents)) {
                                        $textChunk = $bytesContents[0];
                                    }
                                }
                            } elseif ($output->getName() === 'is_final') {
                                $contents = $output->getContents();
                                if ($contents) {
                                    $boolContents = $contents->getBoolContents();
                                    if (!empty($boolContents)) {
                                        $isFinal = $boolContents[0];
                                    }
                                }
                            }
                        }

                        // If no structured output, try raw contents (but this might be binary)
                        if (empty($textChunk)) {
                            $rawContents = $inferResponse->getRawOutputContents();
                            if (!empty($rawContents)) {
                                
                                // Try to decode the first raw content as length-prefixed data
                                if (isset($rawContents[0])) {
                                    $rawData = $rawContents[0];
                                    if (strlen($rawData) >= 4) {
                                        $length = unpack('V', substr($rawData, 0, 4))[1]; // V = little-endian unsigned long
                                        if ($length > 0 && $length <= strlen($rawData) - 4) {
                                            $textChunk = substr($rawData, 4, $length);
                                        } else {
                                            $textChunk = $rawData; // Fallback to raw data
                                        }
                                    } else {
                                        $textChunk = $rawData; // Fallback to raw data
                                    }
                                }
                                // Check if we have is_final indicator in second raw content or detect end
                                if (count($rawContents) > 1 && isset($rawContents[1])) {
                                    // Some models put boolean flags in second position
                                    $isFinal = !empty(trim($rawContents[1]));
                                }
                            }
                        }

                        // Stream the chunk
                        if (!empty($textChunk)) {
                            $answer .= $textChunk;
                            $pendingText .= $textChunk;
                            
                            // Stream meaningful chunks (avoid flooding with tiny updates)
                            if (strlen($pendingText) > 5 || (strlen($pendingText) > 0 && trim($pendingText) !== '')) {
                                Frontend::statusToStream($msgArr["BID"], 'ai', $pendingText);
                                $pendingText = '';
                            }
                        }

                        // Check if this is the final chunk
                        if ($isFinal) {
                            break;
                        }
                    }
                    
                    
                    // Flush any remaining pending text
                    if (!empty($pendingText)) {
                        Frontend::statusToStream($msgArr["BID"], 'ai', $pendingText);
                    }
                    
                } catch (Exception $streamErr) {
                    if ($GLOBALS["debug"]) {
                        error_log("Triton streaming error: " . $streamErr->getMessage());
                    }
                    return "*API topic Error - Streaming failed: " . $streamErr->getMessage();
                }
                
                // Graceful completion fallback
                if (empty($answer)) {
                    return "*API topic Error - Streaming completed with no content";
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
                $arrAnswer['_AI_SERVICE'] = 'AITriton';
                
                // avoid double output to the chat window
                $arrAnswer['ALREADYSHOWN'] = true;

                return $arrAnswer;
                
            } else {
                // Use non-streaming mode (collect all output)
                $answer = self::streamInference($fullPrompt, 1024, false);
            }
            
            
        } catch (Exception $err) {
            if ($GLOBALS["debug"]) {
                error_log("Triton topic error: " . $err->getMessage());
            }
            if ($stream) {
                return "*API topic Error - Streaming failed: " . $err->getMessage();
            }
            return "*APItopic Error - Triton error: * " . $err->getMessage();
        }

        
        // Non-streaming: if JSON came back, extract BTEXT before output
        $maybeBTEXT = self::extractBTEXTFromJsonString($answer);
        if ($maybeBTEXT !== null) {
            $answer = $maybeBTEXT;
        }
        

        // Return final text (plain or extracted from JSON)
        $arrAnswer = $msgArr;
        $arrAnswer['BTEXT'] = $answer;
        $arrAnswer['BDIRECT'] = 'OUT';

        // Add model information to the response
        $arrAnswer['_USED_MODEL'] = $myModel;
        $arrAnswer['_AI_SERVICE'] = 'AITriton';

        return $arrAnswer;
    }

    /**
     * Image content analyzer
     * 
     * Analyzes image content and generates a description using Triton's vision capabilities.
     * Note: This is a placeholder implementation as Triton's mistral-streaming model
     * may not support vision. Returns an error message indicating the limitation.
     * 
     * @param array $arrMessage Message array containing image information
     * @return array|string|bool Image description or error message
     */
    public static function explainImage($arrMessage): array|string|bool {
        // Triton's mistral-streaming model doesn't support vision
        // This would need a different model or external service
        $arrMessage['BFILETEXT'] = "*API Image Error - Triton mistral-streaming model does not support image analysis. Please use a different AI service for image processing.*";
        return $arrMessage;
    }

    /**
     * Create a Triton inference request
     * 
     * @param string $prompt The input prompt
     * @param int $maxTokens Maximum tokens to generate
     * @return ModelInferRequest The prepared request
     */
    private static function createInferRequest($prompt, $maxTokens = 4096) {
        // Prepare inputs
        $textInput = new InferInputTensor();
        $textInput->setName('text_input');
        $textInput->setDatatype('BYTES');
        $textInput->setShape([1, 1]);

        $textContents = new InferTensorContents();
        $textContents->setBytesContents([$prompt]);
        $textInput->setContents($textContents);

        $maxTokensInput = new InferInputTensor();
        $maxTokensInput->setName('max_tokens');
        $maxTokensInput->setDatatype('INT32');
        $maxTokensInput->setShape([1, 1]);

        $maxContents = new InferTensorContents();
        $maxContents->setIntContents([$maxTokens]);
        $maxTokensInput->setContents($maxContents);

        // Prepare outputs
        $textOutput = new InferRequestedOutputTensor();
        $textOutput->setName('text_output');

        $finalOutput = new InferRequestedOutputTensor();
        $finalOutput->setName('is_final');

        // Create request (exactly like working demo)
        $request = new ModelInferRequest();
        $request->setModelName(self::$modelName);
        $request->setId('req-1');
        $request->setInputs([$textInput, $maxTokensInput]);
        $request->setOutputs([$textOutput, $finalOutput]);

        return $request;
    }

    /**
     * Stream inference from Triton server
     * 
     * @param string $prompt The input prompt
     * @param int $maxTokens Maximum tokens to generate
     * @param bool $stream Whether to stream or collect all output
     * @return string The complete response or empty string if streaming
     */
    private static function streamInference($prompt, $maxTokens = 128, $stream = true) {
        $client = self::$client;
        $answer = '';
        $seenAny = false;

        try {
            // Start streaming inference
            $call = $client->ModelStreamInfer(self::createInferRequest($prompt, $maxTokens));

            // Process streaming responses
            foreach ($call->responses() as $response) {
                // Check for errors first
                $errorMessage = $response->getErrorMessage();
                if (!empty($errorMessage)) {
                    if ($GLOBALS["debug"]) {
                        error_log("Triton stream error: " . $errorMessage);
                    }
                    continue;
                }

                $inferResponse = $response->getInferResponse();
                if (!$inferResponse) {
                    continue; // Skip if no infer response
                }

                $textChunk = '';
                $isFinal = false;

                // Try structured output parsing first (more reliable)
                $outputs = $inferResponse->getOutputs();
                foreach ($outputs as $output) {
                    if ($output->getName() === 'text_output') {
                        $contents = $output->getContents();
                        if ($contents) {
                            $bytesContents = $contents->getBytesContents();
                            if (!empty($bytesContents)) {
                                $textChunk = $bytesContents[0];
                            }
                        }
                    } elseif ($output->getName() === 'is_final') {
                        $contents = $output->getContents();
                        if ($contents) {
                            $boolContents = $contents->getBoolContents();
                            if (!empty($boolContents)) {
                                $isFinal = $boolContents[0];
                            }
                        }
                    }
                }

                // If no structured output, try raw contents (but this might be binary)
                if (empty($textChunk)) {
                    $rawContents = $inferResponse->getRawOutputContents();
                    if (!empty($rawContents)) {
                        
                        // Try to decode the first raw content as length-prefixed data
                        if (isset($rawContents[0])) {
                            $rawData = $rawContents[0];
                            if (strlen($rawData) >= 4) {
                                $length = unpack('V', substr($rawData, 0, 4))[1]; // V = little-endian unsigned long
                                if ($length > 0 && $length <= strlen($rawData) - 4) {
                                    $textChunk = substr($rawData, 4, $length);
                                } else {
                                    $textChunk = $rawData; // Fallback to raw data
                                }
                            } else {
                                $textChunk = $rawData; // Fallback to raw data
                            }
                        }
                        // Check if we have is_final indicator in second raw content or detect end
                        if (count($rawContents) > 1 && isset($rawContents[1])) {
                            // Some models put boolean flags in second position
                            $isFinal = !empty(trim($rawContents[1]));
                        }
                    }
                }

                // Collect the chunk
                if (!empty($textChunk)) {
                    $answer .= $textChunk;
                    $seenAny = true;
                }

                // Check if this is the final chunk
                if ($isFinal) {
                    break;
                }
            }

        } catch (Exception $e) {
            if ($GLOBALS["debug"]) {
                error_log("Triton stream error: " . $e->getMessage());
            }
            throw $e;
        }

        
        return $answer;
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
            "\xE2\x80\x9C", // "
            "\xE2\x80\x9D", // "
            "\xE2\x80\x9E", // „
            "\xC2\xAB",     // «
            "\xC2\xBB",     // »
            "`",              // backtick
            "\xE2\x80\x98", // '
            "\xE2\x80\x99"  // '
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
}

// Initialize the Triton client
AITriton::init();
