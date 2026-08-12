<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>unknown</title>
    <style>
        html, body { width: 100%; height: 100%; margin: 0; overflow: hidden; background: #000; }
        #unknown-message {
            position: fixed;
            left: 50%;
            top: 50%;
            translate: -50% -50%;
            color: #171717;
            font: 13px/1.2 monospace;
            letter-spacing: .04em;
            white-space: nowrap;
            opacity: 0;
            user-select: none;
            -webkit-user-select: none;
            pointer-events: none;
        }
        html.unknown-unlocked #unknown-message {
            animation: unknown-message-in 5.5s ease 700ms forwards;
        }
        @keyframes unknown-message-in {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        #unknown-lock {
            position: fixed;
            left: 50%;
            top: 50%;
            width: 13px;
            height: 11px;
            translate: -50% -50%;
            border: 1px solid #545454;
            box-sizing: border-box;
            opacity: 0;
        }
        #unknown-lock::before {
            content: '';
            position: absolute;
            left: 2px;
            top: -8px;
            width: 7px;
            height: 8px;
            border: 1px solid #545454;
            border-bottom: 0;
            border-radius: 5px 5px 0 0;
            box-sizing: border-box;
        }
        html.unknown-locked #unknown-lock { opacity: 1; }
    </style>
    <script>
        (() => {
            let allowed = false;
            try { allowed = localStorage.getItem('displayAuxUnknownAccess') === '1'; } catch (_) {}
            document.documentElement.classList.add(allowed ? 'unknown-unlocked' : 'unknown-locked');
        })();
    </script>
</head>
<body><span id="unknown-message">come back later</span><span id="unknown-lock" aria-label="locked" role="img"></span></body>
</html>
