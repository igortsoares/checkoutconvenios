<?php
/**
 * ============================================================
 * ARQUIVO: /api/config.php
 * DESCRIÇÃO: Carrega as variáveis de ambiente e fornece funções
 *            utilitárias usadas por todos os scripts da API.
 *            Deve ser incluído no início de cada script PHP.
 * ============================================================
 */

// --- Carregamento do .env ---
// Lê o arquivo .env que está na raiz do projeto (um nível acima de /api/)
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Ignora comentários e linhas vazias
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

// --- Constantes de Configuração ---
// Supabase
define('SUPABASE_URL',              rtrim($_ENV['SUPABASE_URL'] ?? '', '/'));
define('SUPABASE_SERVICE_ROLE_KEY', $_ENV['SUPABASE_SERVICE_ROLE_KEY'] ?? '');
define('SUPABASE_ANON_KEY',         $_ENV['SUPABASE_ANON_KEY'] ?? '');
define('SUPABASE_SCHEMA',           $_ENV['SUPABASE_SCHEMA'] ?? 'backoffice_tks');

// Iugu
define('IUGU_API_KEY',   $_ENV['IUGU_API_KEY'] ?? '');
define('IUGU_BASE_URL',  rtrim($_ENV['IUGU_BASE_URL'] ?? 'https://api.iugu.com/v1', '/'));

// Alloyal / Lecupon
define('ALLOYAL_BASE_URL',        rtrim($_ENV['ALLOYAL_BASE_URL'] ?? 'https://api.lecupon.com/client/v2', '/'));
define('ALLOYAL_BUSINESS_CODE',   $_ENV['ALLOYAL_BUSINESS_CODE'] ?? '870');
define('ALLOYAL_EMPLOYEE_EMAIL',  $_ENV['ALLOYAL_EMPLOYEE_EMAIL'] ?? '');
define('ALLOYAL_EMPLOYEE_TOKEN',  $_ENV['ALLOYAL_EMPLOYEE_TOKEN'] ?? '');

// Produto
define('PRODUCT_ID_CLUBE', $_ENV['PRODUCT_ID_CLUBE'] ?? '');

// ============================================================
// FUNÇÕES UTILITÁRIAS
// ============================================================

/**
 * Remove todos os caracteres não numéricos de uma string.
 * Usado para limpar CPF, telefone, etc.
 */
function onlyDigits(string $v): string {
    return preg_replace('/\D+/', '', $v) ?? '';
}

/**
 * Formata um CPF de 11 dígitos para o padrão 000.000.000-00
 */
function formatCpf(string $digits): string {
    if (strlen($digits) !== 11) return $digits;
    return substr($digits, 0, 3) . '.' . substr($digits, 3, 3) . '.' . substr($digits, 6, 3) . '-' . substr($digits, 9, 2);
}

/**
 * Executa uma requisição HTTP via cURL.
 * Retorna um array com: ok (bool), http_code (int), data (array|string), error (string|null)
 */
function executeCurl(string $url, string $method, ?string $body, array $headers): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = null;
    if ($response !== false && $response !== '') {
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $decoded = $response; // Retorna como string se não for JSON
        }
    }

    return [
        'ok'        => ($httpCode >= 200 && $httpCode < 300),
        'http_code' => $httpCode,
        'data'      => $decoded,
        'error'     => $curlError ?: null,
        'raw'       => $response,
    ];
}

/**
 * Faz uma requisição GET ao Supabase usando a Service Role Key.
 * Ideal para leituras no backend que precisam ignorar as políticas de RLS.
 */
function supabaseGet(string $endpoint, array $extraHeaders = []): array {
    $url = SUPABASE_URL . '/rest/v1/' . ltrim($endpoint, '/');
    $headers = array_merge([
        'apikey: '         . SUPABASE_SERVICE_ROLE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
        'Accept-Profile: ' . SUPABASE_SCHEMA,
        'Content-Profile: ' . SUPABASE_SCHEMA,
        'Accept: application/json',
    ], $extraHeaders);
    return executeCurl($url, 'GET', null, $headers);
}

