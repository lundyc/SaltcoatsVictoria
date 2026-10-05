<?php

declare(strict_types=1);

// Public product detail — /shop/p/<slug>  (see .htaccess). No login required.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/shop.php';
require_once __DIR__ . '/../lib/shop_basket.php';
require_once __DIR__ . '/_layout.php';

shop_ensure_schema($pdo);
$settings = shop_get_settings($pdo);

if (($settings['shop_enabled'] ?? '1') !== '1') {
    header('Location: /shop/');
    exit;
}

$slug = trim((string) ($_GET['slug'] ?? ''));
$product = $slug !== '' ? shop_get_product_by_slug($pdo, $slug) : null;
if (!$product && ctype_digit((string) ($_GET['id'] ?? ''))) {
    $product = shop_get_product($pdo, (int) $_GET['id']);
}

$isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

if (!$product || (int) $product['is_active'] !== 1) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'That product is no longer available.']);
        exit;
    }
    http_response_code(404);
    shop_layout_top(['title' => 'Not found', 'basket_count' => shop_basket_count()]);
    echo '<div class="shop-empty"><i class="fa-solid fa-magnifying-glass"></i><p>Sorry, we couldn\'t find that product.</p><p><a class="shop-btn shop-btn--ghost" href="/shop/">Back to the shop</a></p></div>';
    shop_layout_bottom();
    exit;
}

$groups = shop_product_groups($pdo, (int) $product['id'], true);
$gallery = shop_product_gallery($pdo, $product);
$close = shop_product_preorder_close($pdo, $product);
$closed = $close !== null && new DateTimeImmutable('now') > $close;
$orderable = shop_product_is_orderable($pdo, $product);

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $error = 'Your session expired. Please try again.';
    } elseif ((string) ($_POST['action'] ?? '') === 'add') {
        $selected = [];
        foreach ((array) ($_POST['options'] ?? []) as $groupId => $optionIds) {
            $selected[(int) $groupId] = is_array($optionIds) ? array_map('intval', $optionIds) : (int) $optionIds;
        }
        $quantity = max(1, (int) ($_POST['quantity'] ?? 1));
        try {
            shop_basket_add($pdo, (int) $product['id'], $selected, $quantity);
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'ok' => true,
                    'basket_count' => shop_basket_count(),
                    'message' => $product['name'] . ' added to your basket.',
                ]);
                exit;
            }
            header('Location: /shop/basket');
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => $error]);
                exit;
            }
        }
    }
}

$mainImg = $gallery[0]['path'] ?? '';

shop_layout_top([
    'title' => (string) $product['name'],
    'description' => (string) ($product['summary'] ?: $product['name']),
    'active' => '',
    'basket_count' => shop_basket_count(),
    'canonical' => stripe_public_base_url() . '/shop/p/' . rawurlencode((string) $product['slug']),
    'product_image' => $mainImg,
    'image_alt' => (string) $product['name'],
]);
?>
<nav class="shop-breadcrumb" aria-label="Breadcrumb">
    <a href="/shop/">Shop</a> ›
    <a href="/shop/?category=<?= (int) $product['category_id'] ?>"><?= h((string) $product['category_name']) ?></a> ›
    <span><?= h((string) $product['name']) ?></span>
</nav>

