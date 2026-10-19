<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');

$gestaoPayConfig = require __DIR__ . '/gestaopay_config.php';
require_once __DIR__ . '/../lib/tracking.php';
define('GESTAOPAY_PUBLIC_KEY', (string)($gestaoPayConfig['public_key'] ?? ''));
define('GESTAOPAY_SECRET_KEY', (string)($gestaoPayConfig['secret_key'] ?? ''));
define('GESTAOPAY_API_URL', (string)($gestaoPayConfig['api_url'] ?? 'https://api.gestaopayments.com/v1/payment-transaction/create'));
define('GESTAOPAY_TIMEOUT', 30);

function salvarLogGestaoPay($msg) {
    $data = date('d/m/Y H:i:s');
    file_put_contents(__DIR__ . '/log_gestaopay.txt', '[' . $data . '] ' . $msg . PHP_EOL . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function extrairErroGestaoPay($res, $fallback = 'Erro ao gerar PIX') {
    if (!is_array($res)) {
        return $fallback;
    }
    if (!empty($res['error_messages']) && is_array($res['error_messages'])) {
        foreach ($res['error_messages'] as $item) {
            if (is_array($item) && !empty($item['message'])) {
                return (string)$item['message'];
            }
        }
    }
    if (!empty($res['message'])) {
        return (string)$res['message'];
    }
    if (!empty($res['error']) && is_string($res['error'])) {
        return (string)$res['error'];
    }
    return $fallback;
}

function postbackUrlGestaoPay() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '/pagamento')), '/');
    return $scheme . $host . $dir . '/webhook_gestaopay.php';
}

function gerarPixFusionPay($valor, $nome, $email, $telefone, $cpf) {
    if (GESTAOPAY_PUBLIC_KEY === '' || GESTAOPAY_SECRET_KEY === '') {
        return ['success' => false, 'error' => 'Credenciais GestaoPay nao configuradas'];
    }

    $cpfLimpo = preg_replace('/\D/', '', (string)$cpf);
    $telefoneLimpo = preg_replace('/\D/', '', (string)$telefone);
    if (strlen($telefoneLimpo) === 13 && strpos($telefoneLimpo, '55') === 0) {
        $telefoneLimpo = substr($telefoneLimpo, 2);
    }
    if (strlen($telefoneLimpo) < 10) {
        $telefoneLimpo = '11999999999';
    }
    if (strlen($telefoneLimpo) === 10) {
        $telefoneLimpo = substr($telefoneLimpo, 0, 2) . '9' . substr($telefoneLimpo, 2);
    }
    $telefoneComDdi = '55' . $telefoneLimpo;

    $valorCentavos = (int)round(((float)$valor) * 100);
    $identifier = 'pedido_' . uniqid();

    $payload = [
        'amount' => $valorCentavos,
        'payment_method' => 'pix',
        'postback_url' => postbackUrlGestaoPay(),
        'Customer' => [
            'Name' => $nome ?: 'Cliente',
            'Email' => $email ?: 'email@naoinformado.com',
            'Phone' => '+' . $telefoneComDdi,
            'Document' => [
                'Type' => 'cpf',
                'Number' => $cpfLimpo,
            ],
        ],
        'items' => [[
            'title' => 'Produto',
            'unit_price' => $valorCentavos,
            'quantity' => 1,
            'tangible' => false,
        ]],
        'metadata' => [
            'provider_name' => 'g26',
            'external_id' => $identifier,
        ],
        'pix' => [
            'expires_in_days' => 1,
        ],
    ];

    $auth = 'Basic ' . base64_encode(trim(GESTAOPAY_PUBLIC_KEY) . ':' . GESTAOPAY_SECRET_KEY);
    salvarLogGestaoPay('REQ ' . $identifier . ' VALOR=' . $valorCentavos);

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => GESTAOPAY_API_URL,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'authorization: ' . $auth,
        ],
        CURLOPT_TIMEOUT => GESTAOPAY_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($curl);
    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_errno($curl) ? curl_error($curl) : null;
    curl_close($curl);

    if (!empty($curlError)) {
        salvarLogGestaoPay('ERRO CURL: ' . $curlError);
        return ['success' => false, 'error' => 'Erro de conexao', 'details' => $curlError];
    }

    salvarLogGestaoPay('RESP (' . $httpCode . '): ' . (string)$response);

    $res = json_decode((string)$response, true);
    if ($httpCode < 200 || $httpCode > 201) {
        return [
            'success' => false,
            'error' => extrairErroGestaoPay($res),
            'response' => $res,
        ];
    }

    if (isset($res['success']) && $res['success'] === false) {
        return [
            'success' => false,
            'error' => extrairErroGestaoPay($res),
            'response' => $res,
        ];
    }

    $root = $res['data'] ?? ($res['request'] ?? ($res['Request'] ?? $res));
    $pix = $root['pix'] ?? ($root['Pix'] ?? []);
    $pixCode = $pix['qr_code']
        ?? ($pix['code']
        ?? ($pix['Code']
        ?? ($pix['qrcode']
        ?? ($pix['QrCode']
        ?? ($root['pixCode'] ?? ($root['emv'] ?? null))))));

    if (!$pixCode) {
        return ['success' => false, 'error' => 'Erro ao gerar PIX', 'response' => $res];
    }

    $pixQrBase64 = $pix['base64'] ?? ($pix['Base64'] ?? ($pix['image'] ?? ($root['qrCodeBase64'] ?? null)));
    $pixQr = $pixQrBase64 ? 'data:image/png;base64,' . $pixQrBase64 : null;
    $orderId = $root['id'] ?? ($root['transactionId'] ?? ($root['transaction_id'] ?? $identifier));
    $qrFallback = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . urlencode($pixCode);

    return [
        'success' => true,
        'pix_code' => $pixCode,
        'pix_qrcode_url' => $pixQr ?: $qrFallback,
        'transaction_id' => $orderId,
        'provider' => 'gestaopay',
        'data' => $res,
    ];
}

