<?php
/**
 * Chap'miam — test_db.php
 * Script de diagnostic connexion DB
 * ⚠️ SUPPRIMER CE FICHIER APRÈS DIAGNOSTIC
 * Placer dans : public/test_db.php
 * Accéder via : https://chapmiam.onrender.com/test_db.php
 */

// Sécurité minimale : clé secrète dans l'URL
// Accès : https://chapmiam.onrender.com/test_db.php?key=diagnostic2024
if (($_GET['key'] ?? '') !== 'diagnostic2024') {
    http_response_code(403);
    die('Accès refusé');
}

echo "<pre style='font-family:monospace;background:#1a1a2e;color:#00ff88;padding:20px;'>";
echo "=== DIAGNOSTIC CONNEXION DB ===\n\n";

// Variables d'environnement
$host    = getenv('DB_HOST')    ?: 'NON DÉFINI';
$name    = getenv('DB_NAME')    ?: 'NON DÉFINI';
$user    = getenv('DB_USER')    ?: 'NON DÉFINI';
$pass    = getenv('DB_PASS')    ?: 'NON DÉFINI';
$port    = getenv('DB_PORT')    ?: '3306 (défaut)';
$charset = getenv('DB_CHARSET') ?: 'utf8mb4 (défaut)';

echo "DB_HOST    : $host\n";
echo "DB_NAME    : $name\n";
echo "DB_USER    : $user\n";
echo "DB_PASS    : " . (getenv('DB_PASS') ? str_repeat('*', strlen(getenv('DB_PASS'))) : 'NON DÉFINI') . "\n";
echo "DB_PORT    : $port\n";
echo "DB_CHARSET : $charset\n\n";

// Test 1 : résolution DNS
echo "=== TEST 1 : Résolution DNS ===\n";
$hostClean = explode(':', getenv('DB_HOST') ?: '')[0];
$ip = gethostbyname($hostClean);
if ($ip !== $hostClean) {
    echo "✅ DNS résolu : $hostClean → $ip\n\n";
} else {
    echo "❌ DNS non résolu pour : $hostClean\n\n";
}

// Test 2 : connexion TCP (fsockopen)
echo "=== TEST 2 : Connexion TCP port 3306 ===\n";
$portNum = (int)(getenv('DB_PORT') ?: 3306);
$sock = @fsockopen($hostClean, $portNum, $errno, $errstr, 5);
if ($sock) {
    echo "✅ Port $portNum accessible\n\n";
    fclose($sock);
} else {
    echo "❌ Port $portNum inaccessible : $errstr (code $errno)\n";
    echo "   → Clever Cloud bloque peut-être les connexions depuis Render\n\n";
}

// Test 3 : connexion PDO
echo "=== TEST 3 : Connexion PDO MySQL ===\n";
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbPort = (int)(getenv('DB_PORT') ?: 3306);
if (str_contains($dbHost, ':')) {
    [$dbHost, $p] = explode(':', $dbHost, 2);
    $dbPort = (int)$p ?: 3306;
}
$dsn = "mysql:host=$dbHost;port=$dbPort;dbname={$name};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'), [
        PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT          => 10,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    echo "✅ Connexion PDO réussie !\n";

    // Test requête
    $stmt = $pdo->query("SELECT COUNT(*) as nb FROM information_schema.tables WHERE table_schema = DATABASE()");
    $row  = $stmt->fetch();
    echo "✅ Nombre de tables dans la base : " . $row['nb'] . "\n\n";

} catch (PDOException $e) {
    echo "❌ Erreur PDO : " . $e->getMessage() . "\n";
    echo "   Code : " . $e->getCode() . "\n\n";
}

// Test 4 : SSL
echo "=== TEST 4 : Test avec SSL ===\n";
try {
    $pdo2 = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'), [
        PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT          => 10,
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
        PDO::MYSQL_ATTR_SSL_CA     => '',
    ]);
    echo "✅ Connexion avec SSL réussie\n\n";
} catch (PDOException $e) {
    echo "ℹ️  SSL non requis ou non disponible : " . $e->getCode() . "\n\n";
}

echo "=== FIN DU DIAGNOSTIC ===\n";
echo "\n⚠️  SUPPRIMER CE FICHIER : public/test_db.php\n";
echo "</pre>";
