<?php

declare(strict_types=1);

namespace MarkdownWord\Configuration;

/**
 * Immutable registry of named style slots.
 *
 * Every visual decision the renderer makes is routed through a slot name (for
 * example `heading.1`, `codeFont` or `bulletList`). A slot holds either
 *
 *  - a string: the **styleId** of a style, which is how Word references styles
 *    and what a template must define. Note that a styleId has no spaces even
 *    though the style's display name in the Word UI does: the built-in heading
 *    styles are `Heading1`…`Heading6`, not `Heading 1`.
 *  - an array: an inline style definition applied directly to the runs and
 *    paragraphs that use it.
 *  - `null`: no styling.
 *
 * Because slots are plain names, you can point the renderer at the styleIds of
 * your own corporate template and get a pixel-perfect result without touching a
 * line of code.
 */
final class Styles
{
    /** Heading paragraph styles, keyed by level 1-6. */
    public const HEADING_1 = 'heading.1';
    public const HEADING_2 = 'heading.2';
    public const HEADING_3 = 'heading.3';
    public const HEADING_4 = 'heading.4';
    public const HEADING_5 = 'heading.5';
    public const HEADING_6 = 'heading.6';

    public const PARAGRAPH = 'paragraph';
    public const BLOCK_QUOTE = 'blockQuote';
    public const CODE_BLOCK = 'codeBlock';
    public const THEMATIC_BREAK = 'thematicBreak';
    public const HTML_FALLBACK = 'htmlFallback';
    public const LIST_PARAGRAPH = 'listParagraph';

    public const CODE_FONT = 'codeFont';
    public const LINK_FONT = 'linkFont';

    public const BULLET_LIST = 'bulletList';
    public const ORDERED_LIST = 'orderedList';

    public const TABLE = 'table';
    public const TABLE_HEADER_ROW = 'tableHeaderRow';
    public const TABLE_CELL = 'tableCell';

    /**
     * @var array<string, mixed>
     */
    private array $slots;

    /**
     * @param array<string, mixed> $slots Slot overrides merged over the defaults.
     */
    public function __construct(array $slots = [])
    {
        $this->slots = array_merge(self::defaults(), $slots);
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            // Paragraph styles. These are the styleIds of the built-in Word
            // styles, which is what Word resolves a `w:pStyle` against. Using the
            // display names ("Heading 1") would leave the text unstyled.
            self::HEADING_1 => 'Heading1',
            self::HEADING_2 => 'Heading2',
            self::HEADING_3 => 'Heading3',
            self::HEADING_4 => 'Heading4',
            self::HEADING_5 => 'Heading5',
            self::HEADING_6 => 'Heading6',
            self::PARAGRAPH => null,
            self::BLOCK_QUOTE => 'IntenseQuote',
            self::CODE_BLOCK => null,
            self::THEMATIC_BREAK => null,
            self::HTML_FALLBACK => null,
            self::LIST_PARAGRAPH => null,

            // Font styles.
            self::CODE_FONT => [
                'name' => 'Consolas',
                'size' => 9,
                'color' => 'A31515',
            ],
            self::LINK_FONT => [
                'color' => '0563C1',
                'underline' => 'single',
            ],

            // Numbering style names, created on demand by the renderer.
            self::BULLET_LIST => 'MarkdownWord-Bullet',
            self::ORDERED_LIST => 'MarkdownWord-Ordered',

            // Table styling.
            self::TABLE => null,
            self::TABLE_HEADER_ROW => null,
            self::TABLE_CELL => null,
        ];
    }

    /**
     * @return mixed The style for `$slot`, or `$default` when the slot is unset.
     */
    public function get(string $slot, mixed $default = null): mixed
    {
        return $this->slots[$slot] ?? $default;
    }

    /**
     * Resolve the style for a heading level, falling back to the paragraph style
     * when that level has not been configured.
     */
    public function heading(int $level): mixed
    {
        return $this->get('heading.' . max(1, min(6, $level)), $this->get(self::PARAGRAPH));
    }

    public function with(string $slot, mixed $style): self
    {
        return new self([$slot => $style] + $this->slots);
    }

    /**
     * @param array<string, mixed> $slots
     */
    public function withAll(array $slots): self
    {
        return new self($slots + $this->slots);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->slots;
    }
}
