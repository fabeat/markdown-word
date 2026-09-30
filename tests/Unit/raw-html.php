<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Options;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Tests\Support\Scratch;
use PhpOffice\PhpWord\Element\TextBreak;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;

/*
 * Raw HTML in the Markdown source.
 *
 * Two separate things are checked here, and they are worth keeping apart:
 *
 *  - what raw HTML *becomes*, which is more than its disappearance: elements
 *    that correspond to something in Word are rebuilt as that, and
 *  - that no HTML, however hostile, can put anything of its own into the
 *    document's XML. That is the claim the README makes, so it is tested here
 *    rather than asserted in prose.
 *
 * Writing a document reaches the one known upstream deprecation described in
 * tests/Support/Upstream.
 */
beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/**
 * @param array<string, mixed> $options
 * @return array{xml: string, text: string, docx: string}
 */
function renderHtml(string $markdown, array $options = []): array
{
    $file = Scratch::path('html');

    saveDocument($markdown, $file, Configuration::create()->withOptions($options));

    return [
        'xml' => TemplateFactory::xmlOf($file),
        'text' => TemplateFactory::textOf($file),
        'docx' => $file,
    ];
}

/**
 * Whether a document's XML parses. A file that does not is one Word refuses to
 * open, which is the failure this whole area is about.
 */
function isValidDocx(string $docx): bool
{
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);

    $valid = $dom->loadXML(TemplateFactory::xmlOf($docx), LIBXML_NOCDATA);

    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return $valid;
}

// What it does

it('keeps the text an element wraps and drops the element', function () {
    expect(renderText("Some <b>bold</b> text\n"))->toBe('Some bold text');
    expect(renderText("<div>a block</div>\n"))->toContain('a block');
    expect(renderText("An <custom-element>unfamiliar one</custom-element>\n"))
        ->toBe('An unfamiliar one');
});

it('turns an element that wraps its own text into the thing it means', function () {
    // A block of HTML arrives as one node, so the element is an ancestor of the
    // text and the formatting can be applied to it.
    $bold = renderRuns(renderElements("<div><b>foo</b> bar</div>\n")[0]);
    $italic = renderRuns(renderElements("<div><i>x</i></div>\n")[0]);
    $struck = renderRuns(renderElements("<div><s>gone</s></div>\n")[0]);

    expect($bold[0]['font']['bold'] ?? false)->toBeTrue();
    expect($italic[0]['font']['italic'] ?? false)->toBeTrue();
    expect($struck[0]['font']['strike'] ?? false)->toBeTrue();
});

it('maps the same elements Markdown emphasis uses', function () {
    foreach (['strong' => 'bold', 'em' => 'italic', 'del' => 'strike'] as $tag => $property) {
        $runs = renderRuns(renderElements(sprintf("<div><%s>x</%s></div>\n", $tag, $tag))[0]);

        expect($runs[0]['font'][$property] ?? false)->toBeTrue();
    }
});

it('cannot style text that sits outside the element', function () {
    // CommonMark's tree makes `a <b>bold</b> b` five sibling nodes — text, tag,
    // text, tag, text — rather than a `b` element wrapping a word. Each tag is
    // parsed on its own, so there is no scope reaching the word between them and
    // the text survives without the bold.
    //
    // Worth pinning down because it is a property of the syntax tree rather than
    // a choice, and the natural expectation is the opposite one.
    $runs = renderRuns(renderElements("a <b>bold</b> b\n")[0]);

    expect(renderText("a <b>bold</b> b\n"))->toBe('a bold b');
    expect($runs[1]['font']['bold'] ?? false)->toBeFalse();
    expect($runs[1]['text'])->toBe('bold');
});

it('turns a br into a line break', function () {
    $children = renderElements("one<br>two\n")[0]->getElements();

    $text = implode('', array_map(
        static fn ($child): string => $child instanceof TextBreak ? "\n" : $child->getText(),
        array_values(array_filter($children, static fn ($child): bool => !$child instanceof TextBreak)),
    ));

    expect(count($children))->toBe(3);
    expect($children[1])->toBeInstanceOf(TextBreak::class);
    expect($text)->toBe('onetwo');
});

it('contributes nothing for the elements that have no content to give', function () {
    // An image in raw HTML is not a Markdown image, so it is not embedded and
    // its attributes go nowhere; the text around it is still there.
    expect(renderText("<img src=\"x.png\" onerror=\"alert(1)\">after\n"))->toBe('after');
    expect(renderText("a<hr>b\n"))->toBe('ab');
});

it('drops a comment', function () {
    expect(renderText("a <!-- secret --> b\n"))->toBe('a  b');
});

it('decodes entities to the characters they name', function () {
    expect(renderText("&lt;script&gt; &amp; &#60;\n"))->toBe('<script> & <');
});

// The three modes

it('keeps the markup as literal text when asked to preserve it', function () {
    $result = renderHtml("Some <b>bold</b> text\n", ['html' => Options::HTML_PRESERVE]);

    expect($result['text'])->toContain('<b>bold</b>');
    // Monospaced, because it is now code rather than formatting.
    expect($result['xml'])->not->toContain('<w:b w:val="1"/>');
});

