<?php

declare(strict_types=1);

namespace MarkdownWord\Writer;

use MarkdownWord\Render\ImageDescriptionCollector;
use MarkdownWord\Render\LinkPayloadCollector;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use RuntimeException;

/**
 * Writes a `PhpWord` document to `.docx`.
 *
 * On top of PHPWord's own writer this performs one extra pass over
 * `word/document.xml`, replacing the hyperlink placeholders left behind by
 * {@see \MarkdownWord\Render\LinkPayloadCollector} with real `w:hyperlink` elements
 * and registering their relationships. Without it, links whose label contains
 * emphasis would lose either the link or the formatting, and filling in the
 * alternative text of the images, which PHPWord writes as an empty string.
 *
 * Both entry points stage the archive in the system temp directory first, so
 * neither a document being written to disk nor one being handed back as a string
 * is ever half-finished.
 */
final class DocxWriter
{
    /**
     * Write the document to a file, and hand back what was written.
     *
     * The bytes are read from the staged copy before it is moved into place, so
     * a caller that wants both the file and the content gets them from one pass
     * rather than by reading the file it has just written.
     *
     * @return string The `.docx` as written.
     */
    public static function write(
        PhpWord $phpWord,
        string $path,
        ?LinkPayloadCollector $links = null,
        ?ImageDescriptionCollector $images = null,
    ): string {
        $temp = self::stage($phpWord, $links, $images);

        $contents = self::read($temp);

        self::move($temp, $path);

        return $contents;
    }

    /**
     * Write the document and return it as a string, without touching the disk
     * beyond the staging file.
     */
    public static function toString(
        PhpWord $phpWord,
        ?LinkPayloadCollector $links = null,
        ?ImageDescriptionCollector $images = null,
    ): string {
        $temp = self::stage($phpWord, $links, $images);

        try {
            return self::read($temp);
        } finally {
            @unlink($temp);
        }
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
     * Write the archive to a temporary file and put it in order.
     *
     * @return string The path of the finished archive, for the caller to move or
     *         to read. It is the caller's to clean up.
     */
    private static function stage(
        PhpWord $phpWord,
        ?LinkPayloadCollector $links,
        ?ImageDescriptionCollector $images,
    ): string {
        $path = tempnam(sys_get_temp_dir(), 'mdword_');

        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary file.');
        }

        Escaping::enabled(static function () use ($phpWord, $path): void {
            IOFactory::createWriter($phpWord, 'Word2007')->save($path);
        });

        self::patch($path, $links, $images);

        return $path;
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

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Unable to read the generated .docx file.');
        }

        return $contents;
    }

    private static function move(string $from, string $to): void
    {
        $directory = dirname($to);

        // A path given to a converter rarely has its directory made for it, and
        // `rename()` does not create one.
        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create the directory "%s".', $directory));
        }

        if (!rename($from, $to)) {
            throw new RuntimeException(sprintf('Unable to write the document to "%s".', $to));
        }
    }
}
