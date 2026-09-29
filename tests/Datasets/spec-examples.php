<?php

declare(strict_types=1);

use MarkdownWord\Tests\Support\SpecFile;

/**
 * Loads a specification file's examples as a dataset of one argument each.
 *
 * The keys are the example numbers, so a failing run says which example it was
 * without anyone having to count.
 *
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
