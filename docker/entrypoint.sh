#!/bin/sh
# Container entrypoint. CONTAINER_ROLE=app runs migrations and seeders before php-fpm;
# worker and scheduler roles just run their command.
set -e

if [ -z "$APP_KEY" ]; then
    echo "APP_KEY is not set. Copy .env.docker.example to .env at the repo root and set APP_KEY." >&2
    exit 1
fi

if [ "${CONTAINER_ROLE:-app}" = "app" ]; then
    php artisan migrate --force --isolated

    # Roles, permissions, rule and alert defaults: idempotent, never overwrite admin changes,
    # and pick up new rules (e.g. R12) on existing installs.
    php artisan db:seed --class=Database\\Seeders\\RolesAndPermissionsSeeder --force
    php artisan db:seed --class=Database\\Seeders\\RuleAndAlertDefaultsSeeder --force
    php artisan db:seed --class=Database\\Seeders\\ReportTemplatesSeeder --force
    php artisan db:seed --class=Database\\Seeders\\StatesSeeder --force

    if [ "${SEED_DEMO:-false}" = "true" ] && [ "$APP_ENV" != "production" ]; then
        # Demo data only goes into a database with no works yet, so restarts never duplicate it.
        WORKS=$(php artisan tinker --execute='echo \Illuminate\Support\Facades\DB::table("works")->count();' 2>/dev/null | tail -n 1)
        if [ "$WORKS" = "0" ]; then
            echo "Seeding demo data..."
            php artisan db:seed --class=Database\\Seeders\\DemoDataSeeder --force
            php artisan db:seed --class=Database\\Seeders\\DemoProgressSeeder --force
            php artisan db:seed --class=Database\\Seeders\\DemoStatewideSeeder --force
            php artisan db:seed --class=Database\\Seeders\\DemoGrievanceSeeder --force
        fi
    fi

    if [ "$APP_ENV" = "production" ]; then
        php artisan config:cache
        php artisan route:cache
        php artisan view:cache
    fi
fi

exec "$@"
