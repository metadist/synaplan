<?php
$method = $jsonRpcRequest['method'];
$params = $jsonRpcRequest['params'] ?? [];
$id = $jsonRpcRequest['id'];

// Map JSON-RPC methods to existing functionality
switch ($method) {
    case 'messageNew':
        $resArr = Frontend::saveWebMessages();
        break;
    case 'chatStream':
        $resArr = Frontend::chatStream();
        exit;
    case 'promptLoad':
        $resArr = BasicAI::getAprompt($params['promptKey'] ?? '', $params['lang'] ?? '', [], false);
        break;
    case 'promptUpdate':
        $resArr = BasicAI::updatePrompt($params['promptKey'] ?? '');
        break;
    default:
        $resArr = ['error' => 'Method not found'];
        break;
}

// Format JSON-RPC response
$response = [
    'jsonrpc' => '2.0',
    'id' => $id,
    'result' => $resArr
];

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($response);

