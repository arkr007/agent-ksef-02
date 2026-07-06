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
        <h2>Jak to dziala</h2>
        <ul>
            <li>wgraj plik JPK XML,</li>
            <li>wybierz miesiac porownania,</li>
            <li>aplikacja pobierze dane KSeF na biezaco,</li>
            <li>na koncu pobierzesz wynik CSV.</li>
        </ul>
    </aside>

    <section class="page-stack">
        <?php if ($scrollTarget !== ''): ?>
            <div hidden data-scroll-target="<?= htmlspecialchars($scrollTarget, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></div>
        <?php endif; ?>

        <article class="panel">
            <h2><?= htmlspecialchars((string) $pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars((string) $pageDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <p class="small-note">Porownanie korzysta z biezacych danych KSeF dla wskazanego miesiaca i zapisuje wynik do pliku CSV.</p>
            <p class="small-note">Kwoty KSeF porownujemy w PLN. Dla faktur walutowych stosowany jest kurs NBP, a zgodnosc kwot uznajemy dla roznicy mniejszej niz <?= htmlspecialchars($amountTolerance, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>.</p>
        </article>

        <article class="card" id="compare-import-form">
            <div class="section-head">
                <div>
                    <h3>Uruchom porownanie</h3>
                    <p class="small-note">Wgraj plik JPK XML i wybierz miesiac. Po wykonaniu mozesz od razu pobrac wynik CSV.</p>
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
                </div>
                <?php if ($package !== null): ?>
                    <a class="button button-secondary" href="<?= htmlspecialchars($baseUrl . '/accounting-compare/export', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Pobierz CSV</a>
                <?php endif; ?>
            </div>

            <?php if ($package === null): ?>
                <p class="small-note">Po imporcie JPK zobaczysz tu podsumowanie i liste roznic gotowa do eksportu CSV.</p>
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
                        <span>Zgodne</span>
                        <strong><?= htmlspecialchars((string) ($package['summary']['both_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Tylko KSeF</span>
                        <strong><?= htmlspecialchars((string) ($package['summary']['only_ksef_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Tylko JPK</span>
                        <strong><?= htmlspecialchars((string) ($package['summary']['only_jpk_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Kwota rozna</span>
                        <strong><?= htmlspecialchars((string) ($package['summary']['number_match_amount_diff_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
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
                                <th>Kontrahent</th>
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
            <?php endif; ?>
        </article>
    </section>
</div>
