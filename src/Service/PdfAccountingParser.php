<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;

final class PdfAccountingParser
{
    public function parse(string $filePath): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new \RuntimeException('Nie udalo sie odczytac pliku PDF.');
        }

        $text = $this->extractTextWithPythonPypdf($filePath);
        if ($text !== null) {
            $entries = $this->parseEntriesFromLayoutText($text);

            if ($entries !== []) {
                return [
                    'raw_text' => $this->normalizeExtractedText($text),
                    'entries' => $entries,
                ];
            }
        }

        $content = file_get_contents($filePath);
        if ($content === false || $content === '') {
            throw new \RuntimeException('Plik PDF jest pusty albo nieczytelny.');
        }

        $text = $this->extractPdfText($content);
        $text = $this->normalizeExtractedText($text);

        if (trim($text) === '') {
            throw new \RuntimeException('Nie znaleziono warstwy tekstowej w PDF. Dla MVP potrzebny jest tekstowy PDF bez OCR.');
        }

        $entries = $this->parseEntriesFromText($text);
        if ($entries === []) {
            throw new \RuntimeException('Nie udalo sie rozpoznac wpisow ksiegowych w PDF. Sprawdz, czy raport zawiera tekstowe wiersze tabeli.');
        }

        return [
            'raw_text' => $text,
            'entries' => $entries,
        ];
    }

    private function extractTextWithPythonPypdf(string $filePath): ?string
    {
        $scriptPath = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'agent_ksef_pypdf_extract.py';
        $script = <<<'PY'
import base64
import sys
from pypdf import PdfReader

path = sys.argv[1]
reader = PdfReader(path)
text = "\n".join((page.extract_text(extraction_mode="layout") or "") for page in reader.pages)
payload = base64.b64encode(text.encode("utf-8")).decode("ascii")
sys.stdout.write(payload)
PY;

        @file_put_contents($scriptPath, $script);

        foreach ($this->pythonCandidates() as $candidate) {
            $command = array_merge($candidate, [$scriptPath, $filePath]);
            $result = $this->runProcess($command);
            if ($result['exit_code'] !== 0 || trim($result['stdout']) === '') {
                continue;
            }

            $decoded = base64_decode(trim($result['stdout']), true);
            if ($decoded !== false && trim($decoded) !== '') {
                return $decoded;
            }
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

            $normalizedBlock = preg_replace_callback(
                '/\((.*?)(?<!\\\\)\)\s*\'/s',
                fn (array $match): string => $this->decodePdfString((string) $match[1]) . "\n",
                $normalizedBlock
            ) ?? $normalizedBlock;

            $normalizedBlock = preg_replace_callback(
                '/\((.*?)(?<!\\\\)\)\s*"/s',
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

        $replacements = [
            '\\\\' => '\\',
            '\\(' => '(',
            '\\)' => ')',
            '\\n' => "\n",
            '\\r' => "\r",
            '\\t' => "\t",
            '\\b' => "\x08",
            '\\f' => "\x0c",
        ];

        return strtr($value, $replacements);
    }

    private function normalizeExtractedText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function parseEntriesFromText(string $text): array
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R+/', $text) ?: []),
            static fn (string $line): bool => $line !== ''
        ));

        $rows = [];
        $buffer = '';

        foreach ($lines as $line) {
            if ($this->looksLikeEntryStart($line) && $buffer !== '') {
                $rows[] = $buffer;
                $buffer = $line;
                continue;
            }

            $buffer = $buffer === '' ? $line : ($buffer . ' ' . $line);
        }

        if ($buffer !== '') {
            $rows[] = $buffer;
        }

        $entries = [];
        foreach ($rows as $row) {
            $entry = $this->parseSingleRow($row);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function parseEntriesFromLayoutText(string $text): array
    {
        $lines = preg_split('/\R+/', $text) ?: [];
        $blocks = [];
        $currentBlock = [];

        foreach ($lines as $line) {
            $line = rtrim($line);
            if (trim($line) === '') {
                continue;
            }

            if ($this->isLayoutEntryStart($line)) {
                if ($currentBlock !== []) {
                    $blocks[] = $currentBlock;
                }
                $currentBlock = [$line];
                continue;
            }

            if ($currentBlock === []) {
                continue;
            }

            if ($this->isLayoutFooterOrHeader($line)) {
                $blocks[] = $currentBlock;
                $currentBlock = [];
                continue;
            }

            $currentBlock[] = $line;
        }

        if ($currentBlock !== []) {
            $blocks[] = $currentBlock;
        }

        $entries = [];
        foreach ($blocks as $block) {
            $entry = $this->parseLayoutBlock($block);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function isLayoutEntryStart(string $line): bool
    {
        return preg_match('/^\s*\d+\s+\d{1,2}\.\d{2}\.\d{4}\s+/', $line) === 1;
    }

    private function isLayoutFooterOrHeader(string $line): bool
    {
        $trimmed = trim($line);

        foreach ([
            'SUMA STRONY',
            'PRZENIESIENIE Z POPRZEDNIEJ STRONY',
            'RAZEM OD POCZĄTKU OKRESU',
            'KONTRAHENT',
            'PRZYCHÓD',
            'ZAKUP',
            'WYDATKI (KOSZTY)',
            'KOSZTY DZIAŁALNOŚCI',
            'NR DOWODU',
            'L.P.',
            'DATA',
            'OPIS KOSZTU',
            'PODATNIK',
            'PODATKOWA KSIĘGA',
        ] as $needle) {
            if (str_contains($trimmed, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function parseLayoutBlock(array $lines): ?array
    {
        $originalLines = $lines;
        $firstLine = array_shift($lines);
        $firstColumns = $this->splitLayoutColumns($firstLine);
        if (count($firstColumns) < 5) {
            return null;
        }

        $lp = $firstColumns[0] ?? null;
        $eventDate = $this->normalizeDate($firstColumns[1] ?? '');
        $documentParts = [$firstColumns[2] ?? ''];
        $contractorParts = [$firstColumns[3] ?? ''];
        $descriptionParts = [];

        $amountColumns = [];
        for ($index = count($firstColumns) - 1; $index >= 0; $index--) {
            if ($this->looksLikeAmount($firstColumns[$index])) {
                array_unshift($amountColumns, $firstColumns[$index]);
                continue;
            }

            $descriptionParts = array_slice($firstColumns, 4, $index - 3);
            break;
        }

        foreach ($lines as $line) {
            if ($this->isLayoutFooterOrHeader($line)) {
                break;
            }

            $columns = $this->splitLayoutColumns($line);
            if ($columns === []) {
                continue;
            }

            if (count($columns) >= 2 && $this->looksLikeDocumentFragment($columns[0])) {
                $documentParts[] = $columns[0];
                $contractorParts[] = $columns[1];
                if (isset($columns[2]) && !$this->looksLikeAddress($columns[2])) {
                    $descriptionParts[] = $columns[2];
                }
                continue;
            }

            if (count($columns) >= 2 && !$this->looksLikeAmount($columns[1])) {
                $contractorParts[] = $columns[0];
                if (!$this->looksLikeAddress($columns[1])) {
                    $descriptionParts[] = implode(' ', array_slice($columns, 1));
                }
                continue;
            }

            if (count($columns) === 1 && !$this->looksLikeAddress($columns[0])) {
                $target = $descriptionParts !== [] ? 'description' : 'contractor';
                if ($target === 'description') {
                    $descriptionParts[] = $columns[0];
                } else {
                    $contractorParts[] = $columns[0];
                }
            }
        }

        $documentType = $this->detectDocumentTypeFromLayoutLine($firstLine);
        $expenseAmount = null;
        foreach (array_reverse($amountColumns) as $amount) {
            $normalized = $this->normalizeAmount($amount);
            if ($normalized !== null) {
                $expenseAmount = $normalized;
                break;
            }
        }

        if ($eventDate === null || $expenseAmount === null) {
            return null;
        }

        $documentNumber = trim(implode('', array_map('trim', $documentParts)));
        $contractorName = trim(implode(' ', array_map('trim', $contractorParts)));
        $description = trim(implode(' ', array_map('trim', $descriptionParts)));
        $salesAmount = $documentType === 'sale' ? $expenseAmount : null;
        $costAmount = $documentType === 'cost' ? $expenseAmount : null;

        return [
            'row_lp' => $lp,
            'event_date' => $eventDate,
            'document_number' => $documentNumber !== '' ? $documentNumber : null,
            'contractor_name' => $contractorName !== '' ? $contractorName : null,
            'contractor_address' => null,
            'business_event_description' => $description !== '' ? $description : $contractorName,
            'document_type' => $documentType,
            'gross_amount' => $expenseAmount,
            'revenue_amount' => $salesAmount,
            'purchase_goods_amount' => null,
            'side_purchase_costs_amount' => null,
            'other_expenses_amount' => $costAmount,
            'total_expenses_amount' => $costAmount,
            'notes' => null,
            'raw_text' => trim(implode(' ', array_map('trim', $originalLines))),
        ];
    }

    private function splitLayoutColumns(string $line): array
    {
        return array_values(array_filter(
            preg_split('/\s{2,}/', trim($line)) ?: [],
            static fn (string $value): bool => trim($value) !== ''
        ));
    }

    private function looksLikeAmount(string $value): bool
    {
        return preg_match('/^-?\d[\d ]*(?:,\d{2}|\.\d{2})$/', trim($value)) === 1;
    }

    private function looksLikeDocumentFragment(string $value): bool
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }

        return preg_match('/^[A-Za-z0-9\/\-_]+$/', $trimmed) === 1;
    }

    private function looksLikeAddress(string $value): bool
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }

        return preg_match('/\b\d{2}-\d{3}\b/', $trimmed) === 1
            || preg_match('/\b(ul\.|pl\.|al\.|ave|street|road|warszawa|kraków|pozn[aą]ń|oslo|luxembourg)\b/i', $trimmed) === 1;
    }

    private function detectDocumentTypeFromLayoutLine(string $line): string
    {
        preg_match_all('/(?<!\d)-?\d[\d ]*,\d{2}(?!\d)/', $line, $matches, PREG_OFFSET_CAPTURE);
        $amounts = $matches[0] ?? [];
        if ($amounts === []) {
            return 'cost';
        }

        $last = end($amounts);
        $position = is_array($last) ? (int) $last[1] : 999;

        return $position < 200 ? 'sale' : 'cost';
    }

    private function looksLikeEntryStart(string $line): bool
    {
        return preg_match('/^\d+\s+(?:\d{4}-\d{2}-\d{2}|\d{2}[.\/-]\d{2}[.\/-]\d{4})\b/', $line) === 1
            || preg_match('/^(?:\d{4}-\d{2}-\d{2}|\d{2}[.\/-]\d{2}[.\/-]\d{4})\b/', $line) === 1;
    }

    private function parseSingleRow(string $row): ?array
    {
        $compact = trim(preg_replace('/\s+/', ' ', $row) ?? $row);
        if ($compact === '') {
            return null;
        }

        preg_match_all('/-?\d[\d ]*(?:[.,]\d{2})/', $compact, $amountMatches, PREG_OFFSET_CAPTURE);
        $amounts = $amountMatches[0] ?? [];

        if (count($amounts) === 0) {
            return null;
        }

        $tailAmounts = array_slice($amounts, -5);
        $firstAmountOffset = (int) $tailAmounts[0][1];
        $head = trim(substr($compact, 0, $firstAmountOffset));

        $lp = null;
        $eventDate = null;
        $documentNumber = null;
        $remaining = $head;

        if (preg_match('/^(?:(\d+)\s+)?(\d{4}-\d{2}-\d{2}|\d{2}[.\/-]\d{2}[.\/-]\d{4})\s+(.*)$/', $head, $match) === 1) {
            $lp = $match[1] !== '' ? $match[1] : null;
            $eventDate = $this->normalizeDate($match[2]);
            $remaining = trim($match[3]);
        }

        if ($remaining === '' || $eventDate === null) {
            return null;
        }

        $parts = preg_split('/\s{2,}/', $remaining) ?: [];
        if (count($parts) >= 3) {
            $documentNumber = trim((string) array_shift($parts));
            $contractorName = trim((string) array_shift($parts));
            $description = trim(implode(' ', $parts));
        } else {
            $tokens = preg_split('/\s+/', $remaining) ?: [];
            $documentNumber = (string) array_shift($tokens);
            $contractorName = trim(implode(' ', array_slice($tokens, 0, 4)));
            $description = trim(implode(' ', array_slice($tokens, 4)));
        }

        $amountValues = array_map(fn (array $item): ?string => $this->normalizeAmount((string) $item[0]), $tailAmounts);
        while (count($amountValues) < 5) {
            array_unshift($amountValues, null);
        }

        return [
            'row_lp' => $lp,
            'event_date' => $eventDate,
            'document_number' => $documentNumber,
            'contractor_name' => $contractorName !== '' ? $contractorName : null,
            'contractor_address' => null,
            'business_event_description' => $description !== '' ? $description : $contractorName,
            'revenue_amount' => $amountValues[0],
            'purchase_goods_amount' => $amountValues[1],
            'side_purchase_costs_amount' => $amountValues[2],
            'other_expenses_amount' => $amountValues[3],
            'total_expenses_amount' => $amountValues[4],
            'notes' => null,
            'raw_text' => $compact,
        ];
    }

    private function normalizeDate(string $value): ?string
    {
        $formats = ['Y-m-d', 'd.m.Y', 'd/m/Y', 'd-m-Y'];

        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date instanceof DateTimeImmutable && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function normalizeAmount(string $value): ?string
    {
        $normalized = str_replace(' ', '', trim($value));
        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            if ((int) strrpos($normalized, ',') > (int) strrpos($normalized, '.')) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif (str_contains($normalized, ',')) {
            $normalized = str_replace(',', '.', $normalized);
        }

        if (!is_numeric($normalized)) {
            return null;
        }

        return number_format((float) $normalized, 2, '.', '');
    }
}
