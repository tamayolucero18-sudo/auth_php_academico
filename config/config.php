<?php
declare(strict_types=1);

/*
 * CONFIGURACIÓN GENERAL
 * Compatible con XAMPP en PC y acceso desde celular.
 */

$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

/* Base de datos */
const DB_HOST = '127.0.0.1';
const DB_NAME = 'auth_academico';
const DB_USER = 'root';
const DB_PASS = '';

/*
 * Conexión PDO
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_NAME
        );

        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    return $pdo;
}

function user_public_id(array $user): string
{
    $number = min(max(1, (int)($user['id'] ?? 1)), 1000);
    $letter = chr(65 + (($number - 1) % 26));

    return $number . $letter;
}

function user_id_from_public_id(string $publicId): ?int
{
    $publicId = strtoupper(trim($publicId));

    if (!preg_match('/^([1-9][0-9]{0,3})([A-Z])$/D', $publicId, $matches)) {
        return null;
    }

    $number = (int)$matches[1];

    if ($number > 1000 || $matches[2] !== chr(65 + (($number - 1) % 26))) {
        return null;
    }

    return $number;
}

function user_identity_is_public_id(string $identity): bool
{
    return preg_match('/^[1-9][0-9]{0,3}[A-Za-z]$/D', trim($identity)) === 1;
}

function find_user_by_identity(PDO $pdo, string $identity): ?array
{
    $userId = user_id_from_public_id($identity);

    if ($userId !== null) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
    } elseif (user_identity_is_public_id($identity)) {
        return null;
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE name = ? LIMIT 1');
        $stmt->execute([$identity]);
    }

    $user = $stmt->fetch();

    return $user ?: null;
}

/*
 * Escape HTML
 */
function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/*
 * CSRF
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function verify_csrf(?string $token = null): void
{
    $providedToken = $token ?? ($_POST['csrf'] ?? $_POST['csrf_token'] ?? '');

    if (
        empty($_SESSION['csrf']) ||
        empty($providedToken) ||
        !hash_equals($_SESSION['csrf'], $providedToken)
    ) {
        http_response_code(419);
        exit('Token CSRF inválido.');
    }
}

/*
 * Usuario actual
 */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

/*
 * Requiere sesión
 */
function require_login(): void
{
    if (empty($_SESSION['user'])) {
        header('Location: index.php');
        exit;
    }
}

/*
 * Requiere rol
 */
function require_role(string $role): void
{
    require_login();

    if (($_SESSION['user']['role'] ?? '') !== $role) {
        http_response_code(403);
        exit('Acceso denegado.');
    }
}

/*
 * Registrar intento de autenticación
 */
function log_attempt(...$args): void
{
    $pdo = db();

    if (!empty($args) && $args[0] instanceof PDO) {
        $pdo = array_shift($args);
    }

    $argc = count($args);

    if ($argc === 4) {
        [$userId, $method, $success, $reason] = $args;
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    } elseif ($argc === 5) {
        [$userId, $method, $success, $ip, $reason] = $args;
    } else {
        $userId = null;
        $method = 'unknown';
        $success = false;
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $reason = null;
    }

    if (!is_int($userId) && $userId !== null) {
        $userId = (int)$userId;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO login_attempts
        (user_id, method, success, ip_address, reason)
        VALUES (?, ?, ?, ?, ?)'
    );

    $stmt->execute([
        $userId,
        $method,
        $success ? 1 : 0,
        $ip,
        $reason
    ]);
}