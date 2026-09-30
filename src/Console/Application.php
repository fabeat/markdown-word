<?php

declare(strict_types=1);

namespace MarkdownWord\Console;

use MarkdownWord\Configuration;
use MarkdownWord\Converter;
use MarkdownWord\Console\CommandLine;
use MarkdownWord\Console\Command\Command;
use MarkdownWord\Console\Command\ToDocx;
use MarkdownWord\Console\Command\ToMarkdown;
use MarkdownWord\Input;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Reverse\Options as ReverseOptions;
use MarkdownWord\Template\MarkdownTemplate;
use MarkdownWord\WordToMarkdown;
use Throwable;

/**
 * The `mdword` command.
 *
 * Two commands, one per direction, so that the name of the command says what the
 * run does:
 *
 * ```text
 * mdword to-docx     README.md  -o README.docx
 * mdword to-markdown README.docx -o README.md
 * ```
 *
 * The whole interface lives here rather than in a script so it can be tested like
 * the rest of the library, and so the same code serves both the phar and a plain
 * checkout of the source.
 *
 * The result goes to standard output and everything else — progress, warnings,
 * errors — goes to standard error, so `mdword to-docx in.md -o - | pbcopy`
 * does what it looks like it does.
 */
final class Application
{
    public const NAME = 'mdword';

    public const VERSION = '1.0.0';

    /** Exit code for a run that converted something, and for a run that only answered a question. */
    public const SUCCESS = 0;

    /** Exit code for a run that did not finish, whether the user can put it right or it is a defect. */
    public const FAILURE = 1;

    /**
     * The commands, by the name they are typed under.
     *
     * The one list the dispatcher, the usage text and the alias table all read,
     * so a new direction is added in one place rather than in three.
     *
     * @var array<string, class-string<Command>>
     */
    private const COMMANDS = [
        'to-docx' => ToDocx::class,
        'to-markdown' => ToMarkdown::class,
    ];

    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    /** @var resource */
    private $in;

    /**
     * Bytes taken from the input while working out its direction, which the
     * command that reads it will not have seen.
     */
    private string $peeked = '';

    /**
     * @param resource|null $out Standard output; defaults to the process's.
     * @param resource|null $err Standard error; defaults to the process's.
     * @param resource|null $in  Standard input; defaults to the process's.
     */
    public function __construct($out = null, $err = null, $in = null)
    {
        $this->out = $out ?? STDOUT;
        $this->err = $err ?? STDERR;
        $this->in = $in ?? STDIN;
    }

    /**
     * Run a command and return the exit code.
     *
     * @param list<string> $argv The arguments, without the program name.
     */
    public function run(array $argv): int
    {
        // Installed for the whole run rather than around each write: a document
        // is written in several places, and the filter has to span all of them.
        // Wrapped rather than installed and taken off again, because a host that
        // installed the filter itself before embedding this keeps it: taking it
        // off would leave the rest of that process with nothing between it and
        // PHPWord's warnings.
        return UpstreamDeprecations::quietly(fn (): int => $this->runQuietly($argv));
    }

    /**
     * The run itself, with the filter already in place.
     *
     * @param list<string> $argv
     */
    private function runQuietly(array $argv): int
    {
        try {
            return $this->dispatch($argv);
        } catch (ConsoleException $e) {
            $this->error($e->getMessage());

            foreach ($e->hints() as $hint) {
                $this->error('  ' . $hint);
            }

            return self::FAILURE;
        } catch (Throwable $e) {
            // Anything arriving here is a defect rather than a mistake, so it is
            // reported in full: the message, the type, and where it happened.
            // A stack trace would be noise, since the phar has no source paths
            // that mean anything to the person reading it. The exit code is the
            // same as for a mistake, since a program that cannot report the
            // difference has no better one to offer.
            $this->error(sprintf('%s: %s', $e::class, $e->getMessage()));
            $this->error(sprintf('  at %s:%d', $e->getFile(), $e->getLine()));

            return self::FAILURE;
        }
    }

    /**
     * @param list<string> $argv
     */
    private function dispatch(array $argv): int
    {
        $command = $argv[0] ?? null;

        if ($command === null || $command === 'help' || $command === '--help' || $command === '-h') {
            $this->write($this->out, $this->usage());

            return self::SUCCESS;
        }

        if ($command === '--version' || $command === '-V' || $command === 'version') {
            $this->write($this->out, sprintf("%s %s%s", self::NAME, self::VERSION, PHP_EOL));

            return self::SUCCESS;
        }

        // The direction is a command when it is asked for and worked out from the
        // file when it is not, so `mdword notes.md` does the obvious thing and a
        // script can still be explicit.
        if (isset(self::COMMANDS[$command])) {
            $class = self::COMMANDS[$command];

            return (new $class($this))->execute(array_slice($argv, 1));
        }

        // Nothing to strip: whatever is in front is either the file to read or an
        // option, and the whole line goes to the command that gets chosen. A bare
        // word that is not a file is reported as a missing file rather than as an
        // unknown command, because a file may be called anything at all.
        return $this->runInDirection($argv);
    }

