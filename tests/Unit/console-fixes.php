<?php

declare(strict_types=1);

use MarkdownWord\Console\Application;
use MarkdownWord\Console\UpstreamDeprecations;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\Upstream;

/*
 * The overwrite guard and the error filter `run()` took away on its way out.
 *
 * `runCli()` comes from tests/Unit/console.php: one way of driving a run is
 * enough, and a second copy of it would be a second thing to keep right.
 *
 * Writing a document reaches the one known upstream deprecation described in
 * tests/Support/Upstream, so the filter is installed for the duration of each
 * test. The last test installs the library's own filter as well, below the
 * runner's, and checks it is still there afterwards.
 */
beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

// The overwrite guard

it('refuses an output that is the input under another spelling of its case', function () {
    // On APFS and NTFS — the macOS and Windows defaults — `Notes.md` and
    // `notes.md` are one file, and `realpath()` hands both spellings back
    // unchanged, so comparing the two strings says they are two files.
    //
    // Only the *name* is respelled, never the directory. A checkout path is
    // often already all-lowercase, and uppercasing that as well yields a path
    // that does not exist at all — a different failure, saying nothing about the
    // guard, and one that made this test fail on a build machine while passing
    // on a laptop.
    $input = Scratch::path('case', '.md');
    $output = dirname($input) . '/' . strtolower(basename($input));
    $original = "# PRECIOUS ORIGINAL CONTENT\n";

    file_put_contents($input, $original);

    // Whether the two names are one file is a property of the filesystem, so it
    // is asked rather than assumed — and asked before the run, because after one
    // the second name exists on a case-insensitive filesystem either way.
    $oneFile = is_file($output);

    $run = runCli(['to-docx', $input, '-o', $output]);

    if (!$oneFile) {
        // Two files that only look alike: the run is right to go ahead, and the
        // input is untouched because the two names are genuinely two paths.
        expect($run['code'])->toBe(0);
        expect(file_get_contents($input))->toBe($original);
        expect(file_get_contents($output))->not->toBe($original);

        return;
    }

    expect($run['code'])->toBe(Application::FAILURE);
    expect($run['err'])->toContain('would overwrite the input file');
    expect(file_get_contents($input))->toBe($original);
});

it('refuses an output that is a hard link to the input', function () {
    $input = Scratch::path('hard', '.md');
    $alias = Scratch::path('hard-alias', '.md');
    $original = "# PRECIOUS ORIGINAL CONTENT\n";

    file_put_contents($input, $original);

    expect(@link($input, $alias))->toBeTrue();

    $run = runCli(['to-docx', $input, '-o', $alias]);

    expect($run['code'])->toBe(Application::FAILURE);
    expect($run['err'])->toContain('would overwrite the input file');
    expect(file_get_contents($input))->toBe($original);
});

it('refuses an output that reaches the input through a `..`', function () {
    $input = Scratch::path('dotdot', '.md');
    $original = "# PRECIOUS ORIGINAL CONTENT\n";

    file_put_contents($input, $original);

    // A directory that does not exist, so `realpath()` cannot normalise the way
    // out of it and the two spellings have to be compared as text.
    $viaDotDot = dirname($input) . '/absent-' . bin2hex(random_bytes(4)) . '/../' . basename($input);

    $run = runCli(['to-docx', $input, '-o', $viaDotDot]);

    expect($run['code'])->toBe(Application::FAILURE);
    expect($run['err'])->toContain('would overwrite the input file');
    expect(file_get_contents($input))->toBe($original);
});

it('lets a result go to a directory that does not exist yet', function () {
    // The guard must not fire when there is nothing to protect: a path that does
    // not exist cannot be the input.
    $input = Scratch::path('fresh', '.md');
    $output = dirname($input) . '/new-' . bin2hex(random_bytes(4)) . '/out.docx';

    file_put_contents($input, "# fine\n");

    $run = runCli(['to-docx', $input, '-o', $output]);

    expect($run['code'])->toBe(0);
    expect($run['err'])->not->toContain('would overwrite the input file');
    expect(is_file($output))->toBeTrue();
});

