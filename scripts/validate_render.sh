#!/bin/bash
# =============================================================================
# Chap'miam — scripts/validate_render.sh
# Validation complète avant déploiement sur Render
# =============================================================================
#
# UTILISATION :
#   chmod +x scripts/validate_render.sh
#   ./scripts/validate_render.sh
#
# Ce script vérifie :
#   1. La présence de tous les fichiers requis
#   2. La configuration Docker
#   3. Les variables d'environnement critiques
#   4. La sécurité (fichiers sensibles absents de Git)
#   5. La santé de l'application si Docker est disponible
# =============================================================================

set -euo pipefail

# ── Couleurs ─────────────────────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color
BOLD='\033[1m'

# ── Compteurs ─────────────────────────────────────────────────────────────────
PASS=0
WARN=0
FAIL=0

ok()   { echo -e "${GREEN}  ✅ $1${NC}"; PASS=$((PASS+1)); }
warn() { echo -e "${YELLOW}  ⚠️  $1${NC}"; WARN=$((WARN+1)); }
fail() { echo -e "${RED}  ❌ $1${NC}"; FAIL=$((FAIL+1)); }
info() { echo -e "${BLUE}  ℹ️  $1${NC}"; }
sep()  { echo -e "\n${BOLD}$1${NC}"; }

echo ""
echo -e "${BOLD}╔══════════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}║     Chap'miam — Validation Render/Docker             ║${NC}"
echo -e "${BOLD}╚══════════════════════════════════════════════════════╝${NC}"
echo ""

# =============================================================================
# 1. FICHIERS DOCKER REQUIS
# =============================================================================
sep "📁 [1/6] Fichiers Docker requis"

check_file() {
    if [ -f "$1" ]; then
        ok "$(basename $1) présent"
    else
        fail "$1 MANQUANT"
    fi
}

check_dir() {
    if [ -d "$1" ]; then
        ok "Dossier $1 présent"
    else
        fail "Dossier $1 MANQUANT"
    fi
}

check_file "Dockerfile"
check_file ".dockerignore"
check_file "docker/apache.conf"
check_file "docker/entrypoint.sh"
check_file "render.yaml"
check_file "public/.htaccess"
check_file "public/index.php"
check_file ".env.example"
check_file "app/config/Database.php"
check_file "app/config/Logger.php"
check_file "app/helpers/Session.php"
check_file "app/helpers/Security.php"
check_file "app/helpers/RateLimiter.php"
check_file "app/views/errors/404.php"
check_file "app/views/errors/500.php"
check_file "database/install.sql"

# Vérifier que entrypoint.sh est exécutable
if [ -f "docker/entrypoint.sh" ]; then
    if [ -x "docker/entrypoint.sh" ]; then
        ok "entrypoint.sh est exécutable"
    else
        warn "entrypoint.sh n'est pas exécutable — Exécuter : chmod +x docker/entrypoint.sh"
    fi
fi

# =============================================================================
# 2. SÉCURITÉ GIT
# =============================================================================
sep "🔐 [2/6] Sécurité Git"

# Vérifier que .env n'est pas commité
if git rev-parse --git-dir > /dev/null 2>&1; then
    if git ls-files .env | grep -q ".env"; then
        fail ".env est commité dans Git — CRITIQUE : git rm --cached .env"
    else
        ok ".env absent du dépôt Git"
    fi

    # Vérifier .gitignore
    if grep -q "^\.env$" .gitignore 2>/dev/null; then
        ok ".env dans .gitignore"
    else
        warn ".env non présent dans .gitignore — risque de commit accidentel"
    fi

    # Vérifier logs/
    if grep -q "^logs/" .gitignore 2>/dev/null || grep -q "^logs$" .gitignore 2>/dev/null; then
        ok "logs/ dans .gitignore"
    else
        warn "logs/ non présent dans .gitignore"
    fi
else
    warn "Pas de dépôt Git — vérifier manuellement que .env n'est pas partagé"
fi