/**
 * Faz uma requisição POST ao Supabase (INSERT ou UPSERT).
 */
function supabasePost(string $endpoint, array $payload, array $extraHeaders = []): array {
    $url = SUPABASE_URL . '/rest/v1/' . ltrim($endpoint, '/');
    $headers = array_merge([
        'apikey: '         . SUPABASE_SERVICE_ROLE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
        'Accept-Profile: ' . SUPABASE_SCHEMA,
        'Content-Profile: ' . SUPABASE_SCHEMA,
        'Content-Type: application/json',
        'Accept: application/json',
    ], $extraHeaders);
    return executeCurl($url, 'POST', json_encode($payload), $headers);
}

/**
 * Faz uma requisição PATCH ao Supabase (UPDATE).
 */
function supabasePatch(string $endpoint, array $payload): array {
    $url = SUPABASE_URL . '/rest/v1/' . ltrim($endpoint, '/');
    $headers = [
        'apikey: '         . SUPABASE_SERVICE_ROLE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
        'Accept-Profile: ' . SUPABASE_SCHEMA,
        'Content-Profile: ' . SUPABASE_SCHEMA,
        'Content-Type: application/json',
        'Accept: application/json',
        'Prefer: return=representation',
    ];
    return executeCurl($url, 'PATCH', json_encode($payload), $headers);
}

/**
 * Faz uma chamada à API da Iugu.
 * Usa autenticação Basic com a API Key como usuário e senha em branco.
 */
