<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

use MarkdownWord\Configuration\Styles;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Style;
use PhpOffice\PhpWord\Style\Paragraph;

/**
 * Writes the style *definitions* a freshly generated document needs.
 *
 * A fresh `styles.xml` carries only `Normal` and `FootnoteReference`, so a
 * paragraph that merely references `Heading1` is drawn as body text by anything
 * that does not know Word's built-ins. Defining them keeps a standalone document
 * self-contained while still using the styleIds Word recognises, which is what
 * preserves outline levels and any table of contents inserted later.
 *
 * Not run when rendering into a template, which is the authority there.
 */
final class StyleRegistrar
{
    /** Word's built-in heading styles, following the default theme. */
    private const HEADINGS = [
        'Heading1' => [
            'bold' => true, 'size' => 16, 'color' => '2F5496',
            'space' => ['before' => 240, 'after' => 0], 'keepNext' => true,
        ],
        'Heading2' => [
            'bold' => true, 'size' => 13, 'color' => '2F5496',
            'space' => ['before' => 200, 'after' => 0], 'keepNext' => true,
        ],
        'Heading3' => [
            'bold' => true, 'size' => 12, 'color' => '1F3763',
            'space' => ['before' => 160, 'after' => 0], 'keepNext' => true,
        ],
        'Heading4' => [
            'bold' => true, 'italic' => true, 'size' => 11, 'color' => '2F5496',
            'space' => ['before' => 140, 'after' => 0], 'keepNext' => true,
        ],
        'Heading5' => [
            'bold' => true, 'size' => 11, 'color' => '2F5496',
            'space' => ['before' => 120, 'after' => 0], 'keepNext' => true,
        ],
        'Heading6' => [
            'italic' => true, 'size' => 11, 'color' => '1F3763',
            'space' => ['before' => 120, 'after' => 0], 'keepNext' => true,
        ],
    ];

    /** The look of a block quote, matching Word's `Intense Quote`. */
    private const BLOCK_QUOTE = [
        'italic' => true,
        'color' => '404040',
        'indentation' => ['left' => 720, 'right' => 720],
        'space' => ['before' => 120, 'after' => 120],
    ];

    /** The look of a code block, matching Word's `Source Code`. */
    private const CODE_BLOCK = [
        'name' => 'Consolas',
        'size' => 9,
        'shading' => ['fill' => 'F2F2F2'],
        'indentation' => ['left' => 360],
        'space' => ['before' => 0, 'after' => 0],
    ];

    public function __construct(private readonly Styles $styles)
    {
    }

    public function register(PhpWord $phpWord): void
    {
        for ($level = 1; $level <= 6; $level++) {
            $id = 'Heading' . $level;

            // Only the built-in ids are filled in: a custom style name belongs to
            // the user's own template.
            if ($this->styles->get('heading.' . $level) === $id) {
                $this->define($phpWord, $id, self::HEADINGS[$id]);
            }
        }

        if ($this->styles->get(Styles::BLOCK_QUOTE) === 'IntenseQuote') {
            $this->define($phpWord, 'IntenseQuote', self::BLOCK_QUOTE);
        }

        if ($this->styles->get(Styles::CODE_BLOCK) === 'SourceCode') {
            $this->define($phpWord, 'SourceCode', self::CODE_BLOCK);
        }
    }

    private function define(PhpWord $phpWord, string $id, array $definition): void
    {
        // `setStyleValues()` skips its whole body once a name is taken, so a
        // duplicate id does not update the registry — a static, cleared only by
        // `new PhpWord()`. Rendering into one `PhpWord` twice therefore keeps the
        // first definition, which is what this guard makes explicit.
        if (Style::getStyle($id) !== null) {
            return;
        }

        // The array form maps each key to `set<Key>()` and silently ignores one
        // that does not exist, so the keys are the property names PHPWord's Font
        // style has — `italic`, not `italics`.
        $font = [];
        foreach (['name', 'size', 'color', 'bold', 'italic', 'strikethrough', 'underline'] as $key) {
            if (array_key_exists($key, $definition)) {
                $font[$key] = $definition[$key];
            }
        }

        $paragraph = new Paragraph();
        foreach (['indentation', 'space', 'shading', 'keepNext', 'alignment'] as $key) {
            if (!array_key_exists($key, $definition)) {
                continue;
            }

            $setter = 'set' . ucfirst($key);
            if (method_exists($paragraph, $setter)) {
                $paragraph->{$setter}($definition[$key]);
            }
        }

        // The font properties go in as an array, never as a Font object: an
        // `AbstractStyle` of the same class is adopted in place of the one
        // `addFontStyle()` built, and the paragraph went in as a constructor
        // argument to that discarded one. What comes out is a character style
        // with no `w:styleId` and no `w:pPr` — headings render as body text.
        $phpWord->addFontStyle($id, $font, $paragraph);
    }
}
