#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Does this library work on this version of PHP?
 *
 * The test suite needs Pest, and Pest 5 needs PHP 8.4. The library itself
 * supports PHP 8.2, so on the older versions there would otherwise be nothing at
 * all running: a syntax error, a missing extension or a broken autoloader would
 * not be noticed until somebody tried to install it.
 *
 * This covers that gap. It uses no test framework, so it runs anywhere the
 * library claims to run, and it checks both directions plus the command line —
 * `stress.php` only exercises the way into Word.
 *
 * ```sh
 * php smoke.php
 * ```
 */

require __DIR__ . '/vendor/autoload.php';

use MarkdownWord\Console\Application;
use MarkdownWord\Console\UpstreamDeprecations;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\WordToMarkdown;

$checks = 0;
$failures = 0;

// One dependency emits a deprecation for every list item it writes; see the class
// for why. Without this the run prints thousands of lines of somebody else's
// warning and buries anything real.
UpstreamDeprecations::install();

$work = __DIR__ . '/tmp/smoke';
@mkdir($work, 0o777, true);

/**
 * Check one thing, counting it either way.
 */
function check(string $what, callable $test): void
{
    global $checks, $failures;

    $checks++;

    try {
        $result = $test();

        if ($result === true) {
            return;
        }

        $failures++;
        echo 'FAIL  ' . $what . ': ' . (is_string($result) ? $result : 'returned false') . "\n";
    } catch (Throwable $e) {
        $failures++;
        echo 'FAIL  ' . $what . ': ' . $e::class . ' — ' . $e->getMessage() . "\n";
    }
}

/**
 * Whitespace layout is a rendering concern rather than a content one, so runs of
 * spaces collapse and blank lines go. What is left is the text and the structure
 * of the line it is on.
 */
function normalise(string $markdown): string
{
    $lines = array_map(
        static fn (string $line): string => trim((string) preg_replace('/[ \t]+/', ' ', $line)),
        explode("\n", $markdown),
    );

    return implode("\n", array_filter($lines, static fn (string $line): bool => $line !== ''));
}

/**
 * One part of a document inside the zip archive, or null when it is not there.
 */
function part(string $docx, string $name): ?string
{
    $zip = new ZipArchive();

    if ($zip->open($docx) !== true) {
        return null;
    }

    $contents = $zip->getFromName($name);
    $zip->close();

    return $contents === false ? null : $contents;
}

// ------------------------------------------------------------- requirements

echo 'PHP ' . PHP_VERSION . "\n\n";

check('the extensions the library needs are loaded', static function (): bool|string {
    $missing = array_filter(
        ['zip', 'dom', 'mbstring', 'gd'],
        static fn (string $extension): bool => !extension_loaded($extension),
    );

    return $missing === [] ? true : 'missing: ' . implode(', ', $missing);
});

// ------------------------------------------------------------ into a document

