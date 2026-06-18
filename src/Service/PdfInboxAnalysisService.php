<?php

declare(strict_types=1);

namespace App\Service;

final class PdfInboxAnalysisService
{
    public function extractTextPayload(string $filePath): array
    {
        if ($filePath === '' || !is_file($filePath) || !is_readable($filePath)) {
            return [
                'status' => 'error',
                'page_count' => null,
                'text' => '',
                'page_texts' => [],
                'note' => 'Plik nie istnieje albo nie jest czytelny.',
            ];
        }

        $extracted = $this->extractWithPythonPypdf($filePath);
        if ($extracted !== null) {
            $normalizedText = $this->normalizeText((string) ($extracted['text'] ?? ''));

            return [
                'status' => $normalizedText !== '' ? 'text_ready' : 'scan_like',
                'page_count' => $extracted['page_count'] ?? null,
                'text' => $normalizedText,
                'page_texts' => $this->normalizePageTexts((array) ($extracted['page_texts'] ?? [])),
                'note' => $normalizedText !== ''
                    ? 'Plik nadaje się do dalszego rozpoznawania treści.'
                    : 'Nie znaleziono tekstu. Ten plik prawdopodobnie będzie wymagał OCR lub analizy AI.',
            ];
        }

        $rawContent = file_get_contents($filePath);
        if ($rawContent === false || $rawContent === '') {
            return [
                'status' => 'error',
                'page_count' => null,
                'text' => '',
                'page_texts' => [],
                'note' => 'Nie udało się odczytać zawartości pliku PDF.',
            ];
        }

        $normalizedText = $this->normalizeText($this->extractPdfText($rawContent));

        return [
            'status' => $normalizedText !== '' ? 'text_ready' : 'scan_like',
            'page_count' => $this->countPdfPages($rawContent),
            'text' => $normalizedText,
            'page_texts' => $normalizedText !== '' ? [$normalizedText] : [],
            'note' => $normalizedText !== ''
                ? 'Plik nadaje się do dalszego rozpoznawania treści.'
                : 'Nie znaleziono tekstu. Ten plik prawdopodobnie będzie wymagał OCR lub analizy AI.',
        ];
    }

    public function analyze(array $pdfFiles): array
    {
        $documents = [];
        $summary = [
            'document_count' => 0,
            'text_ready_count' => 0,
            'scan_like_count' => 0,
            'error_count' => 0,
        ];

        foreach ($pdfFiles as $index => $file) {
            if (!is_array($file)) {
                continue;
            }

            $analysis = $this->analyzeSinglePdf((string) ($file['path'] ?? ''));
            $documents[] = $file + $analysis + ['position' => $index + 1];
            $summary['document_count']++;

            $status = (string) ($analysis['analysis_status'] ?? 'error');
            if ($status === 'text_ready') {
                $summary['text_ready_count']++;
                continue;
            }

            if ($status === 'scan_like') {
                $summary['scan_like_count']++;
                continue;
            }

            $summary['error_count']++;
        }

        return [
            'analyzed_at' => date('Y-m-d H:i:s'),
            'documents' => $documents,
            'summary' => $summary,
        ];
    }

    private function analyzeSinglePdf(string $filePath): array
    {
        $payload = $this->extractTextPayload($filePath);
        $status = (string) ($payload['status'] ?? 'error');

        if ($status === 'error') {
            return [
                'analysis_status' => 'error',
                'analysis_status_label' => 'Błąd odczytu',
                'analysis_badge_class' => 'error',
                'page_count' => $payload['page_count'] ?? null,
                'text_preview' => '',
                'analysis_note' => (string) ($payload['note'] ?? 'Błąd odczytu pliku PDF.'),
            ];
        }

        if ($status === 'text_ready') {
            $normalizedText = (string) ($payload['text'] ?? '');

            return [
                'analysis_status' => 'text_ready',
                'analysis_status_label' => 'Warstwa tekstowa',
                'analysis_badge_class' => 'ok',
                'page_count' => $payload['page_count'] ?? null,
                'text_preview' => $this->preview($normalizedText),
                'analysis_note' => (string) ($payload['note'] ?? 'Plik nadaje się do dalszego rozpoznawania treści.'),
            ];
        }

        return [
            'analysis_status' => 'scan_like',
            'analysis_status_label' => 'Skan lub pusty PDF',
            'analysis_badge_class' => 'warn',
            'page_count' => $payload['page_count'] ?? null,
            'text_preview' => '',
            'analysis_note' => (string) ($payload['note'] ?? 'Nie znaleziono tekstu.'),
        ];
    }

