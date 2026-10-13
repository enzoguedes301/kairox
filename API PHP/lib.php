<?php
if (!defined('CHECKOUT_BOOT')) { http_response_code(403); exit('Forbidden'); }

function cfg() {
    static $c = null;
    if ($c === null) { $c = require __DIR__ . '/config.php'; }
    return $c;
}

function resolve_offer($id) {
    $c = cfg();
    $offers = $c['offers'];
    $id = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$id);
    if ($id === '' || !isset($offers[$id])) $id = $c['default_offer'];
    $o = $offers[$id];
    $o['offer_id'] = $id;
    return $o;
}

function data_dir() {
    $dir = cfg()['security']['data_dir'];
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) { @file_put_contents($ht, "Require all denied\nDeny from all\n"); }
    return $dir;
}

function json_out($arr, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function json_fail($msg, $code = 400) { json_out(['success' => false, 'error' => $msg], $code); }

function only_digits($s) { return preg_replace('/\D+/', '', (string)$s); }

function clean_str($s, $max = 120) {
    $s = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$s));
    if (function_exists('mb_substr')) { return mb_substr($s, 0, $max); }
    return substr($s, 0, $max);
}

function valid_cpf($cpf) {
    $cpf = only_digits($cpf);
    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) return false;
    for ($t = 9; $t < 11; $t++) {
        $sum = 0;
        for ($i = 0; $i < $t; $i++) $sum += (int)$cpf[$i] * (($t + 1) - $i);
        $d = ((10 * $sum) % 11) % 10;
        if ((int)$cpf[$t] !== $d) return false;
    }
    return true;
}

function normalize_phone($phone) {
    $d = only_digits($phone);
    if ($d === '') return '';
    if (strlen($d) <= 11) $d = '55' . $d;
    return substr($d, 0, 13);
}

function client_ip() {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

function rate_limit_ok() {
    $s = cfg()['security'];
    $file = data_dir() . '/rl_' . md5(client_ip()) . '.json';
    $now = time();
    $hits = [];
    if (is_file($file)) {
        $hits = json_decode((string)@file_get_contents($file), true) ?: [];
    }
    $hits = array_values(array_filter($hits, fn($t) => ($now - $t) < $s['rate_limit_window']));
    if (count($hits) >= $s['rate_limit_max']) return false;
    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);
    return true;
}

function order_path($payment_code) {
    return data_dir() . '/order_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $payment_code) . '.json';
}
function order_save($payment_code, $data) {
    @file_put_contents(order_path($payment_code), json_encode($data), LOCK_EX);
}
function order_load($payment_code) {
    $p = order_path($payment_code);
    return is_file($p) ? (json_decode((string)@file_get_contents($p), true) ?: null) : null;
}

