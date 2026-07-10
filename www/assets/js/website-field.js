/**
 * Website URL input group: scheme prefix + host/path, live preview, submit normalization.
 */
function initWebsiteFields() {
    document.querySelectorAll('[data-website-field]').forEach(function (root) {
        var hidden = root.querySelector('input[type="hidden"]');
        var scheme = root.querySelector('.website-url-scheme');
        var host = root.querySelector('.website-url-host');
        var preview = root.querySelector('.website-url-preview');
        if (!hidden || !scheme || !host) {
            return;
        }

        var invalidMsg = root.getAttribute('data-invalid-msg') || 'Please enter a valid website address.';
        var previewTemplate = root.getAttribute('data-preview-template') || 'Will be saved as :url';

        function stripScheme(value) {
            var trimmed = (value || '').trim();
            var match = trimmed.match(/^(https?):\/\/(.*)$/i);
            if (match) {
                scheme.value = match[1].toLowerCase();
                return match[2];
            }
            return trimmed;
        }

        function combineUrl() {
            var hostPart = stripScheme(host.value);
            if (hostPart === '') {
                return '';
            }
            return scheme.value + '://' + hostPart.replace(/^\/+/, '');
        }

        function updatePreview() {
            if (!preview) {
                return;
            }
            var combined = combineUrl();
            if (combined === '') {
                preview.textContent = '';
                return;
            }
            preview.textContent = previewTemplate.replace(':url', combined);
        }

        function validateHost() {
            var combined = combineUrl();
            if (host.hasAttribute('required') && combined === '') {
                host.setCustomValidity(invalidMsg);
                return false;
            }
            if (combined === '') {
                host.setCustomValidity('');
                hidden.value = '';
                return true;
            }
            try {
                var parsed = new URL(combined);
                if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
                    host.setCustomValidity(invalidMsg);
                    return false;
                }
                if (!parsed.hostname) {
                    host.setCustomValidity(invalidMsg);
                    return false;
                }
            } catch (e) {
                host.setCustomValidity(invalidMsg);
                return false;
            }
            host.setCustomValidity('');
            hidden.value = combined;
            host.value = stripScheme(host.value);
            return true;
        }

        function syncHidden() {
            validateHost();
            updatePreview();
        }

        scheme.addEventListener('change', syncHidden);
        host.addEventListener('input', function () {
            host.setCustomValidity('');
            updatePreview();
        });
        host.addEventListener('blur', syncHidden);

        var form = root.closest('form');
        if (form) {
            form.addEventListener('submit', function (event) {
                if (!validateHost()) {
                    event.preventDefault();
                    host.reportValidity();
                }
            });
        }

        syncHidden();
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initWebsiteFields);
} else {
    initWebsiteFields();
}
