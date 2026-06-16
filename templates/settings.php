<div class="page-grid">
    <aside class="side-card">
        <h2>Zakres etapu 3</h2>
        <ul>
            <li>tryb KSeF production/test,</li>
            <li>dane KSeF i OpenAI,</li>
            <li>dane płatnika do przelewów.</li>
        </ul>
    </aside>

    <section class="page-stack">
        <?php if (!empty($scrollTarget ?? '')): ?>
            <div data-scroll-target="<?= htmlspecialchars((string) $scrollTarget, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></div>
        <?php endif; ?>

        <?php if (($activeForm ?? 'general') === 'general'): ?>
            <?php foreach (($alerts ?? []) as $index => $alert): ?>
                <div class="alert alert-<?= htmlspecialchars((string) $alert['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" id="settings-alert-<?= $index ?>">
                    <strong><?= htmlspecialchars((string) strtoupper($alert['type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    <p><?= htmlspecialchars((string) $alert['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                    <button type="button" data-dismiss="#settings-alert-<?= $index ?>">x</button>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <article class="panel">
            <h2><?= htmlspecialchars((string) $pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars((string) $pageDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <p class="small-note">Dane połączenia z bazą są tylko informacyjne i nadal pochodzą z pliku konfiguracyjnego poza katalogiem publicznym.</p>
        </article>

        <section class="config-pillars">
            <article class="card">
                <h3>Baza danych</h3>
                <p><strong>Host:</strong> <?= htmlspecialchars((string) $settings['database']['host'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <p><strong>Port:</strong> <?= htmlspecialchars((string) $settings['database']['port'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <p><strong>Baza:</strong> <?= htmlspecialchars((string) $settings['database']['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <p><strong>Użytkownik:</strong> <?= htmlspecialchars((string) $settings['database']['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            </article>

            <article class="card">
                <h3>Status sekretów</h3>
                <p><strong>OpenAI API key:</strong> <?= htmlspecialchars((string) $openAiPresenceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= $openAiPresenceLabel === 'ustawione' ? ' (********)' : '' ?></p>
                <p><strong>KSeF production token:</strong> <?= htmlspecialchars((string) $prodTokenPresenceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= $prodTokenPresenceLabel === 'ustawione' ? ' (********)' : '' ?></p>
                <p><strong>KSeF test token:</strong> <?= htmlspecialchars((string) $testTokenPresenceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= $testTokenPresenceLabel === 'ustawione' ? ' (********)' : '' ?></p>
            </article>
        </section>

        <article class="panel" id="settings-ksef">
            <h2>Ustawienia KSeF</h2>
            <?php if (($activeForm ?? '') === 'ksef'): ?>
                <?php foreach (($alerts ?? []) as $index => $alert): ?>
                    <div class="alert alert-<?= htmlspecialchars((string) $alert['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" id="settings-ksef-alert-<?= $index ?>">
                        <strong><?= htmlspecialchars((string) strtoupper($alert['type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                        <p><?= htmlspecialchars((string) $alert['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                        <button type="button" data-dismiss="#settings-ksef-alert-<?= $index ?>">x</button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <form method="post" action="<?= htmlspecialchars($config->url('/settings'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="settings-form" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <input type="hidden" name="form_name" value="ksef">

                <div class="form-grid">
                    <div class="form-field">
                        <label for="environment">Aktywne środowisko</label>
                        <select id="environment" name="environment">
                            <?php foreach ($settings['ksef']['available_environments'] as $environment): ?>
                                <option value="<?= htmlspecialchars((string) $environment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $settings['ksef']['environment'] === $environment ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $environment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-field">
                        <label for="context_nip">NIP kontekstu KSeF</label>
                        <input id="context_nip" name="context_nip" type="text" inputmode="numeric" value="<?= htmlspecialchars((string) ($settings['ksef']['context_nip'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <p class="small-note">To NIP firmy, w której kontekście aplikacja loguje się do KSeF tokenem.</p>
                    </div>
                </div>

                <h3>KSeF production</h3>
                <div class="form-grid">
                    <div class="form-field">
                        <label for="production_base_url">Base URL</label>
                        <input id="production_base_url" name="production_base_url" type="url" value="<?= htmlspecialchars((string) $settings['ksef']['production']['base_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>
                    <div class="form-field">
                        <label for="production_certificate_path">Ścieżka certyfikatu</label>
                        <input id="production_certificate_path" name="production_certificate_path" type="text" value="<?= htmlspecialchars((string) $settings['ksef']['production']['certificate_path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>
                    <div class="form-field">
                        <label for="production_private_key_path">Ścieżka klucza prywatnego</label>
                        <input id="production_private_key_path" name="production_private_key_path" type="text" value="<?= htmlspecialchars((string) $settings['ksef']['production']['private_key_path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>
                    <div class="form-field">
                        <label for="production_token">Nowy token production</label>
                        <input id="production_token" name="production_token" type="password" value="" autocomplete="new-password" spellcheck="false">
                        <p class="small-note">Pole jest zawsze puste po odświeżeniu. Stan zapisanej wartości sprawdzaj w sekcji „Status sekretów”.</p>
                    </div>
                </div>
                <label class="checkbox-line">
                    <input type="checkbox" name="clear_production_token" value="1">
                    Wyczyść zapisany token production
                </label>

                <h3>KSeF test</h3>
                <div class="form-grid">
                    <div class="form-field">
                        <label for="test_base_url">Base URL</label>
                        <input id="test_base_url" name="test_base_url" type="url" value="<?= htmlspecialchars((string) $settings['ksef']['test']['base_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>
                    <div class="form-field">
                        <label for="test_certificate_path">Ścieżka certyfikatu</label>
                        <input id="test_certificate_path" name="test_certificate_path" type="text" value="<?= htmlspecialchars((string) $settings['ksef']['test']['certificate_path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>
                    <div class="form-field">
                        <label for="test_private_key_path">Ścieżka klucza prywatnego</label>
                        <input id="test_private_key_path" name="test_private_key_path" type="text" value="<?= htmlspecialchars((string) $settings['ksef']['test']['private_key_path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>
                    <div class="form-field">
                        <label for="test_token">Nowy token test</label>
                        <input id="test_token" name="test_token" type="password" value="" autocomplete="new-password" spellcheck="false">
                        <p class="small-note">Pole jest zawsze puste po odświeżeniu. Stan zapisanej wartości sprawdzaj w sekcji „Status sekretów”.</p>
                    </div>
                </div>
                <label class="checkbox-line">
                    <input type="checkbox" name="clear_test_token" value="1">
                    Wyczyść zapisany token test
                </label>

                <button class="button" type="submit">Zapisz ustawienia KSeF</button>
            </form>
        </article>

        <article class="panel" id="settings-openai">
            <h2>Ustawienia OpenAI</h2>
            <?php if (($activeForm ?? '') === 'openai'): ?>
                <?php foreach (($alerts ?? []) as $index => $alert): ?>
                    <div class="alert alert-<?= htmlspecialchars((string) $alert['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" id="settings-openai-alert-<?= $index ?>">
                        <strong><?= htmlspecialchars((string) strtoupper($alert['type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                        <p><?= htmlspecialchars((string) $alert['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                        <button type="button" data-dismiss="#settings-openai-alert-<?= $index ?>">x</button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <form method="post" action="<?= htmlspecialchars($config->url('/settings'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="settings-form" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <input type="hidden" name="form_name" value="openai">

                <label class="checkbox-line">
                    <input type="checkbox" name="enabled" value="1" <?= !empty($settings['openai']['enabled']) ? 'checked' : '' ?>>
                    Włącz pomocnicze użycie OpenAI
                </label>

                <div class="form-grid">
                    <div class="form-field">
                        <label for="model">Model</label>
                        <input id="model" name="model" type="text" value="<?= htmlspecialchars((string) $settings['openai']['model'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>
                    <div class="form-field">
                        <label for="api_key">Nowy API key</label>
                        <input id="api_key" name="api_key" type="password" value="" autocomplete="new-password" spellcheck="false">
                        <p class="small-note">Jeśli klucz jest zapisany, zobaczysz to wyżej jako „ustawione (********)”.</p>
                    </div>
                </div>

                <label class="checkbox-line">
                    <input type="checkbox" name="clear_api_key" value="1">
                    Wyczyść zapisany OpenAI API key
                </label>

                <button class="button" type="submit">Zapisz ustawienia OpenAI</button>
            </form>
        </article>

        <article class="panel" id="settings-bank">
            <h2>Dane płatnika do przelewów</h2>
            <?php if (($activeForm ?? '') === 'bank'): ?>
                <?php foreach (($alerts ?? []) as $index => $alert): ?>
                    <div class="alert alert-<?= htmlspecialchars((string) $alert['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" id="settings-bank-alert-<?= $index ?>">
                        <strong><?= htmlspecialchars((string) strtoupper($alert['type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                        <p><?= htmlspecialchars((string) $alert['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                        <button type="button" data-dismiss="#settings-bank-alert-<?= $index ?>">x</button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <form method="post" action="<?= htmlspecialchars($config->url('/settings'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="settings-form" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <input type="hidden" name="form_name" value="bank">

                <div class="form-grid">
                    <div class="form-field">
                        <label for="payer_name">Nazwa płatnika</label>
                        <input id="payer_name" name="payer_name" type="text" value="<?= htmlspecialchars((string) $settings['bank']['payer_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>
                    <div class="form-field">
                        <label for="payer_address">Adres płatnika</label>
                        <input id="payer_address" name="payer_address" type="text" value="<?= htmlspecialchars((string) $settings['bank']['payer_address'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>
                    <div class="form-field">
                        <label for="payer_iban">IBAN / NRB płatnika</label>
                        <input id="payer_iban" name="payer_iban" type="text" value="<?= htmlspecialchars((string) $settings['bank']['payer_iban'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>
                    <div class="form-field">
                        <label for="default_currency">Waluta domyślna</label>
                        <select id="default_currency" name="default_currency">
                            <?php foreach (['PLN', 'EUR', 'USD'] as $currency): ?>
                                <option value="<?= htmlspecialchars($currency, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $settings['bank']['default_currency'] === $currency ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($currency, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="small-note">Przy błędzie walidacji komunikat pojawi się tutaj, bez przeskoku na górę strony.</p>
                    </div>
                </div>

                <button class="button" type="submit">Zapisz dane płatnika</button>
            </form>
        </article>
    </section>
</div>