it('discards the markup when asked to drop it', function () {
    $dropped = Configuration::create()->withOptions(['html' => Options::HTML_DROP]);

    expect(renderText("Some <b>bold</b> text\n", $dropped))->toBe('Some bold text');
});

it('drops only the markup around text the author wrote in Markdown', function () {
    // In `a <b>bold</b> b` the words are ordinary Markdown text and the tags are
    // the HTML, so dropping the markup leaves the words. Dropping those too would
    // silently lose the author's content.
    $dropped = Configuration::create()->withOptions(['html' => Options::HTML_DROP]);

    expect(renderText("a <b>bold</b> b\n", $dropped))->toBe('a bold b');
});

it('drops a whole block of HTML, text and all', function () {
    // The other half of the same coin, and the reason `drop` is not the safe
    // default: in `<div>words</div>` the words are *inside* the HTML, so there is
    // nothing left of the paragraph once it is discarded.
    $dropped = Configuration::create()->withOptions(['html' => Options::HTML_DROP]);

    expect(renderText("<div>a whole block</div>\n", $dropped))->toBe('');
    // `strip` is what keeps them, which is why it is the default.
    expect(renderText("<div>a whole block</div>\n"))->toBe('a whole block');
});

// The security

it('never turns raw HTML into markup in the document', function () {
    // The claim is not that the text is safe, it is that the *structure* is ours.
    // Nothing in this input should reach the XML as anything but character data.
    $hostile = <<<'HTML'
        <div onclick="alert(1)" onmouseover="alert(2)">
        <a href="javascript:alert(3)" style="color:red">link</a>
        <img src="x" onerror="alert(4)">
        <iframe src="https://evil.test"></iframe>
        <form action="/steal"><input name="a"></form>
        </div>
        HTML;

    $result = renderHtml($hostile);

    // No event handler, script URL or frame made it into the document.
    foreach (['onclick', 'onmouseover', 'onerror', 'javascript:', 'iframe', 'evil.test', 'alert('] as $needle) {
        expect($result['xml'])->not->toContain($needle);
    }
});

it('does not let an attribute value break out into the XML', function () {
    // An attribute carrying what would otherwise close the element and open a
    // real run. The renderer never copies attribute text into the XML at all,
    // so this cannot happen however it is written.
    $result = renderHtml(
        '<a href="x" title="&quot;&gt;&lt;/w:t&gt;&lt;w:r&gt;&lt;w:t&gt;INJECTED">t</a>' . "\n"
    );

    expect($result['xml'])->not->toContain('INJECTED');
    expect($result['text'])->toContain('t');
});

it('leaves the document valid XML whatever the input', function () {
    foreach ([
        '<a href="x" onclick="alert(1)">&lt;script&gt;</a>',
        '<div><span><b>unclosed</div>',
        '<table><tr><td>cell',
        '<<>script>alert(1)<</script>',
        '<a href="&quot;">quote</a>',
    ] as $markdown) {
        expect(isValidDocx(renderHtml($markdown . "\n")['docx']))
            ->toBeTrue();
    }
});

it('does not resolve an external entity', function () {
    // The HTML is parsed by libxml, so an entity pointing at a local file is
    // the obvious thing to try. It is not resolved: the parser is told not to
    // reach the network, and an HTML parse has no DTD to expand anyway.
    $canary = Scratch::path('canary', '.txt');
    file_put_contents($canary, 'SECRET-CANARY-12345');

    $result = renderHtml(sprintf(
        "<!DOCTYPE x [<!ENTITY leak SYSTEM \"file://%s\">]>\n<div>&leak;</div>\n",
        $canary,
    ));

    expect($result['text'])->not->toContain('SECRET-CANARY-12345');
});

it('does not follow a reference to a local file through an image tag', function () {
    $canary = Scratch::path('pic-canary', '.png');
    imagepng(imagecreatetruecolor(4, 4), $canary);

    $result = renderHtml(sprintf("<img src=\"file://%s\">\n", $canary));

    // No media part, so nothing was read out of the document either.
    expect($result['xml'])->not->toContain('imagedata');
});

it('leaves a script body as inert text rather than running it', function () {
    // There is nothing to execute: a `.docx` has no scripting, and the body
    // arrives as text. The point of the check is that it is *text*, so it is
    // escaped on the way out like any other.
    $result = renderHtml("<script>alert(1)</script>\n");

    expect($result['text'])->toBe('alert(1)');
    expect($result['xml'])->toContain('alert(1)');
    expect(isValidDocx($result['docx']))->toBeTrue();
});

it('does not make a raw anchor into a live link', function () {
    // Markdown links become hyperlinks. A raw HTML anchor does not, because the
    // `href` is read into a style and then only the text is written — which is
    // one more thing the HTML cannot do to the document.
    $raw = renderHtml("See <a href=\"https://evil.test/x\">this</a>\n");
    $markdown = renderHtml("See [this](https://ok.test)\n");

    expect($raw['xml'])->not->toContain('w:hyperlink');
    expect(TemplateFactory::targetsOf($markdown['docx']))->toContain('https://ok.test');
});
