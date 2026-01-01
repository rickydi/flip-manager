<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Taxes QC">
    <meta name="theme-color" content="#0d6efd">
    <title>Calculateur Taxes Québec</title>

    <!-- PWA Manifest -->
    <link rel="manifest" href="manifest-taxes.json">
    <link rel="apple-touch-icon" href="assets/images/icon-192.png">

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .calculator {
            background: white;
            border-radius: 20px;
            padding: 25px;
            width: 100%;
            max-width: 360px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }

        h1 {
            text-align: center;
            color: #333;
            font-size: 1.4rem;
            margin-bottom: 20px;
        }

        h1 i {
            color: #667eea;
        }

        .mode-toggle {
            display: flex;
            background: #f0f0f0;
            border-radius: 10px;
            padding: 4px;
            margin-bottom: 20px;
        }

        .mode-btn {
            flex: 1;
            padding: 12px;
            border: none;
            background: transparent;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            color: #666;
        }

        .mode-btn.active {
            background: white;
            color: #667eea;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .input-group {
            margin-bottom: 20px;
        }

        .input-label {
            display: block;
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 8px;
        }

        .input-wrapper {
            display: flex;
            align-items: center;
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            overflow: hidden;
            transition: border-color 0.3s;
        }

        .input-wrapper:focus-within {
            border-color: #667eea;
        }

        .amount-input {
            flex: 1;
            border: none;
            background: transparent;
            padding: 16px;
            font-size: 1.5rem;
            font-weight: 600;
            text-align: right;
            outline: none;
            width: 100%;
        }

        .currency {
            padding: 16px;
            font-size: 1.2rem;
            color: #999;
            font-weight: 600;
        }

        .results {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 15px;
        }

        .result-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e9ecef;
        }

        .result-row:last-child {
            border-bottom: none;
            padding-top: 15px;
            margin-top: 5px;
        }

        .result-label {
            color: #666;
            font-size: 0.95rem;
        }

        .result-label small {
            color: #999;
        }

        .result-value {
            font-weight: 600;
            color: #333;
        }

        .result-row.total .result-label {
            font-weight: 700;
            color: #333;
        }

        .result-row.total .result-value {
            font-size: 1.4rem;
            color: #667eea;
        }

        .rates-info {
            text-align: center;
            margin-top: 20px;
            padding: 12px;
            background: #e8f4fd;
            border-radius: 10px;
            font-size: 0.8rem;
            color: #0066cc;
        }

        .install-btn {
            display: none;
            width: 100%;
            margin-top: 15px;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .install-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }

        .install-btn.show {
            display: block;
        }

        /* Clear button */
        .clear-btn {
            position: absolute;
            right: 50px;
            background: #ddd;
            border: none;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            font-size: 14px;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            color: #666;
        }

        .input-wrapper {
            position: relative;
        }

        .has-value .clear-btn {
            display: flex;
        }
    </style>
</head>
<body>
    <div class="calculator">
        <h1><i>🧮</i> Taxes Québec</h1>

        <div class="mode-toggle">
            <button class="mode-btn active" data-mode="add">+ Ajouter taxes</button>
            <button class="mode-btn" data-mode="remove">− Retirer taxes</button>
        </div>

        <div class="input-group">
            <label class="input-label" id="inputLabel">Montant avant taxes</label>
            <div class="input-wrapper">
                <input type="text" class="amount-input" id="amount" placeholder="0.00" inputmode="decimal" autocomplete="off">
                <span class="currency">$</span>
            </div>
        </div>

        <div class="results">
            <div class="result-row">
                <span class="result-label">TPS <small>(5%)</small></span>
                <span class="result-value" id="tps">0.00 $</span>
            </div>
            <div class="result-row">
                <span class="result-label">TVQ <small>(9.975%)</small></span>
                <span class="result-value" id="tvq">0.00 $</span>
            </div>
            <div class="result-row total">
                <span class="result-label" id="totalLabel">Total avec taxes</span>
                <span class="result-value" id="total">0.00 $</span>
            </div>
        </div>

        <div class="rates-info">
            TPS: 5% | TVQ: 9.975% | Total: 14.975%
            <br><small style="opacity:0.7">Tab = changer mode | Esc = effacer</small>
        </div>

        <button class="install-btn" id="installBtn">
            📲 Installer sur l'écran d'accueil
        </button>
    </div>

    <script>
        const TPS_RATE = 0.05;
        const TVQ_RATE = 0.09975;
        let currentMode = 'add';
        let deferredPrompt;

        // Format money
        function formatMoney(num) {
            return num.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' $';
        }

        // Parse amount
        function parseAmount(str) {
            if (!str) return 0;
            return parseFloat(str.replace(/[^\d.,\-]/g, '').replace(',', '.')) || 0;
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
                document.getElementById('totalLabel').textContent = 'Montant avant taxes';
                document.getElementById('total').textContent = formatMoney(subtotal);
            }

            document.getElementById('tps').textContent = formatMoney(tps);
            document.getElementById('tvq').textContent = formatMoney(tvq);
        }

        // Mode toggle
        document.querySelectorAll('.mode-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.mode-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                currentMode = this.dataset.mode;
                calculate();
            });
        });

        // Input handler
        document.getElementById('amount').addEventListener('input', calculate);

        // Keyboard support for desktop
        document.getElementById('amount').addEventListener('keydown', function(e) {
            // Tab to switch mode
            if (e.key === 'Tab') {
                e.preventDefault();
                currentMode = currentMode === 'add' ? 'remove' : 'add';
                document.querySelectorAll('.mode-btn').forEach(b => {
                    b.classList.toggle('active', b.dataset.mode === currentMode);
                });
                calculate();
            }
            // Escape to clear
            if (e.key === 'Escape') {
                this.value = '';
                calculate();
            }
        });

        // PWA Install
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

        // iOS Install instructions
        if (navigator.standalone === false || (navigator.userAgent.match(/iPhone|iPad|iPod/) && !window.navigator.standalone)) {
            // Show install button with different behavior for iOS
            const installBtn = document.getElementById('installBtn');
            installBtn.classList.add('show');
            installBtn.textContent = '📲 Ajouter à l\'écran: ⬆️ puis "Sur l\'écran d\'accueil"';
            installBtn.addEventListener('click', () => {
                alert('Pour installer:\n\n1. Appuyez sur le bouton Partager ⬆️\n2. Faites défiler et appuyez sur "Sur l\'écran d\'accueil"\n3. Appuyez sur "Ajouter"');
            });
        }

        // Focus input on load
        document.getElementById('amount').focus();
    </script>
</body>
</html>
