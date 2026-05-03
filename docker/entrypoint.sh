#!/bin/bash
# =============================================================================
# Chap'miam — docker/entrypoint.sh
# Entrypoint Docker — Gestion du PORT dynamique Render
# =============================================================================
#
# PROBLÈME RENDER :
#   Render injecte $PORT comme variable d'environnement au démarrage du container.
#   Apache doit écouter sur ce port (pas le port 80 habituel).
#   La valeur de $PORT n'est PAS connue au moment du build Dockerfile.
#   → Elle doit être configurée dynamiquement au DÉMARRAGE du container.
#
# CE SCRIPT :
#   1. Lit $PORT (défaut : 10000 si absent)
#   2. Configure Apache pour écouter sur ce port
#   3. Injecte APACHE_PORT dans la configuration VirtualHost
#   4. Crée les dossiers runtime si absents
#   5. Vérifie les variables d'environnement critiques
#   6. Lance Apache en foreground
# =============================================================================

set -e  # Arrêter si une commande échoue

# =============================================================================
# 1. PORT DYNAMIQUE
# =============================================================================
PORT="${PORT:-10000}"
echo "[entrypoint] Port détecté : $PORT"

# Configurer Apache pour écouter sur ce port
cat > /etc/apache2/ports.conf <<EOF
# Généré par docker/entrypoint.sh — Port injecté par Render
Listen ${PORT}
EOF

# Exporter pour que le VirtualHost puisse utiliser ${APACHE_PORT}
export APACHE_PORT="${PORT}"

# =============================================================================
# 2. VÉRIFICATION DES VARIABLES D'ENVIRONNEMENT CRITIQUES
# =============================================================================
MISSING_VARS=0

check_var() {
    local var_name="$1"
    local var_value="${!var_name}"
    if [ -z "$var_value" ] || [ "$var_value" = "CHANGER_CETTE_VALEUR" ]; then
        echo "[entrypoint] ⚠️  Variable manquante ou non configurée : $var_name"
        MISSING_VARS=$((MISSING_VARS + 1))
    fi
}

check_var "DB_HOST"
check_var "DB_NAME"
check_var "DB_USER"
check_var "DB_PASS"
check_var "APP_KEY"

if [ "$MISSING_VARS" -gt 0 ]; then
    echo "[entrypoint] ⚠️  $MISSING_VARS variable(s) critique(s) non configurée(s)."
    echo "[entrypoint]    Configurer dans Render → Environment Variables."
    if [ "${APP_ENV}" = "production" ]; then
        echo "[entrypoint] ❌ APP_ENV=production avec variables manquantes → arrêt."
        exit 1
    fi
fi

# =============================================================================
# 3. CRÉATION DES DOSSIERS RUNTIME
# =============================================================================
mkdir -p /var/www/html/logs
mkdir -p /var/www/html/storage/ratelimit

# Protéger les dossiers sensibles
if [ ! -f /var/www/html/logs/.htaccess ]; then
    printf "Order deny,allow\nDeny from all\n" > /var/www/html/logs/.htaccess
fi
if [ ! -f /var/www/html/storage/.htaccess ]; then
    printf "Order deny,allow\nDeny from all\n" > /var/www/html/storage/.htaccess
fi
if [ ! -f /var/www/html/storage/ratelimit/.htaccess ]; then
    printf "Order deny,allow\nDeny from all\n" > /var/www/html/storage/ratelimit/.htaccess
fi

# Permissions runtime
chown -R www-data:www-data /var/www/html/logs /var/www/html/storage 2>/dev/null || true
chmod 775 /var/www/html/logs /var/www/html/storage /var/www/html/storage/ratelimit 2>/dev/null || true

# =============================================================================
# 4. CONFIGURATION PHP RUNTIME
# =============================================================================
# Désactiver l'affichage des erreurs si en production
if [ "${APP_DEBUG}" = "false" ] || [ "${APP_ENV}" = "production" ]; then
    echo "[entrypoint] Mode PRODUCTION — erreurs PHP désactivées"
    cat > /usr/local/etc/php/conf.d/prod-overrides.ini <<EOF
display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = /dev/stderr
error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT
EOF
else
    echo "[entrypoint] Mode DÉVELOPPEMENT — erreurs PHP activées"
    cat > /usr/local/etc/php/conf.d/dev-overrides.ini <<EOF
display_errors = On
display_startup_errors = On
error_reporting = E_ALL
EOF
fi

# =============================================================================
# 5. TEST DE CONNEXION BASE DE DONNÉES (avec retry)
# =============================================================================
if [ -n "${DB_HOST}" ] && [ -n "${DB_USER}" ] && [ -n "${DB_PASS}" ] && [ -n "${DB_NAME}" ]; then
    echo "[entrypoint] Test de connexion à la base de données..."
    
    DB_PORT="${DB_PORT:-3306}"
    MAX_RETRIES=10
    RETRY_DELAY=3
    
    for i in $(seq 1 $MAX_RETRIES); do
        if mysqladmin ping -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" -p"${DB_PASS}" \
            --silent --connect-timeout=5 2>/dev/null; then
            echo "[entrypoint] ✅ Base de données accessible."
            break
        else
            echo "[entrypoint] ⏳ Tentative $i/$MAX_RETRIES — DB non disponible, attente ${RETRY_DELAY}s..."
            sleep $RETRY_DELAY
            if [ "$i" -eq "$MAX_RETRIES" ]; then
                echo "[entrypoint] ⚠️  DB inaccessible après $MAX_RETRIES tentatives — démarrage quand même."
            fi
        fi
    done
else
    echo "[entrypoint] ⚠️  Variables DB absentes — test de connexion ignoré."
fi

# =============================================================================
# 6. AFFICHAGE DE LA CONFIGURATION (debug uniquement)
# =============================================================================
if [ "${APP_DEBUG}" = "true" ]; then
    echo "=============================================="
    echo " Chap'miam — Configuration de démarrage"
    echo "=============================================="
    echo " APP_ENV    : ${APP_ENV:-non défini}"
    echo " APP_DEBUG  : ${APP_DEBUG:-non défini}"
    echo " APP_URL    : ${APP_URL:-non défini}"
    echo " PORT       : ${PORT}"
    echo " DB_HOST    : ${DB_HOST:-non défini}"
    echo " DB_NAME    : ${DB_NAME:-non défini}"
    echo " LOG_LEVEL  : ${LOG_LEVEL:-debug}"
    echo "=============================================="
fi

# =============================================================================
# 7. LANCEMENT D'APACHE
# =============================================================================
echo "[entrypoint] ✅ Démarrage d'Apache sur le port ${PORT}..."
exec "$@"
