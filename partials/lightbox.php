<?php
/**
 * Shared image lightbox. Requires a [data-lightbox] grid of
 * <a data-full="…" [data-caption="…"]> items elsewhere on the page.
 * Enhanced by public.js — degrades to plain links (open image in a tab).
 */
declare(strict_types=1);
?>
<div class="lightbox" id="lightbox" hidden role="dialog" aria-modal="true" aria-label="Image viewer">
  <button class="lightbox__close" type="button" aria-label="Close">&times;</button>
  <button class="lightbox__nav lightbox__nav--prev" type="button" aria-label="Previous image">&#8249;</button>
  <div class="lightbox__stage">
    <img class="lightbox__img" src="" alt="">
    <p class="lightbox__meta"><span class="lightbox__count"></span><span class="lightbox__caption" hidden></span></p>
  </div>
  <button class="lightbox__nav lightbox__nav--next" type="button" aria-label="Next image">&#8250;</button>
  <div class="lightbox__thumbs"></div>
</div>
