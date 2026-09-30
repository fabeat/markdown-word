<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Render\NumberingRegistry;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use PhpOffice\PhpWord\PhpWord;

/*
 * The style definitions and numbering a standalone document has to carry.
 *
 * A document that merely *references* a style is not enough: PHPWord writes an
 * almost empty `styles.xml`, so anything that does not already know Word's
 * built-in styles draws the text as body copy. These tests pin down that the
 * definitions are present, and that they carry the right shape.
 */

it('defines the heading styles rather than only referencing them', function () {
    $styles = stylesOf('# One

## Two');

    foreach (range(1, 6) as $level) {
        expect($styles)->toContain(sprintf('w:styleId="Heading%d"', $level));
    }
});

it('defines the heading styles as paragraph styles', function () {
    expect(stylesOf('# One'))->toMatch('/<w:style w:type="paragraph" w:styleId="Heading1">/');
});

it('gives the heading styles their formatting', function () {
    $styles = stylesOf('# One');
    preg_match('/<w:style [^>]*w:styleId="Heading1">.*?<\/w:style>/s', $styles, $match);

    expect($match)->not->toBeEmpty();
    expect($match[0])->toContain('<w:b w:val="1"/>', '<w:sz w:val="32"/>', '<w:keepNext w:val="1"/>');
});

it('defines the block quote style', function () {
    expect(stylesOf('> quoted'))->toContain('w:styleId="IntenseQuote"');
});

it('leaves a custom style name to the template', function () {
    $config = Configuration::create()->withStyles([Styles::HEADING_1 => 'CorpTitle']);
    $file = Scratch::path('custom-style');

    saveDocument('# One', $file, $config);

    // Defining it here would override the template's own design.
    expect(TemplateFactory::xmlOf($file, 'word/styles.xml'))->not->toContain('w:styleId="CorpTitle"');
    expect(TemplateFactory::xmlOf($file))->toContain('<w:pStyle w:val="CorpTitle"/>');
});

it('defines every bullet level', function () {
    $file = Scratch::path('bullets');
    saveMarkdown("- one\n  - two\n    - three", $file);

    // A nested list points at level 1 of the same numbering. If that level is
    // undefined the renderer falls back to a different list, and a sub-list of
    // bullets comes out numbered.
    expect(substr_count(TemplateFactory::xmlOf($file, 'word/numbering.xml'), 'w:numFmt w:val="bullet"'))->toBe(NUMBERING_LEVELS);
});

it('writes a level for every level Word supports', function () {
    // The count the tests above assert against, read back off the renderer that
    // produces it. Written out as a number in five places it was a trap: changing
    // the number failed five tests whose only complaint was that a count was not
    // what it had been, with nothing saying which loop had moved.
    //
    // The loops are the renderer's own, so they are asked rather than
    // reimplemented here; a rename in `NumberingRegistry` fails this test by name.
    $registry = new NumberingRegistry(new Configuration(), new PhpWord());

    $levels = static fn (string $method, array $arguments = []): int => count(
        (new ReflectionMethod($registry, $method))->invokeArgs($registry, $arguments),
    );

    expect($levels('bulletLevels'))->toBe(NUMBERING_LEVELS)
        ->and($levels('orderedLevels', [1, '.']))->toBe(NUMBERING_LEVELS);
});

it('gives nested bullets distinct characters', function () {
    $file = Scratch::path('nested-bullets');
    saveMarkdown('- one
  - two', $file);

    $numbering = TemplateFactory::xmlOf($file, 'word/numbering.xml');

    expect($numbering)->toContain('w:lvlText w:val="•"');
    expect($numbering)->toContain('w:lvlText w:val="o"');
});

it('defines every level of an ordered list', function () {
    $file = Scratch::path('ordered');
    saveMarkdown("1. one\n   1. two", $file);

    $numbering = TemplateFactory::xmlOf($file, 'word/numbering.xml');

    expect(substr_count($numbering, 'w:numFmt w:val="decimal"'))->toBe(NUMBERING_LEVELS);
});

it('does not redefine a style on a second render', function () {
    // PHPWord's style registry lives for the whole process; redefining a style a
    // document already used would make the two documents differ.
    $converter = new MarkdownToWord();

    expect(stylesOfDocument($converter->toPhpWord('# Two')))
        ->toBe(stylesOfDocument($converter->toPhpWord('# One')));
});

/**
 * The `styles.xml` of a document rendered from Markdown.
 */
function stylesOf(string $markdown, ?Configuration $config = null): string
{
    $file = Scratch::path('styles');
    saveDocument($markdown, $file, $config ?? new Configuration());

    return TemplateFactory::xmlOf($file, 'word/styles.xml');
}

function stylesOfDocument(PhpWord $phpWord): string
{
    $file = Scratch::path('styles');
    writePhpWordDocument($phpWord, $file);

    return TemplateFactory::xmlOf($file, 'word/styles.xml');
}
