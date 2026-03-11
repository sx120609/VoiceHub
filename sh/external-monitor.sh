#!/bin/sh

set -eu

# Login endpoint to probe
CHECK_URL="${CHECK_URL:-http://127.0.0.1:3000/rareapp/api/auth/login}"

# Probe behavior
CHECK_METHOD="${CHECK_METHOD:-POST}"
CHECK_BODY="${CHECK_BODY:-{\"username\":\"monitor_probe\",\"password\":\"invalid_password\"}}"
REQUEST_TIMEOUT_SEC="${REQUEST_TIMEOUT_SEC:-4}"
CHECK_INTERVAL_SEC="${CHECK_INTERVAL_SEC:-5}"

# Restart behavior
FAIL_THRESHOLD="${FAIL_THRESHOLD:-2}"
PAUSE_AFTER_RESTART_SEC="${PAUSE_AFTER_RESTART_SEC:-30}"
RESTART_CMD="${RESTART_CMD:-docker compose restart voicehub}"

# Consider 2xx/3xx/4xx as service alive; only network/5xx are treated as failures.
SUCCESS_HTTP_REGEX="${SUCCESS_HTTP_REGEX:-^(2|3|4)[0-9][0-9]$}"
CURL_SILENT_ERRORS="${CURL_SILENT_ERRORS:-1}"

log() {
  printf '%s %s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "[external-monitor]" "$*"
}

require_number() {
  name="$1"
  value="$2"
  case "$value" in
    ''|*[!0-9]*)
      log "invalid number: ${name}=${value}"
      exit 1
      ;;
  esac
}

probe_login() {
  tmp_body="$(mktemp)"
  cleanup() {
    rm -f "$tmp_body"
  }
  trap cleanup EXIT INT TERM

  curl_stderr="/dev/stderr"
  if [ "$CURL_SILENT_ERRORS" = "1" ]; then
    curl_stderr="/dev/null"
  fi

  method="$(printf '%s' "$CHECK_METHOD" | tr '[:lower:]' '[:upper:]')"
  if [ "$method" = "POST" ] || [ "$method" = "PUT" ] || [ "$method" = "PATCH" ]; then
    http_code="$(curl -sS -o "$tmp_body" -w '%{http_code}' \
      --max-time "$REQUEST_TIMEOUT_SEC" \
      -X "$method" \
      -H 'Content-Type: application/json' \
      --data "$CHECK_BODY" \
      "$CHECK_URL" 2>"$curl_stderr" || true)"
  else
    http_code="$(curl -sS -o "$tmp_body" -w '%{http_code}' \
      --max-time "$REQUEST_TIMEOUT_SEC" \
      -X "$method" \
      "$CHECK_URL" 2>"$curl_stderr" || true)"
  fi

  trap - EXIT INT TERM
  rm -f "$tmp_body"

  if [ -z "$http_code" ] || [ "$http_code" = "000" ]; then
    return 1
  fi

  if printf '%s' "$http_code" | grep -Eq "$SUCCESS_HTTP_REGEX"; then
    return 0
  fi

  return 1
}

require_number "REQUEST_TIMEOUT_SEC" "$REQUEST_TIMEOUT_SEC"
require_number "CHECK_INTERVAL_SEC" "$CHECK_INTERVAL_SEC"
require_number "FAIL_THRESHOLD" "$FAIL_THRESHOLD"
require_number "PAUSE_AFTER_RESTART_SEC" "$PAUSE_AFTER_RESTART_SEC"

if [ "$FAIL_THRESHOLD" -lt 1 ]; then
  FAIL_THRESHOLD=1
fi
if [ "$CHECK_INTERVAL_SEC" -lt 1 ]; then
  CHECK_INTERVAL_SEC=1
fi
if [ "$PAUSE_AFTER_RESTART_SEC" -lt 0 ]; then
  PAUSE_AFTER_RESTART_SEC=0
fi

log "started"
log "check_url=${CHECK_URL} interval=${CHECK_INTERVAL_SEC}s threshold=${FAIL_THRESHOLD} pause_after_restart=${PAUSE_AFTER_RESTART_SEC}s"
log "restart_cmd=${RESTART_CMD}"

consecutive_failures=0

while true; do
  if probe_login; then
    if [ "$consecutive_failures" -gt 0 ]; then
      log "probe recovered"
    fi
    consecutive_failures=0
  else
    consecutive_failures=$((consecutive_failures + 1))
    log "probe failed (${consecutive_failures}/${FAIL_THRESHOLD})"

    if [ "$consecutive_failures" -ge "$FAIL_THRESHOLD" ]; then
      log "threshold reached, restarting container"
      if sh -c "$RESTART_CMD"; then
        log "restart command completed"
      else
        log "restart command failed"
      fi

      consecutive_failures=0
      if [ "$PAUSE_AFTER_RESTART_SEC" -gt 0 ]; then
        log "pause ${PAUSE_AFTER_RESTART_SEC}s after restart"
        sleep "$PAUSE_AFTER_RESTART_SEC"
      fi
      continue
    fi
  fi

  sleep "$CHECK_INTERVAL_SEC"
done
