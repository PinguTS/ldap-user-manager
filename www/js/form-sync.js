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

    function updateDisplayName() {
        if (!cnEl) return;
        var given = (givenNameEl && givenNameEl.value) ? givenNameEl.value.trim() : '';
        var sn = (snEl && snEl.value) ? snEl.value.trim() : '';
        if (given && sn) {
            cnEl.value = given + ' ' + sn;
        }
    }

    if (givenNameEl && snEl && cnEl) {
        givenNameEl.addEventListener('input', updateDisplayName);
        snEl.addEventListener('input', updateDisplayName);
    }

    if (emailEl && accountEl) {
        function syncEmailToAccount() {
            accountEl.value = emailEl.value.trim();
        }
        emailEl.addEventListener('input', syncEmailToAccount);
        emailEl.addEventListener('change', syncEmailToAccount);
    }
}
