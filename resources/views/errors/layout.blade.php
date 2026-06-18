<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="only light">
    <title>@yield('code') — SA Track</title>
    {{-- Fully self-contained: error pages must render even when the asset
         pipeline or app is mid-deploy, so no Vite, no external fonts. --}}
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #020617;
            color: #e2e8f0;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
            overflow: hidden;
        }
        .glow {
            position: fixed;
            width: 480px; height: 480px;
            border-radius: 50%;
            filter: blur(120px);
            opacity: .18;
            pointer-events: none;
        }
        .glow-1 { background: #f97316; top: -160px; right: 10%; }
        .glow-2 { background: #f59e0b; bottom: -200px; left: 15%; opacity: .10; }
        .card { position: relative; text-align: center; padding: 32px; max-width: 560px; }
        .code {
            font-size: 120px;
            font-weight: 800;
            line-height: 1;
            background: linear-gradient(135deg, #fb923c, #f59e0b);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .bar {
            width: 56px; height: 4px;
            margin: 20px auto;
            border-radius: 999px;
            background: linear-gradient(90deg, #fb923c, #f59e0b);
        }
        h1 { font-size: 24px; font-weight: 700; color: #fff; margin-bottom: 10px; }
        p { font-size: 15px; line-height: 1.6; color: #94a3b8; margin-bottom: 28px; }
        a.btn {
            display: inline-block;
            padding: 12px 28px;
            border-radius: 10px;
            background: linear-gradient(90deg, #f97316, #f59e0b);
            color: #fff;
            font-weight: 600;
            font-size: 14px;
            text-decoration: none;
            box-shadow: 0 10px 25px -8px rgba(249, 115, 22, .5);
        }
        a.btn:hover { filter: brightness(1.08); }
        .brand {
            margin-top: 36px;
            font-size: 12px;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: #475569;
        }
    </style>
</head>
<body>
    <div class="glow glow-1"></div>
    <div class="glow glow-2"></div>
    <div class="card">
        <div class="code">@yield('code')</div>
        <div class="bar"></div>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <a class="btn" href="{{ url('/dashboard') }}">Back to Dashboard</a>
        <div class="brand">SA Track · Sparking Asia</div>
    </div>
</body>
</html>
