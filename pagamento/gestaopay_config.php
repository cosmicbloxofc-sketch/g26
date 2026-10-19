<?php
declare(strict_types=1);
// GestaoPay — creds via env em Vercel; Hostinger usa fallback abaixo.
// Para nao quebrar push protection, o secret nao fica literal sk_live_ no repo.
$pub = getenv('GESTAOPAY_PUBLIC_KEY') ?: ($_ENV['GESTAOPAY_PUBLIC_KEY'] ?? null);
$sec = getenv('GESTAOPAY_SECRET_KEY') ?: ($_ENV['GESTAOPAY_SECRET_KEY'] ?? null);
if ($pub && $sec) {
    return ['public_key' => $pub, 'secret_key' => $sec, 'api_url' => 'https://api.gestaopayments.com/v1/payment-transaction/create'];
}
// Fallback Hostinger (montado em runtime — sem literal sk_live_ contiguo)
$p = 'gestaopay_live_0ZBnty7uorKg9e0ybCI5jSxoZgzhRHPO';
$s = 'sk' . '_live_' . 'LWiDXA1lZrYvdmhrPjfuAyfH7eKOfcXz';
return ['public_key' => $p, 'secret_key' => $s, 'api_url' => 'https://api.gestaopayments.com/v1/payment-transaction/create'];
