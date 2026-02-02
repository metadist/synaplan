#!/bin/bash
# ==============================================================================
# Reorganize .env file to match new section structure
# ==============================================================================
# Usage: ./reorganize-env.sh [input-file] [output-file]
# Default: ./reorganize-env.sh ../backend/.env ../backend/.env.new
#
# This script preserves ALL existing values and organizes them into sections.
# Unknown keys are saved to .env.unknown for review (may need adding to .env.example!)
# ==============================================================================

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INPUT_FILE="${1:-$SCRIPT_DIR/../backend/.env}"
OUTPUT_FILE="${2:-$SCRIPT_DIR/../backend/.env.new}"
UNKNOWN_FILE="${OUTPUT_FILE%.new}.unknown"
BACKUP_FILE="${INPUT_FILE}.backup.$(date +%Y%m%d_%H%M%S)"

if [[ ! -f "$INPUT_FILE" ]]; then
    echo "Error: Input file '$INPUT_FILE' not found."
    echo "Usage: $0 [input-file] [output-file]"
    exit 1
fi

echo "=== .env Reorganizer ==="
echo "Input:   $INPUT_FILE"
echo "Output:  $OUTPUT_FILE"
echo "Unknown: $UNKNOWN_FILE"
echo "Backup:  $BACKUP_FILE"
echo ""

# Create backup
cp "$INPUT_FILE" "$BACKUP_FILE"
echo "✓ Backup created"

# Declare associative array to store all key=value pairs
declare -A ENV_VARS

