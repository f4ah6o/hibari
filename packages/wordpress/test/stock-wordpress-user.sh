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
request_log="$tmp/kintone-requests.redacted.jsonl"
touch "$request_log"
node "$repo_root/test/fixtures/wordpress-kintone-user-runtime.mjs" "$endpoint_file" "$request_log" \
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
  echo "Hibari user runtime HTTP fixture did not become ready" >&2
  cat "$tmp/runtime.log" >&2
  exit 1
fi

runtime_url="$(cat "$endpoint_file")"
if ! php "$package_root/test/stock-wordpress-user.php" "$tmp/wordpress" "$runtime_url"; then
  echo "--- runtime log ---" >&2
  cat "$tmp/runtime.log" >&2 || true
  echo "--- redacted Kintone request log ---" >&2
  cat "$request_log" >&2 || true
  exit 1
fi

if grep -Fq 'HibariUserProofPlaintext-DoNotLog-2026!' "$request_log" || \
   grep -Fq 'DuplicateLoginProofPlaintext-DoNotLog!' "$request_log" || \
   grep -Fq 'DuplicateEmailProofPlaintext-DoNotLog!' "$request_log"; then
  echo "Plain-text password material appeared in Kintone request evidence" >&2
  exit 1
fi

if grep -Eq '\$(wp\$)?2[aby]\$|\$P\$|\$H\$' "$request_log"; then
  echo "Password hash material appeared in Kintone request evidence" >&2
  exit 1
fi

if ! grep -Fq '"User_pass":{"value":"[REDACTED]"}' "$request_log"; then
  echo "User write did not pass through the redacted credential boundary" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq '"app":90' "$request_log"; then
  echo "User proof never reached the configured User app" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq '"app":91' "$request_log"; then
  echo "User proof never reached the configured UserMeta app" >&2
  cat "$request_log" >&2
  exit 1
fi

user_creates="$(grep -F '"app":90' "$request_log" | grep -F '"method":"POST","path":"/k/v1/record.json"' | wc -l | tr -d ' ')"
if [[ "$user_creates" != "1" ]]; then
  echo "Duplicate login/email attempts should leave exactly one User create, observed: $user_creates" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq 'User_login = \"hibari_user\"' "$request_log"; then
  echo "Login uniqueness/lookup was not pushed down as bounded User query" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq 'User_email = \"hibari.user@example.test\"' "$request_log"; then
  echo "Email uniqueness/lookup was not pushed down as bounded User query" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq '"app":91,"record":{"User_id":{"value":1},"Meta_key":{"value":"nickname"},"Meta_value":{"value":"Hibari Nick"}' "$request_log"; then
  echo "User nickname metadata was not persisted through Dynamic Attributes" >&2
  cat "$request_log" >&2
  exit 1
fi

if ! grep -Fq '"app":91,"query":"User_id in (1) order by $id asc"' "$request_log"; then
  echo "User metadata was not read through the owner-scoped Dynamic Attributes path" >&2
  cat "$request_log" >&2
  exit 1
fi

echo "--- redacted Kintone user request evidence ---"
grep -E '"app":(90|91)' "$request_log" | sed -n '1,40p'
