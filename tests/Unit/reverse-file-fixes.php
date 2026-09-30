<?php

declare(strict_types=1);

use MarkdownWord\Exception\FileNotWritable;
use MarkdownWord\Exception\UnreadableDocument;
use MarkdownWord\Reverse\Options as ReverseOptions;
use MarkdownWord\Reverse\Package;
use MarkdownWord\Reverse\StyleTable;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\Upstream;
use MarkdownWord\WordToMarkdown;
use MarkdownWord\Xml;

/*
 * Writing a Word document back out as Markdown, against a hostile one.
 *
 * Everything here is a `.docx` that somebody else wrote. The archive decides what
 * it is called, what is inside it, how large it claims to be when it is a few
 * kilobytes of highly compressible text, and what the files it names are called
 * once they are on disk. A converter that reads such a document has to be the
 * one that says no, because everything downstream of it — a served directory, a
 * git working tree, the caller's own next conversion — trusts the file that came
 * out.
 *
 * The assertions are made against the filesystem rather than against what the
 * converter reported, because every one of these defects is a write that did not
 * happen, or happened to the wrong file. A converter that says it wrote the
 * Markdown and does not is indistinguishable from one that did, right up until
 * the file is opened.
 */

// Half of these tests are about a write that cannot happen, so the library is
// going to try and fail: a directory that cannot be made, a rename onto a
// filesystem that will not take it. Each of those is a diagnostic the library
// silences at the call site, and this keeps it silenced for the runner too.
beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

// ------------------------------------------------------------------- helpers

/**
 * A `.docx` written by hand, part by part.
 *
 * A document this library produced cannot be hostile to it, so the packages here
 * are assembled directly: the content types, the two relationship parts, the
 * document, and whatever the document is being made to point at.
 *
 * @param array<string, string> $parts Part name => its contents.
 */
function fileFixArchive(array $parts): string
{
    $w = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $relationships = 'http://schemas.openxmlformats.org/package/2006/relationships';
    $types = 'http://schemas.openxmlformats.org/package/2006/content-types';

    $document = array_merge([
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="' . $types . '">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="'
            . 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="' . $relationships . '">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
            . ' Target="word/document.xml"/>'
            . '</Relationships>',
        'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="' . $relationships . '"/>',
        'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="' . $w . '"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"'
            . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
            . '><w:body><w:p><w:r><w:t xml:space="preserve">seed</w:t></w:r></w:p></w:body></w:document>',
    ], $parts);

    $archive = new ZipArchive();
    $path = Scratch::path('file-fix');

    if ($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to write the test archive.');
    }

    foreach ($document as $name => $contents) {
        $archive->addFromString($name, $contents);
    }

    $archive->close();

    return $path;
}

/**
 * A document whose only content is a picture, referred to through `$target`.
 *
 * The relationship is what the reader resolves the image's name from, so it is
 * the name the document ends up written under; the bytes are given separately,
 * because a document can name a file and then put anything in it.
 */
function fileFixWithImage(string $target, string $bytes): string
{
    return fileFixArchive([
        'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId7"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image"'
            . ' Target="' . $target . '"/>'
            . '</Relationships>',
        'word/' . $target => $bytes,
        'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"'
            . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
            . '<w:body><w:p><w:r><w:drawing><wp:inline>'
            . '<wp:docPr id="1" name="Picture 1" descr="A picture"/>'
            . '<a:graphic><a:graphicData><pic:pic>'
            . '<pic:blipFill><a:blip r:embed="rId7"/></pic:blipFill>'
            . '</pic:pic></a:graphicData></a:graphic>'
            . '</wp:inline></w:drawing></w:r></w:p></w:body></w:document>',
    ]);
}

/**
 * A document whose one part inflates to forty megabytes out of forty kilobytes.
 *
 * Forty megabytes of a paragraph of spaces is a few kilobytes on the wire, which
 * is the whole point: a `.docx` from somebody else promises neither number, and a
 * zip bomb is nothing more than a promise kept about the wrong one.
 */
function fileFixBombArchive(): string
{
    return fileFixArchive([
        'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . '<w:p><w:r><w:t xml:space="preserve">' . str_repeat(' ', 40 * 1024 * 1024) . '</w:t></w:r></w:p>'
            . '</w:body></w:document>',
    ]);
}

