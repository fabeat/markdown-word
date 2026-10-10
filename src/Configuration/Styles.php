<?php

declare(strict_types=1);

namespace MarkdownWord\Configuration;

/**
 * Immutable registry of named style slots. Every visual decision the renderer
 * makes is routed through a slot name (for example `heading.1`, `codeFont` or
 * `bulletList`), which holds either a **styleId**, an inline style definition as
 * an array, or `null` for no styling.
 *
 * A styleId has no spaces even though the style's display name in the Word UI
 * does: the built-in heading styles are `Heading1`…`Heading6`, not
 * `Heading 1`. Because the slots are plain names, pointing the renderer at the
 * styleIds of a template of your own is a configuration change rather than a
 * change to the code.
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
    /**
     * The keys a *font or paragraph* slot's array form understands.
     *
     * {@see \MarkdownWord\Render\StyleRegistrar} maps each of these onto a `set<Key>()`
     * on PHPWord's font and paragraph styles and ignores anything else, so this is the
     * list of names that can be written rather than the list of names that are read.
     * Both live here because a key added to one and not the other is a key that is
     * silently dropped.
     *
     * The table slots are not on this list and never were: `table`, `tableCell` and
     * `tableHeaderRow` are handed a `Table`, a `Cell` and a `Row`, each with names of
     * its own — `borderColor`, `vAlign`, `tblHeader`. {@see \MarkdownWord\Configuration\Validator}
     * reads those three sets off the classes, which is where the two kinds of slot are
     * told apart.
     */
    public const FONT_KEYS = ['name', 'size', 'color', 'bold', 'italic', 'strikethrough', 'underline'];

    public const PARAGRAPH_KEYS = ['indentation', 'space', 'shading', 'keepNext', 'alignment'];

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
            // The styleIds of the built-in Word styles, which is what Word
            // resolves a `w:pStyle` against. Using the display names ("Heading
            // 1") would leave the text unstyled.
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
     * Resolve the style for a heading level.
     *
     * This is the one place the level-to-slot-name mapping is written down. The name
     * is composed by hand where it is needed rather than resolved —
     * `DocumentRenderer::renderHeading()`, `StyleRegistrar::register()` and
     * `Configuration::withBuiltInHeadingStyles()` — and
     * `tests/Unit/styles-fixes.php` pins the constants to the composed names so the
     * two sides cannot drift apart unnoticed in the meantime.
     *
     * A level explicitly set to `null` resolves to the paragraph style, so that a
     * heading can be given up without its own default spacing and size. Only an
     * explicit `null`: the constructor merges the defaults in, so a level nobody
     * mentioned always has a slot of its own, and treating "not configured" as
     * "not mentioned" would resolve every heading to the paragraph style under the
     * default configuration.
     *
     * @param int $level A heading level; anything outside 1-6 is clamped into it.
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
