#!/bin/sh

set -eu

STATE_FILE="${HEALTHCHECK_STATE_FILE:-/tmp/voicehub-healthcheck.failures}"
FAIL_THRESHOLD="${HEALTHCHECK_FAIL_THRESHOLD:-3}"
TIMEOUT_MS="${HEALTHCHECK_TIMEOUT_MS:-5000}"
PORT_VALUE="${PORT:-3000}"
PATH_VALUE="${HEALTHCHECK_PATH:-/api/system/status}"

case "$FAIL_THRESHOLD" in
  ''|*[!0-9]*) FAIL_THRESHOLD=3 ;;
esac

if [ "$FAIL_THRESHOLD" -lt 1 ]; then
  FAIL_THRESHOLD=1
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

TARGET_URL="${HEALTHCHECK_URL:-http://127.0.0.1:${PORT_VALUE}${PATH_VALUE}}"

if HEALTHCHECK_TARGET_URL="$TARGET_URL" HEALTHCHECK_TIMEOUT_MS="$TIMEOUT_MS" node <<'EOF'
const url = process.env.HEALTHCHECK_TARGET_URL
const timeoutMs = Number(process.env.HEALTHCHECK_TIMEOUT_MS || '5000')

const controller = new AbortController()
const timer = setTimeout(() => controller.abort(new Error('healthcheck timeout')), timeoutMs)

;(async () => {
  try {
    const response = await fetch(url, {
      signal: controller.signal,
      headers: { 'x-health-check': '1' }
    })

    if (!response.ok) {
      process.exit(2)
    }

    const contentType = response.headers.get('content-type') || ''
    if (contentType.includes('application/json')) {
      const data = await response.json().catch(() => null)
      if (data && typeof data === 'object' && 'status' in data && data.status !== 'ok') {
        process.exit(3)
      }
    }

    process.exit(0)
  } catch {
    process.exit(1)
  } finally {
    clearTimeout(timer)
  }
})()
EOF
then
  rm -f "$STATE_FILE"
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
  echo "[healthcheck] fail threshold reached, stopping PID 1 for auto-restart" >&2
  kill -TERM 1 2>/dev/null || true
  sleep 8
  kill -KILL 1 2>/dev/null || true
fi

exit 1
