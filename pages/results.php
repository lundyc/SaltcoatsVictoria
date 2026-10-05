<?php
/** Route: /results */
declare(strict_types=1);

set_meta([
    'title' => club('club_short_name', club('club_name')) . ' Results' . (seo_season_label() !== '' ? ' ' . seo_season_label() : '') . ' | ' . club('club_name'),
    'title_full' => '1',
    'description' => 'Latest ' . club('club_name') . ' results' . (seo_season_label() !== '' ? ' for the ' . seo_season_label() . ' season' : '')
        . ' — scores, competitions and full match centre pages.',
]);
seo_breadcrumbs([['Results', url('results')]]);

partial('fixtures_list', ['mode' => 'results']);
