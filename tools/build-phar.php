#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Builds `build/mdword.phar` — a single file that converts Markdown to Word,
 * and back, on a machine with nothing but PHP.
 *
 * ```sh
 * composer build:phar
 * ```
 *
 * The archive carries the library and its two runtime dependencies and nothing
 * else. The development dependencies are left out, which is most of why it is a
 * few hundred kilobytes rather than a few megabytes: the test suite is several
 * times the size of the library.
 *
 * The dependencies are installed into a directory of their own rather than into
 * the project, because `composer install --no-dev` in the project would delete
 * the test tools from `vendor/` and the next `composer test` would fail.
 *
 * `phar.readonly` is `On` in most PHP installations, which is the right default
 * and cannot be changed at runtime. Rather than refuse, this script re-runs
 * itself with the setting turned off for the length of the build, so that one
 * command works everywhere.
 */

use MarkdownWord\Console\Application;

// ------------------------------------------------------------------ re-exec

if (ini_get('phar.readonly')) {
    $command = sprintf(
        '%s -d phar.readonly=0 %s %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(__FILE__),
        implode(' ', array_map('escapeshellarg', array_slice($argv, 1))),
    );

    passthru($command, $status);

    exit($status);
}

// The name and the version of the tool come from the class it drives, so the
// project's own autoloader has to be loadable. Saying so plainly beats a fatal
// error about a missing file, which is what this used to produce on a machine
// with no `vendor/` — such as a fresh continuous integration runner.
if (!is_file($autoloader = __DIR__ . '/../vendor/autoload.php')) {
    fwrite(STDERR, "build:phar needs the project's dependencies for its autoloader.\n"
        . "Run `composer install` first, or `composer install --no-dev` when you\n"
        . "only want the runtime ones.\n\n"
        . "Nothing has been written.\n");

    exit(1);
}

require $autoloader;

// ----------------------------------------------------------------- helpers

/**
 * Ask a yes/no question, defaulting to yes.
 */
function confirm(string $question): bool
{
    if (!stream_isatty(STDIN)) {
        return true;
    }

    fwrite(STDOUT, $question . ' [Y/n] ');

    $answer = strtolower(trim((string) fgets(STDIN)));

    return $answer === '' || $answer === 'y' || $answer === 'yes';
}

/**
 * Run a command, stopping the build if it fails.
 */
function run(string $command, string $what): void
{
    fwrite(STDOUT, sprintf('  %-44s', $what));

    $output = [];
    $status = 0;

    // Standard input is closed for the child, so that a Composer command asking
    // a question — or waiting on one that was never asked — cannot leave the
    // build waiting on a terminal that is not there.
    exec($command . ' 2>&1 < /dev/null', $output, $status);

    if ($status !== 0) {
        fwrite(STDOUT, "failed\n");
        fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);

        exit($status);
    }

    fwrite(STDOUT, "ok\n");
}

function humanSize(int $bytes): string
{
    return $bytes < 1024
        ? $bytes . ' B'
        : sprintf('%.1f KiB', $bytes / 1024);
}

function copyInto(string $from, string $to): void
{
    run(
        sprintf('cp -R %s %s', escapeshellarg($from), escapeshellarg($to)),
        'copying ' . basename($from) . '/',
    );
}

// ------------------------------------------------------------------- paths

$root = dirname(__DIR__);
$build = $root . '/build';
$deps = $build . '/deps';
$app = $build . '/app';
$target = $build . '/mdword.phar';

// A `composer.phar` in the project root is preferred, since that is the same
// version whoever installed the dependencies used; the `composer` on the PATH is
// the next best thing.
$composerPhar = $root . '/composer.phar';

$composer = is_file($composerPhar)
    ? escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($composerPhar)
    : trim((string) shell_exec('command -v composer 2>/dev/null'));

if ($composer === '') {
    fwrite(STDERR, "This build needs Composer: either a composer.phar in the project root,\n"
        . "or the `composer` command on your PATH.\n");

    exit(1);
}

fwrite(STDOUT, sprintf("Building %s %s\n\n", Application::NAME, Application::VERSION));

// ----------------------------------------------------------------- staging

// Built from scratch every time, so a dependency that has since been removed
// cannot linger in the archive.
run(sprintf('rm -rf %s %s', escapeshellarg($deps), escapeshellarg($app)), 'clearing the build directory');
mkdir($deps, 0o777, true);
mkdir($app, 0o777, true);

fwrite(STDOUT, "\n  Runtime dependencies\n");

