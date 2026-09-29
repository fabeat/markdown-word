<?php

declare(strict_types=1);

namespace MarkdownWord;

use MarkdownWord\Reverse\Block;
use MarkdownWord\Reverse\DocumentReader;
use MarkdownWord\Reverse\Inline;
use MarkdownWord\Reverse\MarkdownWriter;
use MarkdownWord\Reverse\NumberingTable;
use MarkdownWord\Reverse\Options;
use MarkdownWord\Reverse\Package;
use MarkdownWord\Reverse\StyleTable;
use RuntimeException;

/**
 * Converts a Word document back into Markdown.
 *
 * ```php
 * $markdown = (new WordToMarkdown())->convert('report.docx');
 * $markdown = (new WordToMarkdown())->convertString($bytes);
 * ```
 *
 * This is the inverse of {@see \MarkdownWord\MarkdownToWord}, and the two together
 * make a round trip possible: a document can be checked by converting it to
 * Word, back to Markdown, and comparing the text the reader ends up with.
 *
 * A Word document is a lower-fidelity form of the same content, so the round
 * trip preserves everything Word was told to keep and is honest about the rest.
 * The distinctions Word does not record are listed on {@see Options}; a fenced
 * code block carrying no language comes back without one, a table loses whether
 * its first row was a header, and a quote configured as plain indentation
 * rather than as a style is read as an indented paragraph.
 */
final class WordToMarkdown
{
    public function __construct(private readonly Options $options = new Options())
    {
    }

    public function getOptions(): Options
    {
        return $this->options;
    }

    /**
     * Convert a `.docx` file.
     */
    public function convert(string $path): string
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('"%s" does not exist.', $path));
        }

        $package = Package::open($path);

        try {
            return $this->write($package);
        } finally {
            $package->close();
        }
    }

    /**
     * Convert a `.docx` held in memory.
     */
    public function convertString(string $bytes): string
    {
        $package = Package::fromString($bytes);

        try {
            return $this->write($package);
        } finally {
            $package->close();
        }
    }

    /**
     * The block tree a document converts to, for callers that want to inspect it
     * rather than serialise it.
     *
     * @return list<Block>
     */
    public function read(string $path): array
    {
        $package = Package::open($path);

        try {
            return $this->reader($package)->read($package);
        } finally {
            $package->close();
        }
    }

    /**
     * Convert a document and write the result to a file.
     */
    public function save(string $docxPath, string $markdownPath): void
    {
        $markdown = $this->convert($docxPath);

        if (file_put_contents($markdownPath, $markdown) === false) {
            throw new RuntimeException(sprintf('Unable to write "%s".', $markdownPath));
        }
    }

    private function write(Package $package): string
    {
        $blocks = $this->reader($package)->read($package);

        // The images live inside the archive under names the writer invented, so
        // asking for a media directory means taking them out of it: a reference
        // to a name that is not on disk resolves to nothing.
        if ($this->options->mediaDirectory !== null) {
            $this->extractMedia($package, $blocks);
        }

        $markdown = (new MarkdownWriter($this->options))->write($blocks);

        return $this->options->lineEnding === "\n"
            ? $markdown
            : str_replace("\n", $this->options->lineEnding, $markdown) . $this->options->lineEnding;
    }

    /**
     * Write every image the document uses into the configured media directory.
     *
     * @param list<Block> $blocks
     */
    private function extractMedia(Package $package, array $blocks): void
    {
        $directory = $this->options->mediaDirectory ?? '';

        if ($directory !== '' && !is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create the media directory "%s".', $directory));
        }

        foreach ($this->mediaTargets($blocks) as $reference) {
            // The reference is the path the image is written to, so the name
            // inside the archive is recovered from it rather than remembered.
            $name = basename($reference);
            $contents = $package->contentsOf('word/media/' . $name) ?? $package->contentsOf('word/' . $name);

            if ($contents !== null) {
                @file_put_contents($directory . '/' . $name, $contents);
            }
        }
    }

    /**
     * The archive-relative paths of the images the document uses.
     *
     * @param list<Block> $blocks
     * @return list<string>
     */
    private function mediaTargets(array $blocks): array
    {
        $targets = [];

        $walk = static function (array $nodes) use (&$walk, &$targets): void {
            foreach ($nodes as $node) {
                foreach ($node->inlines as $inline) {
                    if ($inline->kind === Inline::IMAGE && $inline->target !== '') {
                        $targets[$inline->target] = true;
                    }
                }

                $walk($node->children);
            }
        };

        $walk($blocks);

        return array_keys($targets);
    }

    private function reader(Package $package): DocumentReader
    {
        return new DocumentReader(
            $this->options,
            new StyleTable($package->styles()),
            new NumberingTable($package->numbering()),
        );
    }
}
