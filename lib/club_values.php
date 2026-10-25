<?php

declare(strict_types=1);

/**
 * Split the Mission/Vision/Values club page HTML into structured parts:
 *   ['intro' => string[], 'mission' => string, 'vision' => item[], 'values' => item[]]
 * where item = ['title' => string, 'body' => string]. Empty parts on failure.
 */
function pub_mvv_parse(string $html): array
{
    $out = ['intro' => [], 'mission' => '', 'vision' => [], 'values' => []];
    if (trim($html) === '' || !class_exists('DOMDocument')) {
        return $out;
    }
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML(
        '<?xml encoding="utf-8"?><div id="r">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    $root = $doc->getElementById('r');
    if (!$root) {
        return $out;
    }

    $section = '';
    foreach (iterator_to_array($root->childNodes) as $node) {
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            continue;
        }
        $tag  = strtolower($node->nodeName);
        $text = trim(preg_replace('/\s+/', ' ', $node->textContent) ?? '');
        if ($text === '') {
            continue;
        }
        if ($tag === 'h3') {
            $l = strtolower($text);
            $section = str_contains($l, 'mission') ? 'mission'
                : (str_contains($l, 'vision') ? 'vision'
                : (str_contains($l, 'value') ? 'values' : ''));
            continue;
        }
        if ($tag === 'h4' && ($section === 'vision' || $section === 'values')) {
            $out[$section][] = ['title' => $text, 'body' => ''];
            continue;
        }
        if ($tag === 'p' || str_starts_with($tag, 'h')) {
            if ($section === 'mission') {
                $out['mission'] = trim($out['mission'] . ' ' . $text);
            } elseif (($section === 'vision' || $section === 'values') && $out[$section] !== []) {
                $k = array_key_last($out[$section]);
                $out[$section][$k]['body'] = trim($out[$section][$k]['body'] . ' ' . $text);
            } elseif ($section === '' && $tag === 'p' && stripos($text, 'view our mission') === false) {
                $out['intro'][] = $text;
            }
        }
    }
    return $out;
}
