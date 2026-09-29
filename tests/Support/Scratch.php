<?php

declare(strict_types=1);

namespace MarkdownWord\Tests\Support;

use RuntimeException;

/**
 * A place for the files a test creates, and their removal.
 *
 * Everything lands in the project's own `tmp` directory rather than the system
 * temp directory, so a failing test leaves its documents where they can be opened
 * and looked at instead of somewhere that gets swept away. The directory is
 * ignored by git.
 */
final class Scratch
{
    /** @var array<string, true> Paths created since the last clean-up. */
    private static array $paths = [];

    /**
     * The directory documents are written to, created if it is not there.
     */
    public static function directory(): string
    {
        $directory = dirname(__DIR__, 2) . '/tmp';

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create the scratch directory "%s".', $directory));
        }

        return $directory;
    }

    /**
     * A path for a document the caller is about to write, remembered so that it
     * can be removed when the test ends.
     */
    public static function path(string $prefix = 'docx', string $extension = '.docx'): string
    {
        $path = self::directory() . '/' . $prefix . '-' . bin2hex(random_bytes(6)) . $extension;

        self::$paths[$path] = true;

        return $path;
    }

    /**
     * Remember a path that something else created.
     */
    public static function remember(string $path): string
    {
        self::$paths[$path] = true;

        return $path;
    }

    /**
     * Empty the scratch directory.
     *
     * The whole directory rather than only the paths that were remembered,
     * because a test cannot know every file it caused to be written: a converter
     * that names its output after its input creates a path nobody asked for, and
     * a media directory appears out of nowhere. A run that leaked those would
     * fill the disk quietly, which is worse than losing a scratch file after a
     * failure — the names are random either way, so there is little to look at.
     */
    public static function cleanUp(): void
    {
        self::$paths = [];

        $entries = scandir(self::directory());

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = self::directory() . '/' . $entry;

            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
    }

    private static function removeTree(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }

        @rmdir($directory);
    }

    /**
     * A small image, for the tests that need a real file to point at.
     */
    public static function image(string $name = 'fixture.png'): string
    {
        $path = self::remember(self::directory() . '/' . $name);

        if (is_file($path)) {
            return $path;
        }

        $size = 24;
        $image = imagecreatetruecolor($size, $size);
        $background = imagecolorallocate($image, 0x8B, 0x1A, 0x1A);
        $ink = imagecolorallocate($image, 0xFF, 0xF3, 0xF3);

        imagefilledrectangle($image, 0, 0, $size, $size, $background);
        imagerectangle($image, 1, 1, $size - 2, $size - 2, $ink);
        imagepng($image, $path);

        return $path;
    }
}
