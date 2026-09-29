<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Options;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;

/*
 * Writing a document reaches the one known upstream deprecation, described in
 * tests/Support/Upstream.
 */
beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

const MARKDOWN = <<<'MD'
        # Quarterly Report

        Revenue **grew** by 12% — see [the details][d] and `quarterly_total`.

        ## Highlights

        - Shipped the new importer
        - Cut latency in half
          - Both read and write paths

        > Profitability improved.
        > Margin is now 18%.

        ```php
        $total = array_sum($rows);
        ```

        | Region | Revenue |
        |:-------|--------:|
        | EMEA   |     120 |
        | AMER   |     340 |

        ---

        Final notes with ~~outdated~~ figures.

        [d]: https://example.com/details
        MD;


it('the output is a valid zip archive', function () {
    $zip = new ZipArchive();

    expect($zip->open(reportDocument(), ZipArchive::RDONLY))->toBe(true);
    expect($zip->locateName('word/document.xml'))->not->toBeFalse();
    expect($zip->locateName('[Content_Types].xml'))->not->toBeFalse();
    expect($zip->locateName('word/styles.xml'))->not->toBeFalse();
    $zip->close();
});

it('every part is well formed xml', function () {
    $zip = new ZipArchive();
    $zip->open(reportDocument(), ZipArchive::RDONLY);

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);

        if (!str_ends_with($name, '.xml') && !str_ends_with($name, '.rels')) {
            continue;
        }

        $contents = (string) $zip->getFromIndex($i);
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($contents, LIBXML_NOCDATA);
        libxml_clear_errors();

        expect($loaded)->toBeTrue();
    }

    $zip->close();
});

it('headings become word heading styles', function () {
    $xml = TemplateFactory::xmlOf(reportDocument());

    expect($xml)->toContain('w:val="Heading1"');
    expect($xml)->toContain('w:val="Heading2"');
});

it('headings reference word built in style ids', function () {
    $xml = TemplateFactory::xmlOf(reportDocument());

    // Word resolves a `w:pStyle` against a styleId, not the display name, and
    // only the built-in ids carry an outline level. Referring to "Heading 1"
    // would leave the text unstyled and out of the navigation pane.
    expect($xml)->toContain('<w:pStyle w:val="Heading1"/>');
    expect($xml)->toContain('<w:pStyle w:val="Heading2"/>');
    expect($xml)->not->toContain('w:val="Heading 1"');
});

it('lists produce numbering definitions', function () {
    $xml = TemplateFactory::xmlOf(reportDocument(), 'word/numbering.xml');

    expect($xml)->toContain('w:numFmt w:val="bullet"');
    expect($xml)->toContain('w:abstractNum');
});

it('tables are written', function () {
    $file = Scratch::path('out');
    saveMarkdown("| a | b |\n| --- | --- |\n| 1 | 2 |", $file);

    $xml = TemplateFactory::xmlOf($file);

    expect(substr_count($xml, '<w:tbl>'))->toBe(1);
    expect(substr_count($xml, '<w:tr>'))->toBe(2);
    expect(substr_count($xml, '<w:tc>'))->toBe(4);
});

it('tables span the full text column', function () {
    $file = Scratch::path('out');
    saveMarkdown("| a | b |\n| --- | --- |\n| 1 | 2 |", $file);

    $xml = TemplateFactory::xmlOf($file);

    // PHPWord's own default is `w:w="0" w:type="auto"`, and a zero width
    // makes every viewer shrink the table to its narrowest content instead of
    // filling the text column. A percentage follows the page size and the
    // margins, which is what makes the table look like a table.
    expect($xml)->toContain('<w:tblW w:w="5000" w:type="pct"/>');
    expect($xml)->not->toContain('<w:tblW w:w="0"');
});

it('the table width is configurable', function () {
    $file = Scratch::path('out');

    $config = Configuration::create()->withOptions(['tableWidth' => 2500]);
    saveDocument("| a |\n| --- |\n| 1 |", $file, $config);

    expect(TemplateFactory::xmlOf($file))->toContain('<w:tblW w:w="2500" w:type="pct"/>');
});

