# =============================================================================
# Chap'miam — Dockerfile
# PHP 8.3 + Apache — Optimisé pour Render (cloud)
# =============================================================================
#
# ARCHITECTURE :
#   - Image officielle php:8.3-apache (Debian Bookworm)
#   - Toute l'app dans /var/www/html/
#   - Le DocumentRoot Apache pointe sur /var/www/html/public/
#   - Le port est dynamique ($PORT injecté par Render, défaut 10000)
#
# BUILD MULTI-STAGE : non utilisé ici (app PHP, pas de compilation)
# mais la structure est prête pour l'ajout d'un stage npm si besoin.
# =============================================================================

FROM php:8.3-apache

# =============================================================================
# 1. MÉTADONNÉES
# =============================================================================
LABEL maintainer="Chap'miam Team"
LABEL description="Application PHP MVC Chap'miam"
LABEL version="2.0"

# =============================================================================
# 2. VARIABLES D'ENVIRONNEMENT DE BUILD
# =============================================================================
# PORT par défaut si Render ne l'injecte pas (ne pas modifier)
ENV PORT=10000
# Empêche les questions interactives lors de apt
ENV DEBIAN_FRONTEND=noninteractive
# PHP : désactive l'exposition de la version
ENV PHP_EXPOSE_PHP=Off

# =============================================================================
# 3. DÉPENDANCES SYSTÈME + EXTENSIONS PHP
# =============================================================================
RUN apt-get update && apt-get install -y --no-install-recommends \
        # Outils système
        curl \
        unzip \
        git \
        # Librairies pour extensions PHP
        libpng-dev \
        libjpeg-dev \
        libwebp-dev \
        libfreetype6-dev \
        libzip-dev \
        libonig-dev \
        libxml2-dev \
        # Client MySQL (pour vérification de connexion au démarrage)
        default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

# Extensions PHP nécessaires
RUN docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
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

# =============================================================================
# 4. CONFIGURATION PHP — PRODUCTION
# =============================================================================
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Surcharge des paramètres clés
RUN { \
    echo ""; \
    echo "; === Chap'miam — Overrides ==="; \
    echo "expose_php = Off"; \
    echo "display_errors = Off"; \
    echo "log_errors = On"; \
    echo "error_log = /dev/stderr"; \
    echo "upload_max_filesize = 10M"; \
    echo "post_max_size = 12M"; \
    echo "max_execution_time = 60"; \
    echo "memory_limit = 256M"; \
    echo "session.cookie_httponly = 1"; \
    echo "session.cookie_samesite = Lax"; \
    echo "session.use_strict_mode = 1"; \
    echo "session.gc_maxlifetime = 1800"; \
    echo "date.timezone = Africa/Porto-Novo"; \
} >> "$PHP_INI_DIR/php.ini"

# OPcache — performances production
RUN { \
    echo "opcache.enable=1"; \
    echo "opcache.memory_consumption=128"; \
    echo "opcache.interned_strings_buffer=16"; \
    echo "opcache.max_accelerated_files=10000"; \
    echo "opcache.revalidate_freq=60"; \
    echo "opcache.fast_shutdown=1"; \
    echo "opcache.validate_timestamps=0"; \
} > /usr/local/etc/php/conf.d/opcache.ini

# =============================================================================
# 5. CONFIGURATION APACHE
# =============================================================================

# Activer les modules Apache nécessaires
RUN a2enmod rewrite headers deflate expires ssl

# Supprimer le VirtualHost par défaut
RUN rm -f /etc/apache2/sites-enabled/000-default.conf

# Copier notre configuration VirtualHost
COPY docker/apache.conf /etc/apache2/sites-available/chapmiam.conf
RUN a2ensite chapmiam.conf

# =============================================================================
# 6. RÉPERTOIRE DE TRAVAIL
# =============================================================================
WORKDIR /var/www/html

# =============================================================================
# 7. COPIE DU CODE SOURCE
# =============================================================================
# Copier d'abord les fichiers moins souvent modifiés (meilleur cache layers)
COPY database/   ./database/
COPY app/        ./app/
COPY public/     ./public/

# Créer les dossiers runtime avec les bonnes permissions
RUN mkdir -p \
        logs \
        storage/ratelimit \
    && touch logs/.gitkeep storage/ratelimit/.gitkeep

# Créer les .htaccess de protection
RUN echo "Order deny,allow" > logs/.htaccess \
    && echo "Deny from all"  >> logs/.htaccess \
    && cp logs/.htaccess storage/.htaccess \
    && cp logs/.htaccess storage/ratelimit/.htaccess

# =============================================================================
# 8. PERMISSIONS
# =============================================================================
# www-data = utilisateur Apache dans le container
RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \; \
    # Dossiers en écriture pour www-data
    && chmod 775 logs storage storage/ratelimit \
    # Protéger les fichiers de config
    && chmod 640 .env.example 2>/dev/null || true \
    # L'entrypoint doit être exécutable
    && chmod +x /usr/local/bin/docker-entrypoint.sh 2>/dev/null || true

# =============================================================================
# 9. ENTRYPOINT — Gestion du PORT dynamique Render
# =============================================================================
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# =============================================================================
# 10. HEALTHCHECK
# =============================================================================
HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=3 \
    CMD curl -f http://localhost:${PORT}/index.php?page=accueil \
        -H "X-Health-Check: docker" \
        --silent --output /dev/null \
    || exit 1

# =============================================================================
# 11. EXPOSITION ET LANCEMENT
# =============================================================================
# PORT est dynamique — Render l'injecte via variable d'environnement
EXPOSE ${PORT}

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
