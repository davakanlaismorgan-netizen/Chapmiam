<?php
/**
 * =============================================================================
 * Chap'miam — app/config/Logger.php — VERSION RENDER/DOCKER
 * =============================================================================
 *
 * ADAPTATIONS RENDER/DOCKER :
 *
 *   1. SORTIE DOCKER : En environnement Docker/Render, les logs sont écrits
 *      sur php://stderr (collectés nativement par Render dans ses logs).
 *      Le fichier de log disque est maintenu en PARALLÈLE pour dev local.
 *
 *   2. DÉTECTION DOCKER : Via la variable RENDER ou la présence de /.dockerenv
 *      → Si Docker détecté : écriture vers stderr EN PLUS du fichier
 *
 *   3. RGPD (conservé de la version finale) :
 *      - IP anonymisée (2 premiers octets seulement)
 *      - Email hashé HMAC-SHA256 (jamais en clair)
 *      - URI sanitisée (tokens masqués)
 * =============================================================================
 */

declare(strict_types=1);

class Logger
{
    private static string $logFile    = '';
    private static string $level      = 'debug';
    private static bool   $isDocker   = false;
    private static bool   $initialized = false;

    private static array $levels = [
        'debug'   => 0,
        'info'    => 1,
        'warning' => 2,
        'error'   => 3,
    ];

    // =========================================================================
    // INITIALISATION
    // =========================================================================

    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        // Détection Docker/Render
        self::$isDocker = getenv('RENDER') !== false
            || getenv('RENDER_SERVICE_ID') !== false
            || file_exists('/.dockerenv');

        self::$level = strtolower(getenv('LOG_LEVEL') ?: 'debug');

        // En Docker : logs vers stderr par défaut (stdout est pour Apache)
        // En local : logs vers fichier
        if (!self::$isDocker) {
            $rawPath = getenv('LOG_PATH') ?: (dirname(__DIR__, 2) . '/logs/app.log');

            // Résoudre les chemins relatifs
            if (!str_starts_with($rawPath, '/')) {
                $rawPath = dirname(__DIR__, 2) . '/' . ltrim($rawPath, './');
            }
            self::$logFile = $rawPath;

            $dir = dirname(self::$logFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
        }

        self::$initialized = true;
    }

    // =========================================================================
    // API PUBLIQUE
    // =========================================================================

    public static function debug(string $message, array $context = []): void
    {
        self::write('debug', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    // =========================================================================
    // MÉTHODES SPÉCIALISÉES (RGPD)
    // =========================================================================

    public static function logLoginAttempt(string $email, bool $success): void
    {
        $status = $success ? 'SUCCESS' : 'FAILED';
        $appKey = getenv('APP_KEY') ?: 'chapmiam_fallback_key';
        $hash   = substr(hash_hmac('sha256', strtolower(trim($email)), $appKey), 0, 16);
        self::write('warning', "Login {$status} | user_hash[{$hash}]");
    }

    public static function logCsrfFailure(string $route): void
    {
        $safeRoute = substr(preg_replace('/[\r\n\0]/', '', $route), 0, 200);
        self::write('error', "CSRF invalide | route[{$safeRoute}]");
    }

    public static function logSecurityEvent(string $event, array $context = []): void
    {
        self::write('warning', "SECURITY[{$event}]", $context);
    }

    // =========================================================================
    // MOTEUR DE LOG
    // =========================================================================

    private static function write(string $level, string $message, array $context = []): void
    {
        if (!self::$initialized) {
            self::init();
        }

        $levelInt    = self::$levels[$level]       ?? 0;
        $minLevelInt = self::$levels[self::$level] ?? 0;

        if ($levelInt < $minLevelInt) {
            return;
        }

        $date       = date('Y-m-d H:i:s');
        $levelUpper = strtoupper(str_pad($level, 7));
        $ip         = self::anonymizeIp($_SERVER['REMOTE_ADDR'] ?? 'CLI');
        $uri        = self::sanitizeUri($_SERVER['REQUEST_URI'] ?? '-');
        $ctx        = empty($context)
            ? ''
            : ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE);

        // Format de log lisible par Render
        $line = "[{$date}] [{$levelUpper}] [IP:{$ip}] [URI:{$uri}] {$message}{$ctx}" . PHP_EOL;

        // ── Docker/Render : écrire sur stderr ─────────────────────────────
        if (self::$isDocker) {
            file_put_contents('php://stderr', $line);
            return; // Sur Render : uniquement stderr (pas de fichier disque)
        }

        // ── Local : écrire dans le fichier de log ─────────────────────────
        if (self::$logFile !== '') {
            self::rotateIfNeeded();
            file_put_contents(self::$logFile, $line, FILE_APPEND | LOCK_EX);
        }
    }

    // =========================================================================
    // ROTATION DES LOGS (local uniquement)
    // =========================================================================

    private static function rotateIfNeeded(): void
    {
        if (self::$logFile === '' || !file_exists(self::$logFile)) {
            return;
        }
        if (filesize(self::$logFile) < 10 * 1024 * 1024) {
            return;
        }

        $dir     = dirname(self::$logFile);
        $base    = basename(self::$logFile);
        $ts      = date('Ymd_His');
        $archive = "{$dir}/{$base}.{$ts}.old";

        rename(self::$logFile, $archive);
        self::purgeOldArchives($dir, $base);
    }

    private static function purgeOldArchives(string $dir, string $base): void
    {
        $cutoff  = time() - (30 * 86400);
        $pattern = "{$dir}/{$base}.*.old";
        foreach (glob($pattern) as $f) {
            if (is_file($f) && filemtime($f) < $cutoff) {
                @unlink($f);
            }
        }
    }

    // =========================================================================
    // ANONYMISATION RGPD
    // =========================================================================

    private static function anonymizeIp(string $ip): string
    {
        if (in_array($ip, ['CLI', '127.0.0.1', '::1'], true)) {
            return $ip;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $p = explode('.', $ip);
            return ($p[0] ?? 'x') . '.' . ($p[1] ?? 'x') . '.x.x';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $p = explode(':', $ip);
            return ($p[0] ?? 'x') . ':' . ($p[1] ?? 'x') . ':x:x:x:x:x:x';
        }
        return 'x.x.x.x';
    }

    private static function sanitizeUri(string $uri): string
    {
        $uri = substr($uri, 0, 300);
        $uri = preg_replace(
            '/([?&](token|password|pass|secret|key|hash|api_key|csrf_token)=)[^&]*/i',
            '$1[MASKED]',
            $uri
        );
        return preg_replace('/[\r\n\0]/', '', $uri);
    }
}
