<?php
/**
 * ============================================================
 * ARQUIVO: /api/processar_assinatura.php
 * MÉTODO:  POST
 * CONTENT-TYPE: application/json
 *
 * CORREÇÕES APLICADAS:
 * - Corrige ordem: define $iuguCustomerId ANTES de usar
 * - Para cartão: cria Payment Method no cliente (customers/{id}/payment_methods)
 * - Para cartão: cria assinatura com customer_payment_method_id correto
 * - Para cartão: se invoice veio pendente, chama /charge e reconsulta invoice
 * - Garante variáveis ($invoiceStatus, $paymentUrl etc.) sempre definidas
 * - Mantém fluxo atual de gravação no Supabase + liberarAcesso()
 * ============================================================
 */

require __DIR__ . '/config.php';
require __DIR__ . '/liberar_acesso.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use POST.']);
    exit;
}

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

$cpfDigits          = onlyDigits($body['cpf']);
$fullName           = trim($body['full_name']);
$email              = trim($body['email']);
$phone              = onlyDigits($body['phone']);
$birthDate          = trim($body['birth_date']);
$iuguPlanIdentifier = trim($body['iugu_plan_identifier']);
$planId             = trim($body['plan_id']);
$paymentMethod      = trim($body['payment_method']);
$profileId          = trim($body['profile_id'] ?? '');
$companyId          = trim($body['company_id'] ?? '');
$cardToken          = trim($body['card_token'] ?? '');

