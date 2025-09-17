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
        // Enhanced debug logging for sorting prompt
        if ($GLOBALS["debug"]) {
            error_log("=== TRITON SORTING DEBUG: Starting sortingPrompt ===");
            error_log("Input msgArr: " . print_r($msgArr, true));
            error_log("Thread count: " . count($threadArr));
        }

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

        // Enhanced debug logging for request
        if ($GLOBALS["debug"]) {
            error_log("=== TRITON SORTING DEBUG: Request details ===");
            error_log("System prompt length: " . strlen($systemPrompt['BPROMPT']));
            error_log("Current message: " . $msgText);
            error_log("Full prompt length: " . strlen($fullPrompt));
        }

        // ------------------------------------------------
        try {
            // Use Triton streaming inference for sorting (collect all output)
            $answer = self::streamInference($fullPrompt, 512, false); // false = collect all output
            
            if ($GLOBALS["debug"]) {
                error_log("=== TRITON SORTING DEBUG: Response Success ===");
                error_log("Response received successfully");
            }
            
        } catch (Exception $err) {
            if($GLOBALS["debug"]) {
                error_log("=== TRITON SORTING DEBUG: API Error Details ===");
                error_log("Error type: " . get_class($err));
                error_log("Error message: " . $err->getMessage());
                error_log("Error code: " . $err->getCode());
                error_log("Error file: " . $err->getFile() . ":" . $err->getLine());
                error_log("Stack trace: " . $err->getTraceAsString());
            }
            return "*API sorting Error - Triton error: * " . $err->getMessage();
        }
        
        // Enhanced DEBUG: Log raw response before parsing (only if debug enabled)
        if ($GLOBALS["debug"]) {
            error_log("=== TRITON SORTING DEBUG: Raw Response Analysis ===");
            error_log("Response type: " . gettype($answer));
            error_log("Response length: " . strlen($answer));
            error_log("Response preview (first 200 chars): " . substr($answer, 0, 200));
        }
        
        // ------------------------------------------------
        // Clean and return response
        if (empty($answer)) {
            if ($GLOBALS["debug"]) {
                error_log("=== TRITON SORTING DEBUG: Empty Response Error ===");
                error_log("Response is empty");
            }
            return "*API sorting Error - Empty response from Triton API*";
        }

        if ($GLOBALS["debug"]) {
            error_log("=== TRITON SORTING DEBUG: Content Processing ===");
            error_log("Raw answer length: " . strlen($answer));
            error_log("Raw answer: " . $answer);
        }

        // Clean JSON response - only remove code fences, don't extract BTEXT
        $answer = str_replace("```json\n", "", $answer);
        $answer = str_replace("\n```", "", $answer);
        $answer = str_replace("```json", "", $answer);
        $answer = str_replace("```", "", $answer);
        $answer = trim($answer);
        
        if ($GLOBALS["debug"]) {
            error_log("=== TRITON SORTING DEBUG: Final Result ===");
            error_log("Final answer: " . $answer);
            error_log("Answer type: " . gettype($answer));
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
                if ($GLOBALS["debug"]) {
                    error_log("=== TRITON TOPIC DEBUG: Starting streaming mode ===");
                    error_log("Model: " . $myModel);
                    error_log("Prompt length: " . strlen($fullPrompt));
                }
                
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

                        // LOG: Raw protobuf response analysis
                        if ($GLOBALS["debug"]) {
                            error_log("=== TRITON PROTOBUF LOG: Analyzing response ===");
                            $rawContents = $inferResponse->getRawOutputContents();
                            error_log("Raw contents count: " . count($rawContents));
                            if (!empty($rawContents)) {
                                for ($i = 0; $i < count($rawContents); $i++) {
                                    error_log("Raw content $i length: " . strlen($rawContents[$i]));
                                    error_log("Raw content $i hex: " . bin2hex(substr($rawContents[$i], 0, 20)));
                                }
                            }
                            $outputs = $inferResponse->getOutputs();
                            error_log("Structured outputs count: " . count($outputs));
                            foreach ($outputs as $output) {
                                error_log("Output name: " . $output->getName());
                                $contents = $output->getContents();
                                if ($contents) {
                                    $bytesContents = $contents->getBytesContents();
                                    $boolContents = $contents->getBoolContents();
                                    $intContents = $contents->getIntContents();
                                    $floatContents = $contents->getFp32Contents();
                                    error_log("  Bytes contents count: " . count($bytesContents));
                                    error_log("  Bool contents count: " . count($boolContents));
                                    error_log("  Int contents count: " . count($intContents));
                                    error_log("  Float contents count: " . count($floatContents));
                                    if (!empty($bytesContents)) {
                                        error_log("  First bytes content length: " . strlen($bytesContents[0]));
                                        error_log("  First bytes content hex: " . bin2hex(substr($bytesContents[0], 0, 20)));
                                        error_log("  First bytes content as string: " . $bytesContents[0]);
                                    }
                                    if (!empty($boolContents)) {
                                        error_log("  First bool content: " . ($boolContents[0] ? 'true' : 'false'));
                                    }
                                    if (!empty($intContents)) {
                                        error_log("  First int content: " . $intContents[0]);
                                    }
                                } else {
                                    error_log("  Contents is null");
                                }
                            }
                        }

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
                                // LOG: Try to decode protobuf data
                                if ($GLOBALS["debug"]) {
                                    error_log("=== TRITON PROTOBUF DECODE: Attempting to decode raw contents ===");
                                    for ($i = 0; $i < count($rawContents); $i++) {
                                        error_log("Raw content $i full hex: " . bin2hex($rawContents[$i]));
                                        // Try to decode as length-prefixed protobuf (little-endian)
                                        if (strlen($rawContents[$i]) >= 4) {
                                            $length = unpack('V', substr($rawContents[$i], 0, 4))[1]; // V = little-endian unsigned long
                                            error_log("  Decoded length: $length");
                                            if ($length > 0 && $length <= strlen($rawContents[$i]) - 4) {
                                                $decoded = substr($rawContents[$i], 4, $length);
                                                error_log("  Decoded content: " . $decoded);
                                                error_log("  Decoded hex: " . bin2hex($decoded));
                                            }
                                        }
                                    }
                                }
                                
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
                            // LOG: Raw chunk received from Triton
                            if ($GLOBALS["debug"]) {
                                error_log("=== TRITON STREAM LOG: Raw chunk received ===");
                                error_log("Chunk length: " . strlen($textChunk));
                                error_log("Chunk content: " . $textChunk);
                                error_log("Chunk hex (first 50 bytes): " . bin2hex(substr($textChunk, 0, 50)));
                                error_log("Chunk is valid UTF-8: " . (mb_check_encoding($textChunk, 'UTF-8') ? 'YES' : 'NO'));
                            }
                            
                            $answer .= $textChunk;
                            $pendingText .= $textChunk;
                            
                            // LOG: Before sending to frontend
                            if ($GLOBALS["debug"]) {
                                error_log("=== TRITON STREAM LOG: Before frontend stream ===");
                                error_log("Pending text length: " . strlen($pendingText));
                                error_log("Pending text content: " . $pendingText);
                                error_log("Pending text hex (first 50 bytes): " . bin2hex(substr($pendingText, 0, 50)));
                            }
                            
                            // Throttle ultra-small deltas (whitespace-only)
                            if (trim($pendingText) !== '' || strlen($pendingText) > 10) {
                                Frontend::statusToStream($msgArr["BID"], 'ai', $pendingText);
                                
                                // LOG: After sending to frontend
                                if ($GLOBALS["debug"]) {
                                    error_log("=== TRITON STREAM LOG: Sent to frontend ===");
                                    error_log("Sent text: " . $pendingText);
                                }
                                
                                $pendingText = '';
                            }
                        }

                        // Check if this is the final chunk
                        if ($isFinal) {
                            break;
                        }
                    }
                    
                    if ($GLOBALS["debug"]) {
                        error_log("=== TRITON TOPIC DEBUG: Streaming completed ===");
                        error_log("Final answer length: " . strlen($answer));
                    }
                    
                    // Flush any remaining pending text
                    if (!empty($pendingText)) {
                        Frontend::statusToStream($msgArr["BID"], 'ai', $pendingText);
                    }
                    
                } catch (Exception $streamErr) {
                    if ($GLOBALS["debug"]) {
                        error_log("=== TRITON TOPIC DEBUG: Streaming Exception ===");
                        error_log("Error type: " . get_class($streamErr));
                        error_log("Error message: " . $streamErr->getMessage());
                        error_log("Error code: " . $streamErr->getCode());
                        error_log("Error file: " . $streamErr->getFile() . ":" . $streamErr->getLine());
                        error_log("Stack trace: " . $streamErr->getTraceAsString());
                    }
                    return "*API topic Error - Streaming failed: " . $streamErr->getMessage();
                }
                
                // Graceful completion fallback
                if (empty($answer)) {
                    return "*API topic Error - Streaming completed with no content";
                }
                
                // LOG: Final answer before processing
                if ($GLOBALS["debug"]) {
                    error_log("=== TRITON STREAM LOG: Final answer processing ===");
                    error_log("Final answer length: " . strlen($answer));
                    error_log("Final answer content: " . $answer);
                    error_log("Final answer hex (first 100 bytes): " . bin2hex(substr($answer, 0, 100)));
                    error_log("Final answer is valid UTF-8: " . (mb_check_encoding($answer, 'UTF-8') ? 'YES' : 'NO'));
                }
                
                // After streaming completes: if the full content is a JSON object, extract BTEXT
                $finalText = $answer;
                $maybeBTEXT = self::extractBTEXTFromJsonString($answer);
                if ($maybeBTEXT !== null) {
                    $finalText = $maybeBTEXT;
                    
                    // LOG: After JSON extraction
                    if ($GLOBALS["debug"]) {
                        error_log("=== TRITON STREAM LOG: After JSON extraction ===");
                        error_log("Extracted BTEXT: " . $finalText);
                        error_log("Extracted BTEXT hex (first 100 bytes): " . bin2hex(substr($finalText, 0, 100)));
                    }
                }
                
                // No encoding processing - use raw output like working demo
                
                // LOG: Final text being returned
                if ($GLOBALS["debug"]) {
                    error_log("=== TRITON STREAM LOG: Final text being returned ===");
                    error_log("Final text length: " . strlen($finalText));
                    error_log("Final text content: " . $finalText);
                    error_log("Final text hex (first 100 bytes): " . bin2hex(substr($finalText, 0, 100)));
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
            
            // DEBUG: Log raw response before parsing (only if debug enabled)
            if ($GLOBALS["debug"]) {
                error_log("=== TRITON TOPIC DEBUG: Raw response structure ===");
                error_log("Response length: " . strlen($answer));
                error_log("Response preview: " . substr($answer, 0, 200));
            }
            
        } catch (Exception $err) {
            if ($GLOBALS["debug"]) {
                error_log("=== TRITON TOPIC DEBUG: Exception Details ===");
                error_log("Error type: " . get_class($err));
                error_log("Error message: " . $err->getMessage());
                error_log("Error code: " . $err->getCode());
                error_log("Error file: " . $err->getFile() . ":" . $err->getLine());
                error_log("Stack trace: " . $err->getTraceAsString());
                error_log("Stream mode: " . ($stream ? 'true' : 'false'));
                error_log("Model: " . $myModel);
            }
            if ($stream) {
                return "*API topic Error - Streaming failed: " . $err->getMessage();
            }
            return "*APItopic Error - Triton error: * " . $err->getMessage();
        }

        // LOG: Non-streaming answer before processing
        if ($GLOBALS["debug"]) {
            error_log("=== TRITON NON-STREAM LOG: Answer before processing ===");
            error_log("Answer length: " . strlen($answer));
            error_log("Answer content: " . $answer);
            error_log("Answer hex (first 100 bytes): " . bin2hex(substr($answer, 0, 100)));
            error_log("Answer is valid UTF-8: " . (mb_check_encoding($answer, 'UTF-8') ? 'YES' : 'NO'));
        }
        
        // Non-streaming: if JSON came back, extract BTEXT before output
        $maybeBTEXT = self::extractBTEXTFromJsonString($answer);
        if ($maybeBTEXT !== null) {
            $answer = $maybeBTEXT;
            
            // LOG: After JSON extraction
            if ($GLOBALS["debug"]) {
                error_log("=== TRITON NON-STREAM LOG: After JSON extraction ===");
                error_log("Extracted BTEXT: " . $answer);
                error_log("Extracted BTEXT hex (first 100 bytes): " . bin2hex(substr($answer, 0, 100)));
            }
        }
        
        // LOG: Final answer being returned
        if ($GLOBALS["debug"]) {
            error_log("=== TRITON NON-STREAM LOG: Final answer being returned ===");
            error_log("Final answer length: " . strlen($answer));
            error_log("Final answer content: " . $answer);
            error_log("Final answer hex (first 100 bytes): " . bin2hex(substr($answer, 0, 100)));
        }
        
        // No encoding processing - use raw output like working demo
        
        // DEBUG: Log final answer (only if debug enabled)
        if ($GLOBALS["debug"]) {
            error_log("=== TRITON TOPIC DEBUG: Final answer (no parsing) ===");
            error_log("Final answer: " . $answer);
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
                        // LOG: Try to decode protobuf data in streamInference
                        if ($GLOBALS["debug"]) {
                            error_log("=== TRITON STREAMINFERENCE PROTOBUF DECODE: Attempting to decode raw contents ===");
                            for ($i = 0; $i < count($rawContents); $i++) {
                                error_log("Raw content $i full hex: " . bin2hex($rawContents[$i]));
                                // Try to decode as length-prefixed protobuf (little-endian)
                                if (strlen($rawContents[$i]) >= 4) {
                                    $length = unpack('V', substr($rawContents[$i], 0, 4))[1]; // V = little-endian unsigned long
                                    error_log("  Decoded length: $length");
                                    if ($length > 0 && $length <= strlen($rawContents[$i]) - 4) {
                                        $decoded = substr($rawContents[$i], 4, $length);
                                        error_log("  Decoded content: " . $decoded);
                                        error_log("  Decoded hex: " . bin2hex($decoded));
                                    }
                                }
                            }
                        }
                        
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
                    // LOG: Chunk received in streamInference
                    if ($GLOBALS["debug"]) {
                        error_log("=== TRITON STREAMINFERENCE LOG: Chunk received ===");
                        error_log("Chunk length: " . strlen($textChunk));
                        error_log("Chunk content: " . $textChunk);
                        error_log("Chunk hex (first 50 bytes): " . bin2hex(substr($textChunk, 0, 50)));
                        error_log("Chunk is valid UTF-8: " . (mb_check_encoding($textChunk, 'UTF-8') ? 'YES' : 'NO'));
                    }
                    
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

        // LOG: Final answer from streamInference
        if ($GLOBALS["debug"]) {
            error_log("=== TRITON STREAMINFERENCE LOG: Final answer ===");
            error_log("Final answer length: " . strlen($answer));
            error_log("Final answer content: " . $answer);
            error_log("Final answer hex (first 100 bytes): " . bin2hex(substr($answer, 0, 100)));
            error_log("Final answer is valid UTF-8: " . (mb_check_encoding($answer, 'UTF-8') ? 'YES' : 'NO'));
        }
        
        // No encoding processing - use raw output like working demo
        
        return $answer;
    }
    
    /**
     * Fix text encoding issues from Triton server
     * 
     * @param string $text The potentially corrupted text
     * @return string The fixed text
     */
    private static function fixTextEncoding(string $text): string {
        if (empty($text)) {
            return $text;
        }
        
        // Debug: Log original text for analysis
        if ($GLOBALS["debug"]) {
            error_log("Original text (first 100 chars): " . substr($text, 0, 100));
            error_log("Original text hex: " . bin2hex(substr($text, 0, 50)));
        }
        
        // Try different encoding approaches
        $attempts = [
            // 1. Check if already valid UTF-8
            function($t) { return mb_check_encoding($t, 'UTF-8') ? $t : null; },
            
            // 2. Convert from auto-detected encoding to UTF-8
            function($t) { return mb_convert_encoding($t, 'UTF-8', 'auto'); },
            
            // 3. Try common encodings
            function($t) { return mb_convert_encoding($t, 'UTF-8', 'ISO-8859-1'); },
            function($t) { return mb_convert_encoding($t, 'UTF-8', 'Windows-1252'); },
            function($t) { return mb_convert_encoding($t, 'UTF-8', 'ASCII'); },
            
            // 4. Try to clean up common corruption patterns
            function($t) {
                // Remove null bytes and control characters
                $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $t);
                return mb_convert_encoding($cleaned, 'UTF-8', 'auto');
            },
            
            // 5. Try to fix UTF-8 sequences that might be corrupted
            function($t) {
                // Replace invalid UTF-8 sequences
                $fixed = mb_convert_encoding($t, 'UTF-8', 'UTF-8');
                return $fixed;
            },
            
            // 6. Last resort: force UTF-8 with replacement characters
            function($t) {
                return mb_convert_encoding($t, 'UTF-8', 'UTF-8');
            }
        ];
        
        foreach ($attempts as $index => $attempt) {
            try {
                $result = $attempt($text);
                if ($result !== null && $result !== false && mb_check_encoding($result, 'UTF-8')) {
                    if ($GLOBALS["debug"]) {
                        error_log("Encoding fix attempt " . ($index + 1) . " succeeded");
                        error_log("Fixed text (first 100 chars): " . substr($result, 0, 100));
                    }
                    return $result;
                }
            } catch (Exception $e) {
                if ($GLOBALS["debug"]) {
                    error_log("Encoding fix attempt " . ($index + 1) . " failed: " . $e->getMessage());
                }
                continue;
            }
        }
        
        // If all attempts fail, return the original text
        if ($GLOBALS["debug"]) {
            error_log("All encoding fix attempts failed, returning original text");
        }
        return $text;
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
