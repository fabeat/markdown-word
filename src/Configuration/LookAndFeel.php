<?php

declare(strict_types=1);

namespace MarkdownWord\Configuration;

/**
 * The formatting a slot has before anybody configures it.
 *
 * A default is written as properties rather than as a reference to a style in
 * Word's catalogue, and that is what makes the same Markdown look the same in a
 * `.docx`, an `.odt` and an `.rtf`: the ODF and RTF writers resolve a named style
 * against a stylesheet of their own, so a heading that is only `Heading1` comes
 * out of either of them as body text.
 *
 * `styleName` is the exception and is the reason a heading is still a heading in
 * Word. It names the built-in style the paragraph also references, and
 * {@see \MarkdownWord\Render\StyleRegistrar} writes the definition of it into a
 * document built from scratch, so the `w:pStyle` a `.docx` heading carries
 * resolves to something. Direct formatting rides alongside it rather than
 * instead of it: the name is what a template's own `Heading1` has to be called,
 * and the properties are what every reader sees.
 *
 * A style name alone is not an outline level, and nothing here pretends it is —
 * `tests/Unit/look-and-feel.php` says what it does and does not amount to, and
 * what PHPWord gives no way to write.
 *
 * A slot configured with a style *name* means the opposite — a template saying
 * what its own `Heading1` looks like — and is left exactly as it was written.
 * {@see \MarkdownWord\Configuration::withBuiltInHeadingStyles()} is how Word's own
 * styles are asked for.
 *
 * Sizes are in points, spacings in twips (a twentieth of a point), and colours are
 * six hexadecimal digits with no leading `#`, which is the spelling
 * `w:color` takes.
 *
 * {@see Styles::FONT_KEYS} and {@see Styles::PARAGRAPH_KEYS} are the two lists every
 * key below has to be in, because the registrar and the validator read them and a
 * key in neither is one that is accepted and then dropped.
 */
final class LookAndFeel
{
    /** The background a code block is drawn on when `codeBlockShading` is on. */
    public const CODE_BACKGROUND = 'F2F2F2';

    /**
     * The properties of every slot that has one, keyed by slot.
     *
     * `Styles::defaults()` is this with the slots that are not a look at all added
     * to it — the numbering definitions a list points at, the table slots, which are
     * handed a `Table`, and the ones nobody styled.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function slots(): array
    {
        return [
            Styles::HEADING_1 => [
                'styleName' => 'Heading1',
                'bold' => true, 'size' => 16, 'color' => '2F5496',
                'space' => ['before' => 240, 'after' => 120], 'keepNext' => true,
            ],
            Styles::HEADING_2 => [
                'styleName' => 'Heading2',
                'bold' => true, 'size' => 13, 'color' => '2F5496',
                'space' => ['before' => 200, 'after' => 100], 'keepNext' => true,
            ],
            Styles::HEADING_3 => [
                'styleName' => 'Heading3',
                'bold' => true, 'size' => 12, 'color' => '1F3763',
                'space' => ['before' => 160, 'after' => 80], 'keepNext' => true,
            ],
            Styles::HEADING_4 => [
                'styleName' => 'Heading4',
                'bold' => true, 'italic' => true, 'size' => 11, 'color' => '2F5496',
                'space' => ['before' => 140, 'after' => 80], 'keepNext' => true,
            ],
            Styles::HEADING_5 => [
                'styleName' => 'Heading5',
                'bold' => true, 'size' => 11, 'color' => '2F5496',
                'space' => ['before' => 120, 'after' => 60], 'keepNext' => true,
            ],
            Styles::HEADING_6 => [
                'styleName' => 'Heading6',
                'italic' => true, 'size' => 11, 'color' => '1F3763',
                'space' => ['before' => 120, 'after' => 60], 'keepNext' => true,
            ],

            Styles::PARAGRAPH => [
                'space' => ['after' => 120],
                'lineHeight' => 1.15,
            ],

            // Indented on both sides, which is what tells a reader the text is being
            // quoted rather than merely set apart. A rule down the left would say it
            // better and only Word's writer carries one.
            Styles::BLOCK_QUOTE => [
                'styleName' => 'IntenseQuote',
                'italic' => true, 'color' => '404040',
                'indentation' => ['left' => 720, 'right' => 720],
                'space' => ['before' => 120, 'after' => 120],
            ],

            // No typeface and no colour of its own: those are `codeFont`, so a
            // code span and a code block agree without either of them naming the
            // other. The background is not here either, because `codeBlockShading`
            // is the switch that decides whether there is one.
            Styles::CODE_BLOCK => [
                'indentation' => ['left' => 360],
                'space' => ['before' => 0, 'after' => 0],
            ],

            Styles::LIST_PARAGRAPH => [
                'space' => ['after' => 60],
            ],

            Styles::CODE_FONT => [
                'name' => 'Consolas',
                'size' => 9,
                'color' => 'A31515',
            ],

            Styles::LINK_FONT => [
                'color' => '0563C1',
                'underline' => 'single',
            ],
        ];
    }

    /**
     * The Word style ids this library defines rather than leaving to a template.
     *
     * A name outside this set belongs to whoever wrote it — {@see \MarkdownWord\Render\StyleRegistrar}
     * defines the built-ins into a document built from scratch and stays out of the
     * way of a document whose styles are already there.
     *
     * @return list<string>
     */
    public static function ownedStyleIds(): array
    {
        $ids = [];

        foreach (self::slots() as $definition) {
            if (isset($definition['styleName']) && is_string($definition['styleName'])) {
                $ids[] = $definition['styleName'];
            }
        }

        return array_values(array_unique($ids));
    }
}