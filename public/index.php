<?php
/**
 * =============================================================================
 * Chap'miam — public/index.php — VERSION RENDER/DOCKER
 * =============================================================================
 *
 * ADAPTATIONS RENDER :
 *
 *   1. HTTPS Detection :
 *      Render est un reverse proxy HTTPS → les requêtes arrivent en HTTP
 *      dans le container. X-Forwarded-Proto doit être lu pour détecter HTTPS.
 *      Session::start() le gère déjà via HTTP_X_FORWARDED_PROTO.
 *
 *   2. Variables d'environnement :
 *      Sur Render, les variables sont injectées directement via getenv().
 *      Le fichier .env n'existe PAS sur Render.
 *      Le loader .env est conservé pour le développement local UNIQUEMENT.
 *
 *   3. Logs → STDERR (Docker/Render) :
 *      En production Docker, les logs fichiers sont évités au profit de stderr
 *      qui est collecté nativement par Render.
 *
 *   4. Port :
 *      Apache écoute sur $PORT (configuré par entrypoint.sh).
 *      index.php n'a pas à gérer le port — c'est la couche Apache.
 *
 * =============================================================================
 */

declare(strict_types=1);

// =============================================================================
// 1. CHARGEMENT .ENV — Développement local UNIQUEMENT
//    Sur Render, les variables sont injectées directement → ce bloc est ignoré
// =============================================================================
(static function (): void {
    $envFile = dirname(__DIR__) . '/.env';

    // Sur Render, .env n'existe pas → skip silencieux
    if (!file_exists($envFile)) {
        return;
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        // Ignorer commentaires et lignes vides
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $rawValue] = explode('=', $line, 2);
        $key = trim($key);
        if ($key === '') {
            continue;
        }

        $rawValue = trim($rawValue);

        // Retirer les commentaires inline : "false  # note" → "false"
        if (preg_match('/^([^#"\']*?)(\s+#.*)?$/', $rawValue, $m)) {
            $rawValue = trim($m[1]);
        }
        $rawValue = trim($rawValue, '"\'');

        // Ne pas écraser les variables déjà définies (ex: variables Render)
        if (!isset($_ENV[$key]) && getenv($key) === false) {
            putenv("{$key}={$rawValue}");
            $_ENV[$key]    = $rawValue;
            $_SERVER[$key] = $rawValue;
        }
    }
})();

// =============================================================================
// 2. CONFIGURATION DEBUG
// =============================================================================
$appEnv   = getenv('APP_ENV')   ?: 'development';
$appDebug = getenv('APP_DEBUG') === 'true';
$isDocker = getenv('RENDER')    !== false || file_exists('/.dockerenv');

if ($appDebug && $appEnv !== 'production') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    // Sur Render/Docker : logs → stderr (collecté par Render)
    ini_set('log_errors', '1');
    ini_set('error_log', 'php://stderr');
}

// =============================================================================
// 3. NONCE CSP — Généré par requête
// =============================================================================
$GLOBALS['csp_nonce'] = base64_encode(random_bytes(16));

// =============================================================================
// 4. CHARGEMENT DES CLASSES DE BASE
// =============================================================================
$appRoot = dirname(__DIR__);

require_once $appRoot . '/app/config/Logger.php';
require_once $appRoot . '/app/config/Database.php';
require_once $appRoot . '/app/helpers/Session.php';
require_once $appRoot . '/app/helpers/Security.php';
require_once $appRoot . '/app/helpers/RateLimiter.php';
require_once $appRoot . '/app/helpers/Utils.php';

// Initialiser le logger
Logger::init();

// Démarrer la session sécurisée
Session::start();

// =============================================================================
// 5. AUTOLOADER
// =============================================================================
spl_autoload_register(static function (string $class) use ($appRoot): void {
    $dirs = [
        $appRoot . '/app/controllers/',
        $appRoot . '/app/models/',
        $appRoot . '/app/helpers/',
        $appRoot . '/app/config/',
    ];
    foreach ($dirs as $dir) {
        $path = $dir . $class . '.php';
        if (file_exists($path)) {
            require_once $path;
            return;
        }
    }
});

