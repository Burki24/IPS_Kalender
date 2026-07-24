#!/usr/bin/env bash
set -euo pipefail

if ! php -r 'exit(function_exists("curl_init") ? 0 : 1);'; then
  echo "PHP cURL extension is required for the CalDAV HTTP integration test." >&2
  exit 1
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP_DIR="$(mktemp -d)"
PRIMARY_LOG="${TMP_DIR}/primary.log"
SECONDARY_LOG="${TMP_DIR}/secondary.log"
PRIMARY_SERVER_LOG="${TMP_DIR}/primary-server.log"
SECONDARY_SERVER_LOG="${TMP_DIR}/secondary-server.log"
: > "${PRIMARY_LOG}"
: > "${SECONDARY_LOG}"

find_free_port() {
  php -r '$socket = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr); if ($socket === false) { fwrite(STDERR, $errstr); exit(1); } $name = stream_socket_get_name($socket, false); fclose($socket); echo substr(strrchr($name, ":"), 1);'
}

PRIMARY_PORT="$(find_free_port)"
SECONDARY_PORT="$(find_free_port)"
while [[ "${SECONDARY_PORT}" == "${PRIMARY_PORT}" ]]; do
  SECONDARY_PORT="$(find_free_port)"
done

cleanup() {
  local exit_code=$?
  if [[ -n "${PRIMARY_PID:-}" ]]; then kill "${PRIMARY_PID}" 2>/dev/null || true; fi
  if [[ -n "${SECONDARY_PID:-}" ]]; then kill "${SECONDARY_PID}" 2>/dev/null || true; fi
  wait "${PRIMARY_PID:-}" 2>/dev/null || true
  wait "${SECONDARY_PID:-}" 2>/dev/null || true
  if [[ ${exit_code} -ne 0 ]]; then
    echo "--- primary test server ---" >&2
    cat "${PRIMARY_SERVER_LOG}" >&2 || true
    echo "--- secondary test server ---" >&2
    cat "${SECONDARY_SERVER_LOG}" >&2 || true
  fi
  rm -rf "${TMP_DIR}"
  exit "${exit_code}"
}
trap cleanup EXIT

CALDAV_TEST_LOG="${PRIMARY_LOG}" \
CALDAV_TEST_SECONDARY_PORT="${SECONDARY_PORT}" \
php -S "127.0.0.1:${PRIMARY_PORT}" "${ROOT_DIR}/tests/fixtures/caldav-http-router.php" \
  >"${PRIMARY_SERVER_LOG}" 2>&1 &
PRIMARY_PID=$!

CALDAV_TEST_LOG="${SECONDARY_LOG}" \
CALDAV_TEST_SECONDARY_PORT="${PRIMARY_PORT}" \
php -S "127.0.0.1:${SECONDARY_PORT}" "${ROOT_DIR}/tests/fixtures/caldav-http-router.php" \
  >"${SECONDARY_SERVER_LOG}" 2>&1 &
SECONDARY_PID=$!

wait_for_server() {
  local port="$1"
  for _ in {1..50}; do
    if php -r '$url = $argv[1]; $context = stream_context_create(["http" => ["timeout" => 0.2, "ignore_errors" => true]]); $body = @file_get_contents($url, false, $context); exit($body === "ok" ? 0 : 1);' "http://127.0.0.1:${port}/health"; then
      return 0
    fi
    sleep 0.1
  done
  return 1
}

wait_for_server "${PRIMARY_PORT}"
wait_for_server "${SECONDARY_PORT}"

php "${ROOT_DIR}/tests/caldav-http.php" \
  "http://127.0.0.1:${PRIMARY_PORT}" \
  "http://127.0.0.1:${SECONDARY_PORT}" \
  "${PRIMARY_LOG}" \
  "${SECONDARY_LOG}"
