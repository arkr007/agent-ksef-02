<?php

declare(strict_types=1);

namespace App\Service;

final class InvoiceAiPromptCatalog
{
    public static function systemPrompt(): string
    {
        return <<<'PROMPT'
Analyze the full PDF document containing cost documents for accounting.
You receive page images in the exact order from page 1 to the last page.

Task:
1. Detect all relevant accounting documents in the PDF.
2. Merge consecutive pages that belong to the same document.
3. For each document return:
- page range,
- document type,
- issuer,
- document number if visible,
- gross amount,
- amount due if visible,
- currency,
- issue date,
- due date.
4. If a page looks relevant but cannot be classified with confidence, add it to manual_review_pages.
5. Return only one JSON object and no markdown.

Rules:
- source_type: invoice, receipt, payment_confirmation, other
- money values must be strings with a dot decimal separator and no spaces
- currency must be a 3-letter ISO code or null
- dates must use YYYY-MM-DD or null
- if the document has many pages, return one item with page_from and page_to
- if both gross amount and amount due are present, return both
PROMPT;
    }

    public static function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'documents' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'page_from' => ['type' => 'integer'],
                            'page_to' => ['type' => 'integer'],
                            'source_type' => [
                                'type' => 'string',
                                'enum' => ['invoice', 'receipt', 'payment_confirmation', 'other'],
                            ],
                            'issuer_name' => ['type' => ['string', 'null']],
                            'invoice_number' => ['type' => ['string', 'null']],
                            'gross_amount' => ['type' => ['string', 'null']],
                            'amount_due' => ['type' => ['string', 'null']],
                            'currency' => ['type' => ['string', 'null']],
                            'issue_date' => ['type' => ['string', 'null']],
                            'due_date' => ['type' => ['string', 'null']],
                            'manual_review' => ['type' => 'boolean'],
                            'note' => ['type' => ['string', 'null']],
                        ],
                        'required' => [
                            'page_from',
                            'page_to',
                            'source_type',
                            'issuer_name',
                            'invoice_number',
                            'gross_amount',
                            'amount_due',
                            'currency',
                            'issue_date',
                            'due_date',
                            'manual_review',
                            'note',
                        ],
                    ],
                ],
                'manual_review_pages' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'page_from' => ['type' => 'integer'],
                            'page_to' => ['type' => 'integer'],
                            'note' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['page_from', 'page_to', 'note'],
                    ],
                ],
            ],
            'required' => ['documents', 'manual_review_pages'],
        ];
    }

    public static function promptIntro(string $sourceFileName, array $pageImages, array $pageTexts): string
    {
        $pageCount = count($pageImages);
        $lines = [
            'Source file: ' . $sourceFileName,
            'The next input contains ' . $pageCount . ' page images ordered from page 1 to page ' . $pageCount . '.',
            'Images are the primary source of truth.',
            'Any page text snippets are only supporting hints.',
            'Return a JSON object matching the provided schema.',
        ];

        foreach (array_values($pageTexts) as $index => $pageText) {
            $snippet = self::pageTextSnippet(is_string($pageText) ? $pageText : '');
            if ($snippet === null) {
                continue;
            }

            $lines[] = 'Page ' . ($index + 1) . ' helper text: ' . $snippet;
        }

        return implode("\n", $lines);
    }

    private static function pageTextSnippet(string $pageText): ?string
    {
        $trimmed = trim($pageText);
        if ($trimmed === '') {
            return null;
        }

        $normalized = preg_replace('/\s+/', ' ', $trimmed) ?? $trimmed;

        return mb_substr($normalized, 0, 240);
    }
}
