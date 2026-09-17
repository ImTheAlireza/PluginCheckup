(function ($) {
    'use strict';

    const cfg = window.TisaCasePM || {};
    const debounceTimers = {};

    function escapeText(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function renderResults($box, items, type) {
        $box.empty();

        if (!items || !items.length) {
            $box.append($('<div class="tisa-search-empty">').text('موردی پیدا نشد.')).show();
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

            const $row = $('<button type="button" class="tisa-search-item">')
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

        $results.html('<div class="tisa-search-loading">در حال جستجو...</div>').show();

        $.post(cfg.ajaxUrl, {
            action: action,
            nonce: cfg.nonce,
            term: term
        }).done(function (response) {
            if (!response || !response.success) {
                $results.html('<div class="tisa-search-empty">خطا در جستجو.</div>').show();
                return;
            }
            renderResults($results, response.data || [], type);
        }).fail(function () {
            $results.html('<div class="tisa-search-empty">ارتباط با سرور برقرار نشد.</div>').show();
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
        const target = type === 'product' ? '#tisa-product-rules' : '#tisa-category-rules';
        const $target = $(target);

        if ($target.find('tr[data-rule-id="' + id + '"]').length) {
            $target.find('tr[data-rule-id="' + id + '"]').addClass('tisa-flash');
            setTimeout(function () {
                $target.find('tr[data-rule-id="' + id + '"]').removeClass('tisa-flash');
            }, 900);
            return;
        }

        const safeName = escapeText(name);
        const row = [
            '<tr data-rule-id="', id, '">',
            '<td class="tisa-rule-name"><strong>', safeName, '</strong><small class="tisa-code">#', id, '</small>',
            '<input type="hidden" name="', group, '[', id, '][exists]" value="1"></td>',
            '<td><input class="tisa-input tisa-input--number" type="number" min="0" max="500" step="0.1" name="', group, '[', id, '][increase]" value="10"></td>',
            '<td><input class="tisa-input tisa-input--number" type="number" min="0" max="99.9" step="0.1" name="', group, '[', id, '][sale]" value="10"></td>',
            '<td class="tisa-rule-enabled"><input type="hidden" name="', group, '[', id, '][enabled]" value="0">',
            '<label><input type="checkbox" name="', group, '[', id, '][enabled]" value="1" checked> فعال</label></td>',
            '<td><button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm tisa-remove-rule">حذف</button></td>',
            '</tr>'
        ].join('');

        $target.append(row);
    }

    bindSearch('#tisa-product-search', '#tisa-product-results', cfg.productAct, 'product');
    bindSearch('#tisa-category-search', '#tisa-category-results', cfg.catAct, 'category');

    $(document).on('click', '.tisa-search-item', function () {
        const $item = $(this);
        addRule($item.data('type'), parseInt($item.data('id'), 10), $item.data('name'));
        $item.closest('.tisa-search-results').empty().hide();
    });

    $(document).on('click', '.tisa-remove-rule', function () {
        $(this).closest('tr').remove();
    });

    $(document).on('click', function (event) {
        if (!$(event.target).closest('.tisa-search-box').length) {
            $('.tisa-search-results').hide();
        }
    });
})(jQuery);
