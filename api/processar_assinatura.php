<?php

/**
 * ============================================================
 * ARQUIVO: /api/processar_assinatura.php
 * MÉTODO:  POST
 * CONTENT-TYPE: application/json
 *
 * DESCRIÇÃO:
 *  Este é o coração do checkout. Recebe os dados do formulário
 *  e orquestra todo o fluxo de pagamento de ponta a ponta,
 *  SEM depender de ferramentas externas como N8N.
 *
 * PAYLOAD ESPERADO (JSON):
 *  {
 *    "cpf":                  "000.000.000-00",
 *    "full_name":            "Nome Completo",
 *    "email":                "email@exemplo.com",
 *    "phone":                "61999999999",
 *    "birth_date":           "1990-01-15",
 *    "iugu_plan_identifier": "plano_conter_mensal",
 *    "plan_id":              "uuid-do-plano-no-banco",
 *    "payment_method":       "credit_card" | "bank_slip" | "pix",
 *    "profile_id":           "uuid-do-perfil" (opcional, se já existe),
 *    "company_id":           "uuid-da-empresa" (opcional, se convênio),
 *
 *    // Apenas para credit_card:
 *    "card_token":           "token-gerado-pelo-iugu-js"
 *  }
 *
 * FLUXO INTERNO:
 *  1. Valida os dados recebidos
 *  2. Cria ou atualiza o perfil do usuário no banco (tabela profiles)
 *  3. Cria ou busca o cliente na Iugu
 *  4. Cria a assinatura na Iugu usando o iugu_plan_identifier
 *  5. Processa o pagamento (cartão, boleto ou PIX)
 *  6. Registra a assinatura no banco (tabela subscriptions)
 *  7. Se pagamento aprovado imediatamente (cartão):
 *     → Cria o entitlement no banco (tabela entitlements)
 *     → Sincroniza o usuário com a Alloyal (Clube de Vantagens)
 *  8. Se pagamento pendente (boleto/PIX):
 *     → Retorna a URL/QR Code para o frontend exibir
 *     → A liberação ocorrerá via webhook (/api/webhook_iugu.php)
 *
 * RETORNO:
 *  - success: bool
 *  - payment_status: "paid" | "pending" | "failed"
 *  - payment_url: string (para boleto/PIX)
 *  - message: string
 * ============================================================
 */

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use POST.']);
    exit;
}

// --- Leitura e decodificação do corpo da requisição ---
$rawBody = file_get_contents('php://input');
$body    = json_decode($rawBody ?? '', true);

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Corpo da requisição inválido. Envie JSON.']);
    exit;
}

// ============================================================
// PASSO 1: Validação dos dados obrigatórios
// ============================================================
$required = ['cpf', 'full_name', 'email', 'phone', 'birth_date', 'iugu_plan_identifier', 'plan_id', 'payment_method'];
foreach ($required as $field) {
    if (empty($body[$field])) {
        http_response_code(400);
        echo json_encode(['error' => "Campo obrigatório ausente: {$field}"]);
        exit;
    }
}

// Extração e limpeza dos dados
$cpfDigits          = onlyDigits($body['cpf']);
$fullName           = trim($body['full_name']);
$email              = trim($body['email']);
$phone              = onlyDigits($body['phone']);
$birthDate          = trim($body['birth_date']); // Formato: YYYY-MM-DD
$iuguPlanIdentifier = trim($body['iugu_plan_identifier']);
$planId             = trim($body['plan_id']);
$paymentMethod      = trim($body['payment_method']); // credit_card | bank_slip | pix
$profileId          = trim($body['profile_id'] ?? '');
$companyId          = trim($body['company_id'] ?? '');
$cardToken          = trim($body['card_token'] ?? ''); // Apenas para cartão

// Validação do método de pagamento
if (!in_array($paymentMethod, ['credit_card', 'bank_slip', 'pix'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Método de pagamento inválido. Use: credit_card, bank_slip ou pix.']);
    exit;
}

// Validação do token de cartão (obrigatório apenas para credit_card)
if ($paymentMethod === 'credit_card' && empty($cardToken)) {
    http_response_code(400);
    echo json_encode(['error' => 'Token do cartão (card_token) é obrigatório para pagamento com cartão.']);
    exit;
}

// ============================================================
// PASSO 2: Criar ou atualizar o perfil do usuário no banco
// Se o profile_id foi passado (usuário já existe), fazemos PATCH.
// Se não foi passado (usuário novo), fazemos INSERT.
// ============================================================
$profileData = [
    'full_name'      => $fullName,
    'email_customer' => $email,
    'phone'          => $phone,
    'birth_date'     => $birthDate,
    'cpf'            => $cpfDigits,
    'updated_at'     => nowIso(),
];

if ($profileId !== '') {
    // Usuário existente: atualiza os dados
    $profileRes = supabasePatch(
        "profiles?id=eq." . rawurlencode($profileId),
        $profileData
    );
} else {
    // Usuário novo: cria o perfil
    $profileData['id']         = generateUuid();
    $profileData['created_at'] = nowIso();
    $profileRes = supabasePost(
        "profiles",
        $profileData,
        ['Prefer: return=representation']
    );
    if ($profileRes['ok'] && !empty($profileRes['data'][0]['id'])) {
        $profileId = $profileRes['data'][0]['id'];
    }
}

if (!$profileRes['ok']) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao salvar perfil do usuário.', 'details' => $profileRes['data']]);
    exit;
}

// Se o profileId ainda não foi definido (caso de update), garante que temos o ID
if ($profileId === '' && !empty($profileData['id'])) {
    $profileId = $profileData['id'];
}

$phoneDigits = onlyDigits($body['phone']); // ex: 61999908491

$ddd = substr($phoneDigits, 0, 2);
$number = substr($phoneDigits, 2); // resto (deve ficar com 9 dígitos p/ celular)

if (strlen($ddd) !== 2 || strlen($number) !== 9) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Telefone inválido. Envie no padrão DDD + número (11 dígitos), ex: 61999908491.',
        'details' => ['ddd' => $ddd, 'number' => $number, 'len' => strlen($phoneDigits)]
    ]);
    exit;
}


