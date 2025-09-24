<?php

/**
 * API Router
 *
 * Handles routing for different API request types including
 * JSON-RPC, OpenAI-compatible routes, and REST endpoints.
 *
 * @package API
 */

class ApiRouter
{
    /**
     * Route incoming request to appropriate handler
     *
     * @param string $rawPostData Raw POST data
     * @return bool True if request was routed and handled
     */
    public static function route(string $rawPostData): bool
    {
        // Check for JSON-RPC requests first
        if (self::handleJsonRpcRequest($rawPostData)) {
            return true;
        }

        // Check for OpenAI-compatible routes
        if (self::handleOpenAIRoutes()) {
            return true;
        }

        // Default to REST API handling
        return false; // Let the calling code handle REST
    }

    /**
     * Handle JSON-RPC requests
     *
     * @param string $rawPostData Raw POST data
     * @return bool True if this was a JSON-RPC request
     */
    private static function handleJsonRpcRequest(string $rawPostData): bool
    {
        if (empty($rawPostData) || !Tools::isValidJson($rawPostData)) {
            return false;
        }

        $jsonRpcRequest = json_decode($rawPostData, true);

        if (!isset($jsonRpcRequest['jsonrpc']) ||
            !isset($jsonRpcRequest['method']) ||
            !isset($jsonRpcRequest['id'])) {
            return false;
        }

        // This is a JSON-RPC request
        $root = __DIR__ . '/../../';
        require_once($root . 'inc/_api-mcp.php');
        exit;
    }

    /**
     * Handle OpenAI-compatible routes
     *
     * @return bool True if this was an OpenAI route
     */
    private static function handleOpenAIRoutes(): bool
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $requestPath = parse_url($requestUri, PHP_URL_PATH);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // Normalize paths like /.../api.php/v1/... → /v1/...
        if ($requestPath) {
            $pos = stripos($requestPath, 'api.php');
            if ($pos !== false) {
                $suffix = substr($requestPath, $pos + 7);
                if ($suffix === '' || $suffix[0] !== '/') {
                    $suffix = '/' . ltrim($suffix, '/');
                }
                $requestPath = $suffix;
            }
        }

        // Handle /v1/* paths as OpenAI-compatible
        if (strpos($requestPath, '/v1/') === 0) {
            $rawPostData = file_get_contents('php://input');
            ApiOpenAPI::handle($requestPath, $method, $rawPostData);
            return true;
        }

        return false;
    }

    /**
     * Get request path for debugging
     *
     * @return string The normalized request path
     */
    public static function getRequestPath(): string
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        return parse_url($requestUri, PHP_URL_PATH) ?: '';
    }

    /**
     * Get request method
     *
     * @return string The HTTP request method
     */
    public static function getRequestMethod(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }
}
