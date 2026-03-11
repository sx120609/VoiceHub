#!/bin/sh

set -eu

STATE_FILE="${HEALTHCHECK_STATE_FILE:-/tmp/voicehub-healthcheck.failures}"
READY_FILE="${HEALTHCHECK_READY_FILE:-/tmp/voicehub-healthcheck.ready}"
FAIL_THRESHOLD="${HEALTHCHECK_FAIL_THRESHOLD:-5}"
TIMEOUT_MS="${HEALTHCHECK_TIMEOUT_MS:-5000}"
PORT_VALUE="${PORT:-3000}"
PATH_VALUE="${HEALTHCHECK_PATH:-/api/healthz}"
BASE_PATH_RAW="${HEALTHCHECK_BASE_PATH:-${NUXT_APP_BASE_URL:-}}"

case "$FAIL_THRESHOLD" in
  ''|*[!0-9]*) FAIL_THRESHOLD=5 ;;
esac

# Avoid restart storms from over-aggressive configuration.
if [ "$FAIL_THRESHOLD" -lt 3 ]; then
  FAIL_THRESHOLD=3
fi

case "$TIMEOUT_MS" in
  ''|*[!0-9]*) TIMEOUT_MS=5000 ;;
esac

if [ "$TIMEOUT_MS" -lt 1000 ]; then
  TIMEOUT_MS=1000
fi

case "$PATH_VALUE" in
  /*) ;;
  *) PATH_VALUE="/${PATH_VALUE}" ;;
esac

normalize_base_path() {
  base="$1"
  if [ -z "$base" ] || [ "$base" = "/" ]; then
    echo ""
    return 0
  fi

  base="$(printf '%s' "$base" | tr -s '/')"
  case "$base" in
    /*) ;;
    *) base="/${base}" ;;
  esac
  base="${base%/}"
  echo "$base"
}

normalize_path() {
  path="$1"
  if [ -z "$path" ]; then
    echo "/"
    return 0
  fi
  case "$path" in
    /*) ;;
    *) path="/${path}" ;;
  esac
  echo "$path"
}

TARGET_URLS=""
append_url() {
  candidate="$1"
  if [ -z "$candidate" ]; then
    return 0
  fi
  case ",$TARGET_URLS," in
    *,"$candidate",*) return 0 ;;
  esac
  if [ -z "$TARGET_URLS" ]; then
    TARGET_URLS="$candidate"
  else
    TARGET_URLS="${TARGET_URLS},${candidate}"
  fi
}

if [ -n "${HEALTHCHECK_URL:-}" ]; then
  append_url "$HEALTHCHECK_URL"
else
  NORMALIZED_PATH="$(normalize_path "$PATH_VALUE")"
  BASE_PATH="$(normalize_base_path "$BASE_PATH_RAW")"

  append_url "http://127.0.0.1:${PORT_VALUE}${NORMALIZED_PATH}"
  if [ -n "$BASE_PATH" ]; then
    append_url "http://127.0.0.1:${PORT_VALUE}${BASE_PATH}${NORMALIZED_PATH}"
  fi
  append_url "http://127.0.0.1:${PORT_VALUE}/rareapp${NORMALIZED_PATH}"
fi

if HEALTHCHECK_TARGET_URLS="$TARGET_URLS" HEALTHCHECK_TIMEOUT_MS="$TIMEOUT_MS" node <<'EOF'
const urls = (process.env.HEALTHCHECK_TARGET_URLS || '')
  .split(',')
  .map((v) => v.trim())
  .filter(Boolean)
const timeoutMs = Number(process.env.HEALTHCHECK_TIMEOUT_MS || '5000')

;(async () => {
  for (const url of urls) {
    const controller = new AbortController()
    const timer = setTimeout(() => controller.abort(new Error('healthcheck timeout')), timeoutMs)

    try {
      const response = await fetch(url, {
        signal: controller.signal,
        headers: { 'x-health-check': '1' }
      })

      if (!response.ok) {
        continue
      }

      const contentType = response.headers.get('content-type') || ''
      if (contentType.includes('application/json')) {
        const data = await response.json().catch(() => null)
        if (data && typeof data === 'object' && 'status' in data && data.status !== 'ok') {
          continue
        }
      }

      process.exit(0)
    } catch {
      // try next candidate URL
    } finally {
      clearTimeout(timer)
    }
  }

  process.exit(1)
})()
EOF
then
  rm -f "$STATE_FILE"
  touch "$READY_FILE"
  exit 0
fi

FAIL_COUNT=0
if [ -f "$STATE_FILE" ]; then
  FAIL_COUNT=$(cat "$STATE_FILE" 2>/dev/null || echo 0)
fi

FAIL_COUNT=$((FAIL_COUNT + 1))
echo "$FAIL_COUNT" > "$STATE_FILE"

echo "[healthcheck] probe failed (${FAIL_COUNT}/${FAIL_THRESHOLD})" >&2

if [ "$FAIL_COUNT" -ge "$FAIL_THRESHOLD" ]; then
  if [ -f "$READY_FILE" ]; then
    echo "[healthcheck] fail threshold reached, stopping PID 1 for auto-restart" >&2
    kill -TERM 1 2>/dev/null || true
    sleep 2
    kill -KILL 1 2>/dev/null || true
  else
    echo "[healthcheck] fail threshold reached before first successful probe; skip restart to prevent boot loop" >&2
  fi
fi

exit 1
