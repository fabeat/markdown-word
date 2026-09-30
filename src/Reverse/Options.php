<?php

declare(strict_types=1);

namespace MarkdownWord\Reverse;

/**
 * Switches for reading a Word document back into Markdown: the points where
 * Word and Markdown genuinely do not agree.
 */
final class Options
{
    /**
     * The properties where `null` is a value in its own right rather than the
     * absence of one; everywhere else it means "not configured" — or, in
     * {@see self::withAll()}, "not mentioned" — and the value that is already
     * there stands.
     *
     * {@see \MarkdownWord\Configuration\Options} keeps the same list for the same
     * reason, and a property added to one of them without a line here is a
     * property whose `null` is handled wrongly.
     *
     * @var list<string>
     */
    private const NULLABLE = ['mediaDirectory'];

    /**
     * @param list<string> $headingStyles Paragraph style ids read as ATX headings,
     *        matched case-insensitively with an optional trailing level, so
     *        `Heading` and `Heading1` to `Heading6` both match.
     * @param list<string> $quoteStyles Paragraph style ids read as block quotes.
     *        Detection is by style, not by indentation: an indented paragraph is
     *        just an indented paragraph, and a list inside a quote carries the
     *        quote's indent without being one.
     * @param list<string> $monospaceFonts One run becomes inline code, two or more
     *        paragraphs in a row a fenced block.
     * @param int          $quoteIndent Twips per level of quote nesting, used both
     *        to recover a quote's depth and to step out of one.
     * @param bool         $tableHeader Whether the first row of a table is the
     *        header. The document does record it, in `w:trPr/w:tblHeader`, and this
     *        reader does not look; see {@see MarkdownWriter::table()}.
     * @param bool         $headingSetext Whether a first- or second-level heading
     *        is underlined. Markdown has only two of those, so deeper headings
     *        keep their hashes.
     * @param string|null  $mediaDirectory A directory, relative to the Markdown,
     *        the images are taken out into. Null keeps the reference pointing at
     *        the name the image has inside the archive, which documents the file
     *        but is not a path any reader can open.
     * @param int          $maxPartBytes The largest one part of the archive may
     *        be once decompressed; {@see Package::read()} applies it. A `.docx` is
     *        somebody else's file, and a zip says nothing about how much room its
     *        contents will take up.
     * @param int          $maxEntries The largest number of parts the archive may
     *        have, read from its central directory and costing no decompression.
     * @param int          $maxStyleDepth How far a `basedOn` chain of styles is
     *        followed; the chain is in the document, and a document is free to
     *        make it a loop. See {@see StyleTable::__construct()}.
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
        public readonly int $maxPartBytes = 268435456,
        public readonly int $maxEntries = 4096,
        public readonly int $maxStyleDepth = 32,
    ) {
    }

    /**
     * Build the reader's options from a plain array, for a `config.php` or a JSON
     * document.
     *
     * A `null` for a property that does not accept one is dropped here rather
     * than passed on, so a config file that names a key it has no value for is
     * read with the default instead of raising a `TypeError` the caller is told
     * nothing about. {@see self::withAll()} reads the same null the other way
     * round, because it has a receiver that would lose a value.
     *
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): self
    {
        // Unknown keys are dropped rather than passed on, so a config file may
        // carry extra entries without breaking the reader.
        $known = (new self())->toArray();
        $given = array_intersect_key($options, $known);

        return new self(...self::cast(array_merge($known, self::withoutUnconfigured($given))));
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
            'maxPartBytes' => $this->maxPartBytes,
            'maxEntries' => $this->maxEntries,
            'maxStyleDepth' => $this->maxStyleDepth,
        ];
    }

    /**
     * Merge a batch of options over this one.
     *
     * A `null` for a property that does not accept one means "not mentioned", so
     * whatever the receiver holds is kept. "Reset to the default" is the other
     * reading of a null and it is wrong here: the caller named a key and no
     * value. It is not a matter of taste either, because the archive limits live
     * on this object — an override array reaching `maxPartBytes` with nothing in
     * it would put a tightened bound back.
     *
     * An empty string is a value too, not an absence: {@see self::cast()} reads
     * `mediaDirectory: ''` as "no media directory" in a merge as much as in
     * {@see self::fromArray()}, and the two ways in must not read the same array
     * differently.
     *
     * @param array<string, mixed> $options
     */
    public function withAll(array $options): self
    {
        return self::fromArray(array_merge($this->toArray(), self::withoutUnconfigured($options)));
    }

