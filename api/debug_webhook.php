<?php
/**
 * ENDPOINT DE DIAGNÓSTICO — debug_webhook.php
 * Captura tudo que a Iugu envia e retorna como JSON para análise.
 * REMOVER após o diagnóstico.
 */

header('Content-Type: application/json; charset=utf-8');
http_response_code(200); // Sempre 200 para a Iugu não retentar

$rawBody = file_get_contents('php://input');

$output = [
    'method'         => $_SERVER['REQUEST_METHOD'] ?? '',
    'content_type'   => $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '',
    'all_headers'    => [],
    'post_fields'    => $_POST,
    'raw_body'       => $rawBody,
    'raw_body_json'  => json_decode($rawBody, true),
    'server_keys'    => [],
];

// Captura todos os headers HTTP
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') === 0 || $key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
        $output['all_headers'][$key] = $value;
    }
}

// Captura chaves relevantes do $_SERVER
$relevantKeys = ['REQUEST_METHOD', 'CONTENT_TYPE', 'CONTENT_LENGTH', 'HTTP_AUTHORIZATION', 'HTTP_X_IUGU_TOKEN', 'HTTP_TOKEN'];
foreach ($relevantKeys as $k) {
    if (isset($_SERVER[$k])) {
        $output['server_keys'][$k] = $_SERVER[$k];
    }
}

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
