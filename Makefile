# =============================================================================
# Chap'miam — Makefile
# Raccourcis pour les commandes Docker courantes
# =============================================================================
#
# UTILISATION :
#   make build     → Construire l'image Docker
#   make up        → Démarrer tous les services
#   make down      → Arrêter tous les services
#   make logs      → Voir les logs en temps réel
#   make test      → Lancer les tests de santé
#   make shell     → Ouvrir un shell dans le container
#   make clean     → Supprimer containers + volumes
#
# =============================================================================

.PHONY: build up down logs test shell clean push help

# Variables
IMAGE_NAME  = chapmiam
IMAGE_TAG   = latest
CONTAINER   = chapmiam_app
PORT        = 8080

# ── BUILD ──────────────────────────────────────────────────────────────────
build:
	@echo "🔨 Construction de l'image Docker..."
	docker build -t $(IMAGE_NAME):$(IMAGE_TAG) .
	@echo "✅ Image construite : $(IMAGE_NAME):$(IMAGE_TAG)"

# ── DÉVELOPPEMENT LOCAL ────────────────────────────────────────────────────
up:
	@echo "🚀 Démarrage de l'environnement..."
	docker-compose up -d
	@echo "✅ Application : http://localhost:$(PORT)"
	@echo "✅ PHPMyAdmin  : http://localhost:8081 (avec --profile dev)"

up-dev:
	@echo "🚀 Démarrage avec PHPMyAdmin..."
	docker-compose --profile dev up -d

down:
	@echo "⏹️  Arrêt des services..."
	docker-compose down

restart:
	docker-compose restart app

# ── LOGS ───────────────────────────────────────────────────────────────────
logs:
	docker-compose logs -f app

logs-db:
	docker-compose logs -f db

logs-all:
	docker-compose logs -f

# ── TESTS ──────────────────────────────────────────────────────────────────
test:
	@echo "🧪 Tests de santé..."
	@echo ""
	@echo "→ Page accueil..."
	@curl -s -o /dev/null -w "  HTTP %{http_code} — %{url_effective}\n" \
		http://localhost:$(PORT)/index.php?page=accueil
	@echo ""
	@echo "→ Test CSRF (POST sans token, attendu: 403)..."
	@curl -s -o /dev/null -w "  HTTP %{http_code} — CSRF test\n" \
		-X POST http://localhost:$(PORT)/index.php?page=login \
		-d "email=test@test.com&mot_de_passe=Test1234"
	@echo ""
	@echo "→ Test route inconnue (attendu: 404)..."
	@curl -s -o /dev/null -w "  HTTP %{http_code} — Route inconnue\n" \
		http://localhost:$(PORT)/index.php?page=page-inexistante-xyz
	@echo ""
	@echo "→ Test accès logs (attendu: 403)..."
	@curl -s -o /dev/null -w "  HTTP %{http_code} — Accès /logs/\n" \
		http://localhost:$(PORT)/../logs/app.log 2>/dev/null || true
	@echo ""
	@echo "→ Test headers de sécurité..."
	@curl -sI http://localhost:$(PORT)/index.php | grep -E "(X-Frame|X-Content|Content-Security|Strict-Transport)"
	@echo ""
	@echo "✅ Tests terminés."

test-db:
	@echo "🧪 Test connexion base de données..."
	docker-compose exec app php -r "\
		require_once '/var/www/html/app/config/Logger.php'; \
		require_once '/var/www/html/app/config/Database.php'; \
		try { \
			\$$db = Database::getInstance(); \
			\$$r  = \$$db->query('SELECT VERSION() as v')->fetch(); \
			echo '✅ DB connectée — MySQL ' . \$$r['v'] . PHP_EOL; \
		} catch (Exception \$$e) { \
			echo '❌ Erreur : ' . \$$e->getMessage() . PHP_EOL; \
		} \
	"

# ── SHELL ──────────────────────────────────────────────────────────────────
shell:
	docker-compose exec app bash

shell-db:
	docker-compose exec db mysql -u$${DB_USER:-chapmiam_user} -p$${DB_PASS:-chapmiam_pass_dev} $${DB_NAME:-chapmiam}

# ── NETTOYAGE ──────────────────────────────────────────────────────────────
clean:
	@echo "🧹 Nettoyage..."
	docker-compose down -v --remove-orphans
	docker rmi $(IMAGE_NAME):$(IMAGE_TAG) 2>/dev/null || true
	rm -f logs/*.log logs/*.old
	@echo "✅ Nettoyage terminé."

prune:
	@echo "🧹 Nettoyage Docker complet..."
	docker system prune -f
	docker volume prune -f

# ── PRODUCTION (simulation locale) ─────────────────────────────────────────
prod-local:
	@echo "🚀 Démarrage en mode production (simulation)..."
	APP_ENV=production APP_DEBUG=false docker-compose up -d

# ── DÉPLOIEMENT RENDER ─────────────────────────────────────────────────────
push:
	@echo "📤 Push vers GitHub..."
	git add .
	git commit -m "deploy: mise à jour $(shell date +%Y-%m-%d)"
	git push origin main
	@echo "✅ Render déploie automatiquement depuis GitHub."

# ── AIDE ───────────────────────────────────────────────────────────────────
help:
	@echo ""
	@echo "  Chap'miam — Commandes disponibles"
	@echo "  =================================="
	@echo ""
	@echo "  make build       Construire l'image Docker"
	@echo "  make up          Démarrer l'environnement de dev"
	@echo "  make up-dev      Démarrer avec PHPMyAdmin"
	@echo "  make down        Arrêter les services"
	@echo "  make restart     Redémarrer l'app"
	@echo "  make logs        Logs de l'app en temps réel"
	@echo "  make logs-all    Logs de tous les services"
	@echo "  make test        Tests de santé HTTP"
	@echo "  make test-db     Test de connexion DB"
	@echo "  make shell       Shell dans le container app"
	@echo "  make shell-db    Shell MySQL"
	@echo "  make clean       Nettoyer containers + volumes"
	@echo "  make push        Git push → Render déploie"
	@echo ""
