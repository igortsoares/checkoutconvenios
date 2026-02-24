/**
 * ============================================================
 * CHECKOUT CONVÊNIOS - TKS VANTAGENS
 * Arquivo: js/checkout.js
 * Descrição: Lógica completa do formulário de checkout.
 *            Gerencia a navegação entre etapas, as chamadas
 *            de API e a tokenização do cartão via SDK da Iugu.
 * ============================================================
 */

// ============================================================
// ESTADO GLOBAL DO CHECKOUT
// Armazena todos os dados coletados ao longo das etapas.
// ============================================================
const state = {
    // Etapa 1: CPF
    cpf:         '',
    profileId:   null,   // UUID do perfil no banco (se já existir)
    companyId:   null,   // UUID da empresa parceira (se for convênio)
    companyName: null,
    planType:    'b2c',  // "convenio" ou "b2c"
    isNewUser:   true,

    // Etapa 2: Plano
    selectedPlan: null,  // Objeto com id, name, price_formatted, iugu_plan_identifier

    // Etapa 3: Dados Pessoais
    fullName:   '',
    email:      '',
    phone:      '',
    birthDate:  '',

    // Etapa 4: Pagamento
    paymentMethod: null, // "credit_card" | "bank_slip" | "pix"
};

// Etapa atual (1 a 4)
let currentStep = 1;

// ============================================================
// INICIALIZAÇÃO
// ============================================================
document.addEventListener('DOMContentLoaded', () => {
    // Máscara de CPF
    document.getElementById('input-cpf').addEventListener('input', maskCPF);

    // Máscara de Telefone
    document.getElementById('input-telefone').addEventListener('input', maskPhone);

    // Máscara do Número do Cartão
    document.getElementById('input-card-number').addEventListener('input', maskCardNumber);

    // Máscara da Validade do Cartão
    document.getElementById('input-card-expiry').addEventListener('input', maskCardExpiry);

    // Botão: Verificar CPF (Etapa 1)
    document.getElementById('btn-verificar-cpf').addEventListener('click', handleVerificarCpf);

    // Botão: Selecionar Plano (Etapa 2)
    document.getElementById('btn-selecionar-plano').addEventListener('click', () => goToStep(3));

    // Botão: Confirmar Dados (Etapa 3)
    document.getElementById('btn-confirmar-dados').addEventListener('click', handleConfirmarDados);

    // Botões de Método de Pagamento (Etapa 4)
    document.querySelectorAll('.payment-method-btn').forEach(btn => {
        btn.addEventListener('click', () => selectPaymentMethod(btn.dataset.method));
    });

    // Botão: Finalizar Assinatura (Etapa 4)
    document.getElementById('btn-finalizar').addEventListener('click', handleFinalizar);

    // Permite submeter com Enter no campo CPF
    document.getElementById('input-cpf').addEventListener('keydown', (e) => {
        if (e.key === 'Enter') handleVerificarCpf();
    });
});

// ============================================================
// NAVEGAÇÃO ENTRE ETAPAS
// ============================================================

/**
 * Navega para uma etapa específica do formulário.
 * Atualiza o indicador de progresso visual.
 */
