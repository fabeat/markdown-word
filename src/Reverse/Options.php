<?php

declare(strict_types=1);

namespace MarkdownWord\Reverse;

/**
 * Switches for reading a Word document back into Markdown.
 *
 * These are the points where Word and Markdown genuinely do not agree, so each
 * one records a decision rather than a preference.
 */
final class Options
{
    /**
     * @param list<string> $headingStyles Paragraph style ids to read as ATX
     *        headings. Word's built-in ids are `Heading1` to `Heading6`; the ids
     *        are matched case-insensitively and a trailing level is optional, so
     *        `Heading` also matches.
     * @param list<string> $quoteStyles Paragraph style ids to read as block
     *        quotes. Detection is by style, not by indentation, because an
     *        indented paragraph is just an indented paragraph and a list inside a
     *        quote carries the quote's indent without being one.
     * @param list<string> $monospaceFonts Typefaces treated as a code span. A
     *        run in one of these becomes inline code; two or more such paragraphs
     *        in a row become a fenced code block.
     * @param int          $quoteIndent The indentation of one level of block
     *        quote in twips, used to recover how deeply a quote is nested.
     * @param bool         $fenceCodeBlocks Whether two or more consecutive
     *        monospaced paragraphs are written as a fenced code block rather than
     *        as separate paragraphs of inline code.
     * @param bool         $tableHeader Whether the first row of a table is
     *        written as the header. GFM tables always have one, and Word does not
     *        record whether a row was a header, so this cannot be recovered.
     * @param bool         $headingSetext Whether a first- or second-level heading
     *        is written in the underlined form. Markdown has only two of those, so
     *        deeper headings keep their hashes.
     * @param string|null  $mediaDirectory A directory, relative to the Markdown,
     *        the images are taken out of the document into. Null keeps the
     *        reference pointing at the name the image has inside the archive,
     *        which documents the file but is not a path any reader can open.
     * @param string       $lineEnding What the output file uses between lines.
     */
    public function __construct(
        public readonly array $headingStyles = ['Heading', 'Title'],
        public readonly array $quoteStyles = ['IntenseQuote', 'Quote', 'BlockQuote'],
        public readonly array $monospaceFonts = [
            'Consolas', 'Courier New', 'Courier', 'Monaco', 'Menlo', 'Andale Mono',
            'Lucida Console', 'Lucida Sans Typewriter', 'Cascadia Mono', 'Cascadia Code',
            'DejaVu Sans Mono', 'Liberation Mono', 'Nimbus Mono PS', 'Source Code Pro',
            'Fira Code', 'JetBrains Mono', 'SF Mono', 'PT Mono', 'Inconsolata',
        ],
        public readonly int $quoteIndent = 720,
        public readonly bool $fenceCodeBlocks = true,
        public readonly bool $tableHeader = true,
        public readonly bool $headingSetext = false,
        public readonly ?string $mediaDirectory = null,
        public readonly string $lineEnding = "\n",
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): self
    {
        $known = (new self())->toArray();

        return new self(...array_merge($known, array_intersect_key($options, $known)));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'headingStyles' => $this->headingStyles,
            'quoteStyles' => $this->quoteStyles,
            'monospaceFonts' => $this->monospaceFonts,
            'quoteIndent' => $this->quoteIndent,
            'fenceCodeBlocks' => $this->fenceCodeBlocks,
            'tableHeader' => $this->tableHeader,
            'headingSetext' => $this->headingSetext,
            'mediaDirectory' => $this->mediaDirectory,
            'lineEnding' => $this->lineEnding,
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withAll(array $options): self
    {
        return self::fromArray(array_merge($this->toArray(), $options));
    }

    public function withMediaDirectory(?string $directory): self
    {
        return $this->withAll(['mediaDirectory' => $directory]);
    }
}
