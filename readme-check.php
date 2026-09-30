<?php

declare(strict_types=1);

/**
 * Runs the examples from README.md so the documentation cannot drift from the
 * code. Not part of the test suite; run it with `php readme-check.php`.
 */

require __DIR__ . '/vendor/autoload.php';

use MarkdownWord\Console\UpstreamDeprecations;
use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Options;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Template\MarkdownTemplate;
use MarkdownWord\Text\TextExtractor;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Style\Paragraph;

// One dependency emits a deprecation for every list item it writes; see the class
// for why. Without this a run prints thousands of lines of somebody else's
// warning and buries anything real.
UpstreamDeprecations::install();


$work = __DIR__ . '/tmp/readme';
@mkdir($work, 0o777, true);

$failures = 0;

$check = static function (string $name, callable $body) use (&$failures): void {
    try {
        $body();
        echo "ok    {$name}\n";
    } catch (\Throwable $e) {
        $failures++;
        echo "FAIL  {$name}: {$e->getMessage()}\n";
    }
};

// The very first example.
$check('quick start', function () use ($work): void {
    $md = __DIR__ . '/tmp/readme/README.md';
    file_put_contents($md, "# Title\n\nBody.\n");
    (new MarkdownToWord($md))->save($work . '/quick.docx');
    assertTrue(is_file($work . '/quick.docx'), 'no file written');
});

// The template example, including a template that defines its own styles.
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

$check('advanced: compose with PhpWord', function () use ($work): void {
    $phpWord = new PhpWord();
    $converter = new MarkdownToWord(null, new Configuration());

    $section = $phpWord->addSection();
    $section->addTitle('Annual Report', 1);
    $converter->renderIntoContainer("# Chapter one\n\nBody.", $section, $phpWord);

    $phpWord->getDocInfo()->setTitle('Annual Report');
    file_put_contents(
        $work . '/report.docx',
        $converter->toDocx("# Chapter one\n\nBody.", $phpWord),
    );

    assertTrue(is_file($work . '/report.docx'), 'no file written');
});

$check('text extractor', function (): void {
    $phpWord = (new MarkdownToWord())->toPhpWord("# Title\n\nBody with **bold**.");
    $text = TextExtractor::fromPhpWord($phpWord);

    assertTrue($text === "Title\nBody with bold.", "unexpected text: {$text}");
});

$check('parser flavours', function (): void {
    foreach (['commonMarkOnly', 'extended', 'withAllExtensions'] as $flavour) {
        $converter = new MarkdownToWord(null, new Configuration(), CommonMarkParser::{$flavour}());
        $converter->toPhpWord("# Title\n\n- a\n- b\n");
    }
});

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

echo $failures === 0 ? "\nAll examples work.\n" : "\n{$failures} example(s) failed.\n";

exit($failures === 0 ? 0 : 1);
