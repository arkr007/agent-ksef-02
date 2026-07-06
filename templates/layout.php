<?php
/** @var string $content */
/** @var \App\Core\Config $config */
/** @var array|null $currentUser */
/** @var array $flashMessages */
/** @var string $csrfToken */

$baseUrl = rtrim((string) $config->get('app.base_url', ''), '/');
$title = $title ?? 'Agent KSeF';

$navItems = [
    '/accountant-package' => 'Pakiet dla ksiegowej',
    '/accounting-compare' => 'Porownanie JPK',
    '/settings' => 'Ustawienia',
];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars((string) $title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl . '/assets/styles.css', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
</head>
<body>
    <div class="app-shell">
        <header class="app-header">
            <div class="brand">
                <span class="brand-tag">Wersja produkcyjna</span>
                <h1>Agent KSeF</h1>
                <p>Pakiet dla ksiegowej i porownanie JPK w uproszczonym przeplywie roboczym.</p>
            </div>
            <div class="nav-stack">
                <nav class="top-nav" aria-label="Glowna nawigacja">
                    <?php if ($currentUser !== null): ?>
                        <?php foreach ($navItems as $path => $label): ?>
                            <a href="<?= htmlspecialchars($baseUrl . $path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                <?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <a href="<?= htmlspecialchars($baseUrl . '/login', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Logowanie</a>
                        <a href="<?= htmlspecialchars($baseUrl . '/setup-admin', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Pierwszy uzytkownik</a>
                    <?php endif; ?>
                </nav>

                <?php if ($currentUser !== null): ?>
                    <div class="user-bar">
                        <span>Zalogowany: <strong><?= htmlspecialchars((string) $currentUser['display_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></span>
                        <form method="post" action="<?= htmlspecialchars($baseUrl . '/logout', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                            <button class="button button-secondary" type="submit">Wyloguj</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </header>

        <?php foreach ($flashMessages as $index => $flash): ?>
            <div class="alert alert-<?= htmlspecialchars((string) $flash['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" id="flash-<?= $index ?>">
                <strong><?= htmlspecialchars((string) strtoupper($flash['type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                <p><?= htmlspecialchars((string) $flash['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <button type="button" data-dismiss="#flash-<?= $index ?>">x</button>
            </div>
        <?php endforeach; ?>

        <?= $content ?>
    </div>
    <script src="<?= htmlspecialchars($baseUrl . '/assets/app.js', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></script>
</body>
</html>
