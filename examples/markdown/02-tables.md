# Tables

GFM pipe tables, which the GitHub flavour of Markdown adds to CommonMark.

## Alignment comes from the delimiter row

The colons in `|---|:---:|---:|` are what decide the alignment of each column.

| Left | Centre | Right |
|:-----|:------:|------:|
| a    | b      | c     |
| dd   | ee     | ff    |
| ggg  | hhhh   | iiii  |

## No colons means left

| Column one | Column two |
|------------|------------|
| value      | value      |

## Formatting inside cells

| Construct | Renders as | Notes |
|-----------|:----------:|-------|
| **bold** | bold | `**bold**` |
| *italic* | italic | `*italic*` |
| `code` | code | backticks |
| ~~strike~~ | struck | `~~strike~~` |
| [a link](https://example.com) | a link | works in cells |

## Empty cells

| Name | Value | Comment |
|------|:-----:|---------|
| Alpha | 42 | |
| Beta | | not set |
| | 7 | no name |

## A cell spanning several source lines

| Column | Value |
|--------|-------|
| A long<br>wrapped<br>cell | fine |
