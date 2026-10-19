<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../lib/tracking.php';

$raw = file_get_contents('php://input');
$dataLog = date('d/m/Y H:i:s');
@file_put_contents(
    __DIR__ . '/log_gestaopay_webhook.txt',
    '[' . $dataLog . '] ' . (string)$raw . PHP_EOL . PHP_EOL,
    FILE_APPEND | LOCK_EX
);

// Tenta decodificar — GestaoPay envia PascalCase {Id, Status, Amount}
$json = json_decode((string)$raw, true);
if (!is_array($json)) $json = [];

// Suporta payload aninhado data/Data
$root = $json['data'] ?? $json['Data'] ?? $json;
if (isset($json['data']) && is_array($json['data'])) $root = $json['data'];
elseif (isset($json['Data']) && is_array($json['Data'])) $root = $json['Data'];
else $root = $json;

// Normaliza campos (PascalCase / camel / snake)
$id = $root['Id'] ?? $root['id'] ?? $root['ID'] ?? $root['transactionId'] ?? $root['transaction_id'] ?? $root['externalId'] ?? $root['ExternalId'] ?? null;
$statusRaw = $root['Status'] ?? $root['status'] ?? $root['STATUS'] ?? '';
$status = strtoupper(trim((string)$statusRaw));
$amountRaw = $root['Amount'] ?? $root['amount'] ?? $root['AMOUNT'] ?? $root['value'] ?? null;
$paidAtRaw = $root['PaidAt'] ?? $root['paidAt'] ?? $root['paid_at'] ?? null;

$id = $id !== null ? (string)$id : null;

// Determina se é pago — GestaoPay: PAID . Tolera variações
$paidStatuses = ['PAID', 'APPROVED', 'COMPLETED', 'CONFIRMED', 'PAGO', 'PAGO_APROVADO'];
$isPaid = in_array($status, $paidStatuses, true);

// Fallback: GestaoPay às vezes envia PaidAt diferente de 0001-01-01 quando pago
if (!$isPaid && $paidAtRaw && $paidAtRaw !== '0001-01-01T00:00:00' && $paidAtRaw !== '0001-01-01 00:00:00' && $paidAtRaw !== null && $paidAtRaw !== '') {
    // se PaidAt preenchido e status nao é PENDING, considera pago
    if ($status !== 'PENDING' && $status !== 'CANCELED' && $status !== 'CANCELLED' && $status !== 'FAILED') {
        $isPaid = true;
    }
}

