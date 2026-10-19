<?php
declare(strict_types=1);

/**
 * g26 tracking — Meta CAPI + Utmify server-side
 * Espelho PHP do htdocs/functions/_lib.js (CNH)
 * - Meta CAPI: event_id deduplicação, advanced matching em/ph (sha256), ip, ua, fbp, fbc, external_id (cpf)
 * - Utmify: POST api.utmify.com.br/api-credentials/orders  x-api-token
 */

define('G26_META_PIXEL_ID', '1094955653523354');
define('G26_META_CAPI_TOKEN', 'EAAjv25niPAsBSyfdkWajwFuIIFQFgIF1iWGmXCmYyJuAZA1huDUZC4L9xuu4cF6zgqndWnZCplFKZBvXzIIA35ZAKdQoQ7h51P8Kl1oZCXZAYKifT66AnLHyWfjIxwpUZABGiOZB9BnbgtOFOStedj4WMbuxEsxRSGqBrmAvIjCxHwFfzSZAPlwXMqF1pfok0XZCQZDZD');
define('G26_UTMIFY_API_TOKEN', 'I3rkjjxZonP50FzjkStNJrm899HjlSfkD1JZ');
define('G26_UTMIFY_PLATFORM', 'g26');

function g26_sha256_hex(?string $value): ?string {
    if ($value === null) return null;
    $v = trim($value);
    if ($v === '') return null;
    return hash('sha256', $v);
}

function g26_normalize_email(?string $email): ?string {
    if (!$email) return null;
    $e = strtolower(trim($email));
    if ($e === '' || strpos($e, '@') === false) return null;
    if (strpos($e, 'naoinformado') !== false) return null;
    return $e;
}

function g26_normalize_phone(?string $phone): ?string {
    if (!$phone) return null;
    $d = preg_replace('/\D/', '', (string)$phone);
    if ($d === '' || $d === null) return null;
    if (strlen($d) === 13 && strpos($d, '55') === 0) $d = substr($d, 2);
    if (strlen($d) < 10) return null;
    if (strlen($d) === 10) $d = substr($d, 0, 2) . '9' . substr($d, 2);
    // Meta espera com DDI para hash
    return '55' . $d;
}

function g26_normalize_cpf(?string $cpf): ?string {
    if (!$cpf) return null;
    $d = preg_replace('/\D/', '', (string)$cpf);
    return strlen($d) === 11 ? $d : null;
}

function g26_extract_fb_cookies(?string $cookieHeader, ?string $fbclid): array {
    $fbp = null; $fbc = null;
    if ($cookieHeader) {
        foreach (explode(';', $cookieHeader) as $part) {
            $idx = strpos($part, '=');
            if ($idx === false) continue;
            $k = trim(substr($part, 0, $idx));
            $v = trim(substr($part, $idx + 1));
            if ($k === '_fbp') $fbp = $v;
            elseif ($k === '_fbc') $fbc = $v;
        }
    }
    if (!$fbc && $fbclid) {
        $fbc = 'fb.1.' . time() . '.' . $fbclid;
    }
    return ['fbp' => $fbp, 'fbc' => $fbc];
}

function g26_get_client_ip(): ?string {
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $v = $_SERVER[$k];
            if (strpos($v, ',') !== false) $v = trim(explode(',', $v)[0]);
            $v = trim($v);
            if (filter_var($v, FILTER_VALIDATE_IP)) return $v;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? null;
}

function g26_get_event_source_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    // Se vier de webhook/api, tenta referer ou host
    return $scheme . $host . $uri;
}

function g26_get_utm_params(array $extra = []): array {
    $utm = [];
    $keys = ['utm_source','utm_campaign','utm_medium','utm_content','utm_term','src','sck','fbclid','xcod'];
    foreach ($keys as $k) {
        $v = $_GET[$k] ?? $_POST[$k] ?? $extra[$k] ?? null;
        if ($v !== null && $v !== '') $utm[$k] = (string)$v;
        else $utm[$k] = null;
    }
    // também tenta extrair de cookie utmify / session
    if (empty($utm['utm_source']) && !empty($_COOKIE['utm_source'])) $utm['utm_source'] = $_COOKIE['utm_source'];
    return $utm;
}

