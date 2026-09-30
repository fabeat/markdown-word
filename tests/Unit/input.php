<?php

declare(strict_types=1);

use MarkdownWord\Input;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\Upstream;
use MarkdownWord\WordToMarkdown;

/*
 * Telling a file from the content itself.
 *
 * This is central rather than incidental: it is the first thing both directions
 * do, and the command line asks the same question of a stream to decide which
 * way the data should go. A wrong answer here is nasty and quiet — a document
 * read as Markdown produces a document full of XML text, and text read as a
 * document produces an error about bytes to someone who passed a filename.
 *
 * The two formats are deliberately not treated alike. Any text is Markdown, so a
 * string that is not a file is the content and is accepted without complaint. A
 * Word document is a zip archive, so a string that is neither a file nor an
 * archive is a mistake worth naming.
 *
 * Building a document to test against reaches the one known upstream deprecation,
 * and the unreadable-file case below needs a diagnostic the call site silenced
 * with `@` to stay silenced. Both are what tests/Support/Upstream is for.
 */
beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/**
 * A real `.docx`, as bytes.
 */
function documentBytes(string $markdown = "# Real\n\nBody.\n"): string
{
    return (new MarkdownToWord($markdown))->convert();
}

// Markdown

it('reads a file when the string names one', function () {
    $path = inputFile('notes.md', "# On disk\n");

    expect(Input::markdown($path))->toBe("# On disk\n");
});

it('takes anything else as the Markdown itself', function () {
    // Any text is Markdown, so there is nothing here that could be wrong.
    foreach ([
        '# Inline',
        "Multi\nline\ntext\n",
        '',
        '   ',
        'not-a-file-that-exists.md',
        'A line that merely looks like a path: /etc/passwd is missing from here',
    ] as $text) {
        expect(Input::markdown($text))->toBe($text);
    }
});

it('reads a file with any name, extension or not', function () {
    // The name is never consulted; only whether something is there.
    foreach (['notes.md', 'notes', 'notes.txt', 'no-extension', 'a.b.c'] as $name) {
        $path = inputFile($name, "# Named {$name}\n");

        expect(Input::markdown($path))->toBe("# Named {$name}\n");
    }
});

it('prefers the file when a single line names one that exists', function () {
    // A string is ambiguous by nature — a single word is both a plausible path
    // and a plausible piece of content — and the file wins. Worth pinning,
    // because the other choice would be defensible and the two cannot both hold.
    $path = inputFile('ambiguous.md', "The file's contents.\n");

    expect(Input::markdown($path))->toBe("The file's contents.\n");
    expect(Input::markdown($path . ' '))->toBe($path . ' ');
});

it('treats a directory as content rather than trying to read it', function () {
    // Not content anyone would write, but harmless: it becomes a document with
    // that text in it rather than an error.
    $directory = Scratch::directory();

    expect(Input::markdown($directory))->toBe($directory);
});

it('reports a file it cannot read rather than pretending it is content', function () {
    skipWithoutPermissions('A file with no read bit is still readable as root');

    $path = inputFile('unreadable.md', "# Secret\n");
    chmod($path, 0o000);

    expect(fn () => Input::markdown($path))->toThrow(RuntimeException::class, 'Unable to read');
});

// Document

it('accepts the bytes of a document as they are', function () {
    $bytes = documentBytes();

    expect(str_starts_with($bytes, Input::DOCUMENT_MAGIC))->toBeTrue();
    expect(Input::document($bytes))->toBe($bytes);
});

it('reads a document from a file when the string names one', function () {
    $bytes = documentBytes();
    $path = inputFile('report.docx', $bytes);

    expect(Input::document($path))->toBe($bytes);
});

it('names a file that exists but is not a document', function () {
    // The person passed a file, so the message is about a file. Telling them
    // about bytes would leave them looking for something they never mentioned.
    $path = inputFile('notes.md', "# Markdown\n");

    expect(fn () => Input::document($path))
        ->toThrow(RuntimeException::class, sprintf('"%s" is not a Word document', $path));
});

it('says the same about a file with a misleading name', function () {
    // The name promises a document and the bytes refuse: the bytes decide.
    $path = inputFile('pretending.docx', 'this is plain text, not a zip');

    expect(fn () => Input::document($path))
        ->toThrow(RuntimeException::class, 'is not a Word document');
});

it('rejects a string that is neither a document nor the name of one', function () {
    foreach ([
        'plain text, no file',
        'a file that is not there.md',
        '',
        "\n",
    ] as $text) {
        expect(fn () => Input::document($text))
            ->toThrow(RuntimeException::class, 'neither a Word document nor the name of one');
    }
});

it('rejects a document that begins like one and is not', function () {
    // The magic bytes are a promise about the first four only; a file that
    // stops after them is caught later, by the archive reader, which is the
    // right layer to say so.
    $path = inputFile('truncated.docx', Input::DOCUMENT_MAGIC);

    expect(Input::document($path))->toBe(Input::DOCUMENT_MAGIC);
    expect(fn () => (new WordToMarkdown($path))->convert())
        ->toThrow(RuntimeException::class);
});

// The sniff

it('recognises a document by its first four bytes', function () {
    expect(Input::looksLikeDocument(documentBytes()))->toBeTrue();
    expect(Input::looksLikeDocument(Input::DOCUMENT_MAGIC))->toBeTrue();
});

it('does not mistake text for a document', function () {
    // Near misses matter: the first three bytes are right, and a BOM would be
    // the sort of thing an editor adds without asking.
    foreach ([
        '# Heading',
        'PK',
        'PK\x03',
        "\xEF\xBB\xBF# Heading",
        "PK\x05\x06",
        'PKnotreally',
    ] as $text) {
        expect(Input::looksLikeDocument($text))->toBeFalse();
    }
});

it('agrees with what the converters do with the same input', function () {
    // The command line decides the direction with the same question, so a
    // disagreement would show up as a document converted the wrong way round
    // with no error at all.
    $bytes = documentBytes();
    $path = inputFile('agreement.docx', $bytes);

    expect(Input::looksLikeDocument(Input::document($path)))->toBeTrue();
    expect(Input::looksLikeDocument(Input::document($bytes)))->toBeTrue();
});
