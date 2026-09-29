<?php

declare(strict_types=1);

namespace MarkdownWord\Tests\Support;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use RuntimeException;
use ZipArchive;

/**
 * Builds the Word documents the tests need as input, rather than checking binary
 * fixtures into the repository.
 */
final class TemplateFactory
{
    /**
     * A template with the constructs a typical report needs: a single value, a
     * Markdown region, and a repeating region.
     */
    public static function report(): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();

        $section->addText('Quarterly Report');
        $section->addText('Reference: ${reference}');

        $section->addText('${summary}');
        $section->addText('${slot}');
        $section->addText('${/summary}');

        $section->addText('${lines}');
        $section->addText('${line}');
        $section->addText('${/lines}');

        return self::write($phpWord, 'report');
    }

    /**
     * A template with nothing but a Markdown region, for tests that only care
     * about the inserted content.
     */
    public static function placeholder(string $name = 'body'): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();

        $section->addText('${' . $name . '}');
        $section->addText('${slot}');
        $section->addText('${/' . $name . '}');

        return self::write($phpWord, 'placeholder-' . $name);
    }

    /**
     * A template whose styles use non-default names, to prove the configuration
     * can target a corporate look.
     */
    public static function styled(): string
    {
        $phpWord = new PhpWord();
        $phpWord->addParagraphStyle('CorpTitle', ['bold' => true, 'size' => 20]);
        $phpWord->addParagraphStyle('CorpBody', ['size' => 11]);

        $section = $phpWord->addSection();
        $section->addText('${body}');
        $section->addText('${slot}');
        $section->addText('${/body}');

        return self::write($phpWord, 'styled');
    }

    private static function write(PhpWord $phpWord, string $name): string
    {
        $path = Scratch::path($name);

        Upstream::quietly(static function () use ($phpWord, $path): void {
            IOFactory::createWriter($phpWord, 'Word2007')->save($path);
        });

        return $path;
    }

    public static function directory(): string
    {
        return Scratch::directory();
    }

    /**
     * The visible text of a generated document, paragraph by paragraph.
     */
    public static function textOf(string $docx): string
    {
        $xpath = self::xpathOf($docx);

        $parts = [];
        foreach ($xpath->query('//w:p') ?: [] as $paragraph) {
            $text = '';
            foreach ($xpath->query('.//w:t', $paragraph) ?: [] as $node) {
                $text .= $node->textContent;
            }
            $parts[] = trim($text);
        }

        return trim(implode("\n", $parts));
    }

    /**
     * The relationship targets declared in a document part.
     *
     * @return list<string>
     */
    public static function targetsOf(string $docx, string $part = 'word/_rels/document.xml.rels'): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML(self::read($docx, $part));
        libxml_clear_errors();

        $targets = [];
        foreach ($dom->documentElement?->childNodes ?? [] as $node) {
            if ($node instanceof \DOMElement && $node->getAttribute('Target') !== '') {
                $targets[] = $node->getAttribute('Target');
            }
        }

        return $targets;
    }

    private static function xpathOf(string $docx): \DOMXPath
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML(self::read($docx, 'word/document.xml'), LIBXML_NOCDATA);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        return $xpath;
    }

    /**
     * The number of `w:hyperlink` elements in the document.
     */
    public static function hyperlinkCount(string $docx): int
    {
        $xpath = self::xpathOf($docx);

        return $xpath->query('//w:hyperlink')?->length ?? 0;
    }

    public static function xmlOf(string $docx, string $part = 'word/document.xml'): string
    {
        return self::read($docx, $part);
    }

    private static function read(string $docx, string $part): string
    {
        $zip = new ZipArchive();

        if ($zip->open($docx) !== true) {
            throw new RuntimeException(sprintf('Unable to open "%s".', $docx));
        }

        $contents = $zip->getFromName($part);
        $zip->close();

        if ($contents === false) {
            throw new RuntimeException(sprintf('"%s" does not contain "%s".', $docx, $part));
        }

        return $contents;
    }
}
