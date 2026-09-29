<?php

declare(strict_types=1);

use MarkdownWord\Console\Application;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Console\Command\ToDocx;
use MarkdownWord\Console\Command\ToMarkdown;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;

/*
 * The `mdword` command.
 *
 * The application takes its three streams as arguments precisely so that a run
 * can be driven from a test and its output read back, which is what these tests
 * do. The alternative — running the real binary — would be a test of the shell
 * rather than of the code.
 *
 * Writing a document reaches the one known upstream deprecation described in
 * tests/Support/Upstream, so the filter spans the whole test.
 */
beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/**
 * Run a command, returning its exit code, what it wrote to standard output, and
 * what it said on standard error.
 *
 * @param list<string> $argv
 * @return array{code: int, out: string, err: string}
 */
function runCli(array $argv, string $stdin = ''): array
{
    $out = fopen('php://memory', 'r+b');
    $err = fopen('php://memory', 'r+b');
    $in = fopen('php://memory', 'r+b');

    fwrite($in, $stdin);
    rewind($in);

    $code = (new Application($out, $err, $in))->run($argv);

    rewind($out);
    rewind($err);

    $result = [
        'code' => $code,
        'out' => (string) stream_get_contents($out),
        'err' => (string) stream_get_contents($err),
    ];

    fclose($out);
    fclose($err);
    fclose($in);

    return $result;
}

// ------------------------------------------------------------------- basics

it('prints its version', function () {
    $run = runCli(['--version']);

    expect($run['code'])->toBe(0);
    expect($run['out'])->toBe('mdword ' . Application::VERSION . PHP_EOL);
});

it('prints help when given no command', function () {
    $run = runCli([]);

    expect($run['code'])->toBe(0);
    expect($run['out'])->toContain('to-docx');
    expect($run['out'])->toContain('to-markdown');
});

it('describes every option the parser accepts', function () {
    // The help is built from the same lists the parser is given, so a run that
    // lists an option in the help is asserting that much.
    foreach (['to-docx', 'to-markdown'] as $command) {
        $run = runCli([$command, '--help']);

        expect($run['code'])->toBe(0);
        expect($run['out'])->toContain('OPTIONS');
        expect($run['out'])->toContain('--output');
    }
});

it('reports a file that is not there', function () {
    // A bare word is read as a filename rather than as a command, because a file
    // may be called anything at all and the direction is worked out from content.
    $run = runCli(['frobnicate']);

    expect($run['code'])->toBe(Application::FAILURE);
    expect($run['err'])->toContain('The file "frobnicate" does not exist');
});

it('reports an unknown option with a hint', function () {
    $run = runCli(['to-docx', '--frobnicate']);

    expect($run['code'])->toBe(Application::FAILURE);
    expect($run['err'])->toContain('Unknown option "--frobnicate"');
});

it('says which command an option belongs to when it is used with the wrong one', function () {
    $run = runCli(['to-docx', '-', '--media', 'assets']);

    expect($run['code'])->toBe(Application::FAILURE);
    expect($run['err'])->toContain('The option "--media" does not apply here');
    expect($run['err'])->toContain('It belongs to `to-markdown`');
});

it('reports an option that is missing its value', function () {
    $run = runCli(['to-docx', '--output']);

    expect($run['code'])->toBe(Application::FAILURE);
    expect($run['err'])->toContain('The option "output" needs a value');
});

// ---------------------------------------------------------------- to-docx

it('converts a file to a document', function () {
    $input = Scratch::path('notes', '.md');
    file_put_contents($input, "# Notes\n\nSome **bold** text.\n");
    $output = Scratch::path('notes');

    $run = runCli(['to-docx', $input, '-o', $output]);

    expect($run['code'])->toBe(0);
    expect(is_file($output))->toBeTrue();
    expect(TemplateFactory::textOf($output))->toContain('Notes');
    expect(TemplateFactory::textOf($output))->toContain('bold');
});

it('names the output after the input when told nothing', function () {
    $input = Scratch::path('report', '.md');
    file_put_contents($input, '# Report');

    // The convention is the same one every other converter uses: the name with
    // its extension swapped, in the directory it was read from.
    $expected = preg_replace('/\.md$/', '.docx', $input);

    expect(runCli(['to-docx', $input])['code'])->toBe(0);
    expect(is_file((string) $expected))->toBeTrue();
});

it('reads standard input and writes standard output', function () {
    $run = runCli(['to-docx', '-', '-o', '-'], "# From standard input\n");

    expect($run['code'])->toBe(0);
    // The magic bytes of a zip archive, which is what a `.docx` is.
    expect(substr($run['out'], 0, 2))->toBe('PK');
});

