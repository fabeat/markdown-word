<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Options;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Tests\Support\HtmlText;
use MarkdownWord\Tests\Support\SpecExample;
use MarkdownWord\Text\TextExtractor;

/**
 * Renders every example from the official specification test suites and checks
 * that the resulting Word document carries exactly the text the specification's
 * expected HTML renders to.
 */
it('renders every CommonMark example with the text the specification asks for', function (SpecExample $example) {
    assertExampleMatches($example);
})->with('commonMarkExamples');

it('renders every GFM example with the text the specification asks for', function (SpecExample $example) {
    assertExampleMatches($example);
})->with('gfmExamples');

it('actually loads the corpora', function () {
    expect(specExamples(__DIR__ . '/../fixtures/spec/commonmark-spec.txt', 'commonmark'))
        ->toHaveCount(654);
    expect(specExamples(__DIR__ . '/../fixtures/spec/gfm-spec.txt', 'gfm'))
        ->toHaveCount(646);
});

/**
 * The configuration the corpus is run with.
 *
 * Images are skipped because the oracle is the *text* of the expected HTML, in
 * which an `<img>` contributes nothing, while a Word image placeholder emits
 * its alt text instead — a deliberate choice, not a fidelity gap.
 */
function conformanceConfiguration(): Configuration
{
    return Configuration::create()->withOptions(['images' => Options::IMAGE_SKIP]);
}

function assertExampleMatches(SpecExample $example): void
{
    $converter = new MarkdownToWord(null, conformanceConfiguration());
    $phpWord = $converter->toPhpWord($example->markdown);

    $actual = normaliseDocumentText(TextExtractor::fromPhpWord(
        $phpWord,
        TextExtractor::LINE_BREAK,
        $converter->pendingHyperlinks(),
    ));

    $expected = normaliseDocumentText(HtmlText::extract($example->html));

    expect($actual)->toBe(
        $expected,
        sprintf(
            "Text differs for %s.\n--- Markdown ---\n%s\n--- Expected HTML ---\n%s",
            $example->label(),
            $example->markdown,
            $example->html,
        ),
    );
}

