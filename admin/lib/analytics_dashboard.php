<?php

declare(strict_types=1);

/**
 * Markup helpers for the interactive dashboard layer on developer_analytics.php.
 * Each widget is a <div data-ad="kind" data-cfg="id"> plus a JSON config block;
 * assets/js/developer_analytics_dashboard.js turns those into charts.
 */

const AD_WINE = '#a23a56';
const AD_BLUE = '#2b7fb8';
const AD_GOLD = '#d9a21b';
const AD_TEAL = '#1f9d94';
const AD_VIOLET = '#7d62b8';
const AD_BRAND = '#6a2036';

/** Categorical colours in fixed order (validated for colour-vision separation). */
const AD_SERIES = [AD_WINE, AD_BLUE, AD_GOLD, AD_TEAL, AD_VIOLET];

function ad_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/** JSON for a <script type="application/json"> block (safe against </script> and quotes). */
function ad_json(array $data): string
{
    return (string) json_encode(
        $data,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
    );
}

/** Echo a widget: its mount element and the JSON config it reads. */
function ad_widget(string $kind, array $cfg, string $extraClass = ''): void
{
    static $counter = 0;
    $counter++;
    $id = 'adcfg' . $counter;
    echo '<div class="ad-widget ad-widget--' . ad_e($kind) . ($extraClass !== '' ? ' ' . ad_e($extraClass) : '') . '" data-ad="' . ad_e($kind) . '" data-cfg="' . $id . '"></div>';
    echo '<script type="application/json" id="' . $id . '">' . ad_json($cfg) . '</script>';
}

function ad_panel_open(string $title, string $subtitle = '', int $span = 6): void
{
    echo '<section class="ad-panel ad-panel--span-' . $span . ' ad-reveal">';
    echo '<div class="ad-panel__head"><h2 class="ad-panel__title">' . ad_e($title) . '</h2>';
    if ($subtitle !== '') {
        echo '<p class="ad-panel__sub">' . ad_e($subtitle) . '</p>';
    }
    echo '</div>';
}

function ad_panel_close(): void
{
    echo '</section>';
}

function ad_empty(string $message): void
{
    echo '<p class="ad-empty">' . ad_e($message) . '</p>';
}

/**
 * Comparison with the previous period, as structured data.
 *
 * @param 'pct'|'pts'|'pos' $mode  pct = relative change, pts = percentage points
 *                                 (inputs are fractions), pos = search position (lower is better)
 * @return array{text: string, dir: int, good: ?bool}|null
 */
function ad_delta(float $current, ?float $previous, string $mode = 'pct', bool $upIsGood = true): ?array
{
    if ($previous === null) {
        return null;
    }
    if ($mode === 'pts') {
        $diff = ($current - $previous) * 100;
        $text = number_format(abs($diff), 1) . ' pts';
    } elseif ($mode === 'pos') {
        $diff = $previous - $current;
        $text = number_format(abs($diff), 1) . ' places';
    } else {
        if ($previous == 0.0) {
            return $current == 0.0
                ? ['text' => 'No change', 'dir' => 0, 'good' => null]
                : ['text' => 'New', 'dir' => 1, 'good' => $upIsGood];
        }
        $diff = ($current - $previous) / $previous * 100;
        $text = number_format(abs($diff), 1) . '%';
    }
    if (abs($diff) < 0.05) {
        return ['text' => 'No change', 'dir' => 0, 'good' => null];
    }
    $dir = $diff > 0 ? 1 : -1;
    return ['text' => $text, 'dir' => $dir, 'good' => ($dir > 0) === $upIsGood];
}

/**
 * One KPI card.
 *
 * @param array{
 *   label: string, value: float|int, format?: string, decimals?: int, icon?: string,
 *   accent?: string, delta?: ?array, compare?: string, meta?: string, spark?: list<float|int>
 * } $card
 */
