<?php
/**
 * ============================================================
 * ARQUIVO: /api/liberar_acesso.php
 * DESCRIÇÃO: Função compartilhada de liberação de acesso.
 *            Centraliza a lógica de criar entitlement e
 *            sincronizar com a Alloyal, evitando duplicação
 *            entre processar_assinatura.php, webhook_iugu.php
 *            e verificar_pendentes.php.
 *
 * USO: require __DIR__ . '/liberar_acesso.php';
 *      $resultado = liberarAcesso($profileId, $subscriptionId, $cpf, $fullName);
 * ============================================================
 */

/**
 * Libera o acesso de um usuário após confirmação de pagamento.
 *
 * Ações realizadas:
 *  1. Verifica se já existe entitlement ativo para evitar duplicatas
 *  2. Cria o entitlement no banco (tabela entitlements)
 *  3. Atualiza o status da assinatura para "active"
 *  4. Sincroniza o usuário com a Alloyal (Clube de Vantagens)
 *
 * @param string $profileId      UUID do perfil do usuário (FK → profiles)
 * @param string $subscriptionId UUID da assinatura no banco (FK → subscriptions)
 * @param string $cpf            CPF apenas com dígitos (para Alloyal)
 * @param string $fullName       Nome completo (para Alloyal)
 * @return array ['ok' => bool, 'skipped' => bool, 'alloyal' => array, 'error' => string|null]
 */
function liberarAcesso(string $profileId, string $subscriptionId, string $cpf, string $fullName): array
{
    // ─── 1. Idempotência: verifica se o entitlement já existe ────────────────
    // Evita criar duplicatas caso o webhook e o polling rodem ao mesmo tempo.
    $existingRes = supabaseGet(
        "entitlements?profile_id=eq." . rawurlencode($profileId) .
        "&source_id=eq." . rawurlencode($subscriptionId) .
        "&status=eq.active&select=id&limit=1"
    );

    if ($existingRes['ok'] && !empty($existingRes['data'][0]['id'])) {
        return [
            'ok'      => true,
            'skipped' => true,
            'reason'  => 'Entitlement já existia — acesso já estava liberado.',
        ];
    }

    // ─── 2. Atualizar status da assinatura para "active" ─────────────────────
    supabasePatch(
        "subscriptions?id=eq." . rawurlencode($subscriptionId),
        ['status' => 'active', 'updated_at' => nowIso()]
    );

    // ─── 3. Criar o entitlement ───────────────────────────────────────────────
    // expires_at: 1 ano a partir de hoje.
    // TODO: calcular com base no interval/interval_type do plano quando necessário.
    $expiresAt = gmdate('Y-m-d\TH:i:s\Z', strtotime('+1 year'));

    $entitlementRow = [
        'id'          => generateUuid(),
        'profile_id'  => $profileId,
        'product_id'  => PRODUCT_ID_CLUBE,
        'source_type' => 'subscription',
        'source_id'   => $subscriptionId,
        'status'      => 'active',
        'expires_at'  => $expiresAt,
        'created_at'  => nowIso(),
        'updated_at'  => nowIso(),
    ];

    $entitlementRes = supabasePost(
        'entitlements',
        $entitlementRow,
        ['Prefer: return=representation']
    );

    if (!$entitlementRes['ok']) {
        return [
            'ok'      => false,
            'skipped' => false,
            'error'   => 'Falha ao criar entitlement.',
            'details' => $entitlementRes['data'],
        ];
    }

    // ─── 4. Sincronizar com a Alloyal (Clube de Vantagens) ───────────────────
    $alloyalRes = alloyalSyncUser($cpf, $fullName);

    return [
        'ok'      => true,
        'skipped' => false,
        'alloyal' => [
            'ok'      => $alloyalRes['ok'],
            'http'    => $alloyalRes['http_code'],
        ],
    ];
}
