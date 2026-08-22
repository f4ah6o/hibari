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
node "$repo_root/test/fixtures/wordpress-kintone-runtime.mjs" "$endpoint_file" "$request_log" \
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
  echo "Hibari runtime HTTP fixture did not become ready" >&2
  cat "$tmp/runtime.log" >&2
  exit 1
fi

runtime_url="$(cat "$endpoint_file")"
if ! php "$package_root/test/stock-wordpress-postmeta.php" "$tmp/wordpress" "$runtime_url"; then
  echo "--- runtime log ---" >&2
  cat "$tmp/runtime.log" >&2 || true
  echo "--- Kintone request log ---" >&2
  cat "$request_log" >&2 || true
  exit 1
fi

if ! grep -Fq '"app":86' "$request_log"; then
  echo "Postmeta proof never reached the configured Kintone metadata app" >&2
  cat "$request_log" >&2
  exit 1
fi

for method_path in \
  '"method":"POST","path":"/k/v1/record.json"' \
  '"method":"GET","path":"/k/v1/records.json"' \
  '"method":"PUT","path":"/k/v1/records.json"' \
  '"method":"DELETE","path":"/k/v1/records.json"'; do
  if ! grep -Fq "$method_path" "$request_log"; then
    echo "Expected Kintone postmeta operation not observed: $method_path" >&2
    cat "$request_log" >&2
    exit 1
  fi
done

if ! grep -Fq '"Meta_key":{"value":"hibari_label"}' "$request_log"; then
  echo "Postmeta key was not persisted through KintoneBackend" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq '"Meta_value":{"value":"one"}' "$request_log" || \
   ! grep -Fq '"Meta_value":{"value":"two"}' "$request_log"; then
  echo "Multi-value postmeta rows were not independently persisted" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq '"Meta_value":{"value":"updated"}' "$request_log"; then
  echo "Updated postmeta value did not reach KintoneBackend" >&2
  cat "$request_log" >&2
  exit 1
fi

unique_writes="$(grep -F '"Meta_key":{"value":"hibari_unique"}' "$request_log" | grep -c '"method":"POST"' || true)"
if [[ "$unique_writes" != "1" ]]; then
  echo "unique=true should create exactly one Kintone metadata record, observed $unique_writes" >&2
  cat "$request_log" >&2
  exit 1
fi
