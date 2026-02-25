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
 *  URL cadastrada no painel da Iugu:
 *  https://tksvantagens.com.br/convenios/api/webhook_iugu.php
 *
 * ATENÇÃO — FORMATO DO PAYLOAD DA IUGU:
 *  A Iugu envia os dados com Content-Type: application/x-www-form-urlencoded
 *  (NÃO é JSON). Os dados chegam via $_POST, não via php://input.
 *
 *  Estrutura do payload (campos planos, não aninhados):
 *   - event            → nome do evento (ex: "invoice.status_changed")
 *   - data[id]         → ID da fatura
 *   - data[status]     → status da fatura (ex: "paid")
 *   - data[subscription_id] → ID da assinatura na Iugu
 *   - authorization    → token de segurança configurado no gatilho
 *
 * FLUXO:
 *  1. Recebe o evento da Iugu via $_POST
 *  2. Valida o token de segurança (campo "authorization")
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

// ============================================================
// LEITURA DO PAYLOAD
// A Iugu envia application/x-www-form-urlencoded, não JSON.
// Os dados chegam via $_POST com chaves no formato data[campo].
// ============================================================
$event = $_POST;

// Fallback: tenta ler como JSON caso o Content-Type seja diferente
if (empty($event)) {
    $rawBody = file_get_contents('php://input');
    $decoded = json_decode($rawBody ?? '', true);
    if (is_array($decoded)) {
        $event = $decoded;
    }
}

if (empty($event)) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload vazio ou inválido.']);
    exit;
}

// ============================================================
// SEGURANÇA: Validação do Token de Autorização
// A Iugu envia o token no campo "authorization" do POST.
// ============================================================
$webhookToken = $_ENV['IUGU_WEBHOOK_TOKEN'] ?? '';
$receivedToken = $event['authorization'] ?? '';

if ($webhookToken !== '' && $receivedToken !== $webhookToken) {
    http_response_code(401);
    echo json_encode(['error' => 'Token de webhook inválido.', 'received' => $receivedToken]);
    exit;
}

// ============================================================
// PASSO 1: Verificar se o evento é de pagamento confirmado
// A Iugu envia os campos de data como data[campo] no form-urlencoded,
// que o PHP converte automaticamente para $event['data']['campo'].
// ============================================================
$eventType    = $event['event'] ?? '';
$invoiceData  = $event['data'] ?? [];
$invoiceStatus = $invoiceData['status'] ?? '';

// Ignora eventos que não são de fatura paga
if ($eventType !== 'invoice.status_changed' || $invoiceStatus !== 'paid') {
    http_response_code(200);
    echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'Evento não relevante.', 'event' => $eventType, 'status' => $invoiceStatus]);
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
    http_response_code(200);
    echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'Assinatura não encontrada no banco.', 'iugu_subscription_id' => $iuguSubscriptionId]);
    exit;
}

$subscription     = $subRes['data'][0];
$subscriptionDbId = $subscription['id'];
$profileId        = $subscription['profile_id'];
$currentStatus    = $subscription['status'];

// Se já está ativa, não precisa fazer nada (idempotência)
if ($currentStatus === 'active') {
    http_response_code(200);
    echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'Assinatura já estava ativa.']);
    exit;
}

// ============================================================
// PASSO 4: Buscar os dados do usuário para liberar o acesso
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
// PASSO 5: Liberar acesso (entitlement + Alloyal)
// Usa a função centralizada em liberar_acesso.php
// ============================================================
$liberarRes = liberarAcesso($profileId, $subscriptionDbId, $cpf, $fullName);

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
