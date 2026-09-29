<?php

declare(strict_types=1);

namespace MarkdownWord\Writer;

use MarkdownWord\Render\ImageDescriptionCollector;
use MarkdownWord\Render\LinkPayloadCollector;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

/**
 * Writes a `PhpWord` document to `.docx`.
 *
 * On top of PHPWord's own writer this performs one extra pass over
 * `word/document.xml`, replacing the hyperlink placeholders left behind by
 * {@see \MarkdownWord\Render\LinkPayloadCollector} with real `w:hyperlink` elements
 * and registering their relationships. Without it, links whose label contains
 * emphasis would lose either the link or the formatting, and filling in the
 * alternative text of the images, which PHPWord writes as an empty string.
 */
final class DocxWriter
{
    public static function write(
        PhpWord $phpWord,
        string $path,
        ?LinkPayloadCollector $links = null,
        ?ImageDescriptionCollector $images = null,
    ): void {
        $temp = self::tempFile();
        self::writePhpWord($phpWord, $temp);

        self::patch($temp, $links, $images);

        self::move($temp, $path);
    }

    public static function toString(
        PhpWord $phpWord,
        ?LinkPayloadCollector $links = null,
        ?ImageDescriptionCollector $images = null,
    ): string {
        $temp = self::tempFile();
        self::writePhpWord($phpWord, $temp);

        self::patch($temp, $links, $images);

        $contents = file_get_contents($temp);
        @unlink($temp);

        if ($contents === false) {
            throw new \RuntimeException('Unable to read the generated .docx file.');
        }

        return $contents;
    }

    /**
     * Replace the hyperlink placeholders inside an already written `.docx`.
     *
     * The file is edited in place, so it is used both by the normal write path
     * and by the template renderer, which produces its document with PHPWord's
     * own template processor.
     *
     * @param list<array{placeholder: string, url: string, title: ?string, runs: list<array{text: string, style: array<string, mixed>}>}> $payloads
     */
    public static function patchHyperlinks(string $docxPath, array $payloads): void
    {
        (new HyperlinkPass($payloads))->applyTo($docxPath);
    }

    /**
     * Replace the hyperlink placeholders and set the image descriptions inside an
     * already written `.docx`.
     */
    private static function patch(
        string $docxPath,
        ?LinkPayloadCollector $links,
        ?ImageDescriptionCollector $images,
    ): void {
        if ($links?->hasPayloads() === true) {
            self::patchHyperlinks($docxPath, $links->payloads());
        }

        $descriptions = $images?->take() ?? [];

        if ($descriptions !== []) {
            (new ImageDescriptionPass($descriptions))->applyTo($docxPath);
        }
    }

    private static function writePhpWord(PhpWord $phpWord, string $path): void
    {
        Escaping::enabled(static function () use ($phpWord, $path): void {
            IOFactory::createWriter($phpWord, 'Word2007')->save($path);
        });
    }

    private static function tempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mdword_');

        if ($path === false) {
            throw new \RuntimeException('Unable to create a temporary file.');
        }

        return $path;
    }

    private static function move(string $from, string $to): void
    {
        $directory = \dirname($to);
        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create the directory "%s".', $directory));
        }

        if (!rename($from, $to)) {
            throw new \RuntimeException(sprintf('Unable to write the document to "%s".', $to));
        }
    }
}
