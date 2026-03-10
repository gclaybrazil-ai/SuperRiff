<?php

declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'lib.php';

$pdo = db();

http_response_code(200);

$raw = file_get_contents('php://input');
$payload = json_decode($raw === false ? '' : $raw, true);

$paymentId = '';
if (is_array($payload)) {
    $data = $payload['data'] ?? null;
    if (is_array($data) && isset($data['id'])) {
        $paymentId = (string) $data['id'];
    }
    if ($paymentId === '' && isset($payload['id'])) {
        $paymentId = (string) $payload['id'];
    }
}

if ($paymentId === '' && isset($_GET['data_id']) && is_string($_GET['data_id'])) {
    $paymentId = $_GET['data_id'];
}

if ($paymentId === '') {
    echo 'ok';
    exit;
}

try {
    $payment = mp_fetch_payment($pdo, $paymentId);
} catch (Throwable $e) {
    echo 'ok';
    exit;
}

$status = (string) ($payment['status'] ?? '');
$external = (string) ($payment['external_reference'] ?? '');

$orderId = 0;
$stmt = $pdo->prepare('SELECT id FROM orders WHERE mp_payment_id = :pid');
$stmt->execute([':pid' => $paymentId]);
$row = $stmt->fetch();
if ($row) {
    $orderId = (int) $row['id'];
} elseif (preg_match('/^order:(\d+)$/', $external, $m)) {
    $orderId = (int) $m[1];
}

if ($orderId <= 0) {
    echo 'ok';
    exit;
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT id, status FROM orders WHERE id = :id');
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        $pdo->rollBack();
        echo 'ok';
        exit;
    }

    $now = now_iso();

    if ($status === 'approved') {
        if ((string) $order['status'] !== 'paid') {
            $pdo->prepare("UPDATE orders SET status = 'paid', updated_at = ? WHERE id = ?")->execute([$now, $orderId]);
            $pdo->prepare("UPDATE raffle_numbers SET status = 'paid', reserved_until = NULL WHERE order_id = ?")->execute([$orderId]);
        }
    } elseif (in_array($status, ['cancelled', 'rejected', 'refunded', 'charged_back', 'expired'], true)) {
        if ((string) $order['status'] !== 'paid') {
            $pdo->prepare("UPDATE orders SET status = 'cancelled', updated_at = ? WHERE id = ?")->execute([$now, $orderId]);
            $pdo->prepare("UPDATE raffle_numbers SET status = 'available', reserved_until = NULL, order_id = NULL WHERE order_id = ? AND status = 'reserved'")
                ->execute([$orderId]);
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

echo 'ok';

