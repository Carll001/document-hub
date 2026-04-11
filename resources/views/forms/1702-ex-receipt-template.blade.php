<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>1702-EX Receipt Template Alignment</title>
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
                max-width: 900px;
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

            .alignment-grid {
                display: grid;
                grid-template-columns: minmax(0, 1.45fr) minmax(320px, 420px);
                gap: 24px;
                margin-top: 24px;
                align-items: start;
            }

            .alignment-main,
            .alignment-sidebar-card {
                padding: 24px;
            }

            .alignment-sidebar {
                display: grid;
                gap: 20px;
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

            .alignment-note-list,
            .alignment-payload-list,
            .alignment-guide-list {
                display: grid;
                gap: 12px;
            }

            .alignment-note,
            .alignment-payload-item,
            .alignment-guide-item {
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
            .alignment-payload-list dd,
            .alignment-guide-copy {
                margin: 0;
                font-size: 13px;
                color: var(--muted);
                line-height: 1.5;
                overflow-wrap: anywhere;
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
                margin: 8px 0 0;
                font-size: 12px;
                color: var(--muted);
            }

            .alignment-stage {
                overflow: auto;
                border-radius: 20px;
                border: 1px solid var(--border);
                background: linear-gradient(180deg, #f8fafc 0%, #eef2f7 100%);
                padding: 16px;
            }

            .alignment-page {
                position: relative;
                width: min(100%, 1000px);
                aspect-ratio: calc(var(--page-width) / var(--page-height));
                container-type: inline-size;
                margin: 0 auto;
                background: #ffffff;
                border-radius: 18px;
                overflow: hidden;
                box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.3);
            }

            .alignment-page__background {
                position: absolute;
                inset: 0;
                width: 100%;
                height: 100%;
                object-fit: cover;
            }

            .alignment-page__field {
                position: absolute;
                display: flex;
                align-items: center;
                overflow: hidden;
                white-space: pre;
                color: #0f172a;
                line-height: 1;
            }

            .alignment-page__field--text {
                text-shadow: 0 0 0.25cqw rgba(255, 255, 255, 0.8);
            }

            .alignment-page__field--checkbox {
                justify-content: center;
            }

            .alignment-page__checkbox-marker {
                width: var(--checkbox-marker-size, 1cqw);
                height: var(--checkbox-marker-size, 1cqw);
                border-radius: 999px;
                background: var(--marker-fill);
            }

            .alignment-page__field--split {
                display: grid;
                grid-template-columns: repeat(var(--field-box-count, 1), minmax(0, 1fr));
                gap: var(--field-box-gap, 0);
            }

            .alignment-page__split-character {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 100%;
                height: 100%;
            }

            @media (max-width: 1100px) {
                .alignment-grid {
                    grid-template-columns: 1fr;
                }
            }

            @media (max-width: 720px) {
                .alignment-shell {
                    width: min(100%, calc(100% - 20px));
                    padding-top: 12px;
                }

                .alignment-header,
                .alignment-main,
                .alignment-sidebar-card {
                    padding: 18px;
                }
            }
        </style>
    </head>
    <body>
        <div class="alignment-shell">
            <section class="alignment-card">
                <div class="alignment-header">
                    <div>
                        <p class="alignment-kicker">1702-EX Receipt</p>
                        <h1 class="alignment-title">Receipt Template Alignment</h1>
                        <p class="alignment-copy">
                            Adjust
                            <code>resources/forms/templates/1702-ex/receipt.schema.json</code>,
                            compare the overlay against the committed receipt PDF, and generate
                            the current receipt from the dedicated mock payload.
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

            <div class="alignment-grid">
                <section class="alignment-card alignment-main">
                    <h2 class="alignment-section-title">Preview Stage</h2>
                    <p class="alignment-section-copy">
                        The overlay below uses the current receipt schema and mock payload. Once
                        the positions look right here, the same coordinates will be used in the
                        per-row Add receipt dialog output.
                    </p>

                    @include('forms.partials.1702-ex-receipt-blade-art', [
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
                                <strong>Template source</strong>
                                <span>
                                    The receipt layout comes from the committed
                                    <code>template-receipt.pdf</code> asset.
                                </span>
                            </div>
                            <div class="alignment-note">
                                <strong>Editable values</strong>
                                <span>
                                    Only the schema keys become dialog inputs. Static BIR wording
                                    stays in the PDF template.
                                </span>
                            </div>
                            <div class="alignment-note">
                                <strong>Email autofill</strong>
                                <span>
                                    The per-row receipt dialog reuses the Inbox clipboard flow for
                                    file name, date received by BIR, and time received by BIR.
                                </span>
                            </div>
                        </div>
                    </section>

                    <section class="alignment-card alignment-sidebar-card">
                        <h2 class="alignment-section-title">Field Guide</h2>
                        <p class="alignment-section-copy">
                            Each unique receipt schema key becomes one dialog field.
                        </p>
                        <div class="alignment-guide-list">
                            @foreach ($fields as $field)
                                <div class="alignment-guide-item">
                                    <div class="alignment-guide-meta">
                                        <span class="alignment-guide-badge alignment-guide-badge--section">
                                            {{ $field['section'] ?? 'Receipt Details' }}
                                        </span>
                                        <span class="alignment-guide-badge">
                                            {{ $field['type'] ?? 'text' }}
                                        </span>
                                    </div>
                                    <p class="alignment-guide-title">
                                        {{ $field['label'] ?? $field['key'] ?? 'Field' }}
                                    </p>
                                    <p class="alignment-guide-key">
                                        Key:
                                        <code>{{ $field['key'] ?? '' }}</code>
                                    </p>
                                    <p class="alignment-guide-copy">
                                        x: {{ number_format((float) ($field['x'] ?? 0), 4, '.', '') }},
                                        y: {{ number_format((float) ($field['y'] ?? 0), 4, '.', '') }},
                                        width: {{ number_format((float) ($field['width'] ?? 0), 4, '.', '') }},
                                        height: {{ number_format((float) ($field['height'] ?? 0), 4, '.', '') }}
                                    </p>
                                </div>
                            @endforeach
                        </div>
                    </section>

                    <section class="alignment-card alignment-sidebar-card">
                        <h2 class="alignment-section-title">Sample Payload</h2>
                        <dl class="alignment-payload-list">
                            @foreach ($payload as $key => $value)
                                <div class="alignment-payload-item">
                                    <dt>{{ $key }}</dt>
                                    <dd>{{ is_scalar($value) || $value === null ? (string) $value : json_encode($value) }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>
                </aside>
            </div>
        </div>
    </body>
</html>
