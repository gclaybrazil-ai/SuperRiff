<?php

declare(strict_types=1);

function app_root(): string
{
    return dirname(__DIR__);
}

function db_path(): string
{
    $dataDir = app_root() . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0775, true);
    }
    return $dataDir . DIRECTORY_SEPARATOR . 'superriffas.sqlite';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . db_path(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    ensure_schema($pdo);
    expire_orders($pdo);
    return $pdo;
}

function ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS admins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS raffles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            description TEXT NOT NULL,
            price_cents INTEGER NOT NULL,
            total_numbers INTEGER NOT NULL,
            digits INTEGER NOT NULL,
            status TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            raffle_id INTEGER NOT NULL,
            customer_name TEXT NOT NULL,
            customer_phone TEXT NOT NULL,
            numbers_json TEXT NOT NULL,
            total_cents INTEGER NOT NULL,
            status TEXT NOT NULL,
            mp_payment_id TEXT,
            mp_pix_qr_code TEXT,
            mp_pix_qr_code_base64 TEXT,
            mp_pix_copia_cola TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            FOREIGN KEY (raffle_id) REFERENCES raffles(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS raffle_numbers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            raffle_id INTEGER NOT NULL,
            number INTEGER NOT NULL,
            status TEXT NOT NULL,
            reserved_until TEXT,
            order_id INTEGER,
            UNIQUE (raffle_id, number),
            FOREIGN KEY (raffle_id) REFERENCES raffles(id) ON DELETE CASCADE,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
        )'
    );

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_numbers_raffle_status ON raffle_numbers(raffle_id, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_orders_status_expires ON orders(status, expires_at)');
}

function now_iso(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function setting(PDO $pdo, string $key, ?string $default = null): ?string
{
    $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = :key');
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch();
    if (!$row) {
        return $default;
    }
    return (string) $row['value'];
}

function set_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare('INSERT INTO settings(key, value) VALUES(:key, :value)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $stmt->execute([':key' => $key, ':value' => $value]);
}

function base_url(PDO $pdo): string
{
    $configured = setting($pdo, 'base_url', '');
    if (is_string($configured) && trim($configured) !== '') {
        return rtrim(trim($configured), '/');
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    $root = $dir === '' || $dir === '.' ? '' : $dir;
    return $scheme . '://' . $host . $root;
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function session_start_once(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'path' => '/',
    ]);
    session_start();
}

function csrf_token(): string
{
    session_start_once();
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || strlen($_SESSION['csrf_token']) < 32) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    session_start_once();
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        http_response_code(403);
        echo 'CSRF inválido.';
        exit;
    }
}

function admin_is_logged_in(): bool
{
    session_start_once();
    return isset($_SESSION['admin_id']) && is_int($_SESSION['admin_id']);
}

function admin_require_login(): void
{
    if (!admin_is_logged_in()) {
        redirect('./admin.php?a=login');
    }
}

function admin_logout(): void
{
    session_start_once();
    unset($_SESSION['admin_id']);
}

function expire_orders(PDO $pdo): void
{
    $now = now_iso();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT id FROM orders WHERE status = 'pending' AND expires_at < :now");
    $stmt->execute([':now' => $now]);
    $expired = $stmt->fetchAll();
    if ($expired) {
        $ids = array_map(fn ($r) => (int) $r['id'], $expired);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE orders SET status = 'expired', updated_at = ? WHERE id IN ($in)")
            ->execute(array_merge([$now], $ids));
        $pdo->prepare("UPDATE raffle_numbers SET status = 'available', reserved_until = NULL, order_id = NULL WHERE order_id IN ($in) AND status = 'reserved'")
            ->execute($ids);
    }
    $pdo->commit();
}

function format_money_brl(int $cents): string
{
    return 'R$ ' . number_format($cents / 100, 2, ',', '.');
}

function normalize_phone(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw);
    if (!is_string($digits)) {
        return '';
    }
    return $digits;
}

function mp_access_token(PDO $pdo): string
{
    $env = getenv('MP_ACCESS_TOKEN');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }
    return (string) setting($pdo, 'mp_access_token', '');
}

function mp_api_request(PDO $pdo, string $method, string $path, ?array $body = null): array
{
    $token = mp_access_token($pdo);
    if ($token === '') {
        throw new RuntimeException('MercadoPago não configurado.');
    }
    $url = 'https://api.mercadopago.com' . $path;
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Falha ao iniciar request.');
    }
    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 25,
    ]);
    if ($body !== null) {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('Falha ao serializar JSON.');
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Erro MercadoPago: ' . $err);
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = ['raw' => $raw];
    }
    return ['status' => $status, 'data' => $data];
}

function mp_create_pix_payment(PDO $pdo, int $orderId, int $amountCents, string $description, string $name, string $phoneDigits, string $expiresAtIso): array
{
    $digits = preg_replace('/\D+/', '', $phoneDigits);
    if (!is_string($digits) || $digits === '') {
        $digits = (string) $orderId;
    }
    $email = 'comprador+' . $digits . '@example.com';
    $amount = round($amountCents / 100, 2);
    $base = base_url($pdo);
    $expiresForMp = $expiresAtIso;
    try {
        $dt = new DateTimeImmutable($expiresAtIso);
        $expiresForMp = $dt->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('Y-m-d\TH:i:s.vP');
    } catch (Throwable $e) {
    }
    $body = [
        'transaction_amount' => $amount,
        'description' => $description,
        'payment_method_id' => 'pix',
        'payer' => [
            'email' => $email,
            'first_name' => mb_substr($name, 0, 60),
        ],
        'notification_url' => $base . '/webhook.php',
        'external_reference' => 'order:' . $orderId,
        'date_of_expiration' => $expiresForMp,
    ];
    $resp = mp_api_request($pdo, 'POST', '/v1/payments', $body);
    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        $msg = isset($resp['data']['message']) ? (string) $resp['data']['message'] : 'Falha ao criar pagamento.';
        throw new RuntimeException($msg);
    }
    $data = $resp['data'];
    $tx = $data['point_of_interaction']['transaction_data'] ?? [];
    return [
        'payment_id' => (string) ($data['id'] ?? ''),
        'status' => (string) ($data['status'] ?? ''),
        'qr_code' => (string) ($tx['qr_code'] ?? ''),
        'qr_code_base64' => (string) ($tx['qr_code_base64'] ?? ''),
        'ticket_url' => (string) ($tx['ticket_url'] ?? ''),
    ];
}

function mp_fetch_payment(PDO $pdo, string $paymentId): array
{
    $resp = mp_api_request($pdo, 'GET', '/v1/payments/' . rawurlencode($paymentId));
    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        $msg = isset($resp['data']['message']) ? (string) $resp['data']['message'] : 'Falha ao consultar pagamento.';
        throw new RuntimeException($msg);
    }
    return $resp['data'];
}
