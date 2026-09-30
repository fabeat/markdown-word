<?php

declare(strict_types=1);

use MarkdownWord\Console\Application;
use MarkdownWord\Console\Command\ToDocx;
use MarkdownWord\Console\UpstreamDeprecations;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;
use PhpOffice\PhpWord\Style;

/*
 * The defects fixed on this branch: the overwrite guard, the conversion to
 * standard output, and the error filter `run()` took away on its way out.
 *
 * `runCli()` comes from tests/Unit/console.php: one way of driving a run is
 * enough, and a second copy of it would be a second thing to keep right. The
 * one exception is the render count, which needs the dispatcher out of the way;
 * that helper says why.
 *
 * Writing a document reaches the one known upstream deprecation described in
 * tests/Support/Upstream, so the filter spans the whole file. The last test
 * below installs the library's own filter as well, below the runner's, and
 * checks it is still there afterwards.
 */
beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/**
 * Run one command the way the dispatcher does, with streams a test can read.
 *
 * Not `runCli()`, and the difference matters: `Application::run()` installs the
 * library's deprecation filter, and it lands *above* any handler a test puts in
 * place, so nothing the filter swallows ever reaches a test. A command driven
 * directly has no filter over it.
 *
 * @param class-string<\MarkdownWord\Console\Command\Command> $command
 * @param list<string> $argv The arguments after the command name.
 * @return array{code: int, out: string, err: string}
 */
function runCommand(string $command, array $argv): array
{
    $out = fopen('php://memory', 'r+b');
    $err = fopen('php://memory', 'r+b');
    $in = fopen('php://memory', 'r+b');

    $code = (new $command(new Application($out, $err, $in)))->execute($argv);

    rewind($out);
    rewind($err);

    return [
        'code' => $code,
        'out' => (string) stream_get_contents($out),
        'err' => (string) stream_get_contents($err),
    ];
}

/**
 * The one diagnostic the library's filter exists to swallow, and this file's own
 * copy of the rule for it.
 */
function isTheUpstreamNullOffset(int $severity, string $message, string $file): bool
{
    return $severity === E_DEPRECATED
        && str_contains($message, 'Using null as an array offset')
        && str_ends_with(str_replace('\\', '/', $file), '/phpoffice/phpword/src/PhpWord/Style.php');
}

/**
 * How many times a callback rendered a document.
 *
 * PHPWord reports one null-offset deprecation for every paragraph it writes
 * without a numbering of its own, and every list item is one, so a conversion
 * that reached the writer once produces a fixed number of them. The count is
 * therefore in proportion to the number of renders, and a run to a file gives
 * the calibration: one render, one number. What the number happens to be today
 * does not matter, because the two are compared with each other.
 *
 * The handler stands on top of the one in tests/Support/Upstream and passes on
 * anything it does not recognise, so an unrelated diagnostic is reported as
 * usual rather than swallowed.
 */
function countRenders(callable $run): int
{
    $renders = 0;
    $previous = null;

    $handler = static function (
        int $severity,
        string $message,
        string $file = '',
        int $line = 0,
    ) use (&$renders, &$previous): bool {
        if (isTheUpstreamNullOffset($severity, $message, $file)) {
            $renders++;

            return true;
        }

        return $previous === null ? false : (bool) $previous($severity, $message, $file, $line);
    };

    $previous = set_error_handler($handler);

    try {
        $run();
    } finally {
        restore_error_handler();
    }

    return $renders;
}

// ------------------------------------------------------------ the overwrite guard

it('refuses an output that is the input under another spelling of its case', function () {
    // On APFS and NTFS — the macOS and Windows defaults — `Notes.md` and
    // `notes.md` are one file, and `realpath()` hands both spellings back
    // unchanged, so comparing the two strings says they are two files.
    $input = strtoupper(Scratch::path('case', '.md'));
    $output = strtolower($input);
    $original = "# PRECIOUS ORIGINAL CONTENT\n";

    file_put_contents($input, $original);

    // Whether the two names are one file is a property of the filesystem, so it
    // is asked rather than assumed — and asked before the run, because after one
    // the name exists on a case-insensitive filesystem either way.
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
    // Two names for one inode. The Markdown goes out through `file_put_contents()`,
    // which truncates whatever it finds there — the input and its alias with it.
    $input = Scratch::path('linked', '.docx');

    saveMarkdown("# Keep me\n", $input);

    $alias = Scratch::path('alias', '.docx');
    $original = (string) file_get_contents($input);

    expect(@link($input, $alias))->toBeTrue('this filesystem could not make a hard link');

    $run = runCli(['to-markdown', $input, '-o', $alias]);

    expect($run['code'])->toBe(Application::FAILURE);
    expect($run['err'])->toContain('would overwrite the input file');
    expect(file_get_contents($input))->toBe($original);
});

