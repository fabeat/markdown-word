# Block quotes

## A simple quote

> Everything inside the markers is a quote, and it keeps its formatting:
> **bold**, *italic*, `code`, and a [link](https://example.com).

## Several paragraphs in one quote

> The first paragraph.
>
> The second paragraph, after a blank line.

> The third.

## Nesting

> A quote at the first level.
>
> > A quote at the second level, indented further.
> >
> > > And a third level.

## Other block content

> A quote can contain a list:
>
> - one
> - two
> - three
>
> And a code block:
>
> ```
> $inside = "a quote";
> ```

> And a heading is rendered as an emphasised paragraph rather than a real
> heading, so it does not break out of the quote:
>
> ### Not a heading here

## Lazy continuation

The markers only have to be on the first line:

> This quote runs
over several source lines
without a marker on each one.

## An empty quote

>
