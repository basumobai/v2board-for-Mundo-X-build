#!/bin/sh

# Let an active schedule:run finish, but interrupt the idle wait on Docker stop.
# A foreground sleep would delay the shell's signal trap until the minute ends.
idle_pid=
stop() {
    if [ -n "$idle_pid" ]; then
        kill "$idle_pid" 2>/dev/null || true
        wait "$idle_pid" 2>/dev/null || true
    fi
    exit 0
}
trap stop TERM INT

while true; do
    php artisan schedule:run --no-interaction
    sleep 60 &
    idle_pid=$!
    wait "$idle_pid" || true
    idle_pid=
done
