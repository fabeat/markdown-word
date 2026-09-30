<?php

declare(strict_types=1);

namespace MarkdownWord;

use DOMDocument;

/**
 * Parsing the XML out of a `.docx`, in one place.
 *
 * Every reader of a document does the same dance around libxml — ask it to keep
 * its complaints to itself, load, put the setting back — and getting that dance
 * subtly wrong is how a malformed document ends up flooding a terminal with
 * parser warnings instead of producing one sentence about itself.
 *
 * The flags are set here so that they cannot differ between the code reading a
 * document's body and the code rewriting its numbering. `LIBXML_NONET` stops
 * libxml reaching the network for anything, which matters because a `.docx` is
 * input from wherever it came from; it was missing from one of these.
 */
final class Xml
{
    /**
     * Parse a fragment of XML.
     *
     * @param string $xml    The document or fragment.
     * @param int    $flags  Anything libxml's `loadXML()` takes, on top of the
     *        two set here.
     * @return DOMDocument|null Null when it does not parse, rather than an
     *         exception: a document with one broken part among several is a thing
     *         the caller decides what to do about.
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
     * @param string $message Said when it does not, so the caller names the part
     *        at fault rather than the caller reporting a parse failure with no
     *        idea which document or which part.
     * @throws \MarkdownWord\Exception\MalformedDocument
     */
    public static function parseOrFail(string $xml, string $message, int $flags = 0): DOMDocument
    {
        return self::parse($xml, $flags)
            ?? throw new Exception\MalformedDocument($message);
    }
}
