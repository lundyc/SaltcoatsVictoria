<?php
/** Route: /fixtures */
declare(strict_types=1);

set_meta([
    'title' => club('club_short_name', club('club_name')) . ' Fixtures' . (seo_season_label() !== '' ? ' ' . seo_season_label() : '') . ' | ' . club('club_name'),
    'title_full' => '1',
    'description' => 'Upcoming ' . club('club_name') . ' fixtures' . (seo_season_label() !== '' ? ' for the ' . seo_season_label() . ' season' : '')
        . ' — kick-off times, venues, competitions and match tickets.',
]);
seo_breadcrumbs([['Fixtures', url('fixtures')]]);

partial('fixtures_list', ['mode' => 'upcoming']);
