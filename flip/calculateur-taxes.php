<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Taxes QC">
    <meta name="theme-color" content="#1a1a2e">
    <meta name="description" content="Calculateur TPS/TVQ Québec - Simple et rapide">
    <title>Taxes Québec | TPS TVQ</title>

    <!-- PWA Manifest -->
    <link rel="manifest" href="manifest-taxes.json">
    <link rel="apple-touch-icon" href="assets/images/tax-icon-192.png">
    <link rel="icon" type="image/png" href="assets/images/tax-icon-192.png">

    <style>
        :root {
            --bg-dark: #0f0f1a;
            --bg-card: #1a1a2e;
            --bg-input: #252542;
            --accent: #4361ee;
            --accent-light: #4cc9f0;
            --accent-glow: rgba(67, 97, 238, 0.3);
            --text-primary: #ffffff;
            --text-secondary: #a0a0b8;
            --text-muted: #6c6c8a;
            --border: #2d2d4a;
            --success: #06d6a0;
            --danger: #ef476f;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }

        html, body {
            height: 100%;
            overflow: hidden;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-dark);
            color: var(--text-primary);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            min-height: -webkit-fill-available;
            padding: 20px;
            padding-top: env(safe-area-inset-top, 20px);
            padding-bottom: env(safe-area-inset-bottom, 20px);
        }

        .calculator {
            width: 100%;
            max-width: 400px;
            animation: fadeIn 0.5s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Header */
        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--accent) 0%, var(--accent-light) 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            box-shadow: 0 10px 40px var(--accent-glow);
        }

        .logo svg {
            width: 40px;
            height: 40px;
            fill: white;
        }

        .title {
            font-size: 1.6rem;
            font-weight: 700;
            letter-spacing: -0.5px;
            margin-bottom: 5px;
        }

        .subtitle {
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        /* Mode Toggle */
        .mode-toggle {
            display: flex;
            background: var(--bg-card);
            border-radius: 16px;
            padding: 5px;
            margin-bottom: 25px;
            border: 1px solid var(--border);
        }

        .mode-btn {
            flex: 1;
            padding: 14px 10px;
            border: none;
            background: transparent;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .mode-btn .icon {
            font-size: 1.2rem;
        }

        .mode-btn.active {
            background: var(--accent);
            color: white;
            box-shadow: 0 4px 20px var(--accent-glow);
        }

        /* Input Section */
        .input-section {
            background: var(--bg-card);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 20px;
            border: 1px solid var(--border);
        }

        .input-label {
            display: block;
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .input-wrapper {
            display: flex;
            align-items: center;
            background: var(--bg-input);
            border: 2px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
            transition: all 0.3s;
        }

        .input-wrapper:focus-within {
            border-color: var(--accent);
            box-shadow: 0 0 0 4px var(--accent-glow);
        }

        .amount-input {
            flex: 1;
            border: none;
            background: transparent;
            padding: 20px;
            font-size: 2rem;
            font-weight: 700;
            text-align: right;
            outline: none;
            color: var(--text-primary);
            width: 100%;
            letter-spacing: -1px;
        }

        .amount-input::placeholder {
            color: var(--text-muted);
        }

        .currency {
            padding: 20px;
            font-size: 1.5rem;
            color: var(--text-muted);
            font-weight: 600;
        }

        /* Results */
        .results {
            background: var(--bg-card);
            border-radius: 20px;
            padding: 20px;
            border: 1px solid var(--border);
        }

        .result-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 5px;
            border-bottom: 1px solid var(--border);
        }

        .result-row:last-child {
            border-bottom: none;
            padding-top: 20px;
            margin-top: 5px;
        }

        .result-label {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .result-label .name {
            color: var(--text-secondary);
            font-size: 0.95rem;
            font-weight: 500;
        }

        .result-label .rate {
            color: var(--text-muted);
            font-size: 0.75rem;
        }

        .result-value {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 1.1rem;
            font-variant-numeric: tabular-nums;
        }

        .result-row.total {
            background: linear-gradient(135deg, var(--accent) 0%, var(--accent-light) 100%);
            margin: 15px -20px -20px;
            padding: 25px;
            border-radius: 0 0 20px 20px;
        }

        .result-row.total .result-label .name {
            color: rgba(255,255,255,0.8);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .result-row.total .result-value {
            font-size: 1.8rem;
            font-weight: 800;
            color: white;
            letter-spacing: -1px;
        }

        /* Footer info */
        .footer {
            text-align: center;
            margin-top: 25px;
            color: var(--text-muted);
            font-size: 0.8rem;
        }

        .footer .rates {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 8px;
        }

        .footer .rate-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .footer .rate-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--accent);
        }

        .footer .shortcuts {
            opacity: 0.6;
            font-size: 0.75rem;
        }

        /* Install Button */
        .install-btn {
            display: none;
            width: 100%;
            margin-top: 20px;
            padding: 16px;
            background: linear-gradient(135deg, var(--success) 0%, #00b894 100%);
            color: white;
            border: none;
            border-radius: 16px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 20px rgba(6, 214, 160, 0.3);
        }

        .install-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(6, 214, 160, 0.4);
        }

        .install-btn:active {
            transform: translateY(0);
        }

        .install-btn.show {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        /* Animations */
        .result-value {
            transition: all 0.2s;
        }

        .result-value.updated {
            transform: scale(1.05);
            color: var(--accent-light);
        }

        /* Responsive */
        @media (max-height: 650px) {
            .header { margin-bottom: 15px; }
            .logo { width: 50px; height: 50px; }
            .logo svg { width: 28px; height: 28px; }
            .title { font-size: 1.3rem; }
            .mode-btn { padding: 10px; }
            .input-section { padding: 15px; }
            .amount-input { font-size: 1.6rem; padding: 15px; }
            .result-row { padding: 10px 5px; }
            .result-row.total { padding: 15px; }
            .result-row.total .result-value { font-size: 1.4rem; }
        }

        /* Standalone mode adjustments */
        @media (display-mode: standalone) {
            body {
                padding-top: calc(env(safe-area-inset-top, 20px) + 10px);
            }
            .install-btn {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <div class="calculator">
        <div class="header">
            <div class="logo">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 14c-3.31 0-6-2.69-6-6s2.69-6 6-6 6 2.69 6 6-2.69 6-6 6zm1-9h-2v3H8v2h3v3h2v-3h3v-2h-3V8z"/>
                </svg>
            </div>
            <h1 class="title">Taxes Québec</h1>
            <p class="subtitle">TPS & TVQ | Calculateur rapide</p>
        </div>

        <div class="mode-toggle">
            <button class="mode-btn active" data-mode="add">
                <span class="icon">+</span>
                <span>Ajouter</span>
            </button>
            <button class="mode-btn" data-mode="remove">
                <span class="icon">−</span>
                <span>Retirer</span>
            </button>
        </div>

        <div class="input-section">
            <label class="input-label" id="inputLabel">Montant avant taxes</label>
            <div class="input-wrapper">
                <input type="text"
                       class="amount-input"
                       id="amount"
                       placeholder="0.00"
                       inputmode="decimal"
                       autocomplete="off"
                       autofocus>
                <span class="currency">$</span>
            </div>
        </div>

        <div class="results">
            <div class="result-row">
                <div class="result-label">
                    <span class="name">TPS</span>
                    <span class="rate">Taxe fédérale 5%</span>
                </div>
                <span class="result-value" id="tps">0.00 $</span>
            </div>
            <div class="result-row">
                <div class="result-label">
                    <span class="name">TVQ</span>
                    <span class="rate">Taxe provinciale 9.975%</span>
                </div>
                <span class="result-value" id="tvq">0.00 $</span>
            </div>
            <div class="result-row total">
                <div class="result-label">
                    <span class="name" id="totalLabel">Total avec taxes</span>
                </div>
                <span class="result-value" id="total">0.00 $</span>
            </div>
        </div>

        <div class="footer">
            <div class="rates">
                <div class="rate-item">
                    <span class="rate-dot"></span>
                    <span>TPS 5%</span>
                </div>
                <div class="rate-item">
                    <span class="rate-dot"></span>
                    <span>TVQ 9.975%</span>
                </div>
                <div class="rate-item">
                    <span class="rate-dot"></span>
                    <span>Total 14.975%</span>
                </div>
            </div>
            <div class="shortcuts">Tab = mode | Esc = effacer</div>
        </div>

        <button class="install-btn" id="installBtn">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                <path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/>
            </svg>
            Installer l'application
        </button>
    </div>

    <script>
        const TPS_RATE = 0.05;
        const TVQ_RATE = 0.09975;
        let currentMode = 'add';
        let deferredPrompt = null;

        // Format money with thousands separator
        function formatMoney(num) {
            return num.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' $';
        }

        // Parse amount from string
        function parseAmount(str) {
            if (!str) return 0;
            return parseFloat(str.replace(/[^\d.,\-]/g, '').replace(',', '.')) || 0;
        }

        // Animate value update
        function animateValue(element) {
            element.classList.add('updated');
            setTimeout(() => element.classList.remove('updated'), 200);
        }

        // Calculate taxes
        function calculate() {
            const amount = parseAmount(document.getElementById('amount').value);
            let subtotal, tps, tvq, total;

            if (currentMode === 'add') {
                subtotal = amount;
                tps = subtotal * TPS_RATE;
                tvq = subtotal * TVQ_RATE;
                total = subtotal + tps + tvq;

                document.getElementById('inputLabel').textContent = 'Montant avant taxes';
                document.getElementById('totalLabel').textContent = 'Total avec taxes';
                document.getElementById('total').textContent = formatMoney(total);
            } else {
                total = amount;
                subtotal = total / (1 + TPS_RATE + TVQ_RATE);
                tps = subtotal * TPS_RATE;
                tvq = subtotal * TVQ_RATE;

                document.getElementById('inputLabel').textContent = 'Montant avec taxes';
                document.getElementById('totalLabel').textContent = 'Montant sans taxes';
                document.getElementById('total').textContent = formatMoney(subtotal);
            }

            const tpsEl = document.getElementById('tps');
            const tvqEl = document.getElementById('tvq');
            const totalEl = document.getElementById('total');

            tpsEl.textContent = formatMoney(tps);
            tvqEl.textContent = formatMoney(tvq);

            // Animate updates
            if (amount > 0) {
                animateValue(tpsEl);
                animateValue(tvqEl);
                animateValue(totalEl);
            }
        }

        // Mode toggle handlers
        document.querySelectorAll('.mode-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                if (this.classList.contains('active')) return;

                document.querySelectorAll('.mode-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                currentMode = this.dataset.mode;
                calculate();

                // Haptic feedback if available
                if (navigator.vibrate) navigator.vibrate(10);
            });
        });

        // Input handler
        const amountInput = document.getElementById('amount');
        amountInput.addEventListener('input', calculate);

        // Keyboard shortcuts
        amountInput.addEventListener('keydown', function(e) {
            if (e.key === 'Tab') {
                e.preventDefault();
                currentMode = currentMode === 'add' ? 'remove' : 'add';
                document.querySelectorAll('.mode-btn').forEach(b => {
                    b.classList.toggle('active', b.dataset.mode === currentMode);
                });
                calculate();
            }
            if (e.key === 'Escape') {
                this.value = '';
                calculate();
            }
            // Enter to select all
            if (e.key === 'Enter') {
                this.select();
            }
        });

        // PWA Install prompt
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            document.getElementById('installBtn').classList.add('show');
        });

        document.getElementById('installBtn').addEventListener('click', async () => {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                const { outcome } = await deferredPrompt.userChoice;
                if (outcome === 'accepted') {
                    document.getElementById('installBtn').classList.remove('show');
                }
                deferredPrompt = null;
            }
        });

        // iOS detection and install instructions
        const isIOS = /iPhone|iPad|iPod/.test(navigator.userAgent) && !window.MSStream;
        const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;

        if (isIOS && !isStandalone) {
            const installBtn = document.getElementById('installBtn');
            installBtn.classList.add('show');
            installBtn.innerHTML = `
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M16 5l-1.42 1.42-1.59-1.59V16h-2V4.83L9.42 6.42 8 5l4-4 4 4zm4 5v11c0 1.1-.9 2-2 2H6c-1.1 0-2-.9-2-2V10c0-1.1.9-2 2-2h3v2H6v11h12V10h-3V8h3c1.1 0 2 .9 2 2z"/>
                </svg>
                Appuyer sur Partager puis "Écran d'accueil"
            `;
            installBtn.addEventListener('click', () => {
                alert('Pour installer sur iPhone/iPad:\n\n1. Appuyez sur le bouton Partager ⬆️\n2. Défilez et appuyez sur "Sur l\'écran d\'accueil"\n3. Appuyez sur "Ajouter"');
            });
        }

        // Register service worker for offline support
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw-taxes.js').catch(() => {});
        }

        // Focus input on load
        setTimeout(() => amountInput.focus(), 100);

        // Prevent zoom on double tap
        document.addEventListener('dblclick', (e) => e.preventDefault());
    </script>
</body>
</html>
