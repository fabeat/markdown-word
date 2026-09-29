<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Text\TextExtractor;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Style\Font;
use PhpOffice\PhpWord\Style\Paragraph;

/**
 * Test bootstrap and the helpers the renderer tests share.
 *
 * The helpers are functions rather than a base test case: Pest builds each test
 * as a closure, so a shared *capability* belongs here and a shared *assertion*
 * belongs in the test that makes it.
 */

/**
 * Render Markdown into a `PhpWord` document.
 */
function convertTo(string $markdown, ?Configuration $config = null, ?PhpWord $phpWord = null): PhpWord
{
    return (new MarkdownToWord($config ?? new Configuration()))->toPhpWord($markdown, $phpWord);
}

/**
 * The elements of the document's first section, which is what most assertions
 * are about.
 *
 * @return list<AbstractElement>
 */
function renderElements(string $markdown, ?Configuration $config = null): array
{
    return array_values(renderSection($markdown, $config)->getElements());
}

function renderSection(string $markdown, ?Configuration $config = null): AbstractContainer
{
    $sections = convertTo($markdown, $config)->getSections();

    expect($sections)->not->toBeEmpty('The document should contain at least one section.');

    return $sections[0];
}

/**
 * The visible text of a rendered document.
 */
function renderText(string $markdown, ?Configuration $config = null): string
{
    $converter = new MarkdownToWord($config ?? new Configuration());

    return TextExtractor::fromPhpWord(
        $converter->toPhpWord($markdown),
        TextExtractor::LINE_BREAK,
        $converter->pendingHyperlinks(),
    );
}

/**
 * The plain text of a single element, useful for asserting on one paragraph.
 */
function elementTextOf(AbstractElement $element): string
{
    return $element instanceof AbstractContainer
        ? TextExtractor::fromContainer($element)
        : '';
}

/**
 * The style name an element references, whether it was given as a string or as a
 * `Paragraph` object carrying one.
 */
function styleNameOf(AbstractElement $element): ?string
{
    $style = $element->getParagraphStyle();

    if (is_string($style)) {
        return $style;
    }

    return $style instanceof Paragraph ? $style->getStyleName() : null;
}

/**
 * The paragraph style of an element, normalised to the shape the renderer was
 * configured with: a style name, an inline array, or null.
 *
 * PHPWord materialises a `Paragraph` object when an inline style array is given,
 * so tests read better once it is turned back into an array.
 *
 * @return array<string, mixed>|string|null
 */
function paragraphStyleOf(AbstractElement $element): array|string|null
{
    $style = $element->getParagraphStyle();

    if (!$style instanceof Paragraph) {
        return $style;
    }

    // Only the values that were actually set are of interest, so a default
    // constructed Paragraph (no options given) reads as "no style".
    $set = [];

    foreach ([
        'alignment' => 'getAlignment',
        'indentation' => 'getIndentation',
        'shading' => 'getShading',
        'keepNext' => 'getKeepNext',
        'pageBreakBefore' => 'getPageBreakBefore',
        'borderBottomStyle' => 'getBorderBottomStyle',
        'borderBottomSize' => 'getBorderBottomSize',
        'borderBottomColor' => 'getBorderBottomColor',
    ] as $key => $getter) {
        if (!method_exists($style, $getter)) {
            continue;
        }

        $value = simplifyStyleValue($style->{$getter}());

        if ($value !== null && $value !== false && $value !== [] && $value !== '') {
            $set[$key] = $value;
        }
    }

    // PHPWord exposes paragraph spacing through dedicated accessors, and a space
    // before of zero is meaningful, so it is read directly.
    $spacing = array_filter([
        'before' => $style->getSpaceBefore(),
        'after' => $style->getSpaceAfter(),
    ], static fn (mixed $value): bool => $value !== null);

    if ($spacing !== []) {
        $set['space'] = $spacing;
    }

    return $set === [] ? null : $set;
}

/**
 * Flatten the value objects PHPWord returns into plain arrays, so assertions read
 * like the configuration that produced them.
 */
function simplifyStyleValue(mixed $value): mixed
{
    if ($value instanceof PhpOffice\PhpWord\Style\Indentation) {
        return array_filter([
            'left' => (int) $value->getLeft(),
            'right' => (int) $value->getRight(),
            'firstLine' => (int) $value->getFirstLine(),
            'hanging' => (int) $value->getHanging(),
        ], static fn (mixed $v): bool => $v !== null);
    }

    if ($value instanceof PhpOffice\PhpWord\Style\Spacing) {
        return array_filter([
            'before' => $value->getBefore(),
            'after' => $value->getAfter(),
            'line' => $value->getLine(),
        ], static fn (mixed $v): bool => $v !== null);
    }

    if ($value instanceof PhpOffice\PhpWord\Style\Shading) {
        return array_filter([
            'fill' => $value->getFill(),
            'color' => $value->getColor(),
        ], static fn (mixed $v): bool => $v !== null);
    }

    if ($value instanceof PhpOffice\PhpWord\Style\Border) {
        return array_filter([
            'style' => $value->getBorderStyle(),
            'size' => $value->getBorderSize(),
            'color' => $value->getBorderColor(),
        ], static fn (mixed $v): bool => $v !== null && $v !== 0);
    }

    return $value;
}

/**
 * The runs of an element, in order.
 *
 * PHPWord materialises the inline style array into a `Font` object, so it is
 * converted back to the shape the renderer was configured with.
 *
 * @return list<array{text: string, font: array<string, mixed>|string|null}>
 */
