<?php

declare(strict_types=1);

require_once __DIR__ . '/template_packs.php';

/**
 * Resolve the immutable pack version assigned to a fixture. Rendering must
 * remain available if pack storage is temporarily unavailable, so callers get
 * an empty context and retain their established graphic defaults.
 *
 * @return array<string, mixed>
 */
function matchTemplatePackContext(PDO $pdo, int $fixtureId, string $actionKey, bool $lock = true): array
{
          try {
                    return template_packs_resolve_for_fixture($pdo, $fixtureId, $actionKey, $lock);
          } catch (Throwable $error) {
                    error_log('Template pack resolution failed: ' . $error->getMessage());
                    return [
                              'pack' => null,
                              'version' => null,
                              'source' => 'none',
                              'brand_settings' => [],
                              'action' => null,
                              'assignment' => null,
                    ];
          }
}

/**
 * Convert both new pack assets and migrated legacy assets into public URLs.
 */
function matchTemplatePackAssetUrl(?string $path): string
{
          $path = trim((string)$path);
          if ($path === '' || str_starts_with($path, '/') || preg_match('#^(?:https?:)?//#i', $path) === 1) {
                    return $path;
          }
          if (str_starts_with($path, 'uploads/template_packs/') || str_starts_with($path, 'uploads/template-packs/')) {
                    return '/' . ltrim($path, '/');
          }
          if (str_starts_with($path, 'uploads/matches/')) {
                    return '/' . ltrim($path, '/');
          }
          return '/' . ltrim($path, '/');
}

/**
 * @param array<string, mixed> $brand
 */
function matchTemplatePackColour(array $brand, string $key, string $fallback): string
{
          $colours = isset($brand['colours']) && is_array($brand['colours']) ? $brand['colours'] : [];
          $candidate = (string)($colours[$key] ?? $brand[$key . '_colour'] ?? $brand[$key . '_color'] ?? '');
          return preg_match('/^#[0-9a-f]{6}$/i', $candidate) === 1 ? $candidate : $fallback;
}

/**
 * @param array<string, mixed> $brand
 */
function matchTemplatePackFont(array $brand, string $key, string $fallback): string
{
          $fonts = isset($brand['fonts']) && is_array($brand['fonts']) ? $brand['fonts'] : [];
          $aliases = [
                    'heading' => 'primary',
                    'body' => 'secondary',
                    'numbers' => 'numbers',
          ];
          $candidate = trim((string)($brand[$key . '_font'] ?? $fonts[$aliases[$key] ?? $key] ?? ''));
          if ($candidate === '' || preg_match('/^[a-z0-9 ._-]{1,80}$/i', $candidate) !== 1) {
                    return $fallback;
          }
          return $candidate;
}

/**
 * Build safe @font-face declarations for fonts uploaded through Template Packs.
 *
 * @param array<string, mixed> $brand
 */
function matchTemplatePackFontFaceCss(array $brand): string
{
          $css = [];
          $fontDefinitions = [];
          foreach (['heading', 'body', 'numbers'] as $role) {
                    $family = trim((string)($brand[$role . '_font_family'] ?? ''));
                    if ($family === '') {
                              $family = matchTemplatePackFont($brand, $role, '');
                    }
                    $fontDefinitions[] = [
                              'family' => $family,
                              'path' => (string)($brand[$role . '_font_path'] ?? ''),
                    ];
          }
          foreach ((array)($brand['font_library'] ?? []) as $font) {
                    if (!is_array($font)) {
                              continue;
                    }
                    $fontDefinitions[] = [
                              'family' => (string)($font['family'] ?? ''),
                              'path' => (string)($font['path'] ?? ''),
                    ];
          }
          $seen = [];
          foreach ($fontDefinitions as $fontDefinition) {
                    $family = trim((string)$fontDefinition['family']);
                    if (preg_match('/^[a-z0-9 ._-]{1,80}$/i', $family) !== 1) {
                              continue;
                    }
                    $path = matchTemplatePackAssetUrl((string)$fontDefinition['path']);
                    if (
                              $family === ''
                              || $path === ''
                              || str_contains($path, '..')
                              || preg_match('#^/uploads/template_packs/[a-z0-9_./-]+$#i', $path) !== 1
                    ) {
                              continue;
                    }
                    if (isset($seen[$family . '|' . $path])) {
                              continue;
                    }
                    $seen[$family . '|' . $path] = true;
                    $format = match (strtolower((string)pathinfo($path, PATHINFO_EXTENSION))) {
                              'woff2' => 'woff2',
                              'woff' => 'woff',
                              'ttf' => 'truetype',
                              'otf' => 'opentype',
                              default => '',
                    };
                    if ($format === '') {
                              continue;
                    }
                    $css[] = '@font-face{font-family:"' . $family . '";src:url("' . $path . '") format("' . $format . '");font-display:swap;}';
          }

          return implode("\n", $css);
}

/**
 * @param array<string, mixed> $brand
 */
