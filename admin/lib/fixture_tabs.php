<?php
declare(strict_types=1);

function renderFixtureTabs(int $fixtureId, int $seasonId, string $activeTab, bool $embeddedFixtureTabs = false): void
{
          $tabs = [
                    'overview' => [
                              'label' => 'Overview',
                              'icon' => 'fa-gauge-high',
                              'href' => 'match.php?id=' . $fixtureId . '&season_id=' . $seasonId . '&tab=overview',
                              'target' => '#fixture-overview-pane',
                    ],
                    'details' => [
                              'label' => 'Details',
                              'icon' => 'fa-clipboard-list',
                              'href' => 'match.php?id=' . $fixtureId . '&season_id=' . $seasonId . '&tab=details',
                              'target' => '#fixture-details-pane',
                    ],
                    'sponsorships' => [
                              'label' => 'Sponsorships',
                              'icon' => 'fa-handshake',
                              'href' => 'match.php?id=' . $fixtureId . '&season_id=' . $seasonId . '&tab=sponsorships',
                              'target' => '#fixture-sponsorships-pane',
                    ],
                    'starting11' => [
                              'label' => 'Line-ups',
                              'icon' => 'fa-people-group',
                              'href' => 'match.php?id=' . $fixtureId . '&season_id=' . $seasonId . '&tab=starting11',
                              'target' => '#fixture-starting11-pane',
                    ],
                    'graphics' => [
                              'label' => 'Events',
                              'icon' => 'fa-stopwatch',
                              'href' => 'match_record_events.php?fixture_id=' . $fixtureId . '&season_id=' . $seasonId,
                              'target' => '',
                    ],
                    'media' => [
                              'label' => 'Media',
                              'icon' => 'fa-images',
                              'href' => 'match_media.php?fixture_id=' . $fixtureId . '&season_id=' . $seasonId,
                              'target' => '',
                    ],
                    'player_of_match' => [
                              'label' => 'POTM',
                              'icon' => 'fa-medal',
                              'href' => 'match_player_of_match.php?fixture_id=' . $fixtureId . '&season_id=' . $seasonId,
                              'target' => '',
                    ],
          ];
          ?>
          <div class="fixture-tabs-shell" aria-label="Fixture sections">
          <ul class="nav fixture-tabs flex-nowrap" id="fixtureTabs" role="tablist">
                    <?php foreach ($tabs as $key => $tab): ?>
                              <?php
                              $isActive = $activeTab === $key;
                              $useButton = $embeddedFixtureTabs && $tab['target'] !== '';
                              ?>
                              <li class="nav-item" role="presentation">
                                        <?php if ($useButton): ?>
                                                  <button class="nav-link <?= $isActive ? 'active' : '' ?>" id="fixture-<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>-tab" data-bs-toggle="tab" data-bs-target="<?= htmlspecialchars($tab['target'], ENT_QUOTES, 'UTF-8') ?>" type="button" role="tab" aria-controls="<?= htmlspecialchars(ltrim($tab['target'], '#'), ENT_QUOTES, 'UTF-8') ?>" aria-selected="<?= $isActive ? 'true' : 'false' ?>"><i class="fa-solid <?= htmlspecialchars((string)$tab['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i><span><?= htmlspecialchars($tab['label'], ENT_QUOTES, 'UTF-8') ?></span></button>
                                        <?php else: ?>
                                                  <a class="nav-link <?= $isActive ? 'active' : '' ?>" href="<?= htmlspecialchars($tab['href'], ENT_QUOTES, 'UTF-8') ?>" role="tab" aria-selected="<?= $isActive ? 'true' : 'false' ?>" <?= $isActive ? 'aria-current="page"' : '' ?>><i class="fa-solid <?= htmlspecialchars((string)$tab['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i><span><?= htmlspecialchars($tab['label'], ENT_QUOTES, 'UTF-8') ?></span></a>
                                        <?php endif; ?>
                              </li>
                    <?php endforeach; ?>
          </ul>
          </div>
          <?php
}
