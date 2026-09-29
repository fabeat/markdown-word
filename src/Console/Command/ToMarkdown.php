<?php

declare(strict_types=1);

namespace MarkdownWord\Console\Command;

use MarkdownWord\Console\Application;
use MarkdownWord\Console\CommandLine;
use MarkdownWord\Console\ConsoleException;
use MarkdownWord\Reverse\Options;

/**
 * `mdword to-markdown` — a Word document in, Markdown out.
 */
final class ToMarkdown implements Command
{
    public function __construct(private readonly Application $application)
    {
    }

    /**
     * @param list<string> $argv
     */
    public function execute(array $argv): int
    {
        $command = CommandLine::parse(
            $argv,
            values: self::spec()['values'],
            flags: self::spec()['flags'],
            repeated: self::spec()['repeated'],
            aliases: self::spec()['aliases'],
            foreign: Application::specFor(ToDocx::class),
        );

        if ($command->flag('help')) {
            $this->application->print(Application::commandHelp(self::class, 'convert a Word document to Markdown.'));

            return Application::SUCCESS;
        }

        self::assertDirection($command);

        $input = $command->input();
        $output = $this->application->outputPath($input, '.md', $command->value('output'));

        self::assertNotOverwriting($input, $output);

        $this->application->writeResult($output, $this->convert($command, $input, $output));

        $this->application->report($input, $output);

        return Application::SUCCESS;
    }

    /**
     * A `--to` that names this direction is redundant, and one that names the
     * other is a contradiction worth saying so about rather than ignoring.
     */
    private static function assertDirection(CommandLine $command): void
    {
        $asked = $command->value('to');

        if ($asked !== null && $asked !== 'markdown' && $asked !== 'md') {
            throw new ConsoleException(
                sprintf('--to %s does not match what this reads: it takes a Word document.', $asked),
                ['Omit it and let mdword work the direction out from the file.'],
            );
        }
    }

    /**
     * Refuse to write the result over the file it was read from.
     */
    private static function assertNotOverwriting(?string $input, string $output): void
    {
        if ($input === null || $input === '-' || $output === '-') {
            return;
        }

        $from = realpath($input);
        $to = realpath(dirname($output) . '/' . basename($output));

        if ($from !== false && $to !== false && $from === $to) {
            throw new ConsoleException(
                sprintf('The result would overwrite the input file "%s".', $input),
                ['Pass --output to write it somewhere else.'],
            );
        }
    }

    /**
     * Read the document and return its Markdown.
     *
     * A `.docx` is a `zip` archive, and reading one from memory means writing it
     * to a scratch file first, so a document that is already on disk is read
     * where it lies. Only standard input — which has no path to read — pays for
     * the round trip.
     */
    private function convert(CommandLine $command, ?string $input, string $output): string
    {
        // The document is named once, in the constructor, and converting it is
        // then the one verb both directions share.
        $reader = $this->application->reader($this->readingOptions($command), $this->readDocument($input));

        return $this->withMedia($command, $output, static fn (): string => $reader->convert());
    }

    private function readDocument(?string $input): string
    {
        if ($input === null || $input === '-') {
            return $this->application->readInput(null);
        }

        if (!is_file($input)) {
            throw new ConsoleException(sprintf('The file "%s" does not exist.', $input));
        }

        return $this->application->readInput($input);
    }

    /**
     * Take the images out of the document, relative to where the Markdown goes.
     *
     * The Markdown refers to the images beside it, and the images are written
     * beside it, so both paths are the same — which means the reader has to be
     * running from the output's own directory to agree with the file. The
     * directory is changed for the duration and put back afterwards, including
     * when the conversion throws.
     */
    private function withMedia(CommandLine $command, string $output, callable $read): string
    {
        if ($command->value('media') === null || $output === '-') {
            return $read();
        }

        $directory = dirname(realpath($output) ?: $output);
        $previous = getcwd();

        if ($previous === false || !@chdir($directory)) {
            // The Markdown still has to be written, so the images are left where
            // the reader puts them and the reference is made absolute instead.
            return $read();
        }

        try {
            return $read();
        } finally {
            @chdir($previous);
        }
    }

    /**
     * The reading options this run asks for.
     */
    private function readingOptions(CommandLine $command): Options
    {
        $overrides = ['headingSetext' => $command->flag('setext')];

        if ($command->flag('no-fence')) {
            $overrides['fenceCodeBlocks'] = false;
        }

        if ($command->flag('no-header')) {
            $overrides['tableHeader'] = false;
        }

        $media = $command->value('media');

        if ($media !== null) {
            // The images are taken out beside the Markdown rather than beside
            // the document, so that the reference and the file agree. The
            // directory is made relative to the output and the reader is run
            // from there, so the two are the same path written twice.
            $overrides['mediaDirectory'] = rtrim($media, '/');
        }

        $ending = $command->value('line-ending');

        if ($ending !== null) {
            $overrides['lineEnding'] = self::lineEnding($ending);
        }

        return Options::fromArray($overrides);
    }

    private static function lineEnding(string $name): string
    {
        return match (strtolower($name)) {
            'lf', 'unix', 'n' => "\n",
            'crlf', 'dos', 'windows', 'r' => "\r\n",
            default => throw new ConsoleException(
                sprintf('Unknown line ending "%s".', $name),
                ['Use lf or crlf.'],
            ),
        };
    }

    /**
     * @return array{values: list<string>, flags: list<string>, repeated: list<string>, aliases: array<string, string>}
     */
    public static function spec(): array
    {
        return [
            'values' => ['output', 'media', 'line-ending', 'to'],
            'flags' => ['help', 'setext', 'no-fence', 'no-header'],
            'repeated' => [],
            'aliases' => ['o' => 'output', 'h' => 'help'],
        ];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function options(): array
    {
        return [
            ['-o, --output <file>', 'where the Markdown goes; "-" for standard output'],
            ['--media <dir>', 'take the images out of the document into this directory'],
            ['--line-ending <lf|crlf>', 'what the output file uses between lines (default: lf)'],
            ['--setext', 'write first- and second-level headings underlined'],
            ['--no-fence', 'leave monospaced paragraphs as text rather than a code block'],
            ['--no-header', 'do not treat the first table row as a header'],
            ['--to <format>', 'which way to convert; detected from the file otherwise'],
            ['-h, --help', 'this text'],
        ];
    }
}
