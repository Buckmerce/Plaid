# shellcheck shell=bash
# ngrok helpers for the public-HTTPS test mode (sourced, never executed).
#
# The agent reads its authtoken from the ngrok configuration file or from NGROK_AUTHTOKEN.
# A reserved domain can be online in only one agent session at a time.

BMFP_NGROK_PID=''

# bmfp_ngrok_start <domain> <local port> <log file>
bmfp_ngrok_start() {
    local domain=$1 port=$2 log=$3
    if ! command -v ngrok >/dev/null 2>&1; then
        printf 'ngrok is not installed (https://ngrok.com/download).\n' >&2
        return 1
    fi
    ngrok http "127.0.0.1:${port}" --url "https://${domain}" --log stdout --log-format logfmt >"$log" 2>&1 &
    BMFP_NGROK_PID=$!
    local _
    for _ in $(seq 1 45); do
        if grep -q 'msg="started tunnel"' "$log" 2>/dev/null; then
            return 0
        fi
        if ! kill -0 "$BMFP_NGROK_PID" 2>/dev/null || grep -qE 'ERR_NGROK_[0-9]+' "$log" 2>/dev/null; then
            printf 'ngrok could not start the tunnel for %s:\n' "$domain" >&2
            grep -oE 'ERR_NGROK_[0-9]+|err="[^"]*"' "$log" | head -3 >&2 || true
            BMFP_NGROK_PID=''
            return 1
        fi
        sleep 1
    done
    printf 'ngrok did not report a started tunnel within 45 seconds.\n' >&2
    return 1
}

bmfp_ngrok_stop() {
    if [[ -n "$BMFP_NGROK_PID" ]]; then
        kill "$BMFP_NGROK_PID" 2>/dev/null || true
        wait "$BMFP_NGROK_PID" 2>/dev/null || true
        BMFP_NGROK_PID=''
    fi
}

# bmfp_wait_public <url>: waits until the public URL answers through the tunnel.
bmfp_wait_public() {
    local url=$1 _
    for _ in $(seq 1 30); do
        if curl -fsS -m 20 -o /dev/null "$url" 2>/dev/null; then
            return 0
        fi
        sleep 2
    done
    printf 'The public URL %s is not reachable through the tunnel.\n' "$url" >&2
    return 1
}