function ad_kpi(array $card): string
{
    $accent = (string) ($card['accent'] ?? AD_BRAND);
    $icon = (string) ($card['icon'] ?? 'fa-chart-simple');
    $delta = $card['delta'] ?? null;

    $chip = '';
    if (is_array($delta)) {
        $class = $delta['good'] === true ? ' ad-delta--good' : ($delta['good'] === false ? ' ad-delta--bad' : '');
        $arrow = $delta['dir'] > 0 ? '▲ ' : ($delta['dir'] < 0 ? '▼ ' : '');
        $chip = '<span class="ad-delta' . $class . '" title="' . ad_e((string) ($card['compare'] ?? '')) . '">' . $arrow . ad_e($delta['text']) . '</span>';
    }
    $meta = (string) ($card['meta'] ?? '');

    $html = '<article class="ad-kpi ad-reveal" style="--kpi-accent:' . ad_e($accent) . '"'
        . ' data-value="' . ad_e((string) ($card['value'] ?? 0)) . '"'
        . ' data-format="' . ad_e((string) ($card['format'] ?? 'int')) . '"'
        . ' data-decimals="' . (int) ($card['decimals'] ?? 0) . '">';
    $html .= '<div class="ad-kpi__head"><span class="ad-kpi__icon"><i class="fa-solid ' . ad_e($icon) . '" aria-hidden="true"></i></span>' . ad_e((string) $card['label']) . '</div>';
    $html .= '<p class="ad-kpi__value" data-kpi-value>' . ad_e(ad_format_plain($card)) . '</p>';
    $html .= '<div class="ad-kpi__foot"><div class="ad-kpi__meta">' . $chip
        . ($meta !== '' ? '<span>' . ad_e($meta) . '</span>' : '') . '</div>';
    $spark = $card['spark'] ?? [];
    if (count($spark) >= 2) {
        $html .= '<div class="ad-spark" data-kpi-spark data-color="' . ad_e($accent) . '" data-points="' . ad_e(ad_json(array_values(array_map('floatval', $spark)))) . '"></div>';
    }
    $html .= '</div></article>';
    return $html;
}

/** Final value as text, so the card is correct even before JavaScript runs. */
function ad_format_plain(array $card): string
{
    $value = (float) ($card['value'] ?? 0);
    $decimals = (int) ($card['decimals'] ?? 0);
    switch ($card['format'] ?? 'int') {
        case 'pct':
            return number_format($value, $decimals) . '%';
        case 'sec':
            $s = (int) round($value);
            return $s < 60 ? $s . 's' : intdiv($s, 60) . 'm ' . ($s % 60) . 's';
        case 'gbp':
            return '£' . number_format($value, $decimals);
        case 'dec':
            return number_format($value, $decimals ?: 1);
        default:
            return number_format($value);
    }
}

/** @param list<array> $cards */
function ad_kpi_row(array $cards): void
{
    echo '<section class="ad-kpis" aria-label="Key figures">';
    foreach ($cards as $card) {
        echo ad_kpi($card);
    }
    echo '</section>';
}

/** Short "d M" label for a Y-m-d or Ymd date string. */
function ad_day_label(string $date): string
{
    $ts = strtotime($date);
    return $ts === false ? $date : date('j M', $ts);
}

/** True when a table control (sort/pagination) was used, so the tables open themselves. */
function ad_tables_should_open(): bool
{
    foreach (array_keys($_GET) as $key) {
        if (str_ends_with((string) $key, '_page') || str_ends_with((string) $key, '_sort') || str_ends_with((string) $key, '_dir')) {
            return true;
        }
    }
    return false;
}

function ad_tables_open_tag(string $hint = ''): void
{
    echo '<details class="ad-details ad-reveal" data-ad-tables data-open="' . (ad_tables_should_open() ? '1' : '0') . '">';
    echo '<summary>Detailed tables &amp; export' . ($hint !== '' ? ' <span class="ad-details__hint">' . ad_e($hint) . '</span>' : '') . '</summary>';
    echo '<div class="ad-details__body">';
}

function ad_tables_close_tag(): void
{
    echo '</div></details>';
}

/** A large figure with a one-line explanation, at the foot of a panel. */
function ad_callout(string $big, string $text): void
{
    echo '<p class="ad-callout"><strong>' . ad_e($big) . '</strong><span>' . ad_e($text) . '</span></p>';
}

/** @param list<array> $rows */
function ad_sort_desc(array $rows, string $key): array
{
    usort($rows, static fn(array $a, array $b): int => (float) ($b[$key] ?? 0) <=> (float) ($a[$key] ?? 0));
    return $rows;
}
