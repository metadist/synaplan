<?php

use ArdaGnsrn\Ollama\Ollama;

class AIOllama {
    private static $host;
    private static $client;

    public static function init(): void {
        $myServer = ApiKeys::get("OLLAMA_SERVER");
        self::$host = 'http://'.$myServer;
        self::$client = Ollama::client(self::$host);
    }

    /**
     * Message sorting prompt handler
     * 
     * Analyzes and categorizes incoming messages to determine their intent and
     * appropriate handling method using local Ollama.
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
        $fullPrompt .= "\nCurrent message to analyze: " . $msgText;

        // which model on ollama?
        $myModel = $GLOBALS["AI_SORT"]["MODEL"];
        
        try {
            $completions = $client->completions()->create([
                'model' => $myModel,
                'prompt' => $fullPrompt,
            ]);
            
            $answer = $completions->response;
        } catch (Exception $err) {
            return "*API sorting Error - Ollama error: * " . $err->getMessage();
        }

        // Clean and return response
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
     * Generates responses based on the specific topic of the message using local Ollama.
     * 
     * @param array $msgArr Message array containing topic information
     * @param array $threadArr Thread context for conversation history
     * @param bool $stream Whether to use streaming mode
     * @return array|string|bool Topic-specific response or error message
     */
    public static function topicPrompt($msgArr, $threadArr, $stream = false): array|string|bool {
        $systemPrompt = BasicAI::getAprompt($msgArr['BTOPIC'], $msgArr['BLANG'], $msgArr, true);

        if(isset($systemPrompt['TOOLS'])) {
            // call tools before the prompt is executed!
        }
        
        $client = self::$client;
        
        // which model on ollama?
        $myModel = $GLOBALS["AI_CHAT"]["MODEL"];
        
        try {
            if ($stream) {
                // Use streaming mode - simplified prompt for streaming
                $fullPrompt = 'You are the Synaplan.com AI assistant. Please answer in the language of the user.' . "\n\n";
                
                // Add conversation history
                $fullPrompt .= "Conversation History:\n";
                foreach($threadArr as $msg) {
                    $fullPrompt .= "[".$msg['BID']."] ".$msg['BTEXT'] . "\n";
                }

                // Add current message
                $msgText = $msgArr['BTEXT'];
                if(strlen($msgArr['BFILETEXT']) > 1) {
                    $msgText .= "\n\n\n---\n\n\nUser provided a file: ".$msgArr['BFILETYPE'].", saying: '".$msgArr['BFILETEXT']."'\n\n";
                }
                $fullPrompt .= "\nCurrent message: " . $msgText;

                // different model configured?
                if(isset($systemPrompt['SETTINGS'])) {
                    foreach($systemPrompt['SETTINGS'] as $setting) {
                        $systemPrompt[$setting['BTOKEN']] = $setting['BVALUE'];
                    }
                    if(isset($systemPrompt['aiModel']) AND intval($systemPrompt['aiModel']) > 0) {
                        $modelArr = BasicAI::getModelDetails(intval($systemPrompt['aiModel']));
                        // Use BPROVID if available, fallback to BNAME, then to global model
                        if (!empty($modelArr) && is_array($modelArr)) {
                            $myModel = !empty($modelArr['BPROVID']) ? $modelArr['BPROVID'] : 
                                      (!empty($modelArr['BNAME']) ? $modelArr['BNAME'] : $GLOBALS["AI_CHAT"]["MODEL"]);
                        } else {
                            $myModel = $GLOBALS["AI_CHAT"]["MODEL"];
                        }
                    } else {
                        $myModel = $GLOBALS["AI_CHAT"]["MODEL"];
                    }
                } else {
                    $myModel = $GLOBALS["AI_CHAT"]["MODEL"];
                }

                $completions = $client->completions()->createStreamed([
                    'model' => $myModel,
                    'prompt' => $fullPrompt,
                ]);

                $answer = '';
                
                foreach ($completions as $completion) {
                    $textChunk = $completion->response;
                    
                    // Only stream non-empty chunks
                    if (!empty($textChunk)) {
                        $answer .= $textChunk;
                        // Stream the chunk to frontend
                        Frontend::statusToStream($msgArr["BID"], 'ai', $textChunk);
                    }
                }
                
                // Return a different way to the rest of the process
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

                // avoid double output to the chat window
                $arrAnswer['ALREADYSHOWN'] = true;

                return $arrAnswer;
                
            } else {
                // Use non-streaming mode (existing logic)
                // Build the complete prompt with system context and message history
                $fullPrompt = $systemPrompt['BPROMPT'] . "\n\n";
                
                // Add conversation history
                $fullPrompt .= "Conversation History:\n";
                foreach($threadArr as $msg) {
                    $fullPrompt .= "[".$msg['BID']."] ".$msg['BTEXT'] . "\n";
                }

                // Add current message
                $msgText = json_encode($msgArr,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $fullPrompt .= "\nCurrent message: " . $msgText;

                $completions = $client->completions()->create([
                    'model' => $myModel,
                    'prompt' => $fullPrompt,
                ]);
                
                $answer = $completions->response;
            }
        } catch (Exception $err) {
            return "*API topic Error - Ollama error: * " . $err->getMessage();
        }

        // Clean response (only for non-streaming mode)
        if (!$stream) {
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
                    return "*API topic Error - Ollama error: * " . $err->getMessage();
                }    
            }

            return $arrAnswer;
        }
        return [];
    }

    /**
     * Welcome message generator
     * 
     * Creates a personalized welcome message for new users using local Ollama.
     * 
     * @param array $msgArr Message array containing user information
     * @return array|string|bool Welcome message or error message
     */
    public static function welcomePrompt($msgArr): array|string|bool {
        $arrPrompt = BasicAI::getAprompt('tools:help');
        $systemPrompt = $arrPrompt['BPROMPT'];
        
        $client = self::$client;

        $fullPrompt = $systemPrompt . "\n\n";
        $msgText = '{"BCOMMAND":"/list","BLANG":"'.$msgArr['BLANG'].'"}';
        $fullPrompt .= "User message: " . $msgText;
        
        // which model on groq?
        $myModel = $GLOBALS["AI_CHAT"]["MODEL"];
        
        try {
            $completions = $client->completions()->create([
                'model' => $myModel,
                'prompt' => $fullPrompt,
            ]);
            
            return $completions->response;
        } catch (Exception $err) {
            return "*API welcome Error - Ollama error: * " . $err->getMessage();
        }
    }

    // ****************************************************************
    // local vision AI
    // ****************************************************************
    public static function explainImage($arrMessage): array|string|bool {
        // Handle large images
        if(filesize(rtrim(UPLOAD_DIR, '/').'/'.$arrMessage['BFILEPATH']) > intval(1024*1024*3.5)) {
            $imageFile = Tools::giveSmallImage($arrMessage['BFILEPATH'], false, 1200);
            $tmpAbs = rtrim(UPLOAD_DIR, '/').'/'."tmp_del_".$arrMessage['BID'].".png";
            $savedFile = imagepng($imageFile, $tmpAbs);
            chmod($tmpAbs, 0755);
            $imagePath = $tmpAbs;
        } else {
            $imagePath = rtrim(UPLOAD_DIR, '/').'/'.$arrMessage['BFILEPATH'];
        }

        $myImage = base64_encode(file_get_contents($imagePath));
        $client = self::$client;
        
        // Use the global prompt if available, otherwise use default
        $imgPrompt = isset($GLOBALS["AI_PIC2TEXT"]["PROMPT"]) 
            ? $GLOBALS["AI_PIC2TEXT"]["PROMPT"] 
            : 'Describe this image in detail. Be comprehensive and accurate.';
            
        if($arrMessage['BTEXT'] != '') {
            $imgPrompt .= ' User requested: '.$arrMessage['BTEXT'];
        }

        // which model on ollama?
        
        try {
            $completions = $client->completions()->create([
                'model' => $$GLOBALS["AI_PIC2TEXT"]["MODEL"],
                'prompt' => $imgPrompt,
                'images' => [$myImage],
            ]); 

            $arrRes = $completions->toArray();
            $arrMessage['BFILETEXT'] = $arrRes['response'];
        } catch (Exception $err) {
            $arrMessage['BFILETEXT'] = "*API Image Error - Ollama error: * " . $err->getMessage();
        }
        
        return $arrMessage;
    }

    /**
     * Audio to text converter
     * 
     * Note: Ollama doesn't have built-in audio transcription capabilities like Groq.
     * This method returns an error message indicating the limitation.
     * 
     * @param array $arrMessage Message array containing audio file information
     * @return array|string|bool Error message indicating unsupported feature
     */
    public static function mp3ToText($arrMessage): array|string|bool {
        // Ollama doesn't support audio transcription natively
        // You would need to use a separate service like OpenAI Whisper or similar
        return "Audio transcription is not supported by Ollama. Please use external transcription service.";
    }

    /**
     * Text translator
     * 
     * Translates text content to a specified language using local Ollama.
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

        $client = self::$client;

        $tPrompt = BasicAI::getAprompt('tools:lang');
        $fullPrompt = $tPrompt . "\n\nUser message: " . $qTerm;

        // which model on ollama?
        $myModel = $GLOBALS["AI_CHAT"]["MODEL"];
        
        try {
            $completions = $client->completions()->create([
                'model' => $myModel,
                'prompt' => $fullPrompt,
            ]);
            
            $msgArr[$sourceText] = $completions->response;
        } catch (Exception $err) {
            $msgArr['BTEXT'] = "*API translate Error - Ollama error: * " . $err->getMessage();
        }

        return $msgArr;
    }

    /**
     * Summarize text using local Ollama
     * 
     * Summarizes a given text using Ollama's summarization capabilities.
     * 
     * @param string $text Text to summarize
     * @return string Summarized text
     */
    public static function summarizePrompt($text): string {
        $client = self::$client;
        // which model on ollama?
        $myModel = $GLOBALS["AI_CHAT"]["MODEL"];
        
        $prompt = 'You summarize the text of the user to a short 2-3 sentence summary. Do not add any other text, just the essence of the text. Stay under 128 characters. Answer in the language of the text.\n\nText to summarize: ' . $text;
        
        try {
            $completions = $client->completions()->create([
                'model' => $myModel,
                'prompt' => $prompt,
            ]);
            
            return $completions->response;
        } catch (Exception $err) {
            return "*API summarize Error - Ollama error: * " . $err->getMessage();
        }
    }

    // ****************************************************************************************************** 
    // transfor the message to vector
    // ****************************************************************************************************** 
    public static function embed($text) {
        $vecclient = self::$client;
        try {
            $embeds = $vecclient->embed()->create([
                'model' => $GLOBALS["AI_VECTORIZE"]["MODEL"],
                'input' => [ $text ]
            ]);
            // Best-effort extraction to array
            $arrRes = method_exists($embeds, 'toArray') ? $embeds->toArray() : (array) $embeds;
            if (isset($arrRes['embeddings'][0]) && is_array($arrRes['embeddings'][0])) {
                return $arrRes['embeddings'][0];
            }
        } catch (\Throwable $e) {
            if (!empty($GLOBALS['debug'])) error_log('AIOllama::embed failed: ' . $e->getMessage());
        }
        // On failure, return empty to let callers gracefully skip RAG
        return [];
    }

    // ****************************************************************************************************** 
    // nicefy the text with a LLM locally
    // ****************************************************************************************************** 
    public static function nicefy($text, $lang) {
        $client = self::$client;    
        // which model on ollama?
        $myModel = $GLOBALS["AI_CHAT"]["MODEL"];
        
        $completions = $client->completions()->create([
            'model' => $myModel,
            'prompt' => 'Translate the following text into language "'.$lang.'". Try to improve the text, if you see typos: '.$text
        ]);
        return $completions->response;
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
        
        // Build the complete prompt with system context and user input
        $fullPrompt = $systemPrompt . "\n\n" . $userPrompt;
        
        // which model on ollama?
        $myModel = $GLOBALS["AI_CHAT"]["MODEL"];
        
        try {
            $completions = $client->completions()->create([
                'model' => $myModel,
                'prompt' => $fullPrompt,
            ]);
            
            $result = $completions->response;
            
            return [
                'success' => true,
                'summary' => $result
            ];
            
        } catch (Exception $err) {
            return [
                'success' => false,
                'summary' => "*API Simple Prompt Error - Ollama error: * " . $err->getMessage()
            ];
        }
    }
}

// ****************************************************************************************************** 
AIOllama::init();
