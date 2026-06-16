<?php
/** @var \App\Core\Config $config */
/** @var array $alerts */
/** @var string $pageTitle */
/** @var string $pageDescription */
/** @var array|null $package */
/** @var string $csrfToken */
/** @var string $scrollTarget */
/** @var string $selectedMonth */
/** @var string $amountTolerance */

$baseUrl = rtrim((string) $config->get('app.base_url', ''), '/');
?>
<div class="page-grid compare-page-grid">
    <aside class="side-card">
        <h2>Zakres etapu 6</h2>
        <ul>
            <li>upload pliku JPK XML,</li>
            <li>pobranie live sprzedazy i kosztow z KSeF,</li>
            <li>porownanie po typie, numerze i kwocie brutto,</li>
            <li>eksport CSV wyniku porownania.</li>
        </ul>
    </aside>

    <section class="page-stack">
        <?php if ($scrollTarget !== ''): ?>
            <div hidden data-scroll-target="<?= htmlspecialchars($scrollTarget, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></div>
        <?php endif; ?>

        <article class="panel">
            <h2><?= htmlspecialchars((string) $pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars((string) $pageDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <p class="small-note">MVP obsluguje przede wszystkim JPK_PKPIR z wierszami PKPIRWiersz, a awaryjnie takze warianty z wierszami sprzedazy i zakupu.</p>
            <p class="small-note">Porownanie nie korzysta z lokalnego archiwum KSeF. Dla wybranego miesiaca pobieramy dane na biezaco z obu rejestrow: kosztow i sprzedazy.</p>
            <p class="small-note">Aby nie wpasc w limit KSeF, porownanie korzysta z metadanych i moze przez okolo 15 minut uzyc ich z cache biezacej sesji, bez masowego pobierania XML.</p>
            <p class="small-note">Kwoty KSeF porownujemy w PLN. Faktury walutowe przeliczamy kursem srednim NBP z ostatniego dostepnego notowania nie pozniejszego niz data faktury, a zgodnosc kwot uznajemy dla roznicy mniejszej niz <?= htmlspecialchars($amountTolerance, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>.</p>
        </article>

        <article class="card" id="compare-import-form">
            <div class="section-head">
                <div>
                    <h3>Import pliku JPK</h3>
                    <p class="small-note">Wgraj plik JPK XML i wybierz miesiac. Aplikacja pobierze na biezaco faktury KSeF z tego miesiaca, a potem wykona porownanie.</p>
                </div>
            </div>

            <?php foreach ($alerts as $index => $alert): ?>
                <div class="alert alert-<?= htmlspecialchars((string) $alert['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" id="compare-alert-<?= $index ?>">
                    <strong><?= htmlspecialchars((string) strtoupper($alert['type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    <p><?= htmlspecialchars((string) $alert['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                    <button type="button" data-dismiss="#compare-alert-<?= $index ?>">x</button>
                </div>
            <?php endforeach; ?>

            <form method="post" action="<?= htmlspecialchars($baseUrl . '/accounting-compare/import', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="settings-form" enctype="multipart/form-data">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

                <div class="form-grid">
                    <label class="form-field">
                        <span>Miesiac porownania</span>
                        <input type="month" name="compare_month" value="<?= htmlspecialchars($selectedMonth, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
                    </label>

                    <label class="form-field">
                        <span>Plik JPK</span>
                        <input type="file" name="jpk_file" accept="application/xml,text/xml,.xml" required>
                    </label>
                </div>

                <div class="form-actions">
                    <button type="submit" class="button">Wczytaj JPK i porownaj</button>
                </div>
            </form>
        </article>

        <article class="card" id="compare-results">
                <div class="section-head">
                    <div>
                        <h3>Wynik porownania</h3>
                        <p class="small-note">Statusy: BOTH, NUMBER_MATCH_AMOUNT_DIFF, AMOUNT_MATCH_NUMBER_DIFF, ONLY_KSEF, ONLY_JPK.</p>
                        <p class="small-note">Kolumna "Brutto KSeF" pokazuje kwote uzyta do porownania w PLN.</p>
                    </div>

                <?php if ($package !== null): ?>
                    <a class="button button-secondary" href="<?= htmlspecialchars($baseUrl . '/accounting-compare/export', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Eksport CSV</a>
                <?php endif; ?>
            </div>

            <?php if ($package === null): ?>
                <p class="small-note">Po imporcie JPK zobaczysz tu wynik porownania z aktualnie pobranym miesiecznym zbiorem KSeF.</p>
            <?php else: ?>
                <div class="metrics">
                    <div class="metric">
                        <span>Miesiac KSeF</span>
                        <strong><?= htmlspecialchars((string) ($package['compare_month'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Plik zrodlowy</span>
                        <strong><?= htmlspecialchars((string) ($package['source_file_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>KSeF razem</span>
                        <strong><?= htmlspecialchars((string) ($package['live_ksef_invoice_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>KSeF koszty</span>
                        <strong><?= htmlspecialchars((string) ($package['live_ksef_cost_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>KSeF sprzedaz</span>
                        <strong><?= htmlspecialchars((string) ($package['live_ksef_sale_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>JPK razem</span>
                        <strong><?= htmlspecialchars((string) ($package['summary']['entries_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>JPK koszty</span>
                        <strong><?= htmlspecialchars((string) ($package['summary']['pdf_cost_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>JPK sprzedaz</span>
                        <strong><?= htmlspecialchars((string) ($package['summary']['pdf_sale_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Zgodne</span>
                        <strong><?= htmlspecialchars((string) ($package['summary']['both_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Numer ok, kwota inna</span>
                        <strong><?= htmlspecialchars((string) ($package['summary']['number_match_amount_diff_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Kwota ok, numer inny</span>
                        <strong><?= htmlspecialchars((string) ($package['summary']['amount_match_number_diff_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Tylko KSeF</span>
                        <strong><?= htmlspecialchars((string) ($package['summary']['only_ksef_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Tylko JPK</span>
                        <strong><?= htmlspecialchars((string) ($package['summary']['only_jpk_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="status-list">
                        <thead>
                            <tr>
                                <th>Typ</th>
                                <th>Status</th>
                                <th>JPK</th>
                                <th>Brutto JPK</th>
                                <th>KSeF</th>
                                <th>Brutto KSeF</th>
                                <th>Strony</th>
                                <th>Uzasadnienie</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (($package['comparison_rows'] ?? []) as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) ($row['document_type_label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td>
                                        <span class="badge <?= htmlspecialchars((string) ($row['status_badge_class'] ?? 'warn'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                            <?= htmlspecialchars((string) ($row['status_label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars((string) ($row['document_number'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><br>
                                        <span class="small-note">
                                            <?= htmlspecialchars((string) ($row['row_lp'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                            <?php if (!empty($row['event_date'])): ?>
                                                / <?= htmlspecialchars((string) $row['event_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars((string) ($row['jpk_gross_amount'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td>
                                        <?= htmlspecialchars((string) ($row['invoice_number'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><br>
                                        <span class="small-note"><?= htmlspecialchars((string) ($row['issue_date'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars((string) ($row['ksef_amount_label'] ?? $row['ksef_gross_amount'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><br>
                                        <?php if (!empty($row['ksef_amount_note'])): ?>
                                            <span class="small-note"><?= htmlspecialchars((string) $row['ksef_amount_note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars((string) ($row['contractor_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><br>
                                        <span class="small-note"><?= htmlspecialchars((string) ($row['issuer_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                    </td>
                                    <td class="cell-note"><?= htmlspecialchars((string) ($row['reasoning'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <article class="card compare-preview-card">
                    <h3>Podglad wczytanego XML</h3>
                    <pre class="compare-raw-preview"><?= htmlspecialchars((string) ($package['raw_text_preview'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
                </article>
            <?php endif; ?>
        </article>
    </section>
</div>
