<?php
define('CHECKOUT_BOOT', 1);

@ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

set_exception_handler(function($e){
    error_log('[checkout api] exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode(['success' => false, 'error' => 'Erro interno ao processar o pagamento. Tente novamente.']);
    exit;
});
register_shutdown_function(function(){
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        error_log('[checkout api] fatal: ' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line']);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode(['success' => false, 'error' => 'Erro interno no servidor de pagamento.']);
    }
});

require __DIR__ . '/lib.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: SAMEORIGIN');

$action = $_GET['action'] ?? '';

function body_json() {
    $raw = file_get_contents('php://input');
    $j = json_decode((string)$raw, true);
    return is_array($j) ? $j : [];
}

switch ($action) {

    case 'consult_cpf': {
        $cpf = only_digits($_GET['cpf'] ?? '');
        if (strlen($cpf) !== 11) json_fail('CPF inválido.', 400);

        $bg = cfg()['blueget'];
        $url = rtrim($bg['base_url'], '/') . '/consult/' . $cpf;
        $r = http_json('GET', $url, ['Authorization: Bearer ' . $bg['api_token']], null, 20);

        if (!is_array($r['json']) || ($r['code'] >= 500)) {
            error_log('[consult_cpf] code=' . ($r['code'] ?? '?') . ' err=' . ($r['error'] ?? '') . ' body=' . substr((string)($r['raw'] ?? ''), 0, 300));
            json_fail('Não foi possível consultar o CPF agora. Tente novamente.', 502);
        }

        $d = $r['json'];
        if (empty($d['NOME'])) json_fail('CPF não encontrado.', 404);

        json_out([
            'success'         => true,
            'nome'            => $d['NOME'],
            'data_nascimento' => $d['NASC'] ?? '',
            'cpf'             => $d['CPF'] ?? $cpf,
            'nome_mae'        => trim((string)($d['NOME_MAE'] ?? '')),
        ]);
        break;
    }

    case 'product': {
        $p = resolve_offer($_GET['offer'] ?? '');
        json_out([
            'success' => true,
            'product' => [
                'offer'           => $p['offer_id'],
                'name'            => $p['name'],
                'amount_cents'    => (int)$p['amount_cents'],
                'amount_formatted'=> 'R$ ' . number_format($p['amount_cents'] / 100, 2, ',', '.'),
            ],
        ]);
        break;
    }

    case 'generate': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_fail('Método inválido', 405);
        if (!rate_limit_ok()) json_fail('Muitas tentativas. Aguarde alguns minutos.', 429);

        $in = body_json();
        $offer = resolve_offer($in['offer'] ?? '');

        // Valor dinâmico do seguro (vem da cotação escolhida na página 9/10)
        if (!empty($in['pix_total']) && is_numeric($in['pix_total'])) {
            $custom_cents = (int)round(floatval($in['pix_total']) * 100);
            $allowed = [2990, 3684, 4690, 4890, 5584, 5890, 6590, 6884];
            if ($custom_cents >= 2990 && $custom_cents <= 7000) {
                $offer['amount_cents'] = $custom_cents;
            }
        }

        $cfg = cfg();
        foreach (['bump', 'bump2', 'bump3'] as $i => $bk) {
            $bnum = $i + 1;
            $bdata = $cfg[$bk];
            $quer  = !empty($in["bump{$bnum}"]) && $in["bump{$bnum}"] !== 'false';
            if ($quer && $bdata['ativo']) {
                $offer['amount_cents'] = (int)$offer['amount_cents'] + (int)$bdata['amount_cents'];
                $nome_bump = ltrim(trim($bdata['nome']), '+ ');
                $offer['name'] = $offer['name'] . ' + ' . $nome_bump;
            }
        }

        $name = clean_str($in['name'] ?? '', 120);
        $doc  = only_digits($in['document'] ?? '');
        $email= clean_str($in['email'] ?? '', 120);
        $phone= normalize_phone($in['phone'] ?? '');

        if ($name === '' || strlen($name) < 3) json_fail('Nome do cliente inválido.');
        if (strlen($doc) < 11) json_fail('CPF inválido.');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = $doc . '@checkout.com';
        }
        if ($phone === '') $phone = '5511999999999';

        $utms = isset($in['utms']) && is_array($in['utms']) ? $in['utms'] : [];
        $clean_utms = [];
        foreach ($utms as $k => $v) {
            $k = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$k);
            if ($k !== '') $clean_utms[$k] = clean_str($v, 300);
        }

        $external_code = $offer['offer_id'] . '_' . substr(md5($doc . '|' . microtime(true) . '|' . mt_rand()), 0, 18);

        $r = tronfy_create_pix(
            ['name' => $name, 'email' => $email, 'document' => $doc, 'phone' => $phone],
            $clean_utms,
            $external_code,
            $offer
        );

        if (!$r['ok']) {
            error_log('[tronfy generate] code=' . ($r['code'] ?? '?') . ' body=' . json_encode($r['data'] ?? null) . ' err=' . ($r['error'] ?? ''));
            json_fail('Não foi possível gerar o PIX agora. Tente novamente em instantes.', 502);
        }

        $payment_code = (string)($r['payment_code'] ?? '');
        $qr_text      = (string)($r['qr_code'] ?? '');
        $qr_img       = (string)($r['qr_code_base64'] ?? '');

        if ($payment_code === '' || $qr_text === '') {
            error_log('[tronfy generate] resposta incompleta: code=' . ($r['code'] ?? '?') . ' body=' . substr((string)($r['raw'] ?? ''), 0, 800));
            json_fail('Resposta incompleta do gateway. Tente novamente.', 502);
        }

        $order = [
            'payment_code'   => $payment_code,
            'external_code'  => $external_code,
            'offer'          => $offer['offer_id'],
            'product'        => ['id' => $offer['id'], 'name' => $offer['name'], 'amount_cents' => (int)$offer['amount_cents']],
            'created_at_utc' => gmdate('Y-m-d H:i:s'),
            'ip'             => client_ip(),
            'user_agent'     => clean_str($_SERVER['HTTP_USER_AGENT'] ?? '', 300),
            'page_url'       => filter_var($in['page_url'] ?? '', FILTER_VALIDATE_URL) ?: checkout_base_url(),
            'customer'       => ['name' => $name, 'email' => $email, 'document' => $doc, 'phone' => $phone],
            'utms'           => $clean_utms,
            'status'         => 'pending',
        ];

        order_save($payment_code, $order);

        json_out([
            'success'    => true,
            'production' => true,
            'pix' => [
                'transaction_id' => $payment_code,
                'qr_code'        => $qr_text,
                'qr_code_base64' => $qr_img,
                'expires_at'     => $r['expires_at'] ?? null,
            ],
        ]);
        break;
    }

    case 'status': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_fail('Método inválido', 405);
        $in = body_json();
        $payment_code = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($in['transaction_id'] ?? ''));
        if ($payment_code === '') json_fail('Transação inválida.');

        $order = order_load($payment_code);
        if ($order && ($order['status'] ?? '') === 'paid') {
            json_out(['success' => true, 'status' => 'paid']);
        }

        $r = tronfy_get_payment($payment_code);
        if (!$r['ok']) json_out(['success' => true, 'status' => 'pending']);

        $status = $r['status'];

        if ($status === 'paid') {
            mark_order_paid($payment_code);
        }

        json_out(['success' => true, 'status' => $status]);
        break;
    }

    case 'webhook': {
        $in = body_json();
        $parsed = tronfy_parse_webhook($in);
        $payment_code = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($parsed['payment_code'] ?? ''));
        if ($payment_code === '') { http_response_code(200); echo 'ignored'; exit; }

        if (($parsed['status'] ?? '') === 'paid') {
            $v = tronfy_get_payment($payment_code);
            if ($v['ok'] && ($v['status'] ?? '') === 'paid') {
                mark_order_paid($payment_code);
            } elseif (!$v['ok']) {
                http_response_code(503);
                header('Retry-After: 30');
                echo 'retry-verify';
                exit;
            }
        }
        http_response_code(200); echo 'ok'; exit;
    }

    default:
        json_fail('Ação desconhecida', 404);
}
