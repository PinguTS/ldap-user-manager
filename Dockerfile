FROM php:8-apache

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

# PHPMailer holen
ADD https://github.com/PHPMailer/PHPMailer/archive/refs/tags/v6.3.0.tar.gz /tmp

RUN a2enmod rewrite ssl && a2dissite 000-default default-ssl

EXPOSE 80
EXPOSE 443

COPY www/ /opt/ldap_user_manager
RUN tar -xzf /tmp/v6.3.0.tar.gz -C /opt && mv /opt/PHPMailer-6.3.0 /opt/PHPMailer

RUN chown -R www-data:www-data /opt/ldap_user_manager
RUN find /opt/ldap_user_manager -type d -exec chmod 755 {} \;
RUN find /opt/ldap_user_manager -type f -exec chmod 644 {} \;

COPY entrypoint /usr/local/bin/entrypoint
RUN chmod a+x /usr/local/bin/entrypoint && touch /etc/ldap/ldap.conf

CMD ["apache2-foreground"]
ENTRYPOINT ["/usr/local/bin/entrypoint"]