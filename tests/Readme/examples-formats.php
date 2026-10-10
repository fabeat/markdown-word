<?php

declare(strict_types=1);

use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Options;
use MarkdownWord\Console\Application;
use MarkdownWord\Format;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;

/*
 * The capability table in the README, checked against the files it describes.
 *
 * A table of what each format can carry is the easiest claim in this repository to
 * make wrong, because nothing about writing a `.docx` checks it: every row was
 * measured by writing the same document through each of PHPWord's three writers and
 * reading the result back, and two of the first measurements were wrong — the ODT
 * heading and the ODT block quote looked carried because the style was in the file,
 * and a rendered page says neither is. So every row here is asserted against the
 * document itself, and the two that are degradations rather than losses say which
 * half is missing.
 *
 * The example the table is about is examples/markdown/20-formats.md, which
 * examples/build.php writes as the three documents beside it.
 */

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

const FORMATS_SOURCE = __DIR__ . '/../../examples/markdown/20-formats.md';

/**
 * The example page as one format, and the converter that produced it.
 *
 * @return array{0: MarkdownToWord, 1: string}
 */
function readmeFormat(Format $format): array
{
    $markdown = (string) file_get_contents(FORMATS_SOURCE);

    $converter = new MarkdownToWord(
        $markdown,
        Configuration::create()->withOptions([
            'images' => Options::IMAGE_EMBED,
            'imageBasePath' => dirname(__DIR__, 2) . '/examples/markdown',
        ]),
        new CommonMarkParser([...CommonMarkParser::FLAVOURS['gfm'], FrontMatterExtension::class]),
    );

    $path = Scratch::path('readme-formats', $format->extension());
    $converter->convertTo($format, $path);

    return [$converter, $path];
}

/**
 * One part of a document, which is where a claim about a `.docx` or an `.odt` has
 * to be read from: both are zipped, and a search of the archive's own bytes finds
 * nothing at all.
 */
function readmePart(string $archive, string $part): string
{
    $zip = new ZipArchive();
    $zip->open($archive);
    $contents = (string) $zip->getFromName($part);
    $zip->close();

    return $contents;
}

/**
 * Everything a reader of the document is shown: the body for a zipped format, the
 * whole file for an RTF, which is not one.
 *
 * For a `.docx` the relationships come with it, because that is where a hyperlink's
 * address is written — `w:hyperlink` holds an identifier and the target lives in
 * `word/_rels/document.xml.rels`, so a document read on its own does not contain a
 * single URL.
 */
function readmeBody(string $archive): string
{
    if (str_ends_with($archive, '.rtf')) {
        return (string) file_get_contents($archive);
    }

    return str_ends_with($archive, '.odt')
        ? readmePart($archive, 'content.xml')
        : readmePart($archive, 'word/document.xml') . readmePart($archive, 'word/_rels/document.xml.rels');
}

/** What the run reported as lost. */
function readmeLosses(MarkdownToWord $converter): array
{
    return array_column($converter->pendingLosses(), 'feature');
}

// The example itself

it('names the three documents the example page produces', function () {
    $source = (string) file_get_contents(FORMATS_SOURCE);

    expect($source)->toContain('20-formats.docx')
        ->and($source)->toContain('20-formats.odt')
        ->and($source)->toContain('20-formats.rtf');
});

it('builds all three of them, from the one page', function (Format $format) {
    [, $path] = readmeFormat($format);

    expect(is_file($path))->toBeTrue()
        // And each is the document it says it is, rather than three copies of one.
        ->and(filesize($path))->toBeGreaterThan(0);
})->with(Format::cases());

// The rows of the table

it('carries bold, italics and strikethrough in all three', function (Format $format) {
    [, $path] = readmeFormat($format);
    $body = readmeBody($path);

    expect($body)->toContain(match ($format) {
        Format::Docx => '<w:b ',
        Format::Odt => 'fo:font-weight="bold"',
        Format::Rtf => '\b',
    })->toContain(match ($format) {
        Format::Docx => '<w:i ',
        Format::Odt => 'fo:font-style="italic"',
        Format::Rtf => '\i',
    })->toContain(match ($format) {
        Format::Docx => '<w:strike',
        Format::Odt => 'text-line-through-type="single"',
        Format::Rtf => '\strike',
    });
})->with(Format::cases());

it('carries a link in all three, including one whose label is emphasised', function (Format $format) {
    [, $path] = readmeFormat($format);
    $body = readmeBody($path);

    expect($body)->toContain('https://example.com/b')
        // The placeholder is what a writer leaves behind when nothing puts the link
        // back, and in the finished document it is visible text.
        ->and($body)->not->toContain('MDWL');
})->with(Format::cases());

it('carries the alternative text of a picture in two of the three', function (Format $format, string $marker) {
    [$converter, $path] = readmeFormat($format);
    $body = readmeBody($path);

    // An RTF is the one with nothing to find, so the row is stated the other way
    // round: the marker is there for the two that carry it and absent for the one
    // that does not, which is a different assertion from "the marker is there".
    if ($marker === '') {
        expect($body)->not->toContain('picprop')
            ->and(readmeLosses($converter))->toContain('image-alt-text');

        return;
    }

    expect($body)->toContain($marker)
        ->and(readmeLosses($converter))->not->toContain('image-alt-text');
})->with([
    'docx' => [Format::Docx, 'o:title="The house mark"'],
    'odt' => [Format::Odt, 'svg:desc="The house mark"'],
    'rtf' => [Format::Rtf, ''],
]);

