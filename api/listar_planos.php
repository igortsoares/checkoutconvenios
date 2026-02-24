<?php
/**
 * ============================================================
 * ARQUIVO: /api/listar_planos.php
 * MÉTODO:  GET
 * PARÂMETROS:
 *   ?plan_type=convenio  → Retorna planos de convênio (ex: CONTER)
 *   ?plan_type=b2c       → Retorna planos B2C padrão
 *   ?company_id=uuid     → UUID da empresa para buscar planos via convênio
 *
 * RELACIONAMENTO CORRETO DO BANCO:
 *   companies → accounts (via accounts.company_id)
 *             → contracts (via contracts.account_id)
 *             → contract_plans (via contract_plans.contract_id)
 *             → plans (via contract_plans.plan_id)
 *
 * LÓGICA:
 *  1. Se plan_type = "convenio" E company_id informado:
 *     a. Busca o account B2B da empresa em `accounts` (company_id = X, type = B2B)
 *     b. Busca o contrato ativo em `contracts` (account_id = account.id)
 *     c. Busca os planos vinculados em `contract_plans` → `plans`
 *     d. Se não encontrar, faz fallback para planos B2C
 *
 *  2. Se plan_type = "b2c" OU company_id não informado:
 *     → Busca na tabela `plans` todos os planos com type = 'B2C' e is_active = true
 *
 * RETORNO:
 *  - plan_type: string → "convenio" ou "b2c"
 *  - total: int        → Quantidade de planos retornados
 *  - plans: array      → Lista de planos com id, nome, preço e iugu_plan_identifier
 * ============================================================
 */

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use GET.']);
    exit;
}

$planType  = strtolower(trim($_GET['plan_type'] ?? 'b2c'));
$companyId = trim($_GET['company_id'] ?? '');

$plans = [];

// ============================================================
// CAMINHO 1: Planos de Convênio
// Fluxo correto: company_id → accounts → contracts → contract_plans → plans
// ============================================================
if ($planType === 'convenio' && $companyId !== '') {

    // PASSO 1a: Busca o account B2B da empresa na tabela `accounts`
    // A tabela accounts tem company_id para contas do tipo B2B
    $accountRes = supabaseGet(
        "accounts?company_id=eq." . rawurlencode($companyId) .
        "&type=eq.B2B" .
        "&select=id&limit=1"
    );

    if (!$accountRes['ok'] || empty($accountRes['data'][0])) {
        // Empresa não tem account B2B → cai para planos B2C
        $planType = 'b2c';
    } else {
        $accountId = $accountRes['data'][0]['id'];

        // PASSO 1b: Busca o contrato ativo em `contracts` usando o account_id
        $contractRes = supabaseGet(
            "contracts?account_id=eq." . rawurlencode($accountId) .
            "&status=eq.active" .
            "&select=id&limit=1"
        );

        if (!$contractRes['ok'] || empty($contractRes['data'][0])) {
            // Empresa não tem contrato ativo → cai para planos B2C
            $planType = 'b2c';
        } else {
            $contractId = $contractRes['data'][0]['id'];

            // PASSO 1c: Busca os planos vinculados ao contrato via `contract_plans`
            // Usa embedding do Supabase para trazer os dados do plano junto
            $contractPlansRes = supabaseGet(
                "contract_plans?contract_id=eq." . rawurlencode($contractId) .
                "&select=plan_id,plans(id,name,price,iugu_plan_identifier,iugu_id_plan,type,is_active)"
            );

            if ($contractPlansRes['ok'] && !empty($contractPlansRes['data'])) {
                foreach ($contractPlansRes['data'] as $row) {
                    $plan = $row['plans'] ?? null;
                    if ($plan && ($plan['is_active'] ?? false)) {
                        $priceFloat = (float)($plan['price'] ?? 0);
                        $plans[] = [
                            'id'                   => $plan['id'],
                            'name'                 => $plan['name'],
                            'price'                => $priceFloat,
                            'price_formatted'      => 'R$ ' . number_format($priceFloat, 2, ',', '.'),
                            'iugu_plan_identifier' => $plan['iugu_plan_identifier'],
                            'iugu_id_plan'         => $plan['iugu_id_plan'],
                            'type'                 => $plan['type'],
                        ];
                    }
                }
            }

            // Se não encontrou planos de convênio, faz fallback para B2C
            if (empty($plans)) {
                $planType = 'b2c';
            }
        }
    }
}

// ============================================================
// CAMINHO 2: Planos B2C (padrão)
// Busca todos os planos com type = 'B2C' e is_active = true
// ============================================================
if ($planType === 'b2c') {

    $b2cPlansRes = supabaseGet(
        "plans?type=eq.B2C&is_active=eq.true" .
        "&select=id,name,price,iugu_plan_identifier,iugu_id_plan,type" .
        "&order=price.asc"
    );

    if ($b2cPlansRes['ok'] && !empty($b2cPlansRes['data'])) {
        foreach ($b2cPlansRes['data'] as $plan) {
            $priceFloat = (float)($plan['price'] ?? 0);
            $plans[] = [
                'id'                   => $plan['id'],
                'name'                 => $plan['name'],
                'price'                => $priceFloat,
                'price_formatted'      => 'R$ ' . number_format($priceFloat, 2, ',', '.'),
                'iugu_plan_identifier' => $plan['iugu_plan_identifier'],
                'iugu_id_plan'         => $plan['iugu_id_plan'],
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
