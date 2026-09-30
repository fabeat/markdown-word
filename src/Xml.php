<?php

declare(strict_types=1);

namespace MarkdownWord;

use DOMDocument;

/**
 * Parsing the XML out of a `.docx`, in one place.
 *
 * The libxml flags live here so they cannot differ between callers, and
 * `LIBXML_NONET` keeps a document from reaching the network — it was missing
 * from one of these call sites once.
 */
final class Xml
{
    /**
     * Parse a fragment of XML.
     *
     * `$flags` are added to the two set here. A fragment that does not parse
     * gives null rather than an exception: a document with one broken part among
     * several is the caller's to decide about.
     */
    public static function parse(string $xml, int $flags = 0, ?DOMDocument $into = null): ?DOMDocument
    {
        $dom = $into ?? new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $dom->loadXML($xml, $flags | LIBXML_NOCDATA | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $loaded ? $dom : null;
    }

    /**
     * Parse a fragment and insist that it works.
     *
     * @throws \MarkdownWord\Exception\MalformedDocument
     */
    public static function parseOrFail(string $xml, string $message, int $flags = 0): DOMDocument
    {
        return self::parse($xml, $flags)
            ?? throw new Exception\MalformedDocument($message);
    }
}
