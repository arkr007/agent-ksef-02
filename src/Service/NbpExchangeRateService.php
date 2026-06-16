<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use RuntimeException;

final class NbpExchangeRateService
{
    private const SESSION_CACHE_KEY = '_nbp_exchange_rate_series';
    private const BASE_URL = 'https://api.nbp.pl/api/exchangerates/rates';

    public function enrichInvoicesForComparison(array $invoices, int $year, int $month): array
    {
        $currencies = [];

        foreach ($invoices as $invoice) {
            $currency = $this->normalizeCurrency($invoice['currency'] ?? 'PLN');
            if ($currency === 'PLN') {
                continue;
            }

            $currencies[$currency] = true;
        }

        $seriesByCurrency = [];
        $errorsByCurrency = [];

        foreach (array_keys($currencies) as $currency) {
            try {
                $seriesByCurrency[$currency] = $this->getMonthlySeries($currency, $year, $month);
            } catch (RuntimeException $exception) {
                $errorsByCurrency[$currency] = $exception->getMessage();
            }
        }

        $enriched = [];

        foreach ($invoices as $invoice) {
            $enriched[] = $this->enrichSingleInvoice(
                $invoice,
                $year,
                $month,
                $seriesByCurrency,
                $errorsByCurrency
            );
        }

        return $enriched;
    }

    private function enrichSingleInvoice(
        array $invoice,
        int $year,
        int $month,
        array $seriesByCurrency,
        array $errorsByCurrency
    ): array {
        $currency = $this->normalizeCurrency($invoice['currency'] ?? 'PLN');
        $grossAmount = $this->normalizeDecimal($invoice['gross_amount'] ?? null);

        $invoice['original_gross_amount'] = $grossAmount;
        $invoice['original_currency'] = $currency;
        $invoice['comparison_currency'] = 'PLN';
        $invoice['comparison_exchange_rate'] = null;
        $invoice['comparison_exchange_rate_date'] = null;
        $invoice['comparison_exchange_rate_table'] = null;
        $invoice['comparison_exchange_rate_source'] = null;
        $invoice['comparison_warning'] = null;

        if ($grossAmount === null) {
            $invoice['comparison_gross_amount'] = null;

            return $invoice;
        }

        if ($currency === 'PLN') {
            $invoice['comparison_gross_amount'] = $grossAmount;
            $invoice['comparison_exchange_rate_source'] = 'native_pln';

            return $invoice;
        }

        if (isset($errorsByCurrency[$currency])) {
            $invoice['comparison_gross_amount'] = $grossAmount;
            $invoice['comparison_warning'] = 'Nie udalo sie pobrac kursu NBP dla waluty ' . $currency . '.';
            $invoice['comparison_exchange_rate_source'] = 'unconverted';

            return $invoice;
        }

        $series = $seriesByCurrency[$currency] ?? [];
        $issueDate = $this->normalizeDate($invoice['issue_date'] ?? null);
        $rateData = $issueDate !== null
            ? $this->findEffectiveRateForDate($series, $issueDate)
            : null;

        if ($rateData === null) {
            $rateData = $this->calculateMonthlyAverageRate($series, $year, $month);
        }

        if ($rateData === null) {
            $invoice['comparison_gross_amount'] = $grossAmount;
            $invoice['comparison_warning'] = 'Brak dostepnego kursu NBP do przeliczenia waluty ' . $currency . '.';
            $invoice['comparison_exchange_rate_source'] = 'unconverted';

            return $invoice;
        }

        $invoice['comparison_gross_amount'] = number_format(
            round((float) $grossAmount * (float) $rateData['rate'], 2),
            2,
            '.',
            ''
        );
        $invoice['comparison_exchange_rate'] = $rateData['rate'];
        $invoice['comparison_exchange_rate_date'] = $rateData['effective_date'];
        $invoice['comparison_exchange_rate_table'] = $rateData['table'];
        $invoice['comparison_exchange_rate_source'] = $rateData['source'];

        return $invoice;
    }

