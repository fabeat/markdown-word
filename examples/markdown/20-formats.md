# One document, three formats

Every construct on this page is converted three times, so the differences
between `.docx`, `.odt` and `.rtf` can be looked at rather than taken on trust.

- `examples/out/20-formats.docx`, `20-formats.odt` and `20-formats.rtf`.

Open all three side by side. The `.rtf` has no list in it at all, the `.odt`
has bullets where the numbers should be, and neither of the two has a rule
under the `---` or a border round the table. The conversion printed a line on
standard error for each of those.

## What is in the page

Paragraphs with **bold**, *italic*, ~~strikethrough~~ and `inline code`, a
[plain link](https://example.com) and a link whose label is
[**bold** like this one](https://example.com/b) — the second of those is the
case that needs a second pass over the file, because a link element holds one
plain string.

- A bullet
- Another bullet
  - Nested under the first
    - And one more level

1. An ordered item
2. And the next one
   1. Nested under it

| Column | Value |
|:-------|------:|
| alpha  |     1 |
| beta   |     2 |

> A block quote, which is indented in all three and keeps its italics only
> in the `.docx`.

---

```php
$this->is_a_code_block = true;
```

![The house mark](assets/logo.png)