/**
 * Shared form field sync: display name from givenName + sn, and email -> account attribute.
 * Call initFormSync(options) on DOMContentLoaded with field IDs.
 * @param {Object} options
 * @param {string} [options.givenNameId] - ID of first name field
 * @param {string} [options.snId] - ID of last name field
 * @param {string} [options.cnId] - ID of display name (cn) field
 * @param {string} [options.emailId] - ID of email field
 * @param {string} [options.accountAttributeId] - ID of account/username field to sync from email
 */
function initFormSync(options) {
    if (!options) return;
    var givenNameEl = options.givenNameId ? document.getElementById(options.givenNameId) : null;
    var snEl = options.snId ? document.getElementById(options.snId) : null;
    var cnEl = options.cnId ? document.getElementById(options.cnId) : null;
    var emailEl = options.emailId ? document.getElementById(options.emailId) : null;
    var accountEl = options.accountAttributeId ? document.getElementById(options.accountAttributeId) : null;

    var cnDirty = false;

    function updateDisplayName() {
        if (!cnEl) return;
        if (cnDirty) return;
        var given = (givenNameEl && givenNameEl.value) ? givenNameEl.value.trim() : '';
        var sn = (snEl && snEl.value) ? snEl.value.trim() : '';
        var combined = (given + ' ' + sn).trim();
        cnEl.value = combined;
    }

    if (givenNameEl && snEl && cnEl) {
        givenNameEl.addEventListener('input', updateDisplayName);
        snEl.addEventListener('input', updateDisplayName);

        cnEl.addEventListener('input', function (e) {
            if (e && e.isTrusted) {
                cnDirty = cnEl.value.trim() !== '';
            }
        });

        cnEl.addEventListener('change', function (e) {
            if (e && e.isTrusted) {
                cnDirty = cnEl.value.trim() !== '';
            }
        });
    }

    if (emailEl && accountEl) {
        function syncEmailToAccount() {
            accountEl.value = emailEl.value.trim();
        }
        emailEl.addEventListener('input', syncEmailToAccount);
        emailEl.addEventListener('change', syncEmailToAccount);
    }
}

/**
 * Backward-compatible helper for legacy inline handlers.
 * Only fills cn when it is empty, so user edits are preserved.
 */
function updateCommonName() {
    var givenNameEl = document.getElementById('givenName');
    var snEl = document.getElementById('sn');
    var cnEl = document.getElementById('cn');
    if (!cnEl) return;
    if (cnEl.value && cnEl.value.trim() !== '') return;
    var given = (givenNameEl && givenNameEl.value) ? givenNameEl.value.trim() : '';
    var sn = (snEl && snEl.value) ? snEl.value.trim() : '';
    cnEl.value = (given + ' ' + sn).trim();
}
