#!/bin/sh
set -e

cd /var/www/html

rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php

wait_for_database() {
    if [ "${WAIT_FOR_DB:-true}" != "true" ]; then
        return 0
    fi

    echo "Waiting for database..."
    attempts=0
    until php artisan db:show >/dev/null 2>&1; do
        attempts=$((attempts + 1))
        if [ "$attempts" -ge 30 ]; then
            echo "Database not reachable within 60s."
            exit 1
        fi
        sleep 2
    done
}

wait_for_redis() {
    if [ "${WAIT_FOR_REDIS:-true}" != "true" ]; then
        return 0
    fi

    if [ "${QUEUE_CONNECTION:-redis}" != "redis" ] && [ "${CACHE_STORE:-redis}" != "redis" ]; then
        return 0
    fi

    echo "Waiting for Redis..."
    attempts=0
    until php -r '
        $host = getenv("REDIS_HOST") ?: "127.0.0.1";
        $port = (int) (getenv("REDIS_PORT") ?: 6379);
        $redis = new Redis();
        if (! @$redis->connect($host, $port, 2.0)) {
            exit(1);
        }
        $redis->ping();
    ' >/dev/null 2>&1; do
        attempts=$((attempts + 1))
        if [ "$attempts" -ge 30 ]; then
            echo "Redis not reachable within 60s."
            exit 1
        fi
        sleep 2
    done
}

prepare_runtime() {
    mkdir -p storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache

    chown -R www-data:www-data storage bootstrap/cache
    chmod -R ug+rwx storage bootstrap/cache
}

run_deploy_tasks() {
    if [ "${RUN_DEPLOY_TASKS:-true}" != "true" ]; then
        return 0
    fi

    php artisan package:discover --ansi --no-interaction
    php artisan storage:link --force >/dev/null 2>&1 || true
    php artisan migrate --force --no-interaction

    if [ "${APP_ENV:-local}" = "production" ]; then
        php artisan config:cache --no-interaction
        php artisan route:cache --no-interaction
        php artisan view:cache --no-interaction
    fi
}

wait_for_database
wait_for_redis
prepare_runtime
run_deploy_tasks

echo "Deamon Plane ready (port 8080)."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
