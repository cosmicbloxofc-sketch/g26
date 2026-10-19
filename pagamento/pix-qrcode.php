<?php

$code = trim($_GET['code'] ?? '');
if ($code === '' || strlen($code) > 2000) {
    http_response_code(400);
    exit;
}

$qrApi = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&margin=8&format=png&data=' . rawurlencode($code);

$image = false;
if (function_exists('curl_init')) {
    $curl = curl_init($qrApi);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $image = curl_exec($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($httpCode !== 200 || $image === false) {
        $image = false;
    }
}

if ($image === false && ini_get('allow_url_fopen')) {
    $ctx = stream_context_create(['http' => ['timeout' => 15]]);
    $image = @file_get_contents($qrApi, false, $ctx);
}

if ($image === false || $image === '') {
    http_response_code(502);
    exit;
}

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
echo $image;
