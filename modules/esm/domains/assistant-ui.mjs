export function createAssistantUi(context) {
    const {
        deps, KEYS, isGuest, localTime, q, qa, safe, state, suite, ui,
        assistantPlainText, renderAssistantSuggestions, setAssistantMode, setAssistantProviderStatus
    } = context;
    if (!state || !suite || typeof q !== 'function' || typeof ui !== 'function') {
        throw new Error('METEONEXA_SUITE_ASSISTANT_UI_CONTEXT_INVALID');
    }
function openAssistantDialog() {
    const dialog = q('#assistant-dialog');
    if (!dialog)
        return;
    const guest = isGuest();
    qa('[data-assistant-mode-choice="ai"]').forEach(button => {
        button.hidden = guest;
        button.disabled = guest;
        button.setAttribute('aria-hidden', guest ? 'true' : 'false');
    });
    qa('[data-assistant-mode-choice="local"]').forEach(button => {
        button.hidden = false;
        button.disabled = false;
    });
    if (guest) setAssistantMode('local', false);
    restoreAssistant();
    renderAssistantSuggestions();
    if (!dialog.open && typeof dialog.showModal === 'function')
        dialog.showModal();
    else
        dialog.setAttribute('open', '');
    setTimeout(() => q('#assistant-input')?.focus(), 80);
}
function closeAssistantDialog() {
    const dialog = q('#assistant-dialog');
    if (!dialog)
        return;
    if (suite.aiSwitchPending) {
        suite.aiSwitchPending = null;
        setAssistantBusy(false);
    }
    if (dialog.open && typeof dialog.close === 'function')
        dialog.close();
    else
        dialog.removeAttribute('open');
}
function assistantRows() {
    try {
        return JSON.parse(sessionStorage.getItem(KEYS.assistant) || '[]');
    }
    catch {
        return [];
    }
}
function saveAssistantRows(rows) { sessionStorage.setItem(KEYS.assistant, JSON.stringify(rows.slice(-30))); }
function setAssistantBusy(busy) {
    suite.assistantBusy = Boolean(busy);
    const form = q('#assistant-form');
    const send = q('#assistant-send');
    const clear = q('#assistant-clear');
    if (form)
        form.setAttribute('aria-busy', busy ? 'true' : 'false');
    if (send)
        send.disabled = Boolean(busy);
    if (clear)
        clear.disabled = Boolean(busy);
}
function copilotEvidenceHtml(rawDecision) {
    const decision = deps.copilot?.normalizeDecision?.(rawDecision) || rawDecision;
    if (!decision?.available) return '';
    const items = [];
    const status = ['good','caution','avoid','learning'].includes(decision.status) ? decision.status : 'learning';
    items.push(`<span class="copilot-evidence-status ${safe(status)}">${safe(ui(`copilot.status.${status}`))}</span>`);
    if (Number.isFinite(decision.score)) items.push(`<span><small>${safe(ui('copilot.metric.score'))}</small><strong>${Math.round(decision.score)}/100</strong></span>`);
    if (Number.isFinite(decision.confidence)) items.push(`<span><small>${safe(ui('copilot.metric.confidence'))}</small><strong>${Math.round(decision.confidence)}%</strong></span>`);
    if (decision.route?.bestDeparture) items.push(`<span><small>${safe(ui('copilot.metric.best_departure'))}</small><strong>${safe(localTime(decision.route.bestDeparture))}</strong></span>`);
    if (Number.isFinite(decision.route?.selectedRisk)) items.push(`<span><small>${safe(ui('copilot.metric.route_risk'))}</small><strong>${Math.round(decision.route.selectedRisk)}/100</strong></span>`);
    if (Number.isFinite(decision.sourceCount)) items.push(`<span><small>${safe(ui('copilot.metric.sources'))}</small><strong>${Math.round(decision.sourceCount)}</strong></span>`);
    return `<div class="copilot-evidence"><div class="copilot-evidence-head"><svg aria-hidden="true"><use href="#i-shield"></use></svg><strong>${safe(ui('copilot.evidence.title'))}</strong></div><div class="copilot-evidence-grid">${items.join('')}</div></div>`;
}
function addAssistant(role, text, persist = true, meta = {}) {
    text = role === 'assistant' ? assistantPlainText(text) : String(text || '').trim();
    if (!text)
        return null;
    const root = q('#assistant-messages');
    if (!root)
        return null;
    const article = document.createElement('article');
    const source = role === 'assistant' && meta?.source === 'ai' ? 'ai' : 'local';
    const pending = role === 'assistant' && Boolean(meta?.pending);
    article.className = `assistant-message ${role}${source === 'ai' ? ' is-ai' : ''}${pending ? ' is-thinking' : ''}`;
    if (role === 'assistant') {
        article.dataset.source = source;
        const avatarIcon = source === 'ai' ? 'i-spark' : 'i-sun';
        const avatarLabel = source === 'ai' ? ` role="img" aria-label="${safe(ui('assistant.response.ai.aria_label'))}" title="${safe(ui('assistant.response.ai.aria_label'))}"` : ' aria-hidden="true"';
        const body = pending
            ? `<p class="assistant-thinking-text"><span>${safe(text)}</span><span aria-hidden="true" class="assistant-thinking-dots"><i></i><i></i><i></i></span></p>`
            : `<p>${safe(text)}</p>`;
        const sourceBadge = source === 'ai' ? `<span class="assistant-source-badge ai">${safe(ui('assistant.response.source.ai'))}</span>` : '';
        const evidence = source === 'ai' && !pending ? copilotEvidenceHtml(meta?.decision) : '';
        article.innerHTML = `<span class="assistant-avatar${source === 'ai' ? ' ai' : ''}"${avatarLabel}><svg aria-hidden="true"><use href="#${avatarIcon}"></use></svg></span><div class="assistant-message-bubble"><div class="assistant-message-meta"><strong>${safe(ui('assistant.name'))}</strong>${sourceBadge}</div>${body}${evidence}</div>`;
    }
    else {
        article.innerHTML = `<div class="assistant-message-bubble"><div class="assistant-message-meta"><strong>${safe(ui('assistant.you'))}</strong></div><p>${safe(text)}</p></div>`;
    }
    root.append(article);
    root.scrollTop = root.scrollHeight;
    if (persist) {
        const rows = assistantRows();
        const row = { role, text, at: Date.now() };
        if (role === 'assistant') {
            row.source = source;
            if (meta?.provider) row.provider = String(meta.provider).slice(0, 60);
            if (meta?.model) row.model = String(meta.model).slice(0, 120);
            if (meta?.decision?.available) row.decision = meta.decision;
        }
        rows.push(row);
        saveAssistantRows(rows);
    }
    return article;
}
function resetAssistant() {
    sessionStorage.removeItem(KEYS.assistant);
    suite.assistantContext = {};
    suite.aiSwitchPending = null;
    setAssistantMode('local');
    const root = q('#assistant-messages');
    if (root)
        root.innerHTML = '';
    addAssistant('assistant', ui('assistant.welcome'), false);
}
function restoreAssistant() {
    const root = q('#assistant-messages');
    if (!root)
        return;
    root.innerHTML = '';
    setAssistantProviderStatus(suite.assistantMode === 'ai', suite.assistantMode === 'ai' ? ui('assistant.provider.pending') : 'local');
    const rows = assistantRows();
    if (!rows.length) {
        addAssistant('assistant', ui('assistant.welcome'), false);
        return;
    }
    rows.forEach(row => addAssistant(row.role, row.text, false, { source: row.source || 'local', provider: row.provider || '', model: row.model || '', decision: row.decision || null }));
}
    return Object.freeze({
        openAssistantDialog, closeAssistantDialog, assistantRows, saveAssistantRows, setAssistantBusy, copilotEvidenceHtml, addAssistant, resetAssistant, restoreAssistant
    });
}
