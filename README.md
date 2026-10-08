# markdown-word

[![tests](https://github.com/fabeat/markdown-word/actions/workflows/tests.yml/badge.svg)](https://github.com/fabeat/markdown-word/actions/workflows/tests.yml)
[![phar](https://github.com/fabeat/markdown-word/actions/workflows/phar.yml/badge.svg)](https://github.com/fabeat/markdown-word/actions/workflows/phar.yml)
[![Quality gate status](https://sonarcloud.io/api/project_badges/measure?project=fabeat_markdown-word&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=fabeat_markdown-word)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=fabeat_markdown-word&metric=coverage)](https://sonarcloud.io/summary/new_code?id=fabeat_markdown-word)

[![Latest release](https://img.shields.io/github/v/release/fabeat/markdown-word)](https://github.com/fabeat/markdown-word/releases/latest)
[![Licence](https://img.shields.io/github/license/fabeat/markdown-word)](https://github.com/fabeat/markdown-word#licence)
[![php](https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fraw.githubusercontent.com%2Ffabeat%2Fmarkdown-word%2Fmain%2Fcomposer.json&query=%24.require.php&label=php&logo=php&logoColor=white)](https://github.com/fabeat/markdown-word#requirements)

Convert Markdown to Word documents in pure PHP — and back again.

Parsing is done by [`league/commonmark`][commonmark], writing by
[`phpoffice/phpword`][phpword]. Everything in between — mapping the Markdown
syntax tree onto Word's document model — is this library.

**Markdown to Word:**

```php
use MarkdownWord\MarkdownToWord;

(new MarkdownToWord('README.md'))->save('README.docx');
```

**Word to Markdown:**

```php
use MarkdownWord\WordToMarkdown;

(new WordToMarkdown('README.docx'))->save('README.md');
```

The two are the same line with the ends swapped. The thing being converted goes
in the constructor, named as a path or handed over as the content itself, and
`save()` writes the result to the file named after it; `convert()` returns the
result as a string instead when that is what is wanted:

```php
(new MarkdownToWord('notes.md'))->convert();          // → the document's bytes
(new MarkdownToWord('notes.md'))->save('notes.docx'); // → a file
(new WordToMarkdown('notes.docx'))->convert();        // → the Markdown
(new WordToMarkdown('notes.docx'))->save('notes.md'); // → a file
```

So there is no need to read a file first. A string that names a file that exists
is read from it, and anything else is taken as the content — the same rule in
both directions, and the one the command line works by:

```php
(new MarkdownToWord('notes.md'))->save('notes.docx');                  // a path
(new MarkdownToWord(file_get_contents('notes.md')))->save('notes.docx'); // or the text
```

Both implement one interface, so code that does not care which way the data goes
can be written once:

```php
use MarkdownWord\Converter;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\WordToMarkdown;

function convert(Converter $converter, string $target): void
{
    $converter->save($target);   // Markdown in, or a document in — either works
}

convert(new MarkdownToWord('notes.md'), 'notes.docx');
convert(new WordToMarkdown('report.docx'), 'report.md');
```

A round trip is two of them and nothing else:

```php
$word = new MarkdownToWord(file_get_contents('notes.md'));
$back = (new WordToMarkdown($word->convert()))->convert();
```

Or from a terminal, with no PHP to write. The direction is worked out from the
file, so there is nothing to choose:

```sh
mdword README.md          # writes README.docx
mdword README.docx        # writes README.md
```

## Installation

```sh
composer require fabeat/markdown-word
```

That installs the command line along with the library, because `composer.json`
declares `bin: ["bin/mdword"]` — after a Composer install it is at
`vendor/bin/mdword`.

Or the whole thing as one file, with nothing installed but PHP:

```sh
curl -L -o mdword.phar https://github.com/fabeat/markdown-word/releases/latest/download/mdword.phar
chmod +x mdword.phar
./mdword.phar --version
```

The `chmod +x` is not optional. A download from a GitHub release does not carry
the executable bit, and without it `./mdword.phar` fails with *permission
denied*.

See [Requirements](#requirements) for what PHP and which extensions it needs.
Where to get help: [the issue tracker][issues].

## Why it exists

The obvious way to build a Markdown-to-Word converter is to render HTML and hand
it to PHPWord's HTML importer. That works for simple documents and quietly falls
apart on real ones: emphasis nested inside emphasis, line breaks, code blocks,
links whose text is itself formatted. Those are exactly the things READMEs are
made of.

This library walks the Markdown **syntax tree** instead. Nothing is flattened on
the way through HTML, so what comes out is what the author wrote.

## Conformance

The test suite runs every example from the official specification suites and
checks that the resulting document carries exactly the text the specification
says it should:

| Suite | Examples |
| --- | --- |
| [CommonMark 0.31.2][cm-spec] | 654 |
| [GitHub-Flavored Markdown 0.29][gfm-spec] | 646 |

`league/commonmark` is a fully conforming parser, so the visible text of its HTML
output *is* the specification's answer. Comparing the text of the generated Word
document against that is a strong, automatic check — a construct that loses or
invents text fails the build.

```sh
composer check             # the lot, in the order continuous integration runs it
composer test              # the test suite
composer test:unit         # the unit tests alone
composer test:spec         # just the conformance suite
composer test:roundtrip    # just the round-trip suite
composer test:readme       # just the examples on this page
composer test:coverage     # with a report in build/coverage
composer build:phar        # writes build/mdword.phar
php smoke.php              # does the library work on this version of PHP?
php stress.php             # malformed input across every configuration
php examples/build.php     # a document for every example in examples/
```

`stress.php` feeds deliberately awkward input — unterminated delimiters, control
characters, lone `<` and `&`, very deep nesting — through nine configurations,
and then through a template with the seven of those that are not merely a choice
of parser. Every result has to be a valid `.docx`. It is what caught the escaping
defect described below.

The examples on this page are `tests/Readme/examples.php`, one test each, and
they run with everything else. The `mdword` one-liners are driven through the
application, so the documentation cannot quietly stop describing what the code
does.

Tests are written with [Pest][pest] 5, against PHPUnit 13.

### Continuous integration

| Workflow | What it does |
| --- | --- |
| `tests` | `composer validate --strict`, `composer audit`, the README examples and the command line, then the suite on PHP 8.4 and 8.5 with a coverage report |
| `phar` | builds `mdword.phar`, runs it on its own, uploads it, and attaches it to a release when a tag is pushed |
| `sonarcloud` (a job in `tests`) | static analysis on [SonarCloud][sonarcloud], failing the build when the quality gate is red |

PHP 8.2 is in the matrix too, but the test suite cannot run there: **Pest 5
requires PHP 8.4**, while the library itself supports 8.2. That gap is covered by
`smoke.php`, which uses no test framework and checks that both directions and the
command line work on whatever PHP it is given, and by `stress.php` beside it.
Pointed at a phar, `smoke.php` runs the archive as well — see
[Requirements](#requirements). Between the two, every supported version is
exercised.

Everything installs what `composer.lock` pins, so a build is repeatable. A weekly
`dependencies` job resolves afresh instead and runs the suite against the result,
which is how a new release of a dependency still gets tested.

### Static analysis

SonarCloud runs from this repository's own workflow rather than from its GitHub
integration, so the quality gate can fail a build. What it analyses is set in
[`sonar-project.properties`](sonar-project.properties) rather than in the workflow,
so it is visible to anyone reading the repository.

It authenticates with a `SONAR_TOKEN` repository secret, which is never in the
repository. Create one at [your account's security page][sonar-token] and add it
under **Settings → Secrets and variables → Actions**. Without it the analysis job
stops and says so.

## Word to Markdown

The same mapping runs in reverse, and the class mirrors the way in:

```php
use MarkdownWord\WordToMarkdown;

echo (new WordToMarkdown('report.docx'))->convert();       // as a string
(new WordToMarkdown('report.docx'))->save('report.md');   // straight to a file
echo (new WordToMarkdown())->toMarkdown($bytes);          // bytes already in hand
```

A Word document is a lower-fidelity form of the Markdown it came from, so the
round trip preserves what Word was told to keep and is explicit about the rest.
[What it does not preserve](#what-the-round-trip-does-not-preserve) is worth
knowing before the options, which configure the reader rather than undo the loss.
The distinctions Word does not record are options:

| Option | Default | Meaning |
| --- | --- | --- |
| `headingStyles` | `Heading`, `Title` | style ids read as ATX headings |
| `quoteStyles` | `IntenseQuote`, `Quote`, `BlockQuote` | style ids read as block quotes |
| `monospaceFonts` | Consolas, Courier, Menlo, … | typefaces read as code |
| `quoteIndent` | `720` | twips per level of quote nesting |
| `fenceCodeBlocks` | `true` | two or more monospaced paragraphs are a code block |
| `tableHeader` | `true` | the first row is written as the header |
| `headingSetext` | `false` | first- and second-level headings underlined |
| `mediaDirectory` | `null` | a directory the images are taken out into |
| `lineEnding` | newline | what the output file uses |
| `maxPartBytes` | 256 MB | largest a part of the archive may be uncompressed |
| `maxEntries` | `4096` | largest number of parts the archive may have |
| `maxStyleDepth` | `32` | how far a `basedOn` chain of styles is followed |

A document is not this library's, so the last three are what it is willing to be
told: a `.docx` is a zip, and a zip says how its contents are laid out without
saying how much room they will take up.

```php
use MarkdownWord\Reverse\Options;

new WordToMarkdown(null, Options::fromArray(['mediaDirectory' => 'assets']));
```

`Reverse\Options::toArray()` gives the whole table back, which is handy in a config
file or a log. And for a caller that wants the document rather than the Markdown
— to index it, or to decide what to do with it before writing anything:

```php
$blocks = (new WordToMarkdown())->read('report.docx');   // list of Reverse\Block
```

The output is GitHub-Flavored Markdown: a Word table can only be a GFM table,
and struck-through text can only be GFM's `~~`.

### Round trips

```
Markdown --> Word --> Markdown --> Word
             |------ same text -----|
```

#### What the round trip does not preserve

Word does not record these, so there is nothing for the reader to find. All five
are what the round trip actually produces:

- **A table's header row comes back bold.** A run is bold in the document, and
  the document does not say whether the renderer or the author made it so:

  ```markdown
  | A | B |               | **A** | **B** |
  | --- | --- |     →     | :-- | :-- |
  | 1 | 2 |               | 1 | 2 |
  ```

  The alignment does survive — `:--`, `--:` and `:-:` say what `---`, `---:` and
  `:---:` said — and the delimiter row is the only part rewritten. It is the
  bold that is added, and `tableHeaderBold: false` is what stops it.

- **A fenced code block comes back without its language.** The fence and the text
  are in the file; the word that followed the fence is not:

  ````markdown
  ```php
  $x = 1;
  $y = 2;
  ```
  ````

  comes back as the same two lines between plain fences, which is all the reader
  has to go on.

- **A fenced code block of one line comes back as inline code.** `fenceCodeBlocks`
  reads *two or more* monospaced paragraphs as a block, so a single line is not
  enough: ```` ```php\n$x = 1;\n``` ```` is written back as `` `$x = 1;` ``.

- **A quote configured as plain indentation, rather than as a style, comes back
  as a plain paragraph.** The `>` is a paragraph style in Word, and with
  `blockQuote` set to `null` there is no style for the reader to recognise and no
  option that puts one back. The paragraph *is* indented, but `quoteIndent` only
  measures depth inside a quote already identified by its style.

- **The last line has no newline after it.** The body of a document ends at its
  last paragraph and records nothing about the end of the text, so the reader
  writes no trailing newline: `"# Title\n"` comes back as `"# Title"`. It is the
  first thing a `diff` shows. `lineEnding` set to `crlf` does add one, because
  that is the one case where the writer appends its own.

The round-trip suite runs all 1300 specification examples through both
directions and checks that the second Word document says the same thing as the
first. It is the check the forward direction alone cannot make: a defect in the
reader cannot hide behind a document nobody reads back.

It is what found that a link's title was dropped, that an image's alt text never
reached the file, and that a task list marker was silently discarded — none of
which the forward suite could see, because each of them loses nothing the
specification's expected text mentions.

`examples/out/11-round-trip.md` is `01-kitchen-sink.docx` read back, for looking
at side by side.

## Command line

`mdword` is the whole library at a terminal, and it works out for itself which
way the data has to go. A Word document is a zip archive and Markdown is text,
and the four bytes that say which is which are part of the format rather than a
convention — so the *name* of the file is never consulted, and a Markdown file
called `notes.docx` still converts the right way. `--to docx` or
`--to markdown` says it outright, which is the only way to be explicit when
reading from a pipe.

From a checkout, `php bin/mdword`. As a single file with nothing installed,
`php mdword.phar`.

The commands still exist for a script to use, where being explicit is worth more
than being short:

```sh
mdword to-docx notes.md
mdword to-markdown report.docx
```

Using one direction's option with the other says which command it belongs to
rather than that it is unknown. The result goes to standard output and progress
goes to standard error, so it composes with everything else:

```sh
cat notes.md | mdword --to docx - -o - | pbcopy
```

With no `-o`, the result is written beside the input with the extension swapped.
Reading from standard input there is no name to derive, so it goes to standard
output instead. A run whose result would land on its own input stops rather than
overwriting it.

The options both directions share:

| Option | Meaning |
| --- | --- |
| `-o, --output <file>` | where the result goes; `-` for standard output |
| `--to <docx\|word\|markdown\|md>` | which way to convert; detected from the file otherwise |

`to-docx` also takes:

| Option | Meaning |
| --- | --- |
| `-c, --config <file.php>` | a file returning the styles and options to use |
| `-t, --template <file.docx>` | render into a Word template rather than a new document |
| `--region <name>` | the template region to fill in, `body` by default |
| `--define <name=value>` | a value for a single-line placeholder; repeatable |
| `--images <mode>` | `embed`, `placeholder` or `skip` |
| `--no-images` | shorthand for `--images skip` |
| `--image-base <dir>` | where relative image paths resolve from |
| `--table-width <n>` | table width in fiftieths of a percent; `5000` is full width |
| `--plain` | no code colouring, no quote style, no table borders |

`to-markdown` also takes:

| Option | Meaning |
| --- | --- |
| `--media <dir>` | take the images out of the document, beside the Markdown |
| `--line-ending <lf\|crlf>` | what the output file uses between lines |
| `--setext` | write first- and second-level headings underlined |
| `--no-fence` | leave monospaced paragraphs as text rather than a code block |
| `--no-header` | do not treat the first table row as a header |

`mdword help` lists them all, and `mdword to-docx --help` the ones for one
direction. The help and the parser are separate lists — one is for reading, one
is for parsing — and a test holds them to each other, so neither can mention
something the other does not have.

A mistake is reported in a sentence, with what to do about it, and the exit code
is non-zero — a wrong `--region` names the regions the template does have rather
than producing a document full of `${...}`.

### The phar

```sh
composer build:phar        # build/mdword.phar
```

One self-contained file, about a megabyte, needing nothing but PHP. The
development dependencies are left out, which is most of why it is a megabyte
rather than ten: the test suite is several times the size of the library.

The build installs the runtime dependencies into a directory of its own rather
than into the project, so your `vendor/` keeps its test tools, and it checks the
result by converting a document in both directions before reporting success.

## Supported Markdown

| Construct | Result in Word |
| --- | --- |
| ATX and Setext headings | `Heading1`…`Heading6` styles |
| Paragraphs, soft and hard breaks | paragraphs and line breaks |
| `**bold**`, `*italic*`, `~~strike~~` | character formatting, nestable |
| `` `code` ``, fenced and indented blocks | monospaced, shaded paragraphs |
| Bullet and ordered lists, nested | real Word numbering, any depth |
| Task lists | `☐` or `☒` at the start of the item, read back as `- [ ]` / `- [x]` |
| `> block quotes`, nested | indented, styled paragraphs |
| Tables with alignment | `w:tbl` with the alignment applied |
| `[links](url)`, reference links, autolinks | `w:hyperlink`, including emphasis inside the label |
| Images | embedded, or alt text when the file is missing |
| `---` | a paragraph with a bottom border |
| Raw HTML | rebuilt as Word content, or shown as text, or dropped |
| Footnotes, description lists | with `CommonMarkParser::extended()` |

A link whose label contains formatting, such as `[**bold** link](url)`, becomes a
genuine Word hyperlink *with* the bold applied. PHPWord cannot express that
directly, so the renderer emits a placeholder and rewrites `word/document.xml`
into a `w:hyperlink` element while writing the file.

## Word templates

Point the renderer at the **styleIds** your template defines and it inherits the
whole design — fonts, colours, spacing, headers and footers.

```php
use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\Template\MarkdownTemplate;

$config = Configuration::create()->withStyles([
    Styles::HEADING_1 => 'ReportTitle',   // a styleId in your template
    Styles::PARAGRAPH => 'BodyText',
    Styles::BLOCK_QUOTE => 'PullQuote',
]);

$template = new MarkdownTemplate('report-template.docx', $config, [
    'customer' => 'Northwind Ltd',
]);

$template
    ->insert('body', file_get_contents('summary.md'))
    ->repeat('rows', [
        ['item' => 'Licence', 'price' => '1,200 EUR'],
        ['item' => 'Support', 'price' => '300 EUR'],
    ])
    ->save('northwind.docx');
```

### The template contract

A `${name}` macro is for a **single-line value**:

```
Report for ${customer}
```

Markdown needs a **region** — a `${name}` marker, a `${slot}` paragraph, and a
matching `${/name}` marker. The region is cloned once per rendered block and each
clone's slot is replaced:

```
${body}
${slot}
${/body}
```

A region whose macros are ordinary names repeats once per row instead:

```
${rows}
${item}: ${price}
${/rows}
```

`MarkdownTemplate::processor()` returns PHPWord's own `TemplateProcessor` for
anything this class does not wrap — replacing images, applying an XSL style sheet
and so on.

Two things are carried across into the template that a naive copy would lose, and
that the library therefore rebuilds on the way out:

- **Hyperlink relationships.** A `w:hyperlink` points at a relationship in the
  document it was written into.
- **List numbering.** A list paragraph points at a numbering definition, and the
  template has its own `word/numbering.xml`.

## Configuration

`Configuration` is immutable; every `with*` method returns a new instance.

```php
Configuration::create()
    ->withStyles([Styles::CODE_FONT => ['name' => 'Fira Code', 'size' => 10]])
    ->withOptions(['images' => Options::IMAGE_PLACEHOLDER]);
```

It can equally be built from a plain array, which keeps the look of your
documents in a config file:

```php
Configuration::fromArray(require 'config/markdown.php');
```

`withBuiltInHeadingStyles()` points every heading at the matching built-in Word
style *and* moves the quote and list slots to the built-in list styles, which is
not the default set — the defaults use `IntenseQuote` and numbering definitions
of this library's own:

```php
$config = Configuration::create()->withBuiltInHeadingStyles();
// blockQuote → Quote, bulletList → ListBullet, orderedList → ListNumber
```

Whatever a configuration holds can be read back as the array it came from, which
is what to write into a config file, to log, or to compare:

```php
$config->toArray();   // ['styles' => [...], 'options' => [...]]
```

`Configuration\Styles` and `Configuration\Options` have a `toArray()` of their own,
and so does `Reverse\Options` — which is how the reader's options are spelled as
an array in the first place.

### Styles

Each slot holds a styleId, an inline style array, or `null`.

| Slot | Default | Controls |
| --- | --- | --- |
| `heading.1` … `heading.6` | `Heading1` … `Heading6` | headings |
| `paragraph` | `null` | body text |
| `blockQuote` | `IntenseQuote` | `>` blocks |
| `codeBlock` | `null` | fenced code paragraphs |
| `thematicBreak` | `null` | `---` |
| `listParagraph` | `null` | list item paragraphs |
| `htmlFallback` | `null` | raw HTML |
| `codeFont` | Consolas 9pt, dark red | `` `code` `` |
| `linkFont` | blue, underlined | hyperlink text |
| `bulletList` | `MarkdownWord-Bullet` | bullet numbering style name |
| `orderedList` | `MarkdownWord-Ordered` | ordered numbering style name |
| `table` | `null` | table style name |
| `tableHeaderRow` | `null` | header row style |
| `tableCell` | `null` | cell style |

> **StyleIds, not display names.** Word looks a style up by its *identifier*, so
> the built-in headings are `Heading1`, not `Heading 1`, and `IntenseQuote`, not
> `Intense Quote`. Use the identifier your template defines.

A `Styles` object is the table above with your changes in it, and can be used on
its own — through `Configuration::withStyles()`, or wherever the renderer asks a
configuration for a slot:

```php
use MarkdownWord\Configuration\Styles;

$styles = new Styles();                       // every slot at its default
$styles = $styles->with(Styles::HEADING_1, 'CorpTitle');   // a new instance
$styles->get(Styles::HEADING_1);              // 'CorpTitle', the original untouched
$styles->heading(2);                          // the style for a heading level
Styles::defaults();                           // the default table as an array
$styles->toArray();                           // the whole table as an array
```

`heading()` clamps: a level past the sixth answers with the sixth, and a level
below the first with the first, since there is no other heading style to give.

### Options

| Option | Default | Values |
| --- | --- | --- |
| `softBreak` | `space` | `space`, `lineBreak`, `paragraph` |
| `hardBreak` | `line` | `line`, `paragraph`, `remove` |
| `html` | `strip` | how raw HTML is handled: `strip` rebuilds it as Word, `preserve` shows it as monospaced text, `drop` discards it |
| `images` | `embed` | `embed`, `placeholder`, `skip` |
| `imageBasePath` | `null` | directory relative image paths resolve against |
| `imageMaxWidth` | `15.0` | centimetres; `0` disables scaling |
| `maxHeadingLevel` | `6` | deeper headings become paragraphs |
| `orderedListFormat` | `decimal` | any OOXML `w:numFmt`, e.g. `lowerRoman` |
| `orderedListSuffix` | `tab` | `tab`, `space`, `nothing` |
| `tableBorders` | `true` | draw cell borders |
| `tableHeaderBold` | `true` | bold the header row |
| `tableWidth` | `5000` | fiftieths of a percent of the text column; `0` leaves sizing to Word |
| `codeBlockShading` | `true` | shade code blocks |
| `linkTarget` | `_blank` | `_blank` or `_self` |
| `thematicBreak` | `border` | `border` or `text` |
| `deferredHyperlinks` | `false` | resolve links while writing the file |

## Advanced use

Keep the `PhpWord` document and add your own content — a cover page, a
`TOC`, metadata:

```php
$phpWord = new PhpWord();
$converter = new MarkdownToWord(null, $config);

$markdown = "# Chapter one\n\n…";

$section = $phpWord->addSection();
$section->addTitle('Annual Report', 1);

$phpWord->getDocInfo()->setTitle('Annual Report');

// toDocx() takes that same document, renders the Markdown into the section that is
// already there, and hands back the finished bytes.
file_put_contents('report.docx', $converter->toDocx($markdown, $phpWord));
```

`toDocx()` renders the Markdown it is given into the document it is handed, so
that document already has your own content in it. `renderIntoContainer()` is the
same render into a container you name — a table cell, a header, a footer — for
when you are writing the document out yourself:

```php
$converter->renderIntoContainer($markdown, $section, $phpWord);
```

Recover the plain text of a rendered document, for indexing or an accessibility
fallback:

```php
use MarkdownWord\Text\TextExtractor;

TextExtractor::fromPhpWord($phpWord);
```

Use a different Markdown dialect:

```php
use MarkdownWord\Parser\CommonMarkParser;

new MarkdownToWord(null, $config, CommonMarkParser::commonMarkOnly());
new MarkdownToWord(null, $config, CommonMarkParser::extended());      // + footnotes
new MarkdownToWord(null, $config, CommonMarkParser::withAllExtensions());
```

Or implement `MarkdownParserInterface` for anything else.

`parse()` is the parser on its own, for a caller that wants the syntax tree rather
than a document — a linter, a table of contents, a search index:

```php
$tree = (new MarkdownToWord())->parse("# Hello\n\nBody.");   // a CommonMark Document
```

`pendingHyperlinks()` is the other half of the way into Word. A link whose label
carries emphasis cannot be expressed by PHPWord's own element, so the renderer
leaves a placeholder behind and rewrites the file while writing it; this is what
is waiting to be rewritten, and the text of a document is only complete once it
has been handed over:

```php
$converter = new MarkdownToWord();

$phpWord = $converter->toPhpWord('A [**bold** link](https://example.com).');

TextExtractor::fromPhpWord($phpWord, TextExtractor::LINE_BREAK, $converter->pendingHyperlinks());

$converter->pendingHyperlinks();
// [['placeholder' => '⁣MDWL⁣0⁣MDWL⁣', 'url' => 'https://example.com', 'title' => null, 'runs' => [...]]]
```

The `placeholder` is the token the writer looks for, and it is
`LinkPlaceholder::MARKER` on *both* sides of the index — the string above is
`"\u{2063}MDWL\u{2063}" . '0' . "\u{2063}MDWL\u{2063}"`, which is why the
invisible separator appears twice. It is there so that the token can never
collide with anything an author typed, and so that a run carrying it renders as
nothing at all if the writer pass never runs.

## Requirements

PHP 8.2+ with `ext-zip`, `ext-dom`, `ext-mbstring` and `ext-gd`.

Working on the library needs PHP 8.4+, because Pest 5 does. That is a floor for
the *test suite* only: the published package installs on 8.2, and the library is
checked on 8.2 by the `minimum` job, which runs `smoke.php` and `stress.php`
there. The phar is a program with its own dependencies inside it, so the library
passing says nothing about whether the archive runs — `smoke.php` takes the path
to one and runs it in a child process, converting a document through it both
ways:

```sh
composer build:phar
php smoke.php build/mdword.phar
```

## Notes

- **Output escaping is turned on while writing**, and the previous setting is
  restored afterwards. PHPWord has escaping off by default, which is correct for
  content that is already escaped and wrong for everything else: a document
  containing a lone `<` or `&` — `a < b`, `AT&T` — otherwise gets raw markup in
  its XML and Word refuses to open it.
- **Tables span the text column, and so do their columns.** Given no width, PHPWord
  writes no `w:tblW` at all — its writer emits the element only when a width has
  been set — and a table with no width is one every viewer shrinks to its
  narrowest content. This library writes `<w:tblW w:w="5000" w:type="pct"/>` instead,
  as a percentage of the column, so it follows the page size and the margins.
  `tableWidth` changes it, and `0` hands the sizing back to Word.

  The columns inside it are measured too, because a Markdown table has no widths
  to carry and Word does not guess well without help: given an empty
  `<w:gridCol/>` and cells with no `<w:tcW>` it narrows every column to one or
  two characters. Each column is given a width in twips summing to exactly the
  text column, measured from the page the table lands on — its size, orientation,
  margins and column count. The width is shared out in proportion to the widest
  cell in each column, with two limits that matter more than the proportion: a
  column is never left below half an inch, and a column is widened no further
  than about forty characters' worth, so one cell holding a paragraph cannot take
  the table away from its neighbours.

  A `tableCell` style naming its own `unit` keeps its own widths and gets none of
  these — `w:tcW` in twips labelled as a percentage is not a narrower table but an
  unreadable one, and a caller who has measured itself is not overridden.
- **Cells let their text wrap.** PHPWord's cell style defaults `noWrap` to true, so
  a cell that says nothing about wrapping is written as `<w:noWrap/>` — Word's
  "Wrap text" option, with the box ticked off. Word honours it: the cell is laid
  out on one line and the column widened to suit, so a table holding a sentence
  runs off the page with nowhere for the line to break. LibreOffice reads it as a
  hint it may ignore, so the same file looks right there and wrong in Word.

  Every cell is therefore rendered with `['noWrap' => false]`, and a `tableCell`
  style of `['noWrap' => true]` gets the old behaviour back. It is a cell style
  and not an `Options` entry because `noWrap` is a cell property, and the
  `tableCell` slot already takes a PHPWord cell style array — a new option would
  have been a second way to say one thing.

  This was worth fixing on its own account, and it also exposed the missing
  column widths above: `noWrap` had been making Word size columns to their
  content, which papered over their being absent rather than filling them in.
- **PHPWord 1.4 emits a deprecation on PHP 8.1+** (`Using null as an array
  offset`). It comes from `PhpWord\Style::getStyle()` being called with a null
  name while writing a paragraph that carries no numbering of its own, which
  every list item does. The output is correct and nothing reachable from this
  library's API can avoid it, so the command line and the test suite silence
  that one message from that one file rather than switching deprecation
  reporting off. Embedding the library in an application leaves it visible.
- **Hyperlinks around images** are rendered as the image without the link.
  PHPWord's `Link` element holds only a string, so there is nowhere to put it.
- **Image alt text and link titles are written by a pass over the finished
  file.** PHPWord has nowhere to put either through its API — it emits
  `o:title` as a literal empty string — so they are filled in while the archive
  is written. That is the same reason links whose label carries emphasis are
  resolved then rather than by PHPWord's own `Link` element.
- **A document in memory is written to the system temp directory to be read
  back**, because `ext-zip` only opens files. It is removed once the archive is
  closed. The library does not write anywhere near its own install directory, so
  nothing appears in your `vendor/` and the phar works unchanged.
- **Raw HTML never becomes markup in the document.** This is worth stating
  precisely, because the default does more than remove tags: a fragment of HTML
  is parsed and *rebuilt* as Word content. `<b>`, `<i>`, `<s>` and `<code>`
  become the corresponding formatting, `<br>` becomes a line break, comments are
  dropped, and `<img>`, `<hr>` and friends contribute nothing. The words they
  wrapped are kept — which is what makes a document with embedded HTML compare
  equal to the same document rendered as HTML, and is why `strip` is the default
  rather than `drop`.

  What cannot happen is the HTML contributing structure of its own: no attribute,
  event handler, script URL or frame reaches the document, because the tags are
  never copied into the XML — only text is. An attribute carrying what would
  close an element and open a run has nothing to escape into, and an external
  entity is not resolved. A raw `<a href>` does not even become a live link,
  though a Markdown `[link](url)` does. `preserve` keeps the markup as literal
  monospaced text, and `drop` discards the fragment outright.

  One thing this cannot do: style text that sits *outside* the element. CommonMark
  makes `a <b>bold</b> b` five sibling nodes rather than a `b` wrapping a word, so
  the bold is not applied — the text survives unformatted. Write `**bold**` for
  text you want emphasised, and reserve raw HTML for content that brings its own
  markup.

## Licence

MIT — see [LICENSE](LICENSE). PHPWord, which this library builds on, is
LGPL-3.0.

[commonmark]: https://github.com/thephpleague/commonmark
[issues]: https://github.com/fabeat/markdown-word/issues
[sonarcloud]: https://sonarcloud.io/summary/new_code?id=fabeat_markdown-word
[sonar-token]: https://sonarcloud.io/account/security
[pest]: https://pestphp.com
[phpword]: https://github.com/PHPOffice/PHPWord
[cm-spec]: https://spec.commonmark.org/0.31.2/
[gfm-spec]: https://github.github.com/gfm/
