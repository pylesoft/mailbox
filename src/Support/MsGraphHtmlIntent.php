<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Support;

use DOMDocument;
use DOMElement;
use DOMProcessingInstruction;

final class MsGraphHtmlIntent
{
    public static function matches(#[\SensitiveParameter] string $expected, #[\SensitiveParameter] string $observed): bool
    {
        $expected = str_replace("\r\n", "\n", $expected);
        $observed = str_replace("\r\n", "\n", $observed);
        if ($expected === $observed) {
            return true;
        }
        // DOM is optional: unsupported or oversized normalization keeps the strict comparison fail-closed.
        if (! class_exists(DOMDocument::class) || max(strlen($expected), strlen($observed)) > 262_144
            || stripos($expected, 'data-outlook-trace') !== false) {
            return false;
        }
        $implicitTables = stripos($expected, '<tbody') === false;
        $chosen = self::canonical($expected, false, $implicitTables);

        return $chosen !== null && $chosen === self::canonical($observed, true, $implicitTables);
    }

    private static function canonical(#[\SensitiveParameter] string $html, bool $provider, bool $implicitTables): ?string
    {
        if (! mb_check_encoding($html, 'UTF-8') || preg_match('/<!\s*(?:DOCTYPE|ENTITY)|<\?/i', $html) === 1) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $document = new DOMDocument;
            if (! $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_COMPACT)
                || libxml_get_errors() !== []) {
                return null;
            }
            foreach (iterator_to_array($document->childNodes) as $child) {
                if ($child instanceof DOMProcessingInstruction) {
                    $document->removeChild($child);
                }
            }
            foreach (iterator_to_array($document->getElementsByTagName('meta')) as $meta) {
                if ($meta->parentNode?->nodeName === 'head' && $meta->attributes->length === 2
                    && strtolower($meta->getAttribute('http-equiv')) === 'content-type'
                    && strtolower($meta->getAttribute('content')) === 'text/html; charset=utf-8') {
                    $meta->parentNode->removeChild($meta);
                }
            }
            foreach (iterator_to_array($document->getElementsByTagName('head')) as $head) {
                if ($head->attributes->length === 0 && $head->childElementCount === 0 && trim($head->textContent) === ''
                    && count(array_filter(iterator_to_array($head->childNodes), fn ($node): bool => $node->nodeType !== XML_TEXT_NODE)) === 0) {
                    $head->parentNode?->removeChild($head);
                }
            }
            foreach (iterator_to_array($document->getElementsByTagName('tbody')) as $body) {
                if (! $provider || ! $implicitTables || ! $body->parentNode instanceof DOMElement || $body->parentNode->tagName !== 'table'
                    || $body->parentNode->getElementsByTagName('tbody')->length !== 1 || $body->attributes->length !== 0
                    || count(array_filter(iterator_to_array($body->childNodes), fn ($node): bool => ! ($node instanceof DOMElement && $node->tagName === 'tr')
                        && ! ($node->nodeType === XML_TEXT_NODE && trim($node->textContent) === ''))) !== 0) {
                    continue;
                }
                while ($body->firstChild !== null) {
                    $body->parentNode->insertBefore($body->firstChild, $body);
                }
                $body->parentNode->removeChild($body);
            }
            if ($provider) {
                foreach ($document->getElementsByTagName('img') as $image) {
                    if ($image->getAttribute('data-outlook-trace') === 'F:0|T:1') {
                        $image->removeAttribute('data-outlook-trace');
                    }
                }
            }

            return $document->C14N(withComments: true) ?: null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
