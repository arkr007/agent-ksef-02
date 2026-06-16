<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    public function __construct(
        private string $templatePath,
        private array $shared = []
    ) {
    }

    public function render(string $template, array $data = [], ?string $layout = 'layout'): string
    {
        $payload = array_merge($this->shared, $data);
        $templateFile = $this->templatePath . '/' . $template . '.php';

        if (!is_file($templateFile)) {
            throw new \RuntimeException(sprintf('Brakuje widoku %s.', $template));
        }

        extract($payload, EXTR_SKIP);

        ob_start();
        require $templateFile;
        $content = (string) ob_get_clean();

        if ($layout === null) {
            return $content;
        }

        $layoutFile = $this->templatePath . '/' . $layout . '.php';
        if (!is_file($layoutFile)) {
            throw new \RuntimeException(sprintf('Brakuje layoutu %s.', $layout));
        }

        ob_start();
        require $layoutFile;

        return (string) ob_get_clean();
    }
}
