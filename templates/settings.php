<div class="page-grid">
    <aside class="side-card">
        <h2>Zakres ustawien</h2>
        <ul>
            <li>tryb KSeF production/test,</li>
            <li>provider AI: ollama / hybrid / openai,</li>
            <li>lokalizacje katalogu PDF i pliku CSV,</li>
            <li>dane platnika do przelewow.</li>
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
            <p class="small-note">Dane polaczenia z baza sa tylko informacyjne i nadal pochodza z pliku konfiguracyjnego poza katalogiem publicznym.</p>
        </article>

        <section class="config-pillars">
            <article class="card">
                <h3>Baza danych</h3>
                <p><strong>Host:</strong> <?= htmlspecialchars((string) $settings['database']['host'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <p><strong>Port:</strong> <?= htmlspecialchars((string) $settings['database']['port'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <p><strong>Baza:</strong> <?= htmlspecialchars((string) $settings['database']['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <p><strong>Uzytkownik:</strong> <?= htmlspecialchars((string) $settings['database']['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            </article>

            <article class="card">
                <h3>Status AI i sekretow</h3>
                <p><strong>Aktywny tryb AI:</strong> <?= htmlspecialchars((string) $aiProviderLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <p><strong>OpenAI API key:</strong> <?= htmlspecialchars((string) $openAiPresenceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= $openAiPresenceLabel === 'ustawione' ? ' (********)' : '' ?></p>
                <p><strong>Ollama endpoint:</strong> <?= htmlspecialchars((string) ($settings['ollama']['base_url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <p><strong>Ollama model:</strong> <?= htmlspecialchars((string) ($settings['ollama']['model'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <p><strong>KSeF production token:</strong> <?= htmlspecialchars((string) $prodTokenPresenceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= $prodTokenPresenceLabel === 'ustawione' ? ' (********)' : '' ?></p>
                <p><strong>KSeF test token:</strong> <?= htmlspecialchars((string) $testTokenPresenceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= $testTokenPresenceLabel === 'ustawione' ? ' (********)' : '' ?></p>
            </article>

            <article class="card">
                <h3>Lokalne foldery</h3>
                <p><strong>Katalog PDF:</strong> <span class="metric-path"><?= htmlspecialchars((string) ($settings['local_paths']['document_inbox_dir'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></p>
                <p><strong>Plik CSV:</strong> <span class="metric-path"><?= htmlspecialchars((string) ($settings['local_paths']['recurring_issuers_csv'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></p>
            </article>
        </section>

        <article class="panel" id="settings-local-paths">
            <h2>Lokalizacje folderow lokalnych</h2>
            <?php if (($activeForm ?? '') === 'local_paths'): ?>
                <?php foreach (($alerts ?? []) as $index => $alert): ?>
                    <div class="alert alert-<?= htmlspecialchars((string) $alert['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" id="settings-local-paths-alert-<?= $index ?>">
                        <strong><?= htmlspecialchars((string) strtoupper($alert['type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                        <p><?= htmlspecialchars((string) $alert['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                        <button type="button" data-dismiss="#settings-local-paths-alert-<?= $index ?>">x</button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <form method="post" action="<?= htmlspecialchars($config->url('/settings'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="settings-form" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <input type="hidden" name="form_name" value="local_paths">

                <div class="form-grid">
                    <div class="form-field">
                        <label for="document_inbox_dir">Katalog lokalnych PDF</label>
                        <input id="document_inbox_dir" name="document_inbox_dir" type="text" value="<?= htmlspecialchars((string) ($settings['local_paths']['document_inbox_dir'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <p class="small-note">To katalog, z ktorego modul "Pakiet dla ksiegowej" czyta faktury PDF z biezacej stacji roboczej.</p>
                    </div>
                    <div class="form-field">
                        <label for="recurring_issuers_csv">Plik listy stalych wystawcow</label>
                        <input id="recurring_issuers_csv" name="recurring_issuers_csv" type="text" value="<?= htmlspecialchars((string) ($settings['local_paths']['recurring_issuers_csv'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <p class="small-note">Wskaz tutaj plik `stali_wystawcy.csv`, domyslnie w podkatalogu `rob`.</p>
                    </div>
                </div>

                <button class="button" type="submit">Zapisz lokalizacje folderow</button>
            </form>
        </article>

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
                        <label for="environment">Aktywne srodowisko</label>
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
                        <p class="small-note">To NIP firmy, w ktorej kontekscie aplikacja loguje sie do KSeF tokenem.</p>
                    </div>
                </div>

                <h3>KSeF production</h3>
                <div class="form-grid">
                    <div class="form-field">
                        <label for="production_base_url">Base URL</label>
                        <input id="production_base_url" name="production_base_url" type="url" value="<?= htmlspecialchars((string) $settings['ksef']['production']['base_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>
                    <div class="form-field">
                        <label for="production_certificate_path">Sciezka certyfikatu</label>
                        <input id="production_certificate_path" name="production_certificate_path" type="text" value="<?= htmlspecialchars((string) $settings['ksef']['production']['certificate_path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>
                    <div class="form-field">
                        <label for="production_private_key_path">Sciezka klucza prywatnego</label>
                        <input id="production_private_key_path" name="production_private_key_path" type="text" value="<?= htmlspecialchars((string) $settings['ksef']['production']['private_key_path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>
                    <div class="form-field">
                        <label for="production_token">Nowy token production</label>
                        <input id="production_token" name="production_token" type="password" value="" autocomplete="new-password" spellcheck="false">
                        <p class="small-note">Pole jest zawsze puste po odswiezeniu. Stan zapisanej wartosci sprawdzaj wyzej w statusie sekretow.</p>
                    </div>
                </div>
                <label class="checkbox-line">
                    <input type="checkbox" name="clear_production_token" value="1">
                    Wyczysc zapisany token production
                </label>

                <h3>KSeF test</h3>
                <div class="form-grid">
                    <div class="form-field">
                        <label for="test_base_url">Base URL</label>
                        <input id="test_base_url" name="test_base_url" type="url" value="<?= htmlspecialchars((string) $settings['ksef']['test']['base_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>
                    <div class="form-field">
                        <label for="test_certificate_path">Sciezka certyfikatu</label>
                        <input id="test_certificate_path" name="test_certificate_path" type="text" value="<?= htmlspecialchars((string) $settings['ksef']['test']['certificate_path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>
                    <div class="form-field">
                        <label for="test_private_key_path">Sciezka klucza prywatnego</label>
                        <input id="test_private_key_path" name="test_private_key_path" type="text" value="<?= htmlspecialchars((string) $settings['ksef']['test']['private_key_path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>
                    <div class="form-field">
                        <label for="test_token">Nowy token test</label>
                        <input id="test_token" name="test_token" type="password" value="" autocomplete="new-password" spellcheck="false">
                        <p class="small-note">Pole jest zawsze puste po odswiezeniu. Stan zapisanej wartosci sprawdzaj wyzej w statusie sekretow.</p>
                    </div>
                </div>
                <label class="checkbox-line">
                    <input type="checkbox" name="clear_test_token" value="1">
                    Wyczysc zapisany token test
                </label>

                <button class="button" type="submit">Zapisz ustawienia KSeF</button>
            </form>
        </article>

        <article class="panel" id="settings-ai">
            <h2>Ustawienia AI</h2>
            <?php if (($activeForm ?? '') === 'ai'): ?>
                <?php foreach (($alerts ?? []) as $index => $alert): ?>
                    <div class="alert alert-<?= htmlspecialchars((string) $alert['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" id="settings-ai-alert-<?= $index ?>">
                        <strong><?= htmlspecialchars((string) strtoupper($alert['type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                        <p><?= htmlspecialchars((string) $alert['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                        <button type="button" data-dismiss="#settings-ai-alert-<?= $index ?>">x</button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <form method="post" action="<?= htmlspecialchars($config->url('/settings'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="settings-form" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

                <div class="form-grid">
                    <div class="form-field">
                        <label for="provider">Tryb AI</label>
                        <select id="provider" name="provider">
                            <?php
                            $providerLabels = [
                                'ollama' => 'ollama',
                                'hybrid' => 'hybrid',
                                'openai' => 'openai',
                            ];
                            ?>
                            <?php foreach ($settings['ai']['available_providers'] as $provider): ?>
                                <option value="<?= htmlspecialchars((string) $provider, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= ($settings['ai']['provider'] ?? 'ollama') === $provider ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) ($providerLabels[$provider] ?? $provider), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="small-note">Ten wybor steruje wszystkimi obecnymi i przyszlymi modulami AI w aplikacji.</p>
                    </div>
                </div>

                <div class="alert alert-warning">
                    <strong>UWAGA</strong>
                    <p>Tryby hybrid i openai moga wysylac wrazliwe dane z faktur poza lokalna stacje. Dla bezpiecznej pracy domyslnie rekomendowany jest tryb ollama.</p>
                </div>

                <h3>Ollama lokalna</h3>
                <div class="form-grid">
                    <div class="form-field">
                        <label for="ollama_base_url">Endpoint</label>
                        <input id="ollama_base_url" name="ollama_base_url" type="url" value="<?= htmlspecialchars((string) ($settings['ollama']['base_url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>
                    <div class="form-field">
                        <label for="ollama_model">Model vision</label>
                        <input id="ollama_model" name="ollama_model" type="text" value="<?= htmlspecialchars((string) ($settings['ollama']['model'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>
                    <div class="form-field">
                        <label for="ollama_timeout_seconds">Timeout (sekundy)</label>
                        <input id="ollama_timeout_seconds" name="ollama_timeout_seconds" type="number" min="30" step="1" value="<?= htmlspecialchars((string) ($settings['ollama']['timeout_seconds'] ?? '180'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>
                    <div class="form-field">
                        <label for="ollama_keep_alive">keep_alive</label>
                        <input id="ollama_keep_alive" name="ollama_keep_alive" type="text" value="<?= htmlspecialchars((string) ($settings['ollama']['keep_alive'] ?? '15m'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>
                </div>

                <label class="checkbox-line">
                    <input type="checkbox" name="ollama_local_only" value="1" <?= !empty($settings['ollama']['local_only']) ? 'checked' : '' ?>>
                    Ogranicz endpoint Ollama do localhost tej samej stacji
                </label>

                <h3>OpenAI</h3>
                <div class="form-grid">
                    <div class="form-field">
                        <label for="model">Model</label>
                        <input id="model" name="model" type="text" value="<?= htmlspecialchars((string) $settings['openai']['model'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>
                    <div class="form-field">
                        <label for="api_key">Nowy API key</label>
                        <input id="api_key" name="api_key" type="password" value="" autocomplete="new-password" spellcheck="false">
                        <p class="small-note">Jesli klucz jest zapisany, zobaczysz to wyzej jako "ustawione (********)".</p>
                    </div>
                </div>

                <label class="checkbox-line">
                    <input type="checkbox" name="clear_api_key" value="1">
                    Wyczysc zapisany OpenAI API key
                </label>

                <div class="form-actions">
                    <button class="button button-secondary" type="submit" name="form_name" value="ai_test">Sprawdz polaczenie z Ollama</button>
                    <button class="button" type="submit" name="form_name" value="ai">Zapisz ustawienia AI</button>
                </div>
            </form>
        </article>

        <article class="panel" id="settings-bank">
            <h2>Dane platnika do przelewow</h2>
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
                        <label for="payer_name">Nazwa platnika</label>
                        <input id="payer_name" name="payer_name" type="text" value="<?= htmlspecialchars((string) $settings['bank']['payer_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>
                    <div class="form-field">
                        <label for="payer_address">Adres platnika</label>
                        <input id="payer_address" name="payer_address" type="text" value="<?= htmlspecialchars((string) $settings['bank']['payer_address'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>
                    <div class="form-field">
                        <label for="payer_iban">IBAN / NRB platnika</label>
                        <input id="payer_iban" name="payer_iban" type="text" value="<?= htmlspecialchars((string) $settings['bank']['payer_iban'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>
                    <div class="form-field">
                        <label for="default_currency">Waluta domyslna</label>
                        <select id="default_currency" name="default_currency">
                            <?php foreach (['PLN', 'EUR', 'USD'] as $currency): ?>
                                <option value="<?= htmlspecialchars($currency, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $settings['bank']['default_currency'] === $currency ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($currency, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="small-note">Przy bledzie walidacji komunikat pojawi sie tutaj, bez przeskoku na gore strony.</p>
                    </div>
                </div>

                <button class="button" type="submit">Zapisz dane platnika</button>
            </form>
        </article>
    </section>
</div>
