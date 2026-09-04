#!/bin/sh
# ============================================================
# Render entrypoint for the quiz PHP API container.
#
# The official php:apache image hardcodes Apache to Listen 80.
# Render requires the app to bind to $PORT (default 10000).
# This script rewrites the Apache listen config to $PORT, then
# delegates to the official image entrypoint (which runs
# apache2-foreground).
# ============================================================
set -e

# Render sets PORT; default to 10000 if unset (matches Render default).
PORT="${PORT:-10000}"

# Substitute the actual port into the listen + vhost configs
# (Listen directive is present in both for robustness).
sed -i "s/Listen \${PORT}/Listen ${PORT}/g" /etc/apache2/ports.conf
sed -i "s/\${PORT}/${PORT}/g" /etc/apache2/sites-available/000-default.conf

# Optional sanity check: log a warning if Apache config is invalid, but do
# NOT hard-fail — apache2-foreground itself performs the authoritative
# config check and prints the real error to the container logs.
apache2ctl -t >/dev/null 2>&1 || {
    echo "WARNING: Apache configuration test failed." >&2
}

# Hand off to the official image entrypoint, preserving its args
# (CMD defaults to apache2-foreground).
exec /usr/local/bin/docker-php-entrypoint "$@"
