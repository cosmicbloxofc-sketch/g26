<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$libLocal = __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'consulta_receita_lib.php';
$libAtencao = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'atencao' . DIRECTORY_SEPARATOR . 'consulta_receita_lib.php';
if (is_file($libLocal)) {
    require_once $libLocal;
} elseif (is_file($libAtencao)) {
    require_once $libAtencao;
} else {
    echo json_encode(['sucesso' => false, 'erro' => 'Módulo de consulta indisponível.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$cpf = preg_replace('/\D/', '', (string)($_GET['cpf'] ?? $_POST['cpf'] ?? ''));
if (strlen($cpf) !== 11) {
    echo json_encode(['sucesso' => false, 'erro' => 'CPF inválido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$dados = consultarCpfReceita26($cpf);
$nome = trim((string)($dados['nome'] ?? ''));
$nasc = trim((string)($dados['data_nascimento'] ?? ''));
$mae = trim((string)($dados['nome_mae'] ?? ''));
$sexo = trim((string)($dados['sexo'] ?? ''));

$idade = '';
if ($nasc !== '') {
    $partes = preg_split('#[/\-.]#', $nasc);
    if (count($partes) === 3) {
        $dia = (int)$partes[0];
        $mes = (int)$partes[1];
        $ano = (int)$partes[2];
        if ($ano < 100) {
            $ano += $ano >= 30 ? 1900 : 2000;
        }
        if ($dia > 0 && $mes > 0 && $ano > 1900) {
            try {
                $dn = new DateTime(sprintf('%04d-%02d-%02d', $ano, $mes, $dia));
                $idade = (string)$dn->diff(new DateTime('today'))->y;
            } catch (Exception $e) {
                $idade = '';
            }
        }
    }
}

$cpfFmt = receita26FormatarCpf($cpf);
$ok = $nome !== '' && $nome !== 'Contribuinte';

echo json_encode([
    'sucesso' => $ok,
    'dados' => [
        'nome' => $nome !== '' ? $nome : 'Contribuinte',
        'cpf' => $cpfFmt,
        'data_nascimento' => $nasc,
        'nome_mae' => $mae !== '' ? $mae : 'Não Informado',
        'nome_pai' => '',
        'rg' => '',
        'orgao_expedidor' => '',
        'uf_rg' => '',
        'renda' => '',
        'sexo' => $sexo,
        'idade' => $idade,
    ],
    'erro' => $ok ? null : 'CPF não encontrado na base de consulta.',
], JSON_UNESCAPED_UNICODE);
