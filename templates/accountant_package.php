<?php
/** @var array $alerts */
/** @var string $pageTitle */
/** @var string $pageDescription */
/** @var string $desktopFolderPath */
/** @var string $expectedCsvPath */
/** @var array|null $catalog */
/** @var array|null $documentCatalog */
/** @var string $scrollTarget */
/** @var string $selectedMonth */
/** @var array|null $ksefPackage */
/** @var array|null $pdfAnalysisPackage */
/** @var array|null $pdfCandidatesPackage */
/** @var array|null $packageSummary */
/** @var string $activeEnvironment */
/** @var string $environmentBaseUrl */
/** @var string $tokenPresenceLabel */

$baseUrl = rtrim((string) $config->get('app.base_url', ''), '/');
$previewRows = static fn (array $rows): array => array_slice(array_values($rows), 0, 1);
?>
<div class="page-grid bank-page-grid">
    <aside class="side-card">
        <h2>Zakres etapu 7</h2>
        <ul>
            <li>lista stałych wystawców z CSV,</li>
            <li>faktury kosztowe z KSeF,</li>
            <li>lokalne pliki PDF z pulpitu,</li>
            <li>kontrola kompletnej paczki dla księgowej.</li>
        </ul>
    </aside>

    <section class="page-stack">
        <?php if ($scrollTarget !== ''): ?>
            <div hidden data-scroll-target="<?= htmlspecialchars($scrollTarget, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></div>
        <?php endif; ?>

        <?php foreach (($alerts ?? []) as $index => $alert): ?>
            <div class="alert alert-<?= htmlspecialchars((string) $alert['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" id="accountant-package-alert-<?= $index ?>">
                <strong><?= htmlspecialchars((string) strtoupper($alert['type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                <p><?= htmlspecialchars((string) $alert['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <button type="button" data-dismiss="#accountant-package-alert-<?= $index ?>">x</button>
            </div>
        <?php endforeach; ?>

        <article class="panel">
            <h2><?= htmlspecialchars((string) $pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars((string) $pageDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <p class="small-note">Moduł będzie pracował na lokalnym folderze pulpitu bieżącej stacji roboczej i pozostanie spójny z wcześniejszymi ekranami aplikacji.</p>
        </article>

        <article class="card">
            <div class="section-head">
                <div>
                    <h3>Stan kroku 1</h3>
                    <p class="small-note">Przygotowany jest punkt wejścia do modułu. Krok 2 dokłada odczyt listy stałych wystawców z lokalnego pliku CSV.</p>
                </div>
            </div>

            <div class="metrics">
                <div class="metric">
                    <span>Routing</span>
                    <strong>gotowy</strong>
                </div>
                <div class="metric">
                    <span>Kontroler</span>
                    <strong>gotowy</strong>
                </div>
                <div class="metric">
                    <span>Widok</span>
                    <strong>gotowy</strong>
                </div>
                <div class="metric">
                    <span>Logika danych</span>
                    <strong><?= $catalog !== null ? 'CSV gotowy' : 'czeka na plik' ?></strong>
                </div>
            </div>
        </article>

        <article class="card">
            <div class="section-head">
                <div>
                    <h3>Źródła danych planowane w module</h3>
                    <p class="small-note">W kolejnych krokach aplikacja będzie czytała dane z następujących miejsc.</p>
                </div>
            </div>

            <table class="status-list">
                <thead>
                    <tr>
                        <th>Źródło</th>
                        <th>Status</th>
                        <th>Uwagi</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Lista stałych wystawców</td>
                        <td><span class="badge <?= $catalog !== null ? 'ok' : 'warn' ?>"><?= $catalog !== null ? 'gotowe w kroku 2' : 'czeka na plik' ?></span></td>
                        <td>Oczekiwana ścieżka: <?= htmlspecialchars($expectedCsvPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    </tr>
                    <tr>
                        <td>Faktury kosztowe KSeF</td>
                        <td><span class="badge <?= $ksefPackage !== null ? 'ok' : 'warn' ?>"><?= $ksefPackage !== null ? 'gotowe w kroku 3' : 'do wdrożenia' ?></span></td>
                        <td>Pobieranie live dla miesiąca wybranego przez użytkownika z użyciem cache metadanych KSeF.</td>
                    </tr>
                    <tr>
                        <td>Lokalne pliki PDF</td>
                        <td><span class="badge <?= $documentCatalog !== null ? 'ok' : 'warn' ?>"><?= $documentCatalog !== null ? 'gotowe w kroku 4' : 'do wdrożenia' ?></span></td>
                        <td>Folder roboczy: <?= htmlspecialchars($desktopFolderPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>, bez podfolderów.</td>
                    </tr>
                    <tr>
                        <td>Eksport zestawienia CSV</td>
                        <td><span class="badge warn">do wdrożenia</span></td>
                        <td>Finalny raport kompletnej paczki dla księgowej.</td>
                    </tr>
                </tbody>
            </table>
        </article>

        <article class="card">
            <div class="section-head">
                <div>
                    <h3>Lista stałych wystawców</h3>
                    <p class="small-note">Moduł czyta plik średnikowy bez nagłówka w formacie: nazwa wystawcy;spodziewana liczba faktur.</p>
                </div>
            </div>

            <?php if ($catalog === null): ?>
                <p class="small-note">Po znalezieniu pliku CSV aplikacja pokaże tutaj listę stałych wystawców i sumę oczekiwanych faktur.</p>
            <?php else: ?>
                <div class="metrics">
                    <div class="metric">
                        <span>Plik CSV</span>
                        <strong>wykryty</strong>
                        <p class="metric-path"><?= htmlspecialchars((string) ($catalog['csv_path'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                    </div>
                    <div class="metric">
                        <span>Stali wystawcy</span>
                        <strong><?= htmlspecialchars((string) ($catalog['summary']['issuer_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Oczekiwane faktury</span>
                        <strong><?= htmlspecialchars((string) ($catalog['summary']['expected_invoice_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                </div>

                <details class="accordion-panel">
                    <summary class="accordion-summary">
                        <div>
                            <strong class="accordion-title">Podgląd listy wystawców</strong>
                            <p class="small-note">Domyślnie widać pierwszy wiersz. Rozwiń sekcję, aby zobaczyć całą listę.</p>
                        </div>
                        <span class="accordion-hint">Rozwiń</span>
                    </summary>

                    <div class="accordion-preview">
                        <table class="status-list">
                            <thead>
                                <tr>
                                    <th>Lp</th>
                                    <th>Wystawca</th>
                                    <th>Spodziewana liczba faktur</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($previewRows((array) ($catalog['issuers'] ?? [])) as $issuer): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) ($issuer['position'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) ($issuer['issuer_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) ($issuer['expected_invoice_count'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="accordion-full">
                        <div class="table-wrap">
                            <table class="status-list">
                                <thead>
                                    <tr>
                                        <th>Lp</th>
                                        <th>Wystawca</th>
                                        <th>Spodziewana liczba faktur</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (($catalog['issuers'] ?? []) as $issuer): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string) ($issuer['position'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) ($issuer['issuer_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) ($issuer['expected_invoice_count'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </details>
            <?php endif; ?>
        </article>

        <article class="card">
            <div class="section-head">
                <div>
                    <h3>Lokalny folder dokumentów</h3>
                    <p class="small-note">Ten krok sprawdza tylko pliki widoczne bezpośrednio w folderze roboczym. Podfoldery są pomijane zgodnie z ustaleniami.</p>
                </div>
            </div>

            <?php if ($documentCatalog === null): ?>
                <p class="small-note">Po wykryciu folderu aplikacja pokaże tutaj listę plików PDF gotowych do dalszej analizy.</p>
            <?php else: ?>
                <div class="metrics">
                    <div class="metric">
                        <span>Folder roboczy</span>
                        <strong>wykryty</strong>
                        <p class="metric-path"><?= htmlspecialchars((string) ($documentCatalog['source_directory'] ?? $desktopFolderPath), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                    </div>
                    <div class="metric">
                        <span>Pliki PDF</span>
                        <strong><?= htmlspecialchars((string) ($documentCatalog['summary']['pdf_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Inne pliki</span>
                        <strong><?= htmlspecialchars((string) ($documentCatalog['summary']['other_file_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Wszystkie pliki</span>
                        <strong><?= htmlspecialchars((string) ($documentCatalog['summary']['total_file_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                </div>

                <?php if (($documentCatalog['pdf_files'] ?? []) === []): ?>
                    <p class="small-note">W folderze nie wykryto jeszcze żadnych plików PDF.</p>
                <?php else: ?>
                    <details class="accordion-panel">
                        <summary class="accordion-summary">
                            <div>
                                <strong class="accordion-title">Lista plików PDF</strong>
                                <p class="small-note">Zwinięty widok pokazuje pierwszy plik z folderu.</p>
                            </div>
                            <span class="accordion-hint">Rozwiń</span>
                        </summary>

                        <div class="accordion-preview">
                            <table class="status-list">
                                <thead>
                                    <tr>
                                        <th>Lp</th>
                                        <th>Plik PDF</th>
                                        <th>Rozmiar</th>
                                        <th>Zmodyfikowano</th>
                                        <th>Ścieżka</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($previewRows((array) ($documentCatalog['pdf_files'] ?? [])) as $file): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string) ($file['position'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) ($file['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) ($file['size_label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) ($file['modified_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td class="cell-note"><?= htmlspecialchars((string) ($file['path'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="accordion-full">
                            <div class="table-wrap">
                                <table class="status-list">
                                    <thead>
                                        <tr>
                                            <th>Lp</th>
                                            <th>Plik PDF</th>
                                            <th>Rozmiar</th>
                                            <th>Zmodyfikowano</th>
                                            <th>Ścieżka</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (($documentCatalog['pdf_files'] ?? []) as $file): ?>
                                            <tr>
                                                <td><?= htmlspecialchars((string) ($file['position'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) ($file['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) ($file['size_label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) ($file['modified_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td class="cell-note"><?= htmlspecialchars((string) ($file['path'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </details>
                <?php endif; ?>

                <?php if (($documentCatalog['other_files'] ?? []) !== []): ?>
                    <details class="accordion-panel">
                        <summary class="accordion-summary">
                            <div>
                                <strong class="accordion-title">Pozostałe pliki w folderze</strong>
                                <p class="small-note">Dodatkowy podgląd plików innych niż PDF.</p>
                            </div>
                            <span class="accordion-hint">Rozwiń</span>
                        </summary>

                        <div class="accordion-preview">
                            <table class="status-list">
                                <thead>
                                    <tr>
                                        <th>Lp</th>
                                        <th>Nazwa</th>
                                        <th>Typ</th>
                                        <th>Rozmiar</th>
                                        <th>Zmodyfikowano</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($previewRows((array) ($documentCatalog['other_files'] ?? [])) as $file): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string) ($file['position'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) ($file['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) strtoupper((string) ($file['extension'] ?? '-')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) ($file['size_label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) ($file['modified_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="accordion-full">
                            <div class="table-wrap">
                                <table class="status-list">
                                    <thead>
                                        <tr>
                                            <th colspan="5">Pozostałe pliki w folderze</th>
                                        </tr>
                                        <tr>
                                            <th>Lp</th>
                                            <th>Nazwa</th>
                                            <th>Typ</th>
                                            <th>Rozmiar</th>
                                            <th>Zmodyfikowano</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (($documentCatalog['other_files'] ?? []) as $file): ?>
                                            <tr>
                                                <td><?= htmlspecialchars((string) ($file['position'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) ($file['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) strtoupper((string) ($file['extension'] ?? '-')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) ($file['size_label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) ($file['modified_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </details>
                <?php endif; ?>
            <?php endif; ?>
        </article>

        <article class="card" id="accountant-package-pdf-analysis-form">
            <div class="section-head">
                <div>
                    <h3>Analiza PDF</h3>
                    <p class="small-note">Ten krok sprawdza, czy pliki PDF mają warstwę tekstową. Nie rozpoznajemy tu jeszcze pojedynczych faktur ani nie łączymy ich z KSeF.</p>
                </div>
            </div>

            <form method="post" action="<?= htmlspecialchars($baseUrl . '/accountant-package/analyze-pdfs', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="settings-form">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <input type="hidden" name="selected_month" value="<?= htmlspecialchars($selectedMonth, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

                <div class="form-actions">
                    <button type="submit" class="button">Sprawdź warstwę tekstową PDF</button>
                </div>
            </form>
        </article>

        <article class="card" id="accountant-package-pdf-analysis-results">
            <div class="section-head">
                <div>
                    <h3>Wynik analizy PDF</h3>
                    <p class="small-note">Tutaj widać, z których plików da się odczytać tekst i które dokumenty będą wymagały kolejnego kroku z OCR lub analizą AI.</p>
                </div>
            </div>

            <?php if ($pdfAnalysisPackage === null): ?>
                <p class="small-note">Po uruchomieniu analizy aplikacja pokaże tutaj status każdego PDF-u oraz krótki podgląd odczytanego tekstu.</p>
            <?php else: ?>
                <div class="metrics">
                    <div class="metric">
                        <span>Przeanalizowane PDF</span>
                        <strong><?= htmlspecialchars((string) ($pdfAnalysisPackage['summary']['document_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Warstwa tekstowa</span>
                        <strong><?= htmlspecialchars((string) ($pdfAnalysisPackage['summary']['text_ready_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Skan lub pusty PDF</span>
                        <strong><?= htmlspecialchars((string) ($pdfAnalysisPackage['summary']['scan_like_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Błędy odczytu</span>
                        <strong><?= htmlspecialchars((string) ($pdfAnalysisPackage['summary']['error_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                </div>

                <p class="small-note">Ostatnia analiza: <strong><?= htmlspecialchars((string) ($pdfAnalysisPackage['analyzed_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></p>

                <details class="accordion-panel">
                    <summary class="accordion-summary">
                        <div>
                            <strong class="accordion-title">Podgląd wyników analizy PDF</strong>
                            <p class="small-note">Zwinięty widok pokazuje pierwszy przeanalizowany dokument.</p>
                        </div>
                        <span class="accordion-hint">Rozwiń</span>
                    </summary>

                    <div class="accordion-preview">
                        <table class="status-list">
                            <thead>
                                <tr>
                                    <th>Lp</th>
                                    <th>Plik</th>
                                    <th>Strony</th>
                                    <th>Status</th>
                                    <th>Uwagi</th>
                                    <th>Podgląd tekstu</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($previewRows((array) ($pdfAnalysisPackage['documents'] ?? [])) as $document): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) ($document['position'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) ($document['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) (($document['page_count'] ?? null) !== null ? $document['page_count'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td>
                                            <span class="badge <?= htmlspecialchars((string) ($document['analysis_badge_class'] ?? 'warn'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                                <?= htmlspecialchars((string) ($document['analysis_status_label'] ?? 'Brak danych'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                            </span>
                                        </td>
                                        <td class="cell-note"><?= htmlspecialchars((string) ($document['analysis_note'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td class="cell-note"><?= htmlspecialchars((string) (($document['text_preview'] ?? '') !== '' ? $document['text_preview'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="accordion-full">
                        <div class="table-wrap">
                            <table class="status-list">
                                <thead>
                                    <tr>
                                        <th>Lp</th>
                                        <th>Plik</th>
                                        <th>Strony</th>
                                        <th>Status</th>
                                        <th>Uwagi</th>
                                        <th>Podgląd tekstu</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (($pdfAnalysisPackage['documents'] ?? []) as $document): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string) ($document['position'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) ($document['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) (($document['page_count'] ?? null) !== null ? $document['page_count'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td>
                                                <span class="badge <?= htmlspecialchars((string) ($document['analysis_badge_class'] ?? 'warn'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                                    <?= htmlspecialchars((string) ($document['analysis_status_label'] ?? 'Brak danych'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                                </span>
                                            </td>
                                            <td class="cell-note"><?= htmlspecialchars((string) ($document['analysis_note'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td class="cell-note"><?= htmlspecialchars((string) (($document['text_preview'] ?? '') !== '' ? $document['text_preview'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </details>
            <?php endif; ?>
        </article>

        <article class="card" id="accountant-package-pdf-candidates-form">
            <div class="section-head">
                <div>
                    <h3>Kandydaci dokumentów z PDF</h3>
                    <p class="small-note">Tutaj próbujemy rozbić tekstowe PDF-y na pojedyncze dokumenty i wyciągnąć podstawowe dane: wystawcę, numer, kwotę i datę.</p>
                </div>
            </div>

            <form method="post" action="<?= htmlspecialchars($baseUrl . '/accountant-package/parse-pdf-candidates', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="settings-form">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <input type="hidden" name="selected_month" value="<?= htmlspecialchars($selectedMonth, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

                <div class="form-actions">
                    <button type="submit" class="button">Rozpoznaj kandydatów z PDF tekstowych</button>
                </div>
            </form>
        </article>

        <article class="card" id="accountant-package-pdf-candidates-results">
            <div class="section-head">
                <div>
                    <h3>Wynik parsowania PDF</h3>
                    <p class="small-note">To jest warstwa pośrednia przed dalszym przypisaniem do stałych wystawców i przed odrzucaniem duplikatów z KSeF.</p>
                </div>
            </div>

            <?php if ($pdfCandidatesPackage === null): ?>
                <p class="small-note">Po uruchomieniu parsowania aplikacja pokaże tutaj dokumenty rozpoznane z tekstowych PDF-ów oraz pliki, które pozostały skanami.</p>
            <?php else: ?>
                <div class="metrics">
                    <div class="metric">
                        <span>Pliki źródłowe</span>
                        <strong><?= htmlspecialchars((string) ($pdfCandidatesPackage['summary']['source_file_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Rozpoznane dokumenty</span>
                        <strong><?= htmlspecialchars((string) ($pdfCandidatesPackage['summary']['parsed_document_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Pliki tekstowe</span>
                        <strong><?= htmlspecialchars((string) ($pdfCandidatesPackage['summary']['text_file_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Skan / błąd</span>
                        <strong><?= htmlspecialchars((string) ((int) ($pdfCandidatesPackage['summary']['scan_like_count'] ?? 0) + (int) ($pdfCandidatesPackage['summary']['error_count'] ?? 0)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                </div>

                <p class="small-note">Ostatnie parsowanie: <strong><?= htmlspecialchars((string) ($pdfCandidatesPackage['parsed_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></p>

                <details class="accordion-panel">
                    <summary class="accordion-summary">
                        <div>
                            <strong class="accordion-title">Podgląd rozpoznanych kandydatów</strong>
                            <p class="small-note">Podejrzane odczyty są oznaczane do ręcznej weryfikacji.</p>
                        </div>
                        <span class="accordion-hint">Rozwiń</span>
                    </summary>

                    <div class="accordion-preview">
                        <table class="status-list">
                            <thead>
                                <tr>
                                    <th>Lp</th>
                                    <th>Plik / dokument</th>
                                    <th>Strona PDF</th>
                                    <th>Tryb rozpoznania</th>
                                    <th>Wystawca</th>
                                    <th>Numer</th>
                                    <th>Kwota</th>
                                    <th>Data</th>
                                    <th>Status</th>
                                    <th>Uwagi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($previewRows((array) ($pdfCandidatesPackage['documents'] ?? [])) as $document): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) ($document['position'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td class="cell-note">
                                            <strong><?= htmlspecialchars((string) ($document['source_file_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong><br>
                                        </td>
                                        <td><?= htmlspecialchars((string) ($document['source_page_label'] ?? $document['source_page_number'] ?? $document['source_chunk_index'] ?? 1), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) ($document['recognition_mode_label'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) (($document['issuer_name'] ?? '') !== '' ? $document['issuer_name'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) (($document['invoice_number'] ?? '') !== '' ? $document['invoice_number'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) (($document['amount_due'] ?? '') !== '' ? ($document['amount_due'] . ' ' . ($document['currency'] ?? '')) : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) (($document['due_date'] ?? '') !== '' ? $document['due_date'] : (($document['issue_date'] ?? '') !== '' ? $document['issue_date'] : '-')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td>
                                            <span class="badge <?= htmlspecialchars((string) ($document['status_badge_class'] ?? 'warn'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                                <?= htmlspecialchars((string) ($document['status_label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                            </span>
                                        </td>
                                        <td class="cell-note"><?= htmlspecialchars((string) ($document['note'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="accordion-full">
                        <div class="table-wrap">
                            <table class="status-list">
                                <thead>
                                <tr>
                                    <th>Lp</th>
                                    <th>Plik / dokument</th>
                                    <th>Strona PDF</th>
                                    <th>Tryb rozpoznania</th>
                                    <th>Wystawca</th>
                                    <th>Numer</th>
                                    <th>Kwota</th>
                                    <th>Data</th>
                                    <th>Status</th>
                                        <th>Uwagi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (($pdfCandidatesPackage['documents'] ?? []) as $document): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string) ($document['position'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td class="cell-note">
                                                <strong><?= htmlspecialchars((string) ($document['source_file_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong><br>
                                            </td>
                                            <td><?= htmlspecialchars((string) ($document['source_page_label'] ?? $document['source_page_number'] ?? $document['source_chunk_index'] ?? 1), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) ($document['recognition_mode_label'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) (($document['issuer_name'] ?? '') !== '' ? $document['issuer_name'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) (($document['invoice_number'] ?? '') !== '' ? $document['invoice_number'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) (($document['amount_due'] ?? '') !== '' ? ($document['amount_due'] . ' ' . ($document['currency'] ?? '')) : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) (($document['due_date'] ?? '') !== '' ? $document['due_date'] : (($document['issue_date'] ?? '') !== '' ? $document['issue_date'] : '-')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td>
                                                <span class="badge <?= htmlspecialchars((string) ($document['status_badge_class'] ?? 'warn'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                                    <?= htmlspecialchars((string) ($document['status_label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                                </span>
                                            </td>
                                            <td class="cell-note">
                                                <?= htmlspecialchars((string) ($document['note'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                                <?php if (($document['text_preview'] ?? '') !== ''): ?>
                                                    <br><span class="small-note"><?= htmlspecialchars((string) $document['text_preview'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </details>
            <?php endif; ?>
        </article>

        <article class="card" id="accountant-package-ksef-form">
            <div class="section-head">
                <div>
                    <h3>Faktury kosztowe z KSeF</h3>
                    <p class="small-note">W tym kroku pobieramy miesięczne metadane kosztowe KSeF bez dociągania XML-i pojedynczych faktur, żeby utrzymać lekki i szybki podgląd.</p>
                </div>
            </div>

            <div class="ksef-meta">
                <span class="badge ok">Środowisko: <?= htmlspecialchars($activeEnvironment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <span class="badge <?= $tokenPresenceLabel === 'ustawione' ? 'ok' : 'warn' ?>">Token: <?= htmlspecialchars($tokenPresenceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            </div>
            <p class="small-note">
                Base URL aktywnego środowiska:
                <strong><?= htmlspecialchars($environmentBaseUrl !== '' ? $environmentBaseUrl : 'brak', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
            </p>

            <form method="post" action="<?= htmlspecialchars($baseUrl . '/accountant-package/fetch-ksef', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="settings-form">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

                <div class="form-grid">
                    <label class="form-field">
                        <span>Miesiąc</span>
                        <input type="month" name="ksef_month" value="<?= htmlspecialchars($selectedMonth, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
                    </label>
                </div>

                <div class="form-actions">
                    <button type="submit" class="button">Pobierz faktury kosztowe KSeF</button>
                </div>
            </form>
        </article>

        <article class="card" id="accountant-package-ksef-results">
            <div class="section-head">
                <div>
                    <h3>Podgląd miesięcznych danych KSeF</h3>
                    <p class="small-note">To jest baza wejściowa do dalszego dopasowania faktur do listy stałych wystawców i dokumentów z pulpitu.</p>
                </div>
            </div>

            <?php if ($ksefPackage === null): ?>
                <p class="small-note">Po pobraniu miesiąca aplikacja pokaże tutaj listę faktur kosztowych KSeF oraz podsumowanie liczby wystawców.</p>
            <?php else: ?>
                <div class="metrics">
                    <div class="metric">
                        <span>Miesiąc</span>
                        <strong><?= htmlspecialchars((string) ($ksefPackage['selected_month'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Faktury kosztowe</span>
                        <strong><?= htmlspecialchars((string) ($ksefPackage['summary']['invoice_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Unikalni wystawcy</span>
                        <strong><?= htmlspecialchars((string) ($ksefPackage['summary']['issuer_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Ostatnie pobranie</span>
                        <strong><?= htmlspecialchars((string) ($ksefPackage['fetched_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                </div>

                <?php if (($ksefPackage['invoices'] ?? []) === []): ?>
                    <p class="small-note">KSeF nie zwrócił faktur kosztowych dla tego miesiąca.</p>
                <?php else: ?>
                    <details class="accordion-panel">
                        <summary class="accordion-summary">
                            <div>
                                <strong class="accordion-title">Podgląd faktur KSeF</strong>
                                <p class="small-note">Zwinięty widok pokazuje pierwszy pobrany dokument.</p>
                            </div>
                            <span class="accordion-hint">Rozwiń</span>
                        </summary>

                        <div class="accordion-preview">
                            <table class="status-list">
                                <thead>
                                    <tr>
                                        <th>Lp</th>
                                        <th>Wystawca</th>
                                        <th>NIP</th>
                                        <th>Numer faktury</th>
                                        <th>Data wystawienia</th>
                                        <th>Kwota brutto</th>
                                        <th>Waluta</th>
                                        <th>Numer KSeF</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($previewRows((array) ($ksefPackage['invoices'] ?? [])) as $invoice): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string) ($invoice['position'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) (($invoice['issuer_name'] ?? '') !== '' ? $invoice['issuer_name'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) (($invoice['issuer_tax_id'] ?? '') !== '' ? $invoice['issuer_tax_id'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) (($invoice['invoice_number'] ?? '') !== '' ? $invoice['invoice_number'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) (($invoice['issue_date'] ?? '') !== '' ? $invoice['issue_date'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) (($invoice['formatted_gross_amount'] ?? '') !== '' ? $invoice['formatted_gross_amount'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) (($invoice['currency'] ?? '') !== '' ? strtoupper((string) $invoice['currency']) : 'PLN'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td class="cell-note"><?= htmlspecialchars((string) (($invoice['ksef_reference_number'] ?? '') !== '' ? $invoice['ksef_reference_number'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="accordion-full">
                            <div class="table-wrap">
                                <table class="status-list">
                                    <thead>
                                        <tr>
                                            <th>Lp</th>
                                            <th>Wystawca</th>
                                            <th>NIP</th>
                                            <th>Numer faktury</th>
                                            <th>Data wystawienia</th>
                                            <th>Kwota brutto</th>
                                            <th>Waluta</th>
                                            <th>Numer KSeF</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (($ksefPackage['invoices'] ?? []) as $invoice): ?>
                                            <tr>
                                                <td><?= htmlspecialchars((string) ($invoice['position'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) (($invoice['issuer_name'] ?? '') !== '' ? $invoice['issuer_name'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) (($invoice['issuer_tax_id'] ?? '') !== '' ? $invoice['issuer_tax_id'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) (($invoice['invoice_number'] ?? '') !== '' ? $invoice['invoice_number'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) (($invoice['issue_date'] ?? '') !== '' ? $invoice['issue_date'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) (($invoice['formatted_gross_amount'] ?? '') !== '' ? $invoice['formatted_gross_amount'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) (($invoice['currency'] ?? '') !== '' ? strtoupper((string) $invoice['currency']) : 'PLN'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td class="cell-note"><?= htmlspecialchars((string) (($invoice['ksef_reference_number'] ?? '') !== '' ? $invoice['ksef_reference_number'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </details>
                <?php endif; ?>
            <?php endif; ?>
        </article>

        <article class="card" id="accountant-package-summary">
            <div class="section-head">
                <div>
                    <h3>Zestawienie dla księgowej</h3>
                    <p class="small-note">Na tym etapie zestawienie łączy faktury kosztowe z KSeF i rozpoznane lokalne PDF-y. Duplikaty PDF względem KSeF są pomijane, a niepewne odczyty trafiają do ręcznej weryfikacji.</p>
                </div>
                <?php if ($packageSummary !== null): ?>
                    <div class="section-actions">
                        <a
                            href="<?= htmlspecialchars($baseUrl . '/accountant-package/export?selected_month=' . urlencode($selectedMonth), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                            class="button"
                        >
                            Pobierz zestawienie CSV
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($packageSummary === null): ?>
                <p class="small-note">Najpierw pobierz miesięczne faktury kosztowe z KSeF. Jeśli chcesz uwzględnić lokalne pliki, uruchom też parsowanie kandydatów z PDF.</p>
            <?php else: ?>
                <div class="metrics">
                    <div class="metric">
                        <span>Stali wystawcy</span>
                        <strong><?= htmlspecialchars((string) ($packageSummary['summary']['recurring_issuer_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Kompletni</span>
                        <strong><?= htmlspecialchars((string) ($packageSummary['summary']['complete_issuer_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Z brakami</span>
                        <strong><?= htmlspecialchars((string) ($packageSummary['summary']['missing_issuer_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Dokumenty z KSeF</span>
                        <strong><?= htmlspecialchars((string) ($packageSummary['summary']['ksef_document_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Dokumenty z PDF</span>
                        <strong><?= htmlspecialchars((string) ($packageSummary['summary']['pdf_document_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Duplikaty PDF</span>
                        <strong><?= htmlspecialchars((string) ($packageSummary['summary']['pdf_duplicate_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Ręczna weryfikacja</span>
                        <strong><?= htmlspecialchars((string) ($packageSummary['summary']['manual_review_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Inne dokumenty</span>
                        <strong><?= htmlspecialchars((string) ($packageSummary['summary']['other_invoice_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                </div>

                <details class="accordion-panel">
                    <summary class="accordion-summary">
                        <div>
                            <strong class="accordion-title">Stali wystawcy i kompletność miesiąca</strong>
                            <p class="small-note">Zwinięty widok pokazuje pierwszy wiersz zestawienia.</p>
                        </div>
                        <span class="accordion-hint">Rozwiń</span>
                    </summary>

                    <div class="accordion-preview">
                        <table class="status-list">
                            <thead>
                                <tr>
                                    <th>Lp</th>
                                    <th>Stały wystawca</th>
                                    <th>Spodziewane</th>
                                    <th>Znalezione dokumenty</th>
                                    <th>Status</th>
                                    <th>Numery, kwoty i źródło</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($previewRows((array) ($packageSummary['recurring_rows'] ?? [])) as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) ($row['position'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) ($row['issuer_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) ($row['expected_invoice_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars((string) ($row['matched_invoice_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td>
                                            <span class="badge <?= htmlspecialchars((string) ($row['status_badge_class'] ?? 'warn'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                                <?= htmlspecialchars((string) ($row['status_label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                            </span>
                                        </td>
                                        <td class="cell-note">
                                            <?php if (($row['matched_invoices'] ?? []) === []): ?>
                                                -
                                            <?php else: ?>
                                                <?php $invoice = $row['matched_invoices'][0]; ?>
                                                <div class="match-entry">
                                                    <span class="badge <?= htmlspecialchars((string) ($invoice['source_badge_class'] ?? 'warn'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars((string) ($invoice['source_label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                                    <strong><?= htmlspecialchars((string) (($invoice['invoice_number'] ?? '') !== '' ? $invoice['invoice_number'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                                                    <span><?= htmlspecialchars((string) (($invoice['formatted_amount_due'] ?? '') !== '' ? $invoice['formatted_amount_due'] : (($invoice['formatted_gross_amount'] ?? '') !== '' ? $invoice['formatted_gross_amount'] : '-')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                                    <span><?= htmlspecialchars((string) (($invoice['due_date'] ?? '') !== '' ? $invoice['due_date'] : (($invoice['issue_date'] ?? '') !== '' ? $invoice['issue_date'] : '-')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                                    <?php if (($invoice['source_reference'] ?? '') !== ''): ?>
                                                        <span class="small-note"><?= htmlspecialchars((string) $invoice['source_reference'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="accordion-full">
                        <div class="table-wrap">
                    <table class="status-list">
                        <thead>
                            <tr>
                                <th>Lp</th>
                                <th>Stały wystawca</th>
                                <th>Spodziewane</th>
                                <th>Znalezione dokumenty</th>
                                <th>Status</th>
                                <th>Numery, kwoty i źródło</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (($packageSummary['recurring_rows'] ?? []) as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) ($row['position'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($row['issuer_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($row['expected_invoice_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td>
                                        <?= htmlspecialchars((string) ($row['matched_invoice_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                        <?php if (!empty($row['surplus_invoice_count'])): ?>
                                            <br><span class="small-note">nadmiar: <?= htmlspecialchars((string) $row['surplus_invoice_count'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                        <?php elseif (!empty($row['missing_invoice_count'])): ?>
                                            <br><span class="small-note">brakuje: <?= htmlspecialchars((string) $row['missing_invoice_count'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= htmlspecialchars((string) ($row['status_badge_class'] ?? 'warn'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                            <?= htmlspecialchars((string) ($row['status_label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td class="cell-note">
                                        <?php if (($row['matched_invoices'] ?? []) === []): ?>
                                            -
                                        <?php else: ?>
                                            <?php foreach (($row['matched_invoices'] ?? []) as $invoice): ?>
                                                <div class="match-entry">
                                                    <span class="badge <?= htmlspecialchars((string) ($invoice['source_badge_class'] ?? 'warn'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars((string) ($invoice['source_label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                                    <strong><?= htmlspecialchars((string) (($invoice['invoice_number'] ?? '') !== '' ? $invoice['invoice_number'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                                                    <span><?= htmlspecialchars((string) (($invoice['formatted_amount_due'] ?? '') !== '' ? $invoice['formatted_amount_due'] : (($invoice['formatted_gross_amount'] ?? '') !== '' ? $invoice['formatted_gross_amount'] : '-')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                                    <span><?= htmlspecialchars((string) (($invoice['due_date'] ?? '') !== '' ? $invoice['due_date'] : (($invoice['issue_date'] ?? '') !== '' ? $invoice['issue_date'] : '-')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                                    <?php if (($invoice['source_reference'] ?? '') !== ''): ?>
                                                        <span class="small-note"><?= htmlspecialchars((string) $invoice['source_reference'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                                    <?php endif; ?>
                                                    <span class="small-note"><?= htmlspecialchars((string) ($invoice['match_note'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                        </div>
                    </div>
                </details>

                <details class="accordion-panel">
                    <summary class="accordion-summary">
                        <div>
                            <strong class="accordion-title">Inne dokumenty spoza listy stałych wystawców</strong>
                            <p class="small-note">Tu trafiają dokumenty z KSeF albo PDF, które nie pasują do żadnego stałego wystawcy.</p>
                        </div>
                        <span class="accordion-hint">Rozwiń</span>
                    </summary>

                    <div class="accordion-preview">
                        <table class="status-list">
                            <thead>
                                <tr>
                                    <th>Lp</th>
                                    <th>Źródło</th>
                                    <th>Wystawca</th>
                                    <th>Numer dokumentu</th>
                                    <th>Kwota</th>
                                    <th>Data</th>
                                    <th>Uwagi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (($packageSummary['other_invoices'] ?? []) === []): ?>
                                    <tr>
                                        <td colspan="7">Brak innych dokumentów poza listą stałych wystawców.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($previewRows((array) ($packageSummary['other_invoices'] ?? [])) as $invoice): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string) ($invoice['position'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td>
                                                <span class="badge <?= htmlspecialchars((string) ($invoice['source_badge_class'] ?? 'warn'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars((string) ($invoice['source_label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                            </td>
                                            <td><?= htmlspecialchars((string) (($invoice['issuer_name'] ?? '') !== '' ? $invoice['issuer_name'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) (($invoice['invoice_number'] ?? '') !== '' ? $invoice['invoice_number'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) (($invoice['formatted_amount_due'] ?? '') !== '' ? $invoice['formatted_amount_due'] : (($invoice['formatted_gross_amount'] ?? '') !== '' ? $invoice['formatted_gross_amount'] : '-')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) (($invoice['due_date'] ?? '') !== '' ? $invoice['due_date'] : (($invoice['issue_date'] ?? '') !== '' ? $invoice['issue_date'] : '-')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td class="cell-note">
                                                <?= htmlspecialchars((string) ($invoice['match_note'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                                <?php if (($invoice['source_reference'] ?? '') !== ''): ?>
                                                    <br><span class="small-note"><?= htmlspecialchars((string) $invoice['source_reference'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="accordion-full">
                        <div class="table-wrap">
                            <table class="status-list">
                                <thead>
                                    <tr>
                                        <th colspan="7">Inne dokumenty spoza listy stałych wystawców</th>
                                    </tr>
                                    <tr>
                                        <th>Lp</th>
                                        <th>Źródło</th>
                                        <th>Wystawca</th>
                                        <th>Numer dokumentu</th>
                                        <th>Kwota</th>
                                        <th>Data</th>
                                        <th>Uwagi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (($packageSummary['other_invoices'] ?? []) === []): ?>
                                        <tr>
                                            <td colspan="7">Brak innych dokumentów poza listą stałych wystawców.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach (($packageSummary['other_invoices'] ?? []) as $invoice): ?>
                                            <tr>
                                                <td><?= htmlspecialchars((string) ($invoice['position'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td>
                                                    <span class="badge <?= htmlspecialchars((string) ($invoice['source_badge_class'] ?? 'warn'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars((string) ($invoice['source_label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                                </td>
                                                <td><?= htmlspecialchars((string) (($invoice['issuer_name'] ?? '') !== '' ? $invoice['issuer_name'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) (($invoice['invoice_number'] ?? '') !== '' ? $invoice['invoice_number'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) (($invoice['formatted_amount_due'] ?? '') !== '' ? $invoice['formatted_amount_due'] : (($invoice['formatted_gross_amount'] ?? '') !== '' ? $invoice['formatted_gross_amount'] : '-')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) (($invoice['due_date'] ?? '') !== '' ? $invoice['due_date'] : (($invoice['issue_date'] ?? '') !== '' ? $invoice['issue_date'] : '-')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td class="cell-note">
                                                    <?= htmlspecialchars((string) ($invoice['match_note'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                                    <?php if (($invoice['source_reference'] ?? '') !== ''): ?>
                                                        <br><span class="small-note"><?= htmlspecialchars((string) $invoice['source_reference'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </details>

                <details class="accordion-panel">
                    <summary class="accordion-summary">
                        <div>
                            <strong class="accordion-title">Dokumenty do ręcznej weryfikacji</strong>
                            <p class="small-note">Tu trafiają niepewne odczyty PDF i pozycje, których nie liczymy jeszcze do kompletności.</p>
                        </div>
                        <span class="accordion-hint">Rozwiń</span>
                    </summary>

                    <div class="accordion-preview">
                        <table class="status-list">
                            <thead>
                                <tr>
                                    <th>Lp</th>
                                    <th>Źródło</th>
                                    <th>Wystawca</th>
                                    <th>Numer dokumentu</th>
                                    <th>Kwota</th>
                                    <th>Data</th>
                                    <th>Uwagi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (($packageSummary['manual_review_documents'] ?? []) === []): ?>
                                    <tr>
                                        <td colspan="7">Brak dokumentów wymagających ręcznej weryfikacji.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($previewRows((array) ($packageSummary['manual_review_documents'] ?? [])) as $document): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string) ($document['position'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td>
                                                <span class="badge <?= htmlspecialchars((string) ($document['status_badge_class'] ?? 'warn'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars((string) ($document['source_label'] ?? 'PDF'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                            </td>
                                            <td><?= htmlspecialchars((string) (($document['issuer_name'] ?? '') !== '' ? $document['issuer_name'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) (($document['invoice_number'] ?? '') !== '' ? $document['invoice_number'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) (($document['formatted_amount_due'] ?? '') !== '' ? $document['formatted_amount_due'] : (($document['formatted_gross_amount'] ?? '') !== '' ? $document['formatted_gross_amount'] : '-')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) (($document['due_date'] ?? '') !== '' ? $document['due_date'] : (($document['issue_date'] ?? '') !== '' ? $document['issue_date'] : '-')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            <td class="cell-note">
                                                <?= htmlspecialchars((string) ($document['match_note'] ?? $document['note'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                                <?php if (($document['source_reference'] ?? '') !== ''): ?>
                                                    <br><span class="small-note"><?= htmlspecialchars((string) $document['source_reference'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="accordion-full">
                        <div class="table-wrap">
                            <table class="status-list">
                                <thead>
                                    <tr>
                                        <th colspan="7">Dokumenty do ręcznej weryfikacji</th>
                                    </tr>
                                    <tr>
                                        <th>Lp</th>
                                        <th>Źródło</th>
                                        <th>Wystawca</th>
                                        <th>Numer dokumentu</th>
                                        <th>Kwota</th>
                                        <th>Data</th>
                                        <th>Uwagi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (($packageSummary['manual_review_documents'] ?? []) === []): ?>
                                        <tr>
                                            <td colspan="7">Brak dokumentów wymagających ręcznej weryfikacji.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach (($packageSummary['manual_review_documents'] ?? []) as $document): ?>
                                            <tr>
                                                <td><?= htmlspecialchars((string) ($document['position'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td>
                                                    <span class="badge <?= htmlspecialchars((string) ($document['status_badge_class'] ?? 'warn'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars((string) ($document['source_label'] ?? 'PDF'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                                </td>
                                                <td><?= htmlspecialchars((string) (($document['issuer_name'] ?? '') !== '' ? $document['issuer_name'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) (($document['invoice_number'] ?? '') !== '' ? $document['invoice_number'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) (($document['formatted_amount_due'] ?? '') !== '' ? $document['formatted_amount_due'] : (($document['formatted_gross_amount'] ?? '') !== '' ? $document['formatted_gross_amount'] : '-')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) (($document['due_date'] ?? '') !== '' ? $document['due_date'] : (($document['issue_date'] ?? '') !== '' ? $document['issue_date'] : '-')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td class="cell-note">
                                                    <?= htmlspecialchars((string) ($document['match_note'] ?? $document['note'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                                    <?php if (($document['source_reference'] ?? '') !== ''): ?>
                                                        <br><span class="small-note"><?= htmlspecialchars((string) $document['source_reference'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                                    <?php endif; ?>
                                                    <?php if (($document['text_preview'] ?? '') !== ''): ?>
                                                        <br><span class="small-note"><?= htmlspecialchars((string) $document['text_preview'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </details>
            <?php endif; ?>
        </article>
    </section>
</div>
