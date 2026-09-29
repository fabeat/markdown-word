<?php

declare(strict_types=1);

namespace MarkdownWord\Configuration;

/**
 * Immutable set of behavioural switches.
 *
 * While {@see Styles} decides *how things look*, these options decide *what
 * gets rendered* in situations where Markdown and Word do not map onto each
 * other one-to-one.
 */
final class Options
{
    /** Soft line breaks inside a paragraph collapse to a single space (spec behaviour). */
    public const SOFT_BREAK_SPACE = 'space';

    /** Soft line breaks become a real Word line break. */
    public const SOFT_BREAK_LINE = 'lineBreak';

    /** Soft line breaks split the paragraph into separate Word paragraphs. */
    public const SOFT_BREAK_PARAGRAPH = 'paragraph';

    public const BREAK_REMOVE = 'remove';
    public const BREAK_LINE = 'line';
    public const BREAK_PARAGRAPH = 'paragraph';

    /** Remove the tags from raw HTML but keep the text they wrap, as a browser would. */
    public const HTML_STRIP = 'strip';

    /** Keep raw HTML as literal, monospaced text. */
    public const HTML_PRESERVE = 'preserve';

    /** Discard raw HTML and the text inside it. */
    public const HTML_DROP = 'drop';

    public const IMAGE_EMBED = 'embed';
    public const IMAGE_PLACEHOLDER = 'placeholder';
    public const IMAGE_SKIP = 'skip';

    /**
     * @param string          $softBreak           How to render a CommonMark soft line break.
     * @param string          $hardBreak           How to render a hard line break (two spaces, backslash or `<br>`).
     * @param string          $html                Handling of raw HTML blocks and inline HTML.
     * @param string          $images              Handling of images: embed into the document, emit alt-text placeholder, or omit.
     * @param string|null     $imageBasePath       Base directory used to resolve relative image paths.
     * @param float           $imageMaxWidth       Maximum image width in centimetres. `0` disables scaling.
     * @param int             $maxHeadingLevel     Headings deeper than this are rendered as paragraphs.
     * @param string          $orderedListFormat   `w:numFmt` value used for ordered lists (decimal, lowerLetter, ...).
     * @param string|null     $orderedListSuffix   Separator between the number and the text: `tab`, `space` or `nothing`.
     * @param bool            $tableBorders        Draw borders around table cells.
     * @param bool            $tableHeaderBold     Render the first table row in bold.
     * @param int             $tableWidth          Table width in fiftieths of a percent of the text column.
     *        `5000` — the default — is the full width, which is what a table read
     *        as a table rather than as a fragment of one should be. `0` leaves the
     *        width to Word's automatic sizing.
     * @param bool            $codeBlockShading    Give code blocks a light background.
     * @param string          $linkTarget          `w:hyperlink` target: `_blank` or `_self`.
     * @param string          $thematicBreak       Horizontal rule rendering: `border` (paragraph rule) or `text` (a row of dashes).
     * @param bool            $deferredHyperlinks   Write every link as a placeholder and resolve it while
     *        the file is written, instead of letting PHPWord emit `w:hyperlink` directly. Needed when the
     *        rendered elements are copied into another document — as the template renderer does — because
     *        a hyperlink refers to a relationship that belongs to the document it was created in.
     */
    public function __construct(
        public readonly string $softBreak = self::SOFT_BREAK_SPACE,
        public readonly string $hardBreak = self::BREAK_LINE,
        public readonly string $html = self::HTML_STRIP,
        public readonly string $images = self::IMAGE_EMBED,
        public readonly ?string $imageBasePath = null,
        public readonly float $imageMaxWidth = 15.0,
        public readonly int $maxHeadingLevel = 6,
        public readonly string $orderedListFormat = 'decimal',
        public readonly ?string $orderedListSuffix = 'tab',
        public readonly bool $tableBorders = true,
        public readonly bool $tableHeaderBold = true,
        public readonly int $tableWidth = 5000,
        public readonly bool $codeBlockShading = true,
        public readonly string $linkTarget = '_blank',
        public readonly string $thematicBreak = 'border',
        public readonly bool $deferredHyperlinks = false,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): self
    {
        // Unknown keys are dropped rather than passed on, so a config file may
        // carry extra entries without breaking the renderer.
        $known = (new self())->toArray();

        return new self(...self::cast(array_merge($known, array_intersect_key($options, $known))));
    }

    public function withSoftBreak(string $mode): self
    {
        return $this->with('softBreak', $mode);
    }

    public function withHardBreak(string $mode): self
    {
        return $this->with('hardBreak', $mode);
    }

    public function withHtml(string $mode): self
    {
        return $this->with('html', $mode);
    }

    public function withImages(string $mode, ?string $basePath = null, ?float $maxWidth = null): self
    {
        return $this->withAll(array_filter([
            'images' => $mode,
            'imageBasePath' => $basePath,
            'imageMaxWidth' => $maxWidth,
        ], static fn (mixed $value): bool => $value !== null));
    }

    public function withMaxHeadingLevel(int $level): self
    {
        return $this->with('maxHeadingLevel', $level);
    }

    public function withTableBorders(bool $borders): self
    {
        return $this->with('tableBorders', $borders);
    }

    /**
     * @param int $width Fiftieths of a percent of the text column, so `5000` is
     *                   the full width and `0` hands the sizing back to Word.
     */
    public function withTableWidth(int $width): self
    {
        return $this->with('tableWidth', $width);
    }

    public function withCodeBlockShading(bool $shading): self
    {
        return $this->with('codeBlockShading', $shading);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withAll(array $options): self
    {
        return self::fromArray(array_merge($this->toArray(), $options));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'softBreak' => $this->softBreak,
            'hardBreak' => $this->hardBreak,
            'html' => $this->html,
            'images' => $this->images,
            'imageBasePath' => $this->imageBasePath,
            'imageMaxWidth' => $this->imageMaxWidth,
            'maxHeadingLevel' => $this->maxHeadingLevel,
            'orderedListFormat' => $this->orderedListFormat,
            'orderedListSuffix' => $this->orderedListSuffix,
            'tableBorders' => $this->tableBorders,
            'tableHeaderBold' => $this->tableHeaderBold,
            'tableWidth' => $this->tableWidth,
            'codeBlockShading' => $this->codeBlockShading,
            'linkTarget' => $this->linkTarget,
            'thematicBreak' => $this->thematicBreak,
            'deferredHyperlinks' => $this->deferredHyperlinks,
        ];
    }

    private function with(string $property, mixed $value): self
    {
        return self::fromArray([$property => $value]);
    }

    /**
     * Coerce loosely typed configuration values (typically coming from a PHP,
     * JSON or YAML config file) into the exact types the constructor demands.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function cast(array $options): array
    {
        $bool = ['tableBorders', 'tableHeaderBold', 'codeBlockShading', 'deferredHyperlinks'];
        foreach ($bool as $key) {
            if (isset($options[$key])) {
                $options[$key] = (bool) $options[$key];
            }
        }

        if (isset($options['maxHeadingLevel'])) {
            $options['maxHeadingLevel'] = max(1, min(6, (int) $options['maxHeadingLevel']));
        }

        if (isset($options['imageMaxWidth'])) {
            $options['imageMaxWidth'] = max(0.0, (float) $options['imageMaxWidth']);
        }

        if (array_key_exists('tableWidth', $options)) {
            $options['tableWidth'] = max(0, min(5000, (int) $options['tableWidth']));
        }

        if (array_key_exists('imageBasePath', $options) && $options['imageBasePath'] === '') {
            $options['imageBasePath'] = null;
        }

        return $options;
    }
}