// ============================================================
// PASSO 3: Criar ou buscar o cliente na Iugu
// A Iugu identifica clientes por e-mail. Tentamos criar;
// se já existir, a Iugu retorna o cliente existente.
// ============================================================
$iuguCustomerPayload = [
    'email'        => $email,
    'name'         => $fullName,
    'cpf_cnpj'     => $cpfDigits,
    'phone_prefix' => $ddd,
    'phone'        => $number,
];

$iuguCustomerRes = iuguCall('POST', 'customers', $iuguCustomerPayload);

if (!$iuguCustomerRes['ok'] || empty($iuguCustomerRes['data']['id'])) {
    http_response_code(502);
    echo json_encode([
        'error'   => 'Erro ao criar cliente na Iugu.',
        'details' => $iuguCustomerRes['data'],
    ]);
    exit;
}

$iuguCustomerId = $iuguCustomerRes['data']['id'];

// ============================================================
// PASSO 4: Criar a assinatura na Iugu
// Usamos o iugu_plan_identifier para vincular ao plano correto.
// A assinatura na Iugu é o contrato recorrente de cobrança.
// ============================================================
$iuguSubscriptionPayload = [
    'plan_identifier'  => $iuguPlanIdentifier,
    'customer_id'      => $iuguCustomerId,
    'payable_with'     => $paymentMethod,
    'only_on_charge_success' => false, // Cria a assinatura mesmo se a 1ª cobrança falhar
];

// Para cartão de crédito, adiciona o token do cartão
if ($paymentMethod === 'credit_card') {
    $iuguSubscriptionPayload['customer_payment_method_id'] = $cardToken;
}

$iuguSubscriptionRes = iuguCall('POST', 'subscriptions', $iuguSubscriptionPayload);

if (!$iuguSubscriptionRes['ok'] || empty($iuguSubscriptionRes['data']['id'])) {
    http_response_code(502);
    echo json_encode([
        'error'   => 'Erro ao criar assinatura na Iugu.',
        'details' => $iuguSubscriptionRes['data'],
    ]);
    exit;
}

$iuguSubscriptionId = $iuguSubscriptionRes['data']['id'];
$iuguSubscriptionData = $iuguSubscriptionRes['data'];

// ============================================================
// PASSO 5: Determinar o status do pagamento
// Para cartão: a Iugu retorna o status imediatamente.
// Para boleto/PIX: o status será "pending" e uma URL é gerada.
// ============================================================
$paymentStatus = 'pending'; // Padrão: pendente
$paymentUrl    = null;
$invoiceId     = null;

// A Iugu retorna a fatura recente dentro da assinatura
$recentInvoice = $iuguSubscriptionData['recent_invoices'][0] ?? null;

if ($recentInvoice) {
    $invoiceId     = $recentInvoice['id'] ?? null;
    $invoiceStatus = $recentInvoice['status'] ?? 'pending';

    if ($invoiceStatus === 'paid') {
        $paymentStatus = 'paid';
    } elseif (in_array($invoiceStatus, ['pending', 'in_analysis'])) {
        $paymentStatus = 'pending';
        // URL para boleto ou PIX
        $paymentUrl = $recentInvoice['secure_url'] ?? $iuguSubscriptionData['secure_url'] ?? null;
    } else {
        $paymentStatus = 'failed';
    }
} elseif ($paymentMethod === 'credit_card') {
    // Para cartão sem fatura retornada, verifica o status da assinatura
    $subStatus = $iuguSubscriptionData['active'] ?? false;
    $paymentStatus = $subStatus ? 'paid' : 'pending';
}

