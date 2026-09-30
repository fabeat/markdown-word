<?php

declare(strict_types=1);

namespace MarkdownWord\Console\Command;

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Options;
use MarkdownWord\Console\Application;
use MarkdownWord\Console\CommandLine;
use MarkdownWord\Console\ConsoleException;
use ZipArchive;

/**
 * `mdword to-docx` — Markdown in, a Word document out.
 */
final class ToDocx extends BaseCommand
{
    /** The region a template is expected to have, used when `--region` is not given. */
    public const DEFAULT_REGION = 'body';

    /**
     * @param list<string> $argv
     */
    public function execute(array $argv): int
    {
        $command = $this->parseOptions($argv, 'convert Markdown to a Word document.');

        if ($command === null) {
            return Application::SUCCESS;
        }

        $input = $command->input();
        $markdown = $this->application->readInput($input);
        $output = $this->outputPath($command, '.docx');

        $config = $this->application->configuration(
            $command->value('config'),
            $this->overrides($command, $input),
            $command->flag('plain'),
        );

        $template = $command->value('template');

        if ($template === null) {
            $this->rejectWithoutTemplate($command);
            $this->convert($config, $markdown, $input, $output);
        } else {
            $this->intoTemplate($markdown, $template, $command, $config, $input, $output);
        }

        $this->report($input, $output);

        return Application::SUCCESS;
    }

    /**
     * The conversion itself, exactly once whichever way the result is going.
     *
     * The two are exclusive because a run's worth of work asked for twice is
     * still twice the work: `-o -` would otherwise convert the whole document,
     * throw the bytes away and build a second converter to do it all again, on
     * the one path the usage text advertises for `| pbcopy`.
     */
    private function convert(Configuration $config, string $markdown, ?string $input, string $output): void
    {
        $this->guardAgainstOverwrite($input, $output);

        $converter = $this->application->converter($config, $markdown);

        if ($output === '-') {
            $this->application->writeResult($output, $converter->convert());

            return;
        }

        $converter->convert($output);
    }

    protected static function other(): string
    {
        return ToMarkdown::class;
    }

    protected static function formats(): array
    {
        return ['docx', 'word'];
    }

    /**
     * The option values that override the configuration for this run.
     *
     * @return array<string, mixed>
     */
    private function overrides(CommandLine $command, ?string $input): array
    {
        $overrides = [];

        if ($command->flag('no-images')) {
            $overrides['images'] = Options::IMAGE_SKIP;
        } elseif ($command->value('images') !== null) {
            $overrides['images'] = self::imageMode((string) $command->value('images'));
        }

        // A relative image path in a file means "next to the file", as a Markdown
        // renderer in an editor would treat it. Standard input has no such
        // anchor, and there the working directory is the only thing to go on.
        $base = $command->value('image-base') ?? Application::directoryOf($input) ?? getcwd();

        if (is_string($base) && $base !== '') {
            $overrides['imageBasePath'] = $base;
        }

        if ($command->value('table-width') !== null) {
            $overrides['tableWidth'] = self::tableWidth((string) $command->value('table-width'));
        }

        return $overrides;
    }

    private static function imageMode(string $mode): string
    {
        return match (strtolower($mode)) {
            'embed' => Options::IMAGE_EMBED,
            'placeholder', 'alt' => Options::IMAGE_PLACEHOLDER,
            'skip', 'none' => Options::IMAGE_SKIP,
            default => throw new ConsoleException(
                sprintf('Unknown image mode "%s".', $mode),
                ['Use embed, placeholder or skip.'],
            ),
        };
    }

    private static function tableWidth(string $width): int
    {
        if (!preg_match('/^\d+$/', $width)) {
            throw new ConsoleException(
                sprintf('The table width "%s" is not a number.', $width),
                ['It is in fiftieths of a percent of the text column, so 5000 is the full width.'],
            );
        }

        return (int) $width;
    }

    /**
     * Refuse a template-only option on a run that is not using a template.
     */
    private function rejectWithoutTemplate(CommandLine $command): void
    {
        $offenders = [];

        if ($command->repeated('define') !== []) {
            $offenders[] = '--define';
        }

        if ($command->value('region') !== null) {
            $offenders[] = '--region';
        }

        if ($offenders === []) {
            return;
        }

        throw new ConsoleException(
            sprintf('%s only means something together with --template.', implode(' and ', $offenders)),
            ['A template names the region the Markdown goes into.'],
        );
    }