# Vérifier qu'il n'y a pas de credentials hardcodés
if grep -rq "password_hash\|VotreMotDe\|CHANGER_CETTE_VALEUR\|password = ''\|password = \"\"" app/config/Database.php 2>/dev/null; then
    fail "Credentials potentiellement hardcodés dans Database.php"
else
    ok "Pas de credentials hardcodés détectés dans Database.php"
fi

# =============================================================================
# 3. CONFIGURATION DOCKERFILE
# =============================================================================
sep "🐳 [3/6] Configuration Dockerfile"

if [ -f "Dockerfile" ]; then
    # Vérifier PHP 8.x
    if grep -q "php:8\." Dockerfile; then
        ok "PHP 8.x configuré dans Dockerfile"
    else
        warn "Version PHP non standard dans Dockerfile"
    fi

    # Vérifier mod_rewrite
    if grep -q "a2enmod rewrite" Dockerfile; then
        ok "mod_rewrite activé dans Dockerfile"
    else
        fail "mod_rewrite non activé — les routes ne fonctionneront pas"
    fi

    # Vérifier PDO MySQL
    if grep -q "pdo_mysql" Dockerfile; then
        ok "Extension pdo_mysql installée"
    else
        fail "Extension pdo_mysql manquante"
    fi

    # Vérifier entrypoint
    if grep -q "entrypoint" Dockerfile; then
        ok "Entrypoint configuré dans Dockerfile"
    else
        fail "Entrypoint manquant dans Dockerfile"
    fi

    # Vérifier EXPOSE
    if grep -q "EXPOSE" Dockerfile; then
        ok "EXPOSE configuré dans Dockerfile"
    else
        warn "EXPOSE absent du Dockerfile (Render le gère via PORT)"
    fi
fi

# Vérifier apache.conf
if [ -f "docker/apache.conf" ]; then
    if grep -q "AllowOverride All" docker/apache.conf; then
        ok "AllowOverride All configuré (active .htaccess)"
    else
        fail "AllowOverride All manquant — .htaccess ne fonctionnera pas"
    fi

    if grep -q "/var/www/html/public" docker/apache.conf; then
        ok "DocumentRoot pointe vers /public"
    else
        fail "DocumentRoot ne pointe pas vers /public"
    fi
fi

# =============================================================================
# 4. CONFIGURATION .HTACCESS (VERSION RENDER)
# =============================================================================
sep "🌐 [4/6] Configuration .htaccess"

if [ -f "public/.htaccess" ]; then
    # Vérifier absence de redirection HTTPS (incompatible Render)
    if grep -q "^RewriteCond %{HTTPS} off" public/.htaccess && ! grep -q "Forwarded-Proto" public/.htaccess; then
        fail ".htaccess redirige HTTP→HTTPS sans vérifier X-Forwarded-Proto"
        info "Sur Render, cela crée une boucle infinie. Utiliser la version adaptée."
    else
        ok "Pas de redirection HTTPS directe (Render gère HTTPS en amont)"
    fi

    # Vérifier mod_rewrite
    if grep -q "RewriteEngine On" public/.htaccess; then
        ok "mod_rewrite activé dans .htaccess"
    else
        fail "mod_rewrite non activé dans .htaccess"
    fi

    # Vérifier routage vers index.php
    if grep -q "index.php" public/.htaccess; then
        ok "Routage vers index.php configuré"
    else
        fail "Routage vers index.php manquant dans .htaccess"
    fi

    # Vérifier blocage du .env
    if grep -q '\.env' public/.htaccess; then
        ok "Protection fichier .env dans .htaccess"
    else
        warn "Blocage du fichier .env non configuré dans .htaccess"
    fi

    # Vérifier headers de sécurité
    if grep -q "X-Frame-Options" public/.htaccess; then
        ok "Headers de sécurité présents (X-Frame-Options)"
    else
        warn "Headers de sécurité absents du .htaccess"
    fi
fi

