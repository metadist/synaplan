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
                
                // Remove quotes if present
                if (($value[0] === '"' && $value[-1] === '"') || 
                    ($value[0] === "'" && $value[-1] === "'")) {
                    $value = substr($value, 1, -1);
                }
                
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
            'APP_ENV',
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
        if($GLOBALS["debug"]) error_log("Warning: API key '$envKey' not found in environment");
        return null;
    }

    /**
     * Get a specific API key
     */
    public static function get($key) {
        self::init();
        return self::$keys[$key] ?? null;
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