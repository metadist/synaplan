<?php

class OpenAICompatController {
    // ---------------------------- Public entrypoints
    public static function chatCompletions(array $req, bool $stream = false) {
        $resolution = self::resolveModel($req['model'] ?? '');
        if (!$resolution['ok']) {
            self::sendError(400, $resolution['error'] ?? 'Invalid model');
            return;
        }

        self::applyChatGlobals($resolution);

        $messages = is_array($req['messages'] ?? null) ? $req['messages'] : [];
        if (count($messages) === 0) {
            self::sendError(400, 'messages array required');
            return;
        }

        $lang = $req['language'] ?? 'en';
        $userId = $_SESSION['USERPROFILE']['BID'] ?? 0;
        $trackId = $_SESSION['USERPROFILE']['BID'] ?? 0;

        [$msgArr, $threadArr] = self::buildMsgAndThread($messages, $lang, $userId, $trackId);

        $serviceClass = $resolution['serviceClass'];

        if ($stream) {
            // Minimal SSE emulation by chunking non-stream result for now
            $result = $serviceClass::topicPrompt($msgArr, $threadArr, false);
            $text = self::normalizeText($result, $msgArr);
            self::streamChatChunks($text, $req['model'] ?? ($resolution['modelName'] ?? 'unknown'));
            return;
        }

        $result = $serviceClass::topicPrompt($msgArr, $threadArr, false);
        $text = self::normalizeText($result, $msgArr);

        $resp = [
            'id' => 'chatcmpl_' . bin2hex(random_bytes(8)),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $req['model'] ?? ($resolution['modelName'] ?? 'unknown'),
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => $text
                ],
                'finish_reason' => 'stop'
            ]]
        ];
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($resp);
    }

    public static function imageGenerations(array $req) {
        $resolution = self::resolveModel($req['model'] ?? '');
        if (!$resolution['ok']) {
            self::sendError(400, $resolution['error'] ?? 'Invalid model');
            return;
        }
        self::applyPicGlobals($resolution);

        $prompt = trim((string)($req['prompt'] ?? ''));
        if ($prompt === '') {
            self::sendError(400, 'prompt required');
            return;
        }

        $userId = $_SESSION['USERPROFILE']['BID'] ?? 0;
        $msgArr = [
            'BID' => 0,
            'BUSERID' => $userId,
            'BTEXT' => '/pic ' . $prompt,
            'BLANG' => $req['language'] ?? 'en',
            'BDIRECT' => 'IN',
            'BFILE' => 0,
            'BFILEPATH' => '',
            'BFILETYPE' => '',
            'BFILETEXT' => ''
        ];

        $serviceClass = $resolution['serviceClass'];
        $outArr = $serviceClass::picPrompt($msgArr, false);
        if (!(is_array($outArr) && !empty($outArr['BFILE']) && !empty($outArr['BFILEPATH']))) {
            self::sendError(500, 'Image generation failed');
            return;
        }

        $fullPath = __DIR__ . '/../' . 'up/' . $outArr['BFILEPATH'];
        if (!is_file($fullPath)) {
            self::sendError(500, 'Generated image not found');
            return;
        }
        $b64 = base64_encode(file_get_contents($fullPath));

        $resp = [
            'created' => time(),
            'data' => [[ 'b64_json' => $b64 ]]
        ];
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($resp);
    }

    public static function listModels() {
        $rows = BasicAI::getAllModels();
        $data = [];
        foreach ($rows as $row) {
            $id = $row['BPROVID'] ?? '';
            if ($id === '') { $id = $row['BNAME'] ?? ('model-' . ($row['BID'] ?? '')); }
            $data[] = [
                'id' => $id,
                'object' => 'model',
                'owned_by' => $row['BSERVICE'] ?? '',
                'created' => 0,
                'metadata' => $row
            ];
        }
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['data' => $data]);
    }

    public static function audioTranscriptions() {
        $model = $_REQUEST['model'] ?? '';
        $resolution = self::resolveModel($model);
        if (!$resolution['ok']) {
            self::sendError(400, $resolution['error'] ?? 'Invalid model');
            return;
        }
        self::applyAudioGlobals($resolution);

        if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            self::sendError(400, 'file upload required (multipart/form-data)');
            return;
        }

        $relPath = self::saveUploadedFile($_FILES['file']);
        if ($relPath === '') {
            self::sendError(500, 'failed to save uploaded file');
            return;
        }

        $userId = $_SESSION['USERPROFILE']['BID'] ?? 0;
        $msgArr = [
            'BID' => 0,
            'BUSERID' => $userId,
            'BTEXT' => '',
            'BLANG' => 'en',
            'BDIRECT' => 'IN',
            'BFILE' => 1,
            'BFILEPATH' => $relPath,
            'BFILETYPE' => pathinfo($relPath, PATHINFO_EXTENSION)
        ];

        $serviceClass = $resolution['serviceClass'];
        $text = $serviceClass::mp3ToText($msgArr);
        if (!is_string($text)) { $text = (string)$text; }
        header('Content-Type: text/plain; charset=UTF-8');
        echo $text;
    }

    public static function imageAnalysis() {
        $model = $_REQUEST['model'] ?? '';
        $resolution = self::resolveModel($model);
        if (!$resolution['ok']) {
            self::sendError(400, $resolution['error'] ?? 'Invalid model');
            return;
        }
        self::applyVisionGlobals($resolution);

        if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            self::sendError(400, 'file upload required (multipart/form-data)');
            return;
        }
        $relPath = self::saveUploadedFile($_FILES['file']);
        if ($relPath === '') {
            self::sendError(500, 'failed to save uploaded file');
            return;
        }

        $userId = $_SESSION['USERPROFILE']['BID'] ?? 0;
        $msgArr = [
            'BID' => 0,
            'BUSERID' => $userId,
            'BTEXT' => '',
            'BLANG' => 'en',
            'BDIRECT' => 'IN',
            'BFILE' => 1,
            'BFILEPATH' => $relPath,
            'BFILETYPE' => pathinfo($relPath, PATHINFO_EXTENSION)
        ];

        $serviceClass = $resolution['serviceClass'];
        $outArr = $serviceClass::explainImage($msgArr);
        $desc = '';
        if (is_array($outArr) && isset($outArr['BFILETEXT'])) { $desc = $outArr['BFILETEXT']; }
        elseif (is_string($outArr)) { $desc = $outArr; }
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['text' => $desc]);
    }

    // ---------------------------- Helpers
    private static function buildMsgAndThread(array $messages, string $lang, int $userId, int $trackId): array {
        $threadArr = [];
        $lastUser = '';
        foreach ($messages as $m) {
            $role = $m['role'] ?? 'user';
            $content = $m['content'] ?? '';
            if (is_array($content)) {
                // If OpenAI array content, concatenate text parts; ignore images for v1
                $parts = [];
                foreach ($content as $part) {
                    if (($part['type'] ?? '') === 'text' && isset($part['text'])) { $parts[] = $part['text']; }
                }
                $content = implode("\n", $parts);
            }
            $content = (string)$content;
            if ($role === 'system') { continue; }
            $threadArr[] = [
                'BDIRECT' => ($role === 'assistant') ? 'OUT' : 'IN',
                'BTEXT' => $content,
                'BDATETIME' => date('YmdHis')
            ];
            if ($role === 'user') { $lastUser = $content; }
        }

        $msgArr = [
            'BID' => 0,
            'BUSERID' => $userId,
            'BTRACKID' => $trackId,
            'BMESSTYPE' => 'API',
            'BDIRECT' => 'IN',
            'BTOPIC' => 'general',
            'BLANG' => $lang,
            'BTEXT' => $lastUser,
            'BFILE' => 0,
            'BFILEPATH' => '',
            'BFILETEXT' => ''
        ];
        return [$msgArr, $threadArr];
    }

    private static function normalizeText($result, $fallbackMsgArr): string {
        if (is_string($result)) { return $result; }
        if (is_array($result) && isset($result['BTEXT'])) { return (string)$result['BTEXT']; }
        return '';
    }

    private static function streamChatChunks(string $text, string $model) {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        @ob_end_flush();
        @ob_implicit_flush(true);

        $id = 'chatcmpl_' . bin2hex(random_bytes(6));
        $now = time();

        $first = [
            'id' => $id,
            'object' => 'chat.completion.chunk',
            'created' => $now,
            'model' => $model,
            'choices' => [[ 'index' => 0, 'delta' => [ 'role' => 'assistant' ] ]]
        ];
        echo 'data: ' . json_encode($first) . "\n\n";
        flush();

        $chunks = str_split($text, 200);
        foreach ($chunks as $chunk) {
            $payload = [
                'id' => $id,
                'object' => 'chat.completion.chunk',
                'created' => $now,
                'model' => $model,
                'choices' => [[ 'index' => 0, 'delta' => [ 'content' => $chunk ] ]]
            ];
            echo 'data: ' . json_encode($payload) . "\n\n";
            flush();
        }
        echo "data: [DONE]\n\n";
        flush();
    }

    private static function sendError(int $status, string $message, string $type = 'invalid_request_error', string $code = ''): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => [
            'message' => $message,
            'type' => $type,
            'code' => $code
        ]]);
    }

    private static function resolveModel(string $model): array {
        $model = trim($model);
        if ($model === '') {
            // Fallback to existing globals
            return [
                'ok' => true,
                'serviceClass' => $GLOBALS['AI_CHAT']['SERVICE'] ?? 'AIOpenAI',
                'modelName' => $GLOBALS['AI_CHAT']['MODEL'] ?? 'gpt-4o',
                'modelId' => $GLOBALS['AI_CHAT']['MODELID'] ?? 0
            ];
        }

        // Fast-path: provider/model
        if (strpos($model, '/') !== false) {
            [$prov, $name] = explode('/', $model, 2);
            $map = [
                'openai' => 'AIOpenAI',
                'ollama' => 'AIOllama',
                'google' => 'AIGoogle',
                'anthropic' => 'AIAnthropic'
            ];
            $provKey = strtolower(trim($prov));
            if (!isset($map[$provKey])) {
                return ['ok' => false, 'error' => 'Unknown provider'];
            }
            return [
                'ok' => true,
                'serviceClass' => $map[$provKey],
                'modelName' => trim($name),
                'modelId' => 0
            ];
        }

        // Lookup by DB rows (BMODELS)
        $models = BasicAI::getAllModels();
        foreach ($models as $row) {
            $matches = false;
            $m = strtolower($model);
            if (strtolower($row['BPROVID'] ?? '') === $m) { $matches = true; }
            if (!$matches && strtolower($row['BNAME'] ?? '') === $m) { $matches = true; }
            if (!$matches && strtolower($row['BTAG'] ?? '') === $m) { $matches = true; }
            if (!$matches && (string)($row['BID'] ?? '') === $model) { $matches = true; }
            if ($matches) {
                $service = 'AI' . ($row['BSERVICE'] ?? 'OpenAI');
                $name = $row['BPROVID'] ?: ($row['BNAME'] ?? '');
                return [
                    'ok' => true,
                    'serviceClass' => $service,
                    'modelName' => $name,
                    'modelId' => intval($row['BID'] ?? 0)
                ];
            }
        }
        return ['ok' => false, 'error' => 'Model not found'];
    }

    private static function applyChatGlobals(array $r): void {
        $GLOBALS['AI_CHAT']['SERVICE'] = $r['serviceClass'];
        $GLOBALS['AI_CHAT']['MODEL'] = $r['modelName'];
        $GLOBALS['AI_CHAT']['MODELID'] = $r['modelId'];
    }

    private static function applyPicGlobals(array $r): void {
        $GLOBALS['AI_TEXT2PIC']['SERVICE'] = $r['serviceClass'];
        $GLOBALS['AI_TEXT2PIC']['MODEL'] = $r['modelName'];
        $GLOBALS['AI_TEXT2PIC']['MODELID'] = $r['modelId'];
    }

    private static function applyAudioGlobals(array $r): void {
        $GLOBALS['AI_SOUND2TEXT']['SERVICE'] = $r['serviceClass'];
        $GLOBALS['AI_SOUND2TEXT']['MODEL'] = $r['modelName'];
        $GLOBALS['AI_SOUND2TEXT']['MODELID'] = $r['modelId'];
    }

    private static function applyVisionGlobals(array $r): void {
        $GLOBALS['AI_PIC2TEXT']['SERVICE'] = $r['serviceClass'];
        $GLOBALS['AI_PIC2TEXT']['MODEL'] = $r['modelName'];
        $GLOBALS['AI_PIC2TEXT']['MODELID'] = $r['modelId'];
    }

    private static function saveUploadedFile(array $file): string {
        $userId = $_SESSION['USERPROFILE']['BID'] ?? 0;
        $base = __DIR__ . '/../' . 'up/';
        $sub = substr(strval($userId), -5, 3) . '/' . substr(strval($userId), -2, 2) . '/' . date('Ym');
        $name = 'upload_' . time() . '_' . bin2hex(random_bytes(3));
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if ($ext === '') { $ext = 'bin'; }
        $rel = $sub . '/' . $name . '.' . $ext;
        $dir = $base . $sub;
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        $ok = @move_uploaded_file($file['tmp_name'], $base . $rel);
        return $ok ? $rel : '';
    }
}


