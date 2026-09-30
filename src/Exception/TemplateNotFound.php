<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

/**
 * The Word document named as a template is not there.
 *
 * A {@see InvalidInput} because the caller named a file that does not exist and
 * naming a different one is the whole of the fix — the same shape as
 * {@see UnreadableFile} and {@see UnreadableDocument} for the other ways a file
 * turns out not to be what the conversion needed.
 *
 * Everything else that can go wrong while a template is being rendered — a
 * staging file that cannot be created, a directory that cannot be made, a
 * document that cannot be written — is a {@see FileNotWritable} instead, which
 * is what those are: the template was fine and the machine was not.
 */
final class TemplateNotFound extends InvalidInput
{
}
