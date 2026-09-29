# Links and images

## Plain links

A [plain link](https://example.com).

A link with a [title](https://example.com "which appears as a tooltip").

## Links whose label is formatted

This is the interesting case. Word models a hyperlink as a `w:hyperlink`
element wrapping runs, so a label with formatting inside it needs more than one
run. PHPWord's `Link` element only holds a single string, so the renderer emits
a placeholder and rewrites `word/document.xml` while writing the file.

A link with [**bold**](https://example.com/bold) text.

A link with [*italic*](https://example.com/italic) text.

A link with [***both***](https://example.com/both) and with
[**bold**, *italic* and `code`](https://example.com/mixed).

A link whose label is [an image](https://example.com/image).

## Reference links

Defined at the bottom of the file, they resolve the same way.

A [reference link][one] and [another][two], plus a [collapsed][] one and a
[shortcut] one.

## Autolinks

An angle-bracket autolink: <https://example.com/autolink>.

An email autolink: <someone@example.com>.

And the GitHub flavour's bare-URL extension, which turns this into a link too:
https://example.com/bare-url

## Escaping

A link that is not a link: \[not a link](/foo)

A reference that is not a reference: [foo][bar]

## Images

An image with alt text, scaled to fit the page width:

![A small red square, generated for this example](assets/logo.png)

An image on its own line:

![Square](assets/logo.png)

An image with an empty alt text, which is the Markdown convention for a purely
decorative image:

![](assets/logo.png)

## Images that cannot be found

The file does not exist, so rather than emitting a broken reference the renderer
falls back to the alt text:

![A missing image](assets/does-not-exist.png)

The same happens for a remote URL, which cannot be fetched without a network
client:

![A remote image](https://example.com/not-fetched.png)
