<?php
/**
 * ============================================================
 * ARQUIVO: /api/sinc_planos_iugu.php
 * DESCRIÇÃO: Sincroniza os planos da Iugu com a tabela `plans`
 *            no Supabase. É chamado por listar_planos.php a cada
 *            requisição, garantindo dados sempre atualizados.
 *
 * COLUNAS REAIS DA TABELA plans (backoffice_tks):
 *   id, name, type, price, iugu_plan_identifier, is_active, iugu_id_plan
 *
 * AÇÕES:
 *   1. Busca todos os planos da Iugu.
 *   2. Busca todos os planos do Supabase.
 *   3. INSERE planos novos da Iugu que não existem no banco.
 *   4. ATUALIZA nome e preço de planos existentes quando há diferença.
 *   5. DESATIVA planos no banco que não existem mais na Iugu.
 *   6. REATIVA planos que estavam inativos mas voltaram na Iugu.
 * ============================================================
 */

function sincronizarPlanosIugu(): array {

    // ─── 1. Buscar todos os planos da Iugu ───────────────────────────────────
    $iuguResult = iuguCall('GET', '/plans?limit=100');

    if (!$iuguResult['ok'] || empty($iuguResult['data']['items'])) {
        return [
            'ok'      => false,
            'error'   => 'Falha ao buscar planos da Iugu.',
            'details' => $iuguResult,
        ];
    }
    $iuguPlans = $iuguResult['data']['items'];

    // ─── 2. Buscar todos os planos do Supabase ───────────────────────────────
    // Seleciona APENAS as colunas que existem na tabela:
    // id, name, type, price, iugu_plan_identifier, is_active, iugu_id_plan
    $supabaseResult = supabaseGet(
        'plans?select=id,name,type,price,iugu_plan_identifier,is_active,iugu_id_plan&order=name.asc'
    );

    if (!$supabaseResult['ok']) {
        return [
            'ok'      => false,
            'error'   => 'Falha ao buscar planos do Supabase.',
            'details' => $supabaseResult,
        ];
    }
    $supabasePlans = $supabaseResult['data'] ?? [];

    // ─── 3. Montar mapas por identifier para comparação rápida ───────────────
    $iuguMap = [];
    foreach ($iuguPlans as $p) {
        $identifier = $p['identifier'] ?? '';
        if ($identifier !== '') {
            $iuguMap[$identifier] = $p;
        }
    }

    $supabaseMap = [];
    foreach ($supabasePlans as $p) {
        $identifier = $p['iugu_plan_identifier'] ?? '';
        if ($identifier !== '') {
            $supabaseMap[$identifier] = $p;
        }
    }

    $summary = [
        'inserted'    => 0,
        'updated'     => 0,
        'deactivated' => 0,
        'reactivated' => 0,
        'unchanged'   => 0,
        'errors'      => 0,
    ];
    $actions = [];

    // ─── 4. INSERIR e ATUALIZAR ───────────────────────────────────────────────
    foreach ($iuguMap as $identifier => $iuguPlan) {
        $iuguName   = $iuguPlan['name'] ?? '';
        $iuguId     = $iuguPlan['id']   ?? '';
        $priceCents = $iuguPlan['prices'][0]['value_cents'] ?? 0;
        $priceBrl   = round($priceCents / 100, 2);

        // Payload com APENAS as colunas que existem na tabela plans
        $payload = [
            'name'                 => $iuguName,
            'iugu_plan_identifier' => $identifier,
            'iugu_id_plan'         => $iuguId,
            'price'                => $priceBrl,
            'is_active'            => true,
        ];

        if (!isset($supabaseMap[$identifier])) {
            // 4.1 INSERIR: plano existe na Iugu mas não no banco
            $res = supabasePost('plans', $payload);
            if ($res['ok']) {
                $summary['inserted']++;
                $actions[] = ['action' => 'inserted', 'plan' => $iuguName, 'price' => $priceBrl];
            } else {
                $summary['errors']++;
                $actions[] = ['action' => 'error_insert', 'plan' => $iuguName, 'details' => $res['data']];
            }

        } else {
            // 4.2 ATUALIZAR ou REATIVAR: plano existe em ambos
            $sup = $supabaseMap[$identifier];

            $nameChanged  = ($sup['name'] !== $iuguName);
            // Tolerância de 1 centavo para evitar falsos positivos de float
            $priceChanged = (abs((float)$sup['price'] - $priceBrl) > 0.009);
            $wasInactive  = !$sup['is_active'];

            if ($nameChanged || $priceChanged || $wasInactive) {
                $res = supabasePatch(
                    'plans?iugu_plan_identifier=eq.' . rawurlencode($identifier),
                    $payload
                );
                if ($res['ok']) {
                    $summary['updated']++;
                    if ($wasInactive) $summary['reactivated']++;
                    $actions[] = [
                        'action'        => 'updated',
                        'plan'          => $iuguName,
                        'name_changed'  => $nameChanged,
                        'price_changed' => $priceChanged,
                        'reactivated'   => $wasInactive,
                        'old_name'      => $sup['name'],
                        'new_name'      => $iuguName,
                        'old_price'     => $sup['price'],
                        'new_price'     => $priceBrl,
                    ];
                } else {
                    $summary['errors']++;
                    $actions[] = ['action' => 'error_update', 'plan' => $iuguName, 'details' => $res['data']];
                }
            } else {
                $summary['unchanged']++;
            }
        }
    }

    // ─── 5. DESATIVAR planos removidos da Iugu ───────────────────────────────
    foreach ($supabaseMap as $identifier => $sup) {
        if (!isset($iuguMap[$identifier]) && $sup['is_active']) {
            $res = supabasePatch(
                'plans?iugu_plan_identifier=eq.' . rawurlencode($identifier),
                ['is_active' => false]
            );
            if ($res['ok']) {
                $summary['deactivated']++;
                $actions[] = ['action' => 'deactivated', 'plan' => $sup['name']];
            } else {
                $summary['errors']++;
                $actions[] = ['action' => 'error_deactivate', 'plan' => $sup['name'], 'details' => $res['data']];
            }
        }
    }

    return [
        'ok'      => true,
        'summary' => $summary,
        'actions' => $actions,
    ];
}
