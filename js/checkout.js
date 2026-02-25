/**
 * ============================================================
 * CHECKOUT CONVÊNIOS - TKS VANTAGENS
 * Arquivo: js/checkout.js
 * Versão: 1.0.2
 *
 * Descrição: Lógica completa do formulário de checkout.
 *   - Navegação entre etapas
 *   - Validações de CPF, telefone, e-mail e data de nascimento
 *   - Chamadas de API para verificação de CPF e listagem de planos
 *   - Tokenização do cartão via SDK da Iugu
 *   - Processamento da assinatura
 * ============================================================
 */

// ============================================================
// ESTADO GLOBAL DO CHECKOUT
// Armazena todos os dados coletados ao longo das etapas.
// ============================================================
const state = {
    // Etapa 1: CPF
    cpf: '',
    profileId: null,   // UUID do perfil no banco (se já existir)
    companyId: null,   // UUID da empresa parceira (se for convênio)
    companyName: null,
    planType: 'b2c',  // "convenio" ou "b2c"
    isNewUser: true,

    // Etapa 2: Plano
    selectedPlan: null,   // Objeto com id, name, price_formatted, iugu_plan_identifier

    // Etapa 3: Dados Pessoais
    fullName: '',
    email: '',
    phone: '',
    birthDate: '',

    // Etapa 4: Pagamento
    paymentMethod: null,  // "credit_card" | "bank_slip" | "pix"
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

    // Link "Termos e Condições" abre o modal
    document.getElementById('link-termos')?.addEventListener('click', openTermsModal);

    // Fechar modal ao clicar no backdrop (fora do card)
    document.getElementById('modal-termos')?.addEventListener('click', (e) => {
        if (e.target === document.getElementById('modal-termos')) closeTermsModal();
    });

    // Fechar modal com tecla Escape
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeTermsModal();
    });

    // Garante que a Iugu está configurada (evita "AccountID inválido")
    if (window.Iugu && typeof Iugu.setAccountID === 'function') {
        Iugu.setAccountID(window.IUGU_ACCOUNT_ID || "B07088D648D048B3B450CCB6B5371BD3");
        Iugu.setTestMode(!!window.IUGU_TEST_MODE);
    }
    // Limpa o erro inline ao editar os campos da Etapa 3
    ['input-nome', 'input-email', 'input-telefone', 'input-nascimento'].forEach(id => {
        document.getElementById(id)?.addEventListener('input', () => clearFieldError(id));
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
    document.getElementById(`step-${currentStep}`)?.classList.add('hidden');
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
        const label = item.querySelector('span');
        const line = item.nextElementSibling;

        if (i < activeStep) {
            circle.className = 'step-circle w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold border-2 completed';
            circle.innerHTML = '<i class="fas fa-check text-xs"></i>';
            label.className = 'text-xs mt-1 text-green-600 font-semibold';
            if (line && line.classList.contains('step-line')) line.classList.add('active');
        } else if (i === activeStep) {
            circle.className = 'step-circle w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold border-2 active';
            circle.textContent = i;
            label.className = 'text-xs mt-1 text-tks-primary font-semibold';
        } else {
            circle.className = 'step-circle w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold border-2 border-slate-200 bg-white text-slate-400';
            circle.textContent = i;
            label.className = 'text-xs mt-1 text-slate-400';
        }
    }
}

// ============================================================
// VALIDAÇÕES
// Todas as regras de negócio para os campos do formulário.
// ============================================================

/**
 * Valida o CPF usando o algoritmo oficial dos dois dígitos verificadores.
 *
 * Regras:
 *  - Deve ter exatamente 11 dígitos
 *  - Não pode ser uma sequência repetida (ex: 111.111.111-11)
 *  - Os dois últimos dígitos devem ser calculados corretamente
 *
 * @param {string} cpf - CPF apenas com dígitos (sem máscara)
 * @returns {boolean}
 */
