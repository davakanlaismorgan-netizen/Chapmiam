# 🚀 Guide de Déploiement — Chap'miam sur Render
> Guide complet · Docker + PHP + Apache + PlanetScale (MySQL)

---

## 📋 PRÉREQUIS

| Outil | Lien | Usage |
|-------|------|-------|
| Compte Render | https://render.com | Hébergeur cloud |
| Compte GitHub | https://github.com | Dépôt du code |
| Compte PlanetScale | https://planetscale.com | MySQL cloud gratuit |
| Docker Desktop | https://docker.com/products/docker-desktop | Tests locaux |

---

## 🗄️ ÉTAPE 1 — Base de données (PlanetScale)

PlanetScale est **MySQL compatible** et dispose d'un **plan gratuit**. C'est la meilleure option pour Render.

### 1.1 Créer la base de données

```bash
# Dans le dashboard PlanetScale :
# New database → Nom : "chapmiam" → Région : EU West (le plus proche du Bénin)
```

### 1.2 Créer la branche de production

```bash
# PlanetScale → votre DB → Branches → Create branch
# Nom : "main" (déjà créée par défaut)
```

### 1.3 Importer le schéma

```bash
# Option A : Interface PlanetScale → Console SQL → Coller le contenu de database/install.sql

# Option B : Via CLI PlanetScale
pscale shell chapmiam main < database/install.sql
```

### 1.4 Récupérer les credentials

```
PlanetScale → votre DB → Connect → Connect with : PHP (PDO)
→ Copier : host, username, password
→ IMPORTANT : DB_SSL=true obligatoire avec PlanetScale
```

---

## 📁 ÉTAPE 2 — Préparer le dépôt Git

### 2.1 Structure minimale requise

```
chapmiam/
├── Dockerfile              ← Fourni
├── .dockerignore           ← Fourni
├── render.yaml             ← Fourni
├── docker-compose.yml      ← Pour tests locaux
├── docker/
│   ├── apache.conf         ← Fourni
│   └── entrypoint.sh       ← Fourni
├── public/
│   ├── index.php           ← Fourni (adapté Render)
│   └── .htaccess           ← Fourni (adapté Render, sans redirect HTTPS)
├── app/
│   ├── config/
│   │   ├── Database.php    ← Fourni (supporte DATABASE_URL)
│   │   └── Logger.php      ← Fourni (stderr Docker)
│   ├── helpers/
│   │   ├── Session.php     ← Fourni (X-Forwarded-Proto)
│   │   ├── Security.php
│   │   └── RateLimiter.php ← Fourni (adapté fs éphémère)
│   ├── controllers/        ← Vos contrôleurs existants
│   ├── models/             ← Vos modèles existants
│   └── views/              ← Vos vues existantes
└── database/
    └── install.sql         ← Script d'installation DB
```

### 2.2 Initialiser Git

```bash
cd chapmiam/
git init
git add .
git commit -m "feat: configuration Docker + Render"
```

### 2.3 Vérifier que .env n'est pas commité

```bash
git ls-files .env
# Doit retourner RIEN (vide)
# Si .env apparaît : git rm --cached .env
```

### 2.4 Pousser sur GitHub

```bash
git remote add origin https://github.com/VOTRE_USER/chapmiam.git
git branch -M main
git push -u origin main
```

---

## 🌐 ÉTAPE 3 — Déploiement sur Render

### 3.1 Créer le service Web

```
Render Dashboard → New → Web Service
→ Connect a repository → Sélectionner chapmiam
→ Runtime : Docker
→ Render détecte automatiquement le Dockerfile
```

### 3.2 Configurer les paramètres

```
Name     : chapmiam
Region   : Frankfurt EU (EU West)
Branch   : main
Plan     : Free (ou Starter pour production)
```

### 3.3 Variables d'environnement — Dashboard Render

Aller dans : **Service → Environment → Add Environment Variable**

#### Variables OBLIGATOIRES

| Variable | Valeur | Note |
|----------|--------|------|
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | |
| `APP_URL` | `https://chapmiam.onrender.com` | URL Render ou domaine custom |
| `APP_KEY` | `<64 chars hex>` | `php -r "echo bin2hex(random_bytes(32));"` |
| `DB_HOST` | `<host PlanetScale>` | Ex: `aws.connect.psdb.cloud` |
| `DB_NAME` | `chapmiam` | |
| `DB_USER` | `<user PlanetScale>` | |
| `DB_PASS` | `<password PlanetScale>` | |
| `DB_SSL` | `true` | Obligatoire pour PlanetScale |
| `SESSION_LIFETIME` | `1800` | |
| `LOG_LEVEL` | `warning` | Pas de debug en prod |
| `CINETPAY_ENV` | `PROD` | |

#### Variables OPTIONNELLES

| Variable | Valeur | Note |
|----------|--------|------|
| `MAIL_HOST` | `smtp.gmail.com` | Si emails activés |
| `MAIL_USER` | `votre@email.com` | |
| `MAIL_PASS` | `<app password>` | Mot de passe d'application Gmail |
| `CINETPAY_API_KEY` | `<clé>` | |
| `CINETPAY_SITE_ID` | `<id>` | |

### 3.4 Lancer le déploiement

```
Render → Create Web Service
→ Render construit l'image Docker (2-5 minutes)
→ Suivre les logs dans : Service → Logs
```

---