    /**
     * Render into an existing document used as a template.
     */
    private function intoTemplate(
        string $markdown,
        string $path,
        CommandLine $command,
        Configuration $config,
        ?string $input,
        string $output,
    ): void {
        $values = [];

        foreach ($command->repeated('define') as $pair) {
            [$name, $value] = CommandLine::pair($pair, '--define');
            $values[$name] = $value;
        }

        $region = (string) $command->value('region', self::DEFAULT_REGION);

        // Before anything is written, so that a wrong name fails here rather
        // than leaving a document full of unreplaced `${...}` markers.
        self::assertRegionExists($path, $region);

        $template = $this->application->template($path, $config, $values);
        $template->insert($region, $markdown);

        // Filling the region is the slow part of a run, so the output is looked
        // at again here rather than trusted from before it.
        $this->guardAgainstOverwrite($input, $output);

        if ($output === '-') {
            $this->application->writeResult($output, $template->toString());

            return;
        }

        $template->save($output);
    }

    /**
     * Check the template actually has the region, before anything is written.
     *
     * Filling a region that is not there leaves the `${name}` markers in the
     * finished document, so a wrong name would otherwise produce a file that
     * looks fine and is full of placeholders. Failing here says which regions
     * the template does have.
     *
     * @throws ConsoleException when the region is not in the template.
     */
    private static function assertRegionExists(string $path, string $region): void
    {
        if (!is_file($path)) {
            throw new ConsoleException(sprintf('The template "%s" does not exist.', $path));
        }

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new ConsoleException(
                sprintf('The template "%s" could not be opened.', $path),
                ['A Word template is a .docx file, which is a zip archive.'],
            );
        }

        try {
            $document = $zip->getFromName('word/document.xml');
        } finally {
            $zip->close();
        }

        if (!is_string($document)) {
            throw new ConsoleException(sprintf('The template "%s" has no document body.', $path));
        }

        // The markers are spread across runs by whoever built the template, so
        // each half is looked for on its own: the closing one is what tells a
        // region apart from a single-line `${name}` value.
        if (str_contains($document, '${' . $region . '}') && str_contains($document, '${/' . $region . '}')) {
            return;
        }

        preg_match_all('/\$\{\/?([A-Za-z_][A-Za-z0-9_]*)\}/', $document, $matches);
        $found = array_values(array_unique($matches[1] ?? []));

        sort($found);

        throw new ConsoleException(
            sprintf('The template has no "${%s}" region.', $region),
            $found === []
                ? ['It has no ${name} regions at all. See the template contract in the README.']
                : ['It does have: ' . implode(', ', array_map(
                    static fn (string $name): string => '${' . $name . '}',
                    $found,
                ))],
        );
    }

    /**
     * @return array{values: list<string>, flags: list<string>, repeated: list<string>, aliases: array<string, string>}
     */
    public static function spec(): array
    {
        return [
            'values' => ['output', 'config', 'template', 'region', 'images', 'image-base', 'table-width', 'to'],
            'flags' => ['help', 'no-images', 'plain'],
            'repeated' => ['define'],
            'aliases' => ['o' => 'output', 't' => 'template', 'c' => 'config', 'h' => 'help'],
        ];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function options(): array
    {
        return [
            ['-o, --output <file>', 'where the result goes; "-" for standard output'],
            ['-c, --config <file.php>', 'a file returning the styles and options to use'],
            ['-t, --template <file.docx>', 'render into a Word template rather than a new document'],
            ['--region <name>', 'the template region to fill in (default: body)'],
            ['--define <name=value>', 'a value for a single-line placeholder; repeatable'],
            ['--images <mode>', 'embed, placeholder or skip'],
            ['--no-images', 'shorthand for --images skip'],
            ['--image-base <dir>', 'where relative image paths resolve from'],
            ['--table-width <n>', 'table width in fiftieths of a percent; 5000 is full width'],
            ['--plain', 'no code colouring, no quote style, no table borders'],
            ['--to <format>', 'which way to convert; detected from the file otherwise'],
            ['-h, --help', 'this text'],
        ];
    }
}