    private function getMonthlySeries(string $currency, int $year, int $month): array
    {
        $cacheKey = sprintf('%s|%04d-%02d', $currency, $year, $month);
        $cache = $this->cache();
        $cachedItem = $cache[$cacheKey] ?? null;

        if (
            is_array($cachedItem)
            && isset($cachedItem['fetched_at'], $cachedItem['series'])
            && is_int($cachedItem['fetched_at'])
            && (time() - $cachedItem['fetched_at']) <= 86400
            && is_array($cachedItem['series'])
        ) {
            return $cachedItem['series'];
        }

        $monthStart = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $requestStart = $monthStart->modify('-7 days');
        $requestEnd = $monthStart->modify('last day of this month');

        $series = $this->fetchSeriesFromNbp('A', $currency, $requestStart, $requestEnd);
        if ($series === []) {
            $series = $this->fetchSeriesFromNbp('B', $currency, $requestStart, $requestEnd);
        }

        if ($series === []) {
            throw new RuntimeException('NBP nie zwrocil kursow dla waluty ' . $currency . '.');
        }

        $cache[$cacheKey] = [
            'fetched_at' => time(),
            'series' => $series,
        ];
        $this->storeCache($cache);

        return $series;
    }

    private function fetchSeriesFromNbp(
        string $table,
        string $currency,
        DateTimeImmutable $startDate,
        DateTimeImmutable $endDate
    ): array {
        $url = sprintf(
            '%s/%s/%s/%s/%s/?format=json',
            self::BASE_URL,
            rawurlencode($table),
            rawurlencode($currency),
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d')
        );

        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Nie udalo sie zainicjalizowac polaczenia z API NBP.');
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $body = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false || $error !== '') {
            throw new RuntimeException('Nie udalo sie pobrac kursow NBP: ' . $error);
        }

        if ($statusCode === 404) {
            return [];
        }

        if ($statusCode !== 200) {
            throw new RuntimeException('NBP zwrocil HTTP ' . $statusCode . ' dla waluty ' . $currency . '.');
        }

        $decoded = json_decode($body, true);
        $rates = $decoded['rates'] ?? null;
        if (!is_array($rates)) {
            throw new RuntimeException('NBP zwrocil niepoprawna odpowiedz dla waluty ' . $currency . '.');
        }

        $series = [];

        foreach ($rates as $rate) {
            if (!is_array($rate)) {
                continue;
            }

            $effectiveDate = isset($rate['effectiveDate']) && is_string($rate['effectiveDate'])
                ? trim($rate['effectiveDate'])
                : '';
            $mid = $rate['mid'] ?? null;

            if ($effectiveDate === '' || !is_numeric((string) $mid)) {
                continue;
            }

            $series[] = [
                'effective_date' => $effectiveDate,
                'rate' => number_format((float) $mid, 4, '.', ''),
                'table' => $table,
            ];
        }

        usort($series, static fn (array $left, array $right): int => strcmp(
            (string) $left['effective_date'],
            (string) $right['effective_date']
        ));

        return $series;
    }

    private function findEffectiveRateForDate(array $series, string $issueDate): ?array
    {
        $selected = null;

        foreach ($series as $row) {
            $effectiveDate = (string) ($row['effective_date'] ?? '');
            if ($effectiveDate === '' || $effectiveDate > $issueDate) {
                continue;
            }

            $selected = $row;
        }

        if ($selected === null) {
            return null;
        }

        $selected['source'] = 'daily_effective_rate';

        return $selected;
    }

    private function calculateMonthlyAverageRate(array $series, int $year, int $month): ?array
    {
        $prefix = sprintf('%04d-%02d-', $year, $month);
        $sum = 0.0;
        $count = 0;
        $table = null;

        foreach ($series as $row) {
            $effectiveDate = (string) ($row['effective_date'] ?? '');
            $rate = $row['rate'] ?? null;

            if (!str_starts_with($effectiveDate, $prefix) || !is_numeric((string) $rate)) {
                continue;
            }

            $sum += (float) $rate;
            $count++;
            $table ??= (string) ($row['table'] ?? 'A');
        }

        if ($count === 0) {
            return null;
        }

        return [
            'effective_date' => sprintf('%04d-%02d', $year, $month),
            'rate' => number_format($sum / $count, 4, '.', ''),
            'table' => $table ?? 'A',
            'source' => 'monthly_average_rate',
        ];
    }

    private function normalizeCurrency(mixed $value): string
    {
        $currency = strtoupper(trim((string) $value));

        return $currency !== '' ? $currency : 'PLN';
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $trimmed = trim($value);
        $datePart = preg_split('/[T\s]/', $trimmed)[0] ?? $trimmed;
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $datePart);

        return $date instanceof DateTimeImmutable ? $date->format('Y-m-d') : null;
    }

    private function normalizeDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace(' ', '', trim((string) $value));
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

    private function cache(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $cache = $_SESSION[self::SESSION_CACHE_KEY] ?? [];

        return is_array($cache) ? $cache : [];
    }

    private function storeCache(array $cache): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION[self::SESSION_CACHE_KEY] = $cache;
    }
}