# =============================================================================
# 5. FICHIERS PHP CRITIQUES
# =============================================================================
sep "🔧 [5/6] Fichiers PHP"

# Database.php - support DATABASE_URL
if [ -f "app/config/Database.php" ]; then
    if grep -q "DATABASE_URL\|MYSQL_URL" app/config/Database.php; then
        ok "Database.php supporte DATABASE_URL (format Render)"
    else
        warn "Database.php ne supporte pas DATABASE_URL — n'utilisera que DB_HOST/DB_USER/etc."
    fi

    if grep -q "getenv" app/config/Database.php; then
        ok "Database.php utilise getenv() (pas de hardcode)"
    else
        fail "Database.php n'utilise pas getenv() — credentials potentiellement hardcodés"
    fi
fi

# Logger.php - sortie stderr Docker
if [ -f "app/config/Logger.php" ]; then
    if grep -q "stderr\|RENDER\|dockerenv" app/config/Logger.php; then
        ok "Logger.php adapté pour Docker/Render (stderr)"
    else
        warn "Logger.php n'est pas adapté pour Docker — les logs peuvent ne pas apparaître dans Render"
    fi
fi

# Session.php - X-Forwarded-Proto
if [ -f "app/helpers/Session.php" ]; then
    if grep -q "X_FORWARDED_PROTO\|HTTP_X_FORWARDED_PROTO" app/helpers/Session.php; then
        ok "Session.php détecte X-Forwarded-Proto (Render reverse proxy)"
    else
        fail "Session.php ne détecte pas X-Forwarded-Proto — cookie_secure=false sur Render HTTPS"
    fi
fi

# index.php - pas de chargement .env bloquant
if [ -f "public/index.php" ]; then
    if grep -q "file_exists.*\.env\|silenc" public/index.php; then
        ok "index.php charge .env silencieusement (ok si absent sur Render)"
    else
        warn "Vérifier que index.php ne plante pas si .env est absent"
    fi

    if grep -q "APP_DEBUG\|APP_ENV" public/index.php; then
        ok "index.php utilise APP_DEBUG/APP_ENV depuis getenv()"
    fi
fi

# =============================================================================
# 6. VALIDATION DOCKER (si Docker disponible)
# =============================================================================
sep "🏗️  [6/6] Build Docker (si disponible)"

if command -v docker &> /dev/null; then
    info "Docker détecté — Test de build..."
    if docker build -t chapmiam:validate . --quiet 2>/dev/null; then
        ok "Build Docker réussi"
        docker rmi chapmiam:validate --force > /dev/null 2>&1 || true
    else
        fail "Build Docker échoué — voir les erreurs avec : docker build -t chapmiam:test ."
    fi
else
    warn "Docker non disponible — build non testé"
    info "Installer Docker Desktop : https://docker.com/products/docker-desktop"
fi

# =============================================================================
# RÉSUMÉ FINAL
# =============================================================================
echo ""
echo -e "${BOLD}╔══════════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}║                   RÉSUMÉ FINAL                      ║${NC}"
echo -e "${BOLD}╚══════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  ${GREEN}✅ Validés  : $PASS${NC}"
echo -e "  ${YELLOW}⚠️  Avertissements : $WARN${NC}"
echo -e "  ${RED}❌ Échecs   : $FAIL${NC}"
echo ""

if [ "$FAIL" -eq 0 ] && [ "$WARN" -le 3 ]; then
    echo -e "${GREEN}${BOLD}  🚀 APPLICATION PRÊTE POUR RENDER${NC}"
    echo -e "  Prochaine étape : make push (ou git push origin main)"
elif [ "$FAIL" -eq 0 ]; then
    echo -e "${YELLOW}${BOLD}  ⚠️  PRÊTE AVEC AVERTISSEMENTS — Corriger les warnings avant déploiement${NC}"
else
    echo -e "${RED}${BOLD}  ❌ NON PRÊTE — Corriger les $FAIL erreur(s) avant déploiement${NC}"
    exit 1
fi

echo ""