it('checks the output again after the conversion, in case it was swapped', function () {
    // The first check happens before the conversion, which is where the whole
    // run's cost is. Between it and the write sits the `--config` file, so that
    // is where a swap is made: a second name for the input appears part way
    // through, and the input must still survive.
    $previous = getcwd();

    chdir(Scratch::directory());

    try {
        $original = "# PRECIOUS ORIGINAL CONTENT\n";

        file_put_contents('swapped.md', $original);
        file_put_contents(
            'swap.php',
            '<?php if (!@link("swapped.md", "swapped.docx")) { throw new RuntimeException("could not swap"); } return [];',
        );

        $run = runCli(['to-docx', 'swapped.md', '--config', 'swap.php']);

        expect($run['code'])->toBe(Application::FAILURE);
        expect($run['err'])->toContain('would overwrite the input file');
        expect(file_get_contents('swapped.md'))->toBe($original);
    } finally {
        chdir($previous === false ? __DIR__ : $previous);
    }
});

it('writes the same document to a pipe as to a file', function () {
    // `-o -` is the documented pipe-to-clipboard workflow, so it is the path every
    // `| pbcopy` takes. It used to convert the document twice — once to a path of
    // `-` that nothing is written to, and once again for the bytes that are —
    // which cost a full parse, render and zip for a result that was thrown away.
    // Measured at 168 ms against 84 ms for a 400-section document.
    //
    // What is asserted is that the two agree, compared on the document body rather
    // than on the archive: a zip records its entries' timestamps, so two correct
    // conversions of the same input are not byte-identical and never were.
    //
    // The number of conversions is *not* asserted, because nothing observable from
    // outside the command reports it: `Application` and `ToDocx` are both `final`,
    // and the one signal that could be counted — the upstream null-offset
    // deprecation PHPWord raises while writing — belongs to PHP and to PHPWord, not
    // to this library. A test built on that passed on a laptop and failed on a
    // build machine for a conversion that was correct. The saving is in the code,
    // where the second call was.
    $input = Scratch::path('piped', '.md');
    $toFile = Scratch::path('once', '.docx');

    file_put_contents($input, "# Heading\n\n- one\n- two\n\nA paragraph.\n");

    $piped = runCli(['to-docx', $input, '-o', '-']);

    expect($piped['code'])->toBe(0);
    expect(substr($piped['out'], 0, 2))->toBe('PK');

    expect(runCli(['to-docx', $input, '-o', $toFile])['code'])->toBe(0);

    // The body, which is the whole of the conversion; the container around it
    // carries timestamps that differ between any two runs.
    expect(documentBody($piped['out']))->toBe(documentBody((string) file_get_contents($toFile)));
});

/** The `word/document.xml` out of a `.docx`, as bytes. */
function documentBody(string $docx): string
{
    $path = Scratch::path('body', '.docx');
    file_put_contents($path, $docx);

    $zip = new ZipArchive();

    expect($zip->open($path))->toBeTrue();

    try {
        return (string) $zip->getFromName('word/document.xml');
    } finally {
        $zip->close();
        @unlink($path);
    }
}

// Run()

it('leaves a deprecation filter the host installed in place', function () {
    // A host that embedded the application and installed the filter itself keeps
    // it: `run()` used to call `restore()` in a `finally` whatever the state, so
    // the first run of a long-lived process tore down what its host had put there
    // — and every conversion after it flooded stderr with the diagnostic the
    // filter exists to swallow.
    UpstreamDeprecations::install();

    expect(filterIsInstalled())->toBeTrue();

    try {
        (new Application())->run(['--version']);

        expect(filterIsInstalled())->toBeTrue();
    } finally {
        UpstreamDeprecations::restore();
    }

    expect(filterIsInstalled())->toBeFalse();
});

/**
 * Whether the library's deprecation filter believes it is in place.
 *
 * Its own flag, read directly, because `install()` and `restore()` both return
 * void and the point of the test is the flag's state either side of a run.
 */
function filterIsInstalled(): bool
{
    $flag = new ReflectionProperty(UpstreamDeprecations::class, 'installed');

    return $flag->getValue() === true;
}
