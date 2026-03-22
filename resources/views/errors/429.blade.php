<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calma lá! - InovaFinance</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --bg: #0f172a;
            --card-bg: #1e293b;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--bg);
            color: var(--text-main);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .container {
            text-align: center;
            padding: 2rem;
            max-width: 500px;
            width: 100%;
            z-index: 10;
        }

        .icon-wrapper {
            position: relative;
            margin-bottom: 2.5rem;
            display: inline-block;
        }

        .icon {
            font-size: 80px;
            filter: drop-shadow(0 0 20px rgba(99, 102, 241, 0.5));
            animation: pulse 2s infinite ease-in-out;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); filter: drop-shadow(0 0 10px rgba(99, 102, 241, 0.3)); }
            50% { transform: scale(1.1); filter: drop-shadow(0 0 30px rgba(99, 102, 241, 0.7)); }
        }

        h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, #fff 0%, #94a3b8 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        p {
            color: var(--text-muted);
            line-height: 1.6;
            margin-bottom: 2rem;
            font-size: 1.1rem;
        }

        .error-code {
            font-family: monospace;
            background: rgba(255, 255, 255, 0.05);
            padding: 0.25rem 0.75rem;
            border-radius: 6px;
            font-size: 0.9rem;
            color: var(--primary);
            margin-top: 1rem;
            display: inline-block;
        }

        .btn {
            display: inline-block;
            background-color: var(--primary);
            color: white;
            padding: 0.85rem 2rem;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        .btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(99, 102, 241, 0.4);
        }

        .bg-gradient {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.1) 0%, rgba(15, 23, 42, 0) 70%);
            z-index: -1;
        }

        .wait-timer {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-top: 2rem;
            opacity: 0.8;
            height: 1.2rem;
        }
    </style>
</head>
<body>
    <div class="bg-gradient"></div>
    
    <div class="container">
        <div class="icon-wrapper">
            <span class="icon">🚦</span>
        </div>
        
        <h1>Calma lá!</h1>
        <p>Recebemos muitas solicitações em curto tempo. <br> Precisamos de um pequeno fôlego para processar tudo com segurança.</p>
        
        <a href="/dashboard" class="btn">Voltar para o Dashboard</a>
        
        <div class="wait-timer" id="timer">
            Sinal verde em instantes...
        </div>

        <div>
            <span class="error-code">ERRO 429: TOO MANY REQUESTS</span>
        </div>
    </div>

    <script>
        // Opcional: Contador amigável
        let seconds = 60;
        const timerEl = document.getElementById('timer');
        
        const countdown = setInterval(() => {
            seconds--;
            if (seconds > 0) {
                // timerEl.textContent = `Aguarde ${seconds} segundos...`;
            } else {
                timerEl.textContent = "Você já pode tentar novamente!";
                timerEl.style.color = "#22c55e";
                clearInterval(countdown);
            }
        }, 1000);

        // Feedback visual de carregamento nos dots
        const dots = () => {
             const t = timerEl.textContent;
             if (t.endsWith("...")) timerEl.textContent = t.slice(0, -3);
             else timerEl.textContent += ".";
        };
        // setInterval(dots, 500);
    </script>
</body>
</html>
