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
node "$repo_root/test/fixtures/wordpress-kintone-comment-runtime.mjs" "$endpoint_file" "$request_log" \
  >"$tmp/runtime.log" 2>&1 &
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
  echo "Hibari comment runtime HTTP fixture did not become ready" >&2
  cat "$tmp/runtime.log" >&2
  exit 1
fi

runtime_url="$(cat "$endpoint_file")"
if ! php "$package_root/test/stock-wordpress-comment.php" "$tmp/wordpress" "$runtime_url"; then
  echo "--- runtime log ---" >&2
  cat "$tmp/runtime.log" >&2 || true
  echo "--- Kintone request log ---" >&2
  cat "$request_log" >&2 || true
  exit 1
fi

if grep -Fq 'COUNT(*)' "$request_log"; then
  echo "Comment-count aggregate unexpectedly reached Hibari" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq '"app":92' "$request_log"; then
  echo "Comment proof never reached the configured Comment app" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq '"app":93' "$request_log"; then
  echo "Comment proof never reached the configured CommentMeta app" >&2
  cat "$request_log" >&2
  exit 1
fi

comment_creates="$(grep -F '"app":92' "$request_log" | grep -F '"method":"POST","path":"/k/v1/record.json"' | wc -l | tr -d ' ')"
if [[ "$comment_creates" != "1" ]]; then
  echo "Expected exactly one Comment create, observed: $comment_creates" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq '"Comment_content":{"value":"Initial Hibari comment"}' "$request_log"; then
  echo "Initial comment content did not reach KintoneBackend" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq '"Comment_content":{"value":"Updated Hibari comment"}' "$request_log"; then
  echo "Updated comment content did not reach KintoneBackend" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq '"app":93,"record":{"Comment_id":{"value":1},"Meta_key":{"value":"proof_key"},"Meta_value":{"value":"initial-meta"}' "$request_log"; then
  echo "Initial CommentMeta did not use the Dynamic Attributes backend path" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq '"app":93,"query":"Comment_id in (1) order by $id asc"' "$request_log"; then
  echo "CommentMeta was not read through the owner-scoped Dynamic Attributes path" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq '"Meta_value":{"value":"updated-meta"}' "$request_log"; then
  echo "Updated CommentMeta value did not reach KintoneBackend" >&2
  cat "$request_log" >&2
  exit 1
fi

echo "--- Kintone comment request evidence ---"
grep -E '"app":(92|93)' "$request_log" | sed -n '1,40p'
