#!/bin/sh
set -eu
cd -- "$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
echo 'GeoTexture Delivery: http://127.0.0.1:8088'
echo 'Login: admin / admin; Ctrl+C to stop.'
exec php -S 127.0.0.1:8088 -t public