// =============================================================================
// 6. TABLE DE ROUTAGE
// =============================================================================
$routes = [
    // ── Public ──────────────────────────────────────────────────────────────
    'accueil'                  => ['HomeController',        'index'],
    'recherche'                => ['HomeController',        'search'],
    'about'                    => ['HomeController',        'about'],
    'contact'                  => ['HomeController',        'contact'],
    'restaurants'              => ['RestaurantController',  'index'],
    'restaurant-detail'        => ['RestaurantController',  'detail'],
    'menu'                     => ['PlatController',        'index'],
    'plat-detail'              => ['PlatController',        'detail'],

    // ── Authentification ────────────────────────────────────────────────────
    'login'                    => ['AuthController',        'login'],
    'register'                 => ['AuthController',        'register'],
    'logout'                   => ['AuthController',        'logout'],
    'profil'                   => ['AuthController',        'profil'],
    'mot-de-passe-oublie'      => ['AuthController',        'forgotPassword'],
    'reinitialiser-mdp'        => ['AuthController',        'resetPassword'],

    // ── Panier ──────────────────────────────────────────────────────────────
    'panier'                   => ['PanierController',      'index'],
    'panier-add'               => ['PanierController',      'add'],
    'panier-update'            => ['PanierController',      'update'],
    'panier-remove'            => ['PanierController',      'remove'],
    'panier-clear'             => ['PanierController',      'clear'],
    'panier-count'             => ['PanierController',      'count'],
    'panier-promo'             => ['PanierController',      'applyPromo'],

    // ── Commandes ───────────────────────────────────────────────────────────
    'commander'                => ['CommandeController',    'create'],
    'suivi-commande'           => ['CommandeController',    'suivi'],
    'mes-commandes'            => ['CommandeController',    'mesCommandes'],
    'commande-statut'          => ['CommandeController',    'updateStatus'],
    'commande-annuler'         => ['CommandeController',    'annuler'],
    'facture'                  => ['CommandeController',    'facture'],

    // ── Paiement ────────────────────────────────────────────────────────────
    'paiement'                 => ['PaiementController',    'page'],
    'paiement-process'         => ['PaiementController',    'process'],

    // ── Restaurant ──────────────────────────────────────────────────────────
    'restaurant-dashboard'     => ['RestaurantController',  'dashboard'],
    'restaurant-edit'          => ['RestaurantController',  'edit'],
    'restaurant-toggle'        => ['RestaurantController',  'toggleStatus'],
    'restaurant-commandes'     => ['RestaurantController',  'commandes'],
    'restaurant-cmd-statut'    => ['RestaurantController',  'updateCommandeStatus'],

    // ── Plats ───────────────────────────────────────────────────────────────
    'plat-create'              => ['PlatController',        'create'],
    'plat-edit'                => ['PlatController',        'edit'],
    'plat-delete'              => ['PlatController',        'delete'],
    'plat-toggle'              => ['PlatController',        'toggle'],

    // ── Livreur ─────────────────────────────────────────────────────────────
    'livreur-dashboard'        => ['LivreurController',     'dashboard'],
    'livreur-accepter'         => ['LivreurController',     'accepter'],
    'livreur-livrer'           => ['LivreurController',     'livrer'],
    'livreur-location'         => ['LivreurController',     'updateLocation'],

    // ── Avis ────────────────────────────────────────────────────────────────
    'avis-create'              => ['AvisController',        'create'],
    'mes-avis'                 => ['AvisController',        'index'],
    'avis-delete'              => ['AvisController',        'delete'],

    // ── Admin ───────────────────────────────────────────────────────────────
    'admin'                    => ['AdminController',       'dashboard'],
    'admin-users'              => ['AdminController',       'users'],
    'admin-user-statut'        => ['AdminController',       'updateUserStatus'],
    'admin-user-delete'        => ['AdminController',       'deleteUser'],
    'admin-restaurants'        => ['AdminController',       'restaurants'],
    'admin-restaurant-valider' => ['AdminController',       'validerRestaurant'],
    'admin-commandes'          => ['AdminController',       'commandes'],
    'admin-avis'               => ['AdminController',       'avis'],
    'admin-avis-delete'        => ['AdminController',       'deleteAvis'],
    'admin-stats'              => ['AdminController',       'stats'],
];

// =============================================================================
// 7. RÉSOLUTION DE LA ROUTE
// =============================================================================
$page  = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_GET['page'] ?? 'accueil')));
$route = $routes[$page] ?? null;

if ($route === null) {
    http_response_code(404);
    Logger::warning("Route 404: {$page}");
    require $appRoot . '/app/views/errors/404.php';
    exit;
}

[$controllerName, $actionName] = $route;

// =============================================================================
// 8. DISPATCH
// =============================================================================
$controllerFile = $appRoot . '/app/controllers/' . $controllerName . '.php';

if (!file_exists($controllerFile)) {
    http_response_code(404);
    Logger::error("Contrôleur manquant: {$controllerName}");
    require $appRoot . '/app/views/errors/404.php';
    exit;
}

if (!class_exists($controllerName)) {
    require_once $controllerFile;
}

try {
    $controller = new $controllerName();

    if (!method_exists($controller, $actionName)) {
        http_response_code(404);
        require $appRoot . '/app/views/errors/404.php';
        exit;
    }

    // Envoyer le header CSP avec le nonce généré
    $nonce = $GLOBALS['csp_nonce'];
    header(
        "Content-Security-Policy: default-src 'self'; " .
        "script-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; " .
        "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; " .
        "font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com; " .
        "img-src 'self' data: blob: https:; " .
        "connect-src 'self'; " .
        "frame-ancestors 'none'; " .
        "base-uri 'self'; " .
        "form-action 'self'; " .
        "object-src 'none';"
    );

    $controller->$actionName();

} catch (PDOException $e) {
    Logger::error('PDOException: ' . $e->getMessage());
    if ($appDebug) {
        http_response_code(500);
        echo '<pre style="background:#1a0000;color:#ff6666;padding:20px;font-family:monospace;">';
        echo '<strong>Erreur SQL :</strong> ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        echo '</pre>';
    } else {
        http_response_code(500);
        require $appRoot . '/app/views/errors/500.php';
    }
} catch (Throwable $e) {
    Logger::error('Exception: ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
    if ($appDebug) {
        http_response_code(500);
        echo '<pre style="background:#1a0000;color:#ff6666;padding:20px;font-family:monospace;">';
        echo '<strong>Erreur :</strong> '   . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "\n";
        echo '<strong>Fichier :</strong> '  . htmlspecialchars($e->getFile(),    ENT_QUOTES, 'UTF-8') . ':' . $e->getLine() . "\n\n";
        echo htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8');
        echo '</pre>';
    } else {
        http_response_code(500);
        require $appRoot . '/app/views/errors/500.php';
    }
}
