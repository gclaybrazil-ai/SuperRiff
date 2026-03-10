<?php

declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'lib.php';

$pdo = db();
session_start_once();

$action = $_GET['a'] ?? 'dashboard';
if (!is_string($action)) {
    $action = 'dashboard';
}

function admin_layout(PDO $pdo, string $title, string $contentHtml): void
{
    $fullTitle = $title === '' ? 'Admin - SuperRiffas' : ($title . ' - Admin - SuperRiffas');
    echo '<!doctype html><html lang="pt-BR"><head>';
    echo '<meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/>';
    echo '<title>' . h($fullTitle) . '</title>';
    echo '<link rel="stylesheet" href="./assets/style.css"/>';
    echo '</head><body>';
    echo '<header class="topbar"><div class="container topbar__inner">';
    echo '<a class="brand" href="./admin.php">Admin</a>';
    echo '<nav class="topbar__nav">';
    echo '<a class="link" href="./index.php">Site</a> ';
    if (admin_is_logged_in()) {
        echo '<a class="link" href="./admin.php?a=logout">Sair</a>';
    }
    echo '</nav></div></header>';
    echo '<main class="container" style="padding:18px 0 48px">';
    echo $contentHtml;
    echo '</main></body></html>';
}

$adminCount = (int) $pdo->query('SELECT COUNT(*) AS c FROM admins')->fetch()['c'];
if ($adminCount === 0 && $action !== 'setup') {
    redirect('./admin.php?a=setup');
}

