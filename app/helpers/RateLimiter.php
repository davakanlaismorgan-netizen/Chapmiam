<?php
/**
 * =============================================================================
 * Chap'miam — app/helpers/RateLimiter.php — VERSION RENDER/DOCKER
 * =============================================================================
 *
 * ADAPTATION RENDER — SYSTÈME DE FICHIERS ÉPHÉMÈRE :
 *
 *   Sur Render (plan gratuit/starter), le système de fichiers est ÉPHÉMÈRE.
 *   Les fichiers créés dans le container sont perdus à chaque redéploiement.
 *   En cas de multiple instances (scaling), les fichiers ne sont PAS partagés.
 *
 *   SOLUTIONS SELON LE CONTEXTE :
 *
 *   A) Plan Render GRATUIT (1 instance, fs éphémère) :
 *      → Stocker dans /tmp (dans le container, pas de problème mutualisé)
 *      → Les données de rate limit sont perdues au redémarrage (acceptable)
 *
 *   B) Plan Render avec Disque Persistant :
 *      → Configurer RATE_LIMIT_PATH=/opt/render/data/ratelimit dans les vars
 *      → Les données persistent entre redémarrages
 *
 *   C) Production multi-instances (scaling horizontal) :
 *      → Utiliser Redis (Upstash, Render Redis) → voir RateLimiterRedis.php
 *      → Seule solution correcte pour plusieurs instances
 *
 *   Ce fichier gère automatiquement A) et B).
 *   Pour C), décommenter l'intégration Redis en bas de fichier.
 * =============================================================================
 */

declare(strict_types=1);

class RateLimiter
{
    private static string $storageDir = '';

    // =========================================================================
    // INITIALISATION DU STOCKAGE
    // =========================================================================

    private static function getStorageDir(): string
    {
        if (self::$storageDir !== '') {
            return self::$storageDir;
        }

        // ── Priorité 1 : Variable d'environnement (Render Disk) ──────────
        $envPath = getenv('RATE_LIMIT_PATH') ?: '';
        if ($envPath !== '' && $envPath !== 'CHANGER_CETTE_VALEUR') {
            self::$storageDir = $envPath;
        }
        // ── Priorité 2 : Dossier storage/ du projet ─────────────────────
        elseif (is_dir(dirname(__DIR__, 2) . '/storage')) {
            self::$storageDir = dirname(__DIR__, 2) . '/storage/ratelimit';
        }
        // ── Priorité 3 : /tmp (fallback Docker/Render fs éphémère) ───────
        else {
            // Sur Render (container), /tmp est privé au container
            $appHash          = substr(md5(getenv('APP_KEY') ?: 'chapmiam'), 0, 8);
            self::$storageDir = "/tmp/chapmiam_rl_{$appHash}";
        }

        if (!is_dir(self::$storageDir)) {
            mkdir(self::$storageDir, 0700, true);
        }

        return self::$storageDir;
    }

    private static function getFilePath(string $identifier): string
    {
        return self::getStorageDir() . '/' . hash('sha256', $identifier) . '.json';
    }

    // =========================================================================
    // API PUBLIQUE
    // =========================================================================

    /**
     * Enregistre une tentative — retourne true si autorisée
     * Lecture + écriture ATOMIQUES via flock()
     */
    public static function attempt(
        string $identifier,
        int    $maxAttempts = 5,
        int    $windowSec   = 900
    ): bool {
        $file = self::getFilePath($identifier);
        $now  = time();

        $fp = fopen($file, 'c+');
        if ($fp === false) {
            Logger::logSecurityEvent('ratelimit_io_error', ['file' => basename($file)]);
            return true; // Fail open
        }

        flock($fp, LOCK_EX);

        try {
            rewind($fp);
            $raw  = stream_get_contents($fp);
            $data = ($raw !== '') ? (json_decode($raw, true) ?: []) : [];
            $data += ['attempts' => [], 'blocked_until' => 0];

            // Bloqué ?
            if ((int)($data['blocked_until'] ?? 0) > $now) {
                return false;
            }

            // Nettoyer les tentatives hors fenêtre
            $data['attempts'] = array_values(array_filter(
                $data['attempts'],
                static fn($ts) => ($now - (int)$ts) < $windowSec
            ));

            $data['attempts'][] = $now;
            $count              = count($data['attempts']);

            if ($count > $maxAttempts) {
                $data['blocked_until'] = $now + $windowSec;
                $data['attempts']      = [];

                // Logger sans révéler l'identifiant complet (peut contenir l'IP)
                $safeId = substr(hash('sha256', $identifier), 0, 8);
                Logger::logSecurityEvent('rate_limit_triggered', [
                    'id_hash'   => $safeId,
                    'attempts'  => $count,
                    'blocked_s' => $windowSec,
                ]);
                $allowed = false;
            } else {
                $allowed = true;
            }

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data));

        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        return $allowed;
    }

    public static function isBlocked(string $identifier): bool
    {
        $file = self::getFilePath($identifier);
        if (!file_exists($file)) {
            return false;
        }
        $data = json_decode((string)file_get_contents($file), true);
        return isset($data['blocked_until']) && (int)$data['blocked_until'] > time();
    }

    public static function remainingMinutes(string $identifier): int
    {
        $file = self::getFilePath($identifier);
        if (!file_exists($file)) {
            return 0;
        }
        $data         = json_decode((string)file_get_contents($file), true);
        $blockedUntil = (int)($data['blocked_until'] ?? 0);
        return $blockedUntil > time() ? (int)ceil(($blockedUntil - time()) / 60) : 0;
    }

    public static function remainingSeconds(string $identifier): int
    {
        $file = self::getFilePath($identifier);
        if (!file_exists($file)) {
            return 0;
        }
        $data         = json_decode((string)file_get_contents($file), true);
        $blockedUntil = (int)($data['blocked_until'] ?? 0);
        return max(0, $blockedUntil - time());
    }

    public static function reset(string $identifier): void
    {
        $file = self::getFilePath($identifier);
        if (file_exists($file)) {
            unlink($file);
        }
    }

    /**
     * Nettoyage des anciens fichiers (appeler depuis un cron ou au démarrage)
     */
    public static function cleanup(): int
    {
        $dir   = self::getStorageDir();
        $count = 0;
        foreach (glob($dir . '/*.json') as $file) {
            $data         = json_decode((string)file_get_contents($file), true);
            $blockedUntil = (int)($data['blocked_until'] ?? 0);
            if ($blockedUntil < time() && empty($data['attempts'])) {
                unlink($file);
                $count++;
            }
        }
        return $count;
    }
}
