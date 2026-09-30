<?php

declare(strict_types=1);

/**
 * Runs the examples from README.md so the documentation cannot drift from the
 * code. Not part of the test suite; run it with `php readme-check.php`.
 *
 * Every PHP block on that page is here, and the `mdword` one-liners are driven
 * through the application. That is worth less than it sounds if half the examples
 * are quietly absent — which is what happened once, seven checks for nine
 * examples and a claim on the page that all of them ran. So the list below is
 * meant to be read against the README rather than trusted, and a new example
 * there is a new check here.
 */

require __DIR__ . '/vendor/autoload.php';

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Options;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\Console\Application;
use MarkdownWord\Console\UpstreamDeprecations;
use MarkdownWord\Converter;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Render\LinkPlaceholder;
use MarkdownWord\Reverse\Options as ReverseOptions;
use MarkdownWord\Template\MarkdownTemplate;
use MarkdownWord\Text\TextExtractor;
use MarkdownWord\WordToMarkdown;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Style\Paragraph;
use PhpOffice\PhpWord\TemplateProcessor;

// One dependency emits a deprecation for every list item it writes, and the run
// would print thousands of somebody else's lines; see the class for why.
UpstreamDeprecations::install();

$work = __DIR__ . '/tmp/readme';
@mkdir($work, 0o777, true);

/**
 * Every part of a `.docx`, keyed by name, with what a clock changes taken out.
 *
 * Two things vary between two correct conversions of the same input, and neither
 * is a difference in the document: the zip's per-entry timestamps — two seconds
 * of resolution, no sub-second part, no time zone — which live in the container
 * and not in the parts, and `docProps/core.xml`, which records when the document
 * was created and last modified. What has to match is everything else, so that
 * is what this compares: a check that compared the archive's bytes failed about
 * three times in four on any machine slow enough to cross a two-second boundary
 * between the two writes.
 *
 * @return array<string, string>
 */
function documentParts(string $docx): array
{
    $path = tempnam(sys_get_temp_dir(), 'mdword-parts-');

    if ($path === false) {
        throw new RuntimeException('Unable to create a temporary file.');
    }

    try {
        file_put_contents($path, $docx);

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('The document is not a readable archive.');
        }

        $parts = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if ($stat === false || !is_string($stat['name'] ?? null)) {
                continue;
            }

            // Every entry is wanted, the empty directories and all, so that a
            // difference in what is present is a difference here too.
            $content = (string) $zip->getFromIndex($index);

            $parts[$stat['name']] = $stat['name'] === 'docProps/core.xml'
                ? withoutTimestamps($content)
                : $content;
        }

        $zip->close();

        ksort($parts);

        return $parts;
    } finally {
        @unlink($path);
    }
}

/**
 * A document's core properties with the two dates blanked: `dcterms:created` and
 * `dcterms:modified` are the only parts of a `.docx` that say when it was made,
 * and leaving them in makes the comparison a test of the clock.
 */
function withoutTimestamps(string $coreProperties): string
{
    return (string) preg_replace(
        ['#<dcterms:(created|modified)[^>]*>.*?</dcterms:\1>#s', '#<dcterms:(created|modified)[^>]*/>#'],
        '<dcterms:$1>whenever</dcterms:$1>',
        $coreProperties,
    );
}

$failures = 0;
$checks = 0;

$check = static function (string $name, callable $body) use (&$failures, &$checks): void {
    $checks++;

    try {
        $body();
        echo "ok    {$name}\n";
    } catch (\Throwable $e) {
        $failures++;
        echo "FAIL  {$name}: {$e->getMessage()}\n";
    }
};

$check('quick start: Markdown to Word', function () use ($work): void {
    $md = $work . '/README.md';
    file_put_contents($md, "# Title\n\nBody.\n");
    (new MarkdownToWord($md))->save($work . '/quick.docx');
    assertTrue(is_file($work . '/quick.docx'), 'no file written');
});

$check('quick start: Word to Markdown', function () use ($work): void {
    (new WordToMarkdown($work . '/quick.docx'))->save($work . '/quick.md');
    assertTrue(str_contains((string) file_get_contents($work . '/quick.md'), '# Title'), 'the heading is missing');
});

