<?php
/**
 * Price alerts (2.12.0) — browser only.
 *
 * No account, no e-mail, nothing sent to this site: an alert is kept in the
 * visitor's own browser (localStorage) and checked by assets/v2/alerts.js
 * while one of our coin pages is open — against the prices the page already
 * holds (the cached price of every coin, from the converter's data) and this
 * coin's live ticks. A browser notification is shown only if the visitor
 * turned it on, with the browser's own permission prompt.
 *
 * Drawn hidden; the script reveals it. Without script there is nothing that
 * could check an alert, so nothing is offered.
 *
 * Expects $v (the page model) and $conv (the converter's data, for the price).
 */
use TheHybit\Coins\V2\Converter;
use TheHybit\Coins\V2\View;
defined('ABSPATH') || exit;

$c = $v['coin'];
?>
<section class="v2-section v2-alerts" id="alerts" aria-labelledby="alerts-title" data-v2-alerts hidden>
  <?= View::heading('alerts', 'هشدار قیمت ' . $c['name'], 'وقتی قیمت از عددی که تعیین می‌کنید بالاتر یا پایین‌تر رفت، خبرتان می‌کنیم') ?>
  <div class="v2-card v2-alerts__card">
    <form class="v2-alerts__form" data-v2-alerts-form novalidate>
      <fieldset class="v2-alerts__dir">
        <legend>وقتی قیمت <?= esc_html($c['name']) ?></legend>
        <label><input type="radio" name="dir" value="above" checked> بالاتر از</label>
        <label><input type="radio" name="dir" value="below"> پایین‌تر از</label>
      </fieldset>
      <div class="v2-conv__field">
        <label for="alert-price">این قیمت شد (دلار)</label>
        <input class="v2-conv__input" id="alert-price" name="price" type="text" inputmode="decimal" autocomplete="off" dir="ltr" maxlength="24"
               aria-describedby="alert-now alert-msg">
        <p class="v2-conv__hint" id="alert-now">قیمت فعلی: <bdi dir="ltr" data-v2-alerts-now><?= esc_html(Converter::fa((float) ($conv['base']['price'] ?? 0), 'usd')) ?></bdi> دلار</p>
      </div>
      <button class="v2-alerts__add" type="submit">ثبت هشدار</button>
      <p class="v2-alerts__msg" id="alert-msg" role="status" aria-live="polite" data-v2-alerts-msg></p>
    </form>
    <div class="v2-alerts__side">
      <h3 class="v2-card__title">هشدارهای شما</h3>
      <ul class="v2-alerts__list" data-v2-alerts-list></ul>
      <p class="v2-alerts__empty" data-v2-alerts-empty>هنوز هشداری ثبت نکرده‌اید.</p>
      <button class="v2-alerts__notify" type="button" data-v2-alerts-notify hidden>اعلان مرورگر را روشن کن</button>
      <p class="v2-alerts__privacy">هشدارها فقط در همین مرورگر ذخیره می‌شوند و چیزی به سرور ما فرستاده نمی‌شود. هشدار فقط وقتی بررسی می‌شود که یکی از صفحه‌های ارز ما باز باشد؛ اگر داده‌های این سایت را در مرورگر پاک کنید، هشدارها هم پاک می‌شوند.</p>
    </div>
  </div>
  <div class="v2-alerts__toast" role="alert" data-v2-alerts-toast hidden></div>
</section>
