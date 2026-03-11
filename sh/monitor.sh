#!/bin/sh

set -eu

# 是否允许探测公网地址（默认不允许，避免外网波动导致误重启）
MONITOR_ALLOW_PUBLIC="${MONITOR_ALLOW_PUBLIC:-0}"

extract_url_host() {
  raw_url="$1"
  rest="${raw_url#*://}"
  rest="${rest%%/*}"
  rest="${rest%%\?*}"
  rest="${rest%%\#*}"
  case "$rest" in
    *@*)
      rest="${rest##*@}"
      ;;
  esac
  case "$rest" in
    \[*\]*)
      # IPv6: [::1]:3000
      host="${rest%%]*}"
      host="${host#[}"
      ;;
    *)
      host="${rest%%:*}"
      ;;
  esac
  printf '%s' "$host"
}

is_private_probe_host() {
  host="$1"
  case "$host" in
    localhost|127.*|0.0.0.0|::1|host.docker.internal|voicehub|voicehub-voicehub-1|postgres|*.local)
      return 0
      ;;
    10.*|192.168.*|172.1[6-9].*|172.2[0-9].*|172.3[0-1].*)
      return 0
      ;;
  esac
  return 1
}

sanitize_probe_url() {
  probe_url="$1"
  fallback_url="$2"
  probe_name="$3"

  if [ -z "$probe_url" ]; then
    printf '%s' ""
    return 0
  fi

  if [ "$MONITOR_ALLOW_PUBLIC" = "1" ]; then
    printf '%s' "$probe_url"
    return 0
  fi

  host="$(extract_url_host "$probe_url")"
  if is_private_probe_host "$host"; then
    printf '%s' "$probe_url"
    return 0
  fi

  printf '%s %s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "[monitor]" \
    "${probe_name} uses public host (${host}), fallback to ${fallback_url}" >&2
  printf '%s' "$fallback_url"
}

# Optional shared base URL (prefer local/private address, e.g. http://127.0.0.1:3000/rareapp)
MONITOR_BASE_URL="${MONITOR_BASE_URL:-}"
if [ -n "$MONITOR_BASE_URL" ]; then
  MONITOR_BASE_URL="${MONITOR_BASE_URL%/}"
fi

default_check_url="http://127.0.0.1:3000/rareapp/api/auth/login"
default_check_url_2="http://127.0.0.1:3000/rareapp/"
if [ -n "$MONITOR_BASE_URL" ]; then
  base_host="$(extract_url_host "$MONITOR_BASE_URL")"
  if [ "$MONITOR_ALLOW_PUBLIC" = "1" ] || is_private_probe_host "$base_host"; then
    default_check_url="${MONITOR_BASE_URL}/api/auth/login"
    default_check_url_2="${MONITOR_BASE_URL}/"
  else
    printf '%s %s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "[monitor]" \
      "MONITOR_BASE_URL is public (${base_host}), ignored" >&2
  fi
fi

# Primary probe: login API
CHECK_URL="${CHECK_URL:-$default_check_url}"
# Secondary probe: public page (set empty to disable)
CHECK_URL_2="${CHECK_URL_2:-$default_check_url_2}"
CHECK_URL="$(sanitize_probe_url "$CHECK_URL" "$default_check_url" "CHECK_URL")"
CHECK_URL_2="$(sanitize_probe_url "$CHECK_URL_2" "$default_check_url_2" "CHECK_URL_2")"

# Probe behavior
CHECK_METHOD="${CHECK_METHOD:-POST}"
CHECK_BODY="${CHECK_BODY:-{\"username\":\"monitor_probe\",\"password\":\"invalid_password\"}}"
CHECK_METHOD_2="${CHECK_METHOD_2:-GET}"
REQUEST_TIMEOUT_SEC="${REQUEST_TIMEOUT_SEC:-4}"
CHECK_INTERVAL_SEC="${CHECK_INTERVAL_SEC:-5}"

