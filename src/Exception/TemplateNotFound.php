<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

/**
 * The caller named a template that is not there, and naming a different one is
 * the whole of the fix — which is what makes this an {@see InvalidInput}.
 *
 * Everything else that can go wrong while a template is rendered is a
 * {@see FileNotWritable}: the template was fine and the machine was not.
 */
final class TemplateNotFound extends InvalidInput
{
}
