<?php
/** @var array $meeting */
/** @var bool $pdfMode */
$pdfMode = $pdfMode ?? false;
$numbered = numberedItems($meeting['items']);
$chapters = count(array_filter($numbered, static fn (array $item): bool => (int) $item['level'] === 1));
?>
            <?php if ($logo = ($pdfMode ? logoPath() : logoUrl())): ?><img class="doc-logo" src="<?= h($logo) ?>" alt="Logo střediska"><?php endif; ?>
            <h2 class="doc-title">Zápis ze střediskové rady č. <?= (int) $meeting['meeting_number'] ?> rok <?= h($meeting['season']) ?></h2>
            <p class="doc-meta">
                <span><strong>Místo:</strong> <?= h($meeting['place']) ?></span>
                <span><strong>Datum konání:</strong> <?= h(formatDate($meeting['meeting_date'])) ?></span>
                <?php if ($meeting['time_from']): ?>
                    <span><strong>Čas konání:</strong> <?= h(formatTime($meeting['time_from'])) ?><?= $meeting['time_to'] ? ' – ' . h(formatTime($meeting['time_to'])) : '' ?></span>
                <?php endif; ?>
            </p>

            <h3>Docházka</h3>
            <p><strong>Přítomni:</strong> <?= h($meeting['attendees']) ?></p>
            <?php if ($meeting['online_attendees']): ?><p><strong>Online:</strong> <?= h($meeting['online_attendees']) ?></p><?php endif; ?>
            <p><strong>Omluveni:</strong> <?= h($meeting['absentees']) ?></p>

            <?php if ($numbered || $meeting['resolutions']): ?>
                <h3>Osnova rady:</h3>
                <ol class="toc">
                    <?php foreach ($numbered as $item): ?>
                        <li class="level-<?= (int) $item['level'] ?>"><?= h($item['label']) ?> <?= h($item['title']) ?></li>
                    <?php endforeach; ?>
                    <?php if ($meeting['resolutions']): ?>
                        <li class="level-1"><?= $chapters + 1 ?>. Usnesení</li>
                    <?php endif; ?>
                </ol>
            <?php endif; ?>

            <?php foreach ($numbered as $item): ?>
                <section class="doc-item level-<?= (int) $item['level'] ?>">
                    <?php if ((int) $item['level'] === 1): ?>
                        <h3><?= h($item['label']) ?> <?= h($item['title']) ?></h3>
                    <?php else: ?>
                        <h4><?= h($item['label']) ?> <?= h($item['title']) ?></h4>
                    <?php endif; ?>
                    <?= renderNotes($item['notes']) ?>
                    <?php if ((int) $item['level'] >= 2 && mb_strtolower(trim($item['title'])) === 'vedoucí družin a družiny'): ?>
                        <?= renderSeasonTimetables($meeting['season'], $pdfMode) ?>
                    <?php endif; ?>
                    <?php $titleLower = mb_strtolower($item['title']); ?>
                    <?php if (str_contains($titleLower, 'výprav')): ?>
                        <?= renderTripTables($meeting['trips'] ?? []) ?>
                    <?php elseif (str_contains($titleLower, 'proběhlé akce')): ?>
                        <?= renderPastEventsTable($meeting['events_past']) ?>
                    <?php elseif (str_contains($titleLower, 'chystané akce')): ?>
                        <?= renderUpcomingEventsTable($meeting['events_upcoming']) ?>
                    <?php endif; ?>
                    <?php if ($item['carry_over']): ?><p class="carry-tag">↻ Řešit na další radě</p><?php endif; ?>
                </section>
            <?php endforeach; ?>

            <?php if ($meeting['resolutions']): ?>
                <section class="doc-item level-1">
                    <h3><?= $chapters + 1 ?>. Usnesení</h3>
                    <?php foreach ($meeting['resolutions'] as $resolution): ?>
                        <div class="resolution">
                            <p><?= nl2br(h($resolution['text'])) ?></p>
                            <p class="votes-line">
                                Pro: <?= h((string) $resolution['votes_for']) ?>
                                Proti: <?= h((string) $resolution['votes_against']) ?>
                                Zdržel se: <?= h((string) $resolution['votes_abstain']) ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <footer class="doc-footer">
                <?php if ($meeting['next_meeting_date']): ?>
                    <p><strong>Další rada:</strong> <?= h(formatDate($meeting['next_meeting_date'])) ?><?= $meeting['next_meeting_time'] ? ' v ' . h(formatTime($meeting['next_meeting_time'])) : '' ?></p>
                <?php endif; ?>
                <p><?= h(CLOSING_TEXT) ?></p>
                <p class="signatures">
                    <span><strong>Zapsal:</strong> <?= h($meeting['author']) ?><?php if (!empty($meeting['author_signature'])): ?><br><img class="sign-img" src="<?= h($meeting['author_signature']) ?>" alt="Podpis"><?php endif; ?></span>
                    <span><strong>Ověřil:</strong> <?= h($meeting['verifier']) ?><?php if (!empty($meeting['verifier_signature'])): ?><br><img class="sign-img" src="<?= h($meeting['verifier_signature']) ?>" alt="Podpis"><?php endif; ?></span>
                </p>
            </footer>