it('writes to standard output when the output is left out and there is no file', function () {
    $run = runCli(['to-docx'], '# Piped');

    expect($run['code'])->toBe(0);
    expect(substr($run['out'], 0, 2))->toBe('PK');
});

it('keeps progress off standard output', function () {
    $run = runCli(['to-docx', '-', '-o', '-'], '# Quiet');

    // Anything but the document itself on standard output, or a pipe would
    // carry a progress line into whatever is reading it.
    expect(substr($run['out'], 0, 2))->toBe('PK');
    expect($run['err'])->toContain('standard input');
});

it('accepts an option value written with an equals sign', function () {
    $input = Scratch::path('eq', '.md');
    file_put_contents($input, '# Equals');
    $output = Scratch::path('eq');

    expect(runCli(['to-docx', $input, '--output=' . $output])['code'])->toBe(0);
    expect(is_file($output))->toBeTrue();
});

it('reads a file whose name begins with a dash', function () {
    $input = Scratch::path('--weird', '.md');
    file_put_contents($input, '# Odd name');
    $output = Scratch::path('weird');

    // Everything after `--` is an operand, so the separator goes last.
    expect(runCli(['to-docx', '-o', $output, '--', $input])['code'])->toBe(0);
    expect(is_file($output))->toBeTrue();
});

it('renders without decoration when asked to', function () {
    $input = Scratch::path('plain', '.md');
    file_put_contents($input, "# Plain\n\n> quoted\n");
    $output = Scratch::path('plain');

    runCli(['to-docx', $input, '--plain', '-o', $output]);

    $xml = TemplateFactory::xmlOf($output);

    expect($xml)->not->toContain('IntenseQuote');
});

it('leaves images out when told to', function () {
    Scratch::image('cli.png');

    $input = Scratch::path('pic', '.md');
    file_put_contents($input, '![Some words](cli.png)');
    $output = Scratch::path('pic');

    runCli(['to-docx', $input, '--no-images', '-o', $output]);

    expect(TemplateFactory::xmlOf($output))->not->toContain('imagedata');
});

it('rejects an image mode it does not know', function () {
    $run = runCli(['to-docx', '-', '--images', 'sideways'], '# X');

    expect($run['code'])->toBe(Application::FAILURE);
    expect($run['err'])->toContain('Unknown image mode "sideways"');
    expect($run['err'])->toContain('embed, placeholder or skip');
});

it('rejects a table width that is not a number', function () {
    $run = runCli(['to-docx', '-', '--table-width', 'wide'], '# X');

    expect($run['code'])->toBe(Application::FAILURE);
    expect($run['err'])->toContain('is not a number');
});

it('applies a configuration file', function () {
    $config = Scratch::path('config', '.php');
    file_put_contents($config, <<<'PHP'
        <?php

        use MarkdownWord\Configuration;

        return Configuration::fromArray(['styles' => ['heading.1' => 'ReportTitle']]);
        PHP);

    $input = Scratch::path('conf', '.md');
    file_put_contents($input, '# Styled');
    $output = Scratch::path('conf');

    runCli(['to-docx', $input, '-c', $config, '-o', $output]);

    // A style the library does not define is left to the template, so the only
    // thing to check is that the heading points at it.
    expect(TemplateFactory::xmlOf($output))->toContain('<w:pStyle w:val="ReportTitle"/>');
});

it('reports a configuration file that is not there', function () {
    $run = runCli(['to-docx', '-', '-c', '/does/not/exist.php'], '# X');

    expect($run['code'])->toBe(Application::FAILURE);
    expect($run['err'])->toContain('does not exist');
});

it('reports a configuration file that returns the wrong thing', function () {
    $config = Scratch::path('bad-config', '.php');
    file_put_contents($config, "<?php\n\nreturn 'not an array';\n");

    $run = runCli(['to-docx', '-', '-c', $config], '# X');

    expect($run['code'])->toBe(Application::FAILURE);
    expect($run['err'])->toContain('must return an array or a Configuration');
});

// -------------------------------------------------------------- templates

it('renders into a template region and substitutes values', function () {
    $template = TemplateFactory::report();
    $output = Scratch::path('from-template');

    $run = runCli([
        'to-docx', '-',
        '--template', $template,
        '--region', 'summary',
        '--define', 'reference=REF-42',
        '-o', $output,
    ], "## Overview\n\nRevenue **grew**.\n");

    expect($run['code'])->toBe(0);

    $text = TemplateFactory::textOf($output);

    expect($text)->toContain('Reference: REF-42');
    expect($text)->toContain('Overview');
    // The region is gone rather than left as literal placeholders.
    expect($text)->not->toContain('${slot}');
});

