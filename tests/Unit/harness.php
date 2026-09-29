<?php

declare(strict_types=1);

/*
 * The test harness itself.
 *
 * Nothing here is about the library; it is about the two things about the
 * harness that fail silently rather than loudly, so a regression shows up as a
 * test rather than as a slowly filling disk.
 */

/*
 * The suite-wide clean-up has to be registered through `pest()`.
 *
 * In Pest 5 a bare `afterEach()` in tests/Pest.php binds to that file rather than
 * to the suite. Nothing complains: the tests all pass, the temporary files are
 * all written, and they are simply never removed. Reading the source is the only
 * way to notice, so the rule is pinned here.
 */
it('registers the scratch clean-up as a suite-wide hook', function () {
    $bootstrap = (string) file_get_contents(dirname(__DIR__) . '/Pest.php');

    expect($bootstrap)->toContain('pest()->afterEach(');
    expect($bootstrap)->not->toMatch('/^\s*afterEach\(/m');
});

/*
 * And the suite-wide hooks the project needs are only global in Pest 5 through
 * `pest()`. `afterAll` is not one of them — it needs a scope — so a clean-up
 * that has to run once at the end has to be reached another way.
 */
it('keeps its own hooks inside a single test file where they apply to that file', function () {
    $file = (string) file_get_contents(__DIR__ . '/escaping.php');

    // Documenting the file-level usage; the global one is asserted above.
    expect($file)->toContain('beforeEach(fn () => Upstream::install())');
});