/**
 * A directory nothing has been written into, as a path.
 */
function fileFixMediaDirectory(): string
{
    return Scratch::path('file-fix-media', '');
}

/**
 * An enhanced metafile's `EMR_HEADER`, which is what an `.emf` begins with.
 *
 * A record type of 1, a header that claims to fill the file, and the ` EMF`
 * signature at the offset the specification puts it at.
 */
function fileFixEnhancedMetafile(int $payload = 128): string
{
    // The record type, the record's own size, the device and the frame bounds,
    // the signature at the offset the specification puts it at, and the rest of
    // the header fields — 88 bytes in all. The records it points at follow.
    $header = "\x01\x00\x00\x00"
        . pack('V', 88)
        . pack('V4', 0, 0, 100, 100)   // rclBounds
        . pack('V4', 0, 0, 2540, 2540) // rclFrame
        . ' EMF'
        . str_repeat("\x00", 40);

    return $header . str_repeat("\x00", $payload);
}

/**
 * A Windows metafile in the Aldus placeable form: the 30-byte wrapper, which is
 * a key, a handle, a bounding box, a scale, a reserved field and a checksum,
 * followed by the `METAHEADER` record a bare metafile starts with.
 */
function fileFixPlaceableMetafile(): string
{
    return "\xd7\xcd\xc6\x9a"
        . pack('v', 0)                    // hmf
        . pack('V4', 0, 0, 100, 100)      // the bounding box
        . pack('v', 1440)                 // units per inch
        . pack('V', 0)                    // reserved
        . pack('v', 0)                    // checksum
        . pack('v', 1)                    // mtType: a metafile header
        . pack('v', 9)                    // mtHeaderSize
        . str_repeat("\x00", 32);
}

/**
 * The names in a directory, sorted, so that "what is on disk" can be asserted.
 *
 * @return list<string>
 */
function fileFixDirectoryContents(string $directory): array
{
    $entries = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
    sort($entries);

    return $entries;
}

/**
 * Run a snippet of PHP in a child process with a memory limit, and report how it
 * went.
 *
 * A memory limit cannot be imposed on the process running the suite, and
 * exhausting one is a fatal error that ends the run rather than failing a test —
 * so a regression in the bounds on a document's size would take the whole suite
 * with it instead of showing up as a failure. A child process is the only place
 * that can be told to be small and then survive not being.
 *
 * @return array{0: int, 1: string} The exit status and everything it printed.
 */
function fileFixInChildProcess(string $code, string $memoryLimit = '16M'): array
{
    $script = Scratch::path('file-fix-child', '.php');

    file_put_contents(
        $script,
        "<?php\n\ndeclare(strict_types=1);\n\nrequire "
        . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true)
        . ";\n\n"
        . $code,
    );

    $output = [];
    $status = 1;

    exec(
        sprintf(
            '%s -d memory_limit=%s -d error_reporting="E_ALL & ~E_DEPRECATED" %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($memoryLimit),
            escapeshellarg($script),
        ),
        $output,
        $status,
    );

    return [$status, implode("\n", $output)];
}

// ------------------------------------------------ writing the Markdown out

it('says so when the Markdown cannot be written, rather than reporting success', function () {
    $document = fileFixArchive([]);

    // A path whose parent is a file: the directory can neither be found nor made,
    // so the write has nowhere to go. The old code swallowed the warning and
    // returned the Markdown as though it had been saved.
    $blocker = Scratch::path('file-fix-blocker');
    file_put_contents($blocker, 'not a directory');

    expect(fn () => (new WordToMarkdown($document))->save($blocker . '/report.md'))
        ->toThrow(FileNotWritable::class, 'Unable to create the directory');

    expect(is_file($blocker . '/report.md'))->toBeFalse();
});

it('makes the directory a path asks for, and leaves nothing else in it', function () {
    $document = fileFixArchive([]);
    $directory = Scratch::path('file-fix-out', '') . '/nested';
    $target = $directory . '/report.md';

    (new WordToMarkdown($document))->save($target);

    expect(is_file($target))->toBeTrue();
    expect((string) file_get_contents($target))->toContain('seed');

    // The write is staged in the target's own directory so that it can be moved
    // into place, and the staging file is not left behind: a name nobody asked
    // for, next to the one they did.
    expect(fileFixDirectoryContents($directory))->toBe(['report.md']);
});

