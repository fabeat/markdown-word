<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

/**
 * The input is not a Word document, or not one that can be opened. A `.docx` is
 * a zip archive, so a Markdown file, a PDF, plain text or a filename that is not
 * there all arrive here. One that opens and then turns out to be broken is a
 * {@see MalformedDocument}.
 */
class UnreadableDocument extends InvalidInput
{
}
