# ✅ CHECKLIST RENDER & VERDICT FINAL — Chap'miam Docker
> Déploiement PHP MVC sur Render via Docker

---

## 🐳 DOCKER

| Vérification | Fichier | Statut |
|---|---|---|
| Dockerfile PHP 8.3 + Apache | `Dockerfile` | ✅ Fourni |
| mod_rewrite + mod_headers activés | `Dockerfile` | ✅ |
| Extensions PDO, pdo_mysql, gd, zip | `Dockerfile` | ✅ |
| OPcache production configuré | `Dockerfile` | ✅ |
| Configuration PHP production | `Dockerfile` | ✅ |
| PORT dynamique Render ($PORT) | `docker/entrypoint.sh` | ✅ |
| VirtualHost Apache DocumentRoot /public | `docker/apache.conf` | ✅ |
| AllowOverride All (active .htaccess) | `docker/apache.conf` | ✅ |
| Logs Apache → /dev/stderr | `docker/apache.conf` | ✅ |
| Retry connexion DB au démarrage | `docker/entrypoint.sh` | ✅ |
| Healthcheck configuré | `Dockerfile` | ✅ |
| .dockerignore propre (exclut .env, logs) | `.dockerignore` | ✅ |
| docker-compose.yml dev local | `docker-compose.yml` | ✅ |

---

## ⚙️ VARIABLES D'ENVIRONNEMENT

| Variable | Valeur production | Obligatoire |
|---|---|---|
| `APP_ENV` | `production` | ✅ |
| `APP_DEBUG` | `false` | ✅ |
| `APP_URL` | `https://votre-app.onrender.com` | ✅ |
| `APP_KEY` | 64 chars hex générés | ✅ |
| `DB_HOST` | Host PlanetScale | ✅ |
| `DB_NAME` | `chapmiam` | ✅ |
| `DB_USER` | User PlanetScale | ✅ |
| `DB_PASS` | Mot de passe fort | ✅ |
| `DB_SSL` | `true` | ✅ si PlanetScale |
| `SESSION_LIFETIME` | `1800` | ✅ |
| `LOG_LEVEL` | `warning` | ✅ |
| `CINETPAY_ENV` | `PROD` | Si paiements |
| `MAIL_USER` | Email expéditeur | Si emails |

---

## 🗄️ BASE DE DONNÉES

| Vérification | Statut |
|---|---|
| PDO avec `getenv()` (pas de hardcode) | ✅ |
| Support `DATABASE_URL` (format cloud) | ✅ |
| Support SSL/TLS pour bases cloud | ✅ |
| Reconnexion automatique (ping avant requête) | ✅ |
| Gestion d'erreur sans exposer les credentials | ✅ |
| Script `install.sql` complet | ✅ |
| Index SQL de performance | ✅ |

---

## 🌐 ROUTES & HTACCESS

| Vérification | Statut |
|---|---|
| mod_rewrite → index.php | ✅ |
| Pas de redirection HTTPS (gérée par Render) | ✅ |
| Headers sécurité (CSP, X-Frame, HSTS) | ✅ |
| Blocage accès fichiers sensibles (.env, .sql, .log) | ✅ |
| Compression GZIP | ✅ |
| Cache navigateur assets | ✅ |
| 404 → app/views/errors/404.php | ✅ |
| 500 → app/views/errors/500.php | ✅ |

---

## 🔐 SÉCURITÉ

| Vérification | Statut |
|---|---|
| HTTPS détecté via X-Forwarded-Proto (Render proxy) | ✅ |
| `session.cookie_secure` automatique selon HTTPS | ✅ |
| `session.cookie_httponly = 1` | ✅ |
| `session.use_strict_mode = 1` | ✅ |
| CSRF sur tous les formulaires POST | ✅ |
| Rate limiting login (5 essais / 15 min) | ✅ |
| Open redirect bloqué dans Session::redirect() | ✅ |
| Log injection protégé (URI/IP sanitisées) | ✅ |
| IP anonymisée dans les logs (RGPD) | ✅ |
| Email hashé HMAC dans les logs (RGPD) | ✅ |
| Whitelist colonnes SQL dans User::update() | ✅ |
| XSS : Security::e() sur toutes les sorties | ✅ |
| Credentials en getenv() (pas hardcodés) | ✅ |
| .env absent du dépôt Git | ✅ |

---

## 📝 LOGS

| Vérification | Statut |
|---|---|
| En Docker/Render : logs → stderr (collecté par Render) | ✅ |
| En local : logs → fichier avec rotation | ✅ |
| Rotation automatique à 10 Mo | ✅ |
| Suppression archives > 30 jours | ✅ |
| `LOG_LEVEL=warning` en production | ✅ |
| Pas d'email en clair dans les logs | ✅ |
| Tokens masqués dans les URI loggées | ✅ |

---

## 🚀 COMPATIBILITÉ RENDER

| Vérification | Détail | Statut |
|---|---|---|
| PORT dynamique | `$PORT` injecté par Render, Apache configuré à la volée | ✅ |
| Reverse proxy HTTPS | `X-Forwarded-Proto` lu dans Session.php | ✅ |
| Système de fichiers éphémère | RateLimiter adapté (/tmp ou storage/) | ✅ |
| Variables env sans .env | Toutes les vars via `getenv()` | ✅ |
| Logs cloud | Écriture sur `php://stderr` → Render Logs | ✅ |
| Health check | `/index.php?page=accueil` | ✅ |
| Auto-deploy | `render.yaml` + `autoDeploy: true` | ✅ |
| Blueprint Render | `render.yaml` fourni | ✅ |

---

## 🏁 VERDICT FINAL

```
╔═══════════════════════════════════════════════════════╗
║                                                       ║
║   ✅ PRÊT POUR DÉPLOIEMENT SUR RENDER                 ║
║                                                       ║
║   Étapes restantes (manuelles) :                     ║
║                                                       ║
║   1. Créer une base MySQL sur PlanetScale             ║
║      → Importer database/install.sql                 ║
║                                                       ║
║   2. Créer votre service Web sur Render               ║
║      → Runtime : Docker                              ║
║      → Connecter votre repo GitHub                   ║
║                                                       ║
║   3. Configurer les variables d'environnement         ║
║      dans Render Dashboard                           ║
║      (APP_KEY, DB_HOST, DB_USER, DB_PASS, DB_SSL)    ║
║                                                       ║
║   4. Premier déploiement → Render pull + build        ║
║      → Suivre dans : Service → Logs                  ║
║                                                       ║
╚═══════════════════════════════════════════════════════╝
```

### Temps estimé de déploiement initial
- Build Docker : 2-4 minutes
- Démarrage du container : 30 secondes
- **Total : ~5 minutes**

### Commandes utiles post-déploiement
```bash
# Voir les logs Render en temps réel (via CLI Render)
render logs --service chapmiam --tail

# Tester l'application déployée
curl -I https://chapmiam.onrender.com/index.php?page=accueil

# Tester le CSRF (doit retourner 403)
curl -s -o /dev/null -w "%{http_code}" \
  -X POST https://chapmiam.onrender.com/index.php?page=login \
  -d "email=test@test.com&mot_de_passe=Test1234"

# Vérifier les headers de sécurité
curl -sI https://chapmiam.onrender.com | grep -E "(X-Frame|Content-Security|Strict-Transport)"
```
