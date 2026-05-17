@props([
    'status',
    'title',
    'summary',
])

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $status }} | DIUQBank PDF Processor</title>
    <style>
        :root {
            color-scheme: light dark;
            --page-bg: #f6f6f7;
            --panel-bg: #ffffff;
            --panel-border: #e6e7eb;
            --text: #111827;
            --muted: #6b7280;
            --soft: #9ca3af;
            --accent: #111827;
            --accent-contrast: #ffffff;
            --accent-hover: #1f2937;
            --accent-hover-contrast: #ffffff;
            --accent-soft: #f3f4f6;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --page-bg: #0f1115;
                --panel-bg: #171a20;
                --panel-border: #2a2f39;
                --text: #f3f4f6;
                --muted: #b0b6c3;
                --soft: #7d8596;
                --accent: #f3f4f6;
                --accent-contrast: #111827;
                --accent-hover: #e5e7eb;
                --accent-hover-contrast: #111827;
                --accent-soft: #20242c;
            }
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            min-height: 100%;
            background: var(--page-bg);
            color: var(--text);
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        body {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 20px;
        }

        .shell {
            width: min(760px, 100%);
        }

        .panel {
            border: 1px solid var(--panel-border);
            border-radius: 20px;
            background: var(--panel-bg);
            padding: 28px;
            box-shadow: 0 1px 2px rgba(17, 24, 39, 0.04);
        }

        .brand {
            margin: 0 0 10px;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text);
        }

        .status {
            margin: 0 0 20px;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--soft);
        }

        .title {
            margin: 0;
            font-size: clamp(2rem, 4vw, 3rem);
            line-height: 1.02;
            letter-spacing: -0.04em;
        }

        .summary {
            margin: 16px 0 0;
            max-width: 58ch;
            font-size: 1rem;
            line-height: 1.7;
            color: var(--muted);
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 28px;
        }

        .link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 0 18px;
            border-radius: 999px;
            border: 1px solid var(--panel-border);
            color: var(--text);
            text-decoration: none;
            font-size: 0.95rem;
            font-weight: 600;
            transition: background-color 120ms ease, border-color 120ms ease, color 120ms ease;
        }

        .link.primary {
            border-color: var(--accent);
            background: var(--accent);
            color: var(--accent-contrast);
        }

        .link.secondary {
            background: var(--accent-soft);
        }

        .link:hover {
            border-color: #cfd3da;
            background: #eeeeef;
        }

        .link.primary:hover {
            border-color: var(--accent-hover);
            background: var(--accent-hover);
            color: var(--accent-hover-contrast);
        }

        .meta {
            margin-top: 28px;
            padding-top: 18px;
            border-top: 1px solid var(--panel-border);
            font-size: 0.92rem;
            line-height: 1.7;
            color: var(--muted);
        }

        @media (max-width: 640px) {
            body {
                padding: 20px 16px;
            }

            .panel {
                border-radius: 16px;
                padding: 22px;
            }

            .actions {
                flex-direction: column;
            }

            .link {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <main class="shell">
        <section class="panel">
            <p class="brand">DIUQBank PDF Processor</p>
            <p class="status">{{ $status }}</p>
            <h1 class="title">{{ $title }}</h1>
            <p class="summary">{{ $summary }}</p>

            <div class="actions">
                <a class="link primary" href="{{ route('docs') }}">Open API docs</a>
                <a class="link secondary" href="https://diuqbank.com" rel="noreferrer" target="_blank">Visit diuqbank.com</a>
            </div>

            <p class="meta">
                This processor service powers PDF compression and watermarking for the DIU Question Bank platform.
            </p>
        </section>
    </main>
</body>
</html>