#!/bin/sh
set -e

UID=${UID:-1000}
GID=${GID:-1000}

# Resolve or create group
if getent group "$GID" >/dev/null 2>&1; then
    GROUP_NAME=$(getent group "$GID" | cut -d: -f1)
else
    addgroup -g "$GID" www
    GROUP_NAME=www
fi

# Resolve or create user
if getent passwd "$UID" >/dev/null 2>&1; then
    USER_NAME=$(getent passwd "$UID" | cut -d: -f1)
else
    adduser -u "$UID" -G "$GROUP_NAME" -s /bin/sh -D www
    USER_NAME=www
fi

# Update php-fpm pool to use synced user/group (handle commented lines and whitespace)
sed -i "s/^;\?\s*user\s*=.*/user = ${USER_NAME}/" /usr/local/etc/php-fpm.d/www.conf
sed -i "s/^;\?\s*group\s*=.*/group = ${GROUP_NAME}/" /usr/local/etc/php-fpm.d/www.conf
sed -i "s/^;\?\s*listen.owner\s*=.*/listen.owner = ${USER_NAME}/" /usr/local/etc/php-fpm.d/www.conf
sed -i "s/^;\?\s*listen.group\s*=.*/listen.group = ${GROUP_NAME}/" /usr/local/etc/php-fpm.d/www.conf

# Fix ownership before running composer
chown -R "${USER_NAME}:${GROUP_NAME}" /var/www

# Auto-install composer deps if missing, running as the synced user
if [ -f /var/www/composer.json ] && [ ! -d /var/www/vendor ]; then
    su -s /bin/sh "$USER_NAME" -c "cd /var/www && composer install --no-interaction --prefer-dist"
fi

exec php-fpm
