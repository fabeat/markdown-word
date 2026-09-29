<?php

declare(strict_types=1);

namespace MarkdownWord;

use RuntimeException;

/**
 * Where the input of a conversion came from.
 *
 * A string that names a file that exists is read from it; anything else is the
 * content itself. That is the same rule the command line works by, where the
 * direction is worked out from the file rather than from its name, so the two do
 * not surprise each other.
 *
 * The two formats cannot be told apart the same way, and the difference matters.
 * Any text is Markdown, so a string that is not a file is taken as the content
 * without complaint. A Word document is a zip archive beginning `PK\x03\x04`, so
 * a string that is neither a file nor an archive is an error rather than
 * something to be guessed at.
 */
final class Input
{
    /** The first four bytes of a zip archive, and therefore of a `.docx`. */
    public const DOCUMENT_MAGIC = "PK\x03\x04";

    /**
     * Markdown: the file if the string names one, otherwise the text.
     */
    public static function markdown(string $input): string
    {
        return is_file($input) ? self::read($input) : $input;
    }

    /**
     * A Word document: the file if the string names one, otherwise the bytes —
     * but only if they really are an archive.
     */
    public static function document(string $input): string
    {
        if (str_starts_with($input, self::DOCUMENT_MAGIC)) {
            return $input;
        }

        if (is_file($input)) {
            return self::read($input);
        }

        throw new RuntimeException(
            'The input is neither a Word document nor the name of one. '
            . 'A .docx is a zip archive, so its first four bytes are "PK".',
        );
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read "%s".', $path));
        }

        return $contents;
    }
}
