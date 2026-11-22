#!/usr/bin/env bash
set -Eeuo pipefail
umask 0007
php /opt/drivecraft/bootstrap.php
unset DC_ADMIN_PASSWORD
php /opt/drivecraft/worker-loop.php &
worker=$!
apache2-foreground &
web=$!
shutdown() {
  trap - TERM INT
  kill -TERM "$worker" "$web" 2>/dev/null || true
  wait "$worker" "$web" 2>/dev/null || true
}
trap shutdown TERM INT EXIT
set +e
wait -n "$worker" "$web"
result=$?
set -e
exit "$result"