function validarCPF(cpf) {
    // Remove qualquer caractere não numérico
    cpf = cpf.replace(/\D/g, '');

    // Deve ter exatamente 11 dígitos
    if (cpf.length !== 11) return false;

    // Rejeita sequências repetidas (ex: 00000000000, 11111111111, etc.)
    if (/^(\d)\1{10}$/.test(cpf)) return false;

    // --- Cálculo do 1º dígito verificador ---
    let soma = 0;
    for (let i = 0; i < 9; i++) {
        soma += parseInt(cpf[i]) * (10 - i);
    }
    let resto = (soma * 10) % 11;
    if (resto === 10 || resto === 11) resto = 0;
    if (resto !== parseInt(cpf[9])) return false;

    // --- Cálculo do 2º dígito verificador ---
    soma = 0;
    for (let i = 0; i < 10; i++) {
        soma += parseInt(cpf[i]) * (11 - i);
    }
    resto = (soma * 10) % 11;
    if (resto === 10 || resto === 11) resto = 0;
    if (resto !== parseInt(cpf[10])) return false;

    return true;
}

/**
 * Valida o e-mail.
 *
 * Regras:
 *  - Deve conter o caractere @
 *  - Deve ter um domínio após o @ (ex: gmail.com)
 *  - Formato geral: usuario@dominio.extensao
 *
 * @param {string} email
 * @returns {boolean}
 */
function validarEmail(email) {
    // Expressão regular que valida o formato básico de e-mail
    const regex = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
    return regex.test(email.trim());
}

/**
 * Valida o telefone celular brasileiro.
 *
 * Regras:
 *  - Deve ter exatamente 11 dígitos (DDD + 9 + 8 dígitos)
 *  - O DDD deve ser um número válido (11 a 99)
 *  - O número deve começar com 9 (celular)
 *
 * @param {string} phone - Telefone apenas com dígitos
 * @returns {{ valid: boolean, message: string }}
 */
function validarTelefone(phone) {
    const digits = phone.replace(/\D/g, '');

    if (digits.length < 11) {
        return { valid: false, message: 'Telefone inválido. Informe DDD + 9 dígitos (ex: 61 9 9618-7769).' };
    }
    if (digits.length > 11) {
        return { valid: false, message: 'Telefone inválido. Número muito longo.' };
    }

    const ddd = parseInt(digits.substring(0, 2));
    const nono = digits[2]; // O 3º dígito deve ser 9 para celular

    // DDD válido: entre 11 e 99
    if (ddd < 11 || ddd > 99) {
        return { valid: false, message: 'DDD inválido. Informe um DDD entre 11 e 99.' };
    }

    // Celular deve começar com 9
    if (nono !== '9') {
        return { valid: false, message: 'Número de celular inválido. O número deve começar com 9 após o DDD.' };
    }

    return { valid: true, message: '' };
}

/**
 * Valida a data de nascimento.
 *
 * Regras:
 *  - Deve ser uma data válida
 *  - O usuário deve ter no mínimo 15 anos na data atual
 *  - Não pode ser uma data futura
 *
 * @param {string} birthDate - Data no formato YYYY-MM-DD (padrão do input type="date")
 * @returns {{ valid: boolean, message: string }}
 */
function validarDataNascimento(birthDate) {
    if (!birthDate) {
        return { valid: false, message: 'Por favor, informe sua data de nascimento.' };
    }

    const nascimento = new Date(birthDate);
    const hoje = new Date();

    // Verifica se é uma data válida
    if (isNaN(nascimento.getTime())) {
        return { valid: false, message: 'Data de nascimento inválida.' };
    }

    // Não pode ser uma data futura
    if (nascimento > hoje) {
        return { valid: false, message: 'A data de nascimento não pode ser uma data futura.' };
    }

    // Calcula a idade exata (considerando mês e dia)
    let idade = hoje.getFullYear() - nascimento.getFullYear();
    const mesAtual = hoje.getMonth();
    const diaAtual = hoje.getDate();
    const mesNasc = nascimento.getMonth();
    const diaNasc = nascimento.getDate();

    // Se ainda não fez aniversário este ano, subtrai 1
    if (mesAtual < mesNasc || (mesAtual === mesNasc && diaAtual < diaNasc)) {
        idade--;
    }

    // Mínimo de 15 anos
    if (idade < 15) {
        return { valid: false, message: 'Você deve ter pelo menos 15 anos para se cadastrar.' };
    }

    return { valid: true, message: '' };
}

