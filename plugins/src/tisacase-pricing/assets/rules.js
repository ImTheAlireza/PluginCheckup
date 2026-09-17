/**
 * TisaCase Pricing — تب قوانین داینامیک: جستجوی محصول/دسته و افزودن ردیف قانون.
 */
(function ($) {
    'use strict';

    const cfg = window.TCP_RULES || {};
    const debounceTimers = {};

    function escapeText(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function renderResults($box, items, type) {
        $box.empty();

        if (!items || !items.length) {
            $box.append($('<div class="tcp-search-empty">').text('موردی پیدا نشد.')).show();
            return;
        }

        items.forEach(function (item) {
            let meta = '#' + item.id;
            if (type === 'product') {
                if (item.sku) meta += ' · SKU: ' + item.sku;
                if (item.type) meta += ' · ' + item.type;
            } else if (typeof item.count !== 'undefined') {
                meta += ' · ' + item.count + ' محصول';
            }

            const $row = $('<button type="button" class="tcp-search-item">')
                .attr('data-id', item.id)
                .attr('data-name', item.name)
                .attr('data-type', type);

            $row.append($('<strong>').text(item.name));
            $row.append($('<small>').text(meta));
            $box.append($row);
        });

        $box.show();
    }

    function search($input, $results, action, type) {
        const term = ($input.val() || '').trim();
        const minChars = parseInt(cfg.minChars || 2, 10);

        if (term.length < minChars) {
            $results.empty().hide();
            return;
        }

        $results.html('<div class="tcp-search-loading">در حال جستجو...</div>').show();

        $.post(cfg.ajaxUrl, {
            action: action,
            nonce: cfg.nonce,
            term: term
        }).done(function (response) {
            if (!response || !response.success) {
                $results.html('<div class="tcp-search-empty">خطا در جستجو.</div>').show();
                return;
            }
            renderResults($results, response.data || [], type);
        }).fail(function () {
            $results.html('<div class="tcp-search-empty">ارتباط با سرور برقرار نشد.</div>').show();
        });
    }

    function bindSearch(inputSelector, resultSelector, action, type) {
        const $input = $(inputSelector);
        const $results = $(resultSelector);

        $input.on('input', function () {
            clearTimeout(debounceTimers[type]);
            debounceTimers[type] = setTimeout(function () {
                search($input, $results, action, type);
            }, 300);
        });

        $input.on('focus', function () {
            if (($input.val() || '').trim().length >= parseInt(cfg.minChars || 2, 10)) {
                search($input, $results, action, type);
            }
        });
    }

    function addRule(type, id, name) {
        const group = type === 'product' ? 'products' : 'categories';
        const target = type === 'product' ? '#tcp-product-rules' : '#tcp-category-rules';
        const $target = $(target);

        if ($target.find('tr[data-rule-id="' + id + '"]').length) {
            $target.find('tr[data-rule-id="' + id + '"]').addClass('tcp-flash');
            setTimeout(function () {
                $target.find('tr[data-rule-id="' + id + '"]').removeClass('tcp-flash');
            }, 900);
            return;
        }

        const safeName = escapeText(name);
        const n = group + '[' + id + ']';
        const modes = cfg.modes || { none: 'بدون رند', round: 'رند به ۸', jitter: 'تخفیف متغیر (رند به ۸)' };
        let modeOpts = '';
        Object.keys(modes).forEach(function (k) {
            modeOpts += '<option value="' + k + '"' + (k === 'round' ? ' selected' : '') + '>' + escapeText(modes[k]) + '</option>';
        });
        const toggle = function (field, label, cls, checked) {
            return '<input type="hidden" name="' + n + '[' + field + ']" value="0">' +
                '<label class="tisa-switch tcp-toggle tcp-toggle--sm"><input type="checkbox" class="' + cls + '" name="' + n + '[' + field + ']" value="1"' + (checked ? ' checked' : '') + '>' +
                '<span class="tisa-switch__track" aria-hidden="true"></span><span>' + label + '</span></label>';
        };
        const row = [
            '<tr data-rule-id="', id, '">',
            '<td class="tcp-rule-name"><strong>', safeName, '</strong><small class="tisa-code">#', id, '</small>',
            '<input type="hidden" name="', n, '[exists]" value="1"></td>',
            '<td class="tcp-rule-num"><input class="tisa-input tisa-input--number" type="number" min="0" max="500" step="0.1" name="', n, '[increase]" value="10"></td>',
            '<td class="tcp-rule-num"><input class="tisa-input tisa-input--number" type="number" min="0" max="99.9" step="0.1" name="', n, '[sale]" value="10"></td>',
            '<td><select class="tisa-input tisa-input--sm tcp-mode" name="', n, '[mode]">', modeOpts, '</select></td>',
            '<td class="tcp-rule-dates"><input type="date" class="tisa-input tisa-input--sm" name="', n, '[from]" title="از تاریخ">',
            '<input type="date" class="tisa-input tisa-input--sm" name="', n, '[to]" title="تا تاریخ"></td>',
            '<td class="tcp-rule-limits"><input type="number" class="tisa-input tisa-input--sm" min="0" step="1000" name="', n, '[min]" placeholder="کف">',
            '<input type="number" class="tisa-input tisa-input--sm" min="0" step="1000" name="', n, '[max]" placeholder="سقف"></td>',
            '<td class="tcp-rule-flags">', toggle('enabled', 'فعال', '', true), toggle('exclude', 'استثنا', 'tcp-exclude', false), '</td>',
            '<td><button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm tcp-remove-rule">حذف</button></td>',
            '</tr>'
        ].join('');

        $target.append(row);
    }

    bindSearch('#tcp-product-search', '#tcp-product-results', cfg.productAct, 'product');
    bindSearch('#tcp-category-search', '#tcp-category-results', cfg.catAct, 'category');

    $(document).on('click', '.tcp-search-item', function () {
        const $item = $(this);
        addRule($item.data('type'), parseInt($item.data('id'), 10), $item.data('name'));
        $item.closest('.tcp-search-results').empty().hide();
    });

    $(document).on('change', '.tcp-exclude', function () {
        const $tr = $(this).closest('tr');
        $tr.toggleClass('is-excluded', this.checked);
    });

    $(document).on('click', '.tcp-remove-rule', function () {
        $(this).closest('tr').remove();
    });

    $(document).on('click', function (event) {
        if (!$(event.target).closest('.tcp-search-box').length) {
            $('.tcp-search-results').hide();
        }
    });
})(jQuery);