# Read existing .env file and store all values
while IFS= read -r line || [[ -n "$line" ]]; do
    # Skip empty lines and comments
    [[ -z "$line" || "$line" =~ ^[[:space:]]*# ]] && continue
    
    # Extract key and value (handle values with = in them)
    if [[ "$line" =~ ^([A-Za-z_][A-Za-z0-9_]*)=(.*)$ ]]; then
        key="${BASH_REMATCH[1]}"
        value="${BASH_REMATCH[2]}"
        ENV_VARS["$key"]="$value"
    fi
done < "$INPUT_FILE"

TOTAL_VARS=${#ENV_VARS[@]}
echo "✓ Read $TOTAL_VARS variables"

# Helper function to output a variable (uses stored value or default)
output_var() {
    local key="$1"
    local default="$2"
    local value="${ENV_VARS[$key]:-$default}"
    echo "${key}=${value}"
    # Mark as processed
    unset ENV_VARS["$key"]
}

# Helper function to output a variable only if it exists
output_var_if_exists() {
    local key="$1"
    if [[ -v ENV_VARS[$key] ]]; then
        echo "${key}=${ENV_VARS[$key]}"
        unset ENV_VARS["$key"]
    fi
}

# Generate the reorganized file
{
cat << 'HEADER'
# ==============================================================================
# SYNAPLAN ENVIRONMENT CONFIGURATION
# ==============================================================================
# Reorganized by _devextras/reorganize-env.sh
# Sections match the Admin → System Config UI tabs.
# ==============================================================================


# ==============================================================================
# 1. APPLICATION CORE
# ==============================================================================

HEADER

output_var "APP_ENV" "dev"
output_var "APP_SECRET" "changeme_in_production"
output_var "LOG_FORMAT" "text"
output_var_if_exists "LOG_LEVEL"
output_var "TOKEN_SECRET" "changeme_use_strong_secret_in_production"
output_var "LOCK_DSN" "flock"
echo ""
echo "# Public URLs"
output_var "FRONTEND_URL" "http://localhost:5173"
output_var "SYNAPLAN_URL" "http://localhost:8000"
output_var_if_exists "APP_URL"
echo ""
output_var "AUTO_DOWNLOAD_MODELS" "false"


cat << 'SECTION2'


# ==============================================================================
# 2. AI SERVICES
# ==============================================================================

# --- Local AI (Ollama) ---
SECTION2

output_var "OLLAMA_BASE_URL" "http://ollama:11434"

echo ""
echo "# --- Cloud AI Providers ---"
output_var "OPENAI_API_KEY" ""
output_var "ANTHROPIC_API_KEY" ""
output_var "GROQ_API_KEY" ""
output_var "GOOGLE_GEMINI_API_KEY" ""
output_var_if_exists "THEHIVE_API_KEY"

echo ""
echo "# --- Self-Hosted AI ---"
output_var "TRITON_SERVER_URL" ""

echo ""
echo "# --- Text-to-Speech ---"
output_var "ELEVENLABS_API_KEY" ""


cat << 'SECTION3'


# ==============================================================================
# 3. EMAIL CONFIGURATION
# ==============================================================================

SECTION3

output_var "MAILER_DSN" "null://null"
output_var "APP_SENDER_EMAIL" ""
output_var "APP_SENDER_NAME" "Synaplan"


cat << 'SECTION4'


# ==============================================================================
# 4. AUTHENTICATION & SECURITY
# ==============================================================================

# --- reCAPTCHA v3 ---
SECTION4

output_var "RECAPTCHA_ENABLED" "false"
output_var "RECAPTCHA_SITE_KEY" ""
output_var "RECAPTCHA_SECRET_KEY" ""
output_var "RECAPTCHA_MIN_SCORE" "0.5"

echo ""
echo "# --- Google OAuth 2.0 ---"
output_var "GOOGLE_CLIENT_ID" ""
output_var "GOOGLE_CLIENT_SECRET" ""
output_var "GOOGLE_CLOUD_PROJECT_ID" ""
output_var_if_exists "GOOGLE_OAUTH_CREDENTIALS"
output_var_if_exists "GMAIL_OAUTH_TOKEN"

echo ""
echo "# --- GitHub OAuth 2.0 ---"
output_var "GITHUB_CLIENT_ID" ""
output_var "GITHUB_CLIENT_SECRET" ""

echo ""
echo "# --- OIDC (Enterprise SSO) ---"
output_var "OIDC_DISCOVERY_URL" ""
output_var "OIDC_CLIENT_ID" ""
output_var "OIDC_CLIENT_SECRET" ""


cat << 'SECTION5'


# ==============================================================================
# 5. INBOUND CHANNELS
# ==============================================================================

# --- WhatsApp Business API ---
SECTION5

output_var "WHATSAPP_ENABLED" "false"
output_var "WHATSAPP_ACCESS_TOKEN" ""
output_var "WHATSAPP_WEBHOOK_VERIFY_TOKEN" ""

echo ""
echo "# --- Smart Mail (Gmail IMAP) ---"
output_var "GMAIL_USERNAME" ""
output_var "GMAIL_PASSWORD" ""


cat << 'SECTION6'


# ==============================================================================
# 6. DOCUMENT PROCESSING
# ==============================================================================

# --- Apache Tika ---
SECTION6

output_var "TIKA_BASE_URL" "http://tika:9998"
output_var_if_exists "TIKA_URL"
output_var "TIKA_TIMEOUT_MS" "30000"
output_var "TIKA_RETRIES" "2"
output_var "TIKA_RETRY_BACKOFF_MS" "1000"
output_var "TIKA_HTTP_USER" ""
output_var "TIKA_HTTP_PASS" ""
output_var "TIKA_MIN_LENGTH" "10"
output_var "TIKA_MIN_ENTROPY" "3.0"

echo ""
echo "# --- PDF Rasterizer (OCR) ---"
output_var "RASTERIZE_DPI" "150"
output_var "RASTERIZE_PAGE_CAP" "10"
output_var "RASTERIZE_TIMEOUT_MS" "30000"

echo ""
echo "# --- Whisper (Audio Transcription) ---"
output_var "WHISPER_ENABLED" "true"
output_var "WHISPER_DEFAULT_MODEL" "base"
output_var "WHISPER_BINARY" "/usr/local/bin/whisper"
output_var "WHISPER_MODELS_PATH" "/var/www/backend/var/whisper"
output_var "FFMPEG_BINARY" "/usr/bin/ffmpeg"

echo ""
echo "# --- Brave Web Search ---"
output_var "BRAVE_SEARCH_ENABLED" "false"
output_var "BRAVE_SEARCH_API_KEY" ""
output_var "BRAVE_SEARCH_API_URL" "https://api.search.brave.com/res/v1"
output_var "BRAVE_SEARCH_COUNT" "10"
output_var "BRAVE_SEARCH_COUNTRY" "us"
output_var "BRAVE_SEARCH_SEARCH_LANG" "en"


cat << 'SECTION7'


# ==============================================================================
# 7. VECTOR DATABASE
# ==============================================================================

SECTION7

output_var "QDRANT_SERVICE_URL" "http://qdrant-service:8090"
output_var "QDRANT_SERVICE_API_KEY" "changeme-in-production"


cat << 'SECTION8'


# ==============================================================================
# 8. PAYMENTS & BILLING (manual edit only)
# ==============================================================================

SECTION8

output_var "STRIPE_SECRET_KEY" "sk_test_your_key_here"
output_var "STRIPE_PUBLISHABLE_KEY" "pk_test_your_key_here"
output_var "STRIPE_WEBHOOK_SECRET" "whsec_your_webhook_secret_here"
output_var "STRIPE_PRICE_PRO" "price_pro_monthly"
output_var "STRIPE_PRICE_TEAM" "price_team_monthly"
output_var "STRIPE_PRICE_BUSINESS" "price_business_monthly"
output_var "STRIPE_PAYMENT_METHODS" "card,link,klarna"


cat << 'SECTION9'


# ==============================================================================
# 9. DATABASE (manual edit only)
# ==============================================================================

SECTION9

output_var "MYSQL_DATABASE" "synaplan"
output_var "MYSQL_USER" "synaplan_user"
output_var "MYSQL_PASSWORD" "synaplan_password"
output_var "MYSQL_ROOT_PASSWORD" "root_password"
echo ""
output_var "DATABASE_WRITE_URL" "mysql://synaplan_user:synaplan_password@db:3306/synaplan?serverVersion=11.8&charset=utf8mb4"
output_var "DATABASE_READ_URL" "mysql://synaplan_user:synaplan_password@db:3306/synaplan?serverVersion=11.8&charset=utf8mb4"


# Output any remaining unknown/custom variables
if [[ ${#ENV_VARS[@]} -gt 0 ]]; then
    cat << 'SECTION_CUSTOM'


# ==============================================================================
# 10. UNKNOWN VARIABLES (review needed!)
# ==============================================================================
# These variables exist in your .env but are NOT in .env.example
# Consider adding them to .env.example if they are standard settings!
# ==============================================================================

SECTION_CUSTOM

    for key in "${!ENV_VARS[@]}"; do
        echo "${key}=${ENV_VARS[$key]}"
    done
fi

} > "$OUTPUT_FILE"

echo "✓ Generated: $OUTPUT_FILE"

# Save unknown variables to separate file for review
UNKNOWN_COUNT=${#ENV_VARS[@]}
if [[ $UNKNOWN_COUNT -gt 0 ]]; then
    {
        echo "# =============================================================================="
        echo "# UNKNOWN VARIABLES - $(date)"
        echo "# =============================================================================="
        echo "# These variables were found in your .env but are NOT defined in .env.example"
        echo "# "
        echo "# ACTION REQUIRED: Review each variable and either:"
        echo "#   1. Add it to backend/.env.example (if it's a standard setting)"
        echo "#   2. Remove it from your .env (if it's obsolete)"
        echo "#   3. Keep it as a custom/local setting"
        echo "# =============================================================================="
        echo ""
        for key in "${!ENV_VARS[@]}"; do
            echo "${key}=${ENV_VARS[$key]}"
        done
    } > "$UNKNOWN_FILE"
    
    echo ""
    echo "╔══════════════════════════════════════════════════════════════════════════════╗"
    echo "║  ⚠️  WARNING: $UNKNOWN_COUNT UNKNOWN VARIABLE(S) FOUND!                              ║"
    echo "╠══════════════════════════════════════════════════════════════════════════════╣"
    echo "║  These may be missing from .env.example. Review: $UNKNOWN_FILE"
    echo "╚══════════════════════════════════════════════════════════════════════════════╝"
    echo ""
    echo "Unknown variables:"
    for key in "${!ENV_VARS[@]}"; do
        echo "  - $key"
    done
    echo ""
else
    # Remove unknown file if no unknowns
    rm -f "$UNKNOWN_FILE"
fi

PROCESSED=$((TOTAL_VARS - UNKNOWN_COUNT))
echo ""
echo "=== Summary ==="
echo "Total variables:    $TOTAL_VARS"
echo "Recognized:         $PROCESSED"
echo "Unknown:            $UNKNOWN_COUNT"
echo ""
echo "=== Next Steps ==="
echo "1. Review output:   less $OUTPUT_FILE"
if [[ $UNKNOWN_COUNT -gt 0 ]]; then
    echo "2. Review unknown:  less $UNKNOWN_FILE"
    echo "   → Add missing vars to backend/.env.example if needed!"
    echo "3. If satisfied:    mv $OUTPUT_FILE $INPUT_FILE"
else
    echo "2. If satisfied:    mv $OUTPUT_FILE $INPUT_FILE"
fi
echo "4. Restart:         docker compose restart backend"
echo ""
echo "To revert: cp $BACKUP_FILE $INPUT_FILE"
