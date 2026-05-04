FROM php:8.3-apache

LABEL maintainer="Chapmiam"

ENV PORT=10000
ENV DEBIAN_FRONTEND=noninteractive

# Dépendances système
RUN apt-get update && apt-get install -y --no-install-recommends \
        curl \
        unzip \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libzip-dev \
        libonig-dev \
        libxml2-dev \
        default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

# Configuration GD sans --with-webp (cause de l'erreur)
RUN docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_mysql \
        mysqli \
        gd \
        zip \
        mbstring \
        xml \
        opcache \
        intl \
        bcmath

# PHP production
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
RUN { \
    echo "expose_php = Off"; \
    echo "display_errors = Off"; \
    echo "log_errors = On"; \
    echo "error_log = /dev/stderr"; \
    echo "upload_max_filesize = 10M"; \
    echo "post_max_size = 12M"; \
    echo "memory_limit = 256M"; \
    echo "session.cookie_httponly = 1"; \
    echo "session.use_strict_mode = 1"; \
} >> "$PHP_INI_DIR/php.ini"

# OPcache
RUN { \
    echo "opcache.enable=1"; \
    echo "opcache.memory_consumption=128"; \
    echo "opcache.max_accelerated_files=10000"; \
    echo "opcache.revalidate_freq=60"; \
} > /usr/local/etc/php/conf.d/opcache.ini

# Apache
RUN a2enmod rewrite headers deflate expires

# VirtualHost
COPY docker/apache.conf /etc/apache2/sites-available/chapmiam.conf
RUN a2dissite 000-default.conf && a2ensite chapmiam.conf

WORKDIR /var/www/html

# Copie des fichiers
COPY database/   ./database/
COPY app/        ./app/
COPY public/     ./public/
COPY docker/     ./docker/

# Dossiers runtime
RUN mkdir -p logs storage/ratelimit \
    && echo "Order deny,allow" > logs/.htaccess \
    && echo "Deny from all"   >> logs/.htaccess \
    && cp logs/.htaccess storage/.htaccess \
    && cp logs/.htaccess storage/ratelimit/.htaccess

# Permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod 775 logs storage storage/ratelimit

# Entrypoint
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE ${PORT}

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
