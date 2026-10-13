<?php
/* ---------------------------------------------------------------------------
   MODELO DE CHAVES  —  este arquivo é um exemplo e vai para o repositório.

   COMO USAR NO SERVIDOR:
     1. Copie este arquivo como "secrets.php" na mesma pasta ("API PHP/").
     2. Troque os valores de exemplo pelas chaves reais.
     3. NUNCA comite o secrets.php — ele está no .gitignore.

   Sem o secrets.php o PIX não é gerado (o log do servidor avisa).
   --------------------------------------------------------------------------- */

if (!defined('CHECKOUT_BOOT')) { http_response_code(403); exit('Forbidden'); }

return [

    /* Tronfy — chave PRIVADA, só backend. Painel do lojista > API.
       Se você preencher a API key no painel do checkout, ela tem prioridade. */
    'tronfy_sk' => 'sk_live_tronfy_SUA_CHAVE_PRIVADA',

    /* Tronfy — chave PÚBLICA. Usada só para tokenizar cartão no front.
       O funil é PIX, então pode ficar vazia se você não usa cartão. */
    'tronfy_pk' => 'pk_live_tronfy_SUA_CHAVE_PUBLICA',

    /* BlueGet / zipcardx — token da consulta de CPF. */
    'blueget_token' => 'SEU_TOKEN_DA_CONSULTA_DE_CPF',

    /* Meta Conversions API — envia o Purchase pelo servidor (independe do
       navegador do cliente ficar aberto). Gere em Gerenciador de Eventos >
       pixel > Configurações > Conversions API > "Gerar token de acesso". */
    'meta_pixel_id'   => '1406579241587289',
    'meta_capi_token' => 'SEU_TOKEN_DA_CONVERSIONS_API',

    /* Opcional: código de teste (aba "Testar eventos" no Gerenciador de
       Eventos) para validar sem misturar com dados reais. Deixe vazio em
       produção. */
    'meta_test_event_code' => '',

];
