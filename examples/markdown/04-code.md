# Code

Word has no code block, so a fenced block becomes one paragraph per line in a
monospaced font with a light background. The point of the treatment is that the
text survives exactly.

## Inline code

The call is `render($markdown)`, and it returns a `string`. Inline code keeps
its spacing and is never parsed further: `*not italic*` and `**not bold**`.

Code inside other formatting: **bold with `code` inside** and
*italic with `code` inside*.

## Fenced, with a language

The language is recorded as an info string. The renderer does not syntax
highlight — Word would need a highlighting engine for that — but the info string
is available if you want to wire one up.

```php
<?php

declare(strict_types=1);

final class MarkdownToWord
{
    public function save(string $markdown, string $path): void
    {
        // Ampersands and angle brackets have to survive the trip into OOXML.
        $phpWord = $this->toPhpWord($markdown);
        $this->writer->write($phpWord, $path);

        if ($a < $b && $b > $c) {
            throw new RuntimeException('out of range');
        }
    }
}
```

```sql
SELECT region, SUM(revenue) AS total
FROM sales
WHERE revenue > 1000 AND region <> 'EMEA'
GROUP BY region;
```

## Fenced, without a language

```
Nothing is highlighted here; the text is what matters.
    Indentation is preserved.
	And so are tabs.
```

## A fence that is longer than three backticks

````markdown
```php
// This inner fence is part of the code, not a delimiter.
$example = '```';
```
````

## Indented code

An indented code block, which is four spaces of leading whitespace:

    function legacy() {
        return "kept exactly as written";
    }

## Blank lines and empty lines

A fenced block can contain blank lines, and they are kept as empty paragraphs
so the vertical rhythm of the source survives:

```
function a() {
    return 1;
}

function b() {
    return 2;
}
```

## A tilde fence

~~~text
The ~~~ fence is also valid CommonMark.
~~~
