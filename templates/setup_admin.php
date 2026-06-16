<section class="login-box">
    <?php foreach (($alerts ?? []) as $index => $alert): ?>
        <div class="alert alert-<?= htmlspecialchars((string) $alert['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" id="setup-alert-<?= $index ?>">
            <strong><?= htmlspecialchars((string) strtoupper($alert['type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
            <p><?= htmlspecialchars((string) $alert['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <button type="button" data-dismiss="#setup-alert-<?= $index ?>">x</button>
        </div>
    <?php endforeach; ?>

    <article class="panel">
        <h2><?= htmlspecialchars((string) $pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
        <p><?= htmlspecialchars((string) $pageDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <form method="post" action="<?= htmlspecialchars(rtrim((string) $config->get('app.base_url', ''), '/') . '/setup-admin', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <div class="form-field">
                <label for="display_name">Nazwa użytkownika</label>
                <input id="display_name" name="display_name" type="text" placeholder="Administrator" required>
            </div>
            <div class="form-field">
                <label for="email">Adres e-mail</label>
                <input id="email" name="email" type="email" placeholder="admin@example.pl" required>
            </div>
            <div class="form-field">
                <label for="password">Hasło</label>
                <input id="password" name="password" type="password" placeholder="Minimum 12 znaków" required>
            </div>
            <div class="form-field">
                <label for="password_confirm">Powtórz hasło</label>
                <input id="password_confirm" name="password_confirm" type="password" placeholder="Powtórz hasło" required>
            </div>
            <button class="button" type="submit">Utwórz konto</button>
        </form>
    </article>
</section>
