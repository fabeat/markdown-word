<?php

declare(strict_types=1);

/**
 * Builds the example documents so they can be opened and inspected.
 *
 *   php examples/build.php
 *
 * Markdown sources live in examples/markdown, the generated .docx files in
 * examples/out. A matching .png of the first page is produced too, if
 * LibreOffice is installed, so the result can be checked without Word.
 */

require __DIR__ . '/../vendor/autoload.php';

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Options;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\WordToMarkdown;
use MarkdownWord\Template\MarkdownTemplate;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Style\Font;
use PhpOffice\PhpWord\Style\Paragraph;

$root = __DIR__;
$out = $root . '/out';
$assets = $root . '/markdown/assets';

@mkdir($out, 0o777, true);
@mkdir($assets, 0o777, true);

logo($assets . '/logo.png');

$built = [];

// --- The Markdown sources, rendered with the default configuration -----------

$default = new Configuration();

foreach ([
    '01-kitchen-sink' => 'Every construct on one page.',
    '02-tables' => 'GFM pipe tables and column alignment.',
    '03-lists' => 'Nesting, custom start values, tight and loose lists.',
    '04-code' => 'Fenced, indented and inline code.',
    '05-links-and-images' => 'Hyperlinks with formatting in the label, and images.',
    '06-quotes' => 'Block quotes, including nesting and other block content.',
] as $name => $description) {
    $markdown = (string) file_get_contents($root . '/markdown/' . $name . '.md');

    // Images in the examples are relative to the Markdown file.
    $config = Configuration::create()->withOptions([
        'images' => Options::IMAGE_EMBED,
        'imageBasePath' => $root . '/markdown',
    ]);

    $path = $out . '/' . $name . '.docx';
    (new MarkdownToWord($config))->save($markdown, $path);

    $built[] = [$name, $description, $path];
}

// --- The same source, without any decoration --------------------------------
//
// Shows what the configuration controls, side by side with the default.

$path = $out . '/07-no-decoration.docx';
(new MarkdownToWord(Configuration::create()->withoutDecoration()))
    ->save((string) file_get_contents($root . '/markdown/01-kitchen-sink.md'), $path);
$built[] = ['07-no-decoration', 'The kitchen sink with every default switched off.', $path];

// --- A house style, defined in code ------------------------------------------

$path = $out . '/08-house-style.docx';
(new MarkdownToWord(houseStyle()))->save(
    (string) file_get_contents($root . '/markdown/01-kitchen-sink.md'),
    $path,
);
$built[] = ['08-house-style', 'The kitchen sink in a custom house style.', $path];

// --- The same source, with the extras a README tends to use ------------------

$path = $out . '/09-extended-parser.docx';
(new MarkdownToWord(Configuration::create(), CommonMarkParser::extended()))
    ->save(
        "# With the extended parser\n\n"
        . "Footnotes[^1] and description lists are available here.\n\n"
        . "Term\n:   A word being defined\n"
        . ":   Another definition\n\n"
        . "[^1]: The note itself.\n",
        $path,
    );
$built[] = ['09-extended-parser', 'Footnotes and description lists.', $path];

// --- A Word template ---------------------------------------------------------

$templatePath = $out . '/template-invoice.docx';
$outputPath = $out . '/10-template.docx';

buildInvoiceTemplate($templatePath);

(new MarkdownTemplate($templatePath, houseStyle(), ['number' => 'INV-2024-0042', 'customer' => 'Northwind Ltd']))
    ->repeat('lines', [
        ['description' => 'Licence renewal', 'amount' => '1,200.00'],
        ['description' => 'Priority support', 'amount' => '300.00'],
        ['description' => 'On-site training', 'amount' => '900.00'],
    ])
    ->insert('terms', "## Terms\n\nPayment is due within **14 days**.\n\n- Bank transfer\n- Card\n\n"
        . "> Late payment attracts interest at 4% per year.\n")
    ->save($outputPath);

$built[] = ['10-template', 'Markdown rendered into a Word template.', $outputPath];

// --- The way back ------------------------------------------------------------

$reverse = new WordToMarkdown();

$roundTripped = $reverse->convert($out . '/01-kitchen-sink.docx');
file_put_contents($out . '/11-round-trip.md', $roundTripped);

$built[] = [
    '11-round-trip',
    'The kitchen sink read back out of 01-kitchen-sink.docx.',
    $out . '/11-round-trip.md',
];

// --- Report ------------------------------------------------------------------

echo "\nBuilt:\n\n";

foreach ($built as [$name, $description, $path]) {
    printf("  %-22s %s\n", basename($path), $description);
}

echo "\n";

renderPreviews($built);

/**
 * A configuration that looks like a printed report rather than a Word default.
 */
