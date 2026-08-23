#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "$0")/../../.." && pwd)"
package_root="$repo_root/packages/wordpress"
wordpress_version="7.1"
tmp="$(mktemp -d)"
runtime_pid=""

cleanup() {
  if [[ -n "$runtime_pid" ]] && kill -0 "$runtime_pid" 2>/dev/null; then
    kill "$runtime_pid" 2>/dev/null || true
    wait "$runtime_pid" 2>/dev/null || true
  fi
  rm -rf "$tmp"
}
trap cleanup EXIT

for php_file in "$package_root"/db.php "$package_root"/src/*.php "$package_root"/test/*.php; do
  php -l "$php_file" >/dev/null
done

npm run build --silent

curl -fsSL "https://wordpress.org/wordpress-${wordpress_version}.tar.gz" -o "$tmp/wordpress.tar.gz"
tar -xzf "$tmp/wordpress.tar.gz" -C "$tmp"

actual_version="$(php -r 'include $argv[1]; echo $wp_version;' "$tmp/wordpress/wp-includes/version.php")"
if [[ "$actual_version" != "$wordpress_version" ]]; then
  echo "Expected WordPress $wordpress_version, got $actual_version" >&2
  exit 1
fi

endpoint_file="$tmp/runtime-url"
request_log="$tmp/kintone-requests.jsonl"
touch "$request_log"
node "$repo_root/test/fixtures/wordpress-kintone-delete-runtime.mjs" \
  "$endpoint_file" "$request_log" >"$tmp/runtime.log" 2>&1 &
runtime_pid=$!

for _ in $(seq 1 100); do
  if [[ -s "$endpoint_file" ]]; then
    break
  fi
  if ! kill -0 "$runtime_pid" 2>/dev/null; then
    cat "$tmp/runtime.log" >&2
    exit 1
  fi
  sleep 0.05
done

if [[ ! -s "$endpoint_file" ]]; then
  echo "Hibari page-delete runtime HTTP fixture did not become ready" >&2
  cat "$tmp/runtime.log" >&2
  exit 1
fi

runtime_url="$(cat "$endpoint_file")"
if ! php "$package_root/test/stock-wordpress-page-delete.php" "$tmp/wordpress" "$runtime_url"; then
  echo "--- runtime log ---" >&2
  cat "$tmp/runtime.log" >&2 || true
  echo "--- Kintone request log ---" >&2
  cat "$request_log" >&2 || true
  exit 1
fi

require_request() {
  local needle="$1"
  local message="$2"
  if ! grep -Fq "$needle" "$request_log"; then
    echo "$message" >&2
    cat "$request_log" >&2
    exit 1
  fi
}

require_request '"method":"POST","path":"/k/v1/record.json","body":{"app":85' \
  "Page delete proof did not create the Post through app 85"
require_request '"method":"POST","path":"/k/v1/record.json","body":{"app":86' \
  "Page delete proof did not create dependent PostMeta through app 86"
require_request '"method":"POST","path":"/k/v1/record.json","body":{"app":92' \
  "Page delete proof did not create dependent Comment through app 92"
require_request '"method":"DELETE","path":"/k/v1/records.json","body":{"app":92' \
  "wp_delete_post() did not delete the dependent Comment through app 92"
require_request '"method":"DELETE","path":"/k/v1/records.json","body":{"app":86' \
  "wp_delete_post() did not delete dependent PostMeta through app 86"
require_request '"method":"DELETE","path":"/k/v1/records.json","body":{"app":85' \
  "wp_delete_post() did not delete the page Post through app 85"
require_request 'Post_parent = 1' \
  "wp_delete_post() did not execute the bounded parent-scoped Post lifecycle query"
require_request 'Comment_post_ID = 1' \
  "wp_delete_post() did not enumerate dependent comments by post ID"
require_request 'Post_id = 1' \
  "wp_delete_post() did not enumerate dependent PostMeta by owner ID"

echo "--- Kintone page-delete request evidence ---"
cat "$request_log"
