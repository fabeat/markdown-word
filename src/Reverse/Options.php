<?php

declare(strict_types=1);

namespace MarkdownWord\Reverse;

/**
 * Switches for reading a Word document back into Markdown: the points where
 * Word and Markdown genuinely do not agree.
 *
 * The two ways in from an array read a `null` differently, and deliberately so:
 * {@see self::fromArray()} has nothing to lose by ignoring one, so it reads it
 * as "not configured" and the default stands, while {@see self::withAll()} is
 * handed a receiver that already holds a value, so it reads it as "not mentioned"
 * and keeps it. Each says so where it is implemented.
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
     * @param list<string> $headingStyles Paragraph style ids read as ATX
     *        headings, matched case-insensitively with an optional trailing
     *        level, so `Heading` and `Heading1` to `Heading6` both match.
     * @param list<string> $quoteStyles Paragraph style ids read as block quotes.
     *        Detection is by style, not by indentation, because an indented
     *        paragraph is just an indented paragraph and a list inside a quote
     *        carries the quote's indent without being one.
     * @param list<string> $monospaceFonts Typefaces treated as a code span: one
     *        run becomes inline code, two or more paragraphs in a row a fenced
     *        block.
     * @param int          $quoteIndent Twips per level of quote nesting, used both
     *        to recover a quote's depth and to step out of one.
     * @param bool         $tableHeader Whether the first row of a table is the
     *        header; see {@see MarkdownWriter::table()} for why the document
     *        cannot be asked.
     * @param bool         $headingSetext Whether a first- or second-level heading
     *        is underlined. Markdown has only two of those, so deeper headings
     *        keep their hashes.
     * @param string|null  $mediaDirectory A directory, relative to the Markdown,
     *        the images are taken out into. Null keeps the reference pointing at
     *        the name the image has inside the archive, which documents the file
     *        but is not a path any reader can open. An empty string is read as
     *        null, not as the root of the filesystem.
     * @param int          $maxPartBytes The largest one part of the archive may
     *        be once decompressed; {@see Package::read()} applies it. A `.docx`
     *        is somebody else's file, and a zip says nothing about how much room
     *        its contents will take up.
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
     * A `null` for a property that is not nullable by design means "not
     * configured" here, so the default below fills it back in. Left in place it
     * would reach the constructor and raise a `TypeError` — a config file that
     * spells out a key it has no value for is a config file that cannot be read
     * at all, and the caller is told nothing about which key it was. A `null`
     * for a property that *is* nullable by design is a value, and is kept.
     *
     * {@see self::withAll()} reads the same null the other way round, because it
     * has a receiver that would lose a value: there it means "not mentioned".
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
     * A `null` for a property that is not nullable by design means "not
     * mentioned", so whatever the receiver holds for it is kept. "Reset to the
     * default" is the other possible reading of a null, and it is the one
     * {@see self::fromArray()} needs — there is no receiver, so nothing can be
     * lost by ignoring it — but it is wrong here: the caller named a key and no
     * value, and reverting it would change an option they never mentioned. On
     * this object that is not a matter of taste either, because the archive
     * limits live here: an override array that reaches `maxPartBytes` with no
     * value in it would put a tightened bound back to 256 MB.
     *
     * A `null` for a property that is nullable by design is the value, and is
     * applied — `withMediaDirectory(null)` is the setter for it.
     *
     * An empty string is a value too, not an absence. {@see self::cast()} reads
     * `mediaDirectory: ''` as "no media directory", in a merge as much as in
     * {@see self::fromArray()}; the two ways in must not read the same array
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

    /**
     * @param int $twips The indentation of one level of quote.
     */
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

    /**
     * @param int $bytes The largest a decompressed part may be.
     */
    public function withMaxPartBytes(int $bytes): self
    {
        return $this->with('maxPartBytes', $bytes);
    }

    /**
     * @param int $entries The largest number of parts the archive may have.
     */
    public function withMaxEntries(int $entries): self
    {
        return $this->with('maxEntries', $entries);
    }

    /**
     * @param int $depth How far a `basedOn` chain is followed.
     */
    public function withMaxStyleDepth(int $depth): self
    {
        return $this->with('maxStyleDepth', $depth);
    }

    /**
     * Change one option and leave the rest as they are.
     *
     * Merging through `withAll()` is the whole point: the archive limits live on
     * this object too, so replacing it wholesale would quietly undo a tightened
     * `maxPartBytes` or `maxStyleDepth` on the next unrelated call.
     *
     * `withMediaDirectory()` is the one setter that can be handed a null, and
     * there it is a value rather than an absence — `mediaDirectory` is nullable
     * by design, so a null clears it. {@see self::withAll()} says how a null for
     * every other property reads.
     */
    private function with(string $property, mixed $value): self
    {
        return $this->withAll([$property => $value]);
    }

    /**
     * The keys of `$options` that say nothing: a `null` for a property that does
     * not accept one, so it cannot be told apart from the absence of a value.
     *
     * Both entry points drop them, and they mean different things afterwards —
     * {@see self::fromArray()} falls back to the default, {@see self::withAll()}
     * to the receiver — but whether a `null` is a value or an absence is a
     * property of the class rather than of the caller, so it is decided here,
     * once, and by the same list the forward direction uses.
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
