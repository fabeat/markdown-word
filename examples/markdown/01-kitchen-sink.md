# Kitchen sink

Every construct this library handles, on one page. Open `01-kitchen-sink.docx`
next to this file to compare.

## Text formatting

**Bold**, *italic*, ***bold italic***, ~~strikethrough~~, `inline code`, and a
combination: **bold with *nested italic* and `code` inside**.

Escaped characters stay literal: \*not emphasis\*, \_not italic\_, and so does
a backtick: \`not code\`.

Entities are resolved: AT&amp;T, 5 &lt; 6, &#65; &copy; 2024.

Raw punctuation that trips up naive converters: a < b && b > c, "quotes",
' apostrophes', 50% — and an em dash — plus non-ASCII: Grüße, 日本語, 🎉.

## Line breaks

This line ends with a hard break (two spaces).
So this is a new line in the same paragraph.

This sentence is
wrapped across a
soft break, which becomes a single space.

## Links

A plain [link to the CommonMark site](https://commonmark.org).

A link with **bold** and *italic* in the label — this one needs a real
`w:hyperlink` wrapping several runs, which the writer assembles on the way out.

A [link with `code` in it](https://example.com/code).

An autolink: <https://php.net>.

A [reference link][spec], defined at the bottom of this file.

## Lists

- Bullet one
- Bullet two
  - Nested bullet
    - Deeper still
      - And deeper
- Back to the top level

1. First
2. Second
   1. Nested number
   2. Another nested
3. Third

A list starting at five, which needs its own numbering definition:

5. Five
6. Six

- Item with **bold**, *italic* and `code`
- Item with a [link](https://example.com)
- Item with a hard break
  and a second line

## Block quotes

> A simple quote.

> A quote with **formatting**, a [link](https://example.com), and a list:
>
> - one
> - two
>
> > And a nested quote inside it.

## Code

Inline `code` sits in a sentence.

    This is an indented code block,
    with its leading whitespace preserved.

```php
final class Example
{
    public function render(string $markdown): string
    {
        // Ampersands and angle brackets: a < b && c > d
        return str_replace('&', '&amp;', $markdown);
    }
}
```

```
No language, no highlighting — the text is still preserved exactly.
```

## Tables

| Feature | Status | Since | Notes |
|:--------|:------:|------:|:------|
| Parser | Done | 1.0 | Full CommonMark |
| Tables | Done | 1.0 | Left column |
| Writer | Done | 1.0 | Right column |
| Templates | Done | 1.1 | — |

A table cell can hold **formatting**, `code`, and even a second line
via a `<br>`.

## Horizontal rule

Above and below:

---

## Raw HTML

A block of HTML has its tags removed but keeps its words:

<div class="notice">
  <p>This paragraph is inside a div.</p>
</div>

Inline <b>bold</b> and <em>italic</em> HTML also keeps its words.

## Escapes and entities in a code block

```
&amp; &lt; &#65; \*not emphasis\* ${not a macro}
```

[spec]: https://spec.commonmark.org/ "The CommonMark specification"