    /**
     * Work out which way the data should go and run that command.
     *
     * The input is the one thing both directions have in common, and a Word
     * document is a zip archive while Markdown is text, so the file itself is
     * enough to tell them apart — no extension, and no naming convention to
     * remember. `--to` overrides it, which is what makes reading from standard
     * input work at all, since a pipe has no name to go on.
     *
     * @param list<string> $argv The whole command line, with no command name in it.
     */
    private function runInDirection(array $argv): int
    {
        $command = CommandLine::parse(
            $argv,
            values: ['to', 'output', 'config', 'template', 'region', 'images', 'image-base', 'table-width', 'media', 'line-ending'],
            flags: ['help', 'no-images', 'plain', 'setext', 'no-fence', 'no-header'],
            repeated: ['define'],
            aliases: self::aliasMap(),
        );

        $input = $command->input();
        $detected = $input !== null && $input !== '-' ? $this->detectDirection($input) : null;
        $forced = $command->value('to');

        if ($forced === null) {
            $direction = $detected ?? $this->detectDirection($input);
        } else {
            $direction = self::directionFor($forced);

            if ($detected !== null && $detected !== $direction) {
                throw new ConsoleException(
                    sprintf('--to %s does not match "%s".', $forced, $input),
                    [sprintf('That file is %s.', $detected === 'to-docx' ? 'Markdown' : 'a Word document')],
                );
            }
        }

        $class = self::COMMANDS[$direction];

        return (new $class($this))->execute($argv);
    }

    private static function directionFor(string $asked): string
    {
        return match (strtolower($asked)) {
            'docx', 'word' => 'to-docx',
            'markdown', 'md' => 'to-markdown',
            default => throw new ConsoleException(
                sprintf('Unknown format "%s".', $asked),
                ['Use docx or markdown.'],
            ),
        };
    }

    /**
     * Which way a file has to go, from what it contains.
     *
     * A `.docx` is a zip archive and begins `PK\x03\x04`; Markdown is text and
     * begins with readable characters. The four bytes that decide it are part of
     * the format rather than a convention, so this holds for a file with any
     * name at all.
     */
    private function detectDirection(?string $input): string
    {
        $fromStream = $input === null || $input === '-';

        // Suppressed so that a missing file produces the sentence below rather
        // than PHP's own warning followed by it.
        $handle = $fromStream ? $this->in : @fopen($input, 'rb');

        if ($handle === false) {
            throw new ConsoleException(
                sprintf('The file "%s" does not exist.', (string) $input),
                ['Name the file to read, or omit it to read standard input.'],
            );
        }

        // A file is opened separately and thrown away, so nothing is consumed
        // from the input. A stream cannot be rewound, so the bytes read are kept
        // and handed back to whoever reads the input next — otherwise the
        // document would arrive four bytes short and no longer be an archive.
        $magic = (string) fread($handle, 4);

        if ($fromStream) {
            $this->peeked .= $magic;
        } else {
            fclose($handle);
        }

        return Input::looksLikeDocument($magic) ? 'to-markdown' : 'to-docx';
    }

    /**
     * The short spellings, from every command that has one.
     *
     * @return array<string, string>
     */
    public static function aliasMap(): array
    {
        $aliases = [];

        foreach (self::COMMANDS as $class) {
            $aliases = array_merge($aliases, $class::spec()['aliases']);
        }

        return $aliases;
    }

    /**
     * The parser specification of one command, for telling a person which command
     * an option they used actually belongs to.
     *
     * @param class-string<Command> $command
     * @return array<string, string>
     */
    public static function specFor(string $command): array
    {
        $foreign = [];
        $name = self::commandName($command);

        foreach (self::names($command::spec()) as $option) {
            $foreign[$option] = $name;
        }

        return $foreign;
    }

    /**
     * @param class-string<Command> $command
     */
    private static function commandName(string $command): string
    {
        $short = substr($command, (int) strrpos($command, '\\') + 1);

        // `ToMarkdown` reads as `to-markdown`. The matched letter is kept by
        // referring to it in the replacement; replacing it outright would give
        // `to-arkdown`.
        return strtolower((string) preg_replace('/(?<!^)([A-Z])/', '-$1', $short));
    }

