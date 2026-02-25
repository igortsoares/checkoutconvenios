<?php
/**
 * ============================================================
 * ARQUIVO: /api/webhook_iugu.php
 * MÉTODO:  POST
 *
 * DESCRIÇÃO:
 *  Este script é um "ouvinte" que a Iugu chama automaticamente
 *  quando um evento ocorre, como a confirmação de pagamento de
 *  um boleto ou PIX.
 *
 *  Você deve cadastrar a URL deste arquivo no painel da Iugu em:
 *  Configurações → Webhooks → URL de Notificação
 *  Exemplo: https://seudominio.com.br/api/webhook_iugu.php
 *
 * EVENTOS TRATADOS:
 *  - invoice.status_changed: Disparado quando o status de uma
 *    fatura muda. Verificamos se o novo status é "paid" para
 *    liberar o acesso do usuário.
 *
 * SEGURANÇA:
 *  - A Iugu envia um token de verificação no campo "token" do
 *    payload. Você deve configurar este token no painel da Iugu
 *    e adicioná-lo ao .env como IUGU_WEBHOOK_TOKEN.
 *
 * FLUXO:
 *  1. Recebe o evento da Iugu
 *  2. Valida o token de segurança
 *  3. Verifica se o evento é "invoice.status_changed" e status = "paid"
 *  4. Busca a assinatura no nosso banco pelo iugu_subscription_id
 *  5. Atualiza o status da assinatura para "active"
 *  6. Cria o entitlement (libera o acesso)
 *  7. Sincroniza o usuário com a Alloyal
 * ============================================================
 */

require __DIR__ . '/config.php';
require __DIR__ . '/liberar_acesso.php';

header('Content-Type: application/json; charset=utf-8');

// Aceita apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido.']);
    exit;
}

// --- Leitura do corpo da requisição ---
$rawBody = file_get_contents('php://input');
$event   = json_decode($rawBody ?? '', true);

if (!is_array($event)) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload inválido.']);
    exit;
}

// ============================================================
// SEGURANÇA: Validação do Token da Iugu
// O token enviado pela Iugu deve bater com o configurado no .env
// ============================================================
$webhookToken = $_ENV['IUGU_WEBHOOK_TOKEN'] ?? '';
if ($webhookToken !== '' && ($event['token'] ?? '') !== $webhookToken) {
    http_response_code(401);
    echo json_encode(['error' => 'Token de webhook inválido.']);
    exit;
}

// ============================================================
// PASSO 1: Verificar se o evento é de pagamento confirmado
// Só processamos eventos do tipo "invoice.status_changed"
// com o novo status igual a "paid".
// ============================================================
$eventType    = $event['event'] ?? '';
$invoiceData  = $event['data']['object'] ?? [];
$invoiceStatus = $invoiceData['status'] ?? '';

// Ignora eventos que não são de fatura paga
if ($eventType !== 'invoice.status_changed' || $invoiceStatus !== 'paid') {
    // Responde 200 para a Iugu não retentar o envio
    http_response_code(200);
    echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'Evento não relevante.']);
    exit;
}

// ============================================================
// PASSO 2: Extrair o ID da assinatura da Iugu a partir da fatura
// ============================================================
$iuguSubscriptionId = $invoiceData['subscription_id'] ?? null;

if (!$iuguSubscriptionId) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'Fatura sem subscription_id.']);
    exit;
}

// ============================================================
// PASSO 3: Buscar a assinatura no nosso banco de dados
// usando o iugu_subscription_id para encontrar o registro
// ============================================================
$subRes = supabaseGet(
    "subscriptions?iugu_subscription_id=eq." . rawurlencode($iuguSubscriptionId) .
    "&select=id,profile_id,account_id,status,plan_id" .
    "&limit=1"
);

if (!$subRes['ok'] || empty($subRes['data'][0])) {
    // Assinatura não encontrada no banco — pode ser de outro sistema
    http_response_code(200);
    echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'Assinatura não encontrada no banco.']);
    exit;
}

$subscription = $subRes['data'][0];
$subscriptionDbId = $subscription['id'];
$profileId        = $subscription['profile_id']; // Usuário físico
$currentStatus    = $subscription['status'];

// Se já está ativa, não precisa fazer nada (idempotência)
if ($currentStatus === 'active') {
    http_response_code(200);
    echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'Assinatura já estava ativa.']);
    exit;
}

// ============================================================
// PASSO 4: Atualizar o status da assinatura para "active"
// ============================================================
supabasePatch(
    "subscriptions?id=eq." . rawurlencode($subscriptionDbId),
    ['status' => 'active', 'updated_at' => nowIso()]
);

// ============================================================
// PASSO 5: Buscar os dados do usuário para liberar o acesso
// ============================================================
$profileRes = supabaseGet(
    "profiles?id=eq." . rawurlencode($profileId) .
    "&select=id,full_name,cpf&limit=1"
);

if (!$profileRes['ok'] || empty($profileRes['data'][0])) {
    http_response_code(200);
    echo json_encode(['ok' => false, 'error' => 'Perfil do usuário não encontrado.']);
    exit;
}

$profile  = $profileRes['data'][0];
$cpf      = $profile['cpf'] ?? '';
$fullName = $profile['full_name'] ?? '';

// ============================================================
// PASSO 6: Criar o entitlement e sincronizar com a Alloyal
// Usa a função centralizada em liberar_acesso.php
// ============================================================
$liberarRes = liberarAcesso($profileId, $subscriptionDbId, $cpf, $fullName);
$alloyalRes = ['ok' => $liberarRes['alloyal']['ok'] ?? false];

// ============================================================
// RETORNO: Responde 200 para a Iugu confirmar o recebimento
// ============================================================
http_response_code(200);
echo json_encode([
    'ok'              => true,
    'subscription_id' => $subscriptionDbId,
    'alloyal_synced'  => $liberarRes['alloyal']['ok'] ?? false,
    'skipped'         => $liberarRes['skipped'] ?? false,
]);
