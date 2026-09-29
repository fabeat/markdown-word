<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use MarkdownWord\Console\UpstreamDeprecations;
use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Options;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Template\MarkdownTemplate;
use MarkdownWord\Text\TextExtractor;
use PhpOffice\PhpWord\PhpWord;

// One dependency emits a deprecation for every list item it writes; see the class
// for why. Without this a run prints thousands of lines of somebody else's
// warning and buries anything real.
UpstreamDeprecations::install();


$work = __DIR__ . '/tmp/stress';
@mkdir($work, 0o777, true);

// Deliberately awkward input: malformed nesting, unbalanced delimiters, very
// deep structures, stray control characters and enormous documents.
$cases = [
    'empty' => '',
    'whitespace only' => "   \n\t\n  \n",
    'unterminated emphasis' => '*unclosed',
    'unterminated code' => "```\nno closing fence\n",
    'unterminated link' => '[text](unclosed',
    'unterminated html' => '<div><span>unclosed',
    'deep emphasis' => str_repeat('*', 40) . 'x' . str_repeat('*', 40),
    'deep quotes' => str_repeat('> ', 20) . 'deep',
    'deep lists' => implode("\n", array_map(
        static fn (int $i): string => str_repeat(' ', $i * 2) . '- item ' . $i,
        range(0, 12),
    )),
    // Marks that introduce no content: the result is legitimately empty.
    'lone markers' => null,
    'only entities' => '&amp;&lt;&gt;&#65;&#x42;',
    'null bytes' => "before\x00after",
    'control chars' => "bell\x07 and vertical\x0B tab",
    'very long line' => str_repeat('word ', 5000),
    'many headings' => implode("\n\n", array_map(
        static fn (int $i): string => '#' . (($i % 6) + 1) . " Heading {$i}",
        range(1, 200),
    )),
    'wide table' => self_table(60, 8),
    'crlf' => "# Title\r\n\r\nBody\r\n",
    'bom' => "\xEF\xBB\xBF# Title\n",
    'html table' => "<table><tr><td>a</td></tr></table>\n",
    'image missing' => '![alt text](nope.png)',
    'image remote' => '![alt](https://example.com/a.png)',
    'link in code' => '`[not a link](x)`',
    'autolink email' => '<mailto:a@b.com>',
    'mixed everything' => self_mixed(),
];

function self_table(int $rows, int $cols): string
{
    $line = fn (int $c): string => '| c' . $c . ' ';

    $out = $line(0);
    for ($c = 1; $c < $cols; $c++) {
        $out .= str_repeat('-', 3) . '|';
    }
    $out = substr($out, 0, -1) . "\n" . $line(0);
    for ($c = 1; $c < $cols; $c++) {
        $out .= '---|';
    }
    $out = rtrim($out, '|') . "\n";

    for ($r = 0; $r < $rows; $r++) {
        $out .= '|';
        for ($c = 0; $c < $cols; $c++) {
            $out .= ' v' . $r . '-' . $c . ' |';
        }
        $out .= "\n";
    }

    return $out;
}

function self_mixed(): string
{
    return <<<'MD'
        # Title with `code` and **bold**

        > Quote with a [link](https://example.com) and a list:
        >
        > - one
        > - two

        1. First
           1. Nested
              - Deeper

        | a | b |
        | - | - |
        | 1 | 2 |

        ```js
        const x = { nested: { deeply: true } };
        ```

        ![img](a.png "title")

        <div class="x">raw <b>html</b></div>

        Term[^1]

        [^1]: The note.

        ---

        [ref]: https://example.com/ref "Ref title"
        MD;
}

$configurations = [
    'default' => new Configuration(),
    'no decoration' => Configuration::create()->withoutDecoration(),
    'CommonMark only' => null,
    'every extension' => null,
    'placeholder images' => Configuration::create()->withOptions(['images' => Options::IMAGE_PLACEHOLDER]),
    'soft break as paragraph' => Configuration::create()->withOptions(['softBreak' => Options::SOFT_BREAK_PARAGRAPH]),
    'html preserved' => Configuration::create()->withOptions(['html' => Options::HTML_PRESERVE]),
    'max heading 2' => Configuration::create()->withOptions(['maxHeadingLevel' => 2]),
    'roman lists' => Configuration::create()->withOptions(['orderedListFormat' => 'lowerRoman']),
];

$parsers = [
    'CommonMark only' => CommonMarkParser::commonMarkOnly(),
    'every extension' => CommonMarkParser::withAllExtensions(),
];

$failures = 0;
$checks = 0;

foreach ($configurations as $configName => $config) {
    $parser = $parsers[$configName] ?? null;

    foreach ($cases as $caseName => $markdown) {
        if ($markdown === null) {
            continue;
        }

        $checks++;

        try {
            $converter = new MarkdownToWord(null, $config ?? new Configuration(), $parser);
            $phpWord = $converter->toPhpWord($markdown);

            $path = $work . '/out.docx';
            file_put_contents($path, $converter->toDocx($markdown));

            if (!is_file($path) || filesize($path) < 100) {
                throw new RuntimeException('the document is empty');
            }

            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('the output is not a zip archive');
            }

            $document = (string) $zip->getFromName('word/document.xml');
            $zip->close();

            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            $valid = $dom->loadXML($document, LIBXML_NOCDATA);
            libxml_clear_errors();

            if (!$valid) {
                throw new RuntimeException('word/document.xml is not valid XML');
            }

            if (str_contains($document, 'MDWL')) {
                throw new RuntimeException('a hyperlink placeholder leaked into the document');
            }

            // The text must be extractable; a null case is one that legitimately
            // produces no content, such as a heading with no heading text.
            $text = TextExtractor::fromPhpWord($phpWord, TextExtractor::LINE_BREAK, $converter->pendingHyperlinks());

            if ($text === '' && $markdown !== null && trim($markdown) !== '') {
                throw new RuntimeException('text was lost');
            }
        } catch (\Throwable $e) {
            $failures++;
            echo "FAIL  [{$configName}] {$caseName}: {$e->getMessage()}\n";
        }
    }
}

// The template path has to survive the same treatment.
foreach ($cases as $caseName => $markdown) {
    if ($markdown === null) {
        continue;
    }

    $checks++;

    try {
        $template = new PhpWord();
        $section = $template->addSection();
        $section->addText('${body}');
        $section->addText('${slot}');
        $section->addText('${/body}');
        \PhpOffice\PhpWord\IOFactory::createWriter($template, 'Word2007')->save($work . '/tpl.docx');

        $processor = new MarkdownTemplate($work . '/tpl.docx');
        $processor->insert('body', $markdown);
        $processor->save($work . '/tpl-out.docx');

        $zip = new ZipArchive();
        $zip->open($work . '/tpl-out.docx');
        $document = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $valid = $dom->loadXML($document, LIBXML_NOCDATA);
        libxml_clear_errors();

        if (!$valid) {
            throw new RuntimeException('the template output is not valid XML');
        }

        if (str_contains($document, '${')) {
            throw new RuntimeException('an unreplaced macro survived');
        }

        if (str_contains($document, 'MDWL')) {
            throw new RuntimeException('a hyperlink placeholder leaked into the template');
        }
    } catch (\Throwable $e) {
        $failures++;
        echo "FAIL  [template] {$caseName}: {$e->getMessage()}\n";
    }
}

echo "\n{$checks} checks, {$failures} failure(s)\n";

exit($failures === 0 ? 0 : 1);