// The manifest and the lock file are copied verbatim, so the lock's content hash
// still matches and the exact versions this project was tested against are what
// go into the archive.
//
// `--no-dev` then installs only the runtime ones *without re-resolving*, which
// is also what lets this run on a PHP older than the test framework's floor: the
// development requirements are read from the lock rather than resolved, and
// skipped. `composer update --no-dev` would not do that — it still resolves them,
// and fails outright on a version the framework does not support.
copyInto($root . '/composer.json', $deps);
copyInto($root . '/composer.lock', $deps);

run(
    sprintf(
        '%s install --no-dev --no-interaction --no-progress --optimize-autoloader --working-dir=%s',
        $composer,
        escapeshellarg($deps),
    ),
    'installing without development dependencies',
);

fwrite(STDOUT, "\n  Application\n");

copyInto($root . '/src', $app);
copyInto($root . '/bin', $app);
copyInto($deps . '/vendor', $app);

foreach (['LICENSE.md', 'LICENSE', 'README.md'] as $document) {
    if (is_file($root . '/' . $document)) {
        run(
            sprintf('cp %s %s', escapeshellarg($root . '/' . $document), escapeshellarg($app)),
            'copying ' . $document,
        );
    }
}

// ------------------------------------------------------------------- build

fwrite(STDOUT, "\n  Archive\n");

if (!confirm(sprintf('Write %s?', $target))) {
    fwrite(STDOUT, "Cancelled.\n");

    exit(0);
}

@unlink($target);

$phar = new Phar($target, 0, Application::NAME . '.phar');
$phar->startBuffering();

$phar->setStub(<<<'STUB'
    #!/usr/bin/env php
    <?php

    /**
     * mdword — convert Markdown to Word, and back.
     *
     * Generated by tools/build-phar.php. Edit bin/mdword or src/ instead.
     */

    Phar::mapPhar('mdword.phar');

    require 'phar://mdword.phar/app/bin/mdword';

    __HALT_COMPILER();
    STUB);

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($app, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST,
);

// Everything under the staging directory goes in at a path relative to it, so
// the archive has an /app root and the stub finds what it expects. The
// directories are created implicitly by the files inside them.
foreach ($files as $file) {
    if (!$file->isFile()) {
        continue;
    }

    $phar->addFile((string) $file->getPathname(), 'app/' . substr((string) $file->getPathname(), strlen($app) + 1));
}

// Compressed once everything is in, which is the only order `compressFiles()`
// accepts. Nearly everything in the archive is text, and images are already
// compressed, so this roughly halves it.
$phar->compressFiles(Phar::GZ);

$phar->setMetadata([
    'name' => Application::NAME,
    'version' => Application::VERSION,
    'built' => gmdate('c'),
]);

$phar->stopBuffering();

chmod($target, 0o755);

// -------------------------------------------------------------- verification

fwrite(STDOUT, "\n  Verification\n");

$reported = trim((string) shell_exec(sprintf(
    '%s %s --version 2>&1',
    escapeshellarg(PHP_BINARY),
    escapeshellarg($target),
)));

$expected = Application::NAME . ' ' . Application::VERSION;

fwrite(STDOUT, sprintf('  %-44s', 'the archive runs'));

if ($reported !== $expected) {
    fwrite(STDOUT, "failed\n");
    fwrite(STDERR, sprintf("Expected \"%s\" but the archive said \"%s\".\n", $expected, $reported));

    exit(1);
}

fwrite(STDOUT, "ok\n");

// A conversion in each direction, because an archive that reports its version
// and then cannot convert anything is still broken. A `.docx` is a zip archive
// and starts with `PK`, and a round trip has to come back with the heading in
// it — together those two catch a missing dependency as well as a broken one.
$phar = escapeshellarg($target);
$example = escapeshellarg($root . '/examples/markdown/01-kitchen-sink.md');

$produced = static function (string $command) use ($phar, $example): string {
    return (string) shell_exec(sprintf($phar . ' ' . $command, $example));
};

$checks = [
    'it converts Markdown to a document' => trim($produced(
        'to-docx %s -o - 2>/dev/null | head -c 2 | xxd -p'
    )) === '504b',
    'it converts a document back to Markdown' => str_contains(
        $produced('to-docx %s -o - 2>/dev/null | ' . escapeshellarg(PHP_BINARY) . ' ' . $phar . ' to-markdown - 2>/dev/null'),
        '# Kitchen sink',
    ),
];

foreach ($checks as $what => $passed) {
    fwrite(STDOUT, sprintf('  %-44s', $what));

    if (!$passed) {
        fwrite(STDOUT, "failed\n");

        exit(1);
    }

    fwrite(STDOUT, "ok\n");
}

fwrite(STDOUT, sprintf("\n%s — %s\n", $target, humanSize((int) filesize($target))));
fwrite(STDOUT, "Put it anywhere on your PATH and run `mdword`.\n\n");