function iuguCall(string $method, string $endpoint, array $payload = []): array {
    $url = IUGU_BASE_URL . '/' . ltrim($endpoint, '/');
    // A Iugu usa autenticação Basic: API_KEY como usuário, senha vazia
    $credentials = base64_encode(IUGU_API_KEY . ':');
    $headers = [
        'Authorization: Basic ' . $credentials,
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    $body = ($method !== 'GET' && !empty($payload)) ? json_encode($payload) : null;
    return executeCurl($url, $method, $body, $headers);
}

/**
 * Faz uma chamada à API da Alloyal/Lecupon para sincronizar um usuário.
 * Usa as credenciais fixas da TKS Vantagens.
 */
function alloyalSyncUser(string $cpf, string $fullName): array {
    $businessCode = ALLOYAL_BUSINESS_CODE;
    $url = ALLOYAL_BASE_URL . "/businesses/{$businessCode}/authorized_users/sync";
    $headers = [
        'X-ClientEmployee-Email: ' . ALLOYAL_EMPLOYEE_EMAIL,
        'X-ClientEmployee-Token: ' . ALLOYAL_EMPLOYEE_TOKEN,
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    $payload = [
        'authorized_users' => [
            [
                'cpf'    => onlyDigits($cpf),
                'name'   => $fullName,
                'active' => true,
            ]
        ]
    ];
    return executeCurl($url, 'POST', json_encode($payload), $headers);
}

/**
 * Gera um UUID v4 aleatório.
 * Usado para criar IDs únicos para registros no banco de dados.
 */
function generateUuid(): string {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

/**
 * Retorna a data/hora atual no formato ISO 8601 (UTC).
 * Padrão usado pelo Supabase/PostgreSQL.
 */
function nowIso(): string {
    return gmdate('Y-m-d\TH:i:s\Z');
}

// ============================================================
// FUNÇÕES DE VALIDAÇÃO DE DADOS
// Implementam as regras de negócio para os campos do formulário.
// Usadas como segunda camada de segurança no backend,
// complementando as validações do frontend (JS).
// ============================================================

/**
 * Valida o CPF usando o algoritmo oficial dos dois dígitos verificadores.
 *
 * Regras:
 *  - Deve ter exatamente 11 dígitos
 *  - Não pode ser uma sequência repetida (ex: 111.111.111-11)
 *  - Os dois dígitos verificadores devem ser matematicamente corretos
 *
 * @param string $cpf CPF apenas com dígitos (sem máscara)
 * @return bool
 */
function validarCPF(string $cpf): bool {
    // Deve ter exatamente 11 dígitos
    if (strlen($cpf) !== 11) return false;

    // Rejeita sequências repetidas (00000000000, 11111111111, etc.)
    if (preg_match('/^(\d)\1{10}$/', $cpf)) return false;

    // --- Cálculo do 1º dígito verificador ---
    $soma = 0;
    for ($i = 0; $i < 9; $i++) {
        $soma += (int)$cpf[$i] * (10 - $i);
    }
    $resto = ($soma * 10) % 11;
    if ($resto === 10 || $resto === 11) $resto = 0;
    if ($resto !== (int)$cpf[9]) return false;

    // --- Cálculo do 2º dígito verificador ---
    $soma = 0;
    for ($i = 0; $i < 10; $i++) {
        $soma += (int)$cpf[$i] * (11 - $i);
    }
    $resto = ($soma * 10) % 11;
    if ($resto === 10 || $resto === 11) $resto = 0;
    if ($resto !== (int)$cpf[10]) return false;

    return true;
}

/**
 * Valida o e-mail.
 *
 * Regras:
 *  - Deve conter o caractere @
 *  - Deve ter um domínio válido após o @ (ex: gmail.com)
 *  - Formato: usuario@dominio.extensao
 *
 * @param string $email
 * @return bool
 */
function validarEmail(string $email): bool {
    return filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Valida o telefone celular brasileiro.
 *
 * Regras:
 *  - Deve ter exatamente 11 dígitos (DDD + 9 + 8 dígitos)
 *  - DDD deve ser um número entre 11 e 99
 *  - O 3º dígito (após o DDD) deve ser 9 (celular)
 *
 * @param string $phone Telefone apenas com dígitos
 * @return array ['valid' => bool, 'message' => string]
 */
function validarTelefone(string $phone): array {
    $digits = preg_replace('/\D+/', '', $phone);

    if (strlen($digits) < 11) {
        return ['valid' => false, 'message' => 'Telefone inválido. Informe DDD + 9 dígitos (ex: 61 9 9618-7769).'];
    }
    if (strlen($digits) > 11) {
        return ['valid' => false, 'message' => 'Telefone inválido. Número muito longo.'];
    }

    $ddd  = (int)substr($digits, 0, 2);
    $nono = $digits[2]; // O 3º dígito deve ser 9 para celular

    if ($ddd < 11 || $ddd > 99) {
        return ['valid' => false, 'message' => 'DDD inválido. Informe um DDD entre 11 e 99.'];
    }
    if ($nono !== '9') {
        return ['valid' => false, 'message' => 'Número de celular inválido. O número deve começar com 9 após o DDD.'];
    }

    return ['valid' => true, 'message' => ''];
}

/**
 * Valida a data de nascimento.
 *
 * Regras:
 *  - Deve ser uma data válida
 *  - Não pode ser uma data futura
 *  - O usuário deve ter no mínimo 15 anos na data atual do cadastro
 *
 * @param string $birthDate Data no formato YYYY-MM-DD
 * @return array ['valid' => bool, 'message' => string]
 */
function validarDataNascimento(string $birthDate): array {
    if (empty($birthDate)) {
        return ['valid' => false, 'message' => 'Data de nascimento é obrigatória.'];
    }

    // Verifica se é uma data válida no formato YYYY-MM-DD
    $parts = explode('-', $birthDate);
    if (count($parts) !== 3 || !checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
        return ['valid' => false, 'message' => 'Data de nascimento inválida.'];
    }

    $nascimento = new DateTime($birthDate);
    $hoje       = new DateTime();

    // Não pode ser uma data futura
    if ($nascimento > $hoje) {
        return ['valid' => false, 'message' => 'A data de nascimento não pode ser uma data futura.'];
    }

    // Calcula a idade exata
    $idade = $hoje->diff($nascimento)->y;

    // Mínimo de 15 anos
    if ($idade < 15) {
        return ['valid' => false, 'message' => 'Você deve ter pelo menos 15 anos para se cadastrar.'];
    }

    return ['valid' => true, 'message' => ''];
}
