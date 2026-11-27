<?php

/**
 * Centralized, best-effort audit logger for the HRIS.
 *
 * Existing call sites can continue to use:
 *   AuditLogger::log('employee.update', 'employee', $id, $details);
 *
 * The logger automatically adds request metadata, a correlation ID, the
 * current actor, and redacts credential-like values before persistence.
 */
final class AuditLogger
{
    private static ?PDO $pdo = null;
    private static ?string $requestId = null;

    private static function connect(): PDO
    {
        if (self::$pdo === null) {
            global $pdo;
            self::$pdo = $pdo;
        }

        return self::$pdo;
    }

    private static function requestId(): string
    {
        if (self::$requestId === null) {
            try {
                self::$requestId = bin2hex(random_bytes(16));
            } catch (Throwable $e) {
                self::$requestId = uniqid('hris-', true);
            }
        }

        return self::$requestId;
    }

    /**
     * Redact secrets recursively while preserving useful audit context.
     */
    private static function sanitize(mixed $value, string $key = ''): mixed
    {
        $sensitive = preg_match(
            '/pass(word)?|secret|token|authorization|cookie|api[_-]?key|otp|pin/i',
            $key
        ) === 1;

        if ($sensitive) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $clean = [];
            foreach ($value as $childKey => $childValue) {
                $clean[(string)$childKey] = self::sanitize(
                    $childValue,
                    (string)$childKey
                );
            }
            return $clean;
        }

        if (is_object($value)) {
            return self::sanitize((array)$value, $key);
        }

        if (is_resource($value)) {
            return '[RESOURCE]';
        }

        if (is_string($value) && strlen($value) > 10000) {
            return substr($value, 0, 10000) . '…';
        }

        return $value;
    }

    private static function context(): array
    {
        $sessionId = session_status() === PHP_SESSION_ACTIVE
            ? session_id()
            : '';

        return [
            'request_id' => self::requestId(),
            'method' => strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'CLI')),
            'route' => (string)($_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? 'CLI')),
            'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
            'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'),
            'session' => $sessionId !== '' ? hash('sha256', $sessionId) : null,
        ];
    }

    /**
     * Write an immutable audit event. Logging failures never interrupt HRIS work.
     */
    public static function log(
        string $action,
        string $entity_type,
        ?int $entity_id = null,
        array $details = []
    ): void {
        try {
            $action = substr(trim($action), 0, 80);
            $entity_type = substr(trim($entity_type), 0, 50);

            if ($action === '' || $entity_type === '') {
                return;
            }

            $actorUserId = isset($_SESSION['user_id']) && $_SESSION['user_id'] !== ''
                ? (int)$_SESSION['user_id']
                : null;

            $payload = array_merge(self::context(), [
                'severity' => self::severity($action, $details),
            ], self::sanitize($details));

            $json = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );

            if ($json === false) {
                $json = json_encode(['severity' => 'info']);
            }

            $stmt = self::connect()->prepare(''
                . 'INSERT INTO audit_logs '
                . '(actor_user_id, action, entity_type, entity_id, details) '
                . 'VALUES (:actor_user_id, :action, :entity_type, :entity_id, :details)'
            );

            $stmt->execute([
                ':actor_user_id' => $actorUserId,
                ':action' => $action,
                ':entity_type' => $entity_type,
                ':entity_id' => $entity_id,
                ':details' => $json,
            ]);
        } catch (Throwable $e) {
            error_log('[AuditLogger] Failed to write log: ' . $e->getMessage());
        }
    }

    /**
     * Convenience method for recording a failed operation.
     */
    public static function failure(
        string $action,
        string $entity_type,
        ?int $entity_id = null,
        array $details = []
    ): void {
        $details['outcome'] = 'failure';
        self::log($action . (str_ends_with($action, '.failed') ? '' : '.failed'), $entity_type, $entity_id, $details);
    }

    /**
     * Shortcut for failed login attempts when there is no authenticated actor.
     */
    public static function logFailedLogin(string $username): void
    {
        self::writeUnauthenticated(
            'login.failed',
            'user',
            [
                'attempted_username' => $username,
                'outcome' => 'failure',
            ]
        );
    }

    private static function writeUnauthenticated(
        string $action,
        string $entityType,
        array $details
    ): void {
        try {
            $payload = array_merge(self::context(), [
                'severity' => 'high',
            ], self::sanitize($details));

            $stmt = self::connect()->prepare(''
                . 'INSERT INTO audit_logs '
                . '(actor_user_id, action, entity_type, entity_id, details) '
                . 'VALUES (NULL, :action, :entity_type, NULL, :details)'
            );

            $stmt->execute([
                ':action' => substr($action, 0, 80),
                ':entity_type' => substr($entityType, 0, 50),
                ':details' => json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
                ),
            ]);
        } catch (Throwable $e) {
            error_log('[AuditLogger] Failed to write failed login log: ' . $e->getMessage());
        }
    }

    private static function severity(string $action, array $details): string
    {
        $explicit = strtolower((string)($details['severity'] ?? ''));
        if (in_array($explicit, ['low', 'info', 'medium', 'high', 'critical'], true)) {
            return $explicit;
        }

        $action = strtolower($action);
        if (str_contains($action, 'failed') || str_contains($action, 'delete') || str_contains($action, 'deactivate')) {
            return 'high';
        }
        if (str_contains($action, 'password') || str_contains($action, 'permission') || str_contains($action, 'role')) {
            return 'medium';
        }
        return 'info';
    }
}
