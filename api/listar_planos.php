<?php
/**
 * ============================================================
 * ARQUIVO: /api/listar_planos.php
 * MÉTODO:  GET
 * PARÂMETROS:
 *   ?plan_type=convenio  → Retorna planos de convênio (ex: CONTER)
 *   ?plan_type=b2c       → Retorna planos B2C padrão
 *   ?company_id=uuid     → (Opcional) UUID da empresa para buscar
 *                          planos específicos via contract_plans
 *
 * DESCRIÇÃO:
 *  Segundo passo do checkout. Após a verificação do CPF, o
 *  frontend chama este endpoint para obter a lista de planos
 *  disponíveis para aquele usuário.
 *
 * LÓGICA:
 *  1. Se plan_type = "convenio" E company_id informado:
 *     → Busca os planos vinculados ao contrato da empresa
 *       na tabela `contract_plans` → `plans`
 *     → Filtra apenas planos com is_active = true
 *
 *  2. Se plan_type = "b2c" OU company_id não informado:
 *     → Busca na tabela `plans` todos os planos B2C ativos
 *       (onde company_id IS NULL ou is_b2c = true)
 *
 *  Em ambos os casos, o campo `iugu_plan_identifier` de cada
 *  plano é retornado — ele é o identificador usado na Iugu.
 *
 * RETORNO:
 *  - plan_type: string → "convenio" ou "b2c"
 *  - plans: array      → Lista de planos com id, nome, preço e
 *                        iugu_plan_identifier
 * ============================================================
 */

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido. Use GET.']);
    exit;
}

$planType  = trim($_GET['plan_type'] ?? 'b2c');
$companyId = trim($_GET['company_id'] ?? '');

$plans = [];

// ============================================================
// CAMINHO 1: Planos de Convênio
// Busca os planos específicos vinculados ao contrato da empresa.
// Fluxo: companies → contracts → contract_plans → plans
// ============================================================
if ($planType === 'convenio' && $companyId !== '') {

    // 1a. Busca o contrato ativo da empresa na tabela `contracts`
    $contractRes = supabaseGet(
        "contracts?company_id=eq." . rawurlencode($companyId) .
        "&status=eq.active" .
        "&select=id&limit=1"
    );

    if (!$contractRes['ok'] || empty($contractRes['data'][0])) {
        // Empresa não tem contrato ativo → cai para planos B2C
        $planType = 'b2c';
    } else {
        $contractId = $contractRes['data'][0]['id'];

        // 1b. Busca os planos vinculados a este contrato via `contract_plans`
        $contractPlansRes = supabaseGet(
            "contract_plans?contract_id=eq." . rawurlencode($contractId) .
            "&select=plan:plans(id,name,description,price_cents,billing_period,iugu_plan_identifier,is_active)"
        );

        if ($contractPlansRes['ok'] && !empty($contractPlansRes['data'])) {
            foreach ($contractPlansRes['data'] as $row) {
                $plan = $row['plan'] ?? null;
                // Filtra apenas planos ativos
                if ($plan && ($plan['is_active'] ?? false)) {
                    $plans[] = [
                        'id'                    => $plan['id'],
                        'name'                  => $plan['name'],
                        'description'           => $plan['description'] ?? '',
                        'price_cents'           => (int)($plan['price_cents'] ?? 0),
                        'price_formatted'       => 'R$ ' . number_format(($plan['price_cents'] ?? 0) / 100, 2, ',', '.'),
                        'billing_period'        => $plan['billing_period'] ?? 'monthly',
                        'iugu_plan_identifier'  => $plan['iugu_plan_identifier'],
                    ];
                }
            }
        }

        // Se não encontrou planos de convênio, cai para B2C como fallback
        if (empty($plans)) {
            $planType = 'b2c';
        }
    }
}

// ============================================================
// CAMINHO 2: Planos B2C (padrão)
// Busca todos os planos ativos que não são exclusivos de convênio.
// Um plano B2C é identificado por is_b2c = true na tabela plans.
// ============================================================
if ($planType === 'b2c') {

    $b2cPlansRes = supabaseGet(
        "plans?is_active=eq.true&is_b2c=eq.true" .
        "&select=id,name,description,price_cents,billing_period,iugu_plan_identifier" .
        "&order=price_cents.asc"
    );

    if ($b2cPlansRes['ok'] && !empty($b2cPlansRes['data'])) {
        foreach ($b2cPlansRes['data'] as $plan) {
            $plans[] = [
                'id'                    => $plan['id'],
                'name'                  => $plan['name'],
                'description'           => $plan['description'] ?? '',
                'price_cents'           => (int)($plan['price_cents'] ?? 0),
                'price_formatted'       => 'R$ ' . number_format(($plan['price_cents'] ?? 0) / 100, 2, ',', '.'),
                'billing_period'        => $plan['billing_period'] ?? 'monthly',
                'iugu_plan_identifier'  => $plan['iugu_plan_identifier'],
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
