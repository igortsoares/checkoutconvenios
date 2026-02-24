<?php
/**
 * ============================================================
 * ARQUIVO: /api/seed_planos.php
 * DESCRIÇÃO: Script de execução ÚNICA para popular a tabela
 *            `plans` do Supabase com os planos cadastrados
 *            na Iugu (produção).
 *
 * COMO USAR: Execute este arquivo UMA VEZ no servidor via
 *            navegador ou linha de comando:
 *            $ php api/seed_planos.php
 *
 * ATENÇÃO: Após executar com sucesso, remova ou proteja
 *          este arquivo do acesso público.
 * ============================================================
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

// ─── 1. Buscar todos os planos da Iugu (produção) ────────────────────────────
$iugu_url = IUGU_BASE_URL . '/plans?api_token=' . IUGU_API_KEY . '&limit=100';

$ch = curl_init($iugu_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    CURLOPT_TIMEOUT        => 30,
]);
$iugu_response = curl_exec($ch);
$iugu_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($iugu_http_code !== 200 || !$iugu_response) {
    http_response_code(500);
    echo json_encode(['error' => 'Falha ao buscar planos da Iugu.', 'http_code' => $iugu_http_code]);
    exit;
}

$iugu_data = json_decode($iugu_response, true);
$iugu_plans = $iugu_data['items'] ?? [];

if (empty($iugu_plans)) {
    http_response_code(404);
    echo json_encode(['error' => 'Nenhum plano encontrado na Iugu.']);
    exit;
}

// ─── 2. Verificar a estrutura da tabela plans no Supabase ────────────────────
// Buscar colunas existentes para decidir se precisa adicionar iugu_plan_identifier e is_b2c
$check_url = SUPABASE_URL . '/rest/v1/plans?limit=1&select=*';
$check_response = supabase_request($check_url, SUPABASE_SERVICE_ROLE_KEY, 'GET');
$existing_plans = json_decode($check_response, true);

// ─── 3. Processar e inserir cada plano ───────────────────────────────────────
$results = [];
$inserted = 0;
$skipped  = 0;
$errors   = 0;

// Palavras-chave que identificam planos de convênio CONTER
$conter_keywords = ['conter', 'CONTER', 'Conter'];

foreach ($iugu_plans as $plan) {
    $plan_name       = $plan['name']       ?? '';
    $plan_identifier = $plan['identifier'] ?? '';
    $plan_iugu_id    = $plan['id']         ?? '';
    $price_cents     = $plan['prices'][0]['value_cents'] ?? 0;
    $price_brl       = round($price_cents / 100, 2);
    $interval        = $plan['interval']      ?? 1;
    $interval_type   = $plan['interval_type'] ?? 'months';
    $payable_with    = $plan['payable_with']  ?? ['credit_card', 'bank_slip', 'pix'];

    // Detectar se é plano de convênio CONTER
    $is_convenio = false;
    foreach ($conter_keywords as $kw) {
        if (stripos($plan_name, $kw) !== false) {
            $is_convenio = true;
            break;
        }
    }

    // Detectar tipo de plano (mensal ou semestral) pelo nome
    $billing_period = 'monthly';
    if (stripos($plan_name, 'semestral') !== false) {
        $billing_period = 'semiannual';
    }

    // Detectar tipo de cobertura (individual ou família)
    $coverage_type = 'individual';
    if (stripos($plan_name, 'famil') !== false) {
        $coverage_type = 'family';
    }

    // Montar payload para inserção no Supabase
    $plan_data = [
        'name'                   => $plan_name,
        'iugu_plan_identifier'   => $plan_identifier,
        'iugu_plan_id'           => $plan_iugu_id,
        'price'                  => $price_brl,
        'billing_period'         => $billing_period,
        'coverage_type'          => $coverage_type,
        'is_b2c'                 => !$is_convenio,
        'is_convenio'            => $is_convenio,
        'convenio_slug'          => $is_convenio ? 'conter' : null,
        'product_id'             => PRODUCT_ID_CLUBE,
        'interval'               => $interval,
        'interval_type'          => $interval_type,
        'payable_with'           => $payable_with,
        'is_active'              => true,
    ];

    // Verificar se o plano já existe no banco (pelo iugu_plan_identifier)
    $check_exists_url = SUPABASE_URL . '/rest/v1/plans?iugu_plan_identifier=eq.' . urlencode($plan_identifier) . '&select=id&limit=1';
    $check_exists_response = supabase_request($check_exists_url, SUPABASE_SERVICE_ROLE_KEY, 'GET');
    $existing = json_decode($check_exists_response, true);

    if (!empty($existing) && isset($existing[0]['id'])) {
        // Plano já existe — atualizar
        $update_url = SUPABASE_URL . '/rest/v1/plans?iugu_plan_identifier=eq.' . urlencode($plan_identifier);
        $update_response = supabase_request($update_url, SUPABASE_SERVICE_ROLE_KEY, 'PATCH', $plan_data);
        $results[] = [
            'action'     => 'updated',
            'plan'       => $plan_name,
            'identifier' => $plan_identifier,
            'price'      => 'R$ ' . number_format($price_brl, 2, ',', '.'),
            'is_convenio'=> $is_convenio,
        ];
        $skipped++;
    } else {
        // Plano não existe — inserir
        $insert_url = SUPABASE_URL . '/rest/v1/plans';
        $insert_response = supabase_request($insert_url, SUPABASE_SERVICE_ROLE_KEY, 'POST', $plan_data);
        $insert_data = json_decode($insert_response, true);

        if (isset($insert_data[0]['id']) || $insert_response === '' || $insert_response === null) {
            $results[] = [
                'action'     => 'inserted',
                'plan'       => $plan_name,
                'identifier' => $plan_identifier,
                'price'      => 'R$ ' . number_format($price_brl, 2, ',', '.'),
                'is_convenio'=> $is_convenio,
            ];
            $inserted++;
        } else {
            $results[] = [
                'action'     => 'error',
                'plan'       => $plan_name,
                'identifier' => $plan_identifier,
                'response'   => $insert_data,
            ];
            $errors++;
        }
    }
}

// ─── 4. Retornar resultado ────────────────────────────────────────────────────
echo json_encode([
    'status'   => $errors === 0 ? 'success' : 'partial',
    'summary'  => [
        'total_iugu_plans' => count($iugu_plans),
        'inserted'         => $inserted,
        'updated'          => $skipped,
        'errors'           => $errors,
    ],
    'plans'    => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
