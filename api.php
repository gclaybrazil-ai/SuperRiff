<?php

declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'lib.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = db();

$action = $_GET['action'] ?? '';
if (!is_string($action)) {
    $action = '';
}

function json_out(int $code, array $payload): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'create_order') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(405, ['ok' => false, 'error' => 'Método inválido.']);
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($data)) {
        json_out(400, ['ok' => false, 'error' => 'JSON inválido.']);
    }

    $raffleId = isset($data['raffle_id']) ? (int) $data['raffle_id'] : 0;
    $name = isset($data['name']) ? trim((string) $data['name']) : '';
    $phone = isset($data['phone']) ? trim((string) $data['phone']) : '';
    $numbers = $data['numbers'] ?? [];

    if ($raffleId <= 0 || $name === '' || $phone === '' || !is_array($numbers) || count($numbers) === 0) {
        json_out(400, ['ok' => false, 'error' => 'Dados incompletos.']);
    }

    $numbers = array_values(array_unique(array_map(fn ($n) => (int) $n, $numbers)));
    sort($numbers);

    $stmt = $pdo->prepare("SELECT id, title, price_cents, total_numbers, digits, status FROM raffles WHERE id = :id");
    $stmt->execute([':id' => $raffleId]);
    $raffle = $stmt->fetch();
    if (!$raffle || $raffle['status'] !== 'active') {
        json_out(404, ['ok' => false, 'error' => 'Rifa não encontrada.']);
    }

    $total = (int) $raffle['total_numbers'];
    foreach ($numbers as $n) {
        if ($n < 0 || $n >= $total) {
            json_out(400, ['ok' => false, 'error' => 'Número inválido.']);
        }
    }

    $expiresAtIso = gmdate('Y-m-d\TH:i:s\Z', time() + (10 * 60));
    $createdAt = now_iso();
    $totalCents = (int) $raffle['price_cents'] * count($numbers);
    $numbersJson = json_encode($numbers, JSON_UNESCAPED_UNICODE);
    if (!is_string($numbersJson)) {
        json_out(500, ['ok' => false, 'error' => 'Falha interna.']);
    }

    $pdo->beginTransaction();
    try {
        $placeholders = implode(',', array_fill(0, count($numbers), '?'));
        $lockStmt = $pdo->prepare("SELECT number, status FROM raffle_numbers WHERE raffle_id = ? AND number IN ($placeholders)");
        $lockStmt->execute(array_merge([$raffleId], $numbers));
        $rows = $lockStmt->fetchAll();
        if (count($rows) !== count($numbers)) {
            throw new RuntimeException('Números indisponíveis.');
        }
        foreach ($rows as $row) {
            if ((string) $row['status'] !== 'available') {
                throw new RuntimeException('Alguns números já foram reservados.');
            }
        }

        $ins = $pdo->prepare("INSERT INTO orders(raffle_id, customer_name, customer_phone, numbers_json, total_cents, status, created_at, updated_at, expires_at)
            VALUES(:rid, :name, :phone, :numbers, :total, 'pending', :created, :updated, :expires)");
        $ins->execute([
            ':rid' => $raffleId,
            ':name' => $name,
            ':phone' => normalize_phone($phone),
            ':numbers' => $numbersJson,
            ':total' => $totalCents,
            ':created' => $createdAt,
            ':updated' => $createdAt,
            ':expires' => $expiresAtIso,
        ]);
        $orderId = (int) $pdo->lastInsertId();

        $upd = $pdo->prepare("UPDATE raffle_numbers SET status = 'reserved', reserved_until = ?, order_id = ? WHERE raffle_id = ? AND number IN ($placeholders) AND status = 'available'");
        $upd->execute(array_merge([$expiresAtIso, $orderId, $raffleId], $numbers));
        if ($upd->rowCount() !== count($numbers)) {
            throw new RuntimeException('Não foi possível reservar os números.');
        }

        $pdo->commit();

        try {
            $desc = 'Rifa: ' . (string) $raffle['title'] . ' - Pedido #' . $orderId;
            $pix = mp_create_pix_payment($pdo, $orderId, $totalCents, $desc, $name, $phone, $expiresAtIso);

            $stmt = $pdo->prepare("UPDATE orders SET mp_payment_id = :pid, mp_pix_qr_code = :qr, mp_pix_qr_code_base64 = :qr64, mp_pix_copia_cola = :pix, updated_at = :u WHERE id = :id");
            $stmt->execute([
                ':pid' => $pix['payment_id'],
                ':qr' => $pix['qr_code'],
                ':qr64' => $pix['qr_code_base64'],
                ':pix' => $pix['qr_code'],
                ':u' => now_iso(),
                ':id' => $orderId,
            ]);
        } catch (Throwable $e) {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE orders SET status = 'cancelled', updated_at = ? WHERE id = ?")->execute([now_iso(), $orderId]);
            $pdo->prepare("UPDATE raffle_numbers SET status = 'available', reserved_until = NULL, order_id = NULL WHERE order_id = ? AND status = 'reserved'")->execute([$orderId]);
            $pdo->commit();
            throw $e;
        }

        json_out(200, [
            'ok' => true,
            'order_id' => $orderId,
            'checkout_url' => './index.php?page=checkout&order=' . $orderId,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $msg = $e->getMessage() !== '' ? $e->getMessage() : 'Erro ao reservar.';
        json_out(400, ['ok' => false, 'error' => $msg]);
    }
}

if ($action === 'order_status') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id <= 0) {
        json_out(400, ['ok' => false, 'error' => 'ID inválido.']);
    }
    $stmt = $pdo->prepare('SELECT status FROM orders WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        json_out(404, ['ok' => false, 'error' => 'Pedido não encontrado.']);
    }
    json_out(200, ['ok' => true, 'status' => (string) $row['status']]);
}

json_out(404, ['ok' => false, 'error' => 'Ação não encontrada.']);

