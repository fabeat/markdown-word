# Lists

## Nesting

Each level is a real Word list level, so the markers and the indentation
follow the Markdown rather than being faked with spaces and characters.

- Level one, bullet
  - Level two, bullet
    - Level three, bullet
      - Level four, bullet
        - Level five, bullet
- Back to level one

1. Level one, decimal
   1. Level two, decimal
      1. Level three, decimal
2. Back to level one

## Alternating kinds

- A bullet
  1. containing an ordered list
     - which contains a bullet again
- Back to bullets

## Custom start values

The list below starts at five, so it gets its own numbering definition rather
than sharing the one that starts at one:

5. Five
6. Six
7. Seven

And a parenthesised delimiter, which is a different Word numbering format:

1) First
2) Second

## Formatting and links inside items

- **Bold** at the start of an item
- *Italic* in the middle of an item
- `code` at the end of an item
- An item with a [link](https://example.com) in it
- An item with a hard break
  and a second line
- An item with an image: ![logo](assets/logo.png)

## Loose versus tight

A tight list, where the items run together with no blank lines:

- One
- Two
- Three

The same list made loose, by putting a blank line between the items. Word
paragraphs get their normal spacing:

- One

- Two

- Three

## An empty item

-
- Not empty