if ($id && $isPaid) {
    try {
        $saved = g26_load_order($id);
        // Valor: prioriza salvo (centavos) senão Amount do webhook (reais)
        $valorCentavos = null;
        $valorReais = null;
        $produto = 'tarifa';
        $productTitle = 'Tarifa Transacional';
        $nome = 'Cliente';
        $email = null;
        $telefone = null;
        $cpf = null;
        $utm = [];
        $fbp = null; $fbc = null;
        $ip = g26_get_client_ip();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $eventSourceUrl = $_SERVER['HTTP_REFERER'] ?? g26_get_event_source_url();

        if (is_array($saved)) {
            $valorCentavos = (int)($saved['valorCentavos'] ?? 0);
            $valorReais = isset($saved['valorReais']) ? (float)$saved['valorReais'] : ($valorCentavos / 100);
            $produto = (string)($saved['produto'] ?? $produto);
            $productTitle = (string)($saved['productTitle'] ?? $productTitle);
            $nome = (string)($saved['nome'] ?? $nome);
            $email = $saved['email'] ?? $email;
            $telefone = $saved['telefone'] ?? $telefone;
            $cpf = $saved['cpf'] ?? $cpf;
            $utm = is_array($saved['utm'] ?? null) ? $saved['utm'] : $utm;
            $fbp = $saved['fbp'] ?? $fbp;
            $fbc = $saved['fbc'] ?? $fbc;
            $ip = $saved['ip'] ?? $ip;
            $ua = $saved['userAgent'] ?? $ua;
            $eventSourceUrl = $saved['eventSourceUrl'] ?? $eventSourceUrl;
        }

        if ($valorCentavos === null || $valorCentavos <= 0) {
            if ($amountRaw !== null) {
                // Amount vem em reais (ex: 33.91) — converte pra centavos
                $valorReais = (float)$amountRaw;
                $valorCentavos = (int)round($valorReais * 100);
            } else {
                $valorCentavos = 3391;
                $valorReais = 33.91;
            }
        }
        if ($valorReais === null) $valorReais = $valorCentavos / 100;

        // Cookie atual (webhook nao tem _fbp do comprador, usa o salvo)
        $cookieHeader = $_SERVER['HTTP_COOKIE'] ?? null;
        $fbclidNow = $_GET['fbclid'] ?? null;
        $fbNow = g26_extract_fb_cookies($cookieHeader, $fbclidNow);
        if (!$fbp && !empty($fbNow['fbp'])) $fbp = $fbNow['fbp'];
        if (!$fbc && !empty($fbNow['fbc'])) $fbc = $fbNow['fbc'];

        // Merge UTM do webhook (referer/query) se salvo vazio
        if (empty($utm['utm_source'])) {
            $extraUtm = g26_get_utm_params($_GET);
            foreach ($extraUtm as $k => $v) if (!empty($v) && empty($utm[$k])) $utm[$k] = $v;
            if (!empty($_SERVER['HTTP_REFERER'])) {
                $refQs = parse_url((string)$_SERVER['HTTP_REFERER'], PHP_URL_QUERY);
                if ($refQs) {
                    parse_str($refQs, $refArr);
                    foreach (['utm_source','utm_campaign','utm_medium','utm_content','utm_term','src','sck','fbclid','xcod'] as $k) {
                        if (empty($utm[$k]) && !empty($refArr[$k])) $utm[$k] = (string)$refArr[$k];
                    }
                }
            }
        }

        @g26_send_meta_capi([
            'eventName' => 'Purchase',
            'eventId' => (string)$id,
            'value' => $valorReais,
            'currency' => 'BRL',
            'contentName' => $productTitle,
            'email' => $email,
            'phone' => $telefone,
            'cpf' => $cpf,
            'ip' => $ip,
            'userAgent' => $ua,
            'fbp' => $fbp,
            'fbc' => $fbc,
            'eventSourceUrl' => $eventSourceUrl,
        ]);

        $utmifyOrder = g26_build_utmify_order([
            'orderId' => (string)$id,
            'status' => 'paid',
            'createdAt' => isset($saved['createdAt']) ? $saved['createdAt'] : new DateTime('now', new DateTimeZone('America/Sao_Paulo')),
            'approvedDate' => new DateTime('now', new DateTimeZone('America/Sao_Paulo')),
            'customerName' => $nome,
            'customerEmail' => $email ?: (($cpf ? $cpf . '@naoinformado.com' : 'cliente@naoinformado.com')),
            'customerPhone' => $telefone ? preg_replace('/\D/', '', (string)$telefone) : null,
            'customerDocument' => $cpf,
            'valueInCents' => $valorCentavos,
            'productId' => $produto,
            'productTitle' => $productTitle,
            'utm' => $utm,
            'ip' => $ip,
        ]);
        @g26_send_utmify_order($utmifyOrder);

        // marca como pago no arquivo salvo
        if (is_array($saved)) {
            $saved['paidAt'] = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
            $saved['statusWebhook'] = $status;
            @g26_save_order((string)$id, $saved);
        }

        g26_log_tracking('WEBHOOK Purchase enviado ' . $id . ' status=' . $status . ' valor=' . $valorCentavos);
    } catch (Throwable $e) {
        @file_put_contents(__DIR__ . '/log_gestaopay.txt', '[' . date('d/m/Y H:i:s') . '] WEBHOOK tracking erro ' . $e->getMessage() . PHP_EOL, FILE_APPEND | LOCK_EX);
        g26_log_tracking('WEBHOOK excecao ' . ($id ?? 'sem-id') . ': ' . $e->getMessage());
    }
} else {
    if ($id) g26_log_tracking('WEBHOOK recebido ' . $id . ' status=' . $status . ' isPaid=' . ($isPaid ? '1' : '0'));
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
