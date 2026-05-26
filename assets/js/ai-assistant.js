(function () {
    const launcher = document.getElementById('aiChatLauncher');
    const panel = document.getElementById('aiChatPanel');
    const closeBtn = document.getElementById('aiChatClose');
    const body = document.getElementById('aiChatBody');
    const form = document.getElementById('aiChatForm');
    const input = document.getElementById('aiChatInput');
    const sendBtn = document.getElementById('aiChatSend');
    const quickWrap = document.getElementById('aiQuickPrompts');

    if (!launcher || !panel || !body || !form || !input || !sendBtn) return;

    function timeNow() {
        return new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    }

    function scrollToBottom() {
        body.scrollTop = body.scrollHeight;
    }

    function appendMessage(type, text) {
        const row = document.createElement('div');
        row.className = `ai-message-row ${type}`;

        const bubble = document.createElement('div');
        bubble.className = 'ai-bubble';
        bubble.textContent = text;

        const time = document.createElement('span');
        time.className = 'ai-time';
        time.textContent = timeNow();
        bubble.appendChild(time);

        row.appendChild(bubble);
        body.appendChild(row);
        scrollToBottom();
    }

    function showTyping() {
        const row = document.createElement('div');
        row.className = 'ai-message-row assistant';
        row.id = 'aiTypingRow';

        const bubble = document.createElement('div');
        bubble.className = 'ai-bubble';

        const dots = document.createElement('span');
        dots.className = 'ai-typing';
        for (let i = 0; i < 3; i++) dots.appendChild(document.createElement('span'));

        bubble.appendChild(dots);
        row.appendChild(bubble);
        body.appendChild(row);
        scrollToBottom();
    }

    function hideTyping() {
        const row = document.getElementById('aiTypingRow');
        if (row) row.remove();
    }

    function setSuggestions(suggestions) {
        if (!quickWrap || !Array.isArray(suggestions)) return;
        quickWrap.innerHTML = '';
        suggestions.slice(0, 5).forEach((suggestion) => {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'ai-chip';
            chip.textContent = suggestion;
            chip.addEventListener('click', () => sendMessage(suggestion));
            quickWrap.appendChild(chip);
        });
    }

    async function sendMessage(message) {
        const text = (message || input.value || '').trim();
        if (!text) return;

        appendMessage('user', text);
        input.value = '';
        input.style.height = '';
        sendBtn.disabled = true;
        showTyping();

        try {
            const response = await fetch('ai_assistant.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ message: text })
            });

            const data = await response.json();
            hideTyping();

            if (!response.ok || !data || typeof data.reply !== 'string') {
                appendMessage('assistant', 'I could not process that request right now. Please try again.');
                return;
            }

            appendMessage('assistant', data.reply);
            setSuggestions(data.suggestions || []);
        } catch (error) {
            hideTyping();
            appendMessage('assistant', 'I had trouble connecting to the local assistant. Please refresh the page and try again.');
        } finally {
            sendBtn.disabled = false;
            input.focus();
        }
    }

    function openChat() {
        panel.classList.add('open');
        panel.setAttribute('aria-hidden', 'false');
        launcher.style.display = 'none';
        setTimeout(() => input.focus(), 80);
    }

    function closeChat() {
        panel.classList.remove('open');
        panel.setAttribute('aria-hidden', 'true');
        launcher.style.display = 'inline-flex';
    }

    launcher.addEventListener('click', openChat);
    closeBtn && closeBtn.addEventListener('click', closeChat);

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        sendMessage();
    });

    input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            sendMessage();
        }
    });

    input.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 104) + 'px';
    });

    document.querySelectorAll('[data-ai-prompt]').forEach((chip) => {
        chip.addEventListener('click', () => sendMessage(chip.getAttribute('data-ai-prompt') || chip.textContent));
    });

    appendMessage('assistant', 'Hi! I am your local 3ZERO assistant. Ask me about your clubs, profile completion, notifications, events, projects, achievements, or how to register a club.');
})();
