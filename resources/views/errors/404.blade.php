<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Página não encontrada - InovaFinance</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --bg: #0f172a;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

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

        .container { text-align: center; padding: 2rem; max-width: 500px; width: 100%; z-index: 10; }

        .icon-wrapper { margin-bottom: 2rem; display: inline-block; }

        .icon { font-size: 100px; display: block; filter: drop-shadow(0 0 20px rgba(99, 102, 241, 0.4)); animation: float 3s infinite ease-in-out; }

        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-15px); }
        }

        h1 { font-size: 3rem; font-weight: 700; margin-bottom: 0.5rem; background: linear-gradient(135deg, #fff 0%, #94a3b8 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        
        p { color: var(--text-muted); line-height: 1.6; margin-bottom: 2.5rem; font-size: 1.1rem; }

        .btn { display: inline-block; background-color: var(--primary); color: white; padding: 0.85rem 2.5rem; border-radius: 12px; text-decoration: none; font-weight: 600; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }

        .btn:hover { background-color: var(--primary-dark); transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(99, 102, 241, 0.4); }

        .error-code { font-family: monospace; opacity: 0.2; font-size: 0.8rem; margin-top: 3rem; display: block; }
        
        .bg-gradient { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 600px; height: 600px; background: radial-gradient(circle, rgba(99, 102, 241, 0.08) 0%, rgba(15, 23, 42, 0) 70%); z-index: -1; }
    </style>
</head>
<body>
    <div class="bg-gradient"></div>
    <div class="container">
        <div class="icon-wrapper"><span class="icon">🔍</span></div>
        <h1>Oops!</h1>
        <p>Parece que essa página se perdeu pelo caminho ou nunca existiu.</p>
        <a href="/dashboard" class="btn">Voltar ao Início</a>
        <span class="error-code">404 - NOT FOUND</span>
    </div>
</body>
</html>
