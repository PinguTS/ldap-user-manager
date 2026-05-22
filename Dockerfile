# Composer stage: install PHP dependencies
FROM composer:2 AS builder
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction

FROM php:8.2-apache

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libldap2-dev libldap-common libldb-dev \
        libfreetype6-dev libjpeg-dev libpng-dev \
        pkg-config; \
    rm -rf /var/lib/apt/lists/*

# gd bauen
RUN set -eux; \
    docker-php-ext-configure gd --with-freetype; \
    docker-php-ext-install -j"$(nproc)" gd

# ldap bauen: Multiarch-Kennung holen und gezielt übergeben
RUN set -eux; \
    arch="$(dpkg-architecture -q DEB_HOST_MULTIARCH)"; \
    docker-php-ext-configure ldap --with-libdir="lib/${arch}"; \
    docker-php-ext-install -j"$(nproc)" ldap

# Enable Apache modules for security, performance, and URL rewriting
RUN a2enmod rewrite ssl headers expires deflate && a2dissite 000-default default-ssl

# Suppress "Could not reliably determine the server's fully qualified domain name"
RUN echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf && a2enconf servername

# Set PHP date.timezone to avoid "Invalid date.timezone value ''" startup warning
RUN echo "date.timezone = UTC" > /usr/local/etc/php/conf.d/99-timezone.ini

# Copy Apache configuration
COPY apache/ /etc/apache2/conf-available/

EXPOSE 80
EXPOSE 443

COPY www/ /opt/ldap_user_manager
COPY --from=builder /app/vendor /opt/ldap_user_manager/vendor

# State directory for rate-limit files, setup lock, password-reset tokens, etc.
# Must be writable by www-data; override with APP_STATE_DIR.
ENV APP_STATE_DIR=/var/lib/ldap_user_manager
# Default session directory (writable by www-data; override with SESSION_SAVE_PATH)
ENV SESSION_SAVE_PATH=/var/lib/ldap_user_manager/sessions
RUN mkdir -p "$APP_STATE_DIR" "$SESSION_SAVE_PATH" && \
    chown -R www-data:www-data "$APP_STATE_DIR"

RUN chown -R www-data:www-data /opt/ldap_user_manager
RUN find /opt/ldap_user_manager -type d -exec chmod 755 {} \;
RUN find /opt/ldap_user_manager -type f -exec chmod 644 {} \;

COPY entrypoint /usr/local/bin/entrypoint
RUN chmod a+x /usr/local/bin/entrypoint && touch /etc/ldap/ldap.conf

CMD ["apache2-foreground"]
ENTRYPOINT ["/usr/local/bin/entrypoint"]
