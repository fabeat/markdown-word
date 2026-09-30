# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-09-30

First release.

### Added

- `MarkdownWord\MarkdownToWord` and `MarkdownWord\WordToMarkdown`, converting
  Markdown to Word (`.docx`) and back on `phpoffice/phpword` and
  `league/commonmark`. Full CommonMark, with GitHub-Flavored Markdown available
  through `CommonMarkParser`.
- `MarkdownWord\Converter`, one interface both directions implement, so a caller
  that does not care which way the data has to go does not have to care.
- `Configuration\Options`, `Reverse\Options` and `Configuration\Styles` for the
  rendering, the reading and the styles. Every option has a `withX()` method, and
  each one carries the rest of the configuration forward.
- `Template\MarkdownTemplate`, for rendering into a Word template and inserting
  the result at a named region.
- The `mdword` command line utility, also shipped as a standalone
  `mdword.phar` with no dependencies to install.
- A conformance suite: 654 CommonMark and 646 GitHub-Flavored Markdown examples,
  compared as text against commonmark's own HTML.
- Resource limits on reading a document — `maxPartBytes`, `maxEntries` and
  `maxStyleDepth` — so a hostile archive cannot exhaust memory or recurse
  without end. All three are options, and all three default to something
  reasonable rather than to nothing.

### Known limitations

The round trip preserves what Word was told to keep and loses the rest, in ways
that are documented rather than hidden. The three worth knowing before you rely
on it:

- a fenced code block comes back without its language, and a one-line block comes
  back as an inline span rather than as a block;
- a table is written with a header whether the document marked one or not, and
  reads back with that header emphasised;
- column alignment in a table is not preserved.

`README.md` has the full list, and `Reverse\Options` is where each one is
configurable.

[0.1.0]: https://github.com/fabeat/markdown-word/releases/tag/v0.1.0
