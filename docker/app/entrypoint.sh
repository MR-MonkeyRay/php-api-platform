#!/usr/bin/env sh
set -eu

mkdir -p /var/www/html/var/logs /var/www/html/var/data

if command -v git >/dev/null 2>&1; then
  git config --global --add safe.directory /var/www/html >/dev/null 2>&1 || true
fi

if [ -n "${TZ:-}" ] && [ -f "/usr/share/zoneinfo/${TZ}" ]; then
  ln -snf "/usr/share/zoneinfo/${TZ}" /etc/localtime
  echo "${TZ}" >/etc/timezone
fi

exec "$@"