# Restart behavior
FAIL_THRESHOLD="${FAIL_THRESHOLD:-2}"
PAUSE_AFTER_RESTART_SEC="${PAUSE_AFTER_RESTART_SEC:-30}"
RESTART_CMD="${RESTART_CMD:-docker compose restart voicehub || docker-compose restart voicehub || docker restart voicehub}"

# login API常见返回401/400（无效凭据）也代表服务存活；但不接受404/5xx
SUCCESS_HTTP_REGEX="${SUCCESS_HTTP_REGEX:-^(200|400|401|403)$}"
# 首页探针默认必须200
SUCCESS_HTTP_REGEX_2="${SUCCESS_HTTP_REGEX_2:-^200$}"
CURL_SILENT_ERRORS="${CURL_SILENT_ERRORS:-1}"

log() {
  printf '%s %s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "[monitor]" "$*"
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

probe_request() {
  probe_url="$1"
  probe_method="$2"
  probe_body="$3"
  probe_regex="$4"

  if [ -z "$probe_url" ]; then
    return 0
  fi

  tmp_body="$(mktemp)"
  cleanup() {
    rm -f "$tmp_body"
  }
  trap cleanup EXIT INT TERM

  curl_stderr="/dev/stderr"
  if [ "$CURL_SILENT_ERRORS" = "1" ]; then
    curl_stderr="/dev/null"
  fi

  method="$(printf '%s' "$probe_method" | tr '[:lower:]' '[:upper:]')"
  if [ "$method" = "POST" ] || [ "$method" = "PUT" ] || [ "$method" = "PATCH" ]; then
    http_code="$(curl -sS -o "$tmp_body" -w '%{http_code}' \
      --max-time "$REQUEST_TIMEOUT_SEC" \
      -X "$method" \
      -H 'Content-Type: application/json' \
      --data "$probe_body" \
      "$probe_url" 2>"$curl_stderr" || true)"
  else
    http_code="$(curl -sS -o "$tmp_body" -w '%{http_code}' \
      --max-time "$REQUEST_TIMEOUT_SEC" \
      -X "$method" \
      "$probe_url" 2>"$curl_stderr" || true)"
  fi

  trap - EXIT INT TERM
  rm -f "$tmp_body"

  if [ -z "$http_code" ] || [ "$http_code" = "000" ]; then
    return 1
  fi

  if printf '%s' "$http_code" | grep -Eq "$probe_regex"; then
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
log "check_url=${CHECK_URL} check_url_2=${CHECK_URL_2:-disabled} interval=${CHECK_INTERVAL_SEC}s threshold=${FAIL_THRESHOLD} pause_after_restart=${PAUSE_AFTER_RESTART_SEC}s"
log "restart_cmd=${RESTART_CMD}"

consecutive_failures=0

while true; do
  primary_ok=0
  secondary_ok=0

  if probe_request "$CHECK_URL" "$CHECK_METHOD" "$CHECK_BODY" "$SUCCESS_HTTP_REGEX"; then
    primary_ok=1
  fi
  if probe_request "$CHECK_URL_2" "$CHECK_METHOD_2" "" "$SUCCESS_HTTP_REGEX_2"; then
    secondary_ok=1
  fi

  if [ "$primary_ok" -eq 1 ] && [ "$secondary_ok" -eq 1 ]; then
    if [ "$consecutive_failures" -gt 0 ]; then
      log "probe recovered"
    fi
    consecutive_failures=0
  else
    consecutive_failures=$((consecutive_failures + 1))
    fail_parts=""
    if [ "$primary_ok" -ne 1 ]; then
      fail_parts="primary"
    fi
    if [ "$secondary_ok" -ne 1 ]; then
      if [ -n "$fail_parts" ]; then
        fail_parts="${fail_parts}+secondary"
      else
        fail_parts="secondary"
      fi
    fi
    log "probe failed (${consecutive_failures}/${FAIL_THRESHOLD}) failed=${fail_parts}"

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
