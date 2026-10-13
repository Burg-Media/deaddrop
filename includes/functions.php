<?php
declare(strict_types=1);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . APP_URL . '/' . ltrim($path, '/'));
    exit;
}

function generate_public_id(): string
{
    return bin2hex(random_bytes(32));
}

function current_user_id(): ?int
{
    return $_SESSION['user_id'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user_id']);
}

function db_column_exists(PDO $pdo, string $table, string $column): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
        return false;
    }

    static $cache = [];
    $key = $table . '.' . $column;

    if (!array_key_exists($key, $cache)) {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1
        ");
        $stmt->execute([$table, $column]);
        $cache[$key] = (bool) $stmt->fetch();
    }

    return $cache[$key];
}

function db_table_exists(PDO $pdo, string $table): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return false;
    }

    static $cache = [];

    if (!array_key_exists($table, $cache)) {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
            LIMIT 1
        ");
        $stmt->execute([$table]);
        $cache[$table] = (bool) $stmt->fetch();
    }

    return $cache[$table];
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Leest een JSON-body (fetch vanuit de browser) of valt terug op $_POST.
 */
function request_data(): array
{
    $type = (string) ($_SERVER['CONTENT_TYPE'] ?? '');

    if (str_starts_with($type, 'application/json')) {
        $data = json_decode((string) file_get_contents('php://input'), true);

        return is_array($data) ? $data : [];
    }

    return $_POST;
}

/**
 * Strikte base64-check met lengtegrens, voor alles wat de browser als
 * versleutelde data aanlevert.
 */
function is_strict_base64(string $value, int $maxLength): bool
{
    if ($value === '' || strlen($value) > $maxLength || strlen($value) % 4 !== 0) {
        return false;
    }

    return (bool) preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $value) && base64_decode($value, true) !== false;
}

/**
 * Tijdstempel afgerond op 5 minuten: genoeg voor sortering, te grof om
 * een bericht aan een precies moment te koppelen.
 */
function time_bucket(): string
{
    return date('Y-m-d H:i:s', (int) (floor(time() / 300) * 300));
}
