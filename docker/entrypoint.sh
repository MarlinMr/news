#!/bin/sh
set -eu

if [ "${1:-}" = 'apache2-foreground' ]; then
    php /usr/local/lib/geonews/configure-frontend-assets.php
fi

exec docker-php-entrypoint "$@"
