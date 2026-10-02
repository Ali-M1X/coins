<?php
/** §7 On-chain — RAW Dune/Alchemy figures only. */
use TheHybit\Coins\Format;
defined('ABSPATH') || exit;

$o = $model['onchain'];
$cardTitle = 'فعالیت آنچین';
$cardSource = $o['source'] ?? 'Dune / Alchemy';
$cardLabelledBy = 'thb-onchain-title';
$cardWide = false;
$cardTiles = array_values(array_filter([
    isset($o['activeAddresses24h']) ? ['label' => 'آدرس‌های فعال (24h)', 'value' => Format::num((float) $o['activeAddresses24h'], 0), 'change' => $o['activeAddressesChange24h'] ?? null] : null,
    isset($o['transactions24h']) ? ['label' => 'تراکنش‌ها (24h)', 'value' => Format::compact((float) $o['transactions24h']), 'change' => $o['transactionsChange24h'] ?? null] : null,
    isset($o['newAddresses24h']) ? ['label' => 'آدرس‌های جدید (24h)', 'value' => Format::num((float) $o['newAddresses24h'], 0), 'change' => $o['newAddressesChange24h'] ?? null] : null,
]));
$cardSpark = !empty($o['sparkline']) ? 'onchain' : null;
$cardSparkCaption = 'روند آدرس‌های فعال در 30 روز گذشته';
$cardExtra = null;

require __DIR__ . '/metric-card.php';
