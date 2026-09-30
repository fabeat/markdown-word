<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

/**
 * The input is not a Word document, or not one that can be opened.
 *
 * A `.docx` is a zip archive. Anything else — a Markdown file, a PDF, text, or
 * a filename that is not there — arrives here, and so does a file that claims
 * to be a document and then will not open as one.
 */
class UnreadableDocument extends InvalidInput
{
}
