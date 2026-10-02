<?php
/** §5 About. Historical dates use the Gregorian calendar, as approved. */
use TheHybit\Coins\Format;
defined('ABSPATH') || exit;

$about = $model['about'];
$social = $model['coin']['links']['social'] ?? [];
$contract = $model['coin']['contracts'][0] ?? null;

$icons = [
  'twitter'  => ['اتریوم در ایکس', 'M17.5 3h3.2l-7 8 8.2 10h-6l-4.7-5.8L6.9 21H3.7l7.5-8.6L3.3 3h6.2l4.2 5.3z'],
  'github'   => ['گیت‌هاب', 'M12 2a10 10 0 0 0-3.2 19.5c.5.1.7-.2.7-.5v-1.7c-2.8.6-3.4-1.3-3.4-1.3-.5-1.2-1.1-1.5-1.1-1.5-.9-.6.1-.6.1-.6 1 .1 1.5 1 1.5 1 .9 1.5 2.3 1.1 2.9.8.1-.6.3-1.1.6-1.3-2.2-.3-4.6-1.1-4.6-5 0-1.1.4-2 1-2.7-.1-.3-.4-1.3.1-2.7 0 0 .8-.3 2.7 1a9.4 9.4 0 0 1 5 0c1.9-1.3 2.7-1 2.7-1 .5 1.4.2 2.4.1 2.7.6.7 1 1.6 1 2.7 0 3.9-2.4 4.7-4.6 5 .4.3.7.9.7 1.9v2.8c0 .3.2.6.7.5A10 10 0 0 0 12 2z'],
  'discord'  => ['دیسکورد', 'M19.3 5.3A16 16 0 0 0 15.4 4l-.2.4a12 12 0 0 1 3.5 1.8 13.6 13.6 0 0 0-11.4 0 12 12 0 0 1 3.5-1.8L10.6 4a16 16 0 0 0-3.9 1.3C3.9 9.4 3.1 13.4 3.5 17.4a16 16 0 0 0 4.9 2.5l.9-1.5a10 10 0 0 1-1.6-.8l.4-.3a11.4 11.4 0 0 0 9.8 0l.4.3a10 10 0 0 1-1.6.8l.9 1.5a16 16 0 0 0 4.9-2.5c.5-4.6-.8-8.6-3.2-12.1zM9.4 15.1c-1 0-1.7-.9-1.7-2s.8-2 1.7-2 1.8.9 1.7 2c0 1.1-.8 2-1.7 2zm5.2 0c-1 0-1.7-.9-1.7-2s.8-2 1.7-2 1.8.9 1.7 2c0 1.1-.7 2-1.7 2z'],
  'telegram' => ['تلگرام', 'M21.9 4.3 2.9 11.6c-1 .4-1 1.8.1 2.1l4.7 1.4 1.8 5.4c.3.8 1.3 1 1.9.4l2.6-2.5 4.7 3.4c.7.5 1.7.1 1.9-.7l3-15c.2-.9-.7-1.6-1.6-1.3z'],
];
?>
<section class="thb-card thb-about" aria-labelledby="thb-about-title">
  <div class="thb-card__head"><h2 class="thb-card__title" id="thb-about-title">درباره <?= esc_html($coin->name) ?></h2></div>

  <div class="thb-card__body">
    <div class="thb-about__grid">
      <p class="thb-about__text"><?= esc_html(wp_strip_all_tags((string) ($about['description'] ?? ''))) ?></p>

      <div class="thb-about__rows">
        <?php
        $rows = array_filter([
          ['تاریخ راه‌اندازی', $about['launchDate'] ? Format::gregorianFa($about['launchDate']) : null],
          ['بنیان‌گذار', $about['founder'] ?? null],
          ['مکانیزم اجماع', $about['consensus'] ?? null],
        ], static fn($r) => !empty($r[1]));

        foreach ($rows as [$label, $value]) : ?>
          <div class="thb-about__row">
            <span class="thb-about__label"><?= esc_html($label) ?></span>
            <span class="thb-about__value"><?= esc_html($value) ?></span>
          </div>
        <?php endforeach; ?>

        <?php if (!empty($about['website'])) : ?>
          <div class="thb-about__row">
            <span class="thb-about__label">وب‌سایت</span>
            <span class="thb-about__value">
              <a class="thb-link" href="<?= esc_url($about['website']) ?>" target="_blank" rel="noopener noreferrer"><bdi><?= esc_html(preg_replace('#^https?://(www\.)?|/$#', '', $about['website'])) ?></bdi></a>
            </span>
          </div>
        <?php endif; ?>

        <?php if ($social) : ?>
          <div class="thb-about__row">
            <span class="thb-about__label">شبکه‌های اجتماعی</span>
            <span class="thb-about__value">
              <span class="thb-socials">
                <?php foreach ($social as $network => $url) :
                  if (empty($icons[$network])) { continue; }
                  [$label, $path] = $icons[$network]; ?>
                  <a class="thb-social" href="<?= esc_url($url) ?>" target="_blank" rel="noopener noreferrer"
                     aria-label="<?= esc_attr($coin->name . ' در ' . $label) ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="<?= esc_attr($path) ?>"/></svg>
                  </a>
                <?php endforeach; ?>
              </span>
            </span>
          </div>
        <?php endif; ?>

        <?php if ($contract && !empty($contract['address'])) : ?>
          <div class="thb-about__row">
            <span class="thb-about__label">قرارداد</span>
            <span class="thb-about__value">
              <span class="thb-contract">
                <button class="thb-icon-btn" type="button"
                        data-thb-copy="<?= esc_attr($contract['address']) ?>" aria-label="کپی نشانی قرارداد">
                  <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h8"/>
                  </svg>
                </button>
                <bdi class="thb-contract__addr"><?= esc_html(Format::shortAddress($contract['address'])) ?></bdi>
              </span>
              <?php if (!empty($contract['networkFa']) || !empty($contract['network'])) : ?>
                <small class="thb-contract__net"><?= esc_html($contract['networkFa'] ?: $contract['network']) ?></small>
              <?php endif; ?>
            </span>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
