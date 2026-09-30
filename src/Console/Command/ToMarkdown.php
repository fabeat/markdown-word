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
final class ToMarkdown extends BaseCommand
{
    /**
     * @param list<string> $argv
     */
    public function execute(array $argv): int
    {
        $command = $this->parseOptions($argv, 'convert a Word document to Markdown.');

        if ($command === null) {
            return Application::SUCCESS;
        }

        $input = $command->input();
        $output = $this->outputPath($command, '.md');

        $markdown = $this->convert($command, $input, $output);

        // Reading and converting the document is the slow part of a run, so the
        // output is looked at again here rather than trusted from before it.
        $this->guardAgainstOverwrite($input, $output);

        $this->application->writeResult($output, $markdown);

        $this->report($input, $output);

        return Application::SUCCESS;
    }

    protected static function other(): string
    {
        return ToDocx::class;
    }

    protected static function formats(): array
    {
        return ['markdown', 'md'];
    }

    /**
     * Read the document and return its Markdown.
     *
     * A `.docx` is a `zip` archive, and reading one from memory means writing it
     * to a scratch file first. The document is read as a string whichever way it
     * arrived — `readInput()` hands back contents either way — so both branches
     * pay for that round trip, and the only thing the file's own path is good
     * for is the direction check the application has already made.
     */
    private function convert(CommandLine $command, ?string $input, string $output): string
    {
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
            // the reader puts them and the reference it writes is used as it
            // comes out — relative to the directory it ran in, which is where
            // the image was found.
            return $read();
        }

        try {
            return $read();
        } finally {
            @chdir($previous);
        }
    }

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
            // The images are taken out beside the Markdown rather than beside the
            // document, so the reference and the file agree; {@see self::withMedia()}
            // runs the reader from that directory to keep the two the same path.
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
