<?php
declare(strict_types=1);

$qs = $_SERVER['QUERY_STRING'] ?? '';
$target = 'fila.html' . ($qs !== '' ? '?' . $qs : '');
header('Location: ' . $target, true, 302);
exit;
