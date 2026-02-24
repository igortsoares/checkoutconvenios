<?php
/**
 * ============================================================
 * ARQUIVO: /api/verificar_cpf.php
 * MÉTODO:  GET
 * PARÂMETRO: ?cpf=000.000.000-00
 *
 * DESCRIÇÃO:
 *  Este é o primeiro passo do checkout. Quando o usuário digita
 *  o CPF, o frontend chama este endpoint para descobrir:
 *  1. Se o CPF já existe no nosso banco de dados (tabela profiles)
 *  2. Se esse usuário tem vínculo com alguma empresa parceira
 *     (tabela company_members -> companies)
 *
 * RETORNO:
 *  - found: bool          → Se o CPF foi encontrado no banco
 *  - is_new_user: bool    → Se é um usuário novo (não encontrado)
 *  - profile_id: string   → UUID do perfil (se encontrado)
 *  - full_name: string    → Nome completo (se encontrado)
 *  - company_id: string   → UUID da empresa parceira (se houver vínculo)
 *  - company_name: string → Nome da empresa parceira (se houver vínculo)
 *  - plan_type: string    → "convenio" ou "b2c"
 *
 * LÓGICA:
 *  - Se CPF encontrado E tem vínculo com empresa → plan_type = "convenio"
 *  - Qualquer outro caso (novo usuário ou sem vínculo) → plan_type = "b2c"
 * ============================================================
 */

require __DIR__ . '/config.php';

// Define o cabeçalho de resposta como JSON
header('Content-Type: application/json; charset=utf-8');

// Aceita apenas requisições GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use GET.']);
    exit;
}

// --- Validação do parâmetro CPF ---
$cpfRaw = trim($_GET['cpf'] ?? '');
if ($cpfRaw === '') {
    http_response_code(400);
    echo json_encode(['error' => "Parâmetro 'cpf' é obrigatório."]);
    exit;
}

// Limpa o CPF, mantendo apenas os dígitos
$cpfDigits = onlyDigits($cpfRaw);
if (strlen($cpfDigits) !== 11) {
    http_response_code(400);
    echo json_encode(['error' => 'CPF inválido. Informe 11 dígitos.']);
    exit;
}

// Formata o CPF no padrão 000.000.000-00 para busca alternativa
$cpfMask = formatCpf($cpfDigits);

// ============================================================
// PASSO 1: Buscar o perfil na tabela `profiles`
// Buscamos tanto pelo CPF sem máscara quanto com máscara,
// pois o banco pode ter registros em qualquer formato.
// ============================================================
$orFilter = "or=(cpf.eq." . rawurlencode($cpfDigits) . ",cpf.eq." . rawurlencode($cpfMask) . ")";
$profileRes = supabaseGet("profiles?{$orFilter}&select=id,full_name,cpf,email_customer,phone,birth_date&limit=1");

if (!$profileRes['ok']) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao consultar o banco de dados.', 'details' => $profileRes['data']]);
    exit;
}

$profile = $profileRes['data'][0] ?? null;

// --- Usuário não encontrado no banco ---
// É um usuário novo. Retornamos plan_type = "b2c" e is_new_user = true.
// O frontend vai prosseguir normalmente para coleta de dados e planos B2C.
if (!$profile) {
    echo json_encode([
        'found'       => false,
        'is_new_user' => true,
        'plan_type'   => 'b2c',
        'message'     => 'CPF não encontrado. Usuário será cadastrado como novo.',
    ]);
    exit;
}

$profileId = $profile['id'];

// ============================================================
// PASSO 2: Verificar se o usuário tem vínculo com empresa parceira
// Consultamos a tabela `company_members` buscando um registro
// ativo para este usuário, trazendo também os dados da empresa.
// ============================================================
$memberRes = supabaseGet(
    "company_members?user_id=eq." . rawurlencode($profileId) .
    "&status=eq.active" .
    "&select=company_id,company:companies(id,name)" .
    "&order=created_at.desc&limit=1"
);

$companyId   = null;
$companyName = null;

if ($memberRes['ok'] && !empty($memberRes['data'][0])) {
    $membership  = $memberRes['data'][0];
    $companyId   = $membership['company_id'] ?? null;
    $companyName = $membership['company']['name'] ?? null;
}

// ============================================================
// PASSO 3: Determinar o tipo de plano
// Se o usuário tem vínculo com uma empresa → "convenio"
// Caso contrário → "b2c"
// ============================================================
$planType = ($companyId !== null) ? 'convenio' : 'b2c';

// --- Retorno final ---
echo json_encode([
    'found'        => true,
    'is_new_user'  => false,
    'profile_id'   => $profileId,
    'full_name'    => $profile['full_name'] ?? null,
    'cpf'          => $cpfDigits,
    'email'        => $profile['email_customer'] ?? null,
    'phone'        => $profile['phone'] ?? null,
    'birth_date'   => $profile['birth_date'] ?? null,
    'company_id'   => $companyId,
    'company_name' => $companyName,
    'plan_type'    => $planType, // "convenio" ou "b2c"
]);
