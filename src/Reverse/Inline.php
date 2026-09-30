<?php

declare(strict_types=1);

namespace MarkdownWord\Reverse;

/**
 * One inline run read back out of a Word document.
 *
 * Word stores emphasis as properties of a run rather than as a nesting of
 * containers, so the tree is deliberately flat: a run carries the four things
 * Markdown can express inline, and the serialiser rebuilds the nesting from
 * them. That is the inverse of what {@see \MarkdownWord\Render\InlineRenderer} does
 * on the way out — and it preserves exactly what Word recorded, which is not the
 * same thing as preserving the Markdown: a fenced code block comes back without
 * its language, and a table is written with a header whether the document marked
 * one. {@see \MarkdownWord\WordToMarkdown} lists the distinctions Word does not
 * keep.
 */
final class Inline
{
    public const TEXT = 'text';
    public const BREAK = 'break';
    public const IMAGE = 'image';
    public const LINK = 'link';

    /**
     * @param string                $kind     One of the `TEXT`, `BREAK`, `IMAGE`
     *        or `LINK` constants.
     * @param string                $text     The literal characters, for a text run.
     * @param bool                  $bold     Whether the run is bold.
     * @param bool                  $italic   Whether the run is italic.
     * @param bool                  $strike   Whether the run is struck through.
     * @param bool                  $code     Whether the run is set in a monospaced
     *        face, which is how Word represents an inline code span.
     * @param string                $url      The destination of a link.
     * @param string|null           $title    The tooltip of a link, if it has one.
     * @param string                $alt      The alternative text of an image.
     * @param string                $target   Where an image can be found, relative to
     *        the Markdown document being written.
     * @param list<Inline>          $children The label of a link, which may itself
     *        contain formatting.
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $text = '',
        public readonly bool $bold = false,
        public readonly bool $italic = false,
        public readonly bool $strike = false,
        public readonly bool $code = false,
        public readonly string $url = '',
        public readonly ?string $title = null,
        public readonly string $alt = '',
        public readonly string $target = '',
        public readonly array $children = [],
    ) {
    }

    public static function text(
        string $text,
        bool $bold = false,
        bool $italic = false,
        bool $strike = false,
        bool $code = false,
    ): self {
        return new self(self::TEXT, $text, $bold, $italic, $strike, $code);
    }

    /** A line break the author asked for, as opposed to a paragraph boundary. */
    public static function break(): self
    {
        return new self(self::BREAK);
    }

    public static function image(string $alt, string $target): self
    {
        return new self(self::IMAGE, alt: $alt, target: $target);
    }

    /**
     * @param list<Inline> $children The label, which may contain emphasis.
     */
    public static function link(string $url, ?string $title, array $children): self
    {
        return new self(self::LINK, url: $url, title: $title, children: $children);
    }

    public function is(string ...$kinds): bool
    {
        return in_array($this->kind, $kinds, true);
    }

    /**
     * Whether two runs can share one Markdown span because they are formatted
     * identically.
     */
    public function sameFormatting(self $other): bool
    {
        return $this->bold === $other->bold
            && $this->italic === $other->italic
            && $this->strike === $other->strike
            && $this->code === $other->code;
    }

    public function withText(string $text): self
    {
        return new self(
            $this->kind,
            $text,
            $this->bold,
            $this->italic,
            $this->strike,
            $this->code,
            $this->url,
            $this->title,
            $this->alt,
            $this->target,
            $this->children,
        );
    }
}