it('does not truncate the document it is reading when the output is a hard link to it', function () {
    // `file_put_contents()` opens an existing name with `O_TRUNC`, and a hard
    // link is the same inode under a second name — so writing the Markdown to
    // the alias destroys the very document being read. `rename()` over the
    // alias leaves the input alone, because it replaces the name rather than
    // emptying the file behind it.
    if (!function_exists('link')) {
        expect(true)->toBeTrue();

        return;
    }

    $document = fileFixArchive([]);
    $alias = Scratch::path('file-fix-alias', '.docx');

    if (!@link($document, $alias)) {
        // A filesystem that will not hard link (an exFAT or FAT volume, or a
        // container on a bind mount) cannot be shown to have the defect.
        expect(true)->toBeTrue();

        return;
    }

    $before = (string) file_get_contents($document);

    $markdown = (new WordToMarkdown($document))->convert($alias);

    expect((string) file_get_contents($document))->toBe($before);
    expect($before)->toStartWith("PK\x03\x04");
    expect((string) file_get_contents($alias))->toBe($markdown);
});

// --------------------------------------------------- taking images out

it('writes nothing for an image the document named after a script', function () {
    // The relationship is the name of the file, and the archive is what is put
    // in it: both are chosen by whoever wrote the document. A `.php` beside a
    // Markdown file, in a directory that is served, is remote code execution.
    $media = fileFixMediaDirectory();
    $document = fileFixWithImage('media/payload.php', '<?php system($_GET["c"]);');

    $options = ReverseOptions::fromArray(['mediaDirectory' => $media]);

    (new WordToMarkdown($document, $options))->convert();

    expect(is_dir($media) ? fileFixDirectoryContents($media) : [])->toBe([]);
    expect(is_file('/payload.php'))->toBeFalse();
});

it('writes nothing for an image named like a file the web server reads', function () {
    // The name is allowed through as an image because it ends in one; the bytes
    // are what give it away. An `.htaccess` is the cheapest version of this,
    // because it needs no extension at all and rewrites how the directory is
    // served.
    $media = fileFixMediaDirectory();
    $document = fileFixWithImage('media/.htaccess', 'AddType application/x-httpd-php .png');

    $options = ReverseOptions::fromArray(['mediaDirectory' => $media]);

    (new WordToMarkdown($document, $options))->convert();

    expect(is_dir($media) ? fileFixDirectoryContents($media) : [])->toBe([]);
});

it('writes nothing for an image whose bytes are a script', function () {
    // The name is the first of a `.docx`'s two answers and the content is the
    // second, and only the second one says what the file is. A name in the
    // allow-list and a payload in the file is the combination that matters.
    $media = fileFixMediaDirectory();
    $document = fileFixWithImage('media/pic.png', '<?php system($_GET["c"]);');

    $options = ReverseOptions::fromArray(['mediaDirectory' => $media]);

    (new WordToMarkdown($document, $options))->convert();

    expect(is_dir($media) ? fileFixDirectoryContents($media) : [])->toBe([]);
});

it('takes a real image out of the archive', function () {
    // The checks above are only worth having if an ordinary document still
    // works: a picture in a picture's own format has to arrive, byte for byte,
    // under the name the Markdown refers to.
    $media = fileFixMediaDirectory();
    $image = (string) file_get_contents(Scratch::image('round-trip.png'));
    $document = fileFixWithImage('media/pic.png', $image);

    $options = ReverseOptions::fromArray(['mediaDirectory' => $media]);

    expect((new WordToMarkdown($document, $options))->convert())->toBe('![A picture](' . $media . '/pic.png)');
    expect((string) file_get_contents($media . '/pic.png'))->toBe($image);
});