<div class="shop-pd">
    <div class="shop-pd__gallery">
        <div class="shop-pd__main <?= $mainImg === '' ? 'shop-pd__main--placeholder' : '' ?>" data-gallery-main
             <?= $mainImg !== '' ? 'style="background-image:url(\'' . h($mainImg) . '\')"' : '' ?>>
            <?php if ($mainImg === ''): ?><i class="fa-solid fa-shirt" aria-hidden="true"></i><?php endif; ?>
        </div>
        <?php if (count($gallery) > 1): ?>
            <div class="shop-pd__thumbs">
                <?php foreach ($gallery as $i => $shot): ?>
                    <button type="button" data-gallery-thumb data-src="<?= h($shot['path']) ?>"
                            class="<?= $i === 0 ? 'is-active' : '' ?>"
                            style="background-image:url('<?= h($shot['path']) ?>')"
                            aria-label="View image <?= $i + 1 ?>"></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="shop-pd__panel">
        <div class="shop-pd__cat"><?= h((string) $product['category_name']) ?></div>
        <h1><?= h((string) $product['name']) ?></h1>
        <div class="shop-pd__price"><?= h(gbp((float) $product['price'])) ?></div>
        <?php if (($product['summary'] ?? '') !== ''): ?>
            <p class="shop-pd__summary"><?= h((string) $product['summary']) ?></p>
        <?php endif; ?>

        <?php if ((int) $product['is_preorder'] === 1): ?>
            <div class="shop-alert shop-alert--warn" style="margin-top:.6rem;">
                <strong><?= $closed ? 'Pre-orders are now closed.' : 'This is a pre-order.' ?></strong>
                <?= h((string) ($product['preorder_message'] ?: $settings['preorder_intro'] ?? '')) ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?><div class="shop-alert shop-alert--error"><?= h($error) ?></div><?php endif; ?>

        <?php if ($closed): ?>
            <p class="shop-note">Pre-orders for this item closed on <?= h($close->format('j F Y')) ?>. It will be back once the next order is placed with VSN.</p>
            <a class="shop-btn shop-btn--ghost shop-btn--block" href="/shop/">Back to the shop</a>
        <?php elseif (!$orderable): ?>
            <p class="shop-note">This item is currently unavailable.</p>
            <a class="shop-btn shop-btn--ghost shop-btn--block" href="/shop/">Back to the shop</a>
        <?php else: ?>
            <form method="post" action="/shop/p/<?= h((string) $product['slug']) ?>" data-add-to-basket>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">

                <?php foreach ($groups as $group): ?>
                    <?php
                    $gid = (int) $group['id'];
                    $isMulti = (string) $group['selection_type'] === 'multi';
                    $inputName = 'options[' . $gid . ']' . ($isMulti ? '[]' : '');
                    $required = (int) $group['is_required'] === 1;
                    $sections = shop_group_sections($group);
                    $deltaLabel = static function (float $d): string {
                        if ($d > 0) return ' (+' . gbp($d) . ')';
                        if ($d < 0) return ' (' . gbp($d) . ')';
                        return '';
                    };
                    ?>
                    <fieldset class="shop-optgroup" data-optgroup>
                        <legend class="shop-optgroup__label">
                            <span><?= h((string) $group['name']) ?></span>
                            <?php if ($required): ?><span class="shop-optgroup__req">Required</span><?php endif; ?>
                        </legend>
                        <?php if (($group['help_text'] ?? '') !== ''): ?>
                            <p class="shop-optgroup__help"><?= h((string) $group['help_text']) ?></p>
                        <?php endif; ?>

                        <?php if ($sections): ?>
                            <?php /* Two-step: choose a section, then a filtered size dropdown. */ ?>
                            <div class="shop-fitrow" role="radiogroup" aria-label="<?= h((string) $group['name']) ?> — choose one" data-fit-row hidden>
                                <?php foreach ($sections as $section): ?>
                                    <label class="shop-chip">
                                        <input type="radio" name="_fit_<?= $gid ?>" value="<?= h($section) ?>" data-fit-radio>
                                        <span><?= h($section) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="shop-sizewrap" data-size-wrap>
                                <label class="shop-optgroup__sublabel" for="size_<?= $gid ?>">Choose your size</label>
                                <select id="size_<?= $gid ?>" class="shop-select" name="options[<?= $gid ?>]" data-size-select <?= $required ? 'required' : '' ?>>
                                    <option value="" disabled selected>Select&hellip;</option>
                                    <?php foreach ($sections as $section): ?>
                                        <optgroup label="<?= h($section) ?>" data-fit-group="<?= h($section) ?>">
                                            <?php foreach ($group['options'] as $opt): ?>
                                                <?php if (trim((string) ($opt['option_group'] ?? '')) !== $section) continue; ?>
                                                <?php $oos = $opt['stock_qty'] !== null && (int) $opt['stock_qty'] <= 0; $d = (float) $opt['price_delta']; ?>
                                                <option value="<?= (int) $opt['id'] ?>" data-fit="<?= h($section) ?>" <?= $oos ? 'data-soldout="1" disabled' : '' ?>>
                                                    <?= h((string) $opt['label']) ?><?= $oos ? ' — sold out' : $deltaLabel($d) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php else: ?>
                            <div class="shop-chips">
                                <?php foreach ($group['options'] as $opt): ?>
                                    <?php
                                    $outOfStock = $opt['stock_qty'] !== null && (int) $opt['stock_qty'] <= 0;
                                    $delta = (float) $opt['price_delta'];
                                    ?>
                                    <label class="shop-chip">
                                        <input type="<?= $isMulti ? 'checkbox' : 'radio' ?>"
                                               name="<?= h($inputName) ?>"
                                               value="<?= (int) $opt['id'] ?>"
                                               <?= $required && !$isMulti ? 'required' : '' ?>
                                               <?= $outOfStock ? 'disabled' : '' ?>>
                                        <span>
                                            <?php if (trim((string) ($opt['option_group'] ?? '')) !== ''): ?><?= h((string) $opt['option_group']) ?> <?php endif; ?><?= h((string) $opt['label']) ?>
                                            <?php if ($outOfStock): ?> — sold out<?php endif; ?>
                                            <?php if ($delta > 0): ?><span class="shop-chip__delta">+<?= h(gbp($delta)) ?></span><?php endif; ?>
                                            <?php if ($delta < 0): ?><span class="shop-chip__delta"><?= h(gbp($delta)) ?></span><?php endif; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </fieldset>
                <?php endforeach; ?>

                <div class="shop-addrow">
                    <div class="shop-stepper" data-stepper>
                        <button type="button" data-step="-1" aria-label="Decrease quantity">−</button>
                        <input type="number" name="quantity" value="1" min="1" max="<?= (int) $product['max_per_order'] ?>" inputmode="numeric" aria-label="Quantity">
                        <button type="button" data-step="1" aria-label="Increase quantity">+</button>
                    </div>
                    <button type="submit" class="shop-btn shop-btn--primary shop-btn--lg">
                        <i class="fa-solid fa-basket-shopping" aria-hidden="true"></i> Add to basket
                    </button>
                </div>
                <p class="shop-note">Maximum <?= (int) $product['max_per_order'] ?> per order. Collection only from
                    <?= h((string) ($settings['collection_point'] ?? 'Campbell Park')) ?> — no delivery.</p>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if (trim((string) ($product['description'] ?? '')) !== ''): ?>
    <div class="shop-pd__desc">
        <h2>Product details</h2>
        <?= h((string) $product['description']) ?>
    </div>
<?php endif; ?>

<?php shop_layout_bottom(); ?>
