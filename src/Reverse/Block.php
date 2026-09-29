<?php

declare(strict_types=1);

namespace MarkdownWord\Reverse;

/**
 * One block-level element read back out of a Word document.
 *
 * A tagged union rather than a class per kind: the reader fills it in and the
 * serialiser switches on `kind`, and keeping the tree in one type means the
 * grouping passes that follow (code blocks, then lists, then quotes) can
 * rearrange a flat sequence of units without a cast at every step.
 */
final class Block
{
    public const PARAGRAPH = 'paragraph';
    public const RULE = 'rule';
    public const CODE = 'code';
    public const LIST = 'list';
    public const ITEM = 'item';
    public const TABLE = 'table';
    public const ROW = 'row';
    public const CELL = 'cell';
    public const QUOTE = 'quote';

    /**
     * @param string               $kind     One of the `KIND` constants.
     * @param list<Block>          $children Nested blocks: list items in a list,
     *        the body of a quote, the rows of a table, and so on.
     * @param list<Inline>         $inlines  The inline content of a paragraph.
     * @param array<string, mixed> $attrs    Kind-specific detail; see the factories.
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $children = [],
        public readonly array $inlines = [],
        public readonly array $attrs = [],
    ) {
    }

    /**
     * @param list<Inline> $inlines
     * @param int|null     $headingLevel 1 to 6 for a heading, null for body text.
     * @param array<string, mixed> $attrs
     */
    public static function paragraph(array $inlines, ?int $headingLevel = null, array $attrs = []): self
    {
        return new self(self::PARAGRAPH, inlines: $inlines, attrs: ['level' => $headingLevel] + $attrs);
    }

    public static function rule(): self
    {
        return new self(self::RULE);
    }

    /**
     * A verbatim block: a fenced code block, or an indented one.
     *
     * @param string $info The language hint, when the document carried one.
     */
    public static function code(string $text, string $info = ''): self
    {
        return new self(self::CODE, attrs: ['text' => $text, 'info' => $info]);
    }

    /**
     * @param list<Block> $items `ITEM` blocks.
     * @param array<string, mixed> $attrs `numId`, `level`, `start`, `delimiter`
     *        and `numberStyle`, all taken from the document's numbering part.
     */
    public static function list(array $items, array $attrs = []): self
    {
        return new self(self::LIST, children: $items, attrs: $attrs);
    }

    /**
     * @param list<Block> $blocks
     */
    public static function item(array $blocks): self
    {
        return new self(self::ITEM, children: $blocks);
    }

    /**
     * @param list<Block>  $rows  `ROW` blocks.
     * @param list<string> $alignments One of `left`, `center`, `right` or `` per
     *        column, as read from the cell paragraphs.
     */
    public static function table(array $rows, array $alignments = []): self
    {
        return new self(self::TABLE, children: $rows, attrs: ['alignments' => $alignments]);
    }

    /**
     * @param list<Block> $cells
     */
    public static function row(array $cells): self
    {
        return new self(self::ROW, children: $cells);
    }

    /**
     * @param list<Block> $blocks
     */
    public static function cell(array $blocks): self
    {
        return new self(self::CELL, children: $blocks);
    }

    /**
     * @param list<Block> $blocks
     */
    public static function quote(array $blocks): self
    {
        return new self(self::QUOTE, children: $blocks);
    }

    public function withChildren(array $children): self
    {
        return new self($this->kind, $children, $this->inlines, $this->attrs);
    }

    /**
     * @param list<Inline> $inlines
     */
    public function withInlines(array $inlines): self
    {
        return new self($this->kind, $this->children, $inlines, $this->attrs);
    }

    public function withAttrs(array $attrs): self
    {
        return new self($this->kind, $this->children, $this->inlines, $this->attrs + $attrs);
    }

    public function attr(string $name, mixed $default = null): mixed
    {
        return $this->attrs[$name] ?? $default;
    }

    public function is(string ...$kinds): bool
    {
        return in_array($this->kind, $kinds, true);
    }
}
