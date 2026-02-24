<?php
/**
 * ============================================================
 * ARQUIVO: /api/verificar_cpf.php
 * MÉTODO:  GET
 * PARÂMETRO: ?cpf=000.000.000-00
 *
 * DESCRIÇÃO:
 *  Primeiro passo do checkout. Quando o usuário digita o CPF,
 *  o frontend chama este endpoint para descobrir:
 *  1. Se o CPF já existe no banco (tabela profiles)
 *  2. Se esse usuário tem vínculo ativo com alguma empresa parceira
 *     (tabela company_members → companies)
 *
 * ESTRUTURA DO BANCO:
 *  - profiles.id  é o mesmo valor que company_members.user_id
 *  - profiles.cpf armazena o CPF SEM máscara (apenas dígitos)
 *  - company_members.user_id aponta para profiles.id
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
 *  - CPF encontrado + vínculo ativo com empresa → plan_type = "convenio"
 *  - Qualquer outro caso (novo usuário ou sem vínculo) → plan_type = "b2c"
 * ============================================================
 */

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

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

// Remove tudo que não for dígito (pontos, traço, espaços)
$cpfDigits = onlyDigits($cpfRaw);
if (strlen($cpfDigits) !== 11) {
    http_response_code(400);
    echo json_encode(['error' => 'CPF inválido. Informe todos os 11 dígitos.']);
    exit;
}

// ============================================================
// VALIDAÇÃO MATEMÁTICA DO CPF
// Implementa o algoritmo oficial dos dois dígitos verificadores.
// Rejeita CPFs com todos os dígitos iguais (ex: 111.111.111-11)
// e CPFs com dígitos verificadores incorretos.
// Esta validação é uma segunda camada de segurança além do frontend.
// ============================================================
if (!validarCPF($cpfDigits)) {
    http_response_code(400);
    echo json_encode(['error' => 'CPF inválido. O número informado não é um CPF válido.']);
    exit;
}

// ============================================================
// PASSO 1: Buscar o perfil na tabela `profiles`
// O banco armazena o CPF SEM máscara (apenas dígitos).
// Buscamos também com máscara como fallback de segurança.
// ============================================================
$cpfMask  = formatCpf($cpfDigits);
$orFilter = "or=(cpf.eq." . rawurlencode($cpfDigits) . ",cpf.eq." . rawurlencode($cpfMask) . ")";

$profileRes = supabaseGet(
    "profiles?{$orFilter}" .
    "&select=id,full_name,cpf,email_customer,phone,birth_date" .
    "&limit=1"
);

if (!$profileRes['ok']) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao consultar o banco de dados.', 'details' => $profileRes['data']]);
    exit;
}

$profile = $profileRes['data'][0] ?? null;

// --- Usuário novo (CPF não encontrado no banco) ---
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
// PASSO 2: Verificar vínculo com empresa parceira
//
// ATENÇÃO — Estrutura real do banco:
//   company_members.user_id = profiles.id  (NÃO é auth_user_id)
//
// O embedding do Supabase (company:companies(...)) funciona
// quando existe uma FK de company_members.company_id → companies.id.
// ============================================================
$memberRes = supabaseGet(
    "company_members?user_id=eq." . rawurlencode($profileId) .
    "&status=eq.active" .
    "&select=company_id,companies(id,name)" .
    "&order=created_at.desc&limit=1"
);

$companyId   = null;
$companyName = null;

if ($memberRes['ok'] && !empty($memberRes['data'][0])) {
    $membership = $memberRes['data'][0];
    $companyId  = $membership['company_id'] ?? null;

    // O Supabase retorna o objeto aninhado com o nome da tabela referenciada
    // Pode vir como 'companies' (array) ou como objeto direto dependendo da versão
    $companyData = $membership['companies'] ?? null;
    if (is_array($companyData)) {
        // Se for array de objetos (embedding padrão do Supabase)
        $companyName = $companyData[0]['name'] ?? ($companyData['name'] ?? null);
    }
}

// ============================================================
// PASSO 3: Determinar o tipo de plano
// Convênio → usuário tem vínculo ativo com empresa parceira
// B2C      → qualquer outro caso
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
