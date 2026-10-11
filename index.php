<?php
/* Raiz do site -> presell antes do funil.
   Preserva a query string (utm_*, fbclid, etc.) para nao quebrar
   a atribuicao do pixel do Meta. */

$qs  = isset($_SERVER['QUERY_STRING']) ? (string)$_SERVER['QUERY_STRING'] : '';
$url = 'inicio/' . ($qs !== '' ? '?' . $qs : '');

header('Location: ' . $url, true, 302);
exit;
