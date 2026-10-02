<?php
/**
 * A dense, self-sizing grid of small figures.
 *
 * WHY THIS EXISTS RATHER THAN ANOTHER METRIC CARD
 *
 * metric-card.php renders three large tiles. That is right for a section whose
 * job is to show three headline numbers, and wrong for the Group A sections,
 * which have six to ten small ones each. Rendering ten values as ten large
 * tiles is exactly how the DEX and DeFi cards ended up half empty: a card sized
 * for its container rather than its content.
 *
 * So this grid is sized by its content. `auto-fill` with a minimum column width
 * means four figures make one tidy row and ten make three, at whatever width the
 * viewport allows, with no fixed row count to leave holes in. Adding a metric
 * later changes the layout by itself.
 *
 * AVAILABILITY IS HANDLED HERE, ONCE.
 *
 * Every Group A section can be in one of five states, and the difference
 * matters to a reader: data that is simply not fetched yet is not the same
 * claim as a provider having nothing, and neither is a zero. Putting the
 * decision in one place is what stops three partials each inventing their own
 * answer — and stops any of them printing 0 for a value nobody has measured.
 *
 * Expects:
 *   $statTitle   string
 *   $statSource  string
 *   $statItems   array<int, array{label:string, value:string, meta?:string,
 *                                 change?:float, plain?:bool}>
 *   $statState   'ok'|'stale'|'pending'|'unavailable'|'disabled'
 *   $statId      string  element id for aria-labelledby
 *   $statSpan    int     desktop column span out of 12 (default 12)
 *   $statNote    string  optional footnote under the grid
 */

use TheHybit\Coins\Format;

defined('ABSPATH') || exit;

$statSpan = $statSpan ?? 12;
$statState = $statState ?? 'ok';
$statItems = $statItems ?? [];

/* A section switched off in configuration is not a failure and must not
   advertise itself as one — it simply does not render. */
if ($statState === 'disabled') {
    return;
}
?>
<section class="thb-card thb-statcard thb-span-<?= (int) $statSpan ?>"
         aria-labelledby="<?= esc_attr($statId) ?>">
  <div class="thb-card__head">
    <h2 class="thb-card__title" id="<?= esc_attr($statId) ?>"><?= esc_html($statTitle) ?></h2>
    <?php if (!empty($statSource)) : ?>
      <span class="thb-source">منبع: <?= esc_html($statSource) ?></span>
    <?php endif; ?>
  </div>

  <div class="thb-card__body">
    <?php if ($statState === 'pending') : ?>
      <p class="thb-datastate thb-datastate--pending">
        در حال دریافت داده — این بخش پس از اولین به‌روزرسانی زمان‌بند نمایش داده می‌شود.
      </p>

    <?php elseif ($statState === 'unavailable' || $statItems === []) : ?>
      <p class="thb-datastate thb-datastate--unavailable">
        داده فعلاً در دسترس نیست.
      </p>

    <?php else : ?>
      <?php if ($statState === 'stale') : ?>
        <p class="thb-datastate thb-datastate--stale">
          داده‌های زیر از آخرین دریافت موفق هستند و ممکن است به‌روز نباشند.
        </p>
      <?php endif; ?>

      <div class="thb-statgrid">
        <?php foreach ($statItems as $item) : ?>
          <div class="thb-stat">
            <span class="thb-stat__label"><?= esc_html($item['label']) ?></span>
            <span class="thb-stat__value">
              <?php if (!empty($item['plain'])) : ?>
                <bdi><?= esc_html($item['value']) ?></bdi>
              <?php else : ?>
                <span class="thb-num"><?= esc_html($item['value']) ?></span>
              <?php endif; ?>
            </span>

            <?php if (isset($item['change'])) : ?>
              <span class="thb-stat__meta">
                <span class="thb-change" data-dir="<?= esc_attr(Format::direction($item['change'])) ?>">
                  <span class="thb-change__arrow" aria-hidden="true"><?= Format::arrow($item['change']) ?></span><span class="thb-num"><?= esc_html(Format::pct($item['change'])) ?></span>
                </span>
              </span>
            <?php elseif (!empty($item['meta'])) : ?>
              <span class="thb-stat__meta thb-muted"><?= esc_html($item['meta']) ?></span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if (!empty($statExtra)) { echo $statExtra; } ?>

      <?php if (!empty($statNote)) : ?>
        <small class="thb-statcard__note"><?= esc_html($statNote) ?></small>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
<?php
/* Cleared so the next include cannot inherit this one's values — the partials
   are plain requires sharing one scope, and a stale $statExtra would silently
   render in a section that never set one. */
$statExtra = null;
$statNote = null;
$statSpan = 12;
