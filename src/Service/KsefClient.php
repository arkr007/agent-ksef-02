<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Config;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class KsefClient
{
    private const SESSION_KEY = '_ksef_runtime';
    private const COMPARE_CACHE_KEY = '_ksef_compare_cache';

    public function __construct(
        private ApplicationSettings $applicationSettings,
        private Config $config
    ) {
    }

    public function authenticate(): void
    {
        $this->ensureAccessToken();
    }

    public function refreshTokenIfNeeded(): void
    {
        $this->ensureAccessToken();
    }

    public function getInvoicesByDateRange(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        string $subjectType,
        bool $includeXml = true
    ): array {
        $accessToken = $this->ensureAccessToken();
        $queryPath = (string) $this->config->get('ksef.endpoints.invoice_metadata_query', '/invoices/query/metadata');
        $baseUrl = $this->activeBaseUrl();

        $query = [
            'sortOrder' => 'Asc',
            'pageOffset' => 0,
            'pageSize' => 100,
        ];

        $body = [
            'subjectType' => $subjectType,
            'dateRange' => [
                'dateType' => 'Issue',
                'from' => $from->setTimezone(new DateTimeZone('Europe/Warsaw'))->setTime(0, 0, 0)->format(DATE_ATOM),
                'to' => $to->setTimezone(new DateTimeZone('Europe/Warsaw'))->setTime(23, 59, 59)->format(DATE_ATOM),
            ],
        ];

        $metadataRows = $this->queryInvoiceMetadata($baseUrl, $queryPath, $body, $query, $accessToken);
        if (!$includeXml) {
            return array_values(array_filter(array_map(function (mixed $metadata): ?array {
                if (!is_array($metadata)) {
                    return null;
                }

                return [
                    'metadata' => $metadata,
                    'invoice_xml' => null,
                    'fetch_warning' => null,
                ];
            }, $metadataRows)));
        }

        $detailPath = (string) $this->config->get('ksef.endpoints.invoice_by_ksef_number', '/invoices/ksef/{ksefNumber}');
        $results = [];

        foreach ($metadataRows as $metadata) {
            if (!is_array($metadata)) {
                continue;
            }

            $ksefNumber = trim((string) ($metadata['ksefNumber'] ?? ''));
            if ($ksefNumber === '') {
                continue;
            }

            $xmlPayload = null;
            $fetchWarning = null;

            try {
                $xmlPayload = $this->downloadInvoiceXml($baseUrl, $detailPath, $ksefNumber, $accessToken);
            } catch (\Throwable $exception) {
                $fetchWarning = 'Nie udalo sie pobrac XML faktury z KSeF: ' . $exception->getMessage();
            }

            $results[] = [
                'metadata' => $metadata,
                'invoice_xml' => $xmlPayload,
                'fetch_warning' => $fetchWarning,
            ];
        }

        return $results;
    }

    public function getCostInvoicesByDateRange(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->getInvoicesByDateRange($from, $to, 'Subject2', true);
    }

    public function getSalesInvoicesByDateRange(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->getInvoicesByDateRange($from, $to, 'Subject1', true);
    }

    public function getInvoicesByMonth(int $year, int $month, string $subjectType, bool $includeXml = true): array
    {
        $from = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $to = $from->modify('last day of this month');

        return $this->getInvoicesByDateRange($from, $to, $subjectType, $includeXml);
    }

    public function getCostInvoicesByMonth(int $year, int $month): array
    {
        return $this->getInvoicesByMonth($year, $month, 'Subject2', true);
    }

    public function getSalesInvoicesByMonth(int $year, int $month): array
    {
        return $this->getInvoicesByMonth($year, $month, 'Subject1', true);
    }

    public function getCachedCostInvoiceMetadataByMonth(int $year, int $month, int $ttlSeconds = 900): array
    {
        return $this->getCachedInvoiceMetadataByMonth($year, $month, 'Subject2', $ttlSeconds);
    }

    public function getCachedSalesInvoiceMetadataByMonth(int $year, int $month, int $ttlSeconds = 900): array
    {
        return $this->getCachedInvoiceMetadataByMonth($year, $month, 'Subject1', $ttlSeconds);
    }

    public function getInvoiceDetails(string $ksefReferenceNumber): array
    {
        $accessToken = $this->ensureAccessToken();
        $baseUrl = $this->activeBaseUrl();
        $detailPath = (string) $this->config->get('ksef.endpoints.invoice_by_ksef_number', '/invoices/ksef/{ksefNumber}');

        return [
            'invoice_xml' => $this->downloadInvoiceXml($baseUrl, $detailPath, $ksefReferenceNumber, $accessToken),
        ];
    }

    private function ensureAccessToken(): string
    {
        $settings = $this->applicationSettings->snapshot();
        $environment = (string) $settings['ksef']['environment'];
        $runtime = $this->runtimeState($environment);

        if (($runtime['access_token'] ?? '') !== '' && $this->isJwtValid((string) $runtime['access_token'])) {
            return (string) $runtime['access_token'];
        }

        if (($runtime['refresh_token'] ?? '') !== '') {
            try {
                $refreshPath = (string) $this->config->get('ksef.endpoints.auth_token_refresh', '/auth/token/refresh');
                $response = $this->requestJson(
                    method: 'POST',
                    url: $this->buildUrl($this->activeBaseUrl(), $refreshPath),
                    bearerToken: (string) $runtime['refresh_token']
                );

                $accessToken = $this->extractTokenString($response, ['accessToken']);
                if ($accessToken !== null) {
                    $runtime['access_token'] = $accessToken;
                    $this->storeRuntimeState($environment, $runtime);

                    return $accessToken;
                }
            } catch (\Throwable) {
                $runtime['access_token'] = '';
                $runtime['refresh_token'] = '';
                $this->storeRuntimeState($environment, $runtime);
            }
        }

        $challengePath = (string) $this->config->get('ksef.endpoints.auth_challenge', '/auth/challenge');
        $authPath = (string) $this->config->get('ksef.endpoints.auth_ksef_token', '/auth/ksef-token');
        $statusPath = (string) $this->config->get('ksef.endpoints.auth_status', '/auth/{referenceNumber}');
        $redeemPath = (string) $this->config->get('ksef.endpoints.auth_token_redeem', '/auth/token/redeem');
        $publicKeyPath = (string) $this->config->get('ksef.endpoints.public_key_certificates', '/security/public-key-certificates');

        $challengeResponse = $this->requestJson(
            method: 'POST',
            url: $this->buildUrl($this->activeBaseUrl(), $challengePath)
        );

        $challenge = trim((string) ($challengeResponse['challenge'] ?? ''));
        $timestampMs = isset($challengeResponse['timestampMs']) ? (int) $challengeResponse['timestampMs'] : null;
        if ($challenge === '' || $timestampMs === null || $timestampMs <= 0) {
            throw new RuntimeException('KSeF nie zwrocil poprawnego challenge do uwierzytelnienia.');
        }

        $contextNip = trim((string) ($settings['ksef']['context_nip'] ?? ''));
        if ($contextNip === '') {
            throw new RuntimeException('Uzupelnij w ustawieniach NIP kontekstu KSeF.');
        }

        $token = $this->readTokenForEnvironment($environment);
        if ($token === null) {
            throw new RuntimeException('Brakuje tokenu KSeF dla wybranego srodowiska.');
        }

        $publicKeyInfo = $this->fetchActivePublicKey($publicKeyPath);
        $encryptedToken = $this->encryptKsefToken($token . '|' . $timestampMs, $publicKeyInfo['pem']);

        $authBody = [
            'challenge' => $challenge,
            'contextIdentifier' => [
                'type' => 'Nip',
                'value' => $contextNip,
            ],
            'encryptedToken' => base64_encode($encryptedToken),
        ];

        if ($publicKeyInfo['id'] !== null) {
            $authBody['publicKeyId'] = $publicKeyInfo['id'];
        }

        $authResponse = $this->requestJson(
            method: 'POST',
            url: $this->buildUrl($this->activeBaseUrl(), $authPath),
            jsonBody: $authBody,
            acceptedStatusCodes: [202]
        );

        $authenticationToken = $this->extractTokenString($authResponse, ['authenticationToken']);
        $referenceNumber = trim((string) ($authResponse['referenceNumber'] ?? ''));
        if ($authenticationToken === null || $referenceNumber === '') {
            throw new RuntimeException('KSeF nie zwrocil tymczasowego tokenu operacyjnego dla logowania.');
        }

        $statusUrl = str_replace('{referenceNumber}', rawurlencode($referenceNumber), $this->buildUrl($this->activeBaseUrl(), $statusPath));
        $statusCode = null;

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $statusResponse = $this->requestJson(
                method: 'GET',
                url: $statusUrl,
                bearerToken: $authenticationToken
            );

            $statusCode = (int) ($statusResponse['status']['code'] ?? $statusResponse['code'] ?? 0);
            if ($statusCode === 200) {
                break;
            }

            if ($statusCode !== 100) {
                $description = (string) ($statusResponse['status']['description'] ?? 'Nieznany status uwierzytelnienia KSeF.');
                throw new RuntimeException('Uwierzytelnienie KSeF nie powiodlo sie: ' . $description);
            }

            usleep(800000);
        }

        if ($statusCode !== 200) {
            throw new RuntimeException('KSeF nie zakonczyl uwierzytelnienia w oczekiwanym czasie. Sprobuj ponownie za chwile.');
        }

        $redeemResponse = $this->requestJson(
            method: 'POST',
            url: $this->buildUrl($this->activeBaseUrl(), $redeemPath),
            bearerToken: $authenticationToken
        );

        $accessToken = $this->extractTokenString($redeemResponse, ['accessToken']);
        $refreshToken = $this->extractTokenString($redeemResponse, ['refreshToken']);
        if ($accessToken === null || $refreshToken === null) {
            throw new RuntimeException('KSeF nie zwrocil pary accessToken/refreshToken.');
        }

        $runtime['access_token'] = $accessToken;
        $runtime['refresh_token'] = $refreshToken;
        $this->storeRuntimeState($environment, $runtime);

        return $accessToken;
    }

    private function activeBaseUrl(): string
    {
        $settings = $this->applicationSettings->snapshot();
        $environment = (string) $settings['ksef']['environment'];
        $baseUrl = trim((string) ($settings['ksef'][$environment]['base_url'] ?? ''));

        if ($baseUrl === '' || str_starts_with($baseUrl, 'TODO_')) {
            throw new RuntimeException('Brakuje poprawnego base URL dla wybranego srodowiska KSeF.');
        }

        return $baseUrl;
    }

    private function getCachedInvoiceMetadataByMonth(int $year, int $month, string $subjectType, int $ttlSeconds): array
    {
        $settings = $this->applicationSettings->snapshot();
        $environment = (string) ($settings['ksef']['environment'] ?? 'production');
        $cacheKey = sprintf('%s|%s|%04d-%02d', $environment, $subjectType, $year, $month);
        $cache = $this->compareCache();
        $cachedItem = $cache[$cacheKey] ?? null;

        if (
            is_array($cachedItem)
            && isset($cachedItem['fetched_at'], $cachedItem['payloads'])
            && is_int($cachedItem['fetched_at'])
            && (time() - $cachedItem['fetched_at']) <= $ttlSeconds
            && is_array($cachedItem['payloads'])
        ) {
            return $cachedItem['payloads'];
        }

        $payloads = $this->getInvoicesByMonth($year, $month, $subjectType, false);
        $cache[$cacheKey] = [
            'fetched_at' => time(),
            'payloads' => $payloads,
        ];
        $this->storeCompareCache($cache);

        return $payloads;
    }

    private function queryInvoiceMetadata(string $baseUrl, string $path, array $body, array $query, string $accessToken): array
    {
        $allInvoices = [];
        $seen = [];
        $currentBody = $body;
        $pageOffset = 0;
        $iteration = 0;

        while (true) {
            $iteration++;
            if ($iteration > 30) {
                throw new RuntimeException('Przerwano pobieranie metadanych KSeF z powodu zbyt wielu iteracji stronicowania.');
            }

            $response = $this->requestJson(
                method: 'POST',
                url: $this->buildUrl($baseUrl, $path),
                query: [
                    'sortOrder' => 'Asc',
                    'pageOffset' => $pageOffset,
                    'pageSize' => $query['pageSize'],
                ],
                jsonBody: $currentBody,
                bearerToken: $accessToken
            );

            $batch = $response['invoices'] ?? [];
            if (!is_array($batch)) {
                throw new RuntimeException('KSeF nie zwrocil poprawnej listy metadanych faktur.');
            }

            foreach ($batch as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $ksefNumber = trim((string) ($item['ksefNumber'] ?? ''));
                if ($ksefNumber !== '' && isset($seen[$ksefNumber])) {
                    continue;
                }

                if ($ksefNumber !== '') {
                    $seen[$ksefNumber] = true;
                }

                $allInvoices[] = $item;
            }

            $hasMore = (bool) ($response['hasMore'] ?? false);
            $isTruncated = (bool) ($response['isTruncated'] ?? false);
            if (!$hasMore) {
                break;
            }

            if (!$isTruncated) {
                $pageOffset++;
                continue;
            }

            $last = end($batch);
            if (!is_array($last) || empty($last['issueDate'])) {
                throw new RuntimeException('KSeF przycial wynik metadanych, ale nie zwrocil daty ostatniego rekordu potrzebnej do kontynuacji.');
            }

            $lastIssueDate = (string) $last['issueDate'];
            $from = new DateTimeImmutable($lastIssueDate . ' 00:00:00', new DateTimeZone('Europe/Warsaw'));
            $currentBody['dateRange']['from'] = $from->format(DATE_ATOM);
            $pageOffset = 0;
        }

        return $allInvoices;
    }

    private function downloadInvoiceXml(string $baseUrl, string $path, string $ksefNumber, string $accessToken): string
    {
        $resolvedPath = str_replace('{ksefNumber}', rawurlencode($ksefNumber), $path);

        return $this->requestRaw(
            method: 'GET',
            url: $this->buildUrl($baseUrl, $resolvedPath),
            bearerToken: $accessToken,
            accept: 'application/xml'
        );
    }

    private function fetchActivePublicKey(string $path): array
    {
        $response = $this->requestJson(
            method: 'GET',
            url: $this->buildUrl($this->activeBaseUrl(), $path)
        );

        $items = $response['items'] ?? $response['certificates'] ?? $response;
        if (!is_array($items)) {
            throw new RuntimeException('KSeF nie zwrocil listy kluczy publicznych.');
        }

        $selected = null;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $usage = $item['usage'] ?? [];
            if (is_array($usage) && in_array('KsefTokenEncryption', $usage, true)) {
                $selected = $item;
                break;
            }
        }

        if ($selected === null) {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $selected = $item;
                break;
            }
        }

        if (!is_array($selected)) {
            throw new RuntimeException('Nie znaleziono aktywnego klucza publicznego KSeF.');
        }

        $pem = $this->extractPemCertificate($selected);
        if ($pem === null) {
            throw new RuntimeException('Nie udalo sie odczytac certyfikatu klucza publicznego KSeF.');
        }

        return [
            'pem' => $pem,
            'id' => isset($selected['publicKeyId']) ? (string) $selected['publicKeyId'] : (isset($selected['id']) ? (string) $selected['id'] : null),
        ];
    }

    private function extractPemCertificate(array $item): ?string
    {
        foreach (['certificatePem', 'pem', 'certificate'] as $key) {
            $value = trim((string) ($item[$key] ?? ''));
            if ($value === '') {
                continue;
            }

            if (str_contains($value, 'BEGIN CERTIFICATE')) {
                return $value;
            }

            return "-----BEGIN CERTIFICATE-----\n"
                . chunk_split(preg_replace('/\s+/', '', $value) ?? '', 64, "\n")
                . "-----END CERTIFICATE-----\n";
        }

        return null;
    }

    private function encryptKsefToken(string $plainText, string $certificatePem): string
    {
        $certificate = openssl_x509_read($certificatePem);
        if ($certificate === false) {
            throw new RuntimeException('Nie udalo sie odczytac certyfikatu klucza publicznego KSeF.');
        }

        $publicKey = openssl_get_publickey($certificate);
        if ($publicKey === false) {
            throw new RuntimeException('Nie udalo sie odczytac klucza publicznego KSeF.');
        }

        $details = openssl_pkey_get_details($publicKey);
        if (!is_array($details) || !isset($details['bits'])) {
            throw new RuntimeException('Nie udalo sie odczytac parametrow klucza publicznego KSeF.');
        }

        $modulusLength = (int) ceil(((int) $details['bits']) / 8);
        $encodedMessage = $this->oaepSha256Encode($plainText, $modulusLength);

        $encrypted = '';
        if (!openssl_public_encrypt($encodedMessage, $encrypted, $publicKey, OPENSSL_NO_PADDING)) {
            throw new RuntimeException('Nie udalo sie zaszyfrowac tokenu KSeF kluczem publicznym.');
        }

        return $encrypted;
    }

    private function oaepSha256Encode(string $message, int $modulusLength): string
    {
        $hashAlgorithm = 'sha256';
        $hashLength = 32;
        $messageLength = strlen($message);
        if ($messageLength > $modulusLength - (2 * $hashLength) - 2) {
            throw new RuntimeException('Token KSeF jest zbyt dlugi dla klucza publicznego srodowiska.');
        }

        $labelHash = hash($hashAlgorithm, '', true);
        $padding = str_repeat("\0", $modulusLength - $messageLength - (2 * $hashLength) - 2);
        $dataBlock = $labelHash . $padding . "\x01" . $message;
        $seed = random_bytes($hashLength);
        $dbMask = $this->mgf1($seed, $modulusLength - $hashLength - 1, $hashAlgorithm);
        $maskedDataBlock = $dataBlock ^ $dbMask;
        $seedMask = $this->mgf1($maskedDataBlock, $hashLength, $hashAlgorithm);
        $maskedSeed = $seed ^ $seedMask;

        return "\0" . $maskedSeed . $maskedDataBlock;
    }

    private function mgf1(string $seed, int $length, string $hashAlgorithm): string
    {
        $result = '';
        $counter = 0;

        while (strlen($result) < $length) {
            $result .= hash($hashAlgorithm, $seed . pack('N', $counter), true);
            $counter++;
        }

        return substr($result, 0, $length);
    }

    private function requestJson(
        string $method,
        string $url,
        array $query = [],
        ?array $jsonBody = null,
        ?string $bearerToken = null,
        array $acceptedStatusCodes = [200]
    ): array {
        $response = $this->requestHttp($method, $url, $query, $jsonBody, $bearerToken, 'application/json', $acceptedStatusCodes);
        $decoded = json_decode($response['body'], true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Odpowiedz KSeF nie jest poprawnym JSON-em.');
        }

        return $decoded;
    }

    private function requestRaw(
        string $method,
        string $url,
        ?string $bearerToken = null,
        string $accept = 'application/xml'
    ): string {
        $response = $this->requestHttp($method, $url, [], null, $bearerToken, $accept, [200]);

        return $response['body'];
    }

    private function requestHttp(
        string $method,
        string $url,
        array $query,
        ?array $jsonBody,
        ?string $bearerToken,
        string $accept,
        array $acceptedStatusCodes
    ): array {
        $fullUrl = $url;
        if ($query !== []) {
            $fullUrl .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $curl = curl_init($fullUrl);
        if ($curl === false) {
            throw new RuntimeException('Nie udalo sie zainicjalizowac polaczenia cURL do KSeF.');
        }

        $headers = [
            'Accept: ' . $accept,
            'X-Error-Format: problem-details',
        ];

        if ($jsonBody !== null) {
            $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                throw new RuntimeException('Nie udalo sie przygotowac JSON-a zadania do KSeF.');
            }

            $headers[] = 'Content-Type: application/json; charset=UTF-8';
            curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
        }

        if ($bearerToken !== null && $bearerToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $bearerToken;
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HEADER => true,
        ]);

        $rawResponse = curl_exec($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($rawResponse === false || $error !== '') {
            throw new RuntimeException('Nie udalo sie pobrac danych z KSeF: ' . $error);
        }

        $responseHeaders = substr($rawResponse, 0, $headerSize);
        $body = substr($rawResponse, $headerSize);

        if (!in_array($httpCode, $acceptedStatusCodes, true)) {
            $message = $this->extractErrorMessage($body);
            if ($httpCode === 429) {
                $message = 'Przekroczono limit zapytan KSeF (16/min). Odczekaj okolo minute i sprobuj ponownie.';
            }

            throw new RuntimeException('KSeF zwrocil HTTP ' . $httpCode . ($message !== '' ? ': ' . $message : '.'));
        }

        return [
            'status_code' => $httpCode,
            'headers' => $responseHeaders,
            'body' => $body,
        ];
    }

    private function extractErrorMessage(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return trim($body);
        }

        foreach (['detail', 'message', 'title'] as $key) {
            if (!empty($decoded[$key]) && is_string($decoded[$key])) {
                return trim($decoded[$key]);
            }
        }

        if (isset($decoded['status']['description']) && is_string($decoded['status']['description'])) {
            return trim($decoded['status']['description']);
        }

        return '';
    }

    private function extractTokenString(array $payload, array $path): ?string
    {
        $current = $payload;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        if (is_string($current) && trim($current) !== '') {
            return trim($current);
        }

        if (is_array($current)) {
            foreach (['token', 'value'] as $key) {
                if (isset($current[$key]) && is_string($current[$key]) && trim($current[$key]) !== '') {
                    return trim($current[$key]);
                }
            }
        }

        return null;
    }

    private function runtimeState(string $environment): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $runtime = $_SESSION[self::SESSION_KEY][$environment] ?? [];

        return is_array($runtime) ? $runtime : [];
    }

    private function compareCache(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $cache = $_SESSION[self::COMPARE_CACHE_KEY] ?? [];

        return is_array($cache) ? $cache : [];
    }

    private function storeRuntimeState(string $environment, array $runtime): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION[self::SESSION_KEY][$environment] = $runtime;
    }

    private function storeCompareCache(array $cache): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION[self::COMPARE_CACHE_KEY] = $cache;
    }

    private function isJwtValid(string $jwt): bool
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            return false;
        }

        $payload = json_decode($this->base64UrlDecode($parts[1]), true);
        if (!is_array($payload) || !isset($payload['exp'])) {
            return false;
        }

        return ((int) $payload['exp']) > (time() + 60);
    }

    private function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }

    private function readTokenForEnvironment(string $environment): ?string
    {
        $settingsKey = 'ksef.' . $environment . '.token';
        $settingsValue = $this->applicationSettings->secretValue($settingsKey);
        if ($settingsValue !== null && trim($settingsValue) !== '') {
            return trim($settingsValue);
        }

        $configKey = 'ksef.' . $environment . '.token';
        $raw = (string) $this->config->get($configKey, '');
        if (trim($raw) !== '') {
            return trim($raw);
        }

        return null;
    }

    private function buildUrl(string $baseUrl, string $path): string
    {
        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }
}
