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
/** @var array $checklistState */
/** @var array|null $ksefPackage */
/** @var array|null $packageSummary */
/** @var string $activeEnvironment */
/** @var string $environmentBaseUrl */
/** @var string $tokenPresenceLabel */
/** @var string $aiProvider */
/** @var bool $requiresRemoteAiConfirmation */

$baseUrl = rtrim((string) $config->get('app.base_url', ''), '/');
$summaryMonth = (string) (($ksefPackage['selected_month'] ?? '') !== '' ? $ksefPackage['selected_month'] : '');
?>
<div class="page-grid bank-page-grid">
    <aside class="side-card">
        <h2>Checklista</h2>
        <ul>
            <li>wybierz folder z fakturami PDF,</li>
            <li>wybierz aktualny plik CSV stalych wystawcow,</li>
            <li>wybierz miesiac KSeF,</li>
            <li>wygeneruj zestawienie i pobierz CSV.</li>
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
            <p class="small-note">W tej wersji produkcyjnej ekran prowadzi od razu do finalnego wyniku. Posrednie zestawienia debugowe zostaly schowane z glownego przebiegu.</p>
        </article>

        <article class="card">
            <div class="section-head">
                <div>
                    <h3>Zrodla danych</h3>
                    <p class="small-note">Aplikacja korzysta z jednorazowo wybranych plikow z przegladarki oraz z miesiecznych danych pobieranych z KSeF.</p>
                </div>
            </div>

            <div class="metrics">
                <div class="metric">
                    <span>Pakiet PDF</span>
                    <strong><?= $documentCatalog !== null ? 'wybrany' : 'oczekuje' ?></strong>
                    <p class="metric-path"><?= htmlspecialchars($desktopFolderPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                </div>
                <div class="metric">
                    <span>Plik stalych wystawcow</span>
                    <strong><?= $catalog !== null ? 'wybrany' : 'oczekuje' ?></strong>
                    <p class="metric-path"><?= htmlspecialchars($expectedCsvPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                </div>
                <div class="metric">
                    <span>Pliki PDF</span>
                    <strong><?= htmlspecialchars((string) ($documentCatalog['summary']['pdf_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                </div>
                <div class="metric">
                    <span>Stali wystawcy</span>
                    <strong><?= htmlspecialchars((string) ($catalog['summary']['issuer_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                </div>
            </div>
        </article>

        <article class="card" id="accountant-package-checklist">
            <div class="section-head">
                <div>
                    <h3>Uruchom pakiet dla ksiegowej</h3>
                    <p class="small-note">Przycisk aktywuje sie po wyborze plikow, zaznaczeniu obu checkboxow i wyborze miesiaca. Po wykonaniu formularz wraca do stanu poczatkowego.</p>
                </div>
            </div>

            <div class="ksef-meta">
                <span class="badge ok">Srodowisko: <?= htmlspecialchars($activeEnvironment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <span class="badge <?= $tokenPresenceLabel === 'ustawione' ? 'ok' : 'warn' ?>">Token: <?= htmlspecialchars($tokenPresenceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <span class="badge <?= $requiresRemoteAiConfirmation ? 'warn' : 'ok' ?>">AI: <?= htmlspecialchars($aiProvider, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            </div>
            <p class="small-note">Base URL aktywnego srodowiska: <strong><?= htmlspecialchars($environmentBaseUrl !== '' ? $environmentBaseUrl : 'brak', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></p>

            <form method="post" action="<?= htmlspecialchars($baseUrl . '/accountant-package/run', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="settings-form" data-package-checklist enctype="multipart/form-data">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

                <div class="form-grid">
                    <label class="form-field">
                        <span>Folder z fakturami PDF</span>
                        <input type="file" name="pdf_files[]" accept=".pdf,application/pdf" multiple webkitdirectory directory data-required-pdf-files>
                        <p class="small-note">Wybierz katalog z dokumentami PDF. Pliki sa uzywane tylko do tego jednego przebiegu.</p>
                    </label>

                    <label class="form-field">
                        <span>Lista stalych wystawcow CSV</span>
                        <input type="file" name="csv_file" accept=".csv,text/csv" data-required-csv-file>
                        <p class="small-note">Wybierz aktualny plik `stali_wystawcy.csv`. Plik nie jest trwale zapisywany na serwerze.</p>
                    </label>
                </div>

                <label class="checkbox-line">
                    <input type="checkbox" name="confirm_pdf_ready" value="1" <?= !empty($checklistState['confirm_pdf_ready']) ? 'checked' : '' ?>>
                    Potwierdzam, ze wybralem poprawny pakiet PDF do przetworzenia
                </label>

                <label class="checkbox-line">
                    <input type="checkbox" name="confirm_csv_ready" value="1" <?= !empty($checklistState['confirm_csv_ready']) ? 'checked' : '' ?>>
                    Potwierdzam, ze wybralem aktualny plik CSV stalych wystawcow
                </label>

                <div class="form-grid">
                    <label class="form-field">
                        <span>Miesiac do pobrania z KSeF</span>
                        <input type="month" name="ksef_month" value="<?= htmlspecialchars($selectedMonth, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-required-month required>
                    </label>
                </div>

                <?php if (!empty($requiresRemoteAiConfirmation)): ?>
                    <div class="alert alert-warning">
                        <strong>UWAGA</strong>
                        <p>Aktywny tryb AI to <?= htmlspecialchars((string) $aiProvider, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>. Uruchomienie moze wyslac dane z faktur poza lokalna stacje robocza.</p>
                    </div>
                <?php endif; ?>

                <div class="form-actions">
                    <button type="submit" class="button" data-run-package-button disabled>Uruchom funkcje i wygeneruj zestawienie</button>
                </div>
            </form>
        </article>

        <article class="card" id="accountant-package-summary">
            <div class="section-head">
                <div>
                    <h3>Zestawienie dla ksiegowej</h3>
                    <p class="small-note">Wynik laczy dane z KSeF oraz dokumenty rozpoznane z PDF. Koncowym artefaktem jest plik CSV do przekazania dalej.</p>
                </div>
                <?php if ($packageSummary !== null): ?>
                    <div class="section-actions">
                        <a href="<?= htmlspecialchars($baseUrl . '/accountant-package/export?selected_month=' . urlencode($summaryMonth), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="button">Pobierz zestawienie CSV</a>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($packageSummary === null): ?>
                <p class="small-note">Po uruchomieniu funkcji zobaczysz tutaj podsumowanie kompletow, brakow i pozycji do recznej weryfikacji.</p>
            <?php else: ?>
                <div class="metrics">
                    <div class="metric">
                        <span>Miesiac</span>
                        <strong><?= htmlspecialchars($summaryMonth, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
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
                        <span>Faktury z KSeF</span>
                        <strong><?= htmlspecialchars((string) ($packageSummary['summary']['ksef_document_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Dokumenty z PDF</span>
                        <strong><?= htmlspecialchars((string) ($packageSummary['summary']['pdf_document_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Duplikaty PDF/KSeF</span>
                        <strong><?= htmlspecialchars((string) ($packageSummary['summary']['pdf_duplicate_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Reczna weryfikacja</span>
                        <strong><?= htmlspecialchars((string) ($packageSummary['summary']['manual_review_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="status-list">
                        <thead>
                            <tr>
                                <th>Lp</th>
                                <th>Wystawca</th>
                                <th>Spodziewane</th>
                                <th>Znalezione</th>
                                <th>Status</th>
                                <th>Dokumenty</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (($packageSummary['recurring_rows'] ?? []) as $row): ?>
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
                                            Brak dopasowanych dokumentow.
                                        <?php else: ?>
                                            <?php foreach (($row['matched_invoices'] ?? []) as $document): ?>
                                                <div>
                                                    <strong><?= htmlspecialchars((string) (($document['issuer_name'] ?? '') !== '' ? $document['issuer_name'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                                                    | <?= htmlspecialchars((string) (($document['invoice_number'] ?? '') !== '' ? $document['invoice_number'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                                    | <?= htmlspecialchars((string) (($document['formatted_amount_due'] ?? '') !== '' ? $document['formatted_amount_due'] : (($document['formatted_gross_amount'] ?? '') !== '' ? $document['formatted_gross_amount'] : '-')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                                    | <?= htmlspecialchars((string) ($document['source_label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                                    <?php if (!empty($document['source_reference'])): ?>
                                                        <br><span class="small-note"><?= htmlspecialchars((string) $document['source_reference'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (($packageSummary['other_invoices'] ?? []) !== []): ?>
                    <details class="accordion-panel">
                        <summary class="accordion-summary">
                            <div>
                                <strong class="accordion-title">Inne faktury</strong>
                                <p class="small-note">Dokumenty, ktorych nie przypisano do listy stalych wystawcow.</p>
                            </div>
                            <span class="accordion-hint">Rozwin</span>
                        </summary>
                        <div class="accordion-full">
                            <div class="table-wrap">
                                <table class="status-list">
                                    <thead>
                                        <tr>
                                            <th>Wystawca</th>
                                            <th>Numer</th>
                                            <th>Kwota</th>
                                            <th>Data</th>
                                            <th>Zrodlo</th>
                                            <th>Uwagi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (($packageSummary['other_invoices'] ?? []) as $invoice): ?>
                                            <tr>
                                                <td><?= htmlspecialchars((string) (($invoice['issuer_name'] ?? '') !== '' ? $invoice['issuer_name'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) (($invoice['invoice_number'] ?? '') !== '' ? $invoice['invoice_number'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) (($invoice['formatted_amount_due'] ?? '') !== '' ? $invoice['formatted_amount_due'] : (($invoice['formatted_gross_amount'] ?? '') !== '' ? $invoice['formatted_gross_amount'] : '-')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) (($invoice['due_date'] ?? '') !== '' ? $invoice['due_date'] : (($invoice['issue_date'] ?? '') !== '' ? $invoice['issue_date'] : '-')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) ($invoice['source_label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td class="cell-note"><?= htmlspecialchars((string) ($invoice['match_note'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </details>
                <?php endif; ?>

                <?php if (($packageSummary['manual_review_documents'] ?? []) !== []): ?>
                    <details class="accordion-panel">
                        <summary class="accordion-summary">
                            <div>
                                <strong class="accordion-title">Do recznej weryfikacji</strong>
                                <p class="small-note">Pozycje wymagajace sprawdzenia przed wyslaniem paczki do ksiegowosci.</p>
                            </div>
                            <span class="accordion-hint">Rozwin</span>
                        </summary>
                        <div class="accordion-full">
                            <div class="table-wrap">
                                <table class="status-list">
                                    <thead>
                                        <tr>
                                            <th>Plik / zrodlo</th>
                                            <th>Wystawca</th>
                                            <th>Numer</th>
                                            <th>Kwota</th>
                                            <th>Data</th>
                                            <th>Uwagi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (($packageSummary['manual_review_documents'] ?? []) as $document): ?>
                                            <tr>
                                                <td class="cell-note"><?= htmlspecialchars((string) ($document['source_reference'] ?? $document['source_label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) (($document['issuer_name'] ?? '') !== '' ? $document['issuer_name'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) (($document['invoice_number'] ?? '') !== '' ? $document['invoice_number'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) (($document['formatted_amount_due'] ?? '') !== '' ? $document['formatted_amount_due'] : (($document['formatted_gross_amount'] ?? '') !== '' ? $document['formatted_gross_amount'] : '-')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars((string) (($document['due_date'] ?? '') !== '' ? $document['due_date'] : (($document['issue_date'] ?? '') !== '' ? $document['issue_date'] : '-')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td class="cell-note"><?= htmlspecialchars((string) ($document['match_note'] ?? $document['note'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
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
    </section>
</div>
