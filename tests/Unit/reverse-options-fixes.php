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
 *
 * The groups below are about the second half of it: the null handling that the
 * forward direction got in `50ea1ab` and this one never received. Eleven of the
 * twelve properties here throw a `TypeError` when a config file spells out a key
 * with no value for it — every property that does not accept a null — which is
 * reachable from the public API and from a JSON config even though the only
 * in-tree caller passes a null-free array, and a file at 100% line coverage
 * makes that look like a closed question.
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

// -------------------------------------------------------------- what a null means

/*
| A `null` in an array is two different things, and the two entry points have to
| read it differently because they are in different positions.
|
| `fromArray()` builds an object that does not exist yet, so there is nothing a
| null could take away: it means "not configured" and the default stands.
| `withAll()` is handed an object that already holds values, and every one of
| them was asked for, so a null there means "not mentioned" and the value stays.
|
| Before this, neither read it: the null went straight to the constructor and
| raised a `TypeError` naming an argument number, from a config file the caller
| had written correctly, for a key that was only present because they had merged
| in an array that had no value for it yet.
|
| The one thing the two agree on is which properties can hold a null at all,
| since that is a property of the class rather than of the caller. The guard at
| the end pins that list against the constructor's declared types.
*/

/**
 * The options of this class whose declared type accepts a `null`.
 *
 * Read off the constructor rather than written out here, so an option added
 * later is in every test below from the moment it exists.
 *
 * @return list<string>
 */
function nullableReverseOptions(): array
{
    $nullable = [];

    foreach ((new ReflectionClass(Options::class))->getConstructor()->getParameters() as $parameter) {
        if ($parameter->getType()?->allowsNull() === true) {
            $nullable[] = $parameter->getName();
        }
    }

    return $nullable;
}

it('treats a key spelled out with no value as unconfigured', function () {
    // The shape: a JSON config that says `"maxStyleDepth": null`, or a PHP
    // config array merged over another that set the option. Stated over every
    // property rather than over a few, because the failure was in all eleven of
    // the non-nullable ones and naming a representative would leave the rest
    // unpinned. Before this, all eleven raised a `TypeError`.
    $defaults = Options::fromArray([])->toArray();
    $nullable = nullableReverseOptions();

    foreach (array_keys($defaults) as $property) {
        $configured = Options::fromArray([$property => null]);

        // The property that accepts a null holds it, because a null is what it
        // means; every other property takes the default. Which one that is comes
        // from the constructor, so the two cannot drift apart.
        $expected = in_array($property, $nullable, true) ? null : $defaults[$property];

        expect($configured->{$property})->toBe($expected, "fromArray(['{$property}' => null])");
    }
});

it('reads a null as the default rather than as a value for one property', function (string $property) {
    // An int, a bool and an array: the three kinds `cast()` touches, so a fix
    // that only handled the numbers or only handled the flags cannot be mistaken
    // for one that handles every property. The base has to be away from the
    // default as well, or "kept" and "reset" would look alike.
    $configured = Options::fromArray(BASE);
    $expected = Options::fromArray([])->{$property};

    expect($configured->{$property})->not->toBe($expected, "BASE leaves {$property} at its default")
        ->and(Options::fromArray([$property => null])->{$property})->toBe($expected);
})->with([
    'a limit' => 'maxStyleDepth',
    'a switch' => 'tableHeader',
    'a list of styles' => 'headingStyles',
]);

it('keeps a value a merge was not asked to change when a key arrives null', function () {
    // The silent half of the bug, once the loud half is fixed: `withAll()` merges
    // over the receiver, so a null it threw away used to leave the class default
    // on top of the value that was there. The archive limits are what that costs
    // most — a tightened `maxStyleDepth` of 5 going back to 32, which is the
    // bound that keeps a document's `basedOn` chain from looping.
    $base = Options::fromArray(BASE);

    $changed = $base->withAll([
        'maxStyleDepth' => null,
        'lineEnding' => "\r\n",
    ]);

    expect($changed->maxStyleDepth)->toBe(5)
        ->and($changed->maxPartBytes)->toBe(999999999)
        // The key that did carry a value is still applied, so this is a merge and
        // not a refusal.
        ->and($changed->lineEnding)->toBe("\r\n")
        ->and($changed->toArray())->toBe(array_replace($base->toArray(), ['lineEnding' => "\r\n"]));
});

it('reads a null for the media directory as the value it is', function () {
    // The one property here that accepts a null, and the reason the merge is
    // keyed on the list rather than on "is this null". `withMediaDirectory(null)`
    // is the setter for it and has to keep clearing, or there is no way back to
    // "no media directory" once one is set.
    $withOne = Options::fromArray(['mediaDirectory' => 'assets']);

    expect(Options::fromArray(['mediaDirectory' => null])->mediaDirectory)->toBeNull()
        ->and($withOne->withMediaDirectory(null)->mediaDirectory)->toBeNull()
        ->and($withOne->withAll(['mediaDirectory' => null])->mediaDirectory)->toBeNull()
        ->and($withOne->mediaDirectory)->toBe('assets');
});

it('reads an empty media directory as a value rather than as an absence', function () {
    // `''` is not the case `null` is: it is a value `cast()` knows how to read —
    // for a nullable option, "no media directory" — and it reads the same way in
    // both ways in, so a merge that kept the configured directory while
    // `fromArray()` cleared it would make the two disagree about the same array.
    $base = Options::fromArray(['mediaDirectory' => 'assets']);

    expect($base->withAll(['mediaDirectory' => ''])->mediaDirectory)->toBeNull()
        ->and(Options::fromArray(['mediaDirectory' => ''])->mediaDirectory)->toBeNull()
        ->and($base->withAll(['mediaDirectory' => null])->mediaDirectory)->toBeNull();
});

it('lists exactly the options whose type accepts a null', function () {
    // The list deciding that is hand-written, so a property added without a line
    // in it is broken from the moment a caller passes a null for it. This file
    // had no list at all, which is the whole of the bug above; the forward
    // direction has had one since `50ea1ab` and the same guard is kept there.
    $listed = (new ReflectionClass(Options::class))->getReflectionConstant('NULLABLE');

    expect($listed)->toBeInstanceOf(
        ReflectionClassConstant::class,
        'Reverse\Options has to say which of its properties accept a null.',
    );

    $declared = $listed->getValue();
    $nullable = nullableReverseOptions();
    sort($declared);
    sort($nullable);

    expect($declared)->toBe($nullable);
});

it('carries every property forward in toArray', function () {
    // `withAll()` merges over `toArray()`, so an option missing from it is
    // rebuilt from the default on the next merge — the same data loss as a setter
    // that forgets its receiver, one array further along.
    $constructor = array_map(
        static fn (ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionClass(Options::class))->getConstructor()->getParameters(),
    );

    expect(array_keys(Options::fromArray([])->toArray()))->toBe($constructor);
});
