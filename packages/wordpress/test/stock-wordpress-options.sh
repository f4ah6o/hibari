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
if ! php "$package_root/test/stock-wordpress-options.php" "$tmp/wordpress" "$runtime_url"; then
  echo "--- runtime log ---" >&2
  cat "$tmp/runtime.log" >&2 || true
  echo "--- Kintone request log ---" >&2
  cat "$request_log" >&2 || true
  exit 1
fi

if ! grep -Fq 'Option_name = \"hibari_existing\"' "$request_log"; then
  echo "Kintone transport did not observe the existing option selector" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq 'Option_name = \"hibari_added\"' "$request_log"; then
  echo "Kintone transport did not observe the newly added option selector" >&2
  cat "$request_log" >&2
  exit 1
fi

for method_path in \
  '"method":"GET","path":"/k/v1/records.json"' \
  '"method":"PUT","path":"/k/v1/records.json"' \
  '"method":"POST","path":"/k/v1/record.json"' \
  '"method":"DELETE","path":"/k/v1/records.json"'; do
  if ! grep -Fq "$method_path" "$request_log"; then
    echo "Expected Kintone operation not observed: $method_path" >&2
    cat "$request_log" >&2
    exit 1
  fi
done

if ! grep -Fq '"Option_value":{"value":"after"}' "$request_log"; then
  echo "Updated option value was not written to KintoneBackend" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq '"Option_value":{"value":"created"}' "$request_log"; then
  echo "Added option value was not written to KintoneBackend" >&2
  cat "$request_log" >&2
  exit 1
fi