it('sees through a `..` on the way to the output', function () {
    // `sub/../notes.md` names the input, and `sub` is not there for the writer
    // to fall over on either: the guard normalises the path itself, or the run
    // ends in a complaint about a directory nobody asked for.
    $input = Scratch::path('detour', '.md');
    $original = "# Keep me\n";

    file_put_contents($input, $original);

    $missing = dirname($input) . '/absent-' . bin2hex(random_bytes(4));
    $detour = $missing . '/../' . basename($input);

    expect(is_dir($missing))->toBeFalse();

    $run = runCli(['to-docx', $input, '-o', $detour]);

    expect($run['code'])->toBe(Application::FAILURE);
    expect($run['err'])->toContain('would overwrite the input file');
    expect($run['err'])->not->toContain('Unable to create the directory');
    expect(file_get_contents($input))->toBe($original);
});

it('writes to an output whose directory is not there yet', function () {
    // The ordinary case, and the one a guard that refuses too eagerly would
    // break: there is nothing at the output path to protect, so nothing is
    // refused.
    $input = Scratch::path('fresh', '.md');

    file_put_contents($input, "# Fresh\n");

    $output = Scratch::path('made', '.docx');

    $run = runCli(['to-docx', $input, '-o', $output]);

    expect($run['code'])->toBe(0);
    expect(is_file($output))->toBeTrue();
    expect(TemplateFactory::textOf($output))->toContain('Fresh');
});

it('checks the output again after the conversion, not only before it', function () {
    // The check happens before a conversion that may take a while, so anything
    // with write access to the output directory can put the input's own inode
    // there in between. The only code that runs in that window is the
    // configuration file, so that is where the swap is made.
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

// ------------------------------------------------------------------- to a pipe

it('converts the document once when the result goes to standard output', function () {
    // `-o -` is the documented pipe-to-clipboard workflow, so it is the path
    // every `| pbcopy` takes. It used to convert the document twice: once to a
    // path of `-` that nothing is written to, and once again for the bytes that
    // are.
    $input = Scratch::path('piped', '.md');

    file_put_contents($input, "# Heading\n\n- one\n- two\n\nA paragraph.\n");

    $toFile = Scratch::path('once', '.docx');

    $once = countRenders(fn () => runCommand(ToDocx::class, [$input, '-o', $toFile]));
    $piped = countRenders(fn () => runCommand(ToDocx::class, [$input, '-o', '-']));
    $bytes = runCommand(ToDocx::class, [$input, '-o', '-']);

    expect($once)->toBeGreaterThan(0, 'the document did not reach the writer at all, so nothing was counted');
    expect($piped)->toBe($once);
    expect(substr($bytes['out'], 0, 2))->toBe('PK');
});

// ---------------------------------------------------------------------- run()

it('leaves a deprecation filter the host installed in place', function () {
    // A host that embedded the application and installed the filter itself keeps
    // it: `run()` is not entitled to take it off again on the way out, and the
    // conversions that follow in the same process are the ones that suffer.
    $leaked = 0;
    $previous = null;

    // Below the filter, so that the run's teardown is what un-covers it. It
    // counts only the diagnostic the filter claims, and passes everything else
    // on, so the assertion is about that filter and nothing else.
    $previous = set_error_handler(
        static function (int $severity, string $message, string $file = '', int $line = 0) use (&$leaked, &$previous): bool {
            if (isTheUpstreamNullOffset($severity, $message, $file)) {
                $leaked++;

                return true;
            }

            return $previous === null ? false : (bool) $previous($severity, $message, $file, $line);
        },
    );

    UpstreamDeprecations::install();

    try {
        $run = runCli(['--version']);

        expect($run['code'])->toBe(0);

        // The one diagnostic the filter exists to swallow, raised by the same
        // line of PHPWord that every list item reaches.
        Style::getStyle(null);

        expect($leaked)->toBe(0, 'run() removed a filter it did not install');
    } finally {
        UpstreamDeprecations::restore();
        restore_error_handler();
    }
});