function matchTemplatePackOption(array $brand, string $group, string $key, $fallback = null)
{
          $groupValues = isset($brand[$group]) && is_array($brand[$group]) ? $brand[$group] : [];
          if (array_key_exists($key, $groupValues)) {
                    return $groupValues[$key];
          }

          $flatAliases = [
                    'badges.variant' => 'badge_style',
                    'badges.white' => 'white_badges',
                    'sponsors.variant' => 'sponsor_style',
                    'sponsors.white' => 'white_sponsors',
                    'sponsors.show_name' => 'show_sponsor_names',
          ];
          $flatKey = $flatAliases[$group . '.' . $key] ?? $key;
          return array_key_exists($flatKey, $brand) ? $brand[$flatKey] : $fallback;
}

/**
 * Preview and share-graphic generation should always reflect a pack's newest
 * design (draft if one is being edited, otherwise the latest published
 * version) instead of the version a fixture's assignment is frozen to. That
 * freeze exists to protect graphics already posted from changing later, but
 * it means edits made after a fixture was assigned never show up when
 * previewing or regenerating that fixture's graphics unless we override it
 * here.
 *
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function matchTemplatePackPreferLatest(PDO $pdo, array $context, string $actionKey): array
{
          $packId = (int) ($context['pack']['id'] ?? 0);
          if ($packId <= 0 || $actionKey === '') {
                    return $context;
          }

          try {
                    $latest = template_packs_get_draft($pdo, $packId);
                    if ($latest === null) {
                              $publishedVersionId = (int) ($context['pack']['current_published_version_id'] ?? 0);
                              $latest = $publishedVersionId > 0 ? template_packs_get_version($pdo, $publishedVersionId) : null;
                    }
          } catch (Throwable $error) {
                    error_log('Template pack latest-version preview resolution failed: ' . $error->getMessage());
                    return $context;
          }
          if ($latest === null) {
                    return $context;
          }

          $latestAction = isset($latest['actions'][$actionKey]) && is_array($latest['actions'][$actionKey])
                    ? $latest['actions'][$actionKey]
                    : null;
          if ($latestAction === null) {
                    return $context;
          }

          $context['version'] = $latest;
          $context['brand_settings'] = isset($latest['brand_settings']) && is_array($latest['brand_settings'])
                    ? $latest['brand_settings']
                    : ($context['brand_settings'] ?? []);
          $context['action'] = $latestAction;

          return $context;
}

/**
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function matchTemplatePackLayout(array $context): array
{
          $action = isset($context['action']) && is_array($context['action']) ? $context['action'] : [];
          return isset($action['layout_settings']) && is_array($action['layout_settings'])
                    ? $action['layout_settings']
                    : [];
}

/**
 * Turn a validated editor element into safe inline positioning rules.
 *
 * @param array<string, mixed>|null $element
 */
function matchTemplatePackElementStyle(?array $element): string
{
          if (!$element) {
                    return '';
          }
          if (array_key_exists('visible', $element) && !$element['visible']) {
                    return 'display:none;';
          }

          $rules = ['position:absolute', 'margin:0'];
          foreach (['x' => 'left', 'y' => 'top', 'width' => 'width', 'height' => 'height'] as $key => $property) {
                    if (isset($element[$key]) && is_numeric($element[$key])) {
                              $rules[] = $property . ':' . max(0, (int)$element[$key]) . 'px';
                    }
          }
          if (isset($element['font_size']) && is_numeric($element['font_size'])) {
                    $rules[] = 'font-size:' . max(1, (int)$element['font_size']) . 'px';
          }
          if (in_array((string)($element['text_align'] ?? ''), ['left', 'center', 'right'], true)) {
                    $rules[] = 'text-align:' . $element['text_align'];
          }
          if (preg_match('/^#[0-9a-f]{6}$/i', (string)($element['color'] ?? '')) === 1) {
                    $rules[] = 'color:' . $element['color'];
          }
          $fontFamily = trim((string)($element['font_family'] ?? ''));
          if ($fontFamily !== '' && preg_match('/^[a-z0-9 ._-]{1,100}$/i', $fontFamily) === 1) {
                    $rules[] = 'font-family:"' . $fontFamily . '",Arial,sans-serif';
          }
          $fontWeight = (int)($element['font_weight'] ?? 0);
          if ($fontWeight >= 100 && $fontWeight <= 900) {
                    $rules[] = 'font-weight:' . $fontWeight;
          }
          if (isset($element['letter_spacing']) && is_numeric($element['letter_spacing'])) {
                    $rules[] = 'letter-spacing:' . max(-20, min(100, (float)$element['letter_spacing'])) . 'px';
          }
          if (isset($element['line_height']) && is_numeric($element['line_height'])) {
                    $rules[] = 'line-height:' . max(.5, min(3, (float)$element['line_height']));
          }
          if (in_array((string)($element['text_transform'] ?? ''), ['none', 'uppercase', 'lowercase', 'capitalize'], true)) {
                    $rules[] = 'text-transform:' . $element['text_transform'];
          }

          return implode(';', $rules) . ';';
}
