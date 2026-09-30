<?php

declare(strict_types=1);

namespace MarkdownWord\Writer;

use MarkdownWord\Exception\FileNotWritable;
use MarkdownWord\Exception\MalformedDocument;
use MarkdownWord\Exception\UnreadableDocument;
use MarkdownWord\Render\ImageDescriptionCollector;
use MarkdownWord\Render\LinkPayloadCollector;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

/**
 * Writes a `PhpWord` document to `.docx`.
 *
 * On top of PHPWord's own writer this makes two extra passes over the archive,
 * each of them reopening it:
 *
 *  - {@see HyperlinkPass} replaces the hyperlink placeholders left behind by
 *    {@see \MarkdownWord\Render\LinkPayloadCollector} in `word/document.xml`
 *    with real `w:hyperlink` elements, and registers their relationships.
 *    Without it, a link whose label contains emphasis would lose either the link
 *    or the formatting.
 *  - {@see ImageDescriptionPass} fills in the alternative text of the images,
 *    which the writer emits as an empty string.
 *
 * Both entry points stage the archive in the system temp directory first, so
 * neither a document being written to disk nor one being handed back as a string
 * is ever half-finished.
 */
final class DocxWriter
{
    /**
     * How many links deep {@see self::followLink()} follows before it takes a
     * cycle for a destination it cannot make sense of.
     */
    private const MAX_LINKS = 10;

    /**
     * Write the document to a file, and hand back what was written.
     *
     * The bytes are read from the staged copy before it is moved into place, so
     * a caller that wants both the file and the content gets them from one pass
     * rather than by reading the file it has just written.
     *
     * @return string The `.docx` as written.
     *
     * @throws FileNotWritable When the archive cannot be staged, read, or put in
     *         place: no temporary file to be had, a directory that cannot be
     *         created, or a destination the process cannot write to.
     * @throws UnreadableDocument When the staged archive cannot be reopened for
     *         one of the two passes.
     * @throws MalformedDocument When a part of the staged archive is not XML.
     */
    public static function write(
        PhpWord $phpWord,
        string $path,
        ?LinkPayloadCollector $links = null,
        ?ImageDescriptionCollector $images = null,
    ): string {
        $temp = self::stage($phpWord, $links, $images);

        try {
            $contents = self::read($temp);

            self::move($temp, $path);

            return $contents;
        } finally {
            // On the happy path `move()` has already renamed the archive away,
            // so there is nothing here to remove and this branch only runs when
            // something went wrong. What it removes is a whole document, sitting
            // in a directory every other account on the machine can read.
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /**
     * Write the document and return it as a string, without touching the disk
     * beyond the staging file.
     *
     * @throws FileNotWritable When the archive cannot be staged or read.
     * @throws UnreadableDocument When the staged archive cannot be reopened for
     *         one of the two passes.
     * @throws MalformedDocument When a part of the staged archive is not XML.
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
     *
     * @throws \RuntimeException When the archive cannot be opened, or is missing
     *         the document part or the relationship part.
     * @throws MalformedDocument When either of those parts is not XML.
     */
    public static function patchHyperlinks(string $docxPath, array $payloads): void
    {
        (new HyperlinkPass($payloads))->applyTo($docxPath);
    }

    /**
     * Write the archive to a temporary file and put it in order.
     *
     * @return string The path of the finished archive. It belongs to the caller,
     *         which must unlink it unless it moves it into place.
     * @throws FileNotWritable When no temporary file can be made.
     */
    private static function stage(
        PhpWord $phpWord,
        ?LinkPayloadCollector $links,
        ?ImageDescriptionCollector $images,
    ): string {
        $path = tempnam(sys_get_temp_dir(), 'mdword_');

        if ($path === false) {
            throw new FileNotWritable('Unable to create a temporary file.');
        }

        OutputEscaping::enabled(static function () use ($phpWord, $path): void {            IOFactory::createWriter($phpWord, 'Word2007')->save($path);
        });

        // `save()` leaves the file at the process umask, which is 0644 for
        // almost everyone: a document in flight through a shared temporary
        // directory is as readable as the one that lands, and this is the only
        // moment its permissions can be narrowed.
        @chmod($path, 0o600);

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
            throw new FileNotWritable('Unable to read the generated .docx file.');
        }

        return $contents;
    }

    /**
     * Put the staged archive where the caller asked for it.
     *
     * Two things about the destination are worth the trouble:
     *
     *  - It may be a symlink. `rename()` replaces a link with a regular file, so
     *    writing through `report.docx -> published/report.docx` would leave the
     *    link gone and the file it named holding its old contents: two paths,
     *    one of them stale, and nothing said so. The link is followed instead.
     *  - It may be on another filesystem. `rename()` cannot cross that boundary
     *    and answers `EXDEV` however the permissions stand, which is what a
     *    container with the output on a mounted volume gives — a destination
     *    that is perfectly writable and refused all the same. Copying is the
     *    fallback, and the staged copy is removed so the move is still a move.
     *
     * @throws FileNotWritable When the destination cannot be made or written.
     */
    private static function move(string $from, string $to): void
    {
        $to = self::followLink($to);
        $directory = dirname($to);

        // A path given to a converter rarely has its directory made for it, and
        // `rename()` does not create one.
        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new FileNotWritable(sprintf('Unable to create the directory "%s".', $directory));
        }

        if (@rename($from, $to)) {
            return;
        }

        $existed = file_exists($to);

        if (@copy($from, $to)) {
            @unlink($from);

            return;
        }

        // A copy that fails part way through leaves the destination half a
        // document, which is the one thing staging it was there to prevent. Only
        // a file that was not there before is removed: one the caller had cannot
        // be taken away again, and `copy()` has truncated it either way.
        if (!$existed) {
            @unlink($to);
        }

        throw new FileNotWritable(sprintf('Unable to write the document to "%s".', $to));
    }

    /**
     * The file a path really names, following a symlink to the end of it.
     *
     * A link is followed even when what it points at is not there yet, since
     * that is how a deployment says where a document goes. A chain of links is
     * followed to its end, and a cycle gives up rather than going round for ever.
     */
    private static function followLink(string $path): string
    {
        for ($hop = 0; $hop < self::MAX_LINKS; $hop++) {
            $target = @readlink($path);

            if ($target === false) {
                return $path;
            }

            $path = self::isAbsolute($target) ? $target : dirname($path) . '/' . $target;
        }

        return $path;
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
    }
}