if (!in_array($paymentMethod, ['credit_card', 'bank_slip', 'pix'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Método de pagamento inválido. Use: credit_card, bank_slip ou pix.']);
    exit;
}

if ($paymentMethod === 'credit_card' && $cardToken === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Token do cartão (card_token) é obrigatório para pagamento com cartão.']);
    exit;
}

// ============================================================
// PASSO 2: Criar/atualizar perfil no Supabase
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
    $profileRes = supabasePatch(
        "profiles?id=eq." . rawurlencode($profileId),
        $profileData
    );
} else {
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

if ($profileId === '' && !empty($profileData['id'])) {
    $profileId = $profileData['id'];
}

// valida telefone p/ Iugu (DDD + 9 dígitos)
$phoneDigits = onlyDigits($body['phone']);
$ddd         = substr($phoneDigits, 0, 2);
$number      = substr($phoneDigits, 2);

if (strlen($ddd) !== 2 || strlen($number) !== 9) {
    http_response_code(400);
    echo json_encode([
        'error'   => 'Telefone inválido. Envie no padrão DDD + número (11 dígitos), ex: 61999908491.',
        'details' => ['ddd' => $ddd, 'number' => $number, 'len' => strlen($phoneDigits)]
    ]);
    exit;
}

// ============================================================
// PASSO 3: Criar cliente na Iugu e DEFINIR $iuguCustomerId
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
// PASSO 3.1: Para cartão -> criar payment method no cliente
// ============================================================
$customerPaymentMethodId = null;

if ($paymentMethod === 'credit_card') {
    $pmRes = iuguCall(
        'POST',
        "customers/{$iuguCustomerId}/payment_methods",
        [
            'description'    => 'Cartão principal',
            'token'          => $cardToken,  // token vindo do Iugu.js
            'set_as_default' => true
        ]
    );

    if (!$pmRes['ok'] || empty($pmRes['data']['id'])) {
        http_response_code(502);
        echo json_encode([
            'error'   => 'Erro ao criar forma de pagamento (cartão) na Iugu.',
            'details' => $pmRes['data'],
        ]);
        exit;
    }

    $customerPaymentMethodId = $pmRes['data']['id'];
}

// ============================================================
// PASSO 4: Criar assinatura na Iugu
// ============================================================
$iuguSubscriptionPayload = [
    'plan_identifier'         => $iuguPlanIdentifier,
    'customer_id'             => $iuguCustomerId,
    'payable_with'            => $paymentMethod,
    'only_on_charge_success'  => false,
];

if ($paymentMethod === 'credit_card') {
    // IMPORTANTE: aqui é o ID do payment_method criado no cliente
    $iuguSubscriptionPayload['customer_payment_method_id'] = $customerPaymentMethodId;
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

$iuguSubscriptionId   = $iuguSubscriptionRes['data']['id'];
$iuguSubscriptionData = $iuguSubscriptionRes['data'];

// ============================================================
// PASSO 5: Ler invoice recente e determinar pagamento
// (Cartão: se não pagar sozinho, força /charge e reconsulta)
// ============================================================
$paymentStatus = 'pending';
$paymentUrl    = null;
$invoiceId     = null;
$invoiceStatus = 'pending';

$recentInvoice = $iuguSubscriptionData['recent_invoices'][0] ?? null;

if ($recentInvoice) {
    $invoiceId     = $recentInvoice['id'] ?? null;
    $invoiceStatus = $recentInvoice['status'] ?? 'pending';

    // Para boleto/pix: já captura URL
    $paymentUrl = $recentInvoice['secure_url'] ?? $iuguSubscriptionData['secure_url'] ?? null;

    // ✅ Cartão: se veio invoice e não está paga, força cobrança
    if ($paymentMethod === 'credit_card' && !empty($invoiceId) && $invoiceStatus !== 'paid') {

        // OBS: alguns fluxos aceitam só invoice_id + pm_id,
        // mas aqui mantemos completo (igual seu exemplo)
        $chargeRes = iuguCall('POST', 'charge', [
            'customer_id'                 => $iuguCustomerId,
            'customer_payment_method_id'  => $customerPaymentMethodId,
            'invoice_id'                  => $invoiceId,
        ]);

        if (!$chargeRes['ok']) {
            $invoiceStatus = 'failed';
        } else {
            // Reconsulta fatura para pegar status real
            $invRes = iuguCall('GET', "invoices/{$invoiceId}", []);
            if ($invRes['ok'] && !empty($invRes['data']['status'])) {
                $invoiceStatus = $invRes['data']['status'];
            }
        }
    }

    if ($invoiceStatus === 'paid') {
        $paymentStatus = 'paid';
    } elseif (in_array($invoiceStatus, ['pending', 'in_analysis'], true)) {
        $paymentStatus = 'pending';
    } else {
        $paymentStatus = 'failed';
    }
} else {
    // Se não veio invoice (raro), decide pelo "active" da assinatura
    if ($paymentMethod === 'credit_card') {
        $paymentStatus = !empty($iuguSubscriptionData['active']) ? 'paid' : 'pending';
    } else {
        $paymentStatus = 'pending';
    }
}

// ============================================================
// PASSO 6: Registrar assinatura no Supabase (subscriptions)
// ============================================================
$dbSubscriptionStatus = ($paymentStatus === 'paid') ? 'active' : 'pending_payment';

$accountId = null;
if ($companyId !== '') {
    $accRes = supabaseGet(
        "accounts?company_id=eq." . rawurlencode($companyId) .
        "&type=eq.B2B&select=id&limit=1"
    );
    $accountId = $accRes['data'][0]['id'] ?? null;
} else {
    $accRes = supabaseGet(
        "accounts?profile_id=eq." . rawurlencode($profileId) .
        "&type=eq.B2C&select=id&limit=1"
    );
    $accountId = $accRes['data'][0]['id'] ?? null;
}

$subscriptionRow = [
    'id'                   => generateUuid(),
    'profile_id'           => $profileId,
    'account_id'           => $accountId,
    'plan_id'              => $planId,
    'status'               => $dbSubscriptionStatus,
    'iugu_subscription_id' => $iuguSubscriptionId,
    'iugu_customer_id'     => $iuguCustomerId,
    'payment_method'       => $paymentMethod,
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
        'error'   => 'Erro ao salvar assinatura na tabela subscriptions.',
        'details' => $subscriptionRes['data'] ?? $subscriptionRes,
        'debug'   => [
            'account_id' => $accountId,
            'profile_id' => $profileId,
            'plan_id'    => $planId,
            'iugu_customer_id' => $iuguCustomerId,
            'iugu_subscription_id' => $iuguSubscriptionId,
        ]
    ]);
    exit;
}

$subscriptionDbId = $subscriptionRes['data'][0]['id'] ?? null;

// ============================================================
// PASSO 7: Se pago -> liberar acesso
// ============================================================
if ($paymentStatus === 'paid') {
    liberarAcesso($profileId, $subscriptionDbId, $cpfDigits, $fullName);
}

// ============================================================
// RETORNO FINAL
// ============================================================
$response = [
    'success'          => ($paymentStatus !== 'failed'),
    'payment_status'   => $paymentStatus,
    'message'          => match ($paymentStatus) {
        'paid'    => 'Pagamento aprovado! Seu acesso foi liberado.',
        'pending' => 'Aguardando confirmação do pagamento.',
        'failed'  => 'Pagamento recusado. Tente novamente.',
        default   => 'Status desconhecido.',
    },
    'subscription_id'  => $subscriptionDbId,
];

// Para boleto/PIX, retorna URL (e também pode retornar em cartão se ficar pendente)
if (!empty($paymentUrl)) {
    $response['payment_url'] = $paymentUrl;
}

// (opcional) debug rápido em dev:
// $response['debug'] = [
//     'invoice_id' => $invoiceId,
//     'invoice_status' => $invoiceStatus,
//     'iugu_customer_id' => $iuguCustomerId,
//     'customer_payment_method_id' => $customerPaymentMethodId,
// ];

echo json_encode($response);