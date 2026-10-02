<?php
/**
 * Screen 5 — DNA. Reference: 6.png.
 *
 * The radar plots the five components of the classic Scoring model with their
 * own normalised values, and the centre is Scoring's own result — so the
 * number here is the number on the classic page. The reference's six axes
 * (network health, liquidity, trust, narrative, usage, risk) are not what the
 * score is made of; drawing them would mean a second, unexplained score.
 */
use TheHybit\Coins\Format;
use TheHybit\Coins\V2\View;
defined('ABSPATH') || exit;

$d = $v['dna'];
$c = $v['coin'];
$sent = $d['sentiment'];
?>
<section class="v2-section v2-dna" id="dna" aria-labelledby="dna-title">
  <?= View::heading('dna', 'DNA ' . $c['name'] . ' به روایت ' . $v['brand'], 'پنج مؤلفه امتیاز تحلیلی و داده‌هایی که پشت آن است') ?>

  <div class="v2-dna__grid">
    <div class="v2-dna__left">
      <div class="v2-card v2-gauge">
        <h3 class="v2-card__title">حال‌وهوای بازار</h3>
        <?php if ($sent['value'] !== null) : ?>
          <svg viewBox="0 0 200 170" role="img" aria-label="شاخص ترس و طمع: <?= esc_attr((string) (int) $sent['value']) ?> از ۱۰۰، <?= esc_attr((string) $sent['label']) ?>">
            <path class="v2-gauge__track" d="<?= esc_attr($sent['arc']) ?>"/>
            <path class="v2-gauge__fill" d="<?= esc_attr($sent['fill']) ?>"/>
            <line class="v2-gauge__needle" x1="100" y1="100" x2="<?= $sent['needle'][0] ?>" y2="<?= $sent['needle'][1] ?>"/>
            <circle cx="100" cy="100" r="6" class="v2-gauge__hub"/>
            <text x="100" y="150" text-anchor="middle" class="v2-gauge__num"><?= esc_html((string) (int) $sent['value']) ?></text>
          </svg>
          <p class="v2-gauge__label"><?= esc_html((string) $sent['label']) ?>
            <?php if ($sent['weekAgo'] !== null) : ?><span class="v2-muted">· هفته قبل <?= View::n((string) (int) $sent['weekAgo']) ?></span><?php endif; ?></p>
          <p class="v2-source">شاخص ترس و طمع کل بازار · <bdi>Alternative.me</bdi></p>
        <?php else : ?>
          <?= View::na($sent['state']) ?>
        <?php endif; ?>
      </div>
      <?php foreach ($d['sparks'] as $sp) : ?>
        <div class="v2-card v2-sparkrow">
          <div><strong><?= esc_html($sp['span']) ?></strong><span><?= esc_html($sp['label']) ?></span></div>
          <?php if ($sp['d'] !== '') : ?>
            <svg viewBox="0 0 180 44" preserveAspectRatio="none" aria-hidden="true"><path class="v2-spark__line v2-s--<?= esc_attr($sp['tone']) ?>" d="<?= esc_attr($sp['d']) ?>"/></svg>
          <?php else : ?>
            <?= View::na('unavailable') ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="v2-card v2-radar">
      <?php if ($d['score'] !== null && $d['polygon'] !== '') : ?>
        <svg viewBox="-40 -10 480 420" role="img" aria-label="رادار امتیاز: <?= esc_attr((string) $d['score']) ?> از ۱۰۰؛ <?= esc_attr(implode('، ', array_map(static fn($a) => $a['label'] . ' ' . ($a['value'] ?? 'بدون داده'), $d['axes']))) ?>">
          <defs>
            <linearGradient id="v2-radar-fill" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0" stop-color="var(--v2-accent)" stop-opacity=".45"/>
              <stop offset="1" stop-color="var(--v2-violet)" stop-opacity=".25"/>
            </linearGradient>
          </defs>
          <?php foreach ($d['grid'] as $ring) : ?>
            <polygon class="v2-radar__grid" points="<?= esc_attr($ring) ?>"/>
          <?php endforeach; ?>
          <?php foreach ($d['axes'] as $a) : ?>
            <line class="v2-radar__axis" x1="200" y1="200" x2="<?= $a['lx'] ?>" y2="<?= $a['ly'] ?>"/>
          <?php endforeach; ?>
          <polygon class="v2-radar__shape" points="<?= esc_attr($d['polygon']) ?>" fill="url(#v2-radar-fill)"/>
          <?php foreach ($d['axes'] as $a) : ?>
            <?php if ($a['value'] !== null) : ?>
              <circle class="v2-radar__pt" cx="<?= $a['vx'] ?>" cy="<?= $a['vy'] ?>" r="5"/>
              <text class="v2-radar__val" x="<?= $a['tx'] ?>" y="<?= $a['ty'] ?>" text-anchor="middle"><?= esc_html((string) $a['value']) ?></text>
            <?php endif; ?>
            <text class="v2-radar__lbl v2-fa<?= $a['value'] === null ? ' is-missing' : '' ?>" x="<?= $a['lx'] ?>" y="<?= $a['ly'] + ($a['ly'] > 200 ? 18 : -6) ?>" text-anchor="middle"><?= esc_html($a['label']) ?></text>
            <?php if ($a['value'] === null) : ?>
              <text class="v2-radar__nodata v2-fa" x="<?= $a['lx'] ?>" y="<?= $a['ly'] + ($a['ly'] > 200 ? 36 : 12) ?>" text-anchor="middle">بدون داده</text>
            <?php endif; ?>
          <?php endforeach; ?>
          <circle cx="200" cy="200" r="46" class="v2-radar__disc"/>
          <text x="200" y="208" text-anchor="middle" class="v2-radar__score"><?= esc_html((string) $d['score']) ?></text>
          <text x="200" y="228" text-anchor="middle" class="v2-radar__of v2-fa">از ۱۰۰ · <?= esc_html((string) $d['band']) ?></text>
        </svg>
        <p class="v2-muted v2-center"><?= View::n($d['coverage'][0] . '/' . $d['coverage'][1]) ?> مؤلفه داده دارند؛ مؤلفه بدون داده سهمی در امتیاز ندارد.</p>
      <?php else : ?>
        <?= View::na('pending', 'امتیاز پس از رسیدن داده‌های بازار و دیفای محاسبه می‌شود') ?>
      <?php endif; ?>
    </div>

    <div class="v2-dna__right">
      <?php foreach ($d['cards'] as $i => $card) : ?>
        <div class="v2-card v2-stat v2-stat--<?= ['violet', 'teal', 'blue', 'pink'][$i % 4] ?>">
          <h3 class="v2-stat__title"><?= esc_html($card['title']) ?></h3>
          <?php if ($card['value'] !== null) : ?>
            <p class="v2-stat__value"><?= View::n($card['value']) ?></p>
            <?php if (!empty($card['sub'])) : ?><p class="v2-stat__sub"><?= esc_html($card['sub']) ?></p><?php endif; ?>
          <?php else : ?>
            <?= View::na('unavailable', $card['note'] ?? null) ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <p class="v2-note">این امتیاز ترکیب وزن‌دار پنج نسبت بالاست و همان امتیازی است که در نسخه کلاسیک صفحه نمایش داده می‌شود. توصیه سرمایه‌گذاری نیست.</p>
</section>
