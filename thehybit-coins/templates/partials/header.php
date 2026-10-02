<?php
/** §1 Coin header + §2 Market summary. Markup identical to the approved page. */
use TheHybit\Coins\Format;
defined('ABSPATH') || exit;

$m = $model['market'];
$dir = Format::direction($m['change24h'] ?? null);
$rate = $model['currency']['usdToToman'] ?? null;
$circPct = (!empty($m['circulatingSupply']) && !empty($m['totalSupply']))
    ? ($m['circulatingSupply'] / $m['totalSupply']) * 100 : null;
?>
<section class="thb-card" aria-labelledby="thb-coin-title">
  <div class="thb-header__main">

    <div class="thb-identity">
      <?php if (!empty($model['coin']['logo'])) : ?>
        <img class="thb-identity__logo" src="<?= esc_url($model['coin']['logo']) ?>"
             alt="<?= esc_attr('لوگوی ' . $coin->name) ?>" width="56" height="56">
      <?php endif; ?>
      <div>
        <div class="thb-identity__names">
          <h1 class="thb-identity__name" id="thb-coin-title"><?= esc_html($coin->name) ?></h1>
          <span class="thb-identity__symbol"><bdi><?= esc_html($coin->symbol) ?></bdi></span>
        </div>
        <div class="thb-identity__meta">
          <?php if (!empty($model['coin']['rank'])) : ?>
            <span class="thb-badge thb-badge--brand">رتبه <span class="thb-num">#<?= esc_html((string) $model['coin']['rank']) ?></span></span>
          <?php endif; ?>
          <?php foreach ($model['coin']['tags'] as $tag) : ?>
            <span class="thb-badge thb-badge--outline"><?= esc_html($tag) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="thb-price">
      <div class="thb-price__figures">
        <div class="thb-price__value"><span class="thb-num"><?= esc_html(Format::usd($m['price'] ?? null)) ?></span></div>

        <div class="thb-price__row">
          <span class="thb-change thb-change--pill" data-dir="<?= esc_attr($dir) ?>">
            <span class="thb-change__arrow" aria-hidden="true"><?= Format::arrow($m['change24h'] ?? null) ?></span>
            <span><?= esc_html(Format::pct($m['change24h'] ?? null)) ?></span>
          </span>
          <span class="thb-price__window">24 ساعت گذشته</span>
        </div>

        <?php if ($rate !== null) : ?>
          <div class="thb-price__toman">
            ≈ <span class="thb-num"><?= esc_html(Format::toman($m['price'] ?? null, $rate)) ?></span> تومان
          </div>
        <?php endif; ?>
      </div>

      <div class="thb-price__spark" data-thb-sparkline="header"></div>
    </div>
  </div>

  <div class="thb-actions">
    <?php if ($url = $model['coin']['links']['website'] ?? null) : ?>
      <a class="thb-btn" href="<?= esc_url($url) ?>" target="_blank" rel="noopener noreferrer">
        <svg class="thb-btn__icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18a15 15 0 0 1 0-18"/></svg>
        وب‌سایت
      </a>
    <?php endif; ?>
    <?php if ($url = $model['coin']['links']['whitepaper'] ?? null) : ?>
      <a class="thb-btn" href="<?= esc_url($url) ?>" target="_blank" rel="noopener noreferrer">
        <svg class="thb-btn__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/></svg>
        سفیدنامه
      </a>
    <?php endif; ?>
    <?php if ($url = $model['coin']['links']['explorer'] ?? null) : ?>
      <a class="thb-btn" href="<?= esc_url($url) ?>" target="_blank" rel="noopener noreferrer">
        <svg class="thb-btn__icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
        کاوشگر
      </a>
    <?php endif; ?>

    <button class="thb-btn thb-btn--watch" type="button" data-thb-watchlist aria-pressed="false">
      <svg class="thb-btn__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8L3.5 9.7l5.9-.9z"/></svg>
      <span data-thb-watchlist-label>افزودن به واچ‌لیست</span>
    </button>
  </div>

  <div class="thb-summary">
    <div class="thb-summary__item">
      <span class="thb-stat__label">ارزش بازار</span>
      <span class="thb-stat__value"><span class="thb-num"><?= esc_html(Format::usdCompact($m['marketCap'] ?? null)) ?></span></span>
      <span class="thb-stat__sub">
        <span class="thb-change" data-dir="<?= esc_attr(Format::direction($m['marketCapChange24h'] ?? null)) ?>">
          <span class="thb-change__arrow" aria-hidden="true"><?= Format::arrow($m['marketCapChange24h'] ?? null) ?></span><span><?= esc_html(Format::pct($m['marketCapChange24h'] ?? null)) ?></span>
        </span>
      </span>
    </div>

    <div class="thb-summary__item">
      <span class="thb-stat__label">ارزش کاملاً رقیق‌شده</span>
      <span class="thb-stat__value"><span class="thb-num"><?= esc_html(Format::usdCompact($m['fdv'] ?? null)) ?></span></span>
      <span class="thb-stat__sub thb-muted">بر پایه عرضه کل</span>
    </div>

    <div class="thb-summary__item">
      <span class="thb-stat__label">حجم معاملات 24 ساعته</span>
      <span class="thb-stat__value"><span class="thb-num"><?= esc_html(Format::usdCompact($m['volume24h'] ?? null)) ?></span></span>
      <span class="thb-stat__sub thb-muted">بر پایه داده CoinGecko</span>
    </div>

    <div class="thb-summary__item">
      <span class="thb-stat__label">عرضه در گردش</span>
      <span class="thb-stat__value"><span class="thb-num"><?= esc_html(Format::compact($m['circulatingSupply'] ?? null)) ?> <bdi><?= esc_html($coin->symbol) ?></bdi></span></span>
      <?php if ($circPct !== null) : ?>
        <span class="thb-stat__sub thb-muted"><span class="thb-num"><?= esc_html(Format::num($circPct, 2)) ?>%</span> از عرضه کل</span>
      <?php endif; ?>
    </div>

    <div class="thb-summary__item">
      <span class="thb-stat__label">حداکثر عرضه</span>
      <span class="thb-stat__value">
        <?php if (($m['maxSupply'] ?? null) === null) : ?>
          <span class="thb-num" aria-label="نامحدود">∞</span>
        <?php else : ?>
          <span class="thb-num"><?= esc_html(Format::compact($m['maxSupply'])) ?></span>
        <?php endif; ?>
      </span>
      <span class="thb-stat__sub thb-muted"><?= ($m['maxSupply'] ?? null) === null ? 'سقف مشخصی ندارد' : 'سقف عرضه' ?></span>
    </div>
  </div>
</section>