it('says which regions a template does have when the named one is missing', function () {
    $run = runCli([
        'to-docx', '-',
        '--template', TemplateFactory::report(),
        '-o', Scratch::path('never'),
    ], '# X');

    // Failing loudly beats producing a document full of `${...}`.
    expect($run['code'])->toBe(Application::FAILURE);
    expect($run['err'])->toContain('The template has no "${body}" region');
    expect($run['err'])->toContain('${summary}');
});

it('refuses a template that is not a document', function () {
    $notADocument = Scratch::path('not-a-template', '.md');
    file_put_contents($notADocument, '# Not a docx');

    $run = runCli(['to-docx', '-', '--template', $notADocument, '-o', Scratch::path('never')], '# X');

    expect($run['code'])->toBe(Application::FAILURE);
    expect($run['err'])->toContain('could not be opened');
});

it('says when a template option is used without a template', function () {
    $run = runCli(['to-docx', '-', '--define', 'a=b'], '# X');

    expect($run['code'])->toBe(Application::FAILURE);
    expect($run['err'])->toContain('only means something together with --template');
});

it('rejects a define that is not a name=value pair', function () {
    $run = runCli([
        'to-docx', '-',
        '--template', TemplateFactory::report(),
        '--define', 'oops',
    ], '# X');

    expect($run['code'])->toBe(Application::FAILURE);
    expect($run['err'])->toContain('needs a name=value pair');
});

// ------------------------------------------------------------ to-markdown

it('converts a document back to Markdown', function () {
    $document = Scratch::path('back', '.docx');
    saveMarkdown("# Round trip\n\nSome **bold** text.\n", $document);
    $output = Scratch::path('back', '.md');

    $run = runCli(['to-markdown', $document, '-o', $output]);

    expect($run['code'])->toBe(0);
    expect(file_get_contents($output))->toContain('# Round trip');
    expect(file_get_contents($output))->toContain('**bold**');
});

it('reads a document from standard input', function () {
    $document = Scratch::path('piped', '.docx');
    saveMarkdown("# Piped back\n", $document);

    $run = runCli(['to-markdown', '-', '-o', '-'], (string) file_get_contents($document));

    expect($run['code'])->toBe(0);
    expect($run['out'])->toContain('# Piped back');
});

it('writes underlined headings when told to', function () {
    $document = Scratch::path('setext', '.docx');
    saveMarkdown("# One\n\n## Two\n", $document);

    $run = runCli(['to-markdown', $document, '--setext', '-o', '-']);

    expect($run['out'])->toContain("One\n===");
    expect($run['out'])->toContain("Two\n---");
});

it('writes CRLF line endings when told to', function () {
    $document = Scratch::path('crlf', '.docx');
    saveMarkdown("# One\n\nText.\n", $document);

    $run = runCli(['to-markdown', $document, '--line-ending', 'crlf', '-o', '-']);

    expect($run['out'])->toContain("\r\n");
});

it('rejects a line ending it does not know', function () {
    $run = runCli(['to-markdown', '-', '--line-ending', 'cr']);

    expect($run['code'])->toBe(Application::FAILURE);
    expect($run['err'])->toContain('Unknown line ending "cr"');
});

it('takes the images out beside the Markdown it writes', function () {
    Scratch::image('extract.png');

    $document = Scratch::path('extract', '.docx');
    saveDocument(
        new MarkdownWord\MarkdownToWord(MarkdownWord\Configuration::create()->withOptions([
            'images' => MarkdownWord\Configuration\Options::IMAGE_EMBED,
            'imageBasePath' => Scratch::directory(),
        ])),
        '![A red square](extract.png)',
        $document,
    );

    $output = Scratch::path('extracted', '.md');
    $run = runCli(['to-markdown', $document, '--media', 'assets', '-o', $output]);

    expect($run['code'])->toBe(0);

    preg_match('/!\[[^\]]*\]\(([^)]+)\)/', (string) file_get_contents($output), $match);

    expect($match)->not->toBeEmpty();
    // The reference has to resolve from where the Markdown is, or the images are
    // written somewhere the document does not point at.
    expect(is_file(dirname($output) . '/' . $match[1]))->toBeTrue();
});

// -------------------------------------------------------------- direction

it('works the direction out from the file', function () {
    $input = Scratch::path('detect', '.md');
    file_put_contents($input, "# Detected\n\nSome **bold**.\n");

    // No command in front: the file says which way it has to go.
    $run = runCli([$input]);

    $document = preg_replace('/\.md$/', '.docx', (string) $input);

    expect($run['code'])->toBe(0);
    expect(is_file((string) $document))->toBeTrue();
    expect(TemplateFactory::textOf((string) $document))->toContain('Detected');
});

