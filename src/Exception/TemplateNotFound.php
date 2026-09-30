<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

/**
 * The caller named a template that is not there, and naming a different one is
 * the whole of the fix — which is what makes this an {@see InvalidInput}. A
 * template that is fine failing for a different reason is the machine's, and
 * that is a {@see FileNotWritable}.
 */
final class TemplateNotFound extends InvalidInput
{
}