// ============================================================
// EXIBIÇÃO DE ERROS INLINE NOS CAMPOS
// ============================================================

/**
 * Exibe uma mensagem de erro diretamente abaixo de um campo específico.
 * Também destaca o campo com borda vermelha.
 *
 * @param {string} fieldId - ID do campo HTML
 * @param {string} message - Mensagem de erro a exibir
 */
function showFieldError(fieldId, message) {
    const field = document.getElementById(fieldId);
    if (!field) return;

    // Remove erro anterior deste campo
    clearFieldError(fieldId);

    // Destaca o campo com borda vermelha
    field.classList.add('border-red-500', 'focus:ring-red-300');
    field.classList.remove('border-slate-200');

    // Cria o elemento de mensagem de erro abaixo do campo
    const errorEl = document.createElement('p');
    errorEl.id = `error-${fieldId}`;
    errorEl.className = 'text-red-500 text-xs mt-1 flex items-center gap-1';
    errorEl.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;

    // Insere após o campo (ou após o wrapper se houver)
    const parent = field.parentElement;
    parent.appendChild(errorEl);
}

/**
 * Remove a mensagem de erro e o destaque de um campo.
 *
 * @param {string} fieldId - ID do campo HTML
 */
function clearFieldError(fieldId) {
    const field = document.getElementById(fieldId);
    const errorEl = document.getElementById(`error-${fieldId}`);

    if (field) {
        field.classList.remove('border-red-500', 'focus:ring-red-300');
        field.classList.add('border-slate-200');
    }
    if (errorEl) errorEl.remove();
}

// ============================================================
// ETAPA 1: VERIFICAÇÃO DO CPF
// ============================================================

/**
 * Chamado quando o usuário clica em "Continuar" na Etapa 1.
 *
 * Validações:
 *  1. CPF deve ter 11 dígitos
 *  2. CPF deve ser matematicamente válido (algoritmo oficial)
 *
 * Se válido, consulta a API para verificar o vínculo do usuário.
 */