if ($action === 'setup') {
    if ($adminCount > 0) {
        redirect('./admin.php?a=login');
    }

    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = trim((string) ($_POST['email'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');
        if ($email === '' || $pass === '') {
            $error = 'Preencha email e senha.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email inválido.';
        } elseif (strlen($pass) < 8) {
            $error = 'A senha deve ter pelo menos 8 caracteres.';
        } else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO admins(email, password_hash, created_at) VALUES(:e, :h, :c)');
            $stmt->execute([':e' => $email, ':h' => $hash, ':c' => now_iso()]);
            $_SESSION['admin_id'] = (int) $pdo->lastInsertId();
            redirect('./admin.php');
        }
    }

    $html = '<div class="card"><h1>Criar primeiro admin</h1>';
    if ($error !== '') {
        $html .= '<p style="color:var(--danger);font-weight:900">' . h($error) . '</p>';
    }
    $html .= '<form method="post" class="form">';
    $html .= '<div class="form__row"><label class="label">Email</label><input class="input" name="email" type="email" required/></div>';
    $html .= '<div class="form__row"><label class="label">Senha</label><input class="input" name="password" type="password" minlength="8" required/></div>';
    $html .= '<div class="form__actions"><button class="btn btn--primary" type="submit">Criar</button></div>';
    $html .= '</form></div>';
    admin_layout($pdo, 'Setup', $html);
    exit;
}

if ($action === 'login') {
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = trim((string) ($_POST['email'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');
        $stmt = $pdo->prepare('SELECT id, password_hash FROM admins WHERE email = :e');
        $stmt->execute([':e' => $email]);
        $row = $stmt->fetch();
        if ($row && password_verify($pass, (string) $row['password_hash'])) {
            $_SESSION['admin_id'] = (int) $row['id'];
            redirect('./admin.php');
        }
        $error = 'Login inválido.';
    }
    $html = '<div class="card"><h1>Entrar</h1>';
    if ($error !== '') {
        $html .= '<p style="color:var(--danger);font-weight:900">' . h($error) . '</p>';
    }
    $html .= '<form method="post" class="form">';
    $html .= '<div class="form__row"><label class="label">Email</label><input class="input" name="email" type="email" required/></div>';
    $html .= '<div class="form__row"><label class="label">Senha</label><input class="input" name="password" type="password" required/></div>';
    $html .= '<div class="form__actions"><button class="btn btn--primary" type="submit">Entrar</button></div>';
    $html .= '</form></div>';
    admin_layout($pdo, 'Login', $html);
    exit;
}

if ($action === 'logout') {
    admin_logout();
    redirect('./admin.php?a=login');
}

admin_require_login();

if ($action === 'dashboard') {
    $html = '<div class="grid" style="grid-template-columns:repeat(2,minmax(0,1fr));padding-bottom:0">';
    $html .= '<div class="card"><div class="card__title">Rifas</div><p class="muted">Crie e edite rifas.</p><a class="btn btn--primary" href="./admin.php?a=raffles">Gerenciar rifas</a></div>';
    $html .= '<div class="card"><div class="card__title">Pedidos</div><p class="muted">Acompanhe pagamentos e reservas.</p><a class="btn btn--primary" href="./admin.php?a=orders">Ver pedidos</a></div>';
    $html .= '<div class="card"><div class="card__title">Configurações</div><p class="muted">Base URL e MercadoPago.</p><a class="btn btn--primary" href="./admin.php?a=settings">Abrir</a></div>';
    $html .= '</div>';
    admin_layout($pdo, 'Dashboard', $html);
    exit;
}

if ($action === 'raffles') {
    $stmt = $pdo->query('SELECT id, title, price_cents, total_numbers, status, updated_at FROM raffles ORDER BY id DESC');
    $rows = $stmt->fetchAll();

    $html = '<div class="card"><div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">';
    $html .= '<h1 style="margin:0">Rifas</h1>';
    $html .= '<a class="btn btn--primary" href="./admin.php?a=raffle_form">Nova rifa</a>';
    $html .= '</div></div>';

    if (!$rows) {
        $html .= '<div class="card"><p>Nenhuma rifa criada.</p></div>';
        admin_layout($pdo, 'Rifas', $html);
        exit;
    }

    $html .= '<div class="card" style="overflow:auto"><table style="width:100%;border-collapse:collapse">';
    $html .= '<thead><tr>';
    foreach (['ID', 'Título', 'Preço', 'Qtd', 'Status', 'Atualizado', ''] as $th) {
        $html .= '<th style="text-align:left;padding:10px;border-bottom:1px solid rgba(15,23,42,0.08)">' . h($th) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $html .= '<tr>';
        $html .= '<td style="padding:10px;border-bottom:1px solid rgba(15,23,42,0.06)">' . (int) $r['id'] . '</td>';
        $html .= '<td style="padding:10px;border-bottom:1px solid rgba(15,23,42,0.06)">' . h($r['title']) . '</td>';
        $html .= '<td style="padding:10px;border-bottom:1px solid rgba(15,23,42,0.06)">' . h(format_money_brl((int) $r['price_cents'])) . '</td>';
        $html .= '<td style="padding:10px;border-bottom:1px solid rgba(15,23,42,0.06)">' . (int) $r['total_numbers'] . '</td>';
        $html .= '<td style="padding:10px;border-bottom:1px solid rgba(15,23,42,0.06)">' . h($r['status']) . '</td>';
        $html .= '<td style="padding:10px;border-bottom:1px solid rgba(15,23,42,0.06)">' . h((string) $r['updated_at']) . '</td>';
        $html .= '<td style="padding:10px;border-bottom:1px solid rgba(15,23,42,0.06)"><a class="btn" href="./admin.php?a=raffle_form&id=' . (int) $r['id'] . '">Editar</a></td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';
    admin_layout($pdo, 'Rifas', $html);
    exit;
}

if ($action === 'raffle_form') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $raffle = null;
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM raffles WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $raffle = $stmt->fetch();
        if (!$raffle) {
            redirect('./admin.php?a=raffles');
        }
    }

    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $price = trim((string) ($_POST['price'] ?? ''));
        $total = isset($_POST['total_numbers']) ? (int) $_POST['total_numbers'] : 0;
        $status = trim((string) ($_POST['status'] ?? 'active'));

        $normalized = str_replace('.', '', $price);
        $normalized = str_replace(',', '.', $normalized);
        $priceFloat = (float) $normalized;
        if ($title === '' || $description === '' || $priceFloat <= 0) {
            $error = 'Preencha título, descrição e preço.';
        } elseif (!in_array($status, ['active', 'draft', 'closed'], true)) {
            $error = 'Status inválido.';
        } elseif ($id === 0 && $total <= 0) {
            $error = 'Quantidade inválida.';
        } else {
            $priceCents = (int) round($priceFloat * 100);
            $now = now_iso();
            if ($id === 0) {
                $digits = max(2, strlen((string) max(0, $total - 1)));
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare('INSERT INTO raffles(title, description, price_cents, total_numbers, digits, status, created_at, updated_at)
                        VALUES(:t, :d, :p, :n, :g, :s, :c, :u)');
                    $stmt->execute([
                        ':t' => $title,
                        ':d' => $description,
                        ':p' => $priceCents,
                        ':n' => $total,
                        ':g' => $digits,
                        ':s' => $status,
                        ':c' => $now,
                        ':u' => $now,
                    ]);
                    $newId = (int) $pdo->lastInsertId();
                    $ins = $pdo->prepare("INSERT INTO raffle_numbers(raffle_id, number, status) VALUES(:rid, :num, 'available')");
                    for ($i = 0; $i < $total; $i++) {
                        $ins->execute([':rid' => $newId, ':num' => $i]);
                    }
                    $pdo->commit();
                    redirect('./admin.php?a=raffles');
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = 'Falha ao criar a rifa.';
                }
            } else {
                $stmt = $pdo->prepare('UPDATE raffles SET title = :t, description = :d, price_cents = :p, status = :s, updated_at = :u WHERE id = :id');
                $stmt->execute([
                    ':t' => $title,
                    ':d' => $description,
                    ':p' => $priceCents,
                    ':s' => $status,
                    ':u' => $now,
                    ':id' => $id,
                ]);
                redirect('./admin.php?a=raffles');
            }
        }
    }

    $title = $raffle ? (string) $raffle['title'] : '';
    $description = $raffle ? (string) $raffle['description'] : '';
    $priceCents = $raffle ? (int) $raffle['price_cents'] : 0;
    $priceText = $priceCents > 0 ? number_format($priceCents / 100, 2, ',', '.') : '';
    $totalNumbers = $raffle ? (int) $raffle['total_numbers'] : 100;
    $statusValue = $raffle ? (string) $raffle['status'] : 'active';

    $html = '<div class="card"><h1 style="margin:0 0 6px">' . ($raffle ? 'Editar rifa' : 'Nova rifa') . '</h1>';
    if ($error !== '') {
        $html .= '<p style="color:var(--danger);font-weight:900">' . h($error) . '</p>';
    }
    $html .= '<form method="post" class="form">';
    $html .= '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"/>';
    $html .= '<div class="form__row"><label class="label">Título</label><input class="input" name="title" value="' . h($title) . '" required/></div>';
    $html .= '<div class="form__row"><label class="label">Descrição</label><textarea class="textarea" name="description" required>' . h($description) . '</textarea></div>';
    $html .= '<div class="form__row"><label class="label">Preço por número (ex: 13,00)</label><input class="input" name="price" value="' . h($priceText) . '" required/></div>';
    if (!$raffle) {
        $html .= '<div class="form__row"><label class="label">Quantidade de números</label><input class="input" name="total_numbers" type="number" min="10" max="20000" value="' . (int) $totalNumbers . '" required/></div>';
    }
    $html .= '<div class="form__row"><label class="label">Status</label><select class="input" name="status">';
    foreach (['active' => 'active', 'draft' => 'draft', 'closed' => 'closed'] as $k => $v) {
        $sel = $statusValue === $k ? ' selected' : '';
        $html .= '<option value="' . h($k) . '"' . $sel . '>' . h($v) . '</option>';
    }
    $html .= '</select></div>';
    $html .= '<div class="form__actions">';
    $html .= '<a class="btn" href="./admin.php?a=raffles">Voltar</a>';
    $html .= '<button class="btn btn--primary" type="submit">Salvar</button>';
    $html .= '</div>';
    $html .= '</form></div>';
    admin_layout($pdo, 'Rifa', $html);
    exit;
}

if ($action === 'orders') {
    $stmt = $pdo->query('SELECT o.id, o.status, o.total_cents, o.created_at, o.customer_name, o.customer_phone, r.title AS raffle_title
        FROM orders o JOIN raffles r ON r.id = o.raffle_id
        ORDER BY o.id DESC LIMIT 200');
    $rows = $stmt->fetchAll();

    $html = '<div class="card"><h1 style="margin:0">Pedidos</h1><p class="muted" style="margin:8px 0 0">Confirmação automática via webhook do MercadoPago.</p></div>';
    if (!$rows) {
        $html .= '<div class="card"><p>Nenhum pedido ainda.</p></div>';
        admin_layout($pdo, 'Pedidos', $html);
        exit;
    }
    $html .= '<div class="card" style="overflow:auto"><table style="width:100%;border-collapse:collapse">';
    $html .= '<thead><tr>';
    foreach (['ID', 'Status', 'Total', 'Cliente', 'WhatsApp', 'Rifa', 'Criado'] as $th) {
        $html .= '<th style="text-align:left;padding:10px;border-bottom:1px solid rgba(15,23,42,0.08)">' . h($th) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $o) {
        $html .= '<tr>';
        $html .= '<td style="padding:10px;border-bottom:1px solid rgba(15,23,42,0.06)">' . (int) $o['id'] . '</td>';
        $html .= '<td style="padding:10px;border-bottom:1px solid rgba(15,23,42,0.06)">' . h((string) $o['status']) . '</td>';
        $html .= '<td style="padding:10px;border-bottom:1px solid rgba(15,23,42,0.06)">' . h(format_money_brl((int) $o['total_cents'])) . '</td>';
        $html .= '<td style="padding:10px;border-bottom:1px solid rgba(15,23,42,0.06)">' . h((string) $o['customer_name']) . '</td>';
        $html .= '<td style="padding:10px;border-bottom:1px solid rgba(15,23,42,0.06)">' . h((string) $o['customer_phone']) . '</td>';
        $html .= '<td style="padding:10px;border-bottom:1px solid rgba(15,23,42,0.06)">' . h((string) $o['raffle_title']) . '</td>';
        $html .= '<td style="padding:10px;border-bottom:1px solid rgba(15,23,42,0.06)">' . h((string) $o['created_at']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';
    admin_layout($pdo, 'Pedidos', $html);
    exit;
}

if ($action === 'settings') {
    $error = '';
    $saved = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $base = trim((string) ($_POST['base_url'] ?? ''));
        $token = trim((string) ($_POST['mp_access_token'] ?? ''));
        if ($base !== '' && !preg_match('#^https?://#i', $base)) {
            $error = 'Base URL deve começar com http:// ou https://';
        } else {
            set_setting($pdo, 'base_url', $base);
            if ($token !== '') {
                set_setting($pdo, 'mp_access_token', $token);
            }
            $saved = true;
        }
    }

    $baseUrl = (string) setting($pdo, 'base_url', '');
    $token = (string) setting($pdo, 'mp_access_token', '');
    $masked = $token !== '' ? (substr($token, 0, 6) . str_repeat('*', max(0, strlen($token) - 10)) . substr($token, -4)) : '';

    $html = '<div class="card"><h1 style="margin:0 0 6px">Configurações</h1>';
    $html .= '<p class="muted" style="margin:0">Defina a URL pública (para webhook) e o Access Token do MercadoPago.</p>';
    if ($saved) {
        $html .= '<p style="color:var(--success);font-weight:900">Salvo.</p>';
    }
    if ($error !== '') {
        $html .= '<p style="color:var(--danger);font-weight:900">' . h($error) . '</p>';
    }
    $html .= '<form method="post" class="form">';
    $html .= '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"/>';
    $html .= '<div class="form__row"><label class="label">Base URL</label><input class="input" name="base_url" placeholder="https://seu-dominio.com" value="' . h($baseUrl) . '"/></div>';
    $html .= '<div class="form__row"><label class="label">MercadoPago Access Token</label><input class="input" name="mp_access_token" placeholder="' . h($masked) . '" value=""/></div>';
    $html .= '<div class="form__actions"><button class="btn btn--primary" type="submit">Salvar</button></div>';
    $html .= '</form>';
    $html .= '<div style="margin-top:10px" class="muted"><strong>Webhook:</strong> ' . h(base_url($pdo) . '/webhook.php') . '</div>';
    $html .= '</div>';
    admin_layout($pdo, 'Configurações', $html);
    exit;
}

redirect('./admin.php');
