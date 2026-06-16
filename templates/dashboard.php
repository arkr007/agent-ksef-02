<div class="page-grid">
    <aside class="side-card">
        <h2>Etap 1</h2>
        <ul>
            <li>struktura katalogów i widoków,</li>
            <li>front controller i routing,</li>
            <li>konfiguracja i przygotowanie PDO,</li>
            <li>bazowy schemat MySQL/MariaDB.</li>
        </ul>
    </aside>

    <section class="page-stack">
        <?php foreach (($alerts ?? []) as $index => $alert): ?>
            <div class="alert alert-<?= htmlspecialchars((string) $alert['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" id="dash-alert-<?= $index ?>">
                <strong><?= htmlspecialchars((string) strtoupper($alert['type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                <p><?= htmlspecialchars((string) $alert['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <button type="button" data-dismiss="#dash-alert-<?= $index ?>">x</button>
            </div>
        <?php endforeach; ?>

        <article class="panel hero">
            <h2><?= htmlspecialchars((string) $pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars((string) $pageDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <div class="metrics">
                <div class="metric">
                    <span>Tryb konfiguracji</span>
                    <strong><?= $config->isConfigured() ? 'config.php obecny' : 'brak config.php' ?></strong>
                </div>
                <div class="metric">
                    <span>Backend</span>
                    <strong>PHP MVC</strong>
                </div>
                <div class="metric">
                    <span>Frontend</span>
                    <strong>TypeScript build lokalny</strong>
                </div>
                <div class="metric">
                    <span>Wdrożenie</span>
                    <strong>FTP / XAMPP</strong>
                </div>
            </div>
        </article>

        <section class="card-grid">
            <?php foreach (($cards ?? []) as $card): ?>
                <article class="card">
                    <h3><?= htmlspecialchars((string) $card['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
                    <p><?= htmlspecialchars((string) $card['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                    <a class="button" href="<?= htmlspecialchars(rtrim((string) $config->get('app.base_url', ''), '/') . $card['link'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        Otwórz moduł
                    </a>
                </article>
            <?php endforeach; ?>
        </section>

        <article class="panel">
            <h2>Status wdrożenia funkcji</h2>
            <table class="status-list">
                <thead>
                    <tr>
                        <th>Moduł</th>
                        <th>Status</th>
                        <th>Uwagi</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Fundament projektu</td>
                        <td><span class="badge ok">gotowe</span></td>
                        <td>Szkielet, routing, config i schemat bazy są przygotowane.</td>
                    </tr>
                    <tr>
                        <td>Bezpieczeństwo i logowanie</td>
                        <td><span class="badge warn">następny etap</span></td>
                        <td>Do wdrożenia: sesje, CSRF, logowanie, uploady i audit log.</td>
                    </tr>
                    <tr>
                        <td>KSeF / bank / PDF</td>
                        <td><span class="badge warn">szkielety klas</span></td>
                        <td>Klasy istnieją, ale logika biznesowa wejdzie w kolejnych etapach.</td>
                    </tr>
                </tbody>
            </table>
        </article>
    </section>
</div>