it('leaves an image that is already there alone', function () {
    // The document chooses the name and the bytes, so a document that has been
    // converted once before could otherwise replace a file somebody else put
    // there — the site's own logo, most of all. The first file written wins, and
    // the second is left where it is.
    $media = fileFixMediaDirectory();
    mkdir($media, 0o777, true);
    file_put_contents($media . '/pic.png', 'the original');

    $image = (string) file_get_contents(Scratch::image('replacing.png'));
    $document = fileFixWithImage('media/pic.png', $image);

    $options = ReverseOptions::fromArray(['mediaDirectory' => $media]);

    (new WordToMarkdown($document, $options))->convert();

    expect((string) file_get_contents($media . '/pic.png'))->toBe('the original');
});

it('takes a vector image out as well, since getimagesize cannot see one', function () {
    // `getimagesize()` knows the raster formats and nothing else, so a check
    // written with it alone would quietly drop every `.emf` and `.wmf` — and
    // those are two of the formats Word itself embeds. They are recognised by
    // their own headers instead: an enhanced metafile's record type and the
    // ` EMF` signature inside it, a Windows metafile's placeable key or its own
    // record type and size.
    $media = fileFixMediaDirectory();
    $emf = fileFixEnhancedMetafile();
    $wmf = fileFixPlaceableMetafile();

    foreach (['media/chart.emf' => $emf, 'media/chart.wmf' => $wmf] as $target => $bytes) {
        $document = fileFixWithImage($target, $bytes);
        $options = ReverseOptions::fromArray(['mediaDirectory' => $media]);

        (new WordToMarkdown($document, $options))->convert();

        expect((string) file_get_contents($media . '/' . basename($target)))->toBe($bytes);
    }
});

it('does not write a vector image whose bytes are a script', function () {
    // The two header checks are small, and a small check is one that can be
    // got wrong: a name allowed as a vector image is still not a reason to
    // write a script.
    //
    // What is not claimed is that the check reads the whole file. Bytes after a
    // valid header are records the header says are there, and a metafile with a
    // script appended to it is a metafile as far as anything short of rendering
    // it can tell. The check is on the container, which is the part a script
    // cannot fake; what is behind it is behind it either way.
    $media = fileFixMediaDirectory();

    $scripts = [
        'media/bare.wmf' => '<?php system($_GET["c"]);',
        // A placeable key and then the script, with the `METAHEADER` a real one
        // carries after the wrapper left out: the key is four fixed bytes at
        // the start of a file, and a check that stops there is a check a script
        // walks past.
        'media/placeable.wmf' => "\xd7\xcd\xc6\x9a" . '<?php system($_GET["c"]);',
    ];

    foreach ($scripts as $target => $bytes) {
        $document = fileFixWithImage($target, $bytes);
        $options = ReverseOptions::fromArray(['mediaDirectory' => $media]);

        (new WordToMarkdown($document, $options))->convert();
    }

    expect(fileFixDirectoryContents($media))->toBe([]);
});

it('does not write a file that only looks like a metafile header', function () {
    // A metafile check that reads the record type and its size but not the
    // signature passes anything beginning `01 00 00 00`, and a script is the
    // cheapest thing to put there. The signature is the part that says this is
    // an enhanced metafile.
    $media = fileFixMediaDirectory();
    $impostor = "\x01\x00\x00\x00" . pack('V', 128) . str_repeat('<?php ', 16);
    $document = fileFixWithImage('media/chart.emf', $impostor);

    $options = ReverseOptions::fromArray(['mediaDirectory' => $media]);

    (new WordToMarkdown($document, $options))->convert();

    expect(fileFixDirectoryContents($media))->toBe([]);
});

// ------------------------------------------------------ the size of a part

