<?php

// Thin include wrapper around OpenAI-compatible controller
require_once(__DIR__ . '/_openaiapi.php');


class ApiOpenAPI
{
    public static function handle($requestPath, $method, $rawBody) {
        // Match exact /v1/* paths
        if ($requestPath === '/v1/chat/completions' && $method === 'POST') {
            $payload = [];
            if (!empty($rawBody) && Tools::isValidJson($rawBody)) {
                $payload = json_decode($rawBody, true);
            }
            $streamFlag = false;
            if (isset($payload['stream']) && ($payload['stream'] === true || $payload['stream'] === 'true' || $payload['stream'] === 1 || $payload['stream'] === '1')) {
                $streamFlag = true;
            }
            if (isset($_REQUEST['stream']) && ($_REQUEST['stream'] === 'true' || $_REQUEST['stream'] === '1')) {
                $streamFlag = true;
            }
            OpenAICompatController::chatCompletions($payload, $streamFlag);
            exit;
        }

        if ($requestPath === '/v1/images/generations' && $method === 'POST') {
            $payload = [];
            if (!empty($rawBody) && Tools::isValidJson($rawBody)) {
                $payload = json_decode($rawBody, true);
            }
            OpenAICompatController::imageGenerations($payload);
            exit;
        }

        if ($requestPath === '/v1/models' && $method === 'GET') {
            OpenAICompatController::listModels();
            exit;
        }

        if ($requestPath === '/v1/audio/transcriptions' && $method === 'POST') {
            OpenAICompatController::audioTranscriptions();
            exit;
        }

        if ($requestPath === '/v1/images/analysis' && $method === 'POST') {
            OpenAICompatController::imageAnalysis();
            exit;
        }
    }
}