$check('convert returns the result, save writes it', function () use ($work): void {
    file_put_contents($work . '/notes.md', "# Notes\n\nSome **bold** text.\n");

    $document = $work . '/four-ways.docx';
    $markdown = $work . '/four-ways-back.md';

    $bytes = (new MarkdownToWord($work . '/notes.md'))->convert();
    usleep(1100000);
    (new MarkdownToWord($work . '/notes.md'))->save($document);
    $back = (new WordToMarkdown($document))->convert();
    (new WordToMarkdown($document))->save($markdown);

    assertTrue(str_starts_with($bytes, 'PK'), 'convert() did not return the document');

    // Parts, not the archive's bytes: `documentParts()` says why.
    assertTrue(
        documentParts($bytes) === documentParts((string) file_get_contents($document)),
        'save() wrote a different document from the one convert() returned',
    );

    assertTrue(str_contains($back, '**bold**'), 'convert() did not return the Markdown');
    assertTrue(
        (string) file_get_contents($markdown) === $back,
        'save() wrote different Markdown from the one convert() returned',
    );
});

$check('a string that names a file is read from it', function () use ($work): void {
    file_put_contents($work . '/path-or-content.md', "# Either way\n");

    // Anything that is not a file is the content, so these two produce the same
    // document.
    $fromPath = (new MarkdownToWord($work . '/path-or-content.md'))->convert();
    $fromText = (new MarkdownToWord(file_get_contents($work . '/path-or-content.md')))->convert();

    assertTrue(str_starts_with($fromPath, 'PK'), 'the path produced no document');
    assertTrue(str_starts_with($fromText, 'PK'), 'the text produced no document');

    file_put_contents($work . '/from-path.docx', $fromPath);
    file_put_contents($work . '/from-text.docx', $fromText);

    assertTrue(
        self_documentBody($work . '/from-path.docx') === self_documentBody($work . '/from-text.docx'),
        'the two documents differ',
    );
});

$check('both directions through the interface', function () use ($work): void {
    file_put_contents($work . '/interface.md', "# Through the interface\n\nBody.\n");
    (new MarkdownToWord($work . '/interface.md'))->save($work . '/interface.docx');

    $convert = static function (Converter $converter, string $target): void {
        $converter->save($target);
    };

    $convert(new MarkdownToWord($work . '/interface.md'), $work . '/interface-a.docx');
    $convert(new WordToMarkdown($work . '/interface.docx'), $work . '/interface-a.md');

    assertTrue(is_file($work . '/interface-a.docx'), 'the first direction wrote nothing');
    assertTrue(
        str_contains((string) file_get_contents($work . '/interface-a.md'), '# Through the interface'),
        'the second direction lost the heading',
    );
});

$check('a round trip is two of them', function () use ($work): void {
    $word = new MarkdownToWord("# Round trip\n\nA paragraph.\n");
    $back = (new WordToMarkdown($word->convert()))->convert();

    assertTrue(str_contains($back, '# Round trip'), 'the heading did not survive');
    assertTrue(str_contains($back, 'A paragraph.'), 'the paragraph did not survive');
});

