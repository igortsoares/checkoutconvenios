<?php
/**
 * ============================================================
 * ARQUIVO: /api/sinc_planos_iugu.php
 * DESCRIÇÃO: Sincroniza os planos da Iugu com a tabela `plans`
 *            no Supabase. É chamado por outros scripts para
 *            garantir que os dados estejam sempre atualizados.
 *
 * AÇÕES:
 *   1. Busca todos os planos da Iugu.
 *   2. Busca todos os planos do Supabase.
 *   3. INSERE planos novos da Iugu que não existem no banco.
 *   4. ATUALIZA planos existentes no banco com dados da Iugu.
 *   5. DESATIVA planos no banco que não existem mais na Iugu.
 * ============================================================
 */

// Esta função será chamada por outros scripts, então não precisa de `require` aqui.
// O `config.php` já terá sido incluído pelo script chamador.

function sincronizarPlanosIugu(): array {
    // ─── 1. Buscar todos os planos da Iugu ───────────────────────────────────
    $iuguResult = iuguCall('GET', '/plans?limit=100');

    if (!$iuguResult['ok'] || empty($iuguResult['data']['items'])) {
        return ['ok' => false, 'error' => 'Falha ao buscar planos da Iugu.', 'details' => $iuguResult];
    }
    $iuguPlans = $iuguResult['data']['items'];

    // ─── 2. Buscar todos os planos do Supabase ───────────────────────────────
    $supabaseResult = supabaseGet('plans?select=id,iugu_plan_identifier,iugu_plan_id,name,price,is_active');
    if (!$supabaseResult['ok']) {
        return ['ok' => false, 'error' => 'Falha ao buscar planos do Supabase.', 'details' => $supabaseResult];
    }
    $supabasePlans = $supabaseResult['data'];

    // Mapeia os planos por identifier para acesso rápido
    $iuguMap = [];
    foreach ($iuguPlans as $p) {
        if (!empty($p['identifier'])) {
            $iuguMap[$p['identifier']] = $p;
        }
    }

    $supabaseMap = [];
    foreach ($supabasePlans as $p) {
        if (!empty($p['iugu_plan_identifier'])) {
            $supabaseMap[$p['iugu_plan_identifier']] = $p;
        }
    }

    $summary = [
        'inserted' => 0,
        'updated' => 0,
        'deactivated' => 0,
        'activated' => 0,
        'errors' => 0,
    ];
    $actions = [];

    // ─── 3. INSERIR E ATUALIZAR ───────────────────────────────────────────────
    foreach ($iuguMap as $identifier => $iuguPlan) {
        $plan_name       = $iuguPlan['name'] ?? '';
        $price_cents     = $iuguPlan['prices'][0]['value_cents'] ?? 0;
        $price_brl       = round($price_cents / 100, 2);

        $payload = [
            'name'                 => $plan_name,
            'iugu_plan_id'         => $iuguPlan['id'] ?? '',
            'iugu_plan_identifier' => $identifier,
            'price'                => $price_brl,
            'interval'             => $iuguPlan['interval'] ?? 1,
            'interval_type'        => $iuguPlan['interval_type'] ?? 'months',
            'is_active'            => true, // Garante que o plano esteja ativo se existe na Iugu
        ];

        if (!isset($supabaseMap[$identifier])) {
            // 3.1 INSERIR: Plano existe na Iugu mas não no Supabase
            $insertResult = supabasePost('plans', $payload);
            if ($insertResult['ok']) {
                $summary['inserted']++;
                $actions[] = ['action' => 'inserted', 'plan' => $plan_name];
            } else {
                $summary['errors']++;
                $actions[] = ['action' => 'error_insert', 'plan' => $plan_name, 'details' => $insertResult['data']];
            }
        } else {
            // 3.2 ATUALIZAR: Plano existe em ambos. Verificar se precisa de update.
            $supPlan = $supabaseMap[$identifier];
            $needsUpdate = false;
            if ($supPlan['name'] != $payload['name'] || (float)$supPlan['price'] != $payload['price'] || !$supPlan['is_active']) {
                $needsUpdate = true;
            }

            if ($needsUpdate) {
                $updateResult = supabasePatch('plans?iugu_plan_identifier=eq.' . urlencode($identifier), $payload);
                if ($updateResult['ok']) {
                    $summary['updated']++;
                    $actions[] = ['action' => 'updated', 'plan' => $plan_name];
                    if (!$supPlan['is_active']) $summary['activated']++;
                } else {
                    $summary['errors']++;
                    $actions[] = ['action' => 'error_update', 'plan' => $plan_name, 'details' => $updateResult['data']];
                }
            }
        }
    }

    // ─── 4. DESATIVAR ────────────────────────────────────────────────────────
    // Plano existe no Supabase mas foi removido da Iugu
    foreach ($supabaseMap as $identifier => $supPlan) {
        if (!isset($iuguMap[$identifier]) && $supPlan['is_active']) {
            $deactivateResult = supabasePatch('plans?iugu_plan_identifier=eq.' . urlencode($identifier), ['is_active' => false]);
            if ($deactivateResult['ok']) {
                $summary['deactivated']++;
                $actions[] = ['action' => 'deactivated', 'plan' => $supPlan['name']];
            } else {
                $summary['errors']++;
                $actions[] = ['action' => 'error_deactivate', 'plan' => $supPlan['name'], 'details' => $deactivateResult['data']];
            }
        }
    }

    return [
        'ok' => true,
        'summary' => $summary,
        'actions' => $actions
    ];
}
