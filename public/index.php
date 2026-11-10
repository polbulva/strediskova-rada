<?php

require __DIR__ . '/src/bootstrap.php';

if (currentUser()) {
    redirect('portal.php');
}

$config = appSettings();
$loginUrl = null;
$loginProblem = '';
try {
    if (skautisConfigured()) {
        $loginUrl = skautisClient()->loginUrl(baseUrl() . 'login.php');
    } else {
        $loginProblem = 'Přihlášení přes skautIS zatím není nastaveno (chybí ID aplikace nebo PHP rozšíření SOAP).';
    }
} catch (Throwable $exception) {
    $loginProblem = $exception->getMessage();
}

$demoEnabled = !empty($config['demo_login']);
$demoRoles = $demoEnabled ? demoRoleOptions() : [];

renderHeader('Přihlášení', null, 'landing');
?>
<main class="landing">
    <section class="landing-hero">
        <div class="container">
            <p class="eyebrow">Skautské středisko</p>
            <h1>⚜ Středisková rada</h1>
            <p class="lead">Interní nástroje střediska na jednom místě. Přihlaste se svým účtem skautIS.</p>
        </div>
    </section>

    <div class="container landing-cards">
        <?php renderFlash(); ?>

        <section class="card login-card">
            <h2>Přihlášení přes skautIS</h2>
            <p class="muted">Použijte své běžné přihlašovací údaje do skautISu. Po přihlášení uvidíte jen ty části aplikace, na které má vaše role oprávnění.</p>
            <?php if ($loginUrl): ?>
                <a class="button big" href="<?= h($loginUrl) ?>">Přihlásit se přes skautIS</a>
            <?php else: ?>
                <p class="message error"><?= h($loginProblem) ?></p>
                <span class="button big disabled" aria-disabled="true">Přihlásit se přes skautIS</span>
            <?php endif; ?>
        </section>

        <?php if ($demoEnabled): ?>
            <section class="card login-card demo">
                <h2>Testovací vstup bez přihlášení</h2>
                <p class="muted">Jen pro vývoj a testování, dokud není skautIS připojen. Vyberte, za jakou roli se chcete vydávat – tak si vyzkoušíte i omezení přístupu.</p>
                <form method="post" action="login.php">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="demo" value="1">
                    <label>Role
                        <select name="role">
                            <?php foreach ($demoRoles as $key => $label): ?>
                                <option value="<?= h((string) $key) ?>"><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="button secondary big" type="submit">Vstoupit bez přihlášení</button>
                </form>
            </section>
        <?php endif; ?>
    </div>
</main>
<?php renderFooter(); ?>