function http_json($method, $url, $headers = [], $body = null, $timeout = 30) {
    $ch = curl_init($url);
    $h = array_merge(['Accept: application/json'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
    }
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    $json = json_decode((string)$raw, true);
    return ['code' => $code, 'json' => $json, 'raw' => $raw, 'error' => $err];
}

function map_status($raw_status) {
    $s = strtolower((string)$raw_status);
    if (in_array($s, ['approved', 'paid', 'completed', 'succeeded', 'success', 'confirmed', 'authorized',
                      'aprovada', 'aprovado', 'paga', 'pago'])) return 'paid';
    if (in_array($s, ['refused', 'canceled', 'cancelled', 'refunded', 'chargeback', 'chargedback', 'expired', 'failed', 'declined', 'rejected', 'error',
                      'falha', 'falhou', 'recusada', 'recusado', 'cancelada', 'cancelado', 'expirada', 'expirado', 'estornada', 'estornado'])) return 'failed';
    return 'pending'; // inclui pending / em_analise / aguardando
}

function extract_payment_status($data) {
    if (!is_array($data)) return '';
    $keys = ['payment_status', 'status', 'transaction_status', 'situation', 'state', 'payment_state'];
    foreach ($keys as $k) {
        if (!empty($data[$k]) && (is_string($data[$k]) || is_numeric($data[$k]))) return (string)$data[$k];
    }
    foreach (['payment', 'data', 'transaction', 'pix', 'result', 'order', 'charge'] as $nk) {
        if (isset($data[$nk]) && is_array($data[$nk])) {
            foreach ($keys as $k) {
                if (!empty($data[$nk][$k]) && (is_string($data[$nk][$k]) || is_numeric($data[$nk][$k]))) return (string)$data[$nk][$k];
            }
        }
    }
    return '';
}

/* ========== TRONFY ========== */

function tronfy_create_pix($customer, $utms, $external_code, $offer) {
    $s = cfg()['tronfy'];

    $doc   = preg_replace('/\D+/', '', (string)($customer['document'] ?? ''));
    $phone = preg_replace('/\D+/', '', (string)($customer['phone'] ?? ''));
    if (strlen($phone) >= 12 && substr($phone, 0, 2) === '55') { $phone = substr($phone, 2); }

    /* A Tronfy trabalha em minutos; o painel guarda em dias. */
    $exp_minutes = (int)($s['pix_expires_in_days'] ?? 1) * 1440;
    if ($exp_minutes < 5) $exp_minutes = 60;

    $base = checkout_base_url();

    $payload = [
        'amount'      => (int)$offer['amount_cents'],
        'description' => $offer['name'],
        'external_id' => $external_code,
        'customer' => [
            'name'     => $customer['name'],
            'document' => $doc,
            'email'    => $customer['email'],
            'phone'    => $phone,
        ],
        'product' => [
            'name'          => $offer['name'],
            'link'          => $base,
            'checkout_link' => $base,
            'external_ref'  => $external_code,
            'value'         => (int)$offer['amount_cents'],
        ],
        'expiration_minutes' => $exp_minutes,
        'metadata'           => array_merge(['external_code' => $external_code], is_array($utms) ? $utms : []),
    ];

    $headers = [
        'x-api-key: '         . $s['api_key'],
        'X-Idempotency-Key: ' . $external_code,
        'Content-Type: application/json',
    ];

    $res = http_json('POST', $s['base_url'] . '/pix-charges', $headers, $payload);
    $ok  = ($res['code'] >= 200 && $res['code'] < 300 && is_array($res['json']));

    $d   = is_array($res['json'] ?? null) ? $res['json'] : [];
    $pix = (isset($d['pix']) && is_array($d['pix'])) ? $d['pix'] : [];

    $payment_code = '';
    foreach (['id', 'transaction_id', 'charge_id'] as $k) {
        if (!empty($d[$k]) && (is_string($d[$k]) || is_numeric($d[$k]))) { $payment_code = (string)$d[$k]; break; }
    }

    /* Copia-e-cola: pix.qr_code_text (documentado) + variações de fallback. */
    $qr_text = '';
    foreach (['qr_code_text', 'qrcode_text', 'copia_cola', 'copia_e_cola', 'emv', 'payload', 'brcode'] as $k) {
        if (!empty($pix[$k]) && is_string($pix[$k])) { $qr_text = $pix[$k]; break; }
    }
    if ($qr_text === '') {
        foreach (['pix_copia_cola', 'qr_code_text', 'copia_cola', 'brcode'] as $k) {
            if (!empty($d[$k]) && is_string($d[$k])) { $qr_text = $d[$k]; break; }
        }
    }

    /* Imagem: pix.qr_code vem como data URI; o front aceita com ou sem prefixo. */
    $qr_img = '';
    foreach (['qr_code', 'qr_code_image', 'qrcode_image', 'image', 'base64'] as $k) {
        if (!empty($pix[$k]) && is_string($pix[$k])) { $qr_img = $pix[$k]; break; }
    }
    if ($qr_img === '' && !empty($d['pix_qr_code']) && is_string($d['pix_qr_code'])) {
        $qr_img = $d['pix_qr_code'];
    }

    /* Se só veio a imagem no campo pix.qr_code e ela não é data URI, é copia-e-cola. */
    if ($qr_text === '' && $qr_img !== '' && strpos($qr_img, 'data:') !== 0) {
        $qr_text = $qr_img;
        $qr_img  = '';
    }

    return [
        'ok'             => $ok && $payment_code !== '' && $qr_text !== '',
        'payment_code'   => $payment_code,
        'qr_code'        => $qr_text,
        'qr_code_base64' => $qr_img,
        'expires_at'     => $pix['expiration'] ?? ($d['expires_at'] ?? null),
        'code'           => $res['code'],
        'raw'            => $res['raw'],
        'error'          => $res['error'],
        'data'           => $d,
    ];
}

function tronfy_get_payment($payment_code) {
    $s = cfg()['tronfy'];
    $headers = ['x-api-key: ' . $s['api_key']];
    $url = $s['base_url'] . '/pix-charges/' . rawurlencode($payment_code);
    $res = http_json('GET', $url, $headers);

    if ($res['code'] >= 200 && $res['code'] < 300 && is_array($res['json'])) {
        return ['ok' => true, 'status' => map_status(extract_payment_status($res['json'])), 'raw' => $res['json']];
    }
    return ['ok' => false, 'status' => 'pending', 'code' => $res['code']];
}

/* Webhook Tronfy: { event, data: { id, external_id, status, paid_at } } */
function tronfy_parse_webhook($payload) {
    $data = (isset($payload['data']) && is_array($payload['data'])) ? $payload['data'] : $payload;

    $code = '';
    foreach (['id', 'transaction_id', 'charge_id'] as $k) {
        if (!empty($data[$k]) && (is_string($data[$k]) || is_numeric($data[$k]))) { $code = (string)$data[$k]; break; }
    }

    $event  = strtolower((string)($payload['event'] ?? ''));
    $status = map_status(extract_payment_status($payload));

    /* Saques não têm pedido associado; ignora. */
    if (strpos($event, 'withdrawal') === 0) { $status = 'pending'; $code = ''; }

    return [
        'payment_code' => $code,
        'status'       => $status,
        'event'        => $event,
    ];
}

function mark_order_paid($payment_code) {
    $order = order_load($payment_code);
    if (!$order) return 'not_found';
    if (($order['status'] ?? '') !== 'paid') {
        $order['status']  = 'paid';
        $order['paid_at'] = gmdate('Y-m-d H:i:s');
        order_save($payment_code, $order);
        meta_capi_send_purchase($order, $payment_code);
    }
    return 'ok';
}

/* ========== META CONVERSIONS API ==========
   Envia o Purchase pelo servidor no instante em que o pedido é marcado como
   pago (webhook ou polling de status) — não depende do navegador do cliente
   continuar aberto, diferente do pixel no front. */

function meta_hash($v) {
    $v = trim(mb_strtolower((string)$v));
    return $v === '' ? null : hash('sha256', $v);
}

function meta_capi_send_purchase($order, $payment_code) {
    $m = cfg()['meta'];
    if ($m['pixel_id'] === '' || $m['access_token'] === '') {
        error_log('[meta capi] ignorado: faltam meta_pixel_id/meta_capi_token em secrets.php');
        return;
    }

    $customer = $order['customer'] ?? [];
    $utms     = $order['utms'] ?? [];
    $product  = $order['product'] ?? [];

    $user_data = array_filter([
        'em'               => meta_hash($customer['email'] ?? ''),
        'ph'               => meta_hash($customer['phone'] ?? ''),
        'external_id'      => meta_hash($customer['document'] ?? ''),
        'client_ip_address'=> $order['ip'] ?? null,
        'client_user_agent'=> $order['user_agent'] ?? null,
        'fbp'              => $utms['_fbp'] ?? null,
        'fbc'              => $utms['_fbc'] ?? null,
    ]);

    $event = [
        'event_name'       => 'Purchase',
        'event_time'       => strtotime(($order['paid_at'] ?? gmdate('Y-m-d H:i:s')) . ' UTC'),
        'event_id'         => $payment_code,
        'action_source'    => 'website',
        'event_source_url' => $order['page_url'] ?? checkout_base_url(),
        'user_data'        => $user_data,
        'custom_data'      => [
            'currency'     => 'BRL',
            'value'        => round(((int)($product['amount_cents'] ?? 0)) / 100, 2),
            'content_name' => $product['name'] ?? '',
            'content_type' => 'product',
        ],
    ];

    $body = ['data' => [$event]];
    if (!empty($m['test_event_code'])) $body['test_event_code'] = $m['test_event_code'];

    $url = 'https://graph.facebook.com/v21.0/' . rawurlencode($m['pixel_id']) . '/events?access_token=' . rawurlencode($m['access_token']);
    $res = http_json('POST', $url, ['Content-Type: application/json'], $body, 15);

    if ($res['code'] < 200 || $res['code'] >= 300) {
        error_log('[meta capi] falhou code=' . ($res['code'] ?? '?') . ' body=' . substr((string)($res['raw'] ?? ''), 0, 500));
    }
}

function checkout_base_url() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) $scheme = explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0];
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . $dir;
}
