<?php
/**
 * The price converter block (2.10.0) — on a coin page, and at /coins/convert/.
 *
 * A plain GET form to /coins/convert/: without script it submits and the
 * server answers with the same figures. With script (assets/v2/converter.js)
 * it answers as you type, in the browser, from the rates in its data island;
 * live ticks replace this coin's price where the 2.4.0 feed carries it.
 *
 * 2.13.0: no explanatory text — controls, result and the small data table
 * only. A currency we hold no rate for is not an option at all.
 *
 * Expects $conv (V2\Converter::forCoin()), $standalone (bool) and $state
 * (from, to, amount — the request's, or the defaults).
 */
use TheHybit\Coins\V2\Converter;
use TheHybit\Coins\V2\View;
defined('ABSPATH') || exit;

$b = $conv['base'];
$from = $state['from'];
$to = $state['to'];
$amountText = $state['amount'];
$amount = Converter::parse($amountText);
$result = $amount !== null ? Converter::sentence($conv, $amount, $from, $to) : null;
$fiat = array_intersect_key(Converter::FIAT_LABELS, $conv['fiat']);
$option = static function (string $value, string $label, string $selected, array $data = []): string {
    $attrs = '';
    foreach ($data as $k => $v) {
        if ($v !== null && $v !== '') { $attrs .= sprintf(' data-%s="%s"', $k, esc_attr($v)); }
    }
    return sprintf('<option value="%s"%s%s>%s</option>', esc_attr($value), $value === $selected ? ' selected' : '', $attrs, esc_html($label));
};
/* 2.13.0: the native select stays — it is what submits without script and
   what assistive technology reads until converter.js replaces it with the
   searchable list (same groups, same values, same box size). */
$select = static function (string $id, string $name, string $selected, string $label) use ($conv, $fiat, $option): string {
    $html = sprintf('<div class="v2-pick" data-v2-pick><select class="v2-conv__select" id="%s" name="%s">', esc_attr($id), esc_attr($name));
    $html .= '<optgroup label="ارز دیجیتال">';
    foreach ($conv['coins'] as $p) { $html .= $option($p['id'], $p['name'] . ' (' . $p['symbol'] . ')', $selected, ['sym' => $p['symbol'], 'name' => $p['name'], 'logo' => $p['logo'] ?? null]); }
    $html .= '</optgroup><optgroup label="ارز رایج">';
    foreach ($fiat as $k => [$fa, $code, $glyph]) { $html .= $option($k, $fa . ' (' . $code . ')', $selected, ['sym' => $code, 'name' => $fa, 'glyph' => $glyph]); }
    $html .= '</optgroup></select>';
    $html .= sprintf('<button class="v2-pick__btn" type="button" hidden aria-haspopup="listbox" aria-expanded="false" aria-label="%s" data-v2-pick-btn></button>', esc_attr($label));
    return $html . '</div>';
};
$fiatNames = array_map(static fn($k) => Converter::FIAT_NAMES[$k], array_keys(array_diff_key($conv['fiat'], ['usd' => 1])));
$title = $standalone ? 'مبدل قیمت ارز دیجیتال' : 'تبدیل ' . $b['name'] . ' به ' . implode('، ', array_merge(isset($conv['fiat']['toman']) ? ['تومان'] : [], ['دلار'], array_diff($fiatNames, ['تومان'])));
?>
<section class="v2-section v2-conv" id="convert" aria-labelledby="convert-title" data-v2-conv>
  <?= View::heading('convert', $title) ?>
  <div class="v2-card v2-conv__card">
    <form class="v2-conv__form" action="<?= esc_url(\TheHybit\Coins\Tools::url('convert')) ?>" method="get" data-v2-conv-form>
      <div class="v2-conv__field v2-conv__field--amount">
        <label for="conv-amount">مقدار</label>
        <input class="v2-conv__input" id="conv-amount" name="amount" type="text" inputmode="decimal" autocomplete="off" spellcheck="false"
               value="<?= esc_attr($amountText) ?>" maxlength="24" dir="ltr">
      </div>
      <div class="v2-conv__field">
        <label for="conv-from">از</label>
        <?= $select('conv-from', 'from', $from, 'از') ?>
      </div>
      <button class="v2-conv__swap" type="button" data-v2-conv-swap aria-label="جابه‌جا کردن «از» و «به»" title="جابه‌جا کردن">⇄</button>
      <div class="v2-conv__field">
        <label for="conv-to">به</label>
        <?= $select('conv-to', 'to', $to, 'به') ?>
      </div>
      <noscript><button class="v2-conv__go" type="submit">تبدیل</button></noscript>
      <output class="v2-conv__out" for="conv-amount conv-from conv-to" aria-live="polite" data-v2-conv-out><?= $result !== null ? esc_html($result) : ($amount === null ? 'یک عدد وارد کنید.' : '—') ?></output>
    </form>

    <div class="v2-conv__side">
      <table class="v2-conv__table">
        <caption><?= esc_html($b['name']) ?> به دلار<?= $conv['toman'] ? ' و تومان' : '' ?></caption>
        <thead><tr><th scope="col"><?= esc_html($b['symbol']) ?></th><th scope="col">دلار</th><?php if ($conv['toman']) : ?><th scope="col">تومان</th><?php endif; ?></tr></thead>
        <tbody>
          <?php foreach ($conv['examples'] as $ex) : ?>
            <tr><th scope="row"><?= esc_html($ex['amount']) ?></th><td><?= esc_html($ex['usd']) ?></td><?php if ($conv['toman']) : ?><td><?= esc_html((string) $ex['toman']) ?></td><?php endif; ?></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (!$standalone) : ?>
        <a class="v2-link" href="<?= esc_url(\TheHybit\Coins\Tools::url('convert')) ?>">مبدل همه ارزها ←</a>
      <?php endif; ?>
    </div>
  </div>
</section>