    /**
     * Every option name a specification mentions.
     *
     * @param array{values: list<string>, flags: list<string>, repeated: list<string>, aliases: array<string, string>} $spec
     * @return list<string>
     */
    public static function names(array $spec): array
    {
        return array_values(array_unique(array_merge(
            $spec['values'],
            $spec['flags'],
            $spec['repeated'],
            array_values($spec['aliases']),
        )));
    }


    /**
     * The text `mdword help` prints.
     *
     * One section per command, taken from {@see self::COMMANDS}, and the option
     * table in it is the one each command publishes for readers. The parser is
     * given a different list, the specification; a test diffs the two in both
     * directions, so the help cannot offer an option the parser rejects without
     * the suite noticing.
     */
    public function usage(): string
    {
        $lines = [
            self::NAME . ' — convert Markdown to Word, and back.',
            '',
            'USAGE',
            '  ' . self::NAME . ' <file>            # the direction is worked out from the file',
            '  ' . self::NAME . ' to-docx     [<markdown>] [options]',
            '  ' . self::NAME . ' to-markdown [<docx>]    [options]',
            '  ' . self::NAME . ' help',
            '  ' . self::NAME . ' --version',
            '',
            'A Word document is a zip archive and Markdown is text, so the file says',
            'which way it has to go. Naming the command anyway is allowed and is what',
            'a script should do; `--to docx` or `--to markdown` says it in one word',
            'and is the only way to be explicit when reading from standard input.',
            '',
            'The input is read from standard input when no file is named. The result is',
            'written to standard output when the output is "-", or when there is no input',
            'file to derive a name from.',
            '',
        ];

        foreach (self::COMMANDS as $class) {
            foreach (self::describeCommand($class) as $line) {
                $lines[] = $line;
            }

            $lines[] = '';
        }

        $lines[] = 'EXAMPLES';
        $lines[] = '  ' . self::NAME . ' README.md                    # -> README.docx';
        $lines[] = '  ' . self::NAME . ' README.docx                  # -> README.md';
        $lines[] = '  ' . self::NAME . ' to-docx README.md -o README.docx';
        $lines[] = '  ' . self::NAME . ' to-docx notes.md --template report.docx --region body --define customer=Northwind';
        $lines[] = '  ' . self::NAME . ' to-markdown report.docx --media assets -o report.md';
        $lines[] = '  cat notes.md | ' . self::NAME . ' to-docx - -o - | pbcopy';

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * The heading and option table for one command.
     *
     * The option list is the command's own, published for readers, and the
     * parser is built from its other list; a test holds the two in step.
     *
     * @param class-string<Command> $command
     * @return list<string>
     */
    public static function describeCommand(string $command): array
    {
        // `ToDocx` reads as "to docx", which is how the command is written.
        $short = (string) preg_replace('/(?<!^)[A-Z]/', ' $0', substr($command, (int) strrpos($command, '\\') + 1));
        $heading = strtoupper(str_replace('_', ' ', $short));
        $lines = [$heading, str_repeat('-', strlen($heading))];

        foreach ($command::options() as [$flags, $description]) {
            $lines[] = sprintf('  %-30s %s', $flags, $description);
        }

        return $lines;
    }

    /**
     * The help for a single command, as `--help` on that command prints it.
     *
     * @param class-string<Command> $command
     */
    public static function commandHelp(string $command, string $summary): string
    {
        // The name as it is typed, not as the heading spells it out: the heading
        // reads "TO DOCX" and the usage line has to read `to-docx`, because that
        // is what someone would type.
        $name = self::commandName($command);

        return implode(PHP_EOL, [
            self::NAME . ' ' . $name . ' — ' . $summary,
            '',
            'USAGE',
            '  ' . self::NAME . ' ' . $name . ' [<file>] [options]',
            '',
            'OPTIONS',
            ...self::describeCommand($command),
            '',
        ]) . PHP_EOL;
    }

    // ------------------------------------------------------------- services
    // Used by the commands, and public so that a caller embedding this in a
    // command line of their own can use the same implementations rather than
    // write their own.

    /**
     * The configuration for a run: the one a `--config` file names, with
     * `--plain` applied and then the individual options layered on top.
     *
     * @param array<string, mixed> $overrides
     */
    public function configuration(?string $configFile, array $overrides, bool $plain = false): Configuration
    {
        $config = $configFile === null ? new Configuration() : $this->loadConfiguration($configFile);

        if ($plain) {
            $config = $config->withoutDecoration();
        }

        return $overrides === [] ? $config : $config->withOptions($overrides);
    }

    /**
     * Read a configuration file: a PHP file returning either a
     * {@see Configuration} or the array form {@see Configuration::fromArray()}
     * understands.
     */
    private function loadConfiguration(string $path): Configuration
    {
        if (!is_file($path)) {
            throw new ConsoleException(sprintf('The configuration file "%s" does not exist.', $path));
        }

        $loaded = require $path;

        if ($loaded instanceof Configuration) {
            return $loaded;
        }

        if (is_array($loaded)) {
            return Configuration::fromArray($loaded);
        }

        throw new ConsoleException(
            sprintf('The configuration file "%s" must return an array or a Configuration.', $path),
            ['Returning `MarkdownWord\Configuration::fromArray([...])` is the usual form.'],
        );
    }

    /**
     * The input, from a file or from standard input.
     */
    public function readInput(?string $path): string
    {
        if ($path === null || $path === '-') {
            // Whatever the direction check read is given back here, so the command
            // sees the whole input rather than the part after it.
            $peeked = $this->peeked;
            $this->peeked = '';

            return $peeked . (string) stream_get_contents($this->in);
        }

        if (!is_file($path)) {
            throw new ConsoleException(sprintf('The file "%s" does not exist.', $path));
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new ConsoleException(sprintf('Unable to read "%s".', $path));
        }

        return $contents;
    }

    /**
     * Write the result: to a file, or to standard output when the path is `-`.
     */
    public function writeResult(string $path, string $contents): void
    {
        if ($path === '-') {
            $this->write($this->out, $contents);

            return;
        }

        $directory = dirname($path);

        if ($directory !== '' && !is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new ConsoleException(sprintf('Unable to create the directory "%s".', $directory));
        }

        if (file_put_contents($path, $contents) === false) {
            throw new ConsoleException(sprintf('Unable to write "%s".', $path));
        }
    }

    /**
     * Where the result should go, when the user did not say.
     *
     * A named input gives a name to derive from. Standard input does not, so the
     * result goes to standard output rather than to a file nobody asked for.
     */
    public function outputPath(?string $input, string $extension, ?string $requested): string
    {
        if ($requested !== null) {
            return $requested;
        }

        if ($input === null || $input === '-') {
            return '-';
        }

        // The stem is the name without its extension, in the directory it was
        // read from, so `docs/notes.md` becomes `docs/notes.docx` rather than
        // something that merely looks like it.
        $directory = pathinfo($input, PATHINFO_DIRNAME);
        $stem = pathinfo($input, PATHINFO_FILENAME);

        // A file whose whole name is an extension, such as `.md`, has no stem to
        // work with; the name is then used whole.
        if ($stem === '') {
            $stem = $input;
            $directory = '.';
        }

        $prefix = ($directory === '' || $directory === '.') ? '' : $directory . '/';

        return $prefix . $stem . $extension;
    }

    /**
     * The converter, for callers that want the object rather than a written file.
     *
     * Typed as the interface rather than the class, so a caller holding one of
     * the two directions cannot tell them apart by accident.
     */
    public function converter(Configuration $config, ?string $source = null): Converter
    {
        return new MarkdownToWord($source, $config);
    }

    /**
     * The other direction, the same contract.
     */
    public function reader(ReverseOptions $options, ?string $source = null): Converter
    {
        return new WordToMarkdown($source, $options);
    }

    public function template(string $path, Configuration $config, array $values): MarkdownTemplate
    {
        return new MarkdownTemplate($path, $config, $values);
    }

    /**
     * The directory of the input file, which relative image paths resolve
     * against — the same rule a Markdown renderer in an editor would follow.
     */
    public static function directoryOf(?string $path): ?string
    {
        if ($path === null || $path === '' || $path === '-') {
            return null;
        }

        $resolved = realpath($path);

        return $resolved === false ? null : dirname($resolved);
    }

    /**
     * Note progress on standard error, so a piped standard output stays clean.
     */
    public function progress(string $message): void
    {
        $this->error($message);
    }

    public function error(string $message): void
    {
        $this->write($this->err, self::NAME . ': ' . $message . PHP_EOL);
    }

    /**
     * Write to standard output.
     *
     * For the help, which is the only thing that goes out this way: a converted
     * document leaves through {@see self::writeResult()}, which writes to the
     * result path or hands the bytes straight to the stream. Either way it goes
     * through `write()` rather than reaching for `STDOUT`, which is what makes a
     * run drivable from a test.
     */
    public function print(string $text): void
    {
        $this->write($this->out, $text);
    }

    /**
     * Say on standard error what a run converted and where it put it.
     *
     * `-` is how a stream is asked for, so a person is told which stream rather
     * than being shown a dash.
     */
    public function report(?string $input, string $output): void
    {
        $this->progress(sprintf(
            '%s → %s',
            $input === null || $input === '-' ? 'standard input' : $input,
            $output === '-' ? 'standard output' : $output,
        ));
    }

    /**
     * @param resource $stream
     */
    public function write($stream, string $text): void
    {
        // A memory sink opened with `php://memory` in a test accepts a plain
        // write just as a pipe does.
        fwrite($stream, $text);
    }
}
