<?php
/** @var \App\Core\Config $config */
/** @var array $alerts */
/** @var string $pageTitle */
/** @var string $pageDescription */
/** @var array $formData */
/** @var array|null $fetchJob */
/** @var array $invoices */
/** @var string $activeEnvironment */
/** @var string $environmentBaseUrl */
/** @var string $contextNip */
/** @var string $tokenPresenceLabel */
/** @var string $csrfToken */
/** @var string $scrollTarget */
/** @var string $currentView */

$baseUrl = rtrim((string) $config->get('app.base_url', ''), '/');
$viewQueryBase = [
    'date_from' => (string) ($formData['date_from'] ?? ''),
    'date_to' => (string) ($formData['date_to'] ?? ''),
];
?>
<div class="page-grid ksef-page-grid">
    <aside class="side-card">
        <h2>Zakres etapu 4</h2>
        <ul>
            <li>pobranie faktur po zakresie dat,</li>
            <li>mapowanie i zapis do bazy,</li>
            <li>walidacja danych do platnosci,</li>
            <li>eksport CSV gotowy do dalszej obrobki.</li>
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
                <span class="badge ok">Srodowisko: <?= htmlspecialchars($activeEnvironment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <span class="badge <?= $tokenPresenceLabel === 'ustawione' ? 'ok' : 'warn' ?>">Token: <?= htmlspecialchars($tokenPresenceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            </div>
            <p class="small-note">
                Base URL dla aktywnego srodowiska:
                <strong><?= htmlspecialchars($environmentBaseUrl !== '' ? $environmentBaseUrl : 'brak', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
            </p>
            <p class="small-note">
                NIP kontekstu KSeF:
                <strong><?= htmlspecialchars($contextNip !== '' ? $contextNip : 'brak', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
            </p>
            <p class="small-note">Pobranie korzysta z oficjalnego logowania tokenem KSeF, listy metadanych oraz dociagania XML-i pojedynczych faktur.</p>
        </article>

        <article class="card" id="ksef-fetch-form">
            <div class="section-head">
                <div>
                    <h3>Zakres pobierania</h3>
                    <p class="small-note">Pobieramy tylko dane z wybranego przedzialu dat. Dla bezpieczenstwa jeden strzal obejmuje maksymalnie 93 dni.</p>
                </div>
            </div>

            <?php foreach ($alerts as $index => $alert): ?>
                <div class="alert alert-<?= htmlspecialchars((string) $alert['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" id="ksef-alert-<?= $index ?>">
                    <strong><?= htmlspecialchars((string) strtoupper($alert['type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    <p><?= htmlspecialchars((string) $alert['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                    <button type="button" data-dismiss="#ksef-alert-<?= $index ?>">x</button>
                </div>
            <?php endforeach; ?>

            <form method="post" action="<?= htmlspecialchars($baseUrl . '/ksef/fetch', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="settings-form">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <input type="hidden" name="view_mode" value="<?= htmlspecialchars($currentView, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

                <div class="form-grid">
                    <label class="form-field">
                        <span>Data od</span>
                        <input type="date" name="date_from" value="<?= htmlspecialchars((string) ($formData['date_from'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
                    </label>

                    <label class="form-field">
                        <span>Data do</span>
                        <input type="date" name="date_to" value="<?= htmlspecialchars((string) ($formData['date_to'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
                    </label>
                </div>

                <div class="form-actions">
                    <button type="submit" class="button">Pobierz z KSeF</button>
                </div>
            </form>
        </article>

        <?php if ($fetchJob !== null): ?>
            <article class="card">
                <div class="section-head">
                    <div>
                        <h3>Ostatnie pobranie</h3>
                        <p class="small-note">Zakres: <?= htmlspecialchars((string) $fetchJob['date_from'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> - <?= htmlspecialchars((string) $fetchJob['date_to'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                    </div>
                    <span class="badge <?= str_starts_with((string) $fetchJob['status'], 'completed') ? 'ok' : (((string) $fetchJob['status']) === 'failed' ? 'error' : 'warn') ?>">
                        <?= htmlspecialchars((string) $fetchJob['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </span>
                </div>

                <div class="metrics">
                    <div class="metric">
                        <span>Faktury</span>
                        <strong><?= htmlspecialchars((string) $fetchJob['invoice_count'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Ostrzezenia</span>
                        <strong><?= htmlspecialchars((string) $fetchJob['warning_count'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Start</span>
                        <strong><?= htmlspecialchars((string) ($fetchJob['started_at'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Koniec</span>
                        <strong><?= htmlspecialchars((string) ($fetchJob['finished_at'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    </div>
                </div>

                <?php if (!empty($fetchJob['error_message'])): ?>
                    <p class="small-note error-text"><?= htmlspecialchars((string) $fetchJob['error_message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <?php endif; ?>
            </article>
        <?php endif; ?>

        <article class="card" id="ksef-results">
            <div class="section-head">
                <div>
                    <h3>Podglad faktur</h3>
                    <p class="small-note">Widok pokazuje aktualnie zapisane faktury KSeF dla wybranego zakresu dat.</p>
                </div>
                <div class="section-actions">
                    <div class="view-switch" aria-label="Tryb podgladu">
                        <a
                            class="view-switch__item <?= $currentView === 'table' ? 'is-active' : '' ?>"
                            href="<?= htmlspecialchars($baseUrl . '/ksef?' . http_build_query($viewQueryBase + ['view' => 'table']) . '#ksef-results', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        >
                            Tabela
                        </a>
                        <a
                            class="view-switch__item <?= $currentView === 'cards' ? 'is-active' : '' ?>"
                            href="<?= htmlspecialchars($baseUrl . '/ksef?' . http_build_query($viewQueryBase + ['view' => 'cards']) . '#ksef-results', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        >
                            Karty
                        </a>
                    </div>

                    <?php if ($invoices !== []): ?>
                        <a
                            class="button button-secondary"
                            href="<?= htmlspecialchars($baseUrl . '/ksef/export?' . http_build_query(['date_from' => $formData['date_from'], 'date_to' => $formData['date_to']]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        >
                            Eksport CSV
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($invoices === []): ?>
                <p class="small-note">Brak zapisanych faktur dla aktualnego zakresu.</p>
            <?php elseif ($currentView === 'cards'): ?>
                <div class="invoice-dashboard">
                    <?php foreach ($invoices as $index => $invoice): ?>
                        <article class="invoice-tile">
                            <div class="invoice-tile__head">
                                <div>
                                    <span class="invoice-tile__eyebrow">Faktura <?= htmlspecialchars((string) ($index + 1), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                    <h4><?= htmlspecialchars((string) ($invoice['issuer_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h4>
                                    <p><?= htmlspecialchars((string) ($invoice['invoice_number'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                                </div>
                                <span class="badge <?= htmlspecialchars((string) ($invoice['status_badge_class'] ?? 'warn'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                    <?= htmlspecialchars((string) ($invoice['status_label'] ?? 'Ostrzezenie'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                </span>
                            </div>

                            <div class="invoice-tile__metrics">
                                <div class="invoice-tile__metric">
                                    <span>Netto</span>
                                    <strong><?= htmlspecialchars((string) ($invoice['formatted_net_amount'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                                </div>
                                <div class="invoice-tile__metric">
                                    <span>Brutto</span>
                                    <strong><?= htmlspecialchars((string) ($invoice['formatted_gross_amount'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                                </div>
                                <div class="invoice-tile__metric">
                                    <span>Do zaplaty</span>
                                    <strong><?= htmlspecialchars((string) (($invoice['formatted_amount_due'] ?? '') !== '' ? $invoice['formatted_amount_due'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                                </div>
                                <div class="invoice-tile__metric">
                                    <span>VAT</span>
                                    <strong><?= htmlspecialchars((string) ($invoice['formatted_vat_amount'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                                </div>
                            </div>

                            <dl class="invoice-tile__details">
                                <div>
                                    <dt>Rachunek</dt>
                                    <dd><?= htmlspecialchars((string) (($invoice['formatted_bank_account'] ?? '') !== '' ? $invoice['formatted_bank_account'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                                </div>
                                <div>
                                    <dt>Tytul platnosci</dt>
                                    <dd><?= htmlspecialchars((string) (($invoice['payment_description'] ?? '') !== '' ? $invoice['payment_description'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                                </div>
                                <div>
                                    <dt>Termin platnosci</dt>
                                    <dd><?= htmlspecialchars((string) (($invoice['due_date'] ?? '') !== '' ? $invoice['due_date'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                                </div>
                                <div>
                                    <dt>Status platnosci</dt>
                                    <dd><?= htmlspecialchars((string) ($invoice['payment_status_label'] ?? 'Brak danych'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                                </div>
                                <div class="invoice-tile__details--wide">
                                    <dt>Uwagi</dt>
                                    <dd><?= htmlspecialchars((string) (($invoice['validation_notes'] ?? '') !== '' ? $invoice['validation_notes'] : '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                                </div>
                            </dl>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="status-list">
                        <thead>
                            <tr>
                                <th>Lp</th>
                                <th>Wystawca faktury</th>
                                <th>Nr faktury</th>
                                <th>Rachunek bankowy do wplaty</th>
                                <th>Za co platnosc</th>
                                <th>Kwota netto</th>
                                <th>Kwota brutto</th>
                                <th>Kwota do zaplaty</th>
                                <th>VAT</th>
                                <th>Termin platnosci</th>
                                <th>Status platnosci</th>
                                <th>Status walidacji</th>
                                <th>Uwagi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoices as $index => $invoice): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) ($index + 1), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($invoice['issuer_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($invoice['invoice_number'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($invoice['formatted_bank_account'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($invoice['payment_description'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($invoice['formatted_net_amount'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($invoice['formatted_gross_amount'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($invoice['formatted_amount_due'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($invoice['formatted_vat_amount'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($invoice['due_date'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) ($invoice['payment_status_label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                    <td><span class="badge <?= htmlspecialchars((string) ($invoice['status_badge_class'] ?? 'warn'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars((string) ($invoice['status_label'] ?? 'Ostrzezenie'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></td>
                                    <td class="cell-note"><?= htmlspecialchars((string) ($invoice['validation_notes'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </article>
    </section>
</div>