it('a zero table width leaves the sizing to word', function () {
    $file = Scratch::path('out');

    $config = Configuration::create()->withOptions(['tableWidth' => 0]);
    saveDocument("| a |\n| --- |\n| 1 |", $file, $config);

    $xml = TemplateFactory::xmlOf($file);

    expect($xml)->not->toContain('w:type="pct"');
    expect($xml)->toContain('<w:tblW w:w="0" w:type="auto"/>');
});

it('a custom table style keeps the full width', function () {
    $file = Scratch::path('out');

    // Styling a table must not silently make it narrow again.
    $config = Configuration::create()->withStyles([
        \MarkdownWord\Configuration\Styles::TABLE => ['layout' => 'fixed'],
    ]);
    saveDocument("| a |\n| --- |\n| 1 |", $file, $config);

    $xml = TemplateFactory::xmlOf($file);

    expect($xml)->toContain('<w:tblW w:w="5000" w:type="pct"/>');
    expect($xml)->toContain('<w:tblLayout w:type="fixed"/>');
});

it('code blocks keep their whitespace', function () {
    $text = TemplateFactory::textOf(reportDocument());

    expect($text)->toContain('$total = array_sum($rows);');
});

it('every construct survives the round trip', function () {
    $text = TemplateFactory::textOf(reportDocument());

    foreach ([
        'Quarterly Report',
        'Revenue grew by 12%',
        'Shipped the new importer',
        'Both read and write paths',
        'Profitability improved.',
        '$total = array_sum($rows);',
        'EMEA',
        'Final notes with outdated figures.',
    ] as $expected) {
        expect($text)->toContain($expected);
    }
});

it('links become real hyperlinks', function () {
    $output = reportDocument();

    expect(TemplateFactory::hyperlinkCount($output))->toBe(1);
    expect(TemplateFactory::targetsOf($output))->toContain('https://example.com/details');
});

it('the document can be written to a string', function () {
    $bytes = (new MarkdownToWord())->toDocx('# Title');

    expect($bytes)->toStartWith('PK');
});

it('decoding is not disabled by the renderer', function () {
    // Output escaping must stay on, or a literal `&` in the Markdown would be
    // written as a raw ampersand and corrupt the XML.
    $xml = TemplateFactory::xmlOf(reportDocument());
    $dom = new \DOMDocument();
    $dom->loadXML($xml, LIBXML_NOCDATA);

    $xpath = new \DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    $text = '';
    foreach ($xpath->query('//w:t') ?: [] as $node) {
        $text .= $node->textContent;
    }

    expect($text)->toContain('grew by 12% — see');
});

it('embeds images from disk', function () {
    Scratch::image('logo.png');

    $config = Configuration::create()->withOptions([
        'images' => Options::IMAGE_EMBED,
        'imageBasePath' => Scratch::directory(),
    ]);

    $file = Scratch::path('out');
    saveDocument('![Logo](logo.png)', $file, $config);

    $zip = new ZipArchive();
    $zip->open($file, ZipArchive::RDONLY);

    $media = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (str_starts_with($name, 'word/media/')) {
            $media[] = $name;
        }
    }
    $zip->close();

    expect($media)->not->toBe([]);
    expect(TemplateFactory::xmlOf($file))->toContain('imagedata');
});

it('an image that cannot be read falls back to its alt text', function () {
    $file = Scratch::path('out');
    saveDocument('![The logo](not-there.png)', $file, Configuration::create()->withOptions([
        'images' => Options::IMAGE_EMBED,
        'imageBasePath' => Scratch::directory(),
    ]));

    expect(TemplateFactory::textOf($file))->toContain('The logo');
});

/**
 * The report used throughout this file, written out and returned.
 */
function reportDocument(): string
{
    $file = Scratch::path('out');
    saveMarkdown(MARKDOWN . "\n", $file);

    return $file;
}