    private function extractWithPythonPypdf(string $filePath): ?array
    {
        $scriptPath = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'agent_ksef_pdf_inbox_extract.py';
        $script = <<<'PY'
import base64
import json
import sys
from pypdf import PdfReader

path = sys.argv[1]
reader = PdfReader(path)
pages = len(reader.pages)
page_texts = [page.extract_text(extraction_mode="layout") or "" for page in reader.pages]
text = "\n".join(page_texts)
payload = {
    "page_count": pages,
    "text_b64": base64.b64encode(text.encode("utf-8")).decode("ascii"),
    "page_texts_b64": [base64.b64encode(page_text.encode("utf-8")).decode("ascii") for page_text in page_texts],
}
sys.stdout.write(json.dumps(payload))
PY;

        @file_put_contents($scriptPath, $script);

        foreach ($this->pythonCandidates() as $candidate) {
            $command = array_merge($candidate, [$scriptPath, $filePath]);
            $result = $this->runProcess($command);
            if ($result['exit_code'] !== 0 || trim($result['stdout']) === '') {
                continue;
            }

            $decoded = json_decode(trim($result['stdout']), true);
            if (!is_array($decoded)) {
                continue;
            }

            $text = base64_decode((string) ($decoded['text_b64'] ?? ''), true);
            $pageTexts = [];
            foreach ((array) ($decoded['page_texts_b64'] ?? []) as $encodedPageText) {
                $decodedPageText = base64_decode((string) $encodedPageText, true);
                $pageTexts[] = is_string($decodedPageText) ? $decodedPageText : '';
            }

            return [
                'page_count' => isset($decoded['page_count']) ? (int) $decoded['page_count'] : null,
                'text' => is_string($text) ? $text : '',
                'page_texts' => $pageTexts,
            ];
        }

        return null;
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

    private function extractPdfText(string $content): string
    {
        preg_match_all('/(<<.*?>>)?\s*stream\s*(.*?)\s*endstream/s', $content, $matches, PREG_SET_ORDER);
        $chunks = [];

        foreach ($matches as $match) {
            $dictionary = (string) ($match[1] ?? '');
            $stream = (string) ($match[2] ?? '');
            $decoded = $this->decodeStream($stream, $dictionary);
            if ($decoded === '') {
                continue;
            }

            $chunks[] = $this->extractTextFromDecodedStream($decoded);
        }

        return implode("\n", array_filter($chunks, static fn (string $chunk): bool => trim($chunk) !== ''));
    }

    private function decodeStream(string $stream, string $dictionary): string
    {
        $stream = ltrim($stream, "\r\n");

        if (!str_contains($dictionary, '/FlateDecode')) {
            return $stream;
        }

        $attempts = [
            static fn (string $value): string|false => @gzuncompress($value),
            static fn (string $value): string|false => @gzinflate($value),
            static fn (string $value): string|false => @gzdecode($value),
            static fn (string $value): string|false => strlen($value) > 2 ? @gzinflate(substr($value, 2)) : false,
        ];

        foreach ($attempts as $attempt) {
            $decoded = $attempt($stream);
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }

        return '';
    }

    private function extractTextFromDecodedStream(string $stream): string
    {
        preg_match_all('/BT(.*?)ET/s', $stream, $blocks);
        $lines = [];

        foreach ($blocks[1] as $block) {
            $normalizedBlock = preg_replace_callback(
                '/\[(.*?)\]\s*TJ/s',
                fn (array $match): string => $this->decodePdfArrayText((string) $match[1]) . "\n",
                (string) $block
            ) ?? (string) $block;

            $normalizedBlock = preg_replace_callback(
                '/\((.*?)(?<!\\\\)\)\s*Tj/s',
                fn (array $match): string => $this->decodePdfString((string) $match[1]) . "\n",
                $normalizedBlock
            ) ?? $normalizedBlock;

            $normalizedBlock = preg_replace('/\s*(?:Td|TD|T\*|Tm)\s*/', "\n", $normalizedBlock) ?? $normalizedBlock;
            $normalizedBlock = preg_replace('/[^\P{C}\n\t]+/u', '', $normalizedBlock) ?? $normalizedBlock;

            foreach (preg_split('/\R+/', $normalizedBlock) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }

        return implode("\n", $lines);
    }

    private function decodePdfArrayText(string $value): string
    {
        preg_match_all('/\((.*?)(?<!\\\\)\)/s', $value, $matches);
        $parts = array_map(fn (string $part): string => $this->decodePdfString($part), $matches[1] ?? []);

        return trim(implode('', $parts));
    }

    private function decodePdfString(string $value): string
    {
        $value = preg_replace_callback('/\\\\([0-7]{1,3})/', static function (array $match): string {
            return chr(octdec($match[1]));
        }, $value) ?? $value;

        return strtr($value, [
            '\\\\' => '\\',
            '\\(' => '(',
            '\\)' => ')',
            '\\n' => "\n",
            '\\r' => "\r",
            '\\t' => "\t",
            '\\b' => "\x08",
            '\\f' => "\x0c",
        ]);
    }

    private function countPdfPages(string $content): int
    {
        preg_match_all('/\/Type\s*\/Page\b/', $content, $matches);

        return count($matches[0] ?? []);
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function preview(string $text): string
    {
        $singleLine = preg_replace('/\s+/', ' ', $text) ?? $text;

        return mb_substr(trim($singleLine), 0, 280);
    }

    private function normalizePageTexts(array $pageTexts): array
    {
        return array_map(
            fn (mixed $pageText): string => $this->normalizeText(is_string($pageText) ? $pageText : ''),
            $pageTexts
        );
    }
}