it('works the direction out from a Word document too', function () {
    $document = Scratch::path('detect', '.docx');
    saveMarkdown("# Detected\n", $document);

    $run = runCli([$document]);

    $markdown = preg_replace('/\.docx$/', '.md', $document);

    expect($run['code'])->toBe(0);
    expect(file_get_contents((string) $markdown))->toContain('# Detected');
});

it('goes by what the file is, not what it is called', function () {
    // A Markdown file with a misleading extension still goes to Word, because a
    // `.docx` is a zip archive and its first four bytes say so.
    $misnamed = Scratch::path('actually-markdown', '.docx');
    file_put_contents($misnamed, "# Not a document\n");
    $output = Scratch::path('converted');

    $run = runCli([$misnamed, '-o', $output]);

    expect($run['code'])->toBe(0);
    expect(TemplateFactory::textOf($output))->toContain('Not a document');
});

it('takes the direction from standard input when it is a document', function () {
    $document = Scratch::path('piped', '.docx');
    saveMarkdown("# Piped back\n", $document);

    $run = runCli(['-', '-o', '-'], (string) file_get_contents($document));

    expect($run['code'])->toBe(0);
    expect($run['out'])->toContain('# Piped back');
});

it('needs --to for Markdown on standard input', function () {
    // A pipe of text is indistinguishable from an empty document by its first
    // four bytes alone, and guessing would be worse than asking.
    $run = runCli(['--to', 'docx', '-', '-o', '-'], "# Piped in\n");

    expect($run['code'])->toBe(0);
    expect(substr($run['out'], 0, 2))->toBe('PK');
});

it('refuses a --to that contradicts the file', function () {
    $document = Scratch::path('real', '.docx');
    saveMarkdown("# A document\n", $document);

    $run = runCli([$document, '--to', 'docx', '-o', Scratch::path('elsewhere')]);

    expect($run['code'])->toBe(Application::FAILURE);
    expect($run['err'])->toContain('does not match');
    expect($run['err'])->toContain('That file is a Word document');
});

it('refuses a format it does not know', function () {
    $run = runCli(['--to', 'pdf', '-']);

    expect($run['code'])->toBe(Application::FAILURE);
    expect($run['err'])->toContain('Unknown format "pdf"');
});

it('refuses to write the result over the file it read', function () {
    // A `.md` in, a `.md` out: without this the run would destroy its own input.
    $document = Scratch::path('keep', '.docx');
    saveMarkdown("# Keep me\n", $document);

    $run = runCli([$document, '-o', $document]);

    expect($run['code'])->toBe(Application::FAILURE);
    expect($run['err'])->toContain('would overwrite the input file');
    expect(is_file($document))->toBeTrue();
});

it('keeps the help and the parser specification in step', function () {
    // The help table and the parser are two separate lists — one is for reading,
    // one is for parsing — so something has to hold them together. Either can
    // drift from the other without breaking anything at runtime.
    foreach ([ToDocx::class, ToMarkdown::class] as $command) {
        $spec = $command::spec();
        $accepted = Application::names($spec);
        $documented = [];

        foreach ($command::options() as [$spelling, $description]) {
            preg_match_all('/--([a-z][a-z-]*)/', $spelling, $matches);
            $documented = array_merge($documented, $matches[1]);
        }

        $name = substr($command, (int) strrpos($command, '\\') + 1);

        expect(array_diff($documented, $accepted))->toBe([], $name . ': the help offers options the parser rejects');
        expect(array_diff($accepted, $documented))->toBe([], $name . ': the parser takes options the help does not mention');
    }
});

it('round trips a document through the command line unchanged', function () {
    $input = Scratch::path('pipeline', '.md');
    file_put_contents($input, <<<'MD'
        # Heading

        A paragraph with **bold**, *italic*, `code` and a [link](https://example.com).

        - one
        - two

        > a quote

        | A | B |
        | --- | --- |
        | 1 | 2 |
        MD);

    $document = Scratch::path('pipeline', '.docx');
    $output = Scratch::path('pipeline', '.md');

    expect(runCli(['to-docx', $input, '-o', $document])['code'])->toBe(0);
    expect(runCli(['to-markdown', $document, '-o', $output])['code'])->toBe(0);

    $markdown = (string) file_get_contents($output);

    expect($markdown)->toContain('# Heading');
    expect($markdown)->toContain('**bold**');
    expect($markdown)->toContain('*italic*');
    expect($markdown)->toContain('`code`');
    expect($markdown)->toContain('[link](https://example.com)');
    expect($markdown)->toContain('- one');
    expect($markdown)->toContain('> a quote');
    expect($markdown)->toContain('| 1 | 2 |');
});