## 🧪 ÉTAPE 4 — Tests locaux avant déploiement

### 4.1 Test avec Docker Compose

```bash
# Copier les variables d'environnement
cp .env.example .env
# Éditer .env avec vos valeurs locales

# Construire et démarrer
docker-compose up -d

# Vérifier que tout démarre
docker-compose ps
docker-compose logs -f app

# Tester
curl http://localhost:8080/index.php?page=accueil
```

### 4.2 Test de connexion DB

```bash
# Vérifier les logs de démarrage
docker-compose logs app | grep -E "(DB|connexion|entrypoint)"

# Tester manuellement dans le container
docker-compose exec app php -r "
require_once '/var/www/html/app/config/Logger.php';
require_once '/var/www/html/app/config/Database.php';
\$db = Database::getInstance();
echo \$db->query('SELECT 1 as ok')->fetch()['ok'] === 1 ? 'DB OK' : 'ERREUR';
"
```

### 4.3 Test de sécurité

```bash
# Test CSRF (doit retourner 403)
curl -s -o /dev/null -w "%{http_code}" \
  -X POST http://localhost:8080/index.php?page=login \
  -d "email=test@test.com&mot_de_passe=Test1234!"
# Attendu : 403

# Test route inconnue (doit retourner 404)
curl -s -o /dev/null -w "%{http_code}" \
  http://localhost:8080/index.php?page=page-inexistante
# Attendu : 404

# Test accès fichiers sensibles (doit retourner 403)
curl -s -o /dev/null -w "%{http_code}" \
  http://localhost:8080/../app/config/Database.php
# Attendu : 403 ou 400
```

---

## ✅ CHECKLIST FINALE RENDER

### 🔴 Critiques — Bloquer le déploiement si non coché

- [ ] `Dockerfile` présent à la racine du repo
- [ ] `docker/apache.conf` présent et référencé dans Dockerfile
- [ ] `docker/entrypoint.sh` présent, exécutable (`chmod +x`)
- [ ] `.dockerignore` présent (exclut `.env`, `logs/`, etc.)
- [ ] `public/.htaccess` VERSION RENDER (sans redirection HTTPS)
- [ ] `APP_ENV=production` configuré sur Render
- [ ] `APP_DEBUG=false` configuré sur Render
- [ ] `APP_KEY` généré et configuré (64 chars hex)
- [ ] `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` configurés sur Render
- [ ] `DB_SSL=true` si PlanetScale
- [ ] `.env` absent du dépôt Git (`git ls-files .env` = vide)
- [ ] Schema SQL importé dans PlanetScale (`install.sql`)

### 🟠 Importants

- [ ] `LOG_LEVEL=warning` (pas debug en prod)
- [ ] `CINETPAY_ENV=PROD` si paiements activés
- [ ] `APP_URL=https://votre-app.onrender.com` (URL exacte)
- [ ] Health check passe : `/index.php?page=accueil`
- [ ] Render Logs ne montrent pas d'erreur au démarrage

### 🟡 Recommandés

- [ ] Domaine custom configuré dans Render (DNS + SSL auto)
- [ ] Plan Starter activé (évite les cold starts du plan Free)
- [ ] `render.yaml` commité pour le déploiement blueprint

---

## 🔍 RÉSOLUTION DES PROBLÈMES COURANTS

### ❌ "Port is already in use" ou container qui ne démarre pas

```bash
# Vérifier les logs de l'entrypoint
docker logs chapmiam_app 2>&1 | head -30
# Vérifier le port utilisé
docker ps
```

### ❌ "SQLSTATE: Connection refused"

```bash
# Vérifier les variables DB sur Render
# Vérifier que la DB PlanetScale est bien démarrée
# Vérifier DB_SSL=true pour PlanetScale
```

### ❌ Erreur 500 en production

```bash
# Consulter les logs Render
Render Dashboard → Service → Logs → Filtrer par "ERROR"
# Activer temporairement APP_DEBUG=true pour diagnostiquer
# Remettre APP_DEBUG=false après diagnostic
```

### ❌ Images ou assets non chargés

```bash
# Vérifier que public/assets/ existe et est dans le repo Git
# Vérifier .dockerignore (ne pas exclure assets/)
git ls-files public/assets/
```

### ❌ Sessions perdues après redéploiement

```bash
# Normal sur Render Free (fs éphémère)
# Les sessions PHP sont stockées dans /tmp → perdu au redémarrage
# Solution : configurer session.save_handler = redis avec Upstash Redis
```

---

## 📊 VERDICT FINAL

| Composant | Statut | Notes |
|-----------|--------|-------|
| 🐳 Docker | ✅ Prêt | PHP 8.3 + Apache, PORT dynamique |
| 🌐 Apache | ✅ Prêt | mod_rewrite, headers sécurité |
| 🔀 Routage | ✅ Prêt | .htaccess → index.php |
| 🔐 HTTPS | ✅ Prêt | Géré par Render (proxy) |
| 🍪 Sessions | ✅ Prêt | X-Forwarded-Proto détecté |
| 🗄️ Base de données | ✅ Prêt | PDO + SSL + reconnexion auto |
| 📝 Logs | ✅ Prêt | stderr → Render Logs |
| ⚙️ Variables env | ✅ Prêt | Render dashboard |
| 🛡️ Sécurité | ✅ Prêt | CSRF, XSS, Rate limit |

**→ Application prête pour le déploiement sur Render.**