it('refuses a document whose parts declare more bytes than the budget allows', function () {
    // The declared size is checked before the part is inflated, so the budget
    // bounds memory rather than reporting it afterwards.
    $document = fileFixBombArchive();

    // The exception is caught, which is the other half of the difference: a
    // refusal is something a caller can carry on from, where a memory fatal
    // ends the process whatever it is wrapped in.
    [$status, $output] = fileFixInChildProcess(
        'try {'
        . 'MarkdownWord\\Reverse\\Package::open(' . var_export($document, true) . ', '
        . 'new MarkdownWord\\Reverse\\Options(maxPartBytes: 1048576))->document();'
        . ' echo "not refused";'
        . '} catch (MarkdownWord\\Exception\\UnreadableDocument $refused) {'
        . ' echo $refused->getMessage();'
        . '}',
    );

    // Before the bound there was nothing to stop this, and the child died of an
    // exhausted memory limit — a fatal error, uncatchable, indistinguishable
    // from a crash.
    expect($status)->toBe(0, 'The child process reported: ' . $output);
    expect($output)->toContain('larger than')
        ->and($output)->not->toContain('not refused');

    // And the same document converts when it fits.
    [$status, $output] = fileFixInChildProcess(
        'echo strlen((new MarkdownWord\\WordToMarkdown(' . var_export($document, true) . '))->convert());',
        '512M',
    );

    expect($status)->toBe(0, 'The child process reported: ' . $output);
    expect((int) $output)->toBeGreaterThanOrEqual(40 * 1024 * 1024);
});

it('leaves the size of a part to the budget rather than to the memory limit', function () {
    // The other half of the test above, and the reason it is not a tautology: a
    // memory limit catches a document only by killing the process, which is not
    // something a caller can handle. A bound checked before the part is
    // inflated is a refusal instead — but it is a bound the caller sets, so the
    // default is generous enough to be a cap rather than a wall, and a document
    // that fits inside it is read.
    $document = fileFixBombArchive();

    [$status, $output] = fileFixInChildProcess(
        '(new MarkdownWord\\WordToMarkdown(' . var_export($document, true) . '))->convert();',
    );

    expect($status)->not->toBe(0);
    expect($output)->toContain('Allowed memory size');
});

it('refuses an archive with more entries in it than the budget allows', function () {
    // The count is in the central directory, so it costs nothing to read before
    // anything is inflated. Without the cap, a document with a million empty
    // parts is a million parts, and the reader walks them.
    $parts = [];

    for ($index = 0; $index < 12; $index++) {
        $parts['word/pad' . $index . '.xml'] = '<p/>';
    }

    $document = fileFixArchive($parts);

    expect(fn () => Package::open($document, ReverseOptions::fromArray(['maxEntries' => 4])))
        ->toThrow(UnreadableDocument::class, 'more entries than');

    // The same document is not a problem under the default cap.
    $package = Package::open($document);
    $root = $package->document()->documentElement?->localName;
    $package->close();

    expect($root)->toBe('document');
});

// ------------------------------------------------------- the options object

it('casts the values that arrive from a configuration file', function () {
    // A JSON or YAML file has no types, and `fromArray()` used to hand whatever
    // it found straight to an `int`-typed property — a string '720' in a
    // property declared `int`, which then quietly changes how quote nesting is
    // measured. The forward direction has cast its options since it had a
    // configuration file; this one had to grow the same habit.
    expect(ReverseOptions::fromArray(['quoteIndent' => '720'])->quoteIndent)->toBe(720)
        ->and(ReverseOptions::fromArray(['fenceCodeBlocks' => '0'])->fenceCodeBlocks)->toBeFalse()
        ->and(ReverseOptions::fromArray(['fenceCodeBlocks' => 'yes'])->fenceCodeBlocks)->toBeTrue()
        ->and(ReverseOptions::fromArray(['maxPartBytes' => '1024'])->maxPartBytes)->toBe(1024)
        ->and(ReverseOptions::fromArray(['maxEntries' => '8'])->maxEntries)->toBe(8);
});

it('clamps the values that have a range', function () {
    // An indent of zero would divide by nothing when a quote is nested, and a
    // negative one would nest the wrong way round.
    expect(ReverseOptions::fromArray(['quoteIndent' => '0'])->quoteIndent)->toBe(1)
        ->and(ReverseOptions::fromArray(['quoteIndent' => -5])->quoteIndent)->toBe(1)
        ->and(ReverseOptions::fromArray(['maxEntries' => 0])->maxEntries)->toBe(1)
        ->and(ReverseOptions::fromArray(['maxPartBytes' => -1])->maxPartBytes)->toBe(1);
});

