#!/usr/bin/env bash
set -euo pipefail

# --- CONFIG ------------------------------------------------------------------
# Load environment variables from .env file
if [[ -f "../.env" ]]; then
    source "../.env"
else
    echo "Error: ../.env file not found"
    exit 1
fi

# Check if OPENAI_API_KEY is set
if [[ -z "${OPENAI_API_KEY:-}" ]]; then
    echo "Error: OPENAI_API_KEY not found in ../.env file"
    exit 1
fi

MODEL="gpt-4.1"                        # any model listed as supported by the Responses API
PROMPT="Create a concise 5-slide PowerPoint explaining Newton's three laws \
to 8-year-olds, with one catchy phrase per slide. Provide a download file in PPT or PPTX."

# -----------------------------------------------------------------------------
###############################################################################
# 1) ask the Responses API to run code that builds the PPTX
###############################################################################
resp_json=$(curl -sS https://api.openai.com/v1/responses \
  -H "Authorization: Bearer $OPENAI_API_KEY" \
  -H "Content-Type: application/json" \
  -d @- <<EOF
{
  "model":"$MODEL",
  "tools":[{"type":"code_interpreter","container":{"type":"auto"}}],
  "input":[{"role":"user","content":"$PROMPT"}],
  "store":true
}
EOF
)


response_id=$(jq -r '.id' <<<"$resp_json")
echo "Queued as response: $response_id"
echo $response_id

###############################################################################
# 2) poll until the run finishes
###############################################################################
while : ; do
  status=$(curl -sS https://api.openai.com/v1/responses/$response_id \
           -H "Authorization: Bearer $OPENAI_API_KEY" | jq -r '.status')
  [[ "$status" == "completed" ]] && break
  echo "• generation is '$status' … waiting 3 s"
  sleep 3
done

# --- 3a) fetch the finished response JSON -------------------------------
run_json=$(curl -sS https://api.openai.com/v1/responses/$response_id \
           -H "Authorization: Bearer $OPENAI_API_KEY")

# --- 3b) pull the first container + file id -----------------------------
container_id=$(jq -r '
  ..|objects|select(.type=="container_file_citation")|.container_id' \
  <<<"$run_json" | head -n1)

echo "Container ID: $run_json"

file_id=$(jq -r '
  ..|objects|select(.type=="container_file_citation")|.file_id' \
  <<<"$run_json" | head -n1)

if [[ -z $container_id || -z $file_id ]]; then
  echo "🤷  No container file found—check the run output"; exit 1
fi
echo "Found artifact → container:$container_id  file:$file_id"

# --- 4) download from the container -------------------------------------
curl -L -H "Authorization: Bearer $OPENAI_API_KEY" \
     "https://api.openai.com/v1/containers/${container_id}/files/${file_id}/content" \
     --output _1TEST.pptx

echo "✅  Saved as newton_laws.pptx"