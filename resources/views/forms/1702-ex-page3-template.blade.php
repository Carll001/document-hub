<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>1702-EX Page 3 Template Alignment</title>
        <style>
            :root {
                color-scheme: light;
                --bg: #edf2f7;
                --card: #ffffff;
                --border: #d7dee8;
                --text: #0f172a;
                --marker-fill: #6e6e6e;
                --muted: #475569;
                --accent: #0f766e;
                --accent-soft: #ccfbf1;
                --outline: rgba(14, 116, 144, 0.72);
                --outline-soft: rgba(14, 165, 233, 0.12);
            }

            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                background: linear-gradient(180deg, #f8fafc 0%, var(--bg) 100%);
                color: var(--text);
                font-family:
                    'Instrument Sans', ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont,
                    'Segoe UI', sans-serif;
            }

            a {
                color: inherit;
                text-decoration: none;
            }

            code {
                font-family:
                    'Cascadia Code', 'Fira Code', 'SFMono-Regular', Consolas, 'Liberation Mono',
                    monospace;
                font-size: 0.92em;
            }

            .alignment-shell {
                width: min(1500px, calc(100% - 32px));
                margin: 0 auto;
                padding: 24px 0 40px;
            }

            .alignment-card {
                border: 1px solid var(--border);
                border-radius: 24px;
                background: var(--card);
                box-shadow: 0 24px 60px rgba(15, 23, 42, 0.08);
            }

            .alignment-header {
                padding: 28px;
                display: flex;
                flex-wrap: wrap;
                gap: 20px;
                align-items: flex-end;
                justify-content: space-between;
            }

            .alignment-kicker {
                margin: 0 0 8px;
                font-size: 12px;
                font-weight: 700;
                letter-spacing: 0.24em;
                text-transform: uppercase;
                color: var(--accent);
            }

            .alignment-title {
                margin: 0;
                font-size: clamp(28px, 3.4vw, 42px);
                line-height: 1.05;
            }

            .alignment-copy {
                max-width: 880px;
                margin: 12px 0 0;
                color: var(--muted);
                line-height: 1.65;
            }

            .alignment-actions {
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
            }

            .alignment-actions form {
                margin: 0;
            }

            .alignment-action {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-height: 44px;
                padding: 0 18px;
                border-radius: 999px;
                border: 1px solid var(--border);
                background: #f8fafc;
                color: var(--text);
                font-size: 14px;
                font-weight: 600;
                cursor: pointer;
            }

            button.alignment-action {
                font: inherit;
            }

            .alignment-action--primary {
                border-color: #0f172a;
                background: #0f172a;
                color: #ffffff;
            }

            .alignment-grid {
                display: grid;
                grid-template-columns: minmax(0, 1.45fr) minmax(320px, 420px);
                gap: 24px;
                margin-top: 24px;
                align-items: start;
            }

            .alignment-flash-stack {
                display: grid;
                gap: 14px;
                margin-top: 20px;
            }

            .alignment-flash {
                padding: 16px 18px;
                border-radius: 18px;
                border: 1px solid var(--border);
                background: #f8fafc;
                color: var(--text);
                line-height: 1.55;
            }

            .alignment-flash--success {
                border-color: #99f6e4;
                background: #f0fdfa;
            }

            .alignment-flash--error {
                border-color: #fecaca;
                background: #fef2f2;
            }

            .alignment-main,
            .alignment-sidebar-card {
                padding: 24px;
            }

            .alignment-section-title {
                margin: 0 0 8px;
                font-size: 20px;
                font-weight: 700;
            }

            .alignment-section-copy {
                margin: 0 0 18px;
                color: var(--muted);
                line-height: 1.6;
            }

            .alignment-sidebar {
                display: grid;
                gap: 20px;
            }

            .alignment-note-list,
            .alignment-payload-list {
                display: grid;
                gap: 12px;
            }

            .alignment-guide-list {
                display: grid;
                gap: 14px;
            }

            .alignment-note {
                padding: 14px 16px;
                border-radius: 18px;
                background: #f8fafc;
                border: 1px solid var(--border);
            }

            .alignment-note strong,
            .alignment-payload-list dt {
                display: block;
                margin-bottom: 6px;
                font-size: 13px;
                font-weight: 700;
                color: var(--text);
            }

            .alignment-note span,
            .alignment-payload-list dd {
                margin: 0;
                font-size: 13px;
                color: var(--muted);
                line-height: 1.5;
                overflow-wrap: anywhere;
            }

            .alignment-payload-item {
                padding: 12px 14px;
                border-radius: 16px;
                border: 1px solid var(--border);
                background: #fcfdff;
            }

            .alignment-guide-item {
                padding: 14px;
                border-radius: 18px;
                border: 1px solid var(--border);
                background: #fcfdff;
            }

            .alignment-guide-meta {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                margin-bottom: 10px;
            }

            .alignment-guide-badge {
                display: inline-flex;
                align-items: center;
                min-height: 24px;
                padding: 0 10px;
                border-radius: 999px;
                background: #e2e8f0;
                color: #1e293b;
                font-size: 12px;
                font-weight: 700;
                letter-spacing: 0.02em;
            }

            .alignment-guide-badge--section {
                background: var(--accent-soft);
                color: #115e59;
            }

            .alignment-guide-title {
                margin: 0;
                font-size: 14px;
                font-weight: 700;
            }

            .alignment-guide-key {
                margin: 4px 0 12px;
                color: var(--muted);
                font-size: 12px;
            }

            .alignment-guide-values {
                margin: 0;
                padding: 12px;
                border-radius: 14px;
                background: #f8fafc;
                border: 1px solid var(--border);
                overflow: auto;
                font-family:
                    'Cascadia Code', 'Fira Code', 'SFMono-Regular', Consolas, 'Liberation Mono',
                    monospace;
                font-size: 12px;
                line-height: 1.55;
                color: var(--text);
            }

            .alignment-stage {
                overflow: auto;
                padding-bottom: 8px;
            }

            .alignment-page {
                position: relative;
                width: min(100%, 1120px);
                margin: 0 auto;
                aspect-ratio: var(--page-width) / var(--page-height);
                overflow: hidden;
                border-radius: 24px;
                background: white;
                box-shadow:
                    0 24px 60px rgba(15, 23, 42, 0.14),
                    inset 0 0 0 1px rgba(148, 163, 184, 0.28);
                container-type: inline-size;
            }

            .alignment-page__background {
                position: absolute;
                inset: 0;
                width: 100%;
                height: 100%;
                object-fit: cover;
                user-select: none;
                pointer-events: none;
            }

            .alignment-page__field {
                position: absolute;
                display: flex;
                align-items: center;
                overflow: hidden;
                color: var(--text);
                font-family:
                    'Arial Narrow', Arial, 'Liberation Sans Narrow', 'Liberation Sans', sans-serif;
                font-weight: 600;
                font-stretch: condensed;
                letter-spacing: 0;
                line-height: 1;
                white-space: nowrap;
                background: var(--outline-soft);
                outline: 1px dashed var(--outline);
                outline-offset: -1px;
                border-radius: 3px;
            }

            .alignment-page__field--text {
                align-items: flex-start;
                padding-inline: 0;
            }

            .alignment-page__field--checkbox {
                justify-content: center;
                overflow: visible;
            }

            .alignment-page__field--split {
                display: grid;
                grid-template-columns: repeat(var(--field-box-count), minmax(0, 1fr));
                gap: var(--field-box-gap, 0cqw);
                align-items: stretch;
                padding: 0;
            }

            .alignment-page__checkbox-marker {
                width: var(--checkbox-marker-size, 1em);
                height: var(--checkbox-marker-size, 1em);
                flex: 0 0 auto;
                border-radius: 999px;
                background: var(--marker-fill);
            }

            .alignment-page__split-character {
                display: grid;
                place-items: center;
                min-width: 0;
                background: rgba(255, 255, 255, 0.55);
            }

            @media (max-width: 1180px) {
                .alignment-grid {
                    grid-template-columns: 1fr;
                }
            }

            @media (max-width: 720px) {
                .alignment-shell {
                    width: min(100% - 20px, 1500px);
                    padding-top: 16px;
                }

                .alignment-header,
                .alignment-main,
                .alignment-sidebar-card {
                    padding: 20px;
                }
            }
        </style>
    </head>
    <body>
        @php
            $generatedAt = !empty($latestExport['generatedAt'] ?? null)
                ? \Illuminate\Support\Carbon::parse($latestExport['generatedAt'])->format('M j, Y g:i A')
                : null;
            $fileSize = !empty($latestExport['fileSize'] ?? null)
                ? number_format(((int) $latestExport['fileSize']) / 1024, 1) . ' KB'
                : null;
            $viewErrors = $errors ?? new \Illuminate\Support\ViewErrorBag();
        @endphp

        <div class="alignment-shell">
            <section class="alignment-card">
                <div class="alignment-header">
                    <div>
                        <p class="alignment-kicker">1702-EX Page 3</p>
                        <h1 class="alignment-title">Page 3 Template Alignment</h1>
                        <p class="alignment-copy">
                            Adjust
                            <code>resources/forms/templates/1702-ex/page3.schema.json</code>,
                            compare the overlay against the page-3 template, and generate the
                            current page-3 PDF from the dedicated mock payload.
                        </p>
                    </div>

                    <div class="alignment-actions">
                        <form method="POST" action="{{ $mockExportUrl }}">
                            @csrf
                            <button class="alignment-action" type="submit">
                                Generate Current PDF
                            </button>
                        </form>
                        @if (!empty($latestExport['previewUrl'] ?? null))
                            <a
                                class="alignment-action"
                                href="{{ $latestExport['previewUrl'] }}"
                                rel="noreferrer"
                                target="_blank"
                            >
                                Open Latest PDF
                            </a>
                        @endif
                        @if (!empty($latestExport['downloadUrl'] ?? null))
                            <a
                                class="alignment-action"
                                href="{{ $latestExport['downloadUrl'] }}"
                            >
                                Download Latest PDF
                            </a>
                        @endif
                        <a
                            class="alignment-action"
                            href="{{ $templatePdfUrl }}"
                            rel="noreferrer"
                            target="_blank"
                        >
                            Open Template PDF
                        </a>
                        <a
                            class="alignment-action alignment-action--primary"
                            href="{{ $templatePdfUrl }}"
                            download
                        >
                            Download Template PDF
                        </a>
                    </div>
                </div>
            </section>

            @if (!empty($flash['success'] ?? null) || !empty($flash['error'] ?? null))
                <div class="alignment-flash-stack">
                    @if (!empty($flash['success'] ?? null))
                        <div class="alignment-flash alignment-flash--success">
                            {{ $flash['success'] }}
                        </div>
                    @endif

                    @if (!empty($flash['error'] ?? null))
                        <div class="alignment-flash alignment-flash--error">
                            {{ $flash['error'] }}
                        </div>
                    @endif
                </div>
            @endif

            @if ($viewErrors->any())
                <div class="alignment-flash-stack">
                    <div class="alignment-flash alignment-flash--error">
                        {{ $viewErrors->first() }}
                    </div>
                </div>
            @endif

            <div class="alignment-grid">
                <section class="alignment-card alignment-main">
                    <h2 class="alignment-section-title">Preview Stage</h2>
                    <p class="alignment-section-copy">
                        The page-3 template is shown below with the current schema overlay so you
                        can keep adjusting coordinates in
                        <code>page3.schema.json</code>.
                    </p>

                    @include('forms.partials.1702-ex-page3-blade-art', [
                        'backgroundUrl' => $backgroundUrl,
                        'fields' => $fields,
                        'schema' => $schema,
                    ])
                </section>

                <aside class="alignment-sidebar">
                    <section class="alignment-card alignment-sidebar-card">
                        <h2 class="alignment-section-title">Workflow</h2>
                        <div class="alignment-note-list">
                            <div class="alignment-note">
                                <strong>Schema file</strong>
                                <span><code>resources/forms/templates/1702-ex/page3.schema.json</code></span>
                            </div>
                            <div class="alignment-note">
                                <strong>Sample payload</strong>
                                <span><code>resources/forms/templates/1702-ex/page3-mock-payload.json</code></span>
                            </div>
                            <div class="alignment-note">
                                <strong>Background image</strong>
                                <span><code>public/form-assets/1702-ex/page3-template.png</code></span>
                            </div>
                            <div class="alignment-note">
                                <strong>Template PDF</strong>
                                <span><code>public/form-assets/1702-ex/template-page3.pdf</code></span>
                            </div>
                            <div class="alignment-note">
                                <strong>Latest generated PDF</strong>
                                <span>
                                    @if ($generatedAt)
                                        {{ $generatedAt }}
                                        @if ($fileSize)
                                            - {{ $fileSize }}
                                        @endif
                                    @else
                                        Not generated yet.
                                    @endif
                                </span>
                            </div>
                            <div class="alignment-note">
                                <strong>How to adjust</strong>
                                <span>
                                    Edit <code>x</code> to move left/right, <code>y</code> to move
                                    up/down, <code>width</code> and <code>height</code> to resize,
                                    <code>fontSize</code> for text size, and <code>boxGap</code>
                                    for split boxes. Generate the current PDF after each change to
                                    verify placement.
                                </span>
                            </div>
                        </div>
                    </section>

                    <section class="alignment-card alignment-sidebar-card">
                        <h2 class="alignment-section-title">Field Guide</h2>
                        <p class="alignment-section-copy">
                            Adjust these one by one in
                            <code>resources/forms/templates/1702-ex/page3.schema.json</code>.
                            The section badge is the replacement for JSON comments.
                        </p>
                        <div class="alignment-guide-list">
                            @foreach ($fields as $field)
                                <div class="alignment-guide-item">
                                    <div class="alignment-guide-meta">
                                        <span class="alignment-guide-badge alignment-guide-badge--section">
                                            {{ $field['section'] ?? 'Unassigned' }}
                                        </span>
                                        <span class="alignment-guide-badge">
                                            {{ $field['type'] ?? 'text' }}
                                        </span>
                                    </div>

                                    <p class="alignment-guide-title">
                                        {{ $field['label'] ?? $field['key'] ?? 'Field' }}
                                    </p>
                                    <p class="alignment-guide-key">
                                        key: <code>{{ $field['key'] ?? '' }}</code>
                                    </p>

                                    <pre class="alignment-guide-values"><code>x: {{ number_format((float) ($field['x'] ?? 0), 4, '.', '') }}
y: {{ number_format((float) ($field['y'] ?? 0), 4, '.', '') }}
width: {{ number_format((float) ($field['width'] ?? 0), 4, '.', '') }}
height: {{ number_format((float) ($field['height'] ?? 0), 4, '.', '') }}
fontSize: {{ number_format((float) ($field['fontSize'] ?? 0), 2, '.', '') }}
@if(array_key_exists('markerSize', $field))
markerSize: {{ number_format((float) ($field['markerSize'] ?? 0), 2, '.', '') }}
@endif
align: {{ $field['align'] ?? 'left' }}@if(array_key_exists('boxCount', $field))
boxCount: {{ $field['boxCount'] }}@endif @if(array_key_exists('boxGap', $field))
boxGap: {{ $field['boxGap'] }}@endif</code></pre>
                                </div>
                            @endforeach
                        </div>
                    </section>

                    <section class="alignment-card alignment-sidebar-card">
                        <h2 class="alignment-section-title">Sample Payload</h2>
                        <dl class="alignment-payload-list">
                            @foreach ($payload as $key => $value)
                                @php
                                    $displayValue = match (true) {
                                        is_bool($value) => $value ? 'true' : 'false',
                                        $value === null => 'null',
                                        default => (string) $value,
                                    };
                                @endphp
                                <div class="alignment-payload-item">
                                    <dt>{{ $key }}</dt>
                                    <dd>{{ $displayValue }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>
                </aside>
            </div>
        </div>
    </body>
</html>