// ============================================================
// PASSO 6: Registrar a assinatura no nosso banco de dados
// Tabela: subscriptions
// Campos gravados:
//   - profile_id      → o usuário físico que assinou (FK → profiles)
//   - account_id      → a conta B2B/B2C vinculada (FK → accounts)
//   - iugu_customer_id → ID do cliente na Iugu (para consultas cruzadas)
//   - payment_method  → credit_card | bank_slip | pix
//   - updated_at      → data da última atualização de status
// Status inicial: "active" (cartão aprovado) ou "pending_payment"
// ============================================================
$dbSubscriptionStatus = ($paymentStatus === 'paid') ? 'active' : 'pending_payment';

// Busca o account_id da conta vinculada ao profile (B2C ou B2B via company)
$accountId = null;
if ($companyId !== '') {
    // Usuário de convênio: busca a conta B2B da empresa
    $accRes = supabaseGet(
        "accounts?company_id=eq." . rawurlencode($companyId) .
        "&type=eq.B2B&select=id&limit=1"
    );
    $accountId = $accRes['data'][0]['id'] ?? null;
} else {
    // Usuário B2C: busca a conta B2C vinculada ao profile
    $accRes = supabaseGet(
        "accounts?profile_id=eq." . rawurlencode($profileId) .
        "&type=eq.B2C&select=id&limit=1"
    );
    $accountId = $accRes['data'][0]['id'] ?? null;
}

$subscriptionRow = [
    'id'                   => generateUuid(),
    'profile_id'           => $profileId,       // Usuário físico (PRINCIPAL)
    'account_id'           => $accountId,       // Conta B2B ou B2C vinculada
    'plan_id'              => $planId,
    'status'               => $dbSubscriptionStatus,
    'iugu_subscription_id' => $iuguSubscriptionId,
    'iugu_customer_id'     => $iuguCustomerId,  // ID do cliente na Iugu
    'payment_method'       => $paymentMethod,   // credit_card | bank_slip | pix
    'created_at'           => nowIso(),
    'updated_at'           => nowIso(),
];

$subscriptionRes = supabasePost(
    'subscriptions',
    $subscriptionRow,
    ['Prefer: return=representation']
);

if (!$subscriptionRes['ok']) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Erro ao salvar assinatura na tabela subscriptions.',
        'details' => $subscriptionRes['data'] ?? $subscriptionRes,
        'debug' => [
            'account_id' => $accountId,
            'profile_id' => $profileId,
            'plan_id' => $planId
        ]
    ]);
    exit;
}

$subscriptionDbId = $subscriptionRes['data'][0]['id'] ?? null;
// ============================================================
// PASSO 7: Se pagamento aprovado → Liberar acesso (entitlement)
//          e sincronizar com a Alloyal
// ============================================================
if ($paymentStatus === 'paid') {
    liberarAcesso($profileId, $subscriptionDbId, $cpfDigits, $fullName);
}

// ============================================================
// RETORNO FINAL PARA O FRONTEND
// ============================================================
$response = [
    'success'        => ($paymentStatus !== 'failed'),
    'payment_status' => $paymentStatus,
    'message'        => match ($paymentStatus) {
        'paid'    => 'Pagamento aprovado! Seu acesso foi liberado.',
        'pending' => 'Aguardando confirmação do pagamento.',
        'failed'  => 'Pagamento recusado. Tente novamente.',
        default   => 'Status desconhecido.',
    },
    'subscription_id' => $subscriptionDbId,
];

// Para boleto/PIX, retorna a URL de pagamento
if ($paymentUrl !== null) {
    $response['payment_url'] = $paymentUrl;
}

echo json_encode($response);

// ============================================================
// FUNÇÃO: liberarAcesso
// Cria o entitlement no banco e sincroniza com a Alloyal.
// Chamada imediatamente após pagamento de cartão aprovado,
// ou pelo webhook após confirmação de boleto/PIX.
// ============================================================
function liberarAcesso(string $profileId, string $subscriptionId, string $cpf, string $fullName): void
{
    // --- Cria o entitlement no banco ---
    // O entitlement é a "prova" de que o usuário tem direito ao produto.
    // expires_at: 1 ano a partir de hoje (ajuste conforme o plano)
    $expiresAt = gmdate('Y-m-d\TH:i:s\Z', strtotime('+1 year'));

    $entitlementRow = [
        'id'          => generateUuid(),
        'profile_id'  => $profileId,       // Usuário físico (FK → profiles)
        'product_id'  => PRODUCT_ID_CLUBE, // Clube de Vantagens
        'source_type' => 'subscription',
        'source_id'   => $subscriptionId,
        'status'      => 'active',
        'expires_at'  => $expiresAt,
        'created_at'  => nowIso(),
        'updated_at'  => nowIso(),
    ];

    supabasePost('entitlements', $entitlementRow, ['Prefer: return=representation']);

    // --- Sincroniza com a Alloyal (Clube de Vantagens) ---
    // Cadastra o usuário na plataforma do fornecedor usando as
    // credenciais fixas da TKS Vantagens.
    alloyalSyncUser($cpf, $fullName);
}
