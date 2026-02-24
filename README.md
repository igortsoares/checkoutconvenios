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
│   ├── config.php          # Configurações, credenciais e funções utilitárias
│   ├── verificar_cpf.php   # Etapa 1: Verifica CPF e detecta vínculo com empresa
│   ├── listar_planos.php   # Etapa 2: Lista planos corretos (convênio ou B2C)
│   ├── processar_assinatura.php  # Etapa 4: Orquestra todo o fluxo de pagamento
│   └── webhook_iugu.php    # Webhook: Recebe confirmações de boleto/PIX da Iugu
├── .env                    # Variáveis de ambiente (NÃO commitar)
├── .env.example            # Exemplo de variáveis de ambiente
└── .gitignore
```

## Configuração

1. Copie o `.env.example` para `.env` e preencha com os valores reais:
   ```bash
   cp .env.example .env
   ```

2. No `index.html`, substitua `SEU_ACCOUNT_ID_IUGU` pelo Account ID real da sua conta Iugu:
   ```html
   <script src="https://js.iugu.com/v2" data-iugu-account-id="SEU_ACCOUNT_ID_IUGU"></script>
   ```

3. Configure o webhook no painel da Iugu:
   - Acesse: Configurações → Webhooks
   - URL: `https://seudominio.com.br/api/webhook_iugu.php`
   - Adicione o token de segurança ao `.env` como `IUGU_WEBHOOK_TOKEN`

## Banco de Dados

Certifique-se de que a tabela `contract_plans` foi criada no banco:

```sql
CREATE TABLE backoffice_tks.contract_plans (
  contract_id uuid NOT NULL,
  plan_id     uuid NOT NULL,
  created_at  timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT contract_plans_pkey PRIMARY KEY (contract_id, plan_id),
  CONSTRAINT contract_plans_contract_id_fkey FOREIGN KEY (contract_id) REFERENCES backoffice_tks.contracts(id) ON DELETE CASCADE,
  CONSTRAINT contract_plans_plan_id_fkey FOREIGN KEY (plan_id) REFERENCES backoffice_tks.plans(id) ON DELETE CASCADE
);
```

Certifique-se também de que a tabela `plans` possui as colunas `iugu_plan_identifier` e `is_b2c`.

## Fluxo de Funcionamento

1. **Etapa 1 (CPF):** Usuário digita o CPF. O sistema verifica se é membro de um convênio.
2. **Etapa 2 (Plano):** Planos específicos do convênio (ou B2C) são carregados da Iugu.
3. **Etapa 3 (Dados):** Dados pessoais são confirmados ou preenchidos.
4. **Etapa 4 (Pagamento):** Usuário escolhe cartão, boleto ou PIX e finaliza.
5. **Pós-pagamento:** O sistema cria a assinatura no banco, libera o entitlement e sincroniza com a Alloyal.