    /**
     * @param list<string> $styles
     */
    public function withHeadingStyles(array $styles): self
    {
        return $this->with('headingStyles', $styles);
    }

    /**
     * @param list<string> $styles
     */
    public function withQuoteStyles(array $styles): self
    {
        return $this->with('quoteStyles', $styles);
    }

    /**
     * @param list<string> $fonts
     */
    public function withMonospaceFonts(array $fonts): self
    {
        return $this->with('monospaceFonts', $fonts);
    }

    public function withQuoteIndent(int $twips): self
    {
        return $this->with('quoteIndent', $twips);
    }

    public function withFenceCodeBlocks(bool $fence): self
    {
        return $this->with('fenceCodeBlocks', $fence);
    }

    public function withTableHeader(bool $header): self
    {
        return $this->with('tableHeader', $header);
    }

    public function withHeadingSetext(bool $setext): self
    {
        return $this->with('headingSetext', $setext);
    }

    public function withMediaDirectory(?string $directory): self
    {
        return $this->with('mediaDirectory', $directory);
    }

    public function withLineEnding(string $ending): self
    {
        return $this->with('lineEnding', $ending);
    }

    public function withMaxPartBytes(int $bytes): self
    {
        return $this->with('maxPartBytes', $bytes);
    }

    public function withMaxEntries(int $entries): self
    {
        return $this->with('maxEntries', $entries);
    }

    public function withMaxStyleDepth(int $depth): self
    {
        return $this->with('maxStyleDepth', $depth);
    }

    /**
     * Change one option and leave the rest as they are.
     *
     * Merging through {@see self::withAll()} is the whole point: the archive
     * limits live on this object too, so replacing it wholesale would quietly
     * undo a tightened `maxPartBytes` or `maxStyleDepth` on the next unrelated
     * call.
     */
    private function with(string $property, mixed $value): self
    {
        return $this->withAll([$property => $value]);
    }

    /**
     * The keys of `$options` that say nothing: a `null` for a property that does
     * not accept one.
     *
     * Both entry points drop them and they mean different things afterwards —
     * {@see self::fromArray()} falls back to the default, {@see self::withAll()}
     * to the receiver — but whether a `null` is a value or an absence is a
     * property of the class rather than of the caller, so it is decided here,
     * once, and by {@see self::NULLABLE}.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function withoutUnconfigured(array $options): array
    {
        foreach (array_keys($options) as $property) {
            if ($options[$property] === null && !in_array($property, self::NULLABLE, true)) {
                unset($options[$property]);
            }
        }

        return $options;
    }

    /**
     * Coerce loosely typed configuration values from a config file into the exact
     * types the constructor demands.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function cast(array $options): array
    {
        $bool = ['fenceCodeBlocks', 'tableHeader', 'headingSetext'];
        foreach ($bool as $key) {
            if (isset($options[$key])) {
                $options[$key] = (bool) $options[$key];
            }
        }

        // Every one of these is a count, an offset or a limit, and every one is
        // meaningless — or wrong in a way that is hard to see — below one.
        // `quoteIndent` in particular is a divisor when a quote is nested.
        foreach (['quoteIndent', 'maxPartBytes', 'maxEntries', 'maxStyleDepth'] as $key) {
            if (isset($options[$key])) {
                $options[$key] = max(1, (int) $options[$key]);
            }
        }

        if (array_key_exists('mediaDirectory', $options) && $options['mediaDirectory'] === '') {
            // An empty string is not a directory. Taken as one it is the root of
            // the filesystem: the images go to `/name`, and the Markdown refers
            // to `/name` as an absolute path.
            $options['mediaDirectory'] = null;
        }

        return $options;
    }
}
