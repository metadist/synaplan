<?php
/**
 * API Keys Configuration
 * 
 * Centralized management of all API keys for the Synaplan application.
 * This file provides a migration path from .keys files to environment variables.
 * 
 * Priority:
 * 1. Environment variables (production)
 * 2. .env file (development)
 * 3. Legacy .keys files (backward compatibility)
 */

class ApiKeys {
    private static $keys = [];
    private static $initialized = false;

    /**
     * Initialize API keys from various sources
     */
    public static function init() {
        if (self::$initialized) {
            return;
        }

        // Load .env file if it exists (for development)
        self::loadDotEnv();

        // Load all API keys
        self::loadKeys();

        self::$initialized = true;
    }

    /**
     * Load .env file if it exists
     */
    private static function loadDotEnv() {
        // Prefer project root .env, fallback to app/.env
        $projectEnv = dirname(__DIR__, 3) . '/.env'; // /project/.env
        $appEnv     = dirname(__DIR__, 2) . '/.env'; // /project/app/.env
        $envFile = file_exists($projectEnv) ? $projectEnv : (file_exists($appEnv) ? $appEnv : null);
        if ($envFile) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '#') === 0) continue; // Skip comments
                if (strpos($line, '=') === false) continue; // Skip invalid lines
                
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Only set if not already in environment
                if (!isset($_ENV[$key]) && !getenv($key)) {
                    putenv("$key=$value");
                    $_ENV[$key] = $value;
                    //error_log("Loaded API key: $key = $value");
                }
            }
        }
    }

    /**
     * Load all API keys with fallback logic
     */
    private static function loadKeys() {
        // Define all API keys
        $keyConfig = [
            'OPENAI_API_KEY',
            'GROQ_API_KEY',
            'GOOGLE_GEMINI_API_KEY',
            'ANTHROPIC_API_KEY',
            'THEHIVE_API_KEY',
            'ELEVENLABS_API_KEY',
            'BRAVE_SEARCH_API_KEY',
            'WHATSAPP_TOKEN',
            'AWS_CREDENTIALS',
            'GOOGLE_OAUTH_CREDENTIALS',
            'GMAIL_OAUTH_TOKEN',
            'OLLAMA_SERVER',
            'TRITON_SERVER',
            'OIDC_PROVIDER_URL',
            'OIDC_CLIENT_ID',
            'OIDC_CLIENT_SECRET',
            'OIDC_REDIRECT_URI',
            'OIDC_SCOPES',
            'OIDC_SSL_VERIFY',
            'OIDC_AUTO_REDIRECT',
            'APP_DEBUG',
            'APP_ENV',
            'APP_URL',
            // Tika / Document extraction
            'TIKA_ENABLED',
            'TIKA_URL',
            'TIKA_TIMEOUT_MS',
            'TIKA_RETRIES',
            'TIKA_RETRY_BACKOFF_MS',
            'TIKA_HTTP_USER',
            'TIKA_HTTP_PASS',
            // Rasterizer / Vision fallback
            'RASTERIZE_DPI',
            'RASTERIZE_PAGE_CAP',
            'RASTERIZE_TIMEOUT_MS',
            // Quality thresholds
            'TIKA_MIN_LENGTH',
            'TIKA_MIN_ENTROPY',
            // Rate Limiting
            'RATE_LIMITING_ENABLED',
        ];

        foreach ($keyConfig as $envKey) {
            self::$keys[$envKey] = self::getKey($envKey);
        }
    }

    /**
     * Get a specific API key
     */
    private static function getKey($envKey) {
        // 1. Try environment variable
        $value = getenv($envKey) ?: $_ENV[$envKey] ?? null;
        if ($value) {
            return trim($value);
        }

        // 2. Return null if not found
        return null;
    }

    /**
     * Get a specific API key
     */
    public static function get($key) {
        self::init();
        return self::$keys[$key] ?? null;
    }

    // ------------------------- TIKA CONFIG -------------------------
    public static function isTikaEnabled(): bool {
        $val = self::get('TIKA_ENABLED');
        if ($val === null) return true; // default enabled
        $v = strtolower(trim(strval($val)));
        return !in_array($v, ['0','false','off','no'], true);
    }
    public static function getTikaUrl(): ?string {
        return self::get('TIKA_URL') ?: null;
    }
    public static function getTikaTimeoutMs(): int {
        $v = intval(self::get('TIKA_TIMEOUT_MS'));
        return $v > 0 ? $v : 15000;
    }
    public static function getTikaRetries(): int {
        $v = intval(self::get('TIKA_RETRIES'));
        return $v >= 0 ? $v : 1;
    }
    public static function getTikaRetryBackoffMs(): int {
        $v = intval(self::get('TIKA_RETRY_BACKOFF_MS'));
        return $v >= 0 ? $v : 300;
    }
    public static function getTikaMinLength(): int {
        $v = intval(self::get('TIKA_MIN_LENGTH'));
        return $v > 0 ? $v : 32;
    }
    public static function getTikaMinEntropy(): float {
        $v = self::get('TIKA_MIN_ENTROPY');
        $f = $v !== null ? floatval($v) : 2.5;
        return $f;
    }

    public static function getTikaHttpUser(): ?string {
        $v = self::get('TIKA_HTTP_USER');
        return ($v !== null && $v !== '') ? $v : null;
    }
    public static function getTikaHttpPass(): ?string {
        $v = self::get('TIKA_HTTP_PASS');
        return ($v !== null) ? $v : null;
    }

    // ------------------------- RASTERIZER CONFIG -------------------------
    public static function getRasterizeDpi(): int {
        $v = intval(self::get('RASTERIZE_DPI'));
        return $v > 0 ? $v : 150;
    }
    public static function getRasterizePageCap(): int {
        $v = intval(self::get('RASTERIZE_PAGE_CAP'));
        return $v > 0 ? $v : 5;
    }
    public static function getRasterizeTimeoutMs(): int {
        $v = intval(self::get('RASTERIZE_TIMEOUT_MS'));
        return $v > 0 ? $v : 20000;
    }

    /**
     * Get OpenAI API key
     */
    public static function getOpenAI() {
        return self::get('OPENAI_API_KEY');
    }

    /**
     * Get Groq API key
     */
    public static function getGroq() {
        return self::get('GROQ_API_KEY');
    }

    /**
     * Get Google Gemini API key
     */
    public static function getGoogleGemini() {
        return self::get('GOOGLE_GEMINI_API_KEY');
    }

    /**
     * Get Anthropic API key
     */
    public static function getAnthropic() {
        return self::get('ANTHROPIC_API_KEY');
    }

    /**
     * Get TheHive API key
     */
    public static function getTheHive() {
        return self::get('THEHIVE_API_KEY');
    }

    /**
     * Get OIDC Provider URL
     */
    public static function getOidcProviderUrl() {
        return self::get('OIDC_PROVIDER_URL');
    }

    /**
     * Get OIDC Client ID
     */
    public static function getOidcClientId() {
        return self::get('OIDC_CLIENT_ID');
    }

    /**
     * Get OIDC Client Secret
     */
    public static function getOidcClientSecret() {
        return self::get('OIDC_CLIENT_SECRET');
    }

    /**
     * Get OIDC Redirect URI
     */
    public static function getOidcRedirectUri() {
        return self::get('OIDC_REDIRECT_URI');
    }

    /**
     * Get OIDC Scopes
     */
    public static function getOidcScopes() {
        return self::get('OIDC_SCOPES') ?: 'openid profile email';
    }

    /**
     * Get ElevenLabs API key
     */
    public static function getElevenLabs() {
        return self::get('ELEVENLABS_API_KEY');
    }

    /**
     * Get Brave Search API key
     */
    public static function getBraveSearch() {
        return self::get('BRAVE_SEARCH_API_KEY');
    }

    /**
     * Get Triton server URL
     */
    public static function getTritonServer() {
        return self::get('TRITON_SERVER');
    }

    /**
     * Get WhatsApp token
     */
    public static function getWhatsApp() {
        return self::get('WHATSAPP_TOKEN');
    }

    /**
     * Check if rate limiting is enabled
     */
    public static function isRateLimitingEnabled(): bool {
        $value = self::get('RATE_LIMITING_ENABLED');
        if ($value === null) return false; // Default disabled
        $v = strtolower(trim(strval($value)));
        return in_array($v, ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Get AWS credentials as array
     */
    public static function getAWS() {
        $credentials = self::get('AWS_CREDENTIALS');
        if ($credentials && strpos($credentials, ';') !== false) {
            list($accessKey, $secretKey) = explode(';', $credentials, 2);
            return [
                'access_key' => trim($accessKey),
                'secret_key' => trim($secretKey)
            ];
        }
        return null;
    }

    /**
     * Check if all required keys are available
     */
    public static function validateKeys() {
        self::init();
        $missing = [];
        
        foreach (self::$keys as $key => $value) {
            if (empty($value)) {
                $missing[] = $key;
            }
        }
        
        return $missing;
    }
}

// Initialize keys on include
ApiKeys::init(); 