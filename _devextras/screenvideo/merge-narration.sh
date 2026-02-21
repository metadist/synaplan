#!/usr/bin/env bash
#
# Merge TTS narration audio into a Playwright screen recording.
#
# Usage:
#   ./merge-narration.sh <video.webm> <combined-audio.wav> [output.mp4]
#
# If output is omitted, writes to <video>-narrated.mp4 next to the input.

set -euo pipefail

VIDEO="${1:?Usage: merge-narration.sh <video> <audio> [output]}"
AUDIO="${2:?Usage: merge-narration.sh <video> <audio> [output]}"
OUTPUT="${3:-${VIDEO%.webm}-narrated.mp4}"

if [ ! -f "$VIDEO" ]; then echo "Video not found: $VIDEO"; exit 1; fi
if [ ! -f "$AUDIO" ]; then echo "Audio not found: $AUDIO"; exit 1; fi

ffmpeg -y \
  -i "$VIDEO" \
  -i "$AUDIO" \
  -c:v libx264 -preset fast -crf 23 \
  -c:a aac -b:a 128k \
  -map 0:v:0 -map 1:a:0 \
  -shortest \
  -r 25 \
  "$OUTPUT"

echo ""
echo "Narrated video: $OUTPUT"