function houseStyle(): Configuration
{
    return Configuration::create()
        ->withStyles([
            Styles::HEADING_1 => ['size' => 20, 'bold' => true, 'color' => '1B3A5C', 'space' => ['before' => 0, 'after' => 240]],
            Styles::HEADING_2 => ['size' => 15, 'bold' => true, 'color' => '1B3A5C', 'space' => ['before' => 280, 'after' => 120]],
            Styles::HEADING_3 => ['size' => 12, 'bold' => true, 'color' => '2E5F86', 'space' => ['before' => 200, 'after' => 80]],
            Styles::BLOCK_QUOTE => ['italic' => true, 'color' => '55606B', 'indentation' => ['left' => 567, 'right' => 567]],
            Styles::CODE_FONT => ['name' => 'Consolas', 'size' => 9, 'color' => '1F3864'],
            Styles::LINK_FONT => ['color' => '1B5E9B', 'underline' => 'single'],
            Styles::TABLE_CELL => ['size' => 10],
        ])
        ->withOptions([
            'codeBlockShading' => true,
            'tableBorders' => true,
            'orderedListFormat' => 'decimal',
        ]);
}

/**
 * A template with the placeholder regions the renderer expects. The paragraph
 * styles are defined here the way a real template would define them, so the
 * example shows a template driving the output rather than the other way round.
 */
function buildInvoiceTemplate(string $path): void
{
    $phpWord = new PhpWord();

    $phpWord->addFontStyle('InvoiceTitle', ['bold' => true, 'size' => 24, 'color' => '1B3A5C'], new Paragraph());
    $phpWord->addFontStyle('InvoiceMeta', ['size' => 10, 'color' => '55606B'], new Paragraph());
    $phpWord->addFontStyle('InvoiceBody', ['size' => 11], new Paragraph());

    $section = $phpWord->addSection();

    // Note the third argument: PHPWord's second parameter of addText() is a
    // *character* style, and a paragraph style has to go in the third. Getting
    // this wrong writes a `w:rStyle` reference to a paragraph style, which Word
    // silently ignores.
    $section->addText('INVOICE', null, 'InvoiceTitle');
    $section->addText('No. ${number}   ·   Issued for ${customer}', null, 'InvoiceMeta');

    // A repeating region: the renderer clones it once per row and adds the
    // `#1`, `#2`, ... index to the macros inside, so the template itself holds
    // the plain names.
    $section->addText('${lines}');
    $section->addText('${description} — ${amount}');
    $section->addText('${/lines}');

    // A Markdown region: the renderer clones it once per rendered block and
    // replaces the `${slot}` paragraph in each copy.
    $section->addText('${terms}');
    $section->addText('${slot}');
    $section->addText('${/terms}');

    IOFactory::createWriter($phpWord, 'Word2007')->save($path);
}

function logo(string $path): void
{
    if (is_file($path)) {
        return;
    }

    $size = 96;
    $image = imagecreatetruecolor($size, $size);

    $background = imagecolorallocate($image, 0x8B, 0x1A, 0x1A);
    $ink = imagecolorallocate($image, 0xFF, 0xF3, 0xF3);

    imagefilledrectangle($image, 0, 0, $size, $size, $background);
    imagerectangle($image, 4, 4, $size - 5, $size - 5, $ink);
    imagefilledellipse($image, $size / 2, $size / 2, 44, 44, $ink);

    imagepng($image, $path);
}

/**
 * Render the first page of each document so the result can be eyeballed.
 */
function renderPreviews(array $built): void
{
    $soffice = locateLibreOffice();

    if ($soffice === null) {
        echo "LibreOffice was not found, so no preview images were produced.\n";

        return;
    }

    $rendered = 0;

    foreach ($built as [, , $path]) {
        // Only a document has a first page to look at; the round-trip example is
        // written as Markdown.
        if (!str_ends_with($path, '.docx')) {
            continue;
        }

        $command = sprintf(
            '%s --headless --convert-to png --outdir %s %s 2>/dev/null',
            escapeshellcmd($soffice),
            escapeshellarg(dirname($path)),
            escapeshellarg($path),
        );

        exec($command, $output, $status);

        if ($status === 0 && is_file(preg_replace('/\.docx$/', '.png', $path))) {
            $rendered++;
        }

        $output = [];
    }

    printf("Rendered %d preview image(s) next to the documents.\n", $rendered);
}

function locateLibreOffice(): ?string
{
    $candidates = [
        '/Applications/LibreOffice.app/Contents/MacOS/soffice',
        '/usr/bin/soffice',
        '/usr/bin/libreoffice',
        '/usr/lib/libreoffice/program/soffice',
    ];

    foreach ($candidates as $candidate) {
        if (is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
}
