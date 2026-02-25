<?php
/**
 * ============================================================
 * ARQUIVO: /api/debug_sinc.php
 * DESCRIÇÃO: Endpoint temporário de diagnóstico.
 *            Testa a chamada à Iugu e a sincronização de planos,
 *            retornando informações detalhadas sobre o que acontece.
 *
 * ATENÇÃO: Remover este arquivo após o diagnóstico!
 * ============================================================
 */

require __DIR__ . '/config.php';
require __DIR__ . '/sinc_planos_iugu.php';

header('Content-Type: application/json; charset=utf-8');

$resultado = [];

// ─── 1. Testar autenticação e resposta bruta da Iugu ─────────────────────────
$iuguResult = iuguCall('GET', '/plans?limit=100');

$resultado['iugu_http_code'] = $iuguResult['http_code'];
$resultado['iugu_ok']        = $iuguResult['ok'];
$resultado['iugu_error']     = $iuguResult['error'];

// Mostrar estrutura da resposta (sem expor dados sensíveis desnecessários)
if (isset($iuguResult['data']) && is_array($iuguResult['data'])) {
    $items = $iuguResult['data']['items'] ?? [];
    $resultado['iugu_total_plans'] = count($items);
    $resultado['iugu_plans_sample'] = array_map(function($p) {
        return [
            'id'         => $p['id'] ?? null,
            'name'       => $p['name'] ?? null,
            'identifier' => $p['identifier'] ?? null,
            'price_cents'=> $p['prices'][0]['value_cents'] ?? null,
        ];
    }, $items);
} else {
    $resultado['iugu_raw_response'] = $iuguResult['raw'];
}

// ─── 2. Testar leitura dos planos no Supabase ─────────────────────────────────
$supabaseResult = supabaseGet('plans?select=id,name,iugu_plan_identifier,price,is_active&order=name.asc');
$resultado['supabase_ok']    = $supabaseResult['ok'];
$resultado['supabase_http']  = $supabaseResult['http_code'];
$resultado['supabase_plans'] = $supabaseResult['data'];

// ─── 3. Rodar a sincronização e capturar o resultado ─────────────────────────
$syncResult = sincronizarPlanosIugu();
$resultado['sync_result'] = $syncResult;

// ─── 4. Buscar os planos do Supabase APÓS a sincronização ────────────────────
$afterSync = supabaseGet('plans?select=id,name,iugu_plan_identifier,price,is_active&order=name.asc');
$resultado['supabase_plans_after_sync'] = $afterSync['data'];

echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