function renderRuns(AbstractElement $element): array
{
    if (!$element instanceof AbstractContainer) {
        return [];
    }

    $runs = [];

    foreach ($element->getElements() as $child) {
        if (!$child instanceof Text) {
            continue;
        }

        $font = simplifyFont($child->getFontStyle());

        // An unstyled run still carries PHPWord's defaults, so an empty result
        // reads as "no formatting".
        $runs[] = [
            'text' => $child->getText(),
            'font' => $font === [] ? null : $font,
        ];
    }

    return $runs;
}

/**
 * @return array<string, mixed>|string|null
 */
function simplifyFont(mixed $font): array|string|null
{
    if (!$font instanceof Font) {
        return $font;
    }

    $set = [];

    foreach ([
        'bold' => 'isBold',
        'italic' => 'isItalic',
        'strike' => 'isStrikethrough',
        'name' => 'getName',
        'size' => 'getSize',
        'color' => 'getColor',
    ] as $key => $getter) {
        $value = $font->{$getter}();

        if ($value !== null && $value !== false && $value !== '') {
            $set[$key] = $value;
        }
    }

    // "none" is PHPWord's default for underline, so it carries no meaning.
    $underline = $font->getUnderline();

    if ($underline !== 'none' && $underline !== '' && $underline !== null) {
        $set['underline'] = $underline;
    }

    return $set;
}

/**
 * The text of an element's runs joined together, which is what a reader sees.
 */
function renderRunText(AbstractElement $element): string
{
    return implode('', array_column(renderRuns($element), 'text'));
}


/**
 * The visible text of a document, in the shape a text comparison needs.
 *
 * Whitespace layout is a rendering concern rather than a content one, so runs of
 * spaces and tabs collapse to a single space and blank lines are dropped: where a
 * line wraps and whether a list is loose are not content. Line breaks are kept,
 * since that is where structure such as list items and table rows shows up.
 */
function normaliseDocumentText(string $text): string
{
    $lines = array_map(
        static fn (string $line): string => rtrim(trim((string) preg_replace('/[ \t]+/', ' ', $line)), " \t"),
        explode("\n", $text),
    );

    $lines = array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));

    return implode("\n", $lines);
}

/*
|--------------------------------------------------------------------------
| Datasets
|--------------------------------------------------------------------------
|
| The specification corpora, as named datasets. Registering them here rather than
| inline keeps the two corpus suites — conformance and round trip — reading as one
| line each, and means the 1300 examples are only parsed when one of them runs.
|
*/

require_once __DIR__ . '/Datasets/spec-examples.php';

dataset('commonMarkExamples', fn (): array => specExamples(__DIR__ . '/fixtures/spec/commonmark-spec.txt', 'commonmark'));

dataset('gfmExamples', fn (): array => specExamples(__DIR__ . '/fixtures/spec/gfm-spec.txt', 'gfm'));

/*
|--------------------------------------------------------------------------
| Known upstream issues
|--------------------------------------------------------------------------
|
| One dependency defect would otherwise mark every document-writing test as
| deprecated. It is silenced precisely, for that one message from that one file,
| rather than by switching deprecation reporting off. See tests/Support/Upstream.
|
*/

function withoutUpstreamDeprecations(callable $work): mixed
{
    return MarkdownWord\Tests\Support\Upstream::quietly($work);
}

/**
 * Render Markdown to the bytes of a `.docx` file.
 */
function toDocx(string $markdown, ?Configuration $config = null): string
{
    return withoutUpstreamDeprecations(
        static fn (): string => (new MarkdownToWord($config ?? new Configuration()))->toDocx($markdown),
    );
}

/*
|--------------------------------------------------------------------------
| Housekeeping
|--------------------------------------------------------------------------
|
| Everything a test writes into the project's `tmp` directory is removed once the
| test is over, so a run leaves nothing behind and a failure leaves the document
| in place long enough to open it.
|
| The hook goes through `pest()`. In Pest 5 a bare `afterEach()` written here
| binds to this file rather than to the suite, which is easy to miss: nothing
| fails, every test passes, and the files simply stop being cleaned up.
|
*/

pest()->afterEach(function (): void {
    MarkdownWord\Tests\Support\Scratch::cleanUp();
});

/**
 * Write a `.docx` file.
 *
 * The wrapper is only there for the upstream deprecation described above; from
 * the test's point of view this is `save()`.
 */
function saveDocument(MarkdownToWord $converter, string $markdown, string $path): void
{
    withoutUpstreamDeprecations(static function () use ($converter, $markdown, $path): void {
        $converter->save($markdown, $path);
    });
}

/**
 * Write a `.docx` file with the default configuration.
 */
function saveMarkdown(string $markdown, string $path): void
{
    saveDocument(new MarkdownToWord(), $markdown, $path);
}

/**
 * Write a `PhpWord` document out with PHPWord's own writer, for the tests that
 * are about what that writer produces.
 */
function writePhpWordDocument(PhpWord $phpWord, string $path): void
{
    withoutUpstreamDeprecations(static function () use ($phpWord, $path): void {
        PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007')->save($path);
    });
}

/**
 * Write a filled-in template out. The wrapper is only here for the upstream
 * deprecation described in tests/Support/Upstream.
 */
function saveTemplateDocument(MarkdownWord\Template\MarkdownTemplate $template, string $path): void
{
    withoutUpstreamDeprecations(static function () use ($template, $path): void {
        $template->save($path);
    });
}


