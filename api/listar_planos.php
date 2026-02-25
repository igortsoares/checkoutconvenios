<?php
/**
 * ============================================================
 * ARQUIVO: /api/listar_planos.php
 * MÉTODO:  GET
 * PARÂMETROS:
 *   ?plan_type=convenio  → Retorna planos de convênio (ex: CONTER)
 *   ?plan_type=b2c       → Retorna planos B2C padrão
 *   ?company_id=uuid     → UUID da empresa (obrigatório para convênio)
 *
 * RELACIONAMENTO DO BANCO (cadeia completa):
 *   companies.id
 *     → accounts.company_id  (type = 'B2B')
 *     → contracts.account_id (status = 'active')
 *     → contract_plans.contract_id
 *     → plans.id             (via contract_plans.plan_id)
 *
 * NOTA TÉCNICA:
 *   Usamos chamadas separadas ao invés de embedding do Supabase
 *   para garantir compatibilidade e robustez com a estrutura real
 *   do banco, evitando problemas com nomes de FK no PostgREST.
 * ============================================================
 */

require __DIR__ . '/config.php';
require __DIR__ . '/sinc_planos_iugu.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode(["error" => "Método não permitido. Use GET."]);
    exit;
}

// --- SINCRONIZAÇÃO AUTOMÁTICA COM IUGU ---
// Garante que os planos no banco de dados local reflitam o estado atual da Iugu.
// Roda em background, não afeta o tempo de resposta se a Iugu estiver lenta,
// pois o script continua com os dados que já tem no banco.
$syncResult = sincronizarPlanosIugu();
// Você pode opcionalmente logar $syncResult para monitorar as sincronizações.
// file_put_contents('sync_log.txt', date('Y-m-d H:i:s') . " - " . json_encode($syncResult) . "\n", FILE_APPEND);

$planType  = strtolower(trim($_GET['plan_type'] ?? 'b2c'));
$companyId = trim($_GET['company_id'] ?? '');

$plans = [];

// ============================================================
// CAMINHO 1: Planos de Convênio
// Cadeia: company_id → accounts → contracts → contract_plans → plans
// ============================================================
if ($planType === 'convenio' && $companyId !== '') {

    // PASSO 1: Buscar o account B2B da empresa
    $accountRes = supabaseGet(
        "accounts?company_id=eq." . rawurlencode($companyId) .
        "&type=eq.B2B&select=id&limit=1"
    );

    if (!$accountRes['ok'] || empty($accountRes['data'][0])) {
        // Sem account B2B → fallback para B2C
        $planType = 'b2c';
    } else {
        $accountId = $accountRes['data'][0]['id'];

        // PASSO 2: Buscar contrato ativo
        $contractRes = supabaseGet(
            "contracts?account_id=eq." . rawurlencode($accountId) .
            "&status=eq.active&select=id&limit=1"
        );

        if (!$contractRes['ok'] || empty($contractRes['data'][0])) {
            // Sem contrato ativo → fallback para B2C
            $planType = 'b2c';
        } else {
            $contractId = $contractRes['data'][0]['id'];

            // PASSO 3: Buscar os plan_ids vinculados ao contrato
            $cpRes = supabaseGet(
                "contract_plans?contract_id=eq." . rawurlencode($contractId) .
                "&select=plan_id"
            );

            if ($cpRes['ok'] && !empty($cpRes['data'])) {
                // Monta a lista de IDs para buscar os planos de uma vez
                $planIds = array_column($cpRes['data'], 'plan_id');

                // PASSO 4: Buscar os detalhes de cada plano
                // Usa o filtro "in" do Supabase: ?id=in.(uuid1,uuid2,...)
                $idsStr   = implode(',', $planIds);
                $plansRes = supabaseGet(
                    "plans?id=in.(" . rawurlencode($idsStr) . ")" .
                    "&is_active=eq.true" .
                    "&select=id,name,price,iugu_plan_identifier,type" .
                    "&order=price.asc"
                );

                if ($plansRes['ok'] && !empty($plansRes['data'])) {
                    foreach ($plansRes['data'] as $plan) {
                        $priceFloat = (float)($plan['price'] ?? 0);
                        $plans[] = [
                            'id'                   => $plan['id'],
                            'name'                 => $plan['name'],
                            'price'                => $priceFloat,
                            'price_formatted'      => 'R$ ' . number_format($priceFloat, 2, ',', '.'),
                            'iugu_plan_identifier' => $plan['iugu_plan_identifier'],
                            'type'                 => $plan['type'],
                        ];
                    }
                }
            }

            // Se não encontrou planos de convênio → fallback para B2C
            if (empty($plans)) {
                $planType = 'b2c';
            }
        }
    }
}

// ============================================================
// CAMINHO 2: Planos B2C (padrão ou fallback)
// Busca todos os planos com type = 'B2C' e is_active = true
// ============================================================
if ($planType === 'b2c') {

    $b2cRes = supabaseGet(
        "plans?type=eq.B2C&is_active=eq.true" .
        "&select=id,name,price,iugu_plan_identifier,iugu_id_plan,type" .
        "&order=price.asc"
    );

    if ($b2cRes['ok'] && !empty($b2cRes['data'])) {
        foreach ($b2cRes['data'] as $plan) {
            $priceFloat = (float)($plan['price'] ?? 0);
            $plans[] = [
                'id'                   => $plan['id'],
                'name'                 => $plan['name'],
                'price'                => $priceFloat,
                'price_formatted'      => 'R$ ' . number_format($priceFloat, 2, ',', '.'),
                'iugu_plan_identifier' => $plan['iugu_plan_identifier'],
                'type'                 => $plan['type'],
            ];
        }
    }
}

// --- Retorno final ---
echo json_encode([
    'plan_type' => $planType,
    'total'     => count($plans),
    'plans'     => $plans,
]);
