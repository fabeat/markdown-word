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
 * A document that merely references `Heading1` is not enough: PHPWord's
 * `styles.xml` is almost empty, so anything that does not know Word's built-in
 * styles draws the paragraph as body text. Defining them keeps a standalone
 * document self-contained while still using the styleIds Word recognises, which
 * is what preserves the outline levels behind the navigation pane and any table
 * of contents the user inserts later.
 *
 * This deliberately does *not* run when rendering into a template, where the
 * template is the authority on what a style looks like.
 */
final class StyleRegistrar
{
    /**
     * Word's built-in heading styles, closely following what Word applies to a
     * document with the default theme.
     */
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

    /**
     * Define every built-in style the configuration points at, so the document
     * stands on its own when opened.
     */
    public function register(PhpWord $phpWord): void
    {
        for ($level = 1; $level <= 6; $level++) {
            $id = 'Heading' . $level;

            // A custom style name belongs to the user's own template; only the
            // built-in ids are filled in here.
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

    /**
     * @param array<string, mixed> $definition
     */
    private function define(PhpWord $phpWord, string $id, array $definition): void
    {
        // PHPWord keeps its style registry for the lifetime of the process, so a
        // second render would otherwise redefine a style a document already used.
        if (Style::getStyle($id) !== null) {
            return;
        }

        // The keys are the ones PHPWord's Font style understands; `italics` is
        // spelled `setItalic` there, so the array form is used rather than
        // guessing a setter name.
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

        // A paragraph style in OOXML carries both `w:pPr` and `w:rPr`, and PHPWord
        // writes that combination from a font style with a paragraph attached.
        //
        // The font properties have to be passed as an array rather than as a Font
        // object: given an object of the same class, PHPWord's style registry
        // adopts it in place of its own and the paragraph binding is lost, which
        // leaves a character style with no `w:styleId` and no `w:pPr` — headings
        // then render as ordinary body text.
        $phpWord->addFontStyle($id, $font, $paragraph);
    }
}