// Neither of the two commands the page prints can be run here: one needs a
// release that does not exist yet, the other a Composer install of the package as
// a dependency. What is checked is everything behind them — the package name, the
// `bin` entry the `vendor/bin/mdword` claim rests on, and the version the phar
// block asks for.
$check('installation: the package, the bin entry and the version', function (): void {
    $composer = json_decode(
        (string) file_get_contents(__DIR__ . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    assertTrue(
        $composer['name'] === 'fabeat/markdown-word',
        'the package is not named what the page asks for: ' . ($composer['name'] ?? '(no name)'),
    );

    // `composer require` puts the CLI in `vendor/bin` because of this entry, so a
    // page naming `vendor/bin/mdword` and a `bin` naming something else is a page
    // that is wrong.
    assertTrue(
        ($composer['bin'] ?? null) === ['bin/mdword'],
        'the `bin` entry is not `["bin/mdword"]`: ' . json_encode($composer['bin'] ?? null),
    );

    assertTrue(is_file(__DIR__ . '/bin/mdword'), 'the file the `bin` entry names is not there');

    // The phar block ends with `./mdword.phar --version`, and the archive's stub is
    // this same script, so the version a release would report is this answer.
    $version = self_cli(['--version']);

    assertTrue($version['code'] === 0, '--version exited ' . $version['code'] . ': ' . $version['err']);
    assertTrue(
        trim($version['out']) === Application::NAME . ' ' . Application::VERSION,
        'unexpected version output: ' . trim($version['out']),
    );
});

$check('the command line works the direction out for itself', function () use ($work): void {
    file_put_contents($work . '/cli.md', "# From the command line\n");
    (new MarkdownToWord($work . '/cli.md'))->save($work . '/cli.docx');

    $fromMarkdown = self_cli(['to-docx', $work . '/cli.md', '-o', $work . '/cli-1.docx']);
    $fromDocument = self_cli(['to-markdown', $work . '/cli.docx', '-o', $work . '/cli-1.md']);
    $detected = self_cli([$work . '/cli.md', '-o', $work . '/cli-2.docx']);
    $piped = self_cli(['--to', 'docx', '-', '-o', '-'], "# Piped in\n");

    assertTrue($fromMarkdown['code'] === 0, 'to-docx exited ' . $fromMarkdown['code'] . ': ' . $fromMarkdown['err']);
    assertTrue($fromDocument['code'] === 0, 'to-markdown exited ' . $fromDocument['code'] . ': ' . $fromDocument['err']);
    assertTrue($detected['code'] === 0, 'the direction was not worked out from the file: ' . $detected['err']);
    assertTrue($piped['code'] === 0, 'the piped conversion exited ' . $piped['code'] . ': ' . $piped['err']);
    assertTrue(substr($piped['out'], 0, 2) === 'PK', 'nothing came out of the pipe');
    assertTrue(
        str_contains((string) file_get_contents($work . '/cli-1.md'), '# From the command line'),
        'the document did not read back',
    );

    $short = self_cli(['--to', 'word', $work . '/cli.md', '-o', $work . '/cli-3.docx']);
    $shorter = self_cli(['--to', 'md', $work . '/cli-3.docx', '-o', '-']);

    assertTrue($short['code'] === 0, '--to word exited ' . $short['code'] . ': ' . $short['err']);
    assertTrue($shorter['code'] === 0, '--to md exited ' . $shorter['code'] . ': ' . $shorter['err']);
    assertTrue(str_contains($shorter['out'], '# From the command line'), '--to md produced nothing');
});

$check('Word to Markdown: convert, save and toMarkdown', function () use ($work): void {
    (new MarkdownToWord("# Bytes in hand\n"))->save($work . '/in-hand.docx');
    $bytes = (string) file_get_contents($work . '/in-hand.docx');

    $converted = (new WordToMarkdown($work . '/in-hand.docx'))->convert();
    (new WordToMarkdown($work . '/in-hand.docx'))->save($work . '/in-hand.md');
    $fromBytes = (new WordToMarkdown())->toMarkdown($bytes);

    assertTrue(str_contains($converted, '# Bytes in hand'), 'convert() lost the heading');
    assertTrue(str_contains($fromBytes, '# Bytes in hand'), 'toMarkdown() lost the heading');
    assertTrue((string) file_get_contents($work . '/in-hand.md') === $converted, 'save() wrote something else');
});

$check('the reader takes its options from an array', function () use ($work): void {
    // The example on the page names a directory beside the Markdown; here it is an
    // absolute path inside the scratch directory, and the rest is as written.
    $assets = $work . '/assets';
    self_redSquare($work . '/red-square.png');

    $config = Configuration::create()->withOptions([
        'images' => Options::IMAGE_EMBED,
        'imageBasePath' => $work,
    ]);
    (new MarkdownToWord('![A red square](red-square.png)', $config))->save($work . '/with-image.docx');

    $options = ReverseOptions::fromArray(['mediaDirectory' => $assets]);
    $markdown = (new WordToMarkdown($work . '/with-image.docx', $options))->convert();

    assertTrue($options->mediaDirectory === $assets, 'the option did not take');
    assertTrue(
        preg_match('/!\[[^\]]*\]\(([^)]+)\)/', $markdown, $match) === 1 && is_file($assets . '/' . basename($match[1])),
        'the image was not taken out of the document: ' . $markdown,
    );
});

// Every loss the page lists under "What the round trip does not preserve",
// reproduced rather than read out of the code: a list of what is lost is worth
// having only while it is true.
$check('what the round trip does not preserve', function () use ($work): void {
    $back = static function (string $markdown, ?Configuration $config = null): string {
        $config ??= new Configuration();
        $document = (new MarkdownToWord($markdown, $config))->convert();

        return (new WordToMarkdown($document))->convert();
    };

    // A table's header row comes back bold — the reader cannot tell the
    // renderer's `tableHeaderBold` from the author's `**`.
    $table = $back("| A | B |\n| --- | --- |\n| 1 | 2 |\n");

    assertTrue(
        str_contains($table, '| **A** | **B** |'),
        "the header row did not come back bold:\n{$table}",
    );

    // The delimiter row is the part that is rewritten, and the page shows the
    // rewrite, so what is asserted is the rewrite rather than merely a change.
    assertTrue(
        str_contains($table, '| :-- | :-- |'),
        "the delimiter row is not what the page shows:\n{$table}",
    );

    // The alignment is *not* part of the loss. `---:` came back as `--:`.
    $aligned = $back("| A | B | C |\n| :--- | ---: | :---: |\n| 1 | 2 | 3 |\n");

    assertTrue(
        str_contains($aligned, '| :-- | --: | :-: |'),
        "the column alignment did not survive:\n{$aligned}",
    );

    $fenced = $back("```php\n\$x = 1;\n\$y = 2;\n```\n");

    assertTrue(
        str_contains($fenced, "```\n\$x = 1;\n\$y = 2;\n```"),
        "the fence did not survive:\n{$fenced}",
    );
    assertTrue(!str_contains($fenced, 'php'), "the language survived:\n{$fenced}");

    // A fenced code block of one line is not read as a block at all: two or more
    // monospaced paragraphs are, which is what `fenceCodeBlocks` says.
    $oneLine = $back("```php\n\$x = 1;\n```\n");

    assertTrue(trim($oneLine) === '`$x = 1;`', "a one-line block is not inline code:\n{$oneLine}");

    // A quote configured as plain indentation rather than as a style is read as a
    // plain paragraph: `>` is a paragraph style, and there is none to recognise.
    $undecorated = Configuration::create()->withStyles([Styles::BLOCK_QUOTE => null]);
    $quote = $back("> quoted\n", $undecorated);

    assertTrue(trim($quote) === 'quoted', "the quote is not a plain paragraph:\n{$quote}");

    // And with the default style it is a quote, so the loss is the configuration
    // and not the reader.
    assertTrue(trim($back("> quoted\n")) === '> quoted', 'the default quote style is not read as a quote');

    // The last line carries no newline, whichever direction and line ending it is
    // read with — the one case the reader appends one.
    assertTrue($back("# Title\n") === '# Title', 'the last line gained a newline');
    assertTrue($back("# Title\n\nBody.\n") === "# Title\n\nBody.", 'only the first newline should be lost');

    (new MarkdownToWord("# Title\n"))->save($work . '/trailing.docx');

    assertTrue(
        (new WordToMarkdown($work . '/trailing.docx'))->convert() === '# Title',
        'reading the written file gave a trailing newline',
    );
    assertTrue(
        (new WordToMarkdown($work . '/trailing.docx', ReverseOptions::fromArray(['lineEnding' => "\r\n"])))->convert()
            === "# Title\r\n",
        'a crlf line ending did not append the terminator',
    );
});

$check('template', function () use ($work): void {
    $phpWord = new PhpWord();
    $phpWord->addFontStyle('ReportTitle', ['bold' => true, 'size' => 20], new Paragraph());
    $phpWord->addFontStyle('BodyText', ['size' => 11], new Paragraph());
    $section = $phpWord->addSection();
    $section->addText('Report for ${customer}');
    $section->addText('${body}');
    $section->addText('${slot}');
    $section->addText('${/body}');
    $section->addText('${rows}');
    $section->addText('${item}: ${price}');
    $section->addText('${/rows}');
    IOFactory::createWriter($phpWord, 'Word2007')->save($work . '/report-template.docx');

    file_put_contents($work . '/summary.md', "# Highlights\n\n- one\n- two\n");

    $config = Configuration::create()->withStyles([
        Styles::HEADING_1 => 'ReportTitle',
        Styles::PARAGRAPH => 'BodyText',
        Styles::BLOCK_QUOTE => 'PullQuote',
    ]);

    $template = new MarkdownTemplate($work . '/report-template.docx', $config, [
        'customer' => 'Northwind Ltd',
    ]);

    $template
        ->insert('body', (string) file_get_contents($work . '/summary.md'))
        ->repeat('rows', [
            ['item' => 'Licence', 'price' => '1,200 EUR'],
            ['item' => 'Support', 'price' => '300 EUR'],
        ])
        ->save($work . '/northwind.docx');

    $zip = new ZipArchive();
    $zip->open($work . '/northwind.docx');
    $xml = (string) $zip->getFromName('word/document.xml');
    $numbering = (string) $zip->getFromName('word/numbering.xml');
    $zip->close();

    assertTrue(!str_contains($xml, '${'), 'an unreplaced macro survived');
    assertTrue(str_contains($xml, 'Northwind Ltd'), 'the customer value is missing');
    assertTrue(str_contains($xml, 'ReportTitle'), 'the template heading style was not used');
    assertTrue(str_contains($xml, '1,200 EUR') && str_contains($xml, '300 EUR'), 'a row is missing');
    assertTrue(str_contains($numbering, 'w:numFmt w:val="bullet"'), 'the list lost its numbering');
});

$check('renderIntoContainer renders into a container you name', function () use ($work): void {
    // The same render as `toDocx()`, into a container rather than into a document.
    // Nothing is written here, which is the point: the caller writes the document
    // out, so the destination is the authority on its styles.
    $phpWord = new PhpWord();
    $converter = new MarkdownToWord(null, new Configuration());

    $header = $phpWord->addSection()->addHeader();
    $converter->renderIntoContainer("# A running head\n", $header, $phpWord);

    $output = $work . '/container.docx';
    IOFactory::createWriter($phpWord, 'Word2007')->save($output);

    $zip = new ZipArchive();
    $zip->open($output);
    $headerXml = (string) $zip->getFromName('word/header1.xml');
    $zip->close();

    assertTrue(str_contains($headerXml, 'A running head'), 'the header is empty');
});

$check('the template hands back PHPWord\'s own processor', function () use ($work): void {
    $template = new MarkdownTemplate($work . '/report-template.docx');

    assertTrue(
        $template->processor() instanceof TemplateProcessor,
        'processor() did not return a TemplateProcessor',
    );
});

$check('configuration from a chain', function (): void {
    $config = Configuration::create()
        ->withStyles([Styles::CODE_FONT => ['name' => 'Fira Code', 'size' => 10]])
        ->withOptions(['images' => Options::IMAGE_PLACEHOLDER]);

    assertTrue($config->getStyles()->get(Styles::CODE_FONT)['name'] === 'Fira Code', 'style not set');
    assertTrue($config->getOptions()->images === 'placeholder', 'option not set');
});

$check('configuration from an array', function () use ($work): void {
    $file = $work . '/config.php';
    file_put_contents($file, "<?php return ['styles' => ['heading.1' => 'Title']];");
    $config = Configuration::fromArray(require $file);

    assertTrue($config->getStyles()->get(Styles::HEADING_1) === 'Title', 'array config not applied');
});

$check('the built-in heading styles, and the rest of the built-in set', function (): void {
    $styles = (new Configuration())->withBuiltInHeadingStyles()->getStyles();

    // Not the defaults: the quote and list slots move to the built-in list styles,
    // which is the whole point of asking for this one.
    assertTrue($styles->get(Styles::HEADING_1) === 'Heading1', 'the heading is not the built-in one');
    assertTrue($styles->get(Styles::BLOCK_QUOTE) === 'Quote', 'the quote style is not Quote');
    assertTrue($styles->get(Styles::BULLET_LIST) === 'ListBullet', 'the bullet list is not ListBullet');
    assertTrue($styles->get(Styles::ORDERED_LIST) === 'ListNumber', 'the ordered list is not ListNumber');
});

$check('a configuration gives its array back', function (): void {
    $config = Configuration::create()->withOptions(['tableBorders' => false]);

    $array = $config->toArray();

    assertTrue(isset($array['styles'], $array['options']), 'the array is not in two parts');
    assertTrue($array['styles'][Styles::HEADING_1] === 'Heading1', 'the styles are not in it');
    assertTrue($array['options']['tableBorders'] === false, 'the options are not in it');

    assertTrue(
        Configuration::fromArray($array)->getOptions()->tableBorders === false,
        'the array did not survive Configuration::fromArray()',
    );
    assertTrue(
        $config->getStyles()->toArray() === $array['styles'],
        'Styles::toArray() says something else',
    );
    assertTrue(
        $config->getOptions()->toArray() === $array['options'],
        'Options::toArray() says something else',
    );
});

$check('the reader options survive the array they are written in', function (): void {
    // The reader's options are the ones a config file is written in, so what they
    // give back has to be what they take.
    $reader = ReverseOptions::fromArray(['mediaDirectory' => 'assets', 'headingSetext' => true]);

    assertTrue(
        $reader->toArray() === ReverseOptions::fromArray($reader->toArray())->toArray(),
        'the reader options do not survive fromArray()',
    );
    assertTrue($reader->mediaDirectory === 'assets', 'the media directory is not in them');
    assertTrue($reader->headingSetext === true, 'the setext flag is not in them');
});

$check('the style slots can be read and replaced one at a time', function (): void {
    $styles = new Styles();

    assertTrue($styles->toArray() === Styles::defaults(), 'a new instance is not the defaults');
    assertTrue($styles->heading(1) === 'Heading1', 'level 1 is not Heading1');
    assertTrue($styles->heading(9) === 'Heading6', 'a level past the sixth is not the sixth');

    $changed = $styles->with(Styles::HEADING_1, 'CorpTitle');

    assertTrue($changed->get(Styles::HEADING_1) === 'CorpTitle', 'the slot was not replaced');
    assertTrue($styles->get(Styles::HEADING_1) === 'Heading1', 'the original was changed');
});

$check('advanced: compose with PhpWord', function () use ($work): void {
    $phpWord = new PhpWord();
    $converter = new MarkdownToWord(null, new Configuration());

    $markdown = "# Chapter one\n\nBody.";

    $section = $phpWord->addSection();
    $section->addTitle('Annual Report', 1);

    $phpWord->getDocInfo()->setTitle('Annual Report');

    $document = $converter->toDocx($markdown, $phpWord);
    file_put_contents($work . '/report.docx', $document);

    assertTrue(is_file($work . '/report.docx'), 'no file written');

    // `toDocx()` renders into the document it is handed, so the example renders
    // once: an example that also called `renderIntoContainer()` with the same
    // section first would have the chapter in the document twice.
    $readBack = (new WordToMarkdown($document))->convert();

    assertTrue(str_contains($readBack, 'Annual Report'), 'the title went missing');
    assertTrue(substr_count($readBack, '# Chapter one') === 1, "the chapter is in the document twice:\n{$readBack}");
});

$check('text extractor', function (): void {
    $phpWord = (new MarkdownToWord())->toPhpWord("# Title\n\nBody with **bold**.");
    $text = TextExtractor::fromPhpWord($phpWord);

    assertTrue($text === "Title\nBody with bold.", "unexpected text: {$text}");
});

$check('the syntax tree is there for callers that want it', function (): void {
    $converter = new MarkdownToWord();

    $tree = $converter->parse("# Parsed\n\nBody with a [link](https://example.com).");

    assertTrue($tree instanceof League\CommonMark\Node\Block\Document, 'not a CommonMark document');

    $converter->toDocx("A [link](https://example.com) and a [**bold** one](https://example.test).");

    // Only the links PHPWord cannot express are left pending: a plain one becomes a
    // `Link` element, while a label carrying emphasis has to wait for the writer.
    $pending = $converter->pendingHyperlinks();
    $urls = array_column($pending, 'url');

    assertTrue($urls === ['https://example.test'], 'unexpected pending hyperlinks: ' . implode(', ', $urls));

    // The shape as well as the URLs. The sample on the page is a shape, and
    // checking only the URLs left nothing holding it up.
    assertTrue(
        array_keys($pending[0]) === ['placeholder', 'url', 'title', 'runs'],
        'the pending hyperlink has the wrong keys: ' . implode(', ', array_keys($pending[0])),
    );

    $token = $pending[0]['placeholder'];

    assertTrue(
        $token === LinkPlaceholder::forIndex(0)
            && str_starts_with($token, LinkPlaceholder::MARKER)
            && str_ends_with($token, LinkPlaceholder::MARKER)
            && substr_count($token, LinkPlaceholder::MARKER) === 2,
        'the placeholder is not the marker on both sides of the index: ' . json_encode($token),
    );

    assertTrue(
        $pending[0]['title'] === null,
        'the title is not null: ' . var_export($pending[0]['title'], true),
    );

    assertTrue(
        $pending[0]['runs'] !== [] && is_array($pending[0]['runs'][0]),
        'the runs are not there: ' . json_encode($pending[0]['runs']),
    );
});

$check('the reader hands back the block tree as well as the Markdown', function () use ($work): void {
    (new MarkdownToWord("# Read back\n\n- one\n- two\n"))->save($work . '/blocks.docx');

    $blocks = (new WordToMarkdown())->read($work . '/blocks.docx');

    assertTrue($blocks !== [], 'no blocks came back');
    assertTrue($blocks[0] instanceof MarkdownWord\Reverse\Block, 'not a block');
    assertTrue(
        str_contains((new WordToMarkdown($work . '/blocks.docx'))->convert(), '# Read back'),
        'the same reader produced no Markdown',
    );
});

$check('parser flavours', function (): void {
    foreach (['commonMarkOnly', 'extended', 'withAllExtensions'] as $flavour) {
        $converter = new MarkdownToWord(null, new Configuration(), CommonMarkParser::{$flavour}());
        $converter->toPhpWord("# Title\n\n- a\n- b\n");
    }
});

// The two rows of the supported-Markdown table that name a parser, and the mistake
// they had between them: task lists are GFM, and GFM is the default.
$check('the default parser: what needs extended() and what does not', function (): void {
    $back = static function (string $markdown, ?CommonMarkParser $parser = null): string {
        $document = (new MarkdownToWord($markdown, new Configuration(), $parser))->convert();

        return (new WordToMarkdown($document))->convert();
    };

    // A task list, through the default parser, unchanged in both directions — the
    // round trip's missing trailing newline and all, which is a loss of its own
    // and is listed as one on the page.
    $tasks = "- [ ] todo\n- [x] done\n";
    assertTrue(
        $back($tasks) === "- [ ] todo\n- [x] done",
        'a task list did not survive the default parser: ' . $back($tasks),
    );

    // What the document actually carries is the ballot box, not the bracket —
    // which is the half of the table row that says what happens in Word.
    $boxes = TextExtractor::fromPhpWord((new MarkdownToWord())->toPhpWord($tasks));

    assertTrue(
        $boxes === "\u{2610} todo\n\u{2612} done",
        'the task list markers are not the ballot boxes: ' . json_encode($boxes),
    );

    // A footnote is not GFM. Without the extension the marker survives as escaped
    // literal text, which is what makes the caveat on the page true for footnotes.
    $footnote = "Text[^1]\n\n[^1]: A note.\n";
    $plain = $back($footnote);

    assertTrue(
        str_contains($plain, '\[^1\]'),
        'a footnote was recognised without extended(): ' . json_encode($plain),
    );

    $extended = $back($footnote, CommonMarkParser::extended());

    assertTrue(
        !str_contains($extended, '[^1]'),
        'a footnote was not recognised by extended(): ' . json_encode($extended),
    );

    // A description list is not either.
    $description = "Term\n\n: Definition\n";

    assertTrue(str_contains($back($description), ': Definition'), 'a description list was recognised without extended()');
    assertTrue(
        trim($back($description, CommonMarkParser::extended())) === 'Definition',
        'a description list was not recognised by extended(): ' . $back($description, CommonMarkParser::extended()),
    );
});

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Compared instead of the archive: see `documentParts()` for what a clock changes.
 */
function self_documentBody(string $docx): string
{
    $zip = new ZipArchive();

    if ($zip->open($docx) !== true) {
        throw new RuntimeException('not a zip archive');
    }

    $body = (string) $zip->getFromName('word/document.xml');
    $zip->close();

    return $body;
}

/**
 * @param list<string> $argv
 * @return array{code: int, out: string, err: string}
 */
function self_cli(array $argv, string $stdin = ''): array
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

function self_redSquare(string $path): void
{
    $image = imagecreatetruecolor(16, 16);
    imagefilledrectangle($image, 0, 0, 16, 16, imagecolorallocate($image, 0x8B, 0x1A, 0x1A));
    imagepng($image, $path);
}

UpstreamDeprecations::restore();

self_removeTree($work);

echo $failures === 0
    ? "\nAll {$checks} examples work.\n"
    : "\n{$failures} of {$checks} example(s) failed.\n";

exit($failures === 0 ? 0 : 1);

/**
 * The whole tree, because an example is allowed to make a directory of its own —
 * the one that takes images out of a document does. Only this script's own
 * directory goes: the suite writes to `tmp/pest` beside it and must not lose it.
 */
function self_removeTree(string $directory): void
{
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . '/' . $entry;

        is_dir($path) ? self_removeTree($path) : @unlink($path);
    }

    @rmdir($directory);
}
