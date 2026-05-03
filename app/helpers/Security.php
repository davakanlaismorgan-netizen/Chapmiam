<?php
/**
 * =============================================================================
 * Chap'miam — app/helpers/Security.php — VERSION RENDER/DOCKER
 * =============================================================================
 *
 * Identique à la version FINALE DÉFINITIVE.
 * Aucune adaptation Render nécessaire sur ce fichier :
 *   - CSRF : pas de dépendance HTTP_REFERER (déjà corrigé)
 *   - XSS  : encodage à la sortie, indépendant du cloud
 *   - Hash : random_bytes() disponible sur toutes les plateformes PHP 8.x
 * =============================================================================
 */

declare(strict_types=1);

class Security
{
    // =========================================================================
    // CSRF
    // =========================================================================

    public static function generateToken(): string
    {
        if (!Session::has('csrf_token')) {
            Session::set('csrf_token', bin2hex(random_bytes(32)));
        }
        return Session::get('csrf_token');
    }

    /**
     * Champ HTML CSRF — À insérer en première ligne de tout <form method="POST">
     * Usage : <?= Security::csrfField() ?>
     */
    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf_token" value="'
             . self::generateToken() . '">';
    }

    /**
     * Vérifie le token CSRF (timing-safe via hash_equals).
     * Régénère le token après validation (one-time use).
     */
    public static function verifyToken(string $submittedToken): bool
    {
        if (!Session::has('csrf_token')) {
            return false;
        }
        $valid = hash_equals(Session::get('csrf_token'), $submittedToken);
        if ($valid) {
            Session::set('csrf_token', bin2hex(random_bytes(32)));
        }
        return $valid;
    }

    /**
     * Vérifie le CSRF et stoppe si invalide (403).
     * Appeler EN PREMIER dans chaque bloc POST d'un contrôleur.
     *
     * CORRECTION : pas de HTTP_REFERER (forgeable) — URL interne uniquement.
     */
    public static function requireValidToken(): void
    {
        $token = $_POST['csrf_token'] ?? '';

        if (!self::verifyToken($token)) {
            Logger::logCsrfFailure($_SERVER['REQUEST_URI'] ?? '');
            http_response_code(403);
            Session::setFlash('danger', 'Token de sécurité invalide. Veuillez réessayer.');

            // Redirection vers chemin interne (jamais de domaine externe)
            $returnUrl = self::buildSafeReturnUrl();
            header('Location: ' . $returnUrl);
            exit;
        }
    }

    private static function buildSafeReturnUrl(): string
    {
        $uri    = $_SERVER['REQUEST_URI'] ?? 'index.php';
        $parsed = parse_url($uri);
        $path   = ltrim($parsed['path'] ?? 'index.php', '/');
        $query  = isset($parsed['query']) ? '?' . $parsed['query'] : '';
        $safe   = preg_replace('/[\r\n\0]/', '', $path . $query);
        return $safe ?: 'index.php';
    }

    // =========================================================================
    // XSS — ENCODAGE À LA SORTIE (uniquement dans les vues)
    // =========================================================================

    /**
     * Encode pour affichage HTML sécurisé.
     * Usage dans les vues : <?= Security::e($variable) ?>
     */
    public static function e(?string $text): string
    {
        return htmlspecialchars((string)($text ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // =========================================================================
    // VALIDATION & NETTOYAGE DES ENTRÉES
    // =========================================================================

    /**
     * Nettoie une entrée pour stockage.
     * NE PAS encoder HTML ici — encoder à l'affichage avec Security::e().
     */
    public static function sanitize(?string $input): string
    {
        if ($input === null) {
            return '';
        }
        $input = trim($input);
        $input = stripslashes($input);
        // Supprimer les caractères de contrôle dangereux
        $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $input);
        return $input;
    }

    public static function sanitizeEmail(string $email): string
    {
        return (string)filter_var(trim(strtolower($email)), FILTER_SANITIZE_EMAIL);
    }

    public static function sanitizeInt(mixed $value): int
    {
        return (int)filter_var($value, FILTER_SANITIZE_NUMBER_INT);
    }

    public static function sanitizeFloat(mixed $value): float
    {
        return (float)filter_var(
            $value,
            FILTER_SANITIZE_NUMBER_FLOAT,
            FILTER_FLAG_ALLOW_FRACTION
        );
    }

    public static function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Mot de passe fort : 8 chars min, 1 maj, 1 min, 1 chiffre.
     */
    public static function validatePassword(string $password): bool
    {
        return strlen($password) >= 8
            && preg_match('/[A-Z]/', $password) === 1
            && preg_match('/[a-z]/', $password) === 1
            && preg_match('/[0-9]/', $password) === 1;
    }

    // =========================================================================
    // MOTS DE PASSE
    // =========================================================================

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    // =========================================================================
    // GÉNÉRATION
    // =========================================================================

    public static function randomString(int $length = 32): string
    {
        return bin2hex(random_bytes((int)ceil($length / 2)));
    }

    public static function generateOrderNumber(): string
    {
        return 'CMD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    /**
     * Référence de transaction — 64 bits d'entropie (corrigé audit V2).
     */
    public static function generateTransactionRef(): string
    {
        return 'PAY-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(8)));
    }

    public static function generateResetToken(): string
    {
        return bin2hex(random_bytes(32)); // 256 bits
    }

    // =========================================================================
    // UPLOAD
    // =========================================================================

    public static function validateUpload(
        array $file,
        array $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        int   $maxBytes     = 5_242_880
    ): bool {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return false;
        }
        if (($file['size'] ?? 0) === 0 || ($file['size'] ?? 0) > $maxBytes) {
            return false;
        }
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        return in_array($mimeType, $allowedTypes, true);
    }

    public static function safeFileName(string $originalName, string $prefix = ''): string
    {
        $ext     = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $ext     = in_array($ext, $allowed, true) ? $ext : 'jpg';
        $base    = $prefix !== '' ? $prefix . '_' : '';
        return $base . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    }

    // =========================================================================
    // WHITELIST SQL
    // =========================================================================

    /**
     * Filtre un tableau pour ne conserver que les clés autorisées.
     * Protection contre l'injection de noms de colonnes SQL.
     */
    public static function filterFields(array $data, array $allowedKeys): array
    {
        return array_intersect_key($data, array_flip($allowedKeys));
    }
}
