<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

/**
 * A Word document that opened but is not one this library can use.
 *
 * The archive is intact and something inside it is not: a part that every
 * document is expected to have is missing, or the XML in one does not parse.
 * That is a different situation from a file that is not a document at all, and
 * the two are worth telling apart when deciding whether a file is salvageable.
 */
final class MalformedDocument extends UnreadableDocument
{
}
