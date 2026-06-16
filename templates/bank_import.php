<?php
/** @var \App\Core\Config $config */
/** @var array $alerts */
/** @var string $pageTitle */
/** @var string $pageDescription */
/** @var string $payerName */
/** @var string $payerAddress */
/** @var string $payerIban */
/** @var string $defaultCurrency */
/** @var bool $payerConfigured */
/** @var array|null $package */
/** @var array|null $latestJob */
/** @var string $csrfToken */
/** @var string $scrollTarget */

$baseUrl = rtrim((string) $config->get('app.base_url', ''), '/');
?>
<div class="page-grid bank-page-grid">
    <aside class="side-card">
        <h2>Zakres etapu 5</h2>
        <ul>
            <li>import CSV srednikowego,</li>
            <li>walidacja kwot, rachunkow i terminow,</li>
            <li>podglad paczki przelewow,</li>
            <li>eksport pain.001.001.09.</li>
        </ul>
    </aside>

    <section class="page-stack">
        <?php if ($scrollTarget !== ''): ?>
            <div hidden data-scroll-target="<?= htmlspecialchars($scrollTarget, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></div>
        <?php endif; ?>

        <article class="panel">
            <h2><?= htmlspecialchars((string) $pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars((string) $pageDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <div class="ksef-meta">
                <span class="badge <?= $payerConfigured ? 'ok' : 'warn' ?>">
                    Platnik: <?= $payerConfigured ? 'gotowy' : 'uzupelnij ustawienia' ?>
                </span>
                <span class="badge ok">Waluta domyslna: <?= htmlspecialchars($defaultCurrency, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            </div>
            <div class="bank-payer-summary">
                <p class="small-note">Nazwa platnika: <strong><?= htmlspecialchars($payerName !== '' ? $payerName : 'brak', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></p>
                <p class="small-note">Adres platnika: <strong><?= htmlspecialchars($payerAddress !== '' ? $payerAddress : 'brak', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></p>
                <p class="small-note">Rachunek platnika: <strong><?= htmlspecialchars($payerIban !== '' ? $payerIban : 'brak', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></p>
            </div>
            <?php if (!$payerConfigured): ?>
                <p class="small-note error-text">Przed eksportem XML uzupelnij w Ustawieniach co najmniej nazwe i rachunek platnika.</p>
            <?php endif; ?>
        </article>

        <article class="card" id="bank-import-form">
            <div class="section-head">
                <div>
                    <h3>Import pliku CSV</h3>
                    <p class="small-note">Akceptujemy CSV UTF-8 z BOM i separatorem srednik. Najlepiej uzyj pliku wyeksportowanego z modulu KSeF.</p>
                </div>
            </div>

            <?php foreach ($alerts as $index => $alert): ?>
                <div class="alert alert-<?= htmlspecialchars((string) $alert['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" id="bank-alert-<?= $index ?>">
                    <strong><?= htmlspecialchars((string) strtoupper($alert['type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    <p><?= htmlspecialchars((string) $alert['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                    <button type="button" data-dismiss="#bank-alert-<?= $index ?>">x</button>
                </div>
            <?php endforeach; ?>

            <form method="post" action="<?= htmlspecialchars($baseUrl . '/bank-import/import', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="settings-form" enctype="multipart/form-data">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

                <div class="form-grid">
                    <label class="form-field">
                        <span>Plik CSV</span>
                        <input type="file" name="csv_file" accept=".csv,text/csv" required>
                    </label>
                </div>

                <div class="form-actions">
                    <button type="submit" class="button">Wczytaj CSV</button>
                </div>
            </form>
        </article>

        <?php if ($latestJob !== null): ?>
            <article class="card">
                <div class="section-head">
                    <div>
                        <h3>Ostatni import</h3>
                        <p class="small-note">Job #<?= htmlspecialchars((string) $latestJob['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                    </div>
                    <span class="badge <?= str_contains((string) $latestJob['status'], 'flags') ? 'warn' : 'ok' ?>">
                        <?= htmlspecialchars((string) $latestJob['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </span>
                </div>

                <div class="metrics">
                    <div class="metric">
                        <span>Przelewy</span>
                        <strong><?= htmlspecialchars((string) $latestJob['transfer_count'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Suma</span>
                        <strong><?= htmlspecialchars((string) $latestJob['total_amount'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars((string) $latestJob['currency'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Ostrzezenia</span>
                        <strong><?= htmlspecialchars((string) $latestJob['warning_count'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Bledy</span>
                        <strong><?= htmlspecialchars((string) $latestJob['error_count'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                </div>
            </article>
        <?php endif; ?>

        <article class="card" id="bank-package">
            <div class="section-head">
                <div>
                    <h3>Podglad paczki przelewow</h3>
                    <p class="small-note">Do eksportu trafiaja tylko wiersze oznaczone jako gotowe.</p>
                </div>

                <?php if ($package !== null): ?>
                    <form method="post" action="<?= htmlspecialchars($baseUrl . '/bank-import/export', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <button type="submit" class="button" <?= !$payerConfigured ? 'disabled' : '' ?>>Generuj pain.001.001.09</button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ($package === null): ?>
                <p class="small-note">Po imporcie CSV zobaczysz tutaj gotowa paczke przelewow i status kazdego wiersza.</p>
            <?php else: ?>
                <div class="metrics">
                    <div class="metric">
                        <span>Plik zrodlowy</span>
                        <strong><?= htmlspecialchars((string) ($package['source_file_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Wiersze</span>
                        <strong><?= htmlspecialchars((string) ($package['summary']['row_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Gotowe do eksportu</span>
                        <strong><?= htmlspecialchars((string) ($package['summary']['included_count'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Suma paczki</span>
                        <strong><?= htmlspecialchars((string) ($package['summary']['total_amount'] ?? '0.00'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars((string) ($package['summary']['currency'] ?? 'PLN'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="status-list">
                        <thead>
                            <tr>
                                <th>Lp</th>
                                <th>Wystawca</th>
                                <th>Nr faktury</th>
                                <th>Rachunek odbiorcy</th>
                                <th>Tytul platnosci</th>
                                <th>Kwota</th>
                                <th>Termin</th>
                                <th>Do eksportu</th>
                                <th>Status</th>
                                <th>Uwagi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (($package['rows'] ?? []) as $row): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) ($row['position'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($row['issuer_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($row['invoice_number'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($row['formatted_recipient_account'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($row['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($row['formatted_amount'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($row['due_date'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td><?= !empty($row['included_in_export']) ? 'tak' : 'nie' ?></td>
                                    <td>
                                        <span class="badge <?= htmlspecialchars((string) ($row['import_status_badge_class'] ?? 'warn'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                            <?= htmlspecialchars((string) ($row['import_status_label'] ?? 'Do sprawdzenia'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td class="cell-note"><?= htmlspecialchars((string) ($row['import_notes'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </article>
    </section>
</div>
