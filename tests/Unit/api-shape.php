<?php

declare(strict_types=1);

use MarkdownWord\Console\UpstreamDeprecations;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\WordToMarkdown;
use MarkdownWord\Tests\Support\Scratch;

/*
 * The shape of the public interface.
 *
 * The two directions are the same operation, so the classes that do them should
 * look alike. They did not at first: `save()` took `(content, path)` in one and
 * `(path, path)` in the other — the same name and the same arity, meaning
 * opposite things, so a caller who learned one got the other wrong silently.
 *
 * These tests are what holds them together. Nothing else notices a method being
 * renamed on one side only.
 */

beforeEach(fn () => UpstreamDeprecations::install());
afterEach(fn () => UpstreamDeprecations::restore());

/**
 * The public methods a caller of either direction would reach for.
 *
 * @return list<string>
 */
function publicMethods(string $class): array
{
    return array_values(array_filter(
        get_class_methods($class),
        static fn (string $method): bool => $method !== '__construct',
    ));
}

it('gives both classes the same operations', function () {
    $markdown = publicMethods(MarkdownToWord::class);
    $document = publicMethods(WordToMarkdown::class);

    // The two the pair is built on, present on both, and nowhere else named
    // differently.
    foreach (['convert', 'save'] as $method) {
        expect($markdown)->toContain($method);
        expect($document)->toContain($method);
    }

    // Each has one string-in, string-out method, named after the direction it
    // produces rather than after the verb.
    expect($markdown)->toContain('toDocx');
    expect($document)->toContain('toMarkdown');
});

it('takes the subject to convert as the first argument to both', function () {
    foreach ([MarkdownToWord::class, WordToMarkdown::class] as $class) {
        $parameters = (new ReflectionMethod($class, '__construct'))->getParameters();

        expect($parameters)->not->toBeEmpty();
        expect($parameters[0]->getName())->toBe('source');
        expect((string) $parameters[0]->getType())->toBe('?string');
    }
});

it('gives save the same signature in both directions', function () {
    // One target, and nothing else: the subject went in the constructor, so
    // there is no second argument to get the argument order wrong.
    foreach ([MarkdownToWord::class, WordToMarkdown::class] as $class) {
        $parameters = (new ReflectionMethod($class, 'save'))->getParameters();

        expect($parameters)->toHaveCount(1);
        expect($parameters[0]->getName())->toBe('target');
        expect((string) $parameters[0]->getType())->toBe('string');
    }
});

it('gives convert the same signature in both directions', function () {
    foreach ([MarkdownToWord::class, WordToMarkdown::class] as $class) {
        $parameters = (new ReflectionMethod($class, 'convert'))->getParameters();

        expect($parameters)->toHaveCount(1);
        expect($parameters[0]->getName())->toBe('target');
        expect($parameters[0]->isOptional())->toBeTrue();
    }
});

it('tells the caller when there is nothing to convert', function () {
    // Both, and in the same words, so neither is a worse experience than the
    // other.
    foreach ([MarkdownToWord::class, WordToMarkdown::class] as $class) {
        $error = null;

        try {
            (new $class())->convert();
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }

        expect($error)->toContain('There is no');
    }
});

it('takes either a file or the content in both directions', function () {
    $markdown = Scratch::path('shape', '.md');
    file_put_contents($markdown, "# Subject\n");

    $document = Scratch::path('shape', '.docx');
    saveMarkdown("# Subject\n", $document);

    // A path, and the bytes themselves, are both accepted — the same rule in
    // both directions, and the same rule the command line works by.
    expect((new MarkdownToWord($markdown))->convert())->toStartWith('PK');
    expect((new MarkdownToWord('# Subject'))->convert())->toStartWith('PK');
    expect((new WordToMarkdown($document))->convert())->toContain('# Subject');
    expect((new WordToMarkdown((new MarkdownToWord($markdown))->convert()))->convert())
        ->toContain('# Subject');
});

it('reports a string that is neither a document nor the name of one', function () {
    // Any text is Markdown, so a string is taken as the content. A document has
    // to be a zip, so a string that is not one is a mistake worth naming.
    expect((new MarkdownToWord('just some text'))->convert())->toStartWith('PK');

    expect(fn () => (new WordToMarkdown('just some text'))->convert())
        ->toThrow(RuntimeException::class, 'neither a Word document nor the name of one');
});
