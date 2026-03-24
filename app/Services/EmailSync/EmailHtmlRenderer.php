<?php

namespace App\Services\EmailSync;

use DOMDocument;
use DOMElement;
use DOMXPath;

class EmailHtmlRenderer
{
    /**
     * Build a safe standalone HTML document for a stored email.
     *
     * @param  array<string, string>  $inlineAttachmentUrls
     */
    public function renderDocument(?string $bodyHtml, ?string $bodyText, array $inlineAttachmentUrls = []): string
    {
        $bodyHtml = trim((string) $bodyHtml);

        if ($bodyHtml === '') {
            return $this->plainTextDocument($bodyText);
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = true;
        $document->formatOutput = false;

        $loaded = @$document->loadHTML(
            '<?xml encoding="UTF-8">'.$this->wrappedHtmlDocument($bodyHtml),
            \LIBXML_NOERROR | \LIBXML_HTML_NODEFDTD | \LIBXML_NOWARNING,
        );

        if (! $loaded) {
            return $this->plainTextDocument($bodyText);
        }

        $this->removeDangerousNodes($document);
        $this->sanitizeAttributes($document, $inlineAttachmentUrls);
        $this->ensureDocumentChrome($document);

        $html = $document->saveHTML() ?: '';
        $html = preg_replace('/^<\?xml[^>]+>\s*/', '', $html) ?? $html;

        return trim($html) !== '' ? $html : $this->plainTextDocument($bodyText);
    }

    /**
     * Wrap a fragment in a complete HTML document when needed.
     */
    private function wrappedHtmlDocument(string $bodyHtml): string
    {
        if (preg_match('/<html[\s>]/i', $bodyHtml)) {
            return $bodyHtml;
        }

        if (preg_match('/<body[\s>]/i', $bodyHtml)) {
            return "<!DOCTYPE html><html><head></head>{$bodyHtml}</html>";
        }

        return "<!DOCTYPE html><html><head></head><body>{$bodyHtml}</body></html>";
    }

    /**
     * Remove elements that should never render inside the inbox viewer.
     */
    private function removeDangerousNodes(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        foreach ([
            '//script',
            '//iframe',
            '//object',
            '//embed',
            '//base',
            '//form',
            '//input',
            '//button',
            '//select',
            '//textarea',
            '//meta[translate(@http-equiv, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "refresh"]',
        ] as $query) {
            $nodes = $xpath->query($query);

            if ($nodes === false) {
                continue;
            }

            foreach (iterator_to_array($nodes) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
    }

    /**
     * Remove unsafe attributes and rewrite inline CID assets.
     *
     * @param  array<string, string>  $inlineAttachmentUrls
     */
    private function sanitizeAttributes(DOMDocument $document, array $inlineAttachmentUrls): void
    {
        foreach (iterator_to_array($document->getElementsByTagName('*')) as $element) {
            if (! $element instanceof DOMElement || ! $element->hasAttributes()) {
                continue;
            }

            $attributesToRemove = [];

            foreach (iterator_to_array($element->attributes) as $attribute) {
                $attributeName = strtolower($attribute->nodeName);
                $attributeValue = trim($attribute->nodeValue);

                if (
                    str_starts_with($attributeName, 'on')
                    || in_array($attributeName, ['srcdoc', 'formaction', 'xmlns:xlink'], true)
                ) {
                    $attributesToRemove[] = $attributeName;

                    continue;
                }

                if ($attributeName === 'srcset') {
                    $attributesToRemove[] = $attributeName;

                    continue;
                }

                if (in_array($attributeName, ['href', 'src', 'poster', 'background', 'xlink:href'], true)) {
                    $sanitizedUrl = $this->sanitizeUrl(
                        $attributeValue,
                        strtolower($element->tagName),
                        $attributeName,
                        $inlineAttachmentUrls,
                    );

                    if ($sanitizedUrl === null) {
                        $attributesToRemove[] = $attributeName;
                    } else {
                        $element->setAttribute($attributeName, $sanitizedUrl);
                    }
                }
            }

            foreach ($attributesToRemove as $attributeName) {
                $element->removeAttribute($attributeName);
            }

            if (strtolower($element->tagName) === 'a' && $element->hasAttribute('href')) {
                $element->setAttribute('target', '_blank');
                $element->setAttribute('rel', 'noopener noreferrer');
            }
        }
    }

    /**
     * Ensure the rendered document has a viewport and sane image sizing.
     */
    private function ensureDocumentChrome(DOMDocument $document): void
    {
        $html = $document->documentElement;

        if (! $html instanceof DOMElement) {
            return;
        }

        $head = $document->getElementsByTagName('head')->item(0);

        if (! $head instanceof DOMElement) {
            $head = $document->createElement('head');
            $html->insertBefore($head, $html->firstChild);
        }

        $viewport = $document->createElement('meta');
        $viewport->setAttribute('name', 'viewport');
        $viewport->setAttribute('content', 'width=device-width, initial-scale=1');
        $head->appendChild($viewport);

        $style = $document->createElement('style');
        $style->appendChild($document->createTextNode(
            'html,body{margin:0;padding:0;background:transparent;}img{max-width:100%;height:auto;}table{max-width:100%;}',
        ));
        $head->appendChild($style);

        $body = $document->getElementsByTagName('body')->item(0);

        if ($body instanceof DOMElement) {
            $existingStyle = trim($body->getAttribute('style'));
            $body->setAttribute(
                'style',
                trim($existingStyle.';margin:0;overflow-wrap:anywhere;'),
            );
        }
    }

    /**
     * Keep only safe URLs in rendered email markup.
     *
     * @param  array<string, string>  $inlineAttachmentUrls
     */
    private function sanitizeUrl(
        string $url,
        string $tagName,
        string $attributeName,
        array $inlineAttachmentUrls,
    ): ?string {
        if ($url === '' || str_starts_with($url, '#')) {
            return $url;
        }

        if (preg_match('/^cid:(.+)$/i', $url, $matches) === 1) {
            return $inlineAttachmentUrls[$this->normalizeContentId($matches[1])] ?? null;
        }

        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }

        $parsedUrl = parse_url($url);

        if ($parsedUrl === false) {
            return null;
        }

        if (! isset($parsedUrl['scheme'])) {
            return null;
        }

        return match (strtolower($parsedUrl['scheme'])) {
            'http', 'https', 'mailto', 'tel' => $url,
            'data' => $this->allowsDataUrl($url, $tagName, $attributeName) ? $url : null,
            default => null,
        };
    }

    /**
     * Limit data URLs to inline media sources only.
     */
    private function allowsDataUrl(string $url, string $tagName, string $attributeName): bool
    {
        if (! in_array($attributeName, ['src', 'poster', 'background', 'xlink:href'], true)) {
            return false;
        }

        if (! in_array($tagName, ['img', 'image', 'source', 'video', 'audio'], true)) {
            return false;
        }

        return preg_match('#^data:(image|audio|video)/#i', $url) === 1;
    }

    /**
     * Create a readable document when only plain text is available.
     */
    private function plainTextDocument(?string $bodyText): string
    {
        $escapedText = htmlspecialchars(
            trim((string) $bodyText) !== '' ? (string) $bodyText : 'No message body was extracted for this email yet.',
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
html,body{margin:0;padding:0;background:transparent;color:#111827;font:15px/1.7 Arial,sans-serif;}
pre{margin:0;white-space:pre-wrap;word-break:break-word;}
</style>
</head>
<body>
<pre>{$escapedText}</pre>
</body>
</html>
HTML;
    }

    /**
     * Normalize content IDs so stored attachments match CID URLs.
     */
    private function normalizeContentId(string $contentId): string
    {
        return trim($contentId, " \t\n\r\0\x0B<>");
    }
}
