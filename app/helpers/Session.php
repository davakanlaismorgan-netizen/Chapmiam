<?php
/**
 * =============================================================================
 * Chap'miam — app/helpers/Session.php — VERSION RENDER/DOCKER
 * =============================================================================
 *
 * ADAPTATION RENDER :
 *
 *   Sur Render, toutes les requêtes arrivent en HTTP dans le container.
 *   Render gère HTTPS en amont (reverse proxy Nginx).
 *   La détection HTTPS DOIT se faire via X-Forwarded-Proto.
 *
 *   Ce fichier conserve la détection multi-sources de la version finale :
 *     1. $_SERVER['HTTPS'] === 'on'  (accès direct HTTPS)
 *     2. SERVER_PORT === 443
 *     3. HTTP_X_FORWARDED_PROTO === 'https'  ← CRITIQUE pour Render
 *
 *   SESSION_DOMAIN : Sur Render, le domaine est auto-détecté depuis APP_URL.
 *   Cela garantit que le cookie de session est correctement scoped.
 * =============================================================================
 */

declare(strict_types=1);

class Session
{
    private static bool $started = false;

    // =========================================================================
    // DÉMARRAGE DE SESSION SÉCURISÉE
    // =========================================================================

    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        // ── Détection HTTPS — couvre direct + reverse proxy (Render) ──────
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
            || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        $lifetime = (int)(getenv('SESSION_LIFETIME') ?: 1800);

        // ── Domaine de session depuis APP_URL ──────────────────────────────
        $sessionDomain = '';
        $appUrl = getenv('APP_URL') ?: '';
        if ($appUrl !== '') {
            $host = parse_url($appUrl, PHP_URL_HOST);
            if ($host !== false && $host !== null) {
                $sessionDomain = $host;
            }
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => $sessionDomain,
            'secure'   => $isHttps,   // Automatique selon HTTPS
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        ini_set('session.use_only_cookies',    '1');
        ini_set('session.use_strict_mode',     '1');
        ini_set('session.gc_maxlifetime',      (string)$lifetime);

        session_name('CHAPMIAM_SID');
        session_start();
        self::$started = true;

        if (!isset($_SESSION['_created'])) {
            $_SESSION['_created'] = time();
            session_regenerate_id(true);
        }

        // Expiration par inactivité
        if (isset($_SESSION['_last_activity'])) {
            if ((time() - (int)$_SESSION['_last_activity']) > $lifetime) {
                self::destroy();
                return;
            }
        }
        $_SESSION['_last_activity'] = time();

        // Régénération périodique (1/4 de la lifetime)
        $regenInterval = (int)($lifetime / 4);
        if (!isset($_SESSION['_regen_at'])) {
            $_SESSION['_regen_at'] = time() + $regenInterval;
        } elseif (time() > (int)$_SESSION['_regen_at']) {
            session_regenerate_id(true);
            $_SESSION['_regen_at'] = time() + $regenInterval;
        }
    }

    // =========================================================================
    // ACCÈS AUX DONNÉES DE SESSION
    // =========================================================================

    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        self::start();
        return isset($_SESSION[$key]);
    }

    public static function remove(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 3600,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => 'Lax',
            ]);
        }

        session_destroy();
        self::$started = false;
    }

    // =========================================================================
    // MESSAGES FLASH (one-time)
    // =========================================================================

    public static function setFlash(string $type, string $message): void
    {
        self::start();
        $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
    }

    public static function getFlash(): ?array
    {
        self::start();
        if (isset($_SESSION['_flash'])) {
            $flash = $_SESSION['_flash'];
            unset($_SESSION['_flash']);
            return $flash;
        }
        return null;
    }

    // =========================================================================
    // AUTHENTIFICATION
    // =========================================================================

    public static function isLogged(): bool
    {
        self::start();
        return !empty($_SESSION['user_id']);
    }

    public static function hasRole(string $role): bool
    {
        self::start();
        return ($_SESSION['user_role'] ?? '') === $role;
    }

    public static function userId(): ?int
    {
        self::start();
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }

    public static function requireLogin(): void
    {
        if (!self::isLogged()) {
            self::setFlash('warning', 'Veuillez vous connecter pour accéder à cette page.');
            self::redirect('index.php?page=login');
        }
    }

    public static function requireRole(string $role): void
    {
        self::requireLogin();
        if (!self::hasRole($role)) {
            http_response_code(403);
            self::setFlash('danger', 'Accès refusé : droits insuffisants.');
            self::redirect('index.php?page=accueil');
        }
    }

    // =========================================================================
    // REDIRECTION SÉCURISÉE (Open Redirect corrigé)
    // =========================================================================

    public static function redirect(string $url): never
    {
        $url = trim($url);

        // Nettoyer les injections CRLF
        $url = preg_replace('/[\r\n\0]/', '', $url);

        // Bloquer les protocoles dangereux
        if (preg_match('/^(javascript:|data:|vbscript:)/i', $url)) {
            $url = 'index.php';
        }

        // Bloquer les redirections vers des domaines externes
        if (preg_match('/^(https?:\/\/|\/\/)/i', $url)) {
            $parsedHost  = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
            $currentHost = strtolower($_SERVER['HTTP_HOST'] ?? '');

            // Normaliser : ignorer le port
            $parsedHost  = explode(':', $parsedHost)[0];
            $currentHost = explode(':', $currentHost)[0];

            if ($parsedHost !== $currentHost) {
                Logger::warning("Open redirect bloqué: {$url}");
                $url = 'index.php';
            }
        }

        header('Location: ' . $url);
        exit;
    }
}
