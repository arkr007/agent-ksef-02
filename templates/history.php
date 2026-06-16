<div class="page-grid">
    <aside class="side-card">
        <h2>Co tu trafi</h2>
        <ul>
            <li>logowania użytkowników,</li>
            <li>pobrania z KSeF,</li>
            <li>uploady CSV i PDF,</li>
            <li>generowanie plików eksportowych.</li>
        </ul>
    </aside>

    <section class="page-stack">
        <article class="panel">
            <h2><?= htmlspecialchars((string) $pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars((string) $pageDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        </article>

        <article class="card">
            <h3>Ostatnie wpisy audit logu</h3>
            <table class="status-list">
                <thead>
                    <tr>
                        <th>Czas</th>
                        <th>Akcja</th>
                        <th>Użytkownik</th>
                        <th>IP</th>
                        <th>Kontekst</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($entries)): ?>
                        <tr>
                            <td colspan="5">Brak wpisów. Zaloguj się lub wykonaj operację, aby zobaczyć pierwszy ślad audytowy.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($entries as $entry): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $entry['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) $entry['action'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) ($entry['user_id'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) ($entry['ip_address'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                <td><code><?= htmlspecialchars((string) ($entry['context_json'] ?? '{}'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </article>
    </section>
</div>
