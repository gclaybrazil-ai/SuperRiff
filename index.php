<?php

declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'lib.php';

$pdo = db();

$page = $_GET['page'] ?? 'home';
if (!is_string($page)) {
    $page = 'home';
}

function render_layout(string $title, string $contentHtml): void
{
    $fullTitle = $title === '' ? 'SuperRiffas' : ($title . ' - SuperRiffas');
    echo '<!doctype html><html lang="pt-BR"><head>';
    echo '<meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/>';
    echo '<title>' . h($fullTitle) . '</title>';
    echo '<link rel="stylesheet" href="./assets/style.css"/>';
    echo '</head><body>';
    echo '<header class="topbar"><div class="container topbar__inner">';
    echo '<a class="brand" href="./index.php">SuperRiffas</a>';
    echo '<nav class="topbar__nav">';
    echo '<a class="link" href="./admin.php">Admin</a>';
    echo '</nav></div></header>';
    echo '<main class="container">' . $contentHtml . '</main>';
    echo '<script src="./assets/app.js"></script>';
    echo '</body></html>';
}

if ($page === 'home') {
    $stmt = $pdo->query("SELECT id, title, description, price_cents, total_numbers, status FROM raffles WHERE status = 'active' ORDER BY id DESC");
    $raffles = $stmt->fetchAll();

    $html = '<section class="hero"><h1>Rifas disponíveis</h1><p>Escolha sua rifa e reserve seus números via PIX.</p></section>';

    if (!$raffles) {
        $html .= '<div class="card"><p>Nenhuma rifa ativa ainda.</p></div>';
        render_layout('Início', $html);
        exit;
    }

    $html .= '<div class="grid">';
    foreach ($raffles as $r) {
        $html .= '<article class="card card--raffle">';
        $html .= '<div class="card__title">' . h($r['title']) . '</div>';
        $html .= '<div class="card__desc">' . nl2br(h($r['description'])) . '</div>';
        $html .= '<div class="card__meta">';
        $html .= '<span class="pill">' . h(format_money_brl((int) $r['price_cents'])) . ' por número</span>';
        $html .= '<span class="pill">' . h((string) $r['total_numbers']) . ' números</span>';
        $html .= '</div>';
        $html .= '<a class="btn btn--primary" href="./index.php?page=raffle&id=' . (int) $r['id'] . '">Escolher números</a>';
        $html .= '</article>';
    }
    $html .= '</div>';

    render_layout('Início', $html);
    exit;
}

if ($page === 'raffle') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $stmt = $pdo->prepare('SELECT * FROM raffles WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $raffle = $stmt->fetch();
    if (!$raffle || $raffle['status'] !== 'active') {
        http_response_code(404);
        render_layout('Rifa', '<div class="card"><p>Rifa não encontrada.</p></div>');
        exit;
    }

    $digits = (int) $raffle['digits'];
    $total = (int) $raffle['total_numbers'];
    $priceCents = (int) $raffle['price_cents'];

    $stmt = $pdo->prepare('SELECT number, status FROM raffle_numbers WHERE raffle_id = :rid');
    $stmt->execute([':rid' => $id]);
    $rows = $stmt->fetchAll();
    $statusByNumber = [];
    foreach ($rows as $row) {
        $statusByNumber[(int) $row['number']] = (string) $row['status'];
    }

    $legend = '<div class="legend">'
        . '<span class="legend__item"><span class="swatch swatch--available"></span> Disponível</span>'
        . '<span class="legend__item"><span class="swatch swatch--reserved"></span> Reservado</span>'
        . '<span class="legend__item"><span class="swatch swatch--paid"></span> Pago</span>'
        . '</div>';

    $html = '<section class="raffle">';
    $html .= '<div class="card raffle__head">';
    $html .= '<h1 class="raffle__title">' . h($raffle['title']) . '</h1>';
    $html .= '<p class="raffle__desc">' . nl2br(h($raffle['description'])) . '</p>';
    $html .= '<div class="raffle__meta"><span class="pill">' . h(format_money_brl($priceCents)) . ' por número</span></div>';
    $html .= $legend;
    $html .= '</div>';

    $html .= '<div class="card"><div class="numbers" data-raffle-id="' . (int) $id . '" data-price-cents="' . $priceCents . '" data-digits="' . $digits . '">';
    for ($n = 0; $n < $total; $n++) {
        $st = $statusByNumber[$n] ?? 'available';
        $label = str_pad((string) $n, $digits, '0', STR_PAD_LEFT);
        $class = 'num num--' . $st;
        $disabled = ($st !== 'available') ? ' disabled' : '';
        $html .= '<button class="' . h($class) . '" data-number="' . $n . '"' . $disabled . '>';
        $html .= '<div class="num__value">' . h($label) . '</div>';
        $html .= '<div class="num__status">' . h($st === 'available' ? 'disponível' : ($st === 'reserved' ? 'reservado' : 'pago')) . '</div>';
        $html .= '</button>';
    }
    $html .= '</div></div>';

    $html .= '<div class="bottom-bar" data-bottom-bar hidden>';
    $html .= '<div class="bottom-bar__left">';
    $html .= '<div class="bottom-bar__line"><span class="muted">Selecionados</span> <strong data-selected-count>0</strong></div>';
    $html .= '<div class="bottom-bar__line"><span class="muted">Total</span> <strong data-total>R$ 0,00</strong></div>';
    $html .= '</div>';
    $html .= '<button class="btn btn--primary" data-open-reserve>Reservar agora</button>';
    $html .= '</div>';

    $html .= '<div class="modal" data-modal hidden>';
    $html .= '<div class="modal__backdrop" data-modal-close></div>';
    $html .= '<div class="modal__card">';
    $html .= '<div class="modal__head"><div class="modal__title">Reservar meus números</div><button class="icon-btn" data-modal-close aria-label="Fechar">×</button></div>';
    $html .= '<form class="form" data-reserve-form>';
    $html .= '<input type="hidden" name="raffle_id" value="' . (int) $id . '"/>';
    $html .= '<div class="form__row"><label class="label">Nome</label><input class="input" name="name" autocomplete="name" required/></div>';
    $html .= '<div class="form__row"><label class="label">WhatsApp</label><input class="input" name="phone" autocomplete="tel" required/></div>';
    $html .= '<div class="form__actions">';
    $html .= '<button type="button" class="btn" data-modal-close>Cancelar</button>';
    $html .= '<button type="submit" class="btn btn--primary" data-submit-reserve>Gerar PIX</button>';
    $html .= '</div>';
    $html .= '<div class="form__error" data-form-error hidden></div>';
    $html .= '</form>';
    $html .= '</div></div>';

    $html .= '</section>';
    render_layout($raffle['title'], $html);
    exit;
}

