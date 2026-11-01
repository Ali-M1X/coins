/**
 * TheHybit Coins — the blocks in the WordPress editor (2.16.0).
 *
 * No build step: every block is defined from window.__THB_BLOCKS__ (written by
 * includes/Blocks.php), previewed with ServerSideRender — the same PHP that
 * renders the page, reading the same cache — and saved as attributes only
 * (dynamic blocks: save() returns null).
 */
(function (wp) {
  'use strict';
  if (!wp || !wp.blocks || !window.__THB_BLOCKS__) return;
  var el = wp.element.createElement;
  var C = wp.components;
  var Inspector = (wp.blockEditor || wp.editor).InspectorControls;
  var SSR = wp.serverSideRender;
  var data = window.__THB_BLOCKS__;
  var LABELS = { coin: 'ارز', live: 'به‌روزرسانی زنده', current: 'ستون فعلی', pillar: 'ستون', count: 'تعداد', post: 'شناسه مقاله (اختیاری)',
    title: 'عنوان (اختیاری)', text: 'متن', period: 'بازه' };
  var opts = function (list, empty) { return [{ value: '', label: empty }].concat(list); };

  function control(name, schema, value, set) {
    var onChange = function (v) { var o = {}; o[name] = v; set(o); };
    if (name === 'coin') return el(C.SelectControl, { key: name, label: LABELS.coin, value: value, options: opts(data.coins, 'ارز همین صفحه'), onChange: onChange });
    if (name === 'pillar' || name === 'current') return el(C.SelectControl, { key: name, label: LABELS[name], value: value, options: opts(data.pillars, name === 'pillar' ? 'همه ستون‌ها' : 'خودکار'), onChange: onChange });
    if (name === 'period') return el(C.SelectControl, { key: name, label: LABELS.period, value: value,
      options: [{ value: '24h', label: '۲۴ ساعت' }, { value: '7d', label: '۷ روز' }, { value: '30d', label: '۳۰ روز' }, { value: '90d', label: '۹۰ روز' }, { value: '1y', label: 'یک سال' }], onChange: onChange });
    if (schema.type === 'boolean') return el(C.ToggleControl, { key: name, label: LABELS[name] || name, checked: !!value, onChange: onChange });
    if (schema.type === 'number') return el(C.TextControl, { key: name, type: 'number', label: LABELS[name] || name, value: value, onChange: function (v) { onChange(parseInt(v, 10) || 0); } });
    if (name === 'text') return el(C.TextareaControl, { key: name, label: 'هر خط: پرسش | پاسخ', value: value, rows: 6, onChange: onChange });
    return el(C.TextControl, { key: name, label: LABELS[name] || name, value: value, onChange: onChange });
  }

  data.blocks.forEach(function (b) {
    wp.blocks.registerBlockType(b.name, {
      apiVersion: 2,
      title: b.title,
      category: 'widgets',
      icon: 'chart-line',
      attributes: b.attributes,
      supports: { html: false },
      edit: function (props) {
        var fields = Object.keys(b.attributes).map(function (k) { return control(k, b.attributes[k], props.attributes[k], props.setAttributes); });
        var blockProps = wp.blockEditor && wp.blockEditor.useBlockProps ? wp.blockEditor.useBlockProps() : {};
        return el('div', blockProps,
          fields.length ? el(Inspector, null, el(C.PanelBody, { title: b.title, initialOpen: true }, fields)) : null,
          el(SSR, { block: b.name, attributes: props.attributes,
            EmptyResponsePlaceholder: function () { return el('p', { style: { padding: '12px', border: '1px dashed #999' } }, b.title + ' — داده واقعی برای نمایش در حافظه نیست؛ در صفحه نمایش داده نمی‌شود.'); } })
        );
      },
      save: function () { return null; }
    });
  });
})(window.wp);
