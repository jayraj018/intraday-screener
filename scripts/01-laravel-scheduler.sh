#!/bin/bash

# Laravel's scheduler needs something to call `schedule:run` every minute. The base image
# runs nginx and php-fpm only, with no cron, so the 09:05 scan declared in
# routes/console.php has never actually fired in production — signals only ever appeared
# when /api/run-screener was hit by hand.
#
# This starts a minute loop alongside php-fpm. It is detached so container startup is not
# blocked, and its output goes to the container log.
#
# IMPORTANT, on Render's free plan: the instance sleeps after 15 idle minutes and this
# loop dies with it, so a 09:05 run only fires if something has already woken the service.
# Treat this as correct-but-best-effort there, and drive the scan from an external cron
# (cron-job.org, GitHub Actions, UptimeRobot) hitting:
#
#   https://<your-app>/api/run-screener?token=<BACKTEST_TOKEN>
#
# On a paid instance that stays awake, this loop is sufficient on its own.

echo "Starting Laravel scheduler loop (every 60s)..."

(
    while true; do
        php /var/www/html/artisan schedule:run --no-interaction >> /dev/stdout 2>&1
        sleep 60
    done
) &

echo "Laravel scheduler loop started (pid $!)."
