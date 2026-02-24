# Checkout Convênios — TKS Vantagens

Formulário de checkout para assinaturas do Clube de Vantagens, com suporte a clientes B2C e clientes com convênio empresarial (ex: CONTER).

## Estrutura do Projeto

```
checkoutconvenios/
├── index.html              # Frontend: formulário de checkout (4 etapas)
├── css/
│   └── style.css           # Estilos customizados e variáveis de marca
├── js/
│   └── checkout.js         # Lógica do frontend (navegação, API calls, máscaras)
├── api/
│   ├── config.php          # Central de configurações e funções utilitárias
│   ├── verificar_cpf.php   # Etapa 1: Verifica CPF e detecta vínculo com empresa
│   ├── listar_planos.php   # Etapa 2: Lista planos corretos (convênio ou B2C)
│   ├── processar_assinatura.php  # Etapa 4: Orquestra todo o fluxo de pagamento
│   └── webhook_iugu.php    # Webhook: Recebe confirmações de boleto/PIX da Iugu
├── .env                    # Variáveis de ambiente (NÃO commitado — criar manualmente)
├── .env.example            # Modelo de variáveis de ambiente
└── .gitignore
```

## Configuração Local

### 1. Criar o arquivo `.env`

O arquivo `.env` **não é commitado** no repositório por segurança. Crie-o manualmente na raiz do projeto com o seguinte conteúdo:

```env
# SUPABASE
SUPABASE_URL=https://api.tksvantagens.com.br
SUPABASE_SERVICE_ROLE_KEY=<service_role_key>
SUPABASE_ANON_KEY=<anon_key>
SUPABASE_SCHEMA=backoffice_tks

# IUGU
IUGU_API_KEY=<iugu_api_key>
IUGU_BASE_URL=https://api.iugu.com/v1

# ALLOYAL
ALLOYAL_BASE_URL=https://api.lecupon.com/client/v2
ALLOYAL_BUSINESS_CODE=870
ALLOYAL_EMPLOYEE_EMAIL=api.tks@lecupon.com
ALLOYAL_EMPLOYEE_TOKEN=<token>

# PRODUTO
PRODUCT_ID_CLUBE=f5686b32-54bf-4759-bd97-934657e61301

# WEBHOOK IUGU (configure também no painel da Iugu)
IUGU_WEBHOOK_TOKEN=
```

### 2. Configurar o Account ID da Iugu no `index.html`

Substitua `SEU_ACCOUNT_ID_IUGU` pelo Account ID real da sua conta Iugu (Configurações → Conta no painel da Iugu):

```html
<script src="https://js.iugu.com/v2" data-iugu-account-id="SEU_ACCOUNT_ID_IUGU"></script>
```

### 3. Configurar o Webhook no painel da Iugu

- Acesse: **Configurações → Webhooks**
- URL: `https://seudominio.com.br/api/webhook_iugu.php`
- Adicione o token de segurança ao `.env` como `IUGU_WEBHOOK_TOKEN`

## Banco de Dados — Ajustes Necessários

### Criar a tabela `contract_plans`

Execute no SQL Editor do Supabase:

```sql
CREATE TABLE backoffice_tks.contract_plans (
  contract_id uuid NOT NULL,
  plan_id     uuid NOT NULL,
  created_at  timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT contract_plans_pkey PRIMARY KEY (contract_id, plan_id),
  CONSTRAINT contract_plans_contract_id_fkey FOREIGN KEY (contract_id)
    REFERENCES backoffice_tks.contracts(id) ON DELETE CASCADE,
  CONSTRAINT contract_plans_plan_id_fkey FOREIGN KEY (plan_id)
    REFERENCES backoffice_tks.plans(id) ON DELETE CASCADE
);
```

### Garantir colunas na tabela `plans`

A tabela `plans` precisa ter as colunas:
- `iugu_plan_identifier` (text) — identificador do plano na Iugu
- `is_b2c` (boolean, default true) — indica se o plano é para clientes B2C

## Fluxo de Funcionamento

1. **Etapa 1 (CPF):** Usuário digita o CPF. O sistema verifica se é membro de um convênio.
2. **Etapa 2 (Plano):** Planos específicos do convênio (ou B2C) são carregados da Iugu via banco.
3. **Etapa 3 (Dados):** Dados pessoais são confirmados ou preenchidos.
4. **Etapa 4 (Pagamento):** Usuário escolhe cartão, boleto ou PIX e finaliza.
5. **Pós-pagamento:** O sistema cria a assinatura no banco, libera o entitlement e sincroniza com a Alloyal.
