<?php

declare(strict_types=1);

namespace MarkdownWord\Writer;

use MarkdownWord\Exception\FileNotWritable;
use MarkdownWord\Exception\MalformedDocument;
use MarkdownWord\Exception\UnreadableDocument;
use MarkdownWord\Render\ImageDescriptionCollector;
use MarkdownWord\Render\LinkPayloadCollector;
use MarkdownWord\Render\SvgAttachmentCollector;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

/**
 * Writes a `PhpWord` document to `.docx`.
 *
 * On top of PHPWord's own writer this makes two extra passes over the archive,
 * each reopening it:
 *
 *  - {@see HyperlinkPass} replaces the placeholders left behind by
 *    {@see \MarkdownWord\Render\LinkPayloadCollector} with real `w:hyperlink`
 *    elements. Without it, a link whose label contains emphasis would lose
 *    either the link or the formatting.
 *  - {@see ImageDescriptionPass} fills in the alternative text of the images,
 *    which PHPWord writes as an empty string.
 *  - {@see SvgPass} puts the vector back behind the raster Word draws, for the SVG
 *    images PHPWord could only have taken as a picture.
 *
 * {@see self::write()} and {@see self::toString()} both stage the archive in the
 * system temp directory and patch it there, so neither a document written to
 * disk nor one handed back as a string is ever half-finished.
 */
final class DocxWriter
{
    /** How many hops {@see self::followLink()} follows before it gives up on a cycle. */
    private const MAX_LINKS = 10;

    /**
     * Write the document to a file, and hand back what was written.
     *
     * The bytes are read before the move, so a caller wanting both the file and
     * the content gets the content of the file that landed.
     *
     * @throws FileNotWritable When the archive cannot be staged, read, or put in
     *         place: no temporary file to be had, a directory that cannot be
     *         made, or a destination the process cannot write to.
     * @throws UnreadableDocument When a pass cannot reopen the staged archive.
     * @throws MalformedDocument When a part of the staged archive is not XML.
     */
    public static function write(
        PhpWord $phpWord,
        string $path,
        ?LinkPayloadCollector $links = null,
        ?ImageDescriptionCollector $images = null,
        ?SvgAttachmentCollector $vectors = null,
    ): string {
        $temp = self::stage($phpWord, $links, $images, $vectors);

        try {
            $contents = self::read($temp);

            self::move($temp, $path);

            return $contents;
        } finally {
            // `move()` has already renamed the archive away on the happy path, so
            // this only runs when something went wrong: a failed conversion should
            // not leave a whole document behind in the temporary directory.
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
     * @throws UnreadableDocument When a pass cannot reopen the staged archive.
     * @throws MalformedDocument When a part of the staged archive is not XML.
     */
    public static function toString(
        PhpWord $phpWord,
        ?LinkPayloadCollector $links = null,
        ?ImageDescriptionCollector $images = null,
        ?SvgAttachmentCollector $vectors = null,
    ): string {
        $temp = self::stage($phpWord, $links, $images, $vectors);

        try {
            return self::read($temp);
        } finally {
            @unlink($temp);
        }
    }

    /**
     * The file is edited in place, so this is public: the template renderer
     * builds its document with PHPWord's own template processor and patches it
     * here afterwards.
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
     * Write the archive to a temporary file and patch it.
     *
     * @return string The path of the finished archive. It belongs to the caller,
     *         which must unlink it unless it moves it into place.
     * @throws FileNotWritable When no temporary file can be made.
     */
    private static function stage(
        PhpWord $phpWord,
        ?LinkPayloadCollector $links,
        ?ImageDescriptionCollector $images,
        ?SvgAttachmentCollector $vectors = null,
    ): string {
        $path = tempnam(sys_get_temp_dir(), 'mdword_');

        if ($path === false) {
            throw new FileNotWritable('Unable to create a temporary file.');
        }

        OutputEscaping::enabled(static function () use ($phpWord, $path): void {
            IOFactory::createWriter($phpWord, 'Word2007')->save($path);
        });

        // `save()` unlinks the 0600 file `tempnam()` made and writes its own at
        // the process umask, usually 0644: a document in flight through a shared
        // temporary directory is readable by every account on the machine, and
        // this is the only moment its permissions can be narrowed.
        @chmod($path, 0o600);

        self::patch($path, $links, $images, $vectors);

        return $path;
    }

    private static function patch(
        string $docxPath,
        ?LinkPayloadCollector $links,
        ?ImageDescriptionCollector $images,
        ?SvgAttachmentCollector $vectors = null,
    ): void {
        if ($links?->hasPayloads() === true) {
            self::patchHyperlinks($docxPath, $links->payloads());
        }

        $descriptions = $images?->take() ?? [];

        if ($descriptions !== []) {
            (new ImageDescriptionPass($descriptions))->applyTo($docxPath);
        }

        $attachments = $vectors?->all() ?? [];

        if ($attachments !== []) {
            (new SvgPass())->applyTo($docxPath, $attachments);
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
     * Two things about the destination are worth the trouble.
     *
     *  - It may be a symlink, and `rename()` replaces a link with a regular file,
     *    so writing through `report.docx -> published/report.docx` would leave
     *    the link gone and the file it named holding its old contents. The link
     *    is followed instead.
     *  - It may be on another filesystem, which `rename()` cannot cross however
     *    the permissions stand — what a container with the output on a mounted
     *    volume gives, a destination that is perfectly writable and refused all
     *    the same. Copying is the fallback, and it creates the destination at
     *    the umask rather than carrying over the staged file's mode.
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
        // document, which is what staging it was there to prevent. Only a file
        // that was not there before is removed: one the caller had has been
        // truncated by `copy()` either way, so it cannot be taken back.
        if (!$existed) {
            @unlink($to);
        }

        throw new FileNotWritable(sprintf('Unable to write the document to "%s".', $to));
    }

    /**
     * A link is followed even when what it points at is not there yet, since
     * that is how a deployment says where a document goes; a chain is followed
     * to its end.
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