it('leaves the list out of an .rtf altogether', function () {
    [$converter, $path] = readmeFormat(Format::Rtf);

    expect(readmeBody($path))->not->toContain('A bullet')
        ->and(readmeLosses($converter))->toContain('lists');
});

it('writes an ordered list as bullets in an .odt', function () {
    [$converter, $path] = readmeFormat(Format::Odt);

    expect(readmePart($path, 'styles.xml'))->toContain('text:bullet-char="%1."')
        ->and(readmeLosses($converter))->toContain('numbered-lists');
});

it('keeps a bullet list, nested, in an .odt', function () {
    [, $path] = readmeFormat(Format::Odt);

    expect(readmePart($path, 'content.xml'))
        ->toMatch('~<text:list-item><text:list[^>]*><text:list-item><text:list[^>]*>~');
});

it('keeps the borders of a table in two of the three', function (Format $format, string $marker) {
    [$converter, $path] = readmeFormat($format);

    expect(readmeBody($path))->toContain($marker)
        ->and(in_array('table-borders', readmeLosses($converter), true))->toBe($marker === 'table:table');
})->with([
    'docx' => [Format::Docx, 'w:tblBorders'],
    'odt' => [Format::Odt, 'table:table'],
    'rtf' => [Format::Rtf, '\clbrdrt'],
]);

it('drops the rule under a thematic break in the two that are not a .docx', function (Format $format, string $marker) {
    [$converter, $path] = readmeFormat($format);

    expect(readmeBody($path))->toContain($marker)
        ->and(in_array('paragraph-border', readmeLosses($converter), true))->toBe($format !== Format::Docx);
})->with([
    'docx' => [Format::Docx, 'w:pBdr'],
    'odt' => [Format::Odt, 'text:p'],
    'rtf' => [Format::Rtf, '\\pard'],
]);

it('drops the background of a code block in the two that are not a .docx', function (Format $format, string $marker) {
    [$converter, $path] = readmeFormat($format);

    expect(readmeBody($path))->toContain($marker)
        ->and(in_array('shading', readmeLosses($converter), true))->toBe($format !== Format::Docx);
})->with([
    'docx' => [Format::Docx, 'w:shd'],
    'odt' => [Format::Odt, 'text:p'],
    'rtf' => [Format::Rtf, '\\pard'],
]);

it('keeps the spacing of a named style in an .odt and none of its character half', function () {
    [$converter, $path] = readmeFormat(Format::Odt);
    $content = readmePart($path, 'content.xml');

    preg_match('~<text:p text:style-name="[^"]*Heading1">(.*?)</text:p>~s', $content, $heading);
    $text = $heading[1] ?? '';

    // Half a claim, asserted as half. The style is in the file and the paragraph
    // references it — and nothing inside the heading sizes, weights or colours the
    // text, which is what a reader sees as body text with air above it. The
    // assertion is on what is absent, because an empty `style:text-properties` is
    // written by one serialiser and dropped by the other.
    expect($content)->toContain('style:parent-style-name="Heading1"')
        ->and(readmePart($path, 'styles.xml'))
        ->toMatch('~<style:style [^>]*style:name="Heading1"[^>]*style:family="text"[^>]*>\s*<style:text-properties[^>]*fo:font-size~')
        ->and($text)->toContain('One document, three formats')
        ->and(readmeLosses($converter))->toContain('named-styles');

    expect($text)->not->toContain('fo:font-size')
        ->and($text)->not->toContain('fo:font-weight')
        ->and($text)->not->toContain('fo:color');
});

it('writes a heading as body text in an .rtf', function () {
    [$converter, $path] = readmeFormat(Format::Rtf);

    // No `\s<N>` reference at all: the RTF writer has no stylesheet for a named
    // style to be referenced from.
    expect(readmeBody($path))->not->toMatch('~\\\\s\d~')
        ->and(readmeLosses($converter))->toContain('named-styles');
});

it('drops a typeface and a run colour in an .rtf and keeps them elsewhere', function (Format $format) {
    [$converter, $path] = readmeFormat($format);
    $body = readmeBody($path);

    // The example page has inline code in it, which is the one place a colour and
    // a typeface are asked for without a style being involved. `toContain()` takes
    // needles and nothing else, so the two directions are two statements.
    expect(in_array('font-face', readmeLosses($converter), true))->toBe($format === Format::Rtf);

    if ($format === Format::Rtf) {
        expect($body)->not->toContain('A31515');
    } else {
        expect($body)->toContain('A31515');
    }
})->with(Format::cases());

it('refuses a template for the two formats that cannot use one', function (Format $format, bool $refused) {
    $input = Scratch::path('templated.md');
    file_put_contents($input, "# Notes\n");

    // A real template rather than any `.docx`: a document without a `${body}`
    // region is refused for being the wrong shape, which would be true of the
    // `.docx` row too and say nothing about the format.
    $run = runCli([
        'to-' . $format->value,
        $input,
        '-t',
        TemplateFactory::placeholder(),
        '-o',
        Scratch::path('out', $format->extension()),
    ]);

    expect($run['code'])->toBe($refused ? Application::FAILURE : Application::SUCCESS)
        ->and($run['err'])->toContain($refused ? 'A template cannot be filled in' : ' → ');
})->with([
    'docx' => [Format::Docx, false],
    'odt' => [Format::Odt, true],
    'rtf' => [Format::Rtf, true],
]);