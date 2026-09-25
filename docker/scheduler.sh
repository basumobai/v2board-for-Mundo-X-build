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
    # Align the next pass to a wall-clock minute. A fixed sleep after the
    # command drifts later on every run and can eventually skip due events.
    now=$(date +%s)
    delay=$((60 - now % 60))
    sleep "$delay" &
    idle_pid=$!
    wait "$idle_pid" || true
    idle_pid=
done
