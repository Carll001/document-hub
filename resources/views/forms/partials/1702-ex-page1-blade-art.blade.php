@php
    $pageWidth = max(1.0, (float) ($schema['page']['width'] ?? 1));
    $pageHeight = max(1.0, (float) ($schema['page']['height'] ?? 1));
@endphp

<div class="alignment-stage">
    <div
        class="alignment-page"
        style="--page-width: {{ $pageWidth }}; --page-height: {{ $pageHeight }};"
    >
        <img
            class="alignment-page__background"
            src="{{ $backgroundUrl }}"
            alt="1702-EX page 1 background"
        >

        @foreach ($fields as $field)
            @php
                $fieldType = (string) ($field['type'] ?? 'text');
                $fieldKey = (string) ($field['key'] ?? '');
                $fieldSection = (string) ($field['section'] ?? 'Unassigned');
                $align = (string) ($field['align'] ?? 'left');
                $justifyContent = match ($align) {
                    'center' => 'center',
                    'right' => 'flex-end',
                    default => 'flex-start',
                };
                $style = [
                    'left: ' . number_format((float) ($field['x'] ?? 0) * 100, 4, '.', '') . '%',
                    'top: ' . number_format((float) ($field['y'] ?? 0) * 100, 4, '.', '') . '%',
                    'width: ' . number_format((float) ($field['width'] ?? 0) * 100, 4, '.', '') . '%',
                    'height: ' . number_format((float) ($field['height'] ?? 0) * 100, 4, '.', '') . '%',
                    'font-size: ' . number_format(((float) ($field['fontSize'] ?? 10) / $pageWidth) * 100, 4, '.', '') . 'cqw',
                    'justify-content: ' . $justifyContent,
                    'text-align: ' . $align,
                ];

                if (filled($field['fontFamily'] ?? null)) {
                    $style[] = 'font-family: ' . e((string) $field['fontFamily']) . ', serif';
                }

                if (filled($field['fontWeight'] ?? null)) {
                    $style[] = 'font-weight: ' . e((string) $field['fontWeight']);
                }

                if (filled($field['fontStyle'] ?? null)) {
                    $style[] = 'font-style: ' . e((string) $field['fontStyle']);
                }

                if (is_numeric($field['letterSpacing'] ?? null)) {
                    $style[] = 'letter-spacing: ' . number_format(((float) $field['letterSpacing'] / $pageWidth) * 100, 4, '.', '') . 'cqw';
                }

                if ($fieldType === 'checkbox') {
                    $markerSize = (float) ($field['markerSize'] ?? $field['fontSize'] ?? 10);
                    $style[] = '--checkbox-marker-size: ' . number_format(($markerSize / $pageWidth) * 100, 4, '.', '') . 'cqw';
                }

                if ($fieldType === 'split-box') {
                    $style[] = '--field-box-count: ' . max(1, count($field['previewCharacters'] ?? []));

                    if (is_numeric($field['boxGap'] ?? null)) {
                        $style[] = '--field-box-gap: ' . number_format(((float) $field['boxGap'] / $pageWidth) * 100, 4, '.', '') . 'cqw';
                    }
                }
            @endphp

            @if ($fieldType === 'checkbox')
                <div
                    class="alignment-page__field alignment-page__field--checkbox"
                    data-field-key="{{ $fieldKey }}"
                    data-field-section="{{ $fieldSection }}"
                    data-field-type="{{ $fieldType }}"
                    style="{{ implode('; ', $style) }}"
                    title="{{ $fieldSection }} | {{ $field['label'] ?? $fieldKey }} | x: {{ number_format((float) ($field['x'] ?? 0), 4, '.', '') }}, y: {{ number_format((float) ($field['y'] ?? 0), 4, '.', '') }}"
                >
                    @if (!empty($field['previewChecked']))
                        <span class="alignment-page__checkbox-marker" aria-hidden="true"></span>
                    @endif
                </div>
            @elseif ($fieldType === 'split-box')
                <div
                    class="alignment-page__field alignment-page__field--split"
                    data-field-key="{{ $fieldKey }}"
                    data-field-section="{{ $fieldSection }}"
                    data-field-type="{{ $fieldType }}"
                    style="{{ implode('; ', $style) }}"
                    title="{{ $fieldSection }} | {{ $field['label'] ?? $fieldKey }} | x: {{ number_format((float) ($field['x'] ?? 0), 4, '.', '') }}, y: {{ number_format((float) ($field['y'] ?? 0), 4, '.', '') }}"
                >
                    @foreach ($field['previewCharacters'] ?? [] as $character)
                        <span class="alignment-page__split-character">
                            {!! $character !== '' ? e($character) : '&nbsp;' !!}
                        </span>
                    @endforeach
                </div>
            @else
                <div
                    class="alignment-page__field alignment-page__field--text"
                    data-field-key="{{ $fieldKey }}"
                    data-field-section="{{ $fieldSection }}"
                    data-field-type="{{ $fieldType }}"
                    style="{{ implode('; ', $style) }}"
                    title="{{ $fieldSection }} | {{ $field['label'] ?? $fieldKey }} | x: {{ number_format((float) ($field['x'] ?? 0), 4, '.', '') }}, y: {{ number_format((float) ($field['y'] ?? 0), 4, '.', '') }}"
                >
                    {{ $field['previewText'] ?? '' }}
                </div>
            @endif
        @endforeach
    </div>
</div>
