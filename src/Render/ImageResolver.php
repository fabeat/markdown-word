<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use MarkdownWord\Configuration\Options;
use PhpOffice\PhpWord\Element\AbstractContainer;
use Throwable;

/**
 * Resolves Markdown image nodes to embedded images, alt text, or nothing at all.
 */
final class ImageResolver
{
    public function __construct(
        private readonly Options $options,
        private readonly ImageDescriptionCollector $descriptions,
    ) {
    }

    public function render(Image $node, AbstractContainer $target, string $alt, InlineStyle $style): void
    {
        if ($this->options->images === Options::IMAGE_SKIP) {
            return;
        }

        // A hyperlink around an image cannot be expressed with PHPWord's Link
        // element, which holds one string of text. The image is still rendered;
        // the link is not attached to it.
        $style = $style->isLink() ? new InlineStyle() : $style;

        $url = $node->getUrl();
        $path = $this->resolvePath($url);

        if ($this->options->images === Options::IMAGE_EMBED && $path !== null && $this->embed($path, $target)) {
            $this->descriptions->add($alt);
            $target->addText(' ');

            return;
        }

        // Placeholder mode, or an image that could not be embedded because it is
        // remote or unreadable. The alt text stands in either way, so nothing is
        // dropped silently, and placeholder mode adds the source after it.
        if ($alt !== '') {
            $target->addText($alt, $style->withItalic());
        }

        if ($url !== '' && $this->options->images === Options::IMAGE_PLACEHOLDER) {
            $target->addText($alt !== '' ? sprintf(' (%s)', $url) : $url);
        }
    }

    /**
     * Add the image, reporting whether it could be embedded.
     *
     * PHPWord validates in the element constructor and throws for anything it
     * cannot handle, so the decision is delegated to it: an unreadable or
     * unsupported file falls back to the alt text rather than aborting the
     * document.
     */
    private function embed(string $path, AbstractContainer $target): bool
    {
        try {
            $target->addImage($path, $this->imageStyle());

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The local path a Markdown image URL refers to, or null when it is remote or
     * missing.
     */
    private function resolvePath(string $url): ?string
    {
        if ($url === '' || $url === '/') {
            return null;
        }

        // A remote URL cannot be fetched without network access, and the library
        // deliberately has no HTTP client.
        if (preg_match('#^(https?|ftp|//)#i', $url) === 1) {
            return null;
        }

        if ($this->options->imageBasePath === null) {
            return is_file($url) ? $url : null;
        }

        $candidate = rtrim($this->options->imageBasePath, '/\\') . DIRECTORY_SEPARATOR . ltrim($url, '/\\');

        return is_file($candidate) ? $candidate : null;
    }

    private function imageStyle(): array
    {
        $style = ['alignment' => 'left'];

        if ($this->options->imageMaxWidth > 0.0) {
            $style['width'] = $this->options->imageMaxWidth . 'cm';
        }

        return $style;
    }
}