$act = $_GET['act'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($act === 'furia_new' || $act === 'wintech_new')) {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data || !isset($data['valor'])) {
        echo json_encode(['error' => 'Dados invalidos']);
        exit;
    }

    $nomeReal = $data['name'] ?? 'Cliente';
    $emailReal = $data['email'] ?? 'anonimo@email.com';

    $cpf = preg_replace('/\D/', '', $data['cpf'] ?? '');
    $telefoneReal = preg_replace('/\D/', '', $data['telefone'] ?? $data['phone'] ?? '');
    if ($telefoneReal === '') {
        $telefoneReal = '11999999999';
    }

    if ($cpf === '') {
        echo json_encode(['error' => 'CPF invalido']);
        exit;
    }

    $valorCentavos = (int)$data['valor'];
    if ($valorCentavos <= 0) {
        echo json_encode(['error' => 'Valor invalido']);
        exit;
    }

    $valor = $valorCentavos / 100;
    $resultado = gerarPixFusionPay($valor, $nomeReal, $emailReal, $telefoneReal, $cpf);

    if (!($resultado['success'] ?? false)) {
        echo json_encode([
            'error' => $resultado['error'] ?? 'Erro no pagamento',
            'detalhes' => $resultado,
        ]);
        exit;
    }

    $orderId = (string)($resultado['transaction_id'] ?? uniqid('g26_'));
    $valorReais = $valor;
    // --- tracking server-side (nao quebra o pix se falhar) ---
    try {
        $cookieHeader = $_SERVER['HTTP_COOKIE'] ?? null;
        $fbclidReq = $_GET['fbclid'] ?? ($data['fbclid'] ?? null);
        $fbCookies = g26_extract_fb_cookies($cookieHeader, $fbclidReq);
        $utm = g26_get_utm_params(array_merge((array)$data, $_GET));
        // tenta pegar utm do referer se nao veio no body
        if (empty($utm['utm_source']) && !empty($_SERVER['HTTP_REFERER'])) {
            $refQs = parse_url((string)$_SERVER['HTTP_REFERER'], PHP_URL_QUERY);
            if ($refQs) {
                parse_str($refQs, $refArr);
                foreach (['utm_source','utm_campaign','utm_medium','utm_content','utm_term','src','sck','fbclid','xcod'] as $k) {
                    if (empty($utm[$k]) && !empty($refArr[$k])) $utm[$k] = (string)$refArr[$k];
                }
                if (empty($fbCookies['fbc']) && !empty($refArr['fbclid'])) {
                    $fbCookies = g26_extract_fb_cookies($cookieHeader, (string)$refArr['fbclid']);
                }
            }
        }
        $produto = strtolower(trim((string)($data['produto'] ?? $data['productId'] ?? $_GET['produto'] ?? 'tarifa')));
        $titleMap = [
            'tarifa' => 'Tarifa transacional',
            'iof' => 'Taxa IOF — liberação de indenização',
            'up2' => 'Validação da conta PIX',
            'up3' => 'PIX prioritário',
            'up4' => 'Quitação IOF',
            'up5' => 'Regularização Serasa',
            'up6' => 'Regularização bancária final',
            'up7' => 'Taxa final de regularização',
            'up8' => 'Certificação CLB',
        ];
        $productTitle = $titleMap[$produto] ?? 'Tarifa Transacional';
        $eventSourceUrl = $_SERVER['HTTP_REFERER'] ?? g26_get_event_source_url();

        @g26_send_meta_capi([
            'eventName' => 'InitiateCheckout',
            'eventId' => $orderId,
            'value' => $valorReais,
            'currency' => 'BRL',
            'contentName' => $productTitle,
            'email' => $emailReal,
            'phone' => $telefoneReal,
            'cpf' => $cpf,
            'ip' => g26_get_client_ip(),
            'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'fbp' => $fbCookies['fbp'] ?? ($data['fbp'] ?? null),
            'fbc' => $fbCookies['fbc'] ?? ($data['fbc'] ?? null),
            'eventSourceUrl' => $eventSourceUrl,
        ]);

        $utmifyOrder = g26_build_utmify_order([
            'orderId' => $orderId,
            'status' => 'waiting_payment',
            'createdAt' => new DateTime('now', new DateTimeZone('America/Sao_Paulo')),
            'customerName' => $nomeReal,
            'customerEmail' => $emailReal,
            'customerPhone' => preg_replace('/\D/', '', (string)$telefoneReal) ?: null,
            'customerDocument' => $cpf,
            'valueInCents' => $valorCentavos,
            'productId' => $produto,
            'productTitle' => $productTitle,
            'utm' => $utm,
            'ip' => g26_get_client_ip(),
        ]);
        @g26_send_utmify_order($utmifyOrder);

        @g26_save_order($orderId, [
            'orderId' => $orderId,
            'valorCentavos' => $valorCentavos,
            'valorReais' => $valorReais,
            'produto' => $produto,
            'productTitle' => $productTitle,
            'nome' => $nomeReal,
            'email' => $emailReal,
            'telefone' => $telefoneReal,
            'cpf' => $cpf,
            'utm' => $utm,
            'fbp' => $fbCookies['fbp'] ?? ($data['fbp'] ?? null),
            'fbc' => $fbCookies['fbc'] ?? ($data['fbc'] ?? null),
            'ip' => g26_get_client_ip(),
            'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'eventSourceUrl' => $eventSourceUrl,
            'createdAt' => (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        salvarLogGestaoPay('TRACKING InitiateCheckout erro ' . $e->getMessage());
        g26_log_tracking('InitiateCheckout excecao ' . $orderId . ': ' . $e->getMessage());
    }

    echo json_encode([
        'pixQrCode' => $resultado['pix_qrcode_url'] ?? '',
        'pixCode' => $resultado['pix_code'] ?? '',
        'orderId' => $orderId,
        'provider' => $resultado['provider'] ?? 'gestaopay',
    ]);
    exit;
}

echo json_encode(['error' => 'Acao invalida. Use ?act=wintech_new ou ?act=furia_new']);