function g26_log_tracking(string $msg): void {
    @file_put_contents(__DIR__ . '/../pagamento/log_tracking.txt', '[' . date('d/m/Y H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * Envia evento para Meta Conversions API (server-side)
 * eventName: InitiateCheckout | Purchase | ViewContent | PageView ...
 * eventId: usado para deduplicação com fbq browser (transactionId)
 */
function g26_send_meta_capi(array $opts): ?array {
    $pixelId = G26_META_PIXEL_ID;
    $token   = G26_META_CAPI_TOKEN;
    if (!$pixelId || !$token) {
        g26_log_tracking('META skip: pixel/token vazio');
        return null;
    }
    $eventName = $opts['eventName'] ?? 'Purchase';
    $eventId   = $opts['eventId'] ?? uniqid('evt_');
    $value     = $opts['value'] ?? null;
    $currency  = $opts['currency'] ?? 'BRL';
    $email     = $opts['email'] ?? null;
    $phone     = $opts['phone'] ?? null;
    $cpf       = $opts['cpf'] ?? null;
    $ip        = $opts['ip'] ?? g26_get_client_ip();
    $ua        = $opts['userAgent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null);
    $fbp       = $opts['fbp'] ?? null;
    $fbc       = $opts['fbc'] ?? null;
    $eventSourceUrl = $opts['eventSourceUrl'] ?? g26_get_event_source_url();

    // advanced matching — tudo hasheado exceto fbp/fbc/ip/ua
    $userData = [];
    $emNorm = g26_normalize_email($email);
    if ($emNorm) {
        $h = g26_sha256_hex($emNorm);
        if ($h) $userData['em'] = [$h];
    }
    $phNorm = g26_normalize_phone($phone);
    if ($phNorm) {
        $h = g26_sha256_hex($phNorm);
        if ($h) $userData['ph'] = [$h];
    }
    $cpfNorm = g26_normalize_cpf($cpf);
    if ($cpfNorm) {
        // Meta não tem campo cpf nativo — envia como external_id (recomendado para BR)
        $h = g26_sha256_hex($cpfNorm);
        if ($h) $userData['external_id'] = [$h];
    }
    if ($ip) $userData['client_ip_address'] = $ip;
    if ($ua) $userData['client_user_agent'] = $ua;
    if ($fbp) $userData['fbp'] = $fbp;
    if ($fbc) $userData['fbc'] = $fbc;

    $payload = [
        'data' => [[
            'event_name' => $eventName,
            'event_time' => time(),
            'action_source' => 'website',
            'event_source_url' => $eventSourceUrl,
            'event_id' => $eventId,
            'user_data' => $userData,
            'custom_data' => array_filter([
                'value' => $value,
                'currency' => $currency,
                'content_name' => $opts['contentName'] ?? 'Tarifa',
                'content_type' => 'product',
            ], fn($v) => $v !== null && $v !== ''),
        ]],
    ];

    $url = 'https://graph.facebook.com/v21.0/' . $pixelId . '/events?access_token=' . $token;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        g26_log_tracking("META CAPI ERRO curl $eventName/$eventId: $err");
        return ['ok' => false, 'error' => $err];
    }
    $json = json_decode((string)$resp, true);
    $ok = $http >= 200 && $http < 300 && isset($json['events_received']);
    g26_log_tracking("META CAPI $eventName/$eventId http=$http ok=" . ($ok ? '1' : '0') . ' resp=' . substr((string)$resp, 0, 400));
    return ['ok' => $ok, 'http' => $http, 'response' => $json, 'payload' => $payload];
}

function g26_build_utmify_order(array $p): array {
    $now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
    $createdAt = $p['createdAt'] ?? $now;
    if (is_string($createdAt)) $createdAt = new DateTime($createdAt);
    $fmt = fn(DateTime $d) => $d->format('Y-m-d H:i:s');
    $cents = (int)($p['valueInCents'] ?? 0);
    $utm = $p['utm'] ?? [];
    $ip = $p['ip'] ?? g26_get_client_ip();
    return [
        'orderId' => $p['orderId'],
        'platform' => G26_UTMIFY_PLATFORM,
        'paymentMethod' => 'pix',
        'status' => $p['status'], // waiting_payment | paid
        'createdAt' => $fmt($createdAt),
        'approvedDate' => !empty($p['approvedDate']) ? $fmt($p['approvedDate'] instanceof DateTime ? $p['approvedDate'] : new DateTime($p['approvedDate'])) : null,
        'refundedAt' => null,
        'customer' => [
            'name' => $p['customerName'] ?? 'Cliente',
            'email' => $p['customerEmail'] ?? ($p['customerDocument'] . '@naoinformado.com'),
            'phone' => $p['customerPhone'] ?? null,
            'document' => preg_replace('/\D/', '', (string)($p['customerDocument'] ?? '')) ?: null,
            'country' => 'BR',
            'ip' => $ip,
        ],
        'products' => [[
            'id' => $p['productId'] ?? 'tarifa',
            'name' => $p['productTitle'] ?? 'Tarifa Transacional',
            'planId' => $p['productId'] ?? 'tarifa',
            'planName' => $p['productTitle'] ?? 'Tarifa Transacional',
            'quantity' => 1,
            'priceInCents' => $cents,
        ]],
        'trackingParameters' => [
            'src' => $utm['src'] ?? null,
            'sck' => $utm['sck'] ?? null,
            'utm_source' => $utm['utm_source'] ?? $utm['src'] ?? null,
            'utm_campaign' => $utm['utm_campaign'] ?? null,
            'utm_medium' => $utm['utm_medium'] ?? null,
            'utm_content' => $utm['utm_content'] ?? null,
            'utm_term' => $utm['utm_term'] ?? null,
        ],
        'commission' => [
            'totalPriceInCents' => $cents,
            'gatewayFeeInCents' => 0,
            'userCommissionInCents' => $cents,
            'currency' => 'BRL',
        ],
        'isTest' => false,
    ];
}

function g26_send_utmify_order(array $order): ?array {
    $token = G26_UTMIFY_API_TOKEN;
    if (!$token || empty($order['orderId'])) {
        g26_log_tracking('UTMIFY skip: token/orderId vazio');
        return null;
    }
    $ch = curl_init('https://api.utmify.com.br/api-credentials/orders');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($order),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-token: ' . $token],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) {
        g26_log_tracking('UTMIFY ERRO curl ' . $order['orderId'] . ': ' . $err);
        return ['ok' => false, 'error' => $err];
    }
    $json = json_decode((string)$resp, true);
    $ok = $http >= 200 && $http < 300;
    g26_log_tracking('UTMIFY ' . $order['status'] . '/' . $order['orderId'] . " http=$http ok=" . ($ok ? '1' : '0') . ' resp=' . substr((string)$resp, 0, 400));
    return ['ok' => $ok, 'http' => $http, 'response' => $json];
}

/** Salva/atualiza pedido para o webhook conseguir enviar Purchase com dados completos */
function g26_save_order(string $orderId, array $data): void {
    $dir = __DIR__ . '/../pagamento/clientes';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = $dir . '/' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $orderId) . '.json';
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function g26_load_order(string $orderId): ?array {
    $file = __DIR__ . '/../pagamento/clientes/' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $orderId) . '.json';
    if (!is_file($file)) return null;
    $j = json_decode((string)@file_get_contents($file), true);
    return is_array($j) ? $j : null;
}