function goToStep(step) {
    // Esconde o painel atual
    document.getElementById(`step-${currentStep}`)?.classList.add('hidden');

    // Mostra o novo painel
    const newPanel = document.getElementById(`step-${step}`);
    if (newPanel) {
        newPanel.classList.remove('hidden');
        currentStep = step;
        updateProgressIndicator(step);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

/**
 * Atualiza visualmente o indicador de progresso no topo.
 */
function updateProgressIndicator(activeStep) {
    for (let i = 1; i <= 4; i++) {
        const item = document.querySelector(`[data-step="${i}"]`);
        if (!item) continue;

        const circle = item.querySelector('.step-circle');
        const label  = item.querySelector('span');
        const line   = item.nextElementSibling; // A linha após o item

        if (i < activeStep) {
            // Etapa concluída
            circle.className = 'step-circle w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold border-2 completed';
            circle.innerHTML = '<i class="fas fa-check text-xs"></i>';
            label.className  = 'text-xs mt-1 text-green-600 font-semibold';
            if (line && line.classList.contains('step-line')) line.classList.add('active');
        } else if (i === activeStep) {
            // Etapa atual
            circle.className = 'step-circle w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold border-2 active';
            circle.textContent = i;
            label.className  = 'text-xs mt-1 text-tks-primary font-semibold';
        } else {
            // Etapa futura
            circle.className = 'step-circle w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold border-2 border-slate-200 bg-white text-slate-400';
            circle.textContent = i;
            label.className  = 'text-xs mt-1 text-slate-400';
        }
    }
}

// ============================================================
// ETAPA 1: VERIFICAÇÃO DO CPF
// ============================================================

/**
 * Chamado quando o usuário clica em "Continuar" na Etapa 1.
 * Verifica o CPF na API e determina se é convênio ou B2C.
 */
async function handleVerificarCpf() {
    const cpfVal = document.getElementById('input-cpf').value;
    if (cpfVal.replace(/\D/g, '').length < 11) {
        showError('Por favor, informe um CPF válido com 11 dígitos.');
        return;
    }

    setButtonLoading('btn-verificar-cpf', 'btn-verificar-text', 'btn-verificar-loader', 'btn-verificar-arrow', true);

    try {
        const res  = await fetch(`/api/verificar_cpf.php?cpf=${encodeURIComponent(cpfVal)}`);
        const data = await res.json();

        if (data.error) throw new Error(data.error);

        // Salva os dados no estado global
        state.cpf        = data.cpf || cpfVal.replace(/\D/g, '');
        state.profileId  = data.profile_id || null;
        state.companyId  = data.company_id || null;
        state.companyName = data.company_name || null;
        state.planType   = data.plan_type || 'b2c';
        state.isNewUser  = data.is_new_user || false;

        // Pré-preenche dados pessoais se o usuário já existe
        if (data.found && !data.is_new_user) {
            if (data.full_name)  document.getElementById('input-nome').value       = data.full_name;
            if (data.email)      document.getElementById('input-email').value      = data.email;
            if (data.phone)      document.getElementById('input-telefone').value   = formatPhone(data.phone);
            if (data.birth_date) document.getElementById('input-nascimento').value = data.birth_date;
        }

        // Exibe o badge de convênio se aplicável
        if (state.planType === 'convenio' && state.companyName) {
            const badge = document.getElementById('badge-convenio');
            document.getElementById('badge-company-name').textContent = state.companyName;
            badge.classList.remove('hidden');
            badge.classList.add('flex');
        }

        // Avança para a etapa de seleção de planos e carrega os planos
        goToStep(2);
        await carregarPlanos();

    } catch (err) {
        showError('Erro ao verificar CPF. Tente novamente.');
        console.error(err);
    } finally {
        setButtonLoading('btn-verificar-cpf', 'btn-verificar-text', 'btn-verificar-loader', 'btn-verificar-arrow', false);
    }
}

// ============================================================
// ETAPA 2: CARREGAMENTO E SELEÇÃO DE PLANOS
// ============================================================

/**
 * Busca os planos disponíveis na API conforme o tipo de usuário.
 */
async function carregarPlanos() {
    const container = document.getElementById('planos-container');
    const loader    = document.getElementById('planos-loader');
    const subtitle  = document.getElementById('planos-subtitle');

    // Mostra o loader
    loader.classList.remove('hidden');

    // Monta a URL com os parâmetros corretos
    let url = `/api/listar_planos.php?plan_type=${state.planType}`;
    if (state.companyId) url += `&company_id=${encodeURIComponent(state.companyId)}`;

    try {
        const res  = await fetch(url);
        const data = await res.json();

        // Esconde o loader
        loader.classList.add('hidden');

        if (!data.plans || data.plans.length === 0) {
            container.innerHTML = '<p class="text-center text-slate-400 py-6">Nenhum plano disponível no momento.</p>';
            return;
        }

        // Atualiza o subtítulo conforme o tipo
        if (data.plan_type === 'convenio') {
            subtitle.textContent = `Planos exclusivos do seu convênio com ${state.companyName || 'a empresa parceira'}.`;
        } else {
            subtitle.textContent = 'Planos disponíveis para você.';
        }

        // Renderiza os cards de plano
        container.innerHTML = '';
        data.plans.forEach(plan => {
            const card = document.createElement('div');
            card.className = 'plan-card';
            card.dataset.planId              = plan.id;
            card.dataset.planName            = plan.name;
            card.dataset.planPrice           = plan.price_formatted;
            card.dataset.planPriceCents      = plan.price_cents;
            card.dataset.planIdentifier      = plan.iugu_plan_identifier;
            card.dataset.planBillingPeriod   = plan.billing_period;

            const periodLabel = plan.billing_period === 'monthly' ? '/mês' : '/ano';

            card.innerHTML = `
                <div class="plan-radio"></div>
                <div class="flex-grow">
                    <p class="font-bold text-slate-800">${plan.name}</p>
                    ${plan.description ? `<p class="text-xs text-slate-400 mt-0.5">${plan.description}</p>` : ''}
                </div>
                <div class="text-right flex-shrink-0">
                    <p class="text-xl font-bold text-tks-primary">${plan.price_formatted}</p>
                    <p class="text-xs text-slate-400">${periodLabel}</p>
                </div>
            `;

            card.addEventListener('click', () => selectPlan(card, plan));
            container.appendChild(card);
        });

    } catch (err) {
        loader.classList.add('hidden');
        container.innerHTML = '<p class="text-center text-red-400 py-6">Erro ao carregar planos. Tente novamente.</p>';
        console.error(err);
    }
}

/**
 * Seleciona um plano e habilita o botão de continuar.
 */
function selectPlan(cardEl, plan) {
    // Remove a seleção de todos os cards
    document.querySelectorAll('.plan-card').forEach(c => c.classList.remove('selected'));

    // Seleciona o card clicado
    cardEl.classList.add('selected');

    // Salva o plano no estado global
    state.selectedPlan = plan;

    // Habilita o botão de continuar
    document.getElementById('btn-selecionar-plano').disabled = false;
}

// ============================================================
// ETAPA 3: CONFIRMAÇÃO DE DADOS PESSOAIS
// ============================================================

/**
 * Valida os dados pessoais e avança para a etapa de pagamento.
 */
function handleConfirmarDados() {
    const nome       = document.getElementById('input-nome').value.trim();
    const email      = document.getElementById('input-email').value.trim();
    const telefone   = document.getElementById('input-telefone').value.trim();
    const nascimento = document.getElementById('input-nascimento').value.trim();

    if (!nome)       { showError('Por favor, informe seu nome completo.'); return; }
    if (!email || !email.includes('@')) { showError('Por favor, informe um e-mail válido.'); return; }
    if (telefone.replace(/\D/g, '').length < 10) { showError('Por favor, informe um telefone válido com DDD.'); return; }
    if (!nascimento) { showError('Por favor, informe sua data de nascimento.'); return; }

    // Salva os dados no estado global
    state.fullName  = nome;
    state.email     = email;
    state.phone     = telefone.replace(/\D/g, '');
    state.birthDate = nascimento;

    // Preenche o resumo na etapa de pagamento
    document.getElementById('resumo-plano-nome').textContent  = state.selectedPlan?.name || '—';
    document.getElementById('resumo-plano-preco').textContent = state.selectedPlan?.price_formatted || '—';

    goToStep(4);
}

// ============================================================
// ETAPA 4: SELEÇÃO DO MÉTODO DE PAGAMENTO E FINALIZAÇÃO
// ============================================================

/**
 * Seleciona o método de pagamento e exibe o formulário correto.
 */
function selectPaymentMethod(method) {
    state.paymentMethod = method;

    // Atualiza visual dos botões
    document.querySelectorAll('.payment-method-btn').forEach(btn => {
        btn.classList.toggle('selected', btn.dataset.method === method);
    });

    // Mostra/esconde o formulário de cartão
    const formCartao = document.getElementById('form-cartao');
    const avisoBoleto = document.getElementById('aviso-boleto-pix');
    const avisoText   = document.getElementById('aviso-boleto-pix-text');

    if (method === 'credit_card') {
        formCartao.classList.remove('hidden');
        avisoBoleto.classList.add('hidden');
    } else {
        formCartao.classList.add('hidden');
        avisoBoleto.classList.remove('hidden');
        avisoText.textContent = method === 'pix'
            ? 'Após clicar em "Finalizar", um QR Code PIX será gerado para você.'
            : 'Após clicar em "Finalizar", um boleto bancário será gerado para você.';
    }

    // Habilita o botão de finalizar
    document.getElementById('btn-finalizar').disabled = false;
}

/**
 * Chamado quando o usuário clica em "Finalizar Assinatura".
 * Tokeniza o cartão (se necessário) e envia para a API.
 */
async function handleFinalizar() {
    if (!state.paymentMethod) {
        showError('Por favor, selecione um método de pagamento.');
        return;
    }

    setButtonLoading('btn-finalizar', 'btn-finalizar-text', 'btn-finalizar-loader', 'btn-finalizar-icon', true);

    try {
        let cardToken = null;

        // --- Tokenização do Cartão via SDK da Iugu ---
        // O SDK da Iugu captura os dados do cartão no FRONTEND e
        // retorna um token temporário. Nunca enviamos os dados do
        // cartão diretamente para o nosso servidor — isso é uma
        // prática de segurança obrigatória (PCI DSS).
        if (state.paymentMethod === 'credit_card') {
            cardToken = await tokenizarCartao();
            if (!cardToken) {
                setButtonLoading('btn-finalizar', 'btn-finalizar-text', 'btn-finalizar-loader', 'btn-finalizar-icon', false);
                return; // Erro já exibido dentro de tokenizarCartao()
            }
        }

        // --- Monta o payload para a API ---
        const payload = {
            cpf:                  state.cpf,
            full_name:            state.fullName,
            email:                state.email,
            phone:                state.phone,
            birth_date:           state.birthDate,
            iugu_plan_identifier: state.selectedPlan.iugu_plan_identifier,
            plan_id:              state.selectedPlan.id,
            payment_method:       state.paymentMethod,
        };

        // Adiciona dados opcionais se disponíveis
        if (state.profileId) payload.profile_id = state.profileId;
        if (state.companyId) payload.company_id  = state.companyId;
        if (cardToken)       payload.card_token   = cardToken;

        // --- Envia para a API de processamento ---
        const res  = await fetch('/api/processar_assinatura.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload),
        });

        const data = await res.json();

        if (!res.ok || data.error) {
            throw new Error(data.error || data.message || 'Erro ao processar assinatura.');
        }

        // --- Trata o resultado ---
        if (data.payment_status === 'paid') {
            // Pagamento aprovado imediatamente (cartão)
            showSuccess(data.message);
        } else if (data.payment_status === 'pending') {
            // Pagamento pendente (boleto/PIX)
            showPending(data.payment_url);
        } else {
            // Pagamento recusado
            throw new Error(data.message || 'Pagamento recusado. Verifique os dados do cartão.');
        }

    } catch (err) {
        showError(err.message || 'Erro inesperado. Tente novamente.');
        console.error(err);
    } finally {
        setButtonLoading('btn-finalizar', 'btn-finalizar-text', 'btn-finalizar-loader', 'btn-finalizar-icon', false);
    }
}

/**
 * Tokeniza os dados do cartão usando o SDK da Iugu.
 * Retorna o token (string) em caso de sucesso, ou null em caso de erro.
 */
function tokenizarCartao() {
    return new Promise((resolve) => {
        const number  = document.getElementById('input-card-number').value.replace(/\s/g, '');
        const name    = document.getElementById('input-card-name').value.trim();
        const expiry  = document.getElementById('input-card-expiry').value; // MM/AA
        const cvv     = document.getElementById('input-card-cvv').value.trim();

        if (!number || !name || !expiry || !cvv) {
            showError('Por favor, preencha todos os dados do cartão.');
            resolve(null);
            return;
        }

        const [expMonth, expYear] = expiry.split('/');

        // Chama o SDK da Iugu para criar o token
        // O SDK está carregado via <script> no index.html
        Iugu.createPaymentToken({
            number:             number,
            verification_value: cvv,
            first_name:         name.split(' ')[0],
            last_name:          name.split(' ').slice(1).join(' '),
            month:              expMonth,
            year:               '20' + expYear,
        }, (response) => {
            if (response.errors) {
                const errorMsg = Object.values(response.errors).join(', ');
                showError('Dados do cartão inválidos: ' + errorMsg);
                resolve(null);
            } else {
                resolve(response.id); // O token gerado pela Iugu
            }
        });
    });
}

// ============================================================
// TELAS DE RESULTADO
// ============================================================

function showSuccess(message) {
    document.getElementById(`step-${currentStep}`)?.classList.add('hidden');
    document.getElementById('progress-steps').classList.add('hidden');
    const successPanel = document.getElementById('step-success');
    successPanel.classList.remove('hidden');
    if (message) document.getElementById('success-message').textContent = message;
    currentStep = 'success';
}

function showPending(paymentUrl) {
    document.getElementById(`step-${currentStep}`)?.classList.add('hidden');
    document.getElementById('progress-steps').classList.add('hidden');
    const pendingPanel = document.getElementById('step-pending');
    pendingPanel.classList.remove('hidden');
    if (paymentUrl) {
        document.getElementById('link-pagamento').href = paymentUrl;
    }
    currentStep = 'pending';
}

// ============================================================
// UTILITÁRIOS
// ============================================================

/** Exibe uma mensagem de erro temporária no topo da tela. */
function showError(message) {
    // Remove qualquer toast anterior
    document.getElementById('toast-error')?.remove();

    const toast = document.createElement('div');
    toast.id = 'toast-error';
    toast.className = 'fixed top-4 left-1/2 -translate-x-1/2 z-50 bg-red-500 text-white px-6 py-3 rounded-xl shadow-lg text-sm font-semibold flex items-center gap-2 animate-bounce';
    toast.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
    document.body.appendChild(toast);

    // Remove após 4 segundos
    setTimeout(() => toast.remove(), 4000);
}

/** Ativa/desativa o estado de loading de um botão. */
function setButtonLoading(btnId, textId, loaderId, iconId, isLoading) {
    const btn    = document.getElementById(btnId);
    const text   = document.getElementById(textId);
    const loader = document.getElementById(loaderId);
    const icon   = document.getElementById(iconId);

    if (!btn) return;
    btn.disabled = isLoading;
    if (text)   text.textContent = isLoading ? 'Aguarde...' : text.dataset.original || text.textContent;
    if (loader) loader.classList.toggle('hidden', !isLoading);
    if (icon)   icon.classList.toggle('hidden', isLoading);
}

/** Máscara de CPF: 000.000.000-00 */
function maskCPF(e) {
    let v = e.target.value.replace(/\D/g, '').slice(0, 11);
    v = v.replace(/(\d{3})(\d)/, '$1.$2');
    v = v.replace(/(\d{3})(\d)/, '$1.$2');
    v = v.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
    e.target.value = v;
}

/** Máscara de Telefone: (00) 00000-0000 */
function maskPhone(e) {
    let v = e.target.value.replace(/\D/g, '').slice(0, 11);
    v = v.replace(/(\d{2})(\d)/, '($1) $2');
    v = v.replace(/(\d{5})(\d)/, '$1-$2');
    e.target.value = v;
}

/** Formata um telefone de dígitos para o padrão com máscara. */
function formatPhone(digits) {
    const d = digits.replace(/\D/g, '');
    if (d.length === 11) return `(${d.slice(0,2)}) ${d.slice(2,7)}-${d.slice(7)}`;
    if (d.length === 10) return `(${d.slice(0,2)}) ${d.slice(2,6)}-${d.slice(6)}`;
    return digits;
}

/** Máscara de Número de Cartão: 0000 0000 0000 0000 */
function maskCardNumber(e) {
    let v = e.target.value.replace(/\D/g, '').slice(0, 16);
    v = v.replace(/(\d{4})(?=\d)/g, '$1 ');
    e.target.value = v;
}

/** Máscara de Validade do Cartão: MM/AA */
function maskCardExpiry(e) {
    let v = e.target.value.replace(/\D/g, '').slice(0, 4);
    if (v.length >= 3) v = v.slice(0, 2) + '/' + v.slice(2);
    e.target.value = v;
}
