<section class="login-box">
    <?php foreach (($alerts ?? []) as $index => $alert): ?>
        <div class="alert alert-<?= htmlspecialchars((string) $alert['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" id="login-alert-<?= $index ?>">
            <strong><?= htmlspecialchars((string) strtoupper($alert['type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
            <p><?= htmlspecialchars((string) $alert['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <button type="button" data-dismiss="#login-alert-<?= $index ?>">x</button>
        </div>
    <?php endforeach; ?>

    <article class="panel">
        <h2><?= htmlspecialchars((string) $pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
        <p><?= htmlspecialchars((string) $pageDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <form method="post" action="<?= htmlspecialchars(rtrim((string) $config->get('app.base_url', ''), '/') . '/login', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <div class="form-field">
                <label for="email">Adres e-mail</label>
                <input id="email" name="email" type="email" placeholder="konto@example.pl" required>
            </div>
            <div class="form-field">
                <label for="password">Hasło</label>
                <input id="password" name="password" type="password" placeholder="••••••••" required>
            </div>
            <button class="button" type="submit">Zaloguj</button>
        </form>
        <?php if (!empty($showSetupLink)): ?>
            <p class="small-note">
                Nie ma jeszcze żadnego użytkownika.
                <a href="<?= htmlspecialchars(rtrim((string) $config->get('app.base_url', ''), '/') . '/setup-admin', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Utwórz pierwsze konto</a>.
            </p>
        <?php endif; ?>
    </article>
</section>