$sample = <<<'MD'
    # Heading

    A paragraph with **bold**, *italic*, `code` and a [link](https://example.test).

    - one
      - nested
    - two

    1. first
    2. second

    > a quote

    ```php
    $x = 1 < 2 && 3 > 2;
    $y = sqrt($x);
    ```

    | A | B |
    | --- | --- |
    | 1 | 2 |
    MD;

$docx = $work . '/sample.docx';

check('Markdown converts to a Word document', static function () use ($sample, $docx): bool|string {
    UpstreamDeprecations::quietly(
        static fn () => (new MarkdownToWord($sample))->save($docx),
    );

    return is_file($docx) && filesize($docx) > 0 ? true : 'nothing was written';
});

check('the document is a zip archive', static function () use ($docx): bool|string {
    return part($docx, 'word/document.xml') !== null ? true : 'the file is not a zip archive';
});

check('the document is well-formed XML', static function () use ($docx): bool|string {
    $document = part($docx, 'word/document.xml');

    if ($document === null) {
        return 'no document body';
    }

    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $valid = $dom->loadXML($document, LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return $valid ? true : 'word/document.xml does not parse';
});

check('the table spans the text column', static function () use ($docx): bool|string {
    $document = part($docx, 'word/document.xml') ?? '';

    return str_contains($document, 'w:tblW w:w="5000" w:type="pct"')
        ? true
        : 'the table is not the full width of the column';
});

check('the hyperlink placeholder did not survive into the document', static function () use ($docx): bool|string {
    return str_contains(part($docx, 'word/document.xml') ?? '', 'MDWL')
        ? 'a placeholder leaked into the output'
        : true;
});

// ------------------------------------------------------------ back out again

$markdown = '';

check('the document converts back to Markdown', static function () use ($docx, &$markdown): bool|string {
    $markdown = (new WordToMarkdown($docx))->convert();

    return str_contains($markdown, '# Heading') ? true : 'the heading did not survive';
});

foreach ([
    'a heading' => '# Heading',
    'bold text' => '**bold**',
    'inline code' => '`code`',
    'a link' => '[link](https://example.test)',
    'a bullet list' => '- one',
    'a nested list item' => '  - nested',
    'an ordered list' => '1. first',
    'a quote' => '> a quote',
    'a code block' => '```',
    'the code itself' => '$y = sqrt($x);',
    'a table' => '| 1 | 2 |',
] as $what => $needle) {
    check('the round trip keeps ' . $what, static function () use (&$markdown, $needle): bool|string {
        return str_contains($markdown, $needle)
            ? true
            : 'no "' . $needle . '" in the Markdown read back';
    });
}

check('a round trip keeps the text', static function () use ($markdown, $work): bool|string {
    $again = $work . '/again.docx';

    UpstreamDeprecations::quietly(
        static fn () => (new MarkdownToWord($markdown))->save($again),
    );

    $readBack = UpstreamDeprecations::quietly(
        static fn (): string => (new WordToMarkdown($again))->convert(),
    );

    $before = normalise($markdown);
    $after = normalise($readBack);

    return $before === $after
        ? true
        : sprintf("the text changed\n    before: %s\n    after:  %s", $before, $after);
});

check('a document in memory converts back too', static function () use ($sample): bool|string {
    $bytes = UpstreamDeprecations::quietly(
        static fn (): string => (new MarkdownToWord())->toDocx($sample),
    );

    $readBack = (new WordToMarkdown($bytes))->convert();

    return str_contains($readBack, '# Heading') ? true : 'the heading did not survive';
});

check('something that is not a document is reported, not swallowed', static function (): bool|string {
    try {
        (new WordToMarkdown('not a zip file'))->convert();
    } catch (RuntimeException) {
        return true;
    }

    return 'no exception was thrown';
});

// ------------------------------------------------------------- command line

check('the command line converts in both directions', static function () use ($sample, $work): bool|string {
    $source = $work . '/cli.md';
    file_put_contents($source, $sample);

    $document = $work . '/cli.docx';
    $markdownOut = $work . '/cli-back.md';

    $out = fopen('php://memory', 'r+b');
    $err = fopen('php://memory', 'r+b');
    $in = fopen('php://memory', 'r+b');

    $first = (new Application($out, $err, $in))->run(['to-docx', $source, '-o', $document]);

    rewind($err);
    $said = trim((string) stream_get_contents($err));

    if ($first !== 0 || !is_file($document)) {
        fclose($out);
        fclose($err);
        fclose($in);

        return 'to-docx exited ' . $first . ($said === '' ? '' : ': ' . $said);
    }

    $second = (new Application($out, $err, $in))->run(['to-markdown', $document, '-o', $markdownOut]);

    fclose($out);
    fclose($err);
    fclose($in);

    if ($second !== 0 || !str_contains((string) @file_get_contents($markdownOut), '# Heading')) {
        return 'to-markdown exited ' . $second;
    }

    return true;
});

check('the command line reports its version', static function (): bool|string {
    $out = fopen('php://memory', 'r+b');
    $err = fopen('php://memory', 'r+b');
    $in = fopen('php://memory', 'r+b');

    $exit = (new Application($out, $err, $in))->run(['--version']);

    rewind($out);
    $text = (string) stream_get_contents($out);

    fclose($out);
    fclose($err);
    fclose($in);

    return $exit === 0 && str_contains($text, Application::NAME)
        ? true
        : 'exited ' . $exit . ' saying "' . trim($text) . '"';
});

// --------------------------------------------------------------------- done

echo "\n{$checks} checks, {$failures} failure(s)\n";

UpstreamDeprecations::restore();

foreach (glob($work . '/*') ?: [] as $file) {
    @unlink($file);
}

@rmdir($work);

exit($failures === 0 ? 0 : 1);
