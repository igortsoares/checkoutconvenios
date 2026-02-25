<?php
/**
 * ============================================================
 * ARQUIVO: /api/verificar_pendentes.php
 * EXECUÇÃO: Cron job (não deve ser chamado pelo browser em produção)
 *
 * DESCRIÇÃO:
 *  Verificador periódico ativo de pagamentos pendentes.
 *  Complementa o webhook_iugu.php: enquanto o webhook é passivo
 *  (aguarda a Iugu chamar), este script é ativo — ele consulta
 *  a Iugu periodicamente para garantir que nenhum pagamento
 *  confirmado fique sem liberar o acesso.
 *
 *  QUANDO USAR:
 *   - PIX: confirmado em minutos após o usuário pagar
 *   - Boleto: confirmado em 1 a 3 dias úteis após o pagamento
 *   - Cartão: raramente necessário (aprovação é imediata), mas
 *     cobre casos de falha temporária no processamento
 *
 *  FLUXO:
 *   1. Busca no banco todas as assinaturas com status "pending_payment"
 *      criadas nas últimas 72 horas (boleto) ou 30 minutos (PIX/cartão)
 *   2. Para cada assinatura, consulta o status na Iugu
 *   3. Se a assinatura ou a fatura mais recente estiver paga:
 *      → Chama liberarAcesso() para criar entitlement e sincronizar Alloyal
 *   4. Se a assinatura estiver cancelada/expirada na Iugu:
 *      → Atualiza o status no banco para "canceled"
 *   5. Gera um log detalhado da execução
 *
 *  CONFIGURAÇÃO DO CRON (no servidor):
 *   Rodar a cada 5 minutos:
 *   */5 * * * * php /caminho/para/api/verificar_pendentes.php >> /var/log/tks_polling.log 2>&1
 *
 *  SEGURANÇA:
 *   - Verificar se está sendo executado via CLI (não via HTTP)
 *   - Para execução via HTTP (debug), usar token de segurança
 * ============================================================
 */

require __DIR__ . '/config.php';
require __DIR__ . '/liberar_acesso.php';

// ─── Controle de execução ────────────────────────────────────────────────────
// Permite execução via CLI (cron) ou via HTTP com token de segurança (debug)
$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    // Via HTTP: exige token de segurança para evitar execução não autorizada
    $token = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
    $expectedToken = $_ENV['IUGU_WEBHOOK_TOKEN'] ?? '';

    if ($expectedToken === '' || $token !== $expectedToken) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Acesso negado. Token de segurança inválido.']);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
}

// ─── Configuração da janela de busca ─────────────────────────────────────────
// Busca assinaturas pendentes criadas nas últimas 72h (cobre boletos de 3 dias)
$windowHours   = 72;
$cutoffDateIso = gmdate('Y-m-d\TH:i:s\Z', strtotime("-{$windowHours} hours"));

$log = [
    'started_at'    => nowIso(),
    'window_hours'  => $windowHours,
    'cutoff'        => $cutoffDateIso,
    'total_found'   => 0,
    'processed'     => 0,
    'activated'     => 0,
    'canceled'      => 0,
    'still_pending' => 0,
    'errors'        => 0,
    'details'       => [],
];

// ─── PASSO 1: Buscar assinaturas pendentes no banco ──────────────────────────
$pendingRes = supabaseGet(
    "subscriptions" .
    "?status=eq.pending_payment" .
    "&created_at=gte." . rawurlencode($cutoffDateIso) .
    "&select=id,profile_id,iugu_subscription_id,payment_method,created_at" .
    "&order=created_at.asc" .
    "&limit=100"
);

if (!$pendingRes['ok']) {
    $log['error'] = 'Falha ao buscar assinaturas pendentes no banco.';
    $log['details'][] = $pendingRes['data'];
    outputLog($log, $isCli);
    exit(1);
}

$pendingSubscriptions = $pendingRes['data'] ?? [];
$log['total_found'] = count($pendingSubscriptions);

if (empty($pendingSubscriptions)) {
    $log['message'] = 'Nenhuma assinatura pendente encontrada na janela de ' . $windowHours . 'h.';
    outputLog($log, $isCli);
    exit(0);
}

