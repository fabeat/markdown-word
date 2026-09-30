<?php

declare(strict_types=1);

use MarkdownWord\Tests\Support\SpecFile;

/**
 * @return array<string, array{MarkdownWord\Tests\Support\SpecExample}>
 */
function specExamples(string $path, string $name): array
{
    $dataset = [];

    foreach (SpecFile::load($path, $name)->examples as $example) {
        $dataset[$name . ' #' . $example->number] = [$example];
    }

    return $dataset;
}