it('reads an empty media directory as no media directory at all', function () {
    // An empty string is not a directory, and `$directory . '/' . $name` turns it
    // into a path at the root of the filesystem: the images land in `/`, or are
    // refused there, either way with a Markdown that refers to `/image1.png`.
    expect(ReverseOptions::fromArray(['mediaDirectory' => ''])->mediaDirectory)->toBeNull()
        ->and(ReverseOptions::fromArray(['mediaDirectory' => 'assets'])->mediaDirectory)->toBe('assets')
        ->and((new ReverseOptions())->withMediaDirectory('')->mediaDirectory)->toBeNull();
});

it('does not put a Markdown reference at the root of the filesystem for an empty media directory', function () {
    $media = fileFixMediaDirectory();
    $image = (string) file_get_contents(Scratch::image('empty-dir.png'));
    $document = fileFixWithImage('media/pic.png', $image);

    // Read through `withAll()`, which is the path a caller takes when the value
    // came from a file rather than from code.
    $options = (new ReverseOptions())->withAll(['mediaDirectory' => '']);

    expect((new WordToMarkdown($document, $options))->convert())->toBe('![A picture](media/pic.png)');
    expect(is_file('/pic.png'))->toBeFalse();

    unset($media);
});

it('carries the style depth cap into the style table, as the cap is for', function () {
    // The bound on a `basedOn` chain lives in `StyleTable`, which takes the cap
    // as an argument and defaults it. The options expose it, so a caller who
    // sets one gets the cap they asked for rather than the default — which is
    // the whole point of it being an option, and the thing that would quietly
    // stop being true if the plumbing were not there.
    $styles = Xml::parse(
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:style w:type="paragraph" w:styleId="S0"><w:basedOn w:val="S1"/></w:style>'
        . '<w:style w:type="paragraph" w:styleId="S1"><w:basedOn w:val="S2"/></w:style>'
        . '<w:style w:type="paragraph" w:styleId="S2"><w:pPr><w:ind w:left="720"/></w:pPr></w:style>'
        . '</w:styles>',
    );

    expect((new StyleTable($styles))->indentOf('S0'))->toBe(720);
    expect((new StyleTable($styles, 1))->indentOf('S0'))->toBe(0);
    expect((new StyleTable($styles, ReverseOptions::fromArray(['maxStyleDepth' => 2])->maxStyleDepth))->indentOf('S0'))
        ->toBe(0);
});

it('offers a setter for every switch, as the forward direction does', function () {
    // The one thing the `Converter` interface promises is that either direction
    // can stand in for the other, and an options object that can only be
    // changed one property at a time is a different object in one direction
    // than in the other.
    $options = new ReverseOptions();

    expect($options->withHeadingStyles(['Title'])->headingStyles)->toBe(['Title'])
        ->and($options->withQuoteStyles(['Quote'])->quoteStyles)->toBe(['Quote'])
        ->and($options->withMonospaceFonts(['Menlo'])->monospaceFonts)->toBe(['Menlo'])
        ->and($options->withQuoteIndent(360)->quoteIndent)->toBe(360)
        ->and($options->withFenceCodeBlocks(false)->fenceCodeBlocks)->toBeFalse()
        ->and($options->withTableHeader(false)->tableHeader)->toBeFalse()
        ->and($options->withHeadingSetext(true)->headingSetext)->toBeTrue()
        ->and($options->withMediaDirectory('assets')->mediaDirectory)->toBe('assets')
        ->and($options->withLineEnding("\r\n")->lineEnding)->toBe("\r\n")
        ->and($options->withMaxPartBytes(1024)->maxPartBytes)->toBe(1024)
        ->and($options->withMaxEntries(8)->maxEntries)->toBe(8)
        ->and($options->withMaxStyleDepth(4)->maxStyleDepth)->toBe(4);
});

it('carries every switch through toArray() and back', function () {
    $options = ReverseOptions::fromArray([
        'headingStyles' => ['Heading1'],
        'quoteStyles' => ['Quote'],
        'monospaceFonts' => ['Menlo'],
        'quoteIndent' => '360',
        'fenceCodeBlocks' => '0',
        'tableHeader' => '0',
        'headingSetext' => '1',
        'mediaDirectory' => 'assets',
        'lineEnding' => "\r\n",
        'maxPartBytes' => '1024',
        'maxEntries' => '8',
        'maxStyleDepth' => '4',
    ]);

    expect(ReverseOptions::fromArray($options->toArray())->toArray())->toBe($options->toArray());
});