// ─── PASSO 2: Processar cada assinatura pendente ─────────────────────────────
foreach ($pendingSubscriptions as $sub) {
    $subDbId            = $sub['id'];
    $profileId          = $sub['profile_id'];
    $iuguSubscriptionId = $sub['iugu_subscription_id'] ?? '';
    $paymentMethod      = $sub['payment_method'] ?? 'unknown';

    $log['processed']++;
    $entry = [
        'subscription_db_id'   => $subDbId,
        'iugu_subscription_id' => $iuguSubscriptionId,
        'payment_method'       => $paymentMethod,
        'created_at'           => $sub['created_at'],
        'action'               => null,
        'iugu_status'          => null,
        'error'                => null,
    ];

    // Pula se não tiver iugu_subscription_id
    if (empty($iuguSubscriptionId)) {
        $entry['action'] = 'skipped';
        $entry['error']  = 'iugu_subscription_id ausente no banco.';
        $log['errors']++;
        $log['details'][] = $entry;
        continue;
    }

    // ─── PASSO 3: Consultar o status da assinatura na Iugu ───────────────────
    $iuguRes = iuguCall('GET', '/subscriptions/' . rawurlencode($iuguSubscriptionId));

    if (!$iuguRes['ok']) {
        $entry['action'] = 'error';
        $entry['error']  = 'Falha ao consultar assinatura na Iugu. HTTP ' . $iuguRes['http_code'];
        $log['errors']++;
        $log['details'][] = $entry;
        continue;
    }

    $iuguSub    = $iuguRes['data'];
    $isActive   = $iuguSub['active'] ?? false;
    $iuguStatus = $iuguSub['status'] ?? 'unknown';

    // Verifica o status da fatura mais recente
    $recentInvoice       = $iuguSub['recent_invoices'][0] ?? null;
    $invoiceStatus       = $recentInvoice['status'] ?? null;
    $isPaid              = ($isActive === true) || ($invoiceStatus === 'paid');
    $isCanceledOrExpired = in_array($iuguStatus, ['expired', 'suspended', 'canceled'], true);

    $entry['iugu_status']          = $iuguStatus;
    $entry['iugu_active']          = $isActive;
    $entry['invoice_status']       = $invoiceStatus;

    // ─── PASSO 4a: Pagamento confirmado → liberar acesso ─────────────────────
    if ($isPaid) {
        // Busca os dados do perfil para passar para liberarAcesso()
        $profileRes = supabaseGet(
            "profiles?id=eq." . rawurlencode($profileId) .
            "&select=id,full_name,cpf&limit=1"
        );

        if (!$profileRes['ok'] || empty($profileRes['data'][0])) {
            $entry['action'] = 'error';
            $entry['error']  = 'Perfil não encontrado no banco.';
            $log['errors']++;
            $log['details'][] = $entry;
            continue;
        }

        $profile  = $profileRes['data'][0];
        $cpf      = $profile['cpf'] ?? '';
        $fullName = $profile['full_name'] ?? '';

        $liberarRes = liberarAcesso($profileId, $subDbId, $cpf, $fullName);

        if ($liberarRes['ok']) {
            $entry['action']  = $liberarRes['skipped'] ? 'already_active' : 'activated';
            $entry['alloyal'] = $liberarRes['alloyal'] ?? null;
            if (!$liberarRes['skipped']) {
                $log['activated']++;
            }
        } else {
            $entry['action'] = 'error';
            $entry['error']  = $liberarRes['error'] ?? 'Erro desconhecido ao liberar acesso.';
            $log['errors']++;
        }

    // ─── PASSO 4b: Assinatura cancelada/expirada na Iugu → atualizar banco ───
    } elseif ($isCanceledOrExpired) {
        supabasePatch(
            "subscriptions?id=eq." . rawurlencode($subDbId),
            ['status' => 'canceled', 'updated_at' => nowIso()]
        );
        $entry['action'] = 'canceled';
        $log['canceled']++;

    // ─── PASSO 4c: Ainda pendente → aguardar ─────────────────────────────────
    } else {
        $entry['action'] = 'still_pending';
        $log['still_pending']++;
    }

    $log['details'][] = $entry;
}

// ─── Finalização ─────────────────────────────────────────────────────────────
$log['finished_at'] = nowIso();

outputLog($log, $isCli);
exit(0);

// ─── Função auxiliar de saída ─────────────────────────────────────────────────
function outputLog(array $log, bool $isCli): void
{
    if ($isCli) {
        // Saída formatada para o log do cron
        $summary = sprintf(
            "[%s] TKS Polling | Encontradas: %d | Ativadas: %d | Canceladas: %d | Pendentes: %d | Erros: %d",
            $log['started_at'],
            $log['total_found'],
            $log['activated'],
            $log['canceled'],
            $log['still_pending'],
            $log['errors']
        );
        echo $summary . PHP_EOL;

        // Detalha apenas as ações relevantes (não "still_pending") para não poluir o log
        foreach ($log['details'] as $d) {
            if (in_array($d['action'], ['activated', 'canceled', 'error'], true)) {
                echo "  → [{$d['action']}] sub={$d['subscription_db_id']} iugu={$d['iugu_subscription_id']} method={$d['payment_method']}" . PHP_EOL;
                if (!empty($d['error'])) {
                    echo "    ERRO: {$d['error']}" . PHP_EOL;
                }
            }
        }
    } else {
        // Saída JSON completa para debug via HTTP
        echo json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
