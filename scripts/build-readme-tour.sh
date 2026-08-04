#!/usr/bin/env bash
#
# Rebuilds the README product tour: the looping hero film, the click-to-enlarge
# thumbnails and the full-size stills.
#
#   scripts/build-readme-tour.sh [source-dir]
#
# The source directory holds one PNG per slide, named after the SLIDES ids below
# (e.g. chat.png). Defaults to docs/images/tour/src, which is gitignored — only
# the generated WebP output is committed.
#
# Recapture rules, so the tour stays consistent when a screen changes:
#   - Light mode, English UI, logged in as an admin.
#   - Same browser viewport for every shot (the stills must share one aspect
#     ratio; letterboxing a mismatched shot is visible in the film).
#   - No real API keys, customer names or personal file names on screen.
#
# Requires: ImageMagick (convert) and ffmpeg with libwebp.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="${1:-$REPO_ROOT/docs/images/tour/src}"
OUT="$REPO_ROOT/docs/images/tour"
THUMBS="$OUT/thumbs"
FILM="$OUT/../synaplan-tour.webp"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# Frame geometry. The film is 1440 px wide so it stays sharp on a HiDPI screen
# at GitHub's ~900 px README column.
W=1440
CAPTION_H=124
BG="#f1f5f9"
CARD_BORDER="#cbd5e1"
INK="#0f172a"
MUTED="#64748b"
ACCENT="#005c97" # BRAND_PRIMARY_COLOR

SECONDS_PER_SLIDE=2.8
THUMB_W=440

# id|caption|in_film
# Order is the story we want a first-time visitor to read: a real answer, then
# how little it takes to get one, then what else the platform does.
SLIDES=(
  "chat|Ask anything — and see the exact cost of every answer|yes"
  "provider-setup|Paste one API key. Tested live, stored encrypted, no restart.|yes"
  "model-selection|Pick a model per task — chat, vision, image, video, audio|yes"
  "rag-search|Semantic search across your own documents|yes"
  "media-generation|Generate images, video and audio right in the chat|yes"
  "chat-widget|Embed an AI chat widget on any website|yes"
  "branding|White-label it — your name, colors, fonts and logo|yes"
  "system-prompts|Shape the AI with your own system prompts|no"
  "file-manager|Everything you upload becomes AI-searchable knowledge|no"
  "plugins|Extend the platform with plugins|no"
  "admin-panel|Full operator control — users, usage and system health|no"
)

command -v convert >/dev/null || { echo "error: ImageMagick (convert) not found" >&2; exit 1; }
command -v ffmpeg >/dev/null || { echo "error: ffmpeg not found" >&2; exit 1; }
[ -d "$SRC" ] || { echo "error: source directory not found: $SRC" >&2; exit 1; }

mkdir -p "$OUT" "$THUMBS"

film_index=0
for entry in "${SLIDES[@]}"; do
  IFS='|' read -r id caption in_film <<<"$entry"
  src="$SRC/$id.png"
  [ -f "$src" ] || { echo "error: missing slide image: $src" >&2; exit 1; }

  # Full-size still: WebP keeps the repo small and GitHub renders it inline.
  convert "$src" -resize "${W}x" -quality 82 -define webp:method=6 "$OUT/$id.webp"

  # Thumbnail for the click-to-enlarge grid.
  convert "$src" -resize "${THUMB_W}x" -quality 78 -define webp:method=6 \
    -bordercolor "$CARD_BORDER" -border 1 "$THUMBS/$id.webp"

  [ "$in_film" = "yes" ] || continue
  film_index=$((film_index + 1))
  n=$(printf "%02d" "$film_index")

  # Film frame: the still on a neutral card, with a caption bar underneath.
  shot="$WORK/shot_$n.png"
  convert "$src" -resize "$((W - 48))x" -bordercolor "$CARD_BORDER" -border 1 "$shot"
  shot_h=$(identify -format "%h" "$shot")
  frame_h=$((shot_h + 48 + CAPTION_H))

  convert -size "${W}x${frame_h}" "xc:$BG" \
    \( "$shot" \) -gravity north -geometry +0+24 -composite \
    -font DejaVu-Sans-Bold -pointsize 34 -fill "$INK" \
    -gravity southwest -annotate +26+56 "$caption" \
    "$WORK/frame_$n.png"
done

# The slide counter and progress bar need the total, known only after the loop.
film_total=$film_index
for f in "$WORK"/frame_*.png; do
  idx=$((10#$(basename "$f" .png | cut -d_ -f2)))
  frame_h=$(identify -format "%h" "$f")
  bar_w=$(( 26 + idx * (W - 52) / film_total ))
  convert "$f" \
    -font DejaVu-Sans -pointsize 22 -fill "$MUTED" \
    -gravity southeast -annotate +26+62 "$idx / $film_total" \
    -fill "$ACCENT" -draw "rectangle 26,$((frame_h - 26)) $bar_w,$((frame_h - 22))" \
    "$f"
done

ffmpeg -hide_banner -loglevel error -y \
  -framerate "1/$SECONDS_PER_SLIDE" -i "$WORK/frame_%02d.png" \
  -vcodec libwebp_anim -lossless 0 -q:v 72 -compression_level 6 \
  -loop 0 -an -fps_mode passthrough \
  "$FILM"

echo "film:       $FILM ($(du -h "$FILM" | cut -f1), $film_total slides)"
echo "stills:     $OUT/*.webp"
echo "thumbnails: $THUMBS/*.webp"
