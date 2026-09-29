<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Options;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\WordToMarkdown;
use MarkdownWord\Tests\Support\SpecExample;
use MarkdownWord\Text\TextExtractor;

/**
 * Converts every example from the specification suites to Word and back to
 * Markdown, and checks that the second Word document says the same thing as the
 * first.
 *
 * ```text
 * Markdown ──▶ Word ──▶ Markdown ──▶ Word
 *              └────── same text ──────┘
 * ```
 *
 * This is the test the forward direction alone cannot make. The conformance
 * suite proves the first Word document carries the text the specification asks
 * for; this proves nothing is lost on the way out *and* comes back recognisably
 * as Markdown, so a defect in the reader cannot hide behind a document nobody
 * reads back.
 *
 * The comparison is on the text of the two documents rather than on Markdown
 * source, because a Word document is a lower-fidelity form of its source: it
 * does not record which fence a code block used, whether a list was loose, or
 * where a line wrapped. Going through a second Word document rather than
 * comparing text to text means both sides are measured the same way, and it needs
 * no assumption about how raw HTML is rendered — the forward converter decides
 * that once and the round trip is judged on the result.
 */
it('carries every CommonMark example through Word and back', function (SpecExample $example) {
    assertExampleSurvives($example);
})->with('commonMarkExamples');

it('carries every GFM example through Word and back', function (SpecExample $example) {
    assertExampleSurvives($example);
})->with('gfmExamples');

it('actually loads the corpora for the round trip', function () {
    expect(specExamples(__DIR__ . '/../fixtures/spec/commonmark-spec.txt', 'commonmark'))
        ->toHaveCount(654);
    expect(specExamples(__DIR__ . '/../fixtures/spec/gfm-spec.txt', 'gfm'))
        ->toHaveCount(646);
});

function assertExampleSurvives(SpecExample $example): void
{
    withoutUpstreamDeprecations(static function () use ($example): void {
        $first = new MarkdownToWord(null, roundTripConfiguration());
        $docx = $first->toDocx($example->markdown);
        $before = normaliseRoundTripText($first, $example->markdown);

        $markdown = (new WordToMarkdown($docx))->convert();

        $second = new MarkdownToWord(null, roundTripConfiguration());
        $second->toPhpWord($markdown);
        $after = normaliseRoundTripText($second, $markdown);

    expect($after)->toBe(
        $before,
        sprintf(
            "The text changed on the way through Word for %s.\n"
            . "--- Source Markdown ---\n%s\n--- Markdown read back ---\n%s\n--- Text before ---\n%s\n--- Text after ---\n%s",
            $example->label(),
            $example->markdown,
            $markdown,
            $before,
            $after,
        ),
        );
    });
}

function normaliseRoundTripText(MarkdownToWord $converter, string $markdown): string
{
    return normaliseDocumentText(TextExtractor::fromPhpWord(
        $converter->toPhpWord($markdown),
        TextExtractor::LINE_BREAK,
        $converter->pendingHyperlinks(),
    ));
}

/**
 * Images are skipped because the comparison is on text, in which an image
 * contributes nothing — the same reason the conformance suite skips them.
 *
 * Both passes use the default parser rather than a CommonMark-only one. The
 * reader writes GitHub-Flavored Markdown, because a Word table can only be a GFM
 * table and struck-through text can only be GFM's `~~`; reading that back with a
 * stricter parser would be testing the parser rather than the round trip.
 */
function roundTripConfiguration(): Configuration
{
    return Configuration::create()->withOptions(['images' => Options::IMAGE_SKIP]);
}
