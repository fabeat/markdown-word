<?php

declare(strict_types=1);

namespace MarkdownWord\Reverse;

use MarkdownWord\Exception\FileNotWritable;
use MarkdownWord\Xml;
use MarkdownWord\Exception\MalformedDocument;
use MarkdownWord\Exception\UnreadableDocument;
use ZipArchive;

/**
 * Reads the parts of a `.docx` archive that describe a document's content.
 *
 * A `.docx` is a zip of XML parts. The reverse converter needs four of them —
 * the document itself, the relationship part that resolves hyperlinks and
 * images, the style definitions that say what a `w:pStyle` means, and the
 * numbering definitions that say what a `w:numId` means — and this class
 * hands them over as parsed documents without leaking the archive into the
 * rest of the code.
 */
final class Package
{
    private const DOCUMENT = 'word/document.xml';
    private const RELATIONSHIPS = 'word/_rels/document.xml.rels';
    private const STYLES = 'word/styles.xml';
    private const NUMBERING = 'word/numbering.xml';

    /** @var array<string, \DOMDocument|null> */
    private array $parts = [];

    private ?string $scratchPath = null;

    private bool $closed = false;

    private function __construct(private readonly \ZipArchive $zip)
    {
    }

    public static function open(string $path): self
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new UnreadableDocument(sprintf('Unable to open "%s" as a zip archive.', $path));
        }

        return new self($zip);
    }

    /**
     * Open a document held in memory rather than in a file.
     */
    public static function fromString(string $bytes): self
    {
        $path = self::writeScratchFile($bytes);

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            @unlink($path);

            throw new UnreadableDocument('The given bytes are not a zip archive.');
        }

        $package = new self($zip);
        $package->scratchPath = $path;

        return $package;
    }

    /**
     * Release the archive, removing the scratch file if one was needed.
     *
     * Safe to call more than once: the destructor calls it after the caller has
     * already closed the package on the way out.
     */
    public function close(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            $this->zip->close();
        }

        if ($this->scratchPath !== null) {
            @unlink($this->scratchPath);
            $this->scratchPath = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function document(): \DOMDocument
    {
        return $this->part(self::DOCUMENT, required: true);
    }

    public function styles(): ?\DOMDocument
    {
        return $this->part(self::STYLES);
    }

    public function numbering(): ?\DOMDocument
    {
        return $this->part(self::NUMBERING);
    }

    /**
     * The relationship targets of the document part, keyed by relationship id.
     *
     * A relationship is how Word refers to something that is not inline text: a
     * hyperlink's destination, an image's file, a footnote.
     *
     * @return array<string, string>
     */
    public function relationships(): array
    {
        $dom = $this->part(self::RELATIONSHIPS);
        $targets = [];

        if ($dom === null) {
            return $targets;
        }

        foreach ($dom->documentElement?->childNodes ?? [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $id = $node->getAttribute('Id');
            if ($id === '') {
                continue;
            }

            $targets[$id] = $node->getAttribute('Target');
        }

        return $targets;
    }

    /**
     * The bytes of a part inside the archive, for example an image.
     */
    public function contentsOf(string $part): ?string
    {
        $contents = $this->zip->getFromName($part);

        return $contents === false ? null : $contents;
    }

    private function part(string $name, bool $required = false): ?\DOMDocument
    {
        if (array_key_exists($name, $this->parts)) {
            return $this->parts[$name];
        }

        $xml = $this->zip->getFromName($name);

        if ($xml === false) {
            if ($required) {
                throw new MalformedDocument(sprintf('The document is missing "%s".', $name));
            }

            return $this->parts[$name] = null;
        }

        $dom = Xml::parse($xml);

        if ($dom === null) {
            if ($required) {
                throw new MalformedDocument(sprintf('"%s" is not valid XML.', $name));
            }

            return $this->parts[$name] = null;
        }

        return $this->parts[$name] = $dom;
    }

    /**
     * Write the bytes to a scratch file so the archive can be opened.
     *
     * `ext-zip` only opens files, so a document in memory has to land on disk
     * for a moment. The file goes to the system temp directory and is removed
     * when the package is closed, so nothing is left behind.
     *
     * The system temp directory and not a directory beside this file, because a
     * library has no business writing into the tree it was installed in: that
     * would drop a `tmp` directory into somebody's `vendor/`, and inside a phar
     * the install directory is a read-only archive, so it would not work at all.
     */
    private static function writeScratchFile(string $bytes): string
    {
        $directory = rtrim(sys_get_temp_dir(), '/') . '/mdword';

        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new FileNotWritable(sprintf('Unable to create the scratch directory "%s".', $directory));
        }

        $path = $directory . '/mdword-' . bin2hex(random_bytes(8)) . '.docx';

        if (@file_put_contents($path, $bytes) === false) {
            throw new FileNotWritable(sprintf('Unable to write the document to "%s".', $directory));
        }

        return $path;
    }
}
