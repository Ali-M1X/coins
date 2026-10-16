<?php
/**
 * The price converter block (2.10.0) — on a coin page, and at /coins/convert/.
 *
 * A plain GET form to /coins/convert/: without script it submits and the
 * server answers with the same figures. With script (assets/v2/converter.js)
 * it answers as you type, in the browser, from the rates in its data island;
 * live ticks replace this coin's price where the 2.4.0 feed carries it.
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
$units = ['usd' => 'دلار آمریکا (USD)'] + ($conv['toman'] ? ['toman' => 'تومان'] : []);
$option = static function (string $value, string $label, string $selected): string {
    return sprintf('<option value="%s"%s>%s</option>', esc_attr($value), $value === $selected ? ' selected' : '', esc_html($label));
};
$select = static function (string $id, string $name, string $selected) use ($conv, $units, $option): string {
    $html = sprintf('<select class="v2-conv__select" id="%s" name="%s">', esc_attr($id), esc_attr($name));
    $html .= '<optgroup label="پول رایج">';
    foreach ($units as $k => $label) { $html .= $option($k, $label, $selected); }
    $html .= '</optgroup><optgroup label="ارزهای دیجیتال">';
    foreach ($conv['coins'] as $p) { $html .= $option($p['id'], $p['name'] . ' (' . $p['symbol'] . ')', $selected); }
    return $html . '</optgroup></select>';
};
$title = $standalone ? 'مبدل قیمت ارز دیجیتال' : 'تبدیل ' . $b['name'] . ' به دلار و تومان';
$sub = $standalone ? 'هر ارز دیجیتال را به دلار، تومان یا ارز دیگر تبدیل کنید' : 'با آخرین قیمت ' . $b['name'] . ($conv['live']['usd'] ? ' و قیمت زنده در همین صفحه' : '');
?>
<section class="v2-section v2-conv" id="convert" aria-labelledby="convert-title" data-v2-conv>
  <?= View::heading('convert', $title, $sub) ?>
  <div class="v2-card v2-conv__card">
    <form class="v2-conv__form" action="<?= esc_url(\TheHybit\Coins\Tools::url('convert')) ?>" method="get" data-v2-conv-form>
      <div class="v2-conv__field v2-conv__field--amount">
        <label for="conv-amount">مقدار</label>
        <input class="v2-conv__input" id="conv-amount" name="amount" type="text" inputmode="decimal" autocomplete="off" spellcheck="false"
               value="<?= esc_attr($amountText) ?>" maxlength="24" dir="ltr" aria-describedby="conv-amount-hint">
        <p class="v2-conv__hint" id="conv-amount-hint">رقم فارسی یا انگلیسی؛ مثلاً ۰٫۵ یا 2,500</p>
      </div>
      <div class="v2-conv__field">
        <label for="conv-from">از</label>
        <?= $select('conv-from', 'from', $from) ?>
      </div>
      <button class="v2-conv__swap" type="button" data-v2-conv-swap aria-label="جابه‌جا کردن «از» و «به»" title="جابه‌جا کردن">⇄</button>
      <div class="v2-conv__field">
        <label for="conv-to">به</label>
        <?= $select('conv-to', 'to', $to) ?>
      </div>
      <noscript><button class="v2-conv__go" type="submit">تبدیل</button></noscript>
      <output class="v2-conv__out" for="conv-amount conv-from conv-to" aria-live="polite" data-v2-conv-out><?= $result !== null ? esc_html($result) : ($amount === null ? 'یک عدد وارد کنید.' : '—') ?></output>
      <p class="v2-conv__meta" data-v2-conv-meta>
        قیمت‌ها از آخرین به‌روزرسانی (<?= esc_html($conv['updated']) ?> به وقت تهران)<?= $conv['toman'] ? '؛ نرخ دلار ' . esc_html(Converter::fa($conv['toman']['rate'], 'toman')) . ' تومان (' . esc_html($conv['toman']['source']) . '، ' . esc_html((string) $conv['tomanUpdated']) . ')' : '' ?>.
        <?php if (!$conv['toman']) : ?><span class="v2-conv__nofx">نرخ تومان فعلاً در دسترس نیست؛ تبدیل به تومان نمایش داده نمی‌شود.</span><?php endif; ?>
      </p>
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
      <?php if (!empty($conv['text'])) : ?>
        <p class="v2-conv__text"><?= esc_html($conv['text']) ?></p>
      <?php endif; ?>
      <?php if (!$standalone) : ?>
        <a class="v2-link" href="<?= esc_url(\TheHybit\Coins\Tools::url('convert')) ?>">مبدل همه ارزها ←</a>
      <?php endif; ?>
    </div>
  </div>
</section>
