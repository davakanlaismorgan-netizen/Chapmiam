<?php
/**
 * Chap'miam — diagnostic.php
 * ⚠️ SUPPRIMER APRÈS UTILISATION
 * URL : https://chapmiam.onrender.com/diagnostic.php?key=diag2024
 */

if (($_GET['key'] ?? '') !== 'diag2024') {
    http_response_code(403);
    die('Accès refusé');
}

// Forcer l'affichage de toutes les erreurs
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<pre style='background:#0d1117;color:#58a6ff;padding:20px;font-size:13px;'>";

// ─── 1. Structure des fichiers ────────────────────────────────────────────────
echo "=== 1. STRUCTURE /var/www/html/ ===\n";
$base = '/var/www/html';
$dirs = ['', '/public', '/app', '/app/controllers', '/app/helpers',
         '/app/config', '/app/models', '/app/views', '/logs'];
foreach ($dirs as $d) {
    $path = $base . $d;
    if (is_dir($path)) {
        $files = scandir($path);
        $count = count($files) - 2;
        echo "✅ $path ($count fichiers)\n";
    } else {
        echo "❌ ABSENT : $path\n";
    }
}

// ─── 2. Fichiers critiques ────────────────────────────────────────────────────
echo "\n=== 2. FICHIERS CRITIQUES ===\n";
$critiques = [
    '/var/www/html/public/index.php',
    '/var/www/html/app/config/Database.php',
    '/var/www/html/app/config/Logger.php',
    '/var/www/html/app/helpers/Session.php',
    '/var/www/html/app/helpers/Security.php',
    '/var/www/html/app/helpers/Utils.php',
    '/var/www/html/app/helpers/RateLimiter.php',
    '/var/www/html/app/controllers/AuthController.php',
    '/var/www/html/app/views/errors/404.php',
    '/var/www/html/app/views/errors/500.php',
];
foreach ($critiques as $f) {
    echo (file_exists($f) ? "✅" : "❌ MANQUANT") . " $f\n";
}

// ─── 3. Variables d'environnement ────────────────────────────────────────────
echo "\n=== 3. VARIABLES D'ENVIRONNEMENT ===\n";
$vars = ['APP_ENV','APP_DEBUG','APP_KEY','APP_URL',
         'DB_HOST','DB_NAME','DB_USER','DB_PORT',
         'LOG_PATH','LOG_LEVEL','SESSION_LIFETIME'];
foreach ($vars as $v) {
    $val = getenv($v);
    if ($val === false) {
        echo "❌ NON DÉFINIE : $v\n";
    } elseif (in_array($v, ['DB_PASS','APP_KEY'])) {
        echo "✅ $v = " . str_repeat('*', strlen($val)) . "\n";
    } else {
        echo "✅ $v = $val\n";
    }
}

// ─── 4. Charger index.php et capturer l'erreur ───────────────────────────────
echo "\n=== 4. ERREUR RÉELLE (chargement index.php) ===\n";
ob_start();
try {
    // Simuler les variables serveur
    $_SERVER['REQUEST_URI']  = '/';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['page'] = 'accueil';

    // Capturer les erreurs fatales
    register_shutdown_function(function() {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            echo "\n❌ ERREUR FATALE : " . $err['message'] . "\n";
            echo "   Fichier : " . $err['file'] . "\n";
            echo "   Ligne   : " . $err['line'] . "\n";
        }
    });

    include '/var/www/html/public/index.php';
    $output = ob_get_clean();
    echo "✅ index.php chargé sans erreur fatale\n";
    echo "   Taille sortie : " . strlen($output) . " octets\n";

} catch (Throwable $e) {
    ob_get_clean();
    echo "❌ EXCEPTION : " . $e->getMessage() . "\n";
    echo "   Type    : " . get_class($e) . "\n";
    echo "   Fichier : " . $e->getFile() . "\n";
    echo "   Ligne   : " . $e->getLine() . "\n";
    echo "\n   STACK TRACE :\n";
    foreach ($e->getTrace() as $i => $t) {
        echo "   #$i " . ($t['file'] ?? '?') . ":" . ($t['line'] ?? '?') . "\n";
    }
}

// ─── 5. Test des helpers ─────────────────────────────────────────────────────
echo "\n=== 5. CHARGEMENT DES HELPERS ===\n";
$helpers = [
    'Logger'      => '/var/www/html/app/config/Logger.php',
    'Database'    => '/var/www/html/app/config/Database.php',
    'Session'     => '/var/www/html/app/helpers/Session.php',
    'Security'    => '/var/www/html/app/helpers/Security.php',
    'RateLimiter' => '/var/www/html/app/helpers/RateLimiter.php',
];
foreach ($helpers as $name => $path) {
    if (!file_exists($path)) {
        echo "❌ FICHIER MANQUANT : $name → $path\n";
        continue;
    }
    try {
        if (!class_exists($name)) {
            require_once $path;
        }
        echo "✅ $name chargé\n";
    } catch (Throwable $e) {
        echo "❌ $name ERREUR : " . $e->getMessage() . "\n";
    }
}

// ─── 6. Test HomeController ──────────────────────────────────────────────────
echo "\n=== 6. CONTRÔLEUR HOME ===\n";
$homeFile = '/var/www/html/app/controllers/HomeController.php';
if (!file_exists($homeFile)) {
    echo "❌ HomeController.php MANQUANT → c'est probablement la cause du 500 !\n";
    echo "   Ce fichier est requis pour la page d'accueil.\n";
} else {
    echo "✅ HomeController.php présent\n";
    try {
        require_once $homeFile;
        echo "✅ HomeController chargé\n";
    } catch (Throwable $e) {
        echo "❌ Erreur : " . $e->getMessage() . "\n";
    }
}

// ─── 7. Liste tous les controllers présents ───────────────────────────────────
echo "\n=== 7. CONTROLLERS PRÉSENTS ===\n";
$ctrlDir = '/var/www/html/app/controllers/';
if (is_dir($ctrlDir)) {
    foreach (glob($ctrlDir . '*.php') as $f) {
        echo "   📄 " . basename($f) . "\n";
    }
} else {
    echo "❌ Dossier controllers introuvable !\n";
}

// ─── 8. PHP Info partiel ─────────────────────────────────────────────────────
echo "\n=== 8. ENVIRONNEMENT PHP ===\n";
echo "PHP Version    : " . PHP_VERSION . "\n";
echo "Extensions PDO : " . (extension_loaded('pdo_mysql') ? '✅ pdo_mysql' : '❌ pdo_mysql MANQUANT') . "\n";
echo "Session handler: " . ini_get('session.save_handler') . "\n";
echo "Upload max     : " . ini_get('upload_max_filesize') . "\n";
echo "Memory limit   : " . ini_get('memory_limit') . "\n";
echo "DocumentRoot   : " . ($_SERVER['DOCUMENT_ROOT'] ?? 'non défini') . "\n";
echo "Script         : " . ($_SERVER['SCRIPT_FILENAME'] ?? 'non défini') . "\n";

echo "\n=== FIN DU DIAGNOSTIC ===\n";
echo "⚠️  SUPPRIMER CE FICHIER APRÈS UTILISATION\n";
echo "    git rm public/diagnostic.php && git commit -m 'rm diag' && git push\n";
echo "</pre>";
