/* Loonymoon Email Gate — frontend toggles. Vanilla JS, no deps. */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    function initForm(form) {
        var channelInput = form.querySelector('input[name="contact_type"]');
        var emailField   = form.querySelector('.lmeg-field-email');
        var phoneField   = form.querySelector('.lmeg-field-phone');
        var emailInput   = form.querySelector('input[name="email"]');
        var phoneInput   = form.querySelector('input[name="phone"]');
        var countrySel   = form.querySelector('select[name="phone_country"]');
        var dialBadge    = form.querySelector('.lmeg-dial');

        function setChannel(channel) {
            channelInput.value = channel;
            form.querySelectorAll('.lmeg-tab').forEach(function (t) {
                var active = t.dataset.channel === channel;
                t.classList.toggle('is-active', active);
                t.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            if (channel === 'phone') {
                emailField.hidden = true;
                phoneField.hidden = false;
                if (emailInput) emailInput.required = false;
                if (phoneInput) phoneInput.required = true;
            } else {
                emailField.hidden = false;
                phoneField.hidden = true;
                if (emailInput) emailInput.required = true;
                if (phoneInput) phoneInput.required = false;
            }
        }

        form.querySelectorAll('.lmeg-tab').forEach(function (t) {
            t.addEventListener('click', function (e) {
                e.preventDefault();
                setChannel(t.dataset.channel);
            });
        });

        if (countrySel && dialBadge) {
            var updateDial = function () {
                var opt = countrySel.options[countrySel.selectedIndex];
                dialBadge.textContent = opt ? '+' + opt.dataset.dial : '';
            };
            countrySel.addEventListener('change', updateDial);
            updateDial();
        }

        // Address fields collapsible
        var addrToggle = form.querySelector('.lmeg-address-toggle');
        var addrBlock  = form.querySelector('.lmeg-address-block');
        if (addrToggle && addrBlock) {
            addrToggle.addEventListener('click', function (e) {
                e.preventDefault();
                var open = !addrBlock.hidden;
                addrBlock.hidden = open;
                addrToggle.setAttribute('aria-expanded', open ? 'false' : 'true');
                addrToggle.classList.toggle('is-open', !open);
            });
        }

        // Sync hidden iso field with country dropdown
        if (countrySel) {
            var isoInput = form.querySelector('input[name="phone_country_iso"]');
            var syncIso = function () {
                var opt = countrySel.options[countrySel.selectedIndex];
                if (isoInput && opt) isoInput.value = opt.value;
            };
            countrySel.addEventListener('change', syncIso);
            syncIso();
        }
    }

    ready(function () {
        document.querySelectorAll('.lmeg-form').forEach(initForm);
    });
})();
