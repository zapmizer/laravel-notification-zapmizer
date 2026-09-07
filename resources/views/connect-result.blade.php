<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WhatsApp connection</title>
    <style>
        body {
            font-family: system-ui, -apple-system, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            background: #f8fafc;
            color: #0f172a;
        }

        main {
            max-width: 24rem;
            padding: 2rem;
            text-align: center;
        }

        p.hint {
            color: #64748b;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
    <main>
        <p>{{ $message }}</p>
        <p class="hint">You can close this window and go back to the {{ config('app.name') }} tab.</p>
    </main>

    <script>
        (function () {
            var payload = {
                source: 'zapmizer-connect',
                status: @json($status),
                message: @json($message),
            };

            // Popup opened outside the flow (null opener): the text above
            // tells the user to go back to the tab by hand.
            if (window.opener) {
                window.opener.postMessage(payload, window.location.origin);
                window.close();
            }
        })();
    </script>
</body>
</html>
