<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\Exception\InvalidInput;
use MarkdownWord\Exception\TemplateNotFound;

/*
 * What is left of the code that was removed for having no caller.
 *
 * Five public methods existed whose only caller in the whole repository was a
 * test written alongside them, so the coverage went up and the library did not
 * grow: `StyleResolver::font()` and `StyleResolver::headingStyle()`,
 * `Reverse\Inline::formatted()`, `Reverse\StyleTable::has()` and
 * `Reverse\Escaping::insideSpan()`. Two of them carried a docblock saying what
 * they were for, and both docblocks were wrong — which is the part that cannot be
 * left to rot, because a wrong docblock is read as a contract by whoever comes
 * next.
 *
 * So what is here is not "the method is gone" — a test that asserts an absence
 * only fails when somebody puts the method back, and says nothing about whether
 * the library still works. It is the other half: the claims that were false are
 * asserted absent from the sources, and the behaviour that outlived the deletion
 * is asserted still to work. When the next method with a dead caller and a
 * confident docblock turns up, both kinds of test apply to it as well.
 */

/**
 * The files in `src/` holding a phrase, so a claim that has been corrected
 * cannot come back unnoticed.
 *
 * @return list<string>
 */
function sourcesWithClaim(string $claim): array
{
    $found = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src')
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (str_contains($source, $claim)) {
            $found[] = $file->getFilename();
        }
    }

    sort($found);

    return $found;
}

it('does not claim again what the code does not do', function (string $claim) {
    // Each of these was a docblock explaining a method that had no caller, and
    // both were wrong: a code block's shading is a paragraph property, and the
    // round trip is lossy wherever Word did not record the distinction.
    expect(sourcesWithClaim($claim))->toBe([]);
})->with([
    'code block shading goes through a paragraph style, not a Font' => 'used for code block shading',
    'the round trip loses what Word does not record' => 'the round trip is exact',
]);

// ---------------------------------------------- what outlived the deletion

it('gives a code block its shading as a paragraph property', function () {
    // `StyleResolver::font()` claimed to build the Font that a code block's
    // shading was applied through. It is not: the background is a property of
    // the paragraph, and the type is a property of the run inside it.
    $elements = renderElements("```\nx = 1\n```\n");
    $style = paragraphStyleOf($elements[0]);
    $font = renderRuns($elements[0])[0]['font'];

    expect($style['shading']['fill'] ?? null)->toBe('F2F2F2')
        ->and($font['name'] ?? null)->toBe('Consolas');
});

it('splits a code block style between the paragraph and the runs in it', function () {
    // The same slot read as both halves: a Word paragraph has no character
    // formatting of its own, so a style that kept `name` on the paragraph would
    // have had no effect at all and the run would come out in the default face.
    $config = Configuration::create()->withStyles([
        Styles::CODE_BLOCK => [
            'name' => 'Fira Code',
            'size' => 11,
            'shading' => ['fill' => 'EEEEEE'],
        ],
    ]);

    $elements = renderElements("```\nx = 1\n```\n", $config);
    $style = paragraphStyleOf($elements[0]);
    $font = renderRuns($elements[0])[0]['font'];

    expect($style['shading']['fill'] ?? null)->toBe('EEEEEE')
        ->and($style)->not->toHaveKey('name')
        ->and($font['name'] ?? null)->toBe('Fira Code')
        ->and($font['size'] ?? null)->toBe(11);
});

it('uses the shading a code block style configures when the default is off', function () {
    // The same split from the other end: with the built-in background switched
    // off, the one the caller configured is the one that is there.
    $config = Configuration::create()
        ->withOptions(['codeBlockShading' => false])
        ->withStyles([Styles::CODE_BLOCK => ['shading' => ['fill' => 'EEEEEE']]]);

    $style = paragraphStyleOf(renderElements("```\nx\n```\n", $config)[0]);

    expect($style['shading']['fill'] ?? null)->toBe('EEEEEE');
});

it('clamps a heading level to the six there are styles for', function () {
    // The clamp lives in `Styles::heading()`, which is where a level becomes a
    // slot name; `StyleResolver::headingStyle()` was only a reader of it. There
    // is no seventh heading style, so a level past the sixth wants the deepest
    // rather than nothing.
    $styles = new Styles();

    expect($styles->heading(9))->toBe('Heading6')
        ->and($styles->heading(0))->toBe('Heading1');
});

it('reports a template that is not there as the caller\'s mistake', function () {
    // A missing template is a file the caller named and can name differently, so
    // it belongs with the other "that is not what the conversion needs" failures
    // and not beside the ones the machine is responsible for.
    expect(new TemplateNotFound('no such template'))
        ->toBeInstanceOf(InvalidInput::class);
});
