<?php

declare(strict_types=1);

namespace Cms;

use PDO;
use DateTime;

class SecurityManager
{
    private ?PDO $db;
    private CmsManager $cmsManager;

    /**
     * Constructor: Accepts a database connection and CmsManager for logging.
     */
    public function __construct(CmsManager $cmsManager, ?PDO $db = null)
    {
        $this->db = $db;
        $this->cmsManager = $cmsManager;
    }

    /**
     * ============================
     * CSRF TOKEN MANAGEMENT
     * ============================
     */

    public function generateCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_token_expiry']) || time() > $_SESSION['csrf_token_expiry']) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_expiry'] = time() + 3600; // Token expires in 1 hour
        }
        return $_SESSION['csrf_token'];
    }

    public function verifyCsrfToken(string $token): bool
    {
        return hash_equals($_SESSION['csrf_token'] ?? '', $token);
    }

    /**
     * ============================
     * INPUT SANITIZATION
     * ============================
     */

    public function sanitizeInput($input): string|array
    {
        if (is_array($input)) {
            return array_map([$this, 'sanitizeInput'], $input);
        }
        return htmlspecialchars(trim((string) $input), ENT_QUOTES, 'UTF-8');
    }

    public function escape(?string $string): string
    {
        return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
    }

    /**
     * ============================
     * IP BLOCKING
     * ============================
     */

    public function checkBlockedIp(): void
    {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        if (!$this->db) {
            return;
        }

        $tableCheckStmt = $this->db->query("SHOW TABLES LIKE 'blocked_entities'");
        if (!$tableCheckStmt || $tableCheckStmt->rowCount() === 0) {
            return;
        }

        $stmt = $this->db->prepare("SELECT entity, reason, created_at, unblock_at
                                    FROM blocked_entities 
                                    WHERE entity = ? AND type = 'ip' LIMIT 1");
        $stmt->execute([$ipAddress]);
        $blockRecord = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$blockRecord) {
            return;
        }

        $currentTime = new DateTime();
        $unblockAt = $blockRecord['unblock_at'] ? new DateTime($blockRecord['unblock_at']) : null;

        if ($unblockAt && $unblockAt <= $currentTime) {
            $removeStmt = $this->db->prepare("DELETE FROM blocked_entities WHERE entity = ? AND type = 'ip'");
            $removeStmt->execute([$ipAddress]);
            return;
        }

        $blockMessage = "Your access has been blocked.\n\n";
        $blockMessage .= "IP Address: {$ipAddress}\n";
        $blockMessage .= "Blocked Since: " . (new DateTime($blockRecord['created_at']))->format('Y-m-d H:i:s') . "\n";
        $blockMessage .= "Reason: {$blockRecord['reason']}\n";

        if ($unblockAt) {
            $blockMessage .= "This block will expire on: {$unblockAt->format('Y-m-d H:i:s')}\n";
        } else {
            $blockMessage .= "This block is permanent.\n";
        }

        header('Content-Type: text/plain; charset=UTF-8');
        echo $blockMessage;
        $this->cmsManager->logCmsEvent('security', "Blocked IP accessed: {$ipAddress}");
        exit();
    }

    /**
     * ============================
     * ACCESS CONTROL
     * ============================
     */

public function hasAccess(string $requiredLevel): bool
{
    $levels = ['public', 'registered', 'editor', 'admin'];
    $currentLevel = $_SESSION['access_level'] ?? 'public';

    // Load access level from database if not set in session
    if (!isset($_SESSION['access_level']) && isset($_SESSION['user_id']) && $this->db) {
        $stmt = $this->db->prepare("SELECT access_level FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && in_array($user['access_level'], $levels, true)) {
            $currentLevel = $user['access_level'];
            $_SESSION['access_level'] = $currentLevel; // Cache it in the session
        }
    }

    // Compare access levels
    if (array_search($currentLevel, $levels, true) >= array_search($requiredLevel, $levels, true)) {
        return true;
    }

    // Log the denied access event
    if (isset($this->cmsManager)) {
        $this->cmsManager->logCmsEvent(
            '403',
            "Access denied: required '$requiredLevel', current '$currentLevel'"
        );
    }

    return false;
}


public function enforceSsl(): void
{
    $enforceSsl = $this->cmsManager->getSiteOption('enforce_ssl', '0'); // Default is disabled (0)

    if ($enforceSsl === '1' && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off')) {
        $redirect = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        header("Location: $redirect", true, 301);
        $this->cmsManager->logCmsEvent('security', "SSL enforced for URI: {$_SERVER['REQUEST_URI']}");
        exit;
    }
}


    /**
     * ============================
     * MIDDLEWARE
     * ============================
     */

    public function handleMiddleware(array $middlewares, callable $callback): void
    {
        foreach ($middlewares as $middleware) {
            if (is_callable($middleware)) {
                if (call_user_func($middleware) === false) {
                    $this->cmsManager->logCmsEvent('error', "Middleware failed.");
                    return;
                }
            } else {
                $this->cmsManager->logCmsEvent('error', "Invalid middleware callback.");
            }
        }
        call_user_func($callback);
    }
}
