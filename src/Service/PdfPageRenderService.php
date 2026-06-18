<?php

declare(strict_types=1);

namespace App\Service;

final class PdfPageRenderService
{
    public function renderPages(string $filePath): array
    {
        if ($filePath === '' || !is_file($filePath) || !is_readable($filePath)) {
            return [
                'status' => 'error',
                'page_count' => 0,
                'pages' => [],
                'temp_dir' => null,
                'note' => 'Plik PDF nie istnieje albo nie jest czytelny.',
            ];
        }

        $tempDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'agent_ksef_pdf_pages_' . sha1($filePath . microtime(true) . (string) random_int(1, PHP_INT_MAX));
        if (!is_dir($tempDir) && !@mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
            return [
                'status' => 'error',
                'page_count' => 0,
                'pages' => [],
                'temp_dir' => null,
                'note' => 'Nie udało się przygotować katalogu tymczasowego dla obrazów stron PDF.',
            ];
        }

        $scriptPath = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'agent_ksef_pdf_render_pages.py';
        $script = <<<'PY'
import json
import os
import sys
import pypdfium2 as pdfium

pdf_path = sys.argv[1]
output_dir = sys.argv[2]

doc = pdfium.PdfDocument(pdf_path)
pages = []

for index in range(len(doc)):
    page = doc[index]
    image = page.render(scale=1.5).to_pil().convert("RGB")
    output_path = os.path.join(output_dir, f"page-{index + 1:03d}.jpg")
    image.save(output_path, format="JPEG", quality=72, optimize=True)
    image.close()
    page.close()
    pages.append({
        "page_number": index + 1,
        "image_path": output_path,
    })

payload = {
    "page_count": len(doc),
    "pages": pages,
}

sys.stdout.write(json.dumps(payload))
PY;

        @file_put_contents($scriptPath, $script);

        foreach ($this->pythonCandidates() as $candidate) {
            $command = array_merge($candidate, [$scriptPath, $filePath, $tempDir]);
            $result = $this->runProcess($command);
            if ($result['exit_code'] !== 0 || trim($result['stdout']) === '') {
                continue;
            }

            $decoded = json_decode(trim($result['stdout']), true);
            if (!is_array($decoded)) {
                continue;
            }

            return [
                'status' => 'ok',
                'page_count' => (int) ($decoded['page_count'] ?? 0),
                'pages' => array_values((array) ($decoded['pages'] ?? [])),
                'temp_dir' => $tempDir,
                'note' => 'Strony PDF zostały wyrenderowane do obrazów JPG.',
            ];
        }

        $this->cleanup($tempDir);

        return [
            'status' => 'error',
            'page_count' => 0,
            'pages' => [],
            'temp_dir' => null,
            'note' => 'Nie udało się wyrenderować stron PDF do obrazów.',
        ];
    }

    public function cleanup(?string $tempDir): void
    {
        if ($tempDir === null || !is_dir($tempDir)) {
            return;
        }

        foreach (glob($tempDir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        @rmdir($tempDir);
    }

    private function pythonCandidates(): array
    {
        $candidates = [];

        $env = getenv('PDF_PYTHON_BIN');
        if (is_string($env) && trim($env) !== '') {
            $candidates[] = [trim($env)];
        }

        $knownPath = 'C:\\Users\\Dell\\.cache\\codex-runtimes\\codex-primary-runtime\\dependencies\\python\\python.exe';
        if (is_file($knownPath)) {
            $candidates[] = [$knownPath];
        }

        $candidates[] = ['python'];
        $candidates[] = ['py', '-3'];

        return $candidates;
    }

    private function runProcess(array $command): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => ''];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exit_code' => is_int($exitCode) ? $exitCode : 1,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }
}
