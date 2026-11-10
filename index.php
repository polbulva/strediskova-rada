<?php

session_start();

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function databaseConnection(): PDO
{
    $config = is_file(__DIR__ . '/config.php')
        ? require __DIR__ . '/config.php'
        : [
            'host' => getenv('DB_HOST') ?: 'localhost',
            'database' => getenv('DB_NAME') ?: '',
            'username' => getenv('DB_USER') ?: '',
            'password' => getenv('DB_PASSWORD') ?: '',
        ];

    if (!is_array($config) || empty($config['database']) || empty($config['username'])) {
        throw new RuntimeException('Není nastavena konfigurace databáze.');
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $config['host'] ?? 'localhost',
        $config['database']
    );

    return new PDO($dsn, $config['username'], $config['password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$values = [
    'meeting_date' => date('Y-m-d'),
    'place' => '',
    'attendees' => '',
    'absentees' => '',
    'chair' => '',
    'secretary' => '',
    'agenda' => '',
    'minutes' => '',
    'tasks' => '',
    'next_meeting' => '',
];
$errors = [];
$notice = '';
$databaseError = '';
$pdo = null;

try {
    $pdo = databaseConnection();
} catch (Throwable $exception) {
    $databaseError = 'Databázi se nepodařilo připojit. Zkontrolujte konfiguraci a postup v README.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $key => $default) {
        $values[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Formulář vypršel nebo není platný. Obnovte stránku a zkuste to znovu.';
    }

    foreach (['meeting_date', 'place', 'attendees', 'agenda', 'minutes'] as $required) {
        if ($values[$required] === '') {
            $errors[] = 'Vyplňte všechna povinná pole.';
            break;
        }
    }

    foreach (['meeting_date', 'next_meeting'] as $dateField) {
        if ($values[$dateField] !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $values[$dateField]);
            if (!$date || $date->format('Y-m-d') !== $values[$dateField]) {
                $errors[] = 'Zkontrolujte zadané datum.';
                break;
            }
        }
    }

    if (!$errors && $pdo) {
        try {
            $statement = $pdo->prepare(
                'INSERT INTO meeting_minutes
                    (meeting_date, place, attendees, absentees, chair, secretary, agenda, minutes, tasks, next_meeting)
                 VALUES
                    (:meeting_date, :place, :attendees, :absentees, :chair, :secretary, :agenda, :minutes, :tasks, :next_meeting)'
            );
            $statement->execute([
                'meeting_date' => $values['meeting_date'],
                'place' => $values['place'],
                'attendees' => $values['attendees'],
                'absentees' => $values['absentees'] ?: null,
                'chair' => $values['chair'] ?: null,
                'secretary' => $values['secretary'] ?: null,
                'agenda' => $values['agenda'],
                'minutes' => $values['minutes'],
                'tasks' => $values['tasks'] ?: null,
                'next_meeting' => $values['next_meeting'] ?: null,
            ]);

            header('Location: index.php?id=' . $pdo->lastInsertId() . '&saved=1');
            exit;
        } catch (Throwable $exception) {
            $errors[] = 'Zápis se nepodařilo uložit. Zkontrolujte, že je databáze připravena podle schema.sql.';
        }
    }
}

$meeting = null;
$meetings = [];
if ($pdo) {
    try {
        if (isset($_GET['id']) && ctype_digit((string) $_GET['id'])) {
            $statement = $pdo->prepare('SELECT * FROM meeting_minutes WHERE id = :id');
            $statement->execute(['id' => (int) $_GET['id']]);
            $meeting = $statement->fetch() ?: null;
        }

        $meetings = $pdo->query(
            'SELECT id, meeting_date, place FROM meeting_minutes ORDER BY meeting_date DESC, id DESC LIMIT 10'
        )->fetchAll();
    } catch (Throwable $exception) {
        $databaseError = 'Zápisy nelze načíst. Zkontrolujte, že je databáze připravena podle schema.sql.';
    }
}

if (isset($_GET['saved'])) {
    $notice = 'Zápis byl uložen.';
}
?>
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Zápisy ze střediskové rady</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header class="site-header">
    <div class="container">
        <p class="eyebrow">Středisková rada</p>
        <h1>Zápisy z jednání</h1>
        <p>Vytvořte, uložte a vytiskněte zápis z rady.</p>
    </div>
</header>

<main class="container">
    <?php if ($notice): ?>
        <p class="message success"><?= h($notice) ?></p>
    <?php endif; ?>
    <?php if ($databaseError): ?>
        <p class="message error"><?= h($databaseError) ?></p>
    <?php endif; ?>
    <?php foreach ($errors as $error): ?>
        <p class="message error"><?= h($error) ?></p>
    <?php endforeach; ?>

    <?php if ($meeting): ?>
        <article class="card minutes">
            <div class="minutes-heading">
                <div>
                    <p class="eyebrow">Zápis z jednání</p>
                    <h2><?= h(date('j. n. Y', strtotime($meeting['meeting_date']))) ?></h2>
                </div>
                <button class="button secondary print-button" type="button" onclick="window.print()">Tisknout</button>
            </div>
            <dl class="details">
                <dt>Místo</dt><dd><?= h($meeting['place']) ?></dd>
                <dt>Přítomni</dt><dd><?= nl2br(h($meeting['attendees'])) ?></dd>
                <?php if ($meeting['absentees']): ?><dt>Omluveni</dt><dd><?= nl2br(h($meeting['absentees'])) ?></dd><?php endif; ?>
                <?php if ($meeting['chair']): ?><dt>Vedoucí jednání</dt><dd><?= h($meeting['chair']) ?></dd><?php endif; ?>
                <?php if ($meeting['secretary']): ?><dt>Zapisovatel/ka</dt><dd><?= h($meeting['secretary']) ?></dd><?php endif; ?>
                <dt>Program</dt><dd><?= nl2br(h($meeting['agenda'])) ?></dd>
                <dt>Průběh a závěry</dt><dd><?= nl2br(h($meeting['minutes'])) ?></dd>
                <?php if ($meeting['tasks']): ?><dt>Úkoly</dt><dd><?= nl2br(h($meeting['tasks'])) ?></dd><?php endif; ?>
                <?php if ($meeting['next_meeting']): ?><dt>Příští jednání</dt><dd><?= h(date('j. n. Y', strtotime($meeting['next_meeting']))) ?></dd><?php endif; ?>
            </dl>
        </article>
    <?php endif; ?>

    <section class="layout">
        <section class="card form-card">
            <h2>Nový zápis</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <div class="form-grid">
                    <label>Datum jednání <span class="required">*</span>
                        <input type="date" name="meeting_date" required value="<?= h($values['meeting_date']) ?>">
                    </label>
                    <label>Místo <span class="required">*</span>
                        <input type="text" name="place" required maxlength="255" value="<?= h($values['place']) ?>">
                    </label>
                    <label>Přítomni <span class="required">*</span>
                        <textarea name="attendees" rows="3" required><?= h($values['attendees']) ?></textarea>
                    </label>
                    <label>Omluveni
                        <textarea name="absentees" rows="3"><?= h($values['absentees']) ?></textarea>
                    </label>
                    <label>Vedoucí jednání
                        <input type="text" name="chair" maxlength="255" value="<?= h($values['chair']) ?>">
                    </label>
                    <label>Zapisovatel/ka
                        <input type="text" name="secretary" maxlength="255" value="<?= h($values['secretary']) ?>">
                    </label>
                    <label class="wide">Program jednání <span class="required">*</span>
                        <textarea name="agenda" rows="4" required><?= h($values['agenda']) ?></textarea>
                    </label>
                    <label class="wide">Průběh jednání a závěry <span class="required">*</span>
                        <textarea name="minutes" rows="7" required><?= h($values['minutes']) ?></textarea>
                    </label>
                    <label class="wide">Úkoly (odpovědná osoba, termín)
                        <textarea name="tasks" rows="4"><?= h($values['tasks']) ?></textarea>
                    </label>
                    <label>Příští jednání
                        <input type="date" name="next_meeting" value="<?= h($values['next_meeting']) ?>">
                    </label>
                </div>
                <button class="button" type="submit">Uložit zápis</button>
            </form>
        </section>

        <aside class="card recent-card">
            <h2>Poslední zápisy</h2>
            <?php if ($meetings): ?>
                <ul class="meeting-list">
                    <?php foreach ($meetings as $item): ?>
                        <li>
                            <a href="index.php?id=<?= (int) $item['id'] ?>">
                                <strong><?= h(date('j. n. Y', strtotime($item['meeting_date']))) ?></strong>
                                <span><?= h($item['place']) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="muted">Zatím zde nejsou žádné uložené zápisy.</p>
            <?php endif; ?>
        </aside>
    </section>
</main>
<footer class="container footer">Zápisy ze střediskové rady</footer>
</body>
</html>