if ($page === 'checkout') {
    $orderId = isset($_GET['order']) ? (int) $_GET['order'] : 0;
    $stmt = $pdo->prepare('SELECT o.*, r.title AS raffle_title, r.digits AS digits FROM orders o JOIN raffles r ON r.id = o.raffle_id WHERE o.id = :id');
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        http_response_code(404);
        render_layout('Pagamento', '<div class="card"><p>Pedido não encontrado.</p></div>');
        exit;
    }

    $numbers = json_decode((string) $order['numbers_json'], true);
    if (!is_array($numbers)) {
        $numbers = [];
    }
    $digits = (int) $order['digits'];
    $labels = array_map(fn ($n) => str_pad((string) (int) $n, $digits, '0', STR_PAD_LEFT), $numbers);

    $expiresAt = (string) $order['expires_at'];
    $pix = (string) ($order['mp_pix_copia_cola'] ?? '');
    $qrBase64 = (string) ($order['mp_pix_qr_code_base64'] ?? '');

    $html = '<div class="card">';
    $html .= '<h1>Pagamento PIX</h1>';
    $html .= '<p class="muted">Rifa: <strong>' . h($order['raffle_title']) . '</strong></p>';
    $html .= '<p class="muted">Números: <strong>' . h(implode(', ', $labels)) . '</strong></p>';
    $html .= '<div class="paybox" data-checkout data-order-id="' . (int) $orderId . '" data-expires-at="' . h($expiresAt) . '">';
    $html .= '<div class="paybox__timer"><span class="timer" data-timer>--:--</span><span class="muted">Tempo restante para pagamento</span></div>';
    $html .= '<div class="paybox__amount"><span class="muted">Valor</span> <strong>' . h(format_money_brl((int) $order['total_cents'])) . '</strong></div>';
    if ($qrBase64 !== '') {
        $html .= '<div class="paybox__qr"><img alt="QR Code PIX" src="data:image/png;base64,' . h($qrBase64) . '"/></div>';
    }
    if ($pix !== '') {
        $html .= '<div class="paybox__pix">';
        $html .= '<div class="label">PIX copia e cola</div>';
        $html .= '<textarea class="textarea" readonly data-pix>' . h($pix) . '</textarea>';
        $html .= '<button class="btn btn--primary" type="button" data-copy-pix>Copiar código PIX</button>';
        $html .= '</div>';
    } else {
        $html .= '<p>PIX ainda está sendo preparado. Aguarde…</p>';
    }
    $html .= '<div class="paybox__status" data-pay-status></div>';
    $html .= '</div>';
    $html .= '</div>';

    render_layout('Pagamento', $html);
    exit;
}

http_response_code(404);
render_layout('Página', '<div class="card"><p>Página não encontrada.</p></div>');

