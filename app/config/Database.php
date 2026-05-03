<?php
/**
 * =============================================================================
 * Chap'miam — app/config/Database.php — VERSION RENDER/DOCKER
 * =============================================================================
 *
 * ADAPTATIONS RENDER :
 *
 *   1. Pas de chargement .env (géré dans index.php)
 *   2. DB_URL supporté : Render injecte parfois l'URL complète de connexion
 *      Format : mysql://user:pass@host:port/dbname
 *      → Parsé automatiquement si présent
 *   3. SSL/TLS pour les bases de données cloud (PlanetScale, Railway, etc.)
 *      Activé si DB_SSL=true dans les variables Render
 *   4. Reconnexion automatique (les containers cloud peuvent avoir des
 *      connexions coupées après inactivité)
 * =============================================================================
 */

declare(strict_types=1);

class Database
{
    private static ?PDO $instance = null;

    // =========================================================================
    // CONNEXION PRINCIPALE
    // =========================================================================

    public static function getInstance(): PDO
    {
        if (self::$instance !== null) {
            // Vérifier que la connexion est toujours active (reconnexion auto)
            try {
                self::$instance->query('SELECT 1');
            } catch (PDOException $e) {
                // Connexion perdue → forcer la reconnexion
                self::$instance = null;
            }
        }

        if (self::$instance === null) {
            self::$instance = self::createConnection();
        }

        return self::$instance;
    }

    // =========================================================================
    // CRÉATION DE LA CONNEXION PDO
    // =========================================================================

    private static function createConnection(): PDO
    {
        // ── Priorité 1 : DATABASE_URL (format Render/Railway/PlanetScale) ──
        $dbUrl = getenv('DATABASE_URL') ?: getenv('MYSQL_URL') ?: '';
        if ($dbUrl !== '') {
            return self::connectFromUrl($dbUrl);
        }

        // ── Priorité 2 : Variables individuelles ──────────────────────────
        $host    = getenv('DB_HOST')    ?: 'localhost';
        $port    = getenv('DB_PORT')    ?: '3306';
        $dbName  = getenv('DB_NAME')    ?: '';
        $user    = getenv('DB_USER')    ?: '';
        $pass    = getenv('DB_PASS')    ?: '';
        $charset = getenv('DB_CHARSET') ?: 'utf8mb4';
        $ssl     = getenv('DB_SSL')     === 'true';

        if ($dbName === '' || $user === '') {
            self::fatalError('Variables DB_HOST, DB_NAME, DB_USER, DB_PASS manquantes.');
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset={$charset}";

        return self::buildPdo($dsn, $user, $pass, $ssl);
    }

    // =========================================================================
    // CONNEXION DEPUIS UNE URL (Render DATABASE_URL)
    // =========================================================================

    private static function connectFromUrl(string $url): PDO
    {
        $parsed = parse_url($url);

        if ($parsed === false || empty($parsed['host'])) {
            self::fatalError('DATABASE_URL invalide : ' . substr($url, 0, 30) . '...');
        }

        $host    = $parsed['host'];
        $port    = (string)($parsed['port'] ?? 3306);
        $dbName  = ltrim($parsed['path'] ?? '', '/');
        $user    = urldecode($parsed['user'] ?? '');
        $pass    = urldecode($parsed['pass'] ?? '');
        $charset = 'utf8mb4';

        // PlanetScale et certains providers cloud nécessitent SSL
        $ssl = str_contains($url, 'sslmode=require')
            || str_contains($url, 'ssl=true')
            || getenv('DB_SSL') === 'true';

        $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset={$charset}";

        return self::buildPdo($dsn, $user, $pass, $ssl);
    }

    // =========================================================================
    // CONSTRUCTION DE L'OBJET PDO
    // =========================================================================

    private static function buildPdo(string $dsn, string $user, string $pass, bool $ssl = false): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 10,
            // Reconnexion automatique MySQL
            PDO::ATTR_PERSISTENT         => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00'",
        ];

        // SSL pour les bases cloud (PlanetScale, Railway, Render PostgreSQL, etc.)
        if ($ssl) {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            $options[PDO::MYSQL_ATTR_SSL_CA]                 = '';
        }

        try {
            $pdo = new PDO($dsn, $user, $pass, $options);

            // Vérification immédiate de la connexion
            $pdo->query('SELECT 1');

            return $pdo;

        } catch (PDOException $e) {
            // Logguer sans exposer le mot de passe
            $safeDsn = preg_replace('/:[^:@]+@/', ':***@', $dsn);
            Logger::error("Connexion DB échouée [{$safeDsn}]: " . $e->getMessage());
            self::fatalError('Connexion à la base de données impossible.');
        }
    }

    // =========================================================================
    // GESTION D'ERREUR FATALE
    // =========================================================================

    private static function fatalError(string $message): never
    {
        $isDebug = getenv('APP_DEBUG') === 'true';
        http_response_code(500);

        if ($isDebug) {
            die("Erreur DB : {$message}");
        }

        // En production : message générique + log
        $appRoot = dirname(__DIR__, 2);
        $errorView = $appRoot . '/app/views/errors/500.php';
        if (file_exists($errorView)) {
            require $errorView;
        } else {
            die('Erreur serveur. Veuillez réessayer.');
        }
        exit;
    }

    // =========================================================================
    // UTILITAIRES
    // =========================================================================

    /** Réinitialise la connexion (tests, reconnexion forcée) */
    public static function reset(): void
    {
        self::$instance = null;
    }

    private function __construct() {}
    private function __clone()     {}
}
