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
    <title>{{ $status }} | DIUQBank PDF Helper</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-linear-to-br from-amber-50 via-stone-50 to-cyan-100 text-stone-950">
    <div class="relative isolate overflow-hidden">
        <div class="absolute -top-24 left-0 h-72 w-72 rounded-full bg-amber-300/40 blur-3xl"></div>
        <div class="absolute right-0 top-1/3 h-80 w-80 rounded-full bg-cyan-300/35 blur-3xl"></div>

        <main class="mx-auto flex min-h-screen max-w-6xl items-center px-6 py-12">
            <div class="grid w-full gap-8 overflow-hidden rounded-[2rem] border border-stone-900/10 bg-white/85 p-8 shadow-2xl shadow-stone-900/10 backdrop-blur md:grid-cols-[1.15fr_0.85fr] md:p-12">
                <section class="flex flex-col gap-6">
                    <p class="text-xs font-semibold uppercase tracking-[0.35em] text-cyan-700">DIUQBank Helper</p>
                    <div class="flex flex-col gap-3">
                        <p class="text-6xl font-black tracking-tight text-stone-950 md:text-8xl">{{ $status }}</p>
                        <h1 class="max-w-2xl text-3xl font-semibold tracking-tight text-stone-900 md:text-5xl">{{ $title }}</h1>
                        <p class="max-w-2xl text-base leading-7 text-stone-600 md:text-lg">{{ $summary }}</p>
                    </div>

                    <div class="flex flex-col gap-3 sm:flex-row">
                        <a
                            class="inline-flex items-center justify-center rounded-full bg-stone-950 px-6 py-3 text-sm font-semibold text-white transition hover:bg-stone-800"
                            href="{{ route('docs') }}"
                        >
                            Open API docs
                        </a>

                        <a
                            class="inline-flex items-center justify-center rounded-full border border-stone-900/15 bg-white px-6 py-3 text-sm font-semibold text-stone-900 transition hover:border-stone-900/30 hover:bg-stone-50"
                            href="https://diuqbank.com"
                            rel="noreferrer"
                            target="_blank"
                        >
                            Visit diuqbank.com
                        </a>
                    </div>

                    <p class="max-w-2xl text-sm leading-6 text-stone-500">
                        This helper service powers PDF compression and watermarking for the DIU Question Bank ecosystem.
                    </p>
                </section>

                <aside class="flex flex-col justify-between gap-8 rounded-[1.5rem] bg-stone-950 p-6 text-stone-50 md:p-8">
                    <div class="space-y-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.3em] text-amber-300">What this service does</p>
                        <div class="space-y-3 text-sm leading-6 text-stone-300">
                            <p>Compress uploaded PDFs with Ghostscript using the ebook preset.</p>
                            <p>Apply the DIUQBank-style header watermark, then compress the final output.</p>
                            <p>Expose a small backend-only API and a public Scalar reference for integrators.</p>
                        </div>
                    </div>

                    <div class="rounded-[1.25rem] border border-white/10 bg-white/5 p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.25em] text-cyan-300">Recovery path</p>
                        <p class="mt-3 text-sm leading-6 text-stone-300">If you landed here by mistake, start with the API documentation or head back to the main question bank site.</p>
                    </div>
                </aside>
            </div>
        </main>
    </div>
</body>
</html>