<?php

declare(strict_types=1);

use MarkdownWord\Reverse\Options;

/*
 * The reverse options' fluent setters.
 *
 * `with()` reached `fromArray()`, which starts from the defaults rather than
 * from the receiver, so every setter discarded the other eleven properties. On
 * this object that is worse than an inconvenience: the archive limits live here
 * too, so a single `withLineEnding()` call put `maxPartBytes` back to 256 MB,
 * `maxEntries` back to 4096 and `maxStyleDepth` back to 32 — a caller who had
 * tightened the zip-bomb bound lost it by asking for CRLF line endings.
 *
 * The same bug shipped in `Configuration\Options`; this file is its twin, so the
 * test is the twin of that one.
 */

const BASE = [
    'maxPartBytes' => 999999999,
    'maxEntries' => 77,
    'maxStyleDepth' => 5,
    'tableHeader' => false,
    'fenceCodeBlocks' => false,
    'headingSetext' => true,
    'quoteIndent' => 1440,
    'monospaceFonts' => ['Fira Code'],
    'headingStyles' => ['CorpTitle'],
    'quoteStyles' => ['CorpQuote'],
];

/** The properties the base configures away from their defaults, for a dataset. */
function baseKeys(): array
{
    return array_keys(BASE);
}

it('configures a base that differs from the defaults in every named property', function () {
    // A guard on the guard: if BASE ever came to equal the defaults, the dataset
    // above would compare defaults with defaults and pass on broken code. So
    // every property it names must actually be away from the default.
    $configured = Options::fromArray(BASE);
    $defaults = Options::fromArray([]);

    foreach (baseKeys() as $property) {
        expect($configured->{$property})->not->toBe($defaults->{$property}, "BASE leaves {$property} at its default");
    }
});

it('changes one option without disturbing the others', function (string $method, mixed $argument, string $property) {
    // The base differs from the defaults in every property it names, and the
    // expectation is built from the base rather than from the defaults. A test
    // that compared against the defaults would pass whether or not the merge
    // happened — which is how the bug shipped with a test in the first place.
    $base = Options::fromArray(BASE);

    $changed = $base->{$method}($argument);

    expect($changed->{$property})->toBe($argument);

    foreach (BASE as $other => $value) {
        if ($other !== $property) {
            expect($changed->{$other})->toBe($value, "{$method}() disturbed {$other}");
        }
    }
})->with([
    'line ending' => ['withLineEnding', "\r\n", 'lineEnding'],
    'quote indent' => ['withQuoteIndent', 720, 'quoteIndent'],
    'fence code blocks' => ['withFenceCodeBlocks', true, 'fenceCodeBlocks'],
    'table header' => ['withTableHeader', true, 'tableHeader'],
    'heading setext' => ['withHeadingSetext', false, 'headingSetext'],
    'heading styles' => ['withHeadingStyles', ['Other'], 'headingStyles'],
    'quote styles' => ['withQuoteStyles', ['Other'], 'quoteStyles'],
    'monospace fonts' => ['withMonospaceFonts', ['Other'], 'monospaceFonts'],
    'media directory' => ['withMediaDirectory', 'assets', 'mediaDirectory'],
    'part bytes' => ['withMaxPartBytes', 1234, 'maxPartBytes'],
    'entries' => ['withMaxEntries', 9, 'maxEntries'],
    'style depth' => ['withMaxStyleDepth', 3, 'maxStyleDepth'],
]);

it('keeps a tightened archive limit through an unrelated call', function () {
    // The reason this matters: these three are the bounds that keep a hostile
    // document from costing unbounded memory, and they are the settings a caller
    // is most likely to have changed from the defaults.
    $tightened = Options::fromArray(BASE)->withLineEnding("\r\n");

    expect($tightened->maxPartBytes)->toBe(999999999)
        ->and($tightened->maxEntries)->toBe(77)
        ->and($tightened->maxStyleDepth)->toBe(5);
});

it('keeps every option set earlier in a chain', function () {
    $chained = Options::fromArray([])
        ->withMaxPartBytes(1024)
        ->withMaxEntries(3)
        ->withLineEnding("\r\n")
        ->withTableHeader(true)
        ->withQuoteIndent(2880);

    expect($chained->maxPartBytes)->toBe(1024)
        ->and($chained->maxEntries)->toBe(3)
        ->and($chained->lineEnding)->toBe("\r\n")
        ->and($chained->tableHeader)->toBeTrue()
        ->and($chained->quoteIndent)->toBe(2880);
});

it('leaves the receiver alone', function () {
    $base = Options::fromArray(BASE);
    $changed = $base->withLineEnding("\n");

    expect($changed)->not->toBe($base)
        ->and($base->lineEnding)->toBe(Options::fromArray(BASE)->lineEnding)
        ->and($base->maxPartBytes)->toBe(999999999);
});
