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
 * php smoke.php                  # the library, from a checkout
 * php smoke.php build/mdword.phar   # a phar as well, if one has been built
 * ```
 *
 * The phar is a separate program with its own dependencies inside it, so the
 * library passing says nothing about whether the archive that ships it runs.
 * Pointed at one, this runs it in a child process and converts a document through
 * it both ways — which is the only way to check that a phar works, since the
 * extensions and `phar.readonly` it needs belong to the PHP that runs it.
 */

require __DIR__ . '/vendor/autoload.php';

use MarkdownWord\Console\Application;
use MarkdownWord\Console\UpstreamDeprecations;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\WordToMarkdown;

$checks = 0;
$failures = 0;
$phar = $argv[1] ?? getenv('MDWORD_PHAR') ?: null;

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
    // Skipped rather than failed when the conversion above produced nothing: the
    // eleven checks below all look for the same thing in the same empty string, so
    // reporting them one by one would turn one defect into twelve.
    if ($markdown === '') {
        echo 'SKIP  the round trip keeps ' . $what . ": nothing was read back\n";
        $checks++;

        continue;
    }

    check('the round trip keeps ' . $what, static function () use ($markdown, $needle): bool|string {
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

// ------------------------------------------------------------------ the phar

if ($phar !== null && $phar !== '') {
    $havePhar = is_file($phar);

    check('there is a phar at that path', static function () use ($phar, $havePhar): bool|string {
        return $havePhar ? true : 'there is no file at "' . $phar . '"';
    });

    if (!$havePhar) {
        // Reported once: the two checks below would only say it again, in the past
        // tense, about a file that is not there.
        echo "SKIP  the phar was not run: there is nothing at that path\n";
    } else {
        check('the phar runs on this version of PHP', static function () use ($phar): bool|string {
            // A child process, because a phar is a program of its own: what is
            // being checked is that *that* program starts, with whatever it carries
            // inside it, rather than that this process can reach inside the
            // archive.
            $code = runPhar([$phar, '--version']);

            if ($code === null) {
                return true;
            }

            return $code === 0 ? true : 'it exited ' . $code;
        });

        check('the phar converts in both directions', static function () use ($phar, $work): bool|string {
            $source = $work . '/phar.md';
            $document = $work . '/phar.docx';
            $readBack = $work . '/phar-back.md';

            file_put_contents($source, "# Built\n\n| A | B |\n| --- | --- |\n| 1 | 2 |\n");

            $forward = runPhar([$phar, 'to-docx', $source, '-o', $document]);

            if ($forward === null) {
                return true;
            }

            if ($forward !== 0) {
                return 'to-docx exited ' . $forward;
            }

            $reverse = runPhar([$phar, 'to-markdown', $document, '-o', $readBack]);

            if ($reverse === null) {
                return true;
            }

            if ($reverse !== 0) {
                return 'to-markdown exited ' . $reverse;
            }

            return is_file($readBack) && str_contains((string) @file_get_contents($readBack), '# Built')
                ? true
                : 'nothing came back out of the phar';
        });
    }
}

// --------------------------------------------------------------------- done

echo "\n{$checks} checks, {$failures} failure(s)\n";

UpstreamDeprecations::restore();

removeTree($work);

exit($failures === 0 ? 0 : 1);

/**
 * Remove a directory and everything in it, and only that.
 *
 * `tmp` is shared with the test suite and with the other checks at the root of the
 * repository, so nothing outside this run's own directory is touched.
 */
function removeTree(string $directory): void
{
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . '/' . $entry;

        is_dir($path) ? removeTree($path) : @unlink($path);
    }

    @rmdir($directory);
}

/**
 * Run the phar in a child process and hand back its exit code.
 *
 * Null where no child process could be started — `proc_open` disabled, which some
 * build hosts do — so the caller can skip rather than report a phar that was never
 * asked a question. A phar needs the extensions of the PHP that runs it, so this
 * is the only place a check like this can honestly live.
 *
 * The command is an array, so it is executed directly rather than through a shell
 * and nothing here has to be quoted: a path with a space in it is one argument
 * rather than two, and a path that is not there fails the way a missing file
 * should.
 *
 * @param list<string> $arguments
 */
function runPhar(array $arguments): ?int
{
    $pipes = [];
    $process = function_exists('proc_open')
        ? @proc_open(array_merge([PHP_BINARY], $arguments), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes)
        : null;

    if (!is_resource($process)) {
        echo "SKIP  no child process could be started, so the phar was not run\n";

        return null;
    }

    echo (string) stream_get_contents($pipes[1]);
    echo (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($process);
}
