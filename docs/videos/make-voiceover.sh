#!/usr/bin/env bash
# Generate English AIFF voiceovers from narration/*.txt using macOS `say`.
# Usage:
#   ./make-voiceover.sh          # all chapters 1-7
#   ./make-voiceover.sh 3        # chapter 3 only
#   VOICE='Samantha' ./make-voiceover.sh
#
# List English voices: say -v '?' | grep -i 'en_'
# Download premium voices: System Settings → Accessibility → Spoken Content → Manage Voices

set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
NARRATION="$ROOT/narration"
OUT="$ROOT/audio"
VOICE="${VOICE:-Ava (Premium)}"

mkdir -p "$OUT"

say_chapter() {
  local n="$1"
  local src="$NARRATION/chapter-${n}.txt"
  local dest="$OUT/chapter-${n}.aiff"
  if [[ ! -f "$src" ]]; then
    echo "Missing $src" >&2
    exit 1
  fi
  echo "Saying chapter ${n} with voice '${VOICE}' → ${dest}"
  if ! say -v "$VOICE" -f "$src" -o "$dest"; then
    echo "Voice '${VOICE}' failed. Try: say -v '?' | grep -i en_" >&2
    echo "Example: VOICE='Samantha' $0 ${n}" >&2
    exit 1
  fi
}

if [[ $# -eq 0 ]]; then
  for n in 1 2 3 4 5 6 7; do
    say_chapter "$n"
  done
  echo "Done. Files in $OUT"
  exit 0
fi

if [[ "$1" =~ ^[1-7]$ ]]; then
  say_chapter "$1"
  exit 0
fi

echo "Usage: $0 [1-7]" >&2
exit 1