async function handleVerificarCpf() {
    const cpfInput = document.getElementById('input-cpf');
    const cpfVal = cpfInput.value;
    const cpfDigits = cpfVal.replace(/\D/g, '');

    // --- Validação 1: Tamanho ---
    if (cpfDigits.length !== 11) {
        showError('CPF inválido. Por favor, informe todos os 11 dígitos.');
        cpfInput.focus();
        return;
    }

    // --- Validação 2: Algoritmo oficial do CPF ---
    if (!validarCPF(cpfDigits)) {
        showError('CPF inválido. O número informado não é um CPF válido. Verifique e tente novamente.');
        cpfInput.focus();
        return;
    }

    setButtonLoading('btn-verificar-cpf', 'btn-verificar-text', 'btn-verificar-loader', 'btn-verificar-arrow', true);

    try {
        const res = await fetch(`api/verificar_cpf.php?cpf=${encodeURIComponent(cpfVal)}`);
        const data = await res.json();

        if (data.error) throw new Error(data.error);

        // Salva os dados no estado global
        state.cpf = data.cpf || cpfDigits;
        state.profileId = data.profile_id || null;
        state.companyId = data.company_id || null;
        state.companyName = data.company_name || null;
        state.planType = data.plan_type || 'b2c';
        state.isNewUser = data.is_new_user || false;

        // Pré-preenche dados pessoais se o usuário já existe no banco
        if (data.found && !data.is_new_user) {
            if (data.full_name) document.getElementById('input-nome').value = data.full_name;
            if (data.email) document.getElementById('input-email').value = data.email;
            if (data.phone) document.getElementById('input-telefone').value = formatPhone(data.phone);
            if (data.birth_date) document.getElementById('input-nascimento').value = data.birth_date;
        }

        // Exibe o badge de convênio se aplicável
        if (state.planType === 'convenio' && state.companyName) {
            const badge = document.getElementById('badge-convenio');
            document.getElementById('badge-company-name').textContent = state.companyName;
            badge.classList.remove('hidden');
            badge.classList.add('flex');
        }

        // Avança para a etapa de seleção de planos
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
 * Convênio → planos específicos da empresa
 * B2C      → planos padrão
 */
async function carregarPlanos() {
    const container = document.getElementById('planos-container');
    const loader = document.getElementById('planos-loader');
    const subtitle = document.getElementById('planos-subtitle');

    loader.classList.remove('hidden');

    let url = `api/listar_planos.php?plan_type=${state.planType}`;
    if (state.companyId) url += `&company_id=${encodeURIComponent(state.companyId)}`;

    try {
        const res = await fetch(url);
        const data = await res.json();

        loader.classList.add('hidden');

        if (!data.plans || data.plans.length === 0) {
            container.innerHTML = '<p class="text-center text-slate-400 py-6">Nenhum plano disponível no momento.</p>';
            return;
        }

        if (data.plan_type === 'convenio') {
            subtitle.textContent = `Planos exclusivos do seu convênio com ${state.companyName || 'a empresa parceira'}.`;
        } else {
            subtitle.textContent = 'Planos disponíveis para você.';
        }

        container.innerHTML = '';
        data.plans.forEach(plan => {
            const card = document.createElement('div');
            card.className = 'plan-card';
            card.dataset.planId = plan.id;
            card.dataset.planName = plan.name;
            card.dataset.planPrice = plan.price_formatted;
            card.dataset.planIdentifier = plan.iugu_plan_identifier;

            card.innerHTML = `
                <div class="plan-radio"></div>
                <div class="flex-grow">
                    <p class="font-bold text-slate-800">${plan.name}</p>
                    ${plan.description ? `<p class="text-xs text-slate-400 mt-0.5">${plan.description}</p>` : ''}
                </div>
                <div class="text-right flex-shrink-0">
                    <p class="text-xl font-bold text-tks-primary">${plan.price_formatted}</p>
                    <p class="text-xs text-slate-400">/mês</p>
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
    document.querySelectorAll('.plan-card').forEach(c => c.classList.remove('selected'));
    cardEl.classList.add('selected');
    state.selectedPlan = plan;
    document.getElementById('btn-selecionar-plano').disabled = false;
}

// ============================================================
// ETAPA 3: CONFIRMAÇÃO DE DADOS PESSOAIS
// ============================================================

/**
 * Valida todos os dados pessoais antes de avançar para o pagamento.
 *
 * Validações:
 *  - Nome: obrigatório
 *  - E-mail: formato válido com @ e domínio
 *  - Telefone: DDD (2 dígitos) + 9 + 8 dígitos = 11 dígitos no total
 *  - Data de Nascimento: data válida, não futura, mínimo 15 anos
 *
 * Exibe erros inline abaixo de cada campo com problema.
 * Só avança se TODOS os campos estiverem válidos.
 */
function handleConfirmarDados() {
    const nome = document.getElementById('input-nome').value.trim();
    const email = document.getElementById('input-email').value.trim();
    const telefone = document.getElementById('input-telefone').value.trim();
    const nascimento = document.getElementById('input-nascimento').value.trim();

    // Limpa todos os erros anteriores
    ['input-nome', 'input-email', 'input-telefone', 'input-nascimento'].forEach(clearFieldError);

    let hasError = false;

    // --- Validação: Nome ---
    if (!nome) {
        showFieldError('input-nome', 'Por favor, informe seu nome completo.');
        hasError = true;
    } else if (nome.split(' ').filter(p => p.length > 0).length < 2) {
        showFieldError('input-nome', 'Informe seu nome e sobrenome.');
        hasError = true;
    }

    // --- Validação: E-mail ---
    if (!email) {
        showFieldError('input-email', 'Por favor, informe seu e-mail.');
        hasError = true;
    } else if (!validarEmail(email)) {
        showFieldError('input-email', 'E-mail inválido. Informe um e-mail no formato usuario@dominio.com');
        hasError = true;
    }

    // --- Validação: Telefone ---
    const telResult = validarTelefone(telefone);
    if (!telResult.valid) {
        showFieldError('input-telefone', telResult.message);
        hasError = true;
    }

    // --- Validação: Data de Nascimento ---
    const nascResult = validarDataNascimento(nascimento);
    if (!nascResult.valid) {
        showFieldError('input-nascimento', nascResult.message);
        hasError = true;
    }

    // Se houver qualquer erro, interrompe e não avança
    if (hasError) {
        showError('Por favor, corrija os campos destacados em vermelho antes de continuar.');
        return;
    }

    // Todos os campos válidos — salva no estado e avança
    state.fullName = nome;
    state.email = email;
    state.phone = telefone.replace(/\D/g, '');
    state.birthDate = nascimento;

    // Preenche o resumo na etapa de pagamento
    document.getElementById('resumo-plano-nome').textContent = state.selectedPlan?.name || '—';
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

    document.querySelectorAll('.payment-method-btn').forEach(btn => {
        btn.classList.toggle('selected', btn.dataset.method === method);
    });

    const formCartao = document.getElementById('form-cartao');
    const avisoBoleto = document.getElementById('aviso-boleto-pix');
    const avisoText = document.getElementById('aviso-boleto-pix-text');

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

    updateFinalizarButton();
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

    // Valida aceite dos Termos e Condições
    const chkTermos = document.getElementById('chk-termos');
    const erroTermos = document.getElementById('erro-termos');
    if (!chkTermos || !chkTermos.checked) {
        erroTermos?.classList.remove('hidden');
        erroTermos?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }

    setButtonLoading('btn-finalizar', 'btn-finalizar-text', 'btn-finalizar-loader', 'btn-finalizar-icon', true);

    try {
        let cardToken = null;

        // --- Tokenização do Cartão via SDK da Iugu ---
        // O SDK captura os dados do cartão no FRONTEND e retorna
        // um token temporário. Os dados do cartão NUNCA chegam ao
        // nosso servidor — isso é obrigatório pelo padrão PCI DSS.
        if (state.paymentMethod === 'credit_card') {
            cardToken = await tokenizarCartao();
            if (!cardToken) {
                setButtonLoading('btn-finalizar', 'btn-finalizar-text', 'btn-finalizar-loader', 'btn-finalizar-icon', false);
                return;
            }
        }

        // --- Monta o payload para a API ---
        const payload = {
            cpf: state.cpf,
            full_name: state.fullName,
            email: state.email,
            phone: state.phone,
            birth_date: state.birthDate,
            iugu_plan_identifier: state.selectedPlan.iugu_plan_identifier,
            plan_id: state.selectedPlan.id,
            payment_method: state.paymentMethod,
        };

        if (state.profileId) payload.profile_id = state.profileId;
        if (state.companyId) payload.company_id = state.companyId;
        if (cardToken) payload.card_token = cardToken;

        // --- Envia para a API de processamento ---
        const res = await fetch('api/processar_assinatura.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });

        const data = await res.json();

        if (!res.ok || data.error) {
            throw new Error(data.error || data.message || 'Erro ao processar assinatura.');
        }

        if (data.payment_status === 'paid') {
            showSuccess(data.message);
        } else if (data.payment_status === 'pending') {
            showPending(data.payment_url);
        } else {
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
 * @returns {Promise<string|null>} Token ou null em caso de erro.
 */
function tokenizarCartao() {
    return new Promise((resolve) => {
        const number = document.getElementById('input-card-number').value.replace(/\s/g, '');
        const name = document.getElementById('input-card-name').value.trim();
        const expiry = document.getElementById('input-card-expiry').value;
        const cvv = document.getElementById('input-card-cvv').value.trim();

        if (!number || !name || !expiry || !cvv) {
            showError('Por favor, preencha todos os dados do cartão.');
            resolve(null);
            return;
        }

        const [expMonth, expYear] = expiry.split('/');

        Iugu.createPaymentToken({
            number: number,
            verification_value: cvv,
            first_name: name.split(' ')[0],
            last_name: name.split(' ').slice(1).join(' '),
            month: expMonth,
            year: '20' + expYear,
        }, (response) => {
            if (response.errors) {
                showError('Dados do cartão inválidos: ' + Object.values(response.errors).join(', '));
                resolve(null);
            } else {
                resolve(response.id);
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
    const panel = document.getElementById('step-success');
    panel.classList.remove('hidden');
    if (message) document.getElementById('success-message').textContent = message;
    currentStep = 'success';
}

function showPending(paymentUrl) {
    document.getElementById(`step-${currentStep}`)?.classList.add('hidden');
    document.getElementById('progress-steps').classList.add('hidden');
    const panel = document.getElementById('step-pending');
    panel.classList.remove('hidden');
    if (paymentUrl) document.getElementById('link-pagamento').href = paymentUrl;
    currentStep = 'pending';
}

// ============================================================
// UTILITÁRIOS
// ============================================================

/**
 * Exibe um toast de erro temporário no topo da tela.
 * Remove automaticamente após 5 segundos.
 */
function showError(message) {
    document.getElementById('toast-error')?.remove();

    const toast = document.createElement('div');
    toast.id = 'toast-error';
    toast.className = 'fixed top-4 left-1/2 -translate-x-1/2 z-50 bg-red-500 text-white px-6 py-3 rounded-xl shadow-lg text-sm font-semibold flex items-center gap-2';
    toast.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
    document.body.appendChild(toast);

    setTimeout(() => toast.remove(), 5000);
}

/** Ativa/desativa o estado de loading de um botão. */
function setButtonLoading(btnId, textId, loaderId, iconId, isLoading) {
    const btn = document.getElementById(btnId);
    const text = document.getElementById(textId);
    const loader = document.getElementById(loaderId);
    const icon = document.getElementById(iconId);

    if (!btn) return;
    btn.disabled = isLoading;
    if (text) text.textContent = isLoading ? 'Aguarde...' : (text.dataset.original || text.textContent);
    if (loader) loader.classList.toggle('hidden', !isLoading);
    if (icon) icon.classList.toggle('hidden', isLoading);
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
    if (d.length === 11) return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`;
    if (d.length === 10) return `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`;
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

// ============================================================
// MODAL DE TERMOS E CONDIÇÕES
// ============================================================

/**
 * Abre o modal de Termos e Condições.
 * Chamado pelo link "Termos e Condições" na Etapa 4.
 */
function openTermsModal(e) {
    if (e) e.preventDefault();
    const modal = document.getElementById('modal-termos');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.style.overflow = 'hidden'; // Impede scroll do fundo
}

/**
 * Fecha o modal de Termos e Condições.
 */
function closeTermsModal() {
    const modal = document.getElementById('modal-termos');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.style.overflow = '';
}

/**
 * Chamado pelo botão "Li e aceito" dentro do modal.
 * Marca o checkbox e fecha o modal.
 */
function acceptTermsFromModal() {
    const chk = document.getElementById('chk-termos');
    chk.checked = true;
    onTermsChange();
    closeTermsModal();
}

/**
 * Chamado quando o checkbox de termos muda de estado.
 * Atualiza a visibilidade do erro e habilita/desabilita o botão finalizar.
 */
function onTermsChange() {
    const chk = document.getElementById('chk-termos');
    const erroTermos = document.getElementById('erro-termos');
    if (chk.checked) {
        erroTermos.classList.add('hidden');
    }
    updateFinalizarButton();
}

/**
 * Habilita o botão "Finalizar" apenas quando:
 *  - Um método de pagamento foi selecionado, E
 *  - O checkbox de termos está marcado.
 */
function updateFinalizarButton() {
    const chk = document.getElementById('chk-termos');
    const btn = document.getElementById('btn-finalizar');
    if (!btn) return;
    btn.disabled = !(state.paymentMethod && chk && chk.checked);
}
