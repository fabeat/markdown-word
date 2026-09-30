# Code documentation

What to write, what to delete, and what never to touch. Kept short on purpose:
a policy nobody reads costs more than the comments it governs.

## Principles

1. **Clarity over ceremony.** Structure already organises code. A divider above
   the methods it divides adds nothing.
2. **Token efficiency.** Every comment is re-read on every pass over the file,
   by a human or an agent. Make each word carry its weight.
3. **Why, not what.** The code says what it does. A comment earns its place by
   saying what the code cannot.
4. **Code first.** If a name can carry the meaning, the name does, and the
   comment goes.

## Delete

**Dividers.** Any line of dashes, equals or asterisks, however short.

```php
// ------------------------------------------------------------------ tables   DELETE
// ---------------------------------------- inlines                          DELETE
// -------- helpers                                                          DELETE
```

Method names are the table of contents. Where a run of methods genuinely belongs
together, one plain line is enough: `// Style resolution.`

**Restatement.** Anything a reader derives from the code below it.

```php
// Loop over the paragraphs   DELETE
// Return the result          DELETE
// Set the flag               DELETE
```

**Type-only docblocks.** A docblock whose whole content is `@param string $x`
for a natively-typed `string $x` — see *Keep* for the exception, because the
exception is common here.

**Numbers that rot.** A count of findings, of files, of lines. It is wrong the
moment one is fixed, and nobody notices. Say what the number was *for*.

## Keep

**Dependency and platform quirks.** The highest-value comments in this codebase,
because they are true of something other than us and nobody can derive them from
the code.

```php
/**
 * Parsing the XML out of a `.docx`, in one place.
 *
 * The libxml flags live here so they cannot differ between callers, and
 * `LIBXML_NONET` keeps a document from reaching the network — it was missing
 * from one of these call sites once.
 */
```

_(from `src/Xml.php`)_

Others worth knowing about, all of them load-bearing: PHPWord 1.4 writes VML
only; `Style::getStyle()` ignores a duplicate registration; `Settings::
$outputEscapingEnabled` defaults false; `setStyleValues()` adopts a same-class
`AbstractStyle` in place of its own, losing the paragraph binding.

**Security rationale.** A limit and the threat it bounds.

```php
// A zip says nothing about how much room its contents will take up, so the
// limit is on the decompressed size of one part, not on the archive.
```

Never drop these to save space. `maxPartBytes`, `maxEntries` and `maxStyleDepth`
each exist because a document can make the reader exhaust memory, read the
central directory forever, or recurse without end. A reader who does not know
that will "simplify" one of them away.

**Invariants a reviewer would otherwise break.** Lists that must stay in step
with the code, ordering requirements, and the one place a rule is enforced.

```php
/**
 * The properties where `null` is a value in its own right rather than the
 * absence of one; everywhere else it means "not configured" — or, in
 * {@see self::withAll()}, "not mentioned" — and the value that is already
 * there stands.
 *
 * {@see \MarkdownWord\Configuration\Options} keeps the same list for the same
 * reason, and a property added to one of them without a line here is a
 * property whose `null` is handled wrongly.
 */
```

**`@throws` naming a real type.** Especially where the interface promises the
set: `Converter::convert()` listing all five is the contract, and it is the first
thing a consumer reads.

**Array shapes that narrow a native type.** `array` cannot say this; the
docblock is the only place it can:

```php
@param list<array{placeholder: string, url: string, title: ?string, runs: list<array{text: string, style: array<string, mixed>}>}> $payloads
```

`HyperlinkPass`, `LinkPayloadCollector` and `TextExtractor` all speak this shape.
It is a contract, not restatement — **keep it even though a type-only-docblock
rule would otherwise delete it.**

**Fidelity, stated once.** What the round trip loses is the single most useful
comment in a bidirectional converter. `src/WordToMarkdown.php` is its home;
`README.md` is the other, and both must agree.

## Quick reference

| Situation                                    | Action    |
| -------------------------------------------- | --------- |
| `// ----` or `// ======` divider               | DELETE    |
| Restates the line below it                    | DELETE    |
| `@param string` for a native `string`         | DELETE    |
| A count of files, findings or lines           | DELETE    |
| Explains _why_ a guard or a limit exists      | KEEP      |
| A dependency's or Word's actual behaviour     | KEEP      |
| Two lists that must stay in step              | KEEP      |
| `@throws` naming a real type                  | KEEP      |
| An array shape narrower than the native type  | KEEP      |
| What a round trip does not preserve           | KEEP once |
| `tests/`, `tools/` and root scripts           | same rules |

## Decision tree

1. **Can a name say it instead?** Rename, then the comment is unnecessary.
2. **Is it _why_?** If not, delete it.
3. **Would a developer or an agent get it wrong without this?** If not, delete it.
4. **Would deleting it let someone "simplify" a fix into a bug?** If so, keep it
   and make it shorter instead.

## Enforced

`tests/Unit/dead-code.php` greps `src/` for phrasing that has already been
wrong, so a claim cannot come back after it has been corrected once. Its cases
are the list above, made mechanical. **When you delete a wrong comment, add its
phrase there** — that is the whole maintenance cost, and it is one line.

Adding a new one is a test:

```php
it('does not claim again what the code does not do', function (string $claim) {
    expect(sourcesWithClaim($claim))->toBe([]);
})->with([
    'a table's header is bold because the renderer made it so' => 'used for code block shading',
]);
```

The first argument is the case, the second is the phrase that must not reappear.

## Tests

Gate is **91%** (`composer.json`, and both CI jobs that measure it). Four traps,
each of which has cost a real bug here:

- **Never compare `.docx` bytes.** A zip stores 2-second timestamps and
  `docProps/core.xml` carries `dcterms:created`/`modified`, so two correct
  conversions differ about three times in four. Compare parts.
- **Assert preservation, not defaults, for the option objects.** Set every
  property away from the default, change one, assert the rest survived. Against
  the defaults, a `with()` that discards everything passes.
- **A method with no caller outside `tests/` is not exercised.** It is dead, or
  public API nobody documented. Say which.
- **A new test must fail without its fix.** Run it against the reverted change.
  One that passes either way proves nothing.

## Static analysis

PHPStan **level 1** over `src`, `bin`, `tools`, `tests`; fetched by checksum, not
a dependency. **No `ignoreErrors`, no baseline, no suppression comment** — a clean
level 1 is why the level is 1. Fix the finding or leave the level alone.

## Sonar

Runs on PRs, gated on `sonar.qualitygate.status`; config in
`sonar-project.properties`. Two things to know:

- **The gate is the check. Missing diff annotations mean nothing** — they need the
  SonarCloud GitHub App, which is a UI setting, not a file here.
- **It does not read prose.** A confidently false docblock passes. Only
  `tests/Unit/dead-code.php` checks claims, and only ones it has been told.
