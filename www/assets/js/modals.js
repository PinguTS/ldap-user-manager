/**
 * Shared modal helpers for manage pages (Bootstrap 5).
 * Use showModal(id) to open a modal by ID; use confirmAction(modalId, fieldValues)
 * to set form/display fields and then show the modal.
 */
(function () {
    'use strict';

    window.showModal = function (id) {
        var el = document.getElementById(id);
        if (el && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(el).show();
        }
    };

    /**
     * Set fields by element ID then show the modal.
     * @param {string} modalId - ID of the modal element
     * @param {Object.<string, string>} fieldValues - Map of element ID to value (sets .value for inputs, .textContent for others)
     */
    window.confirmAction = function (modalId, fieldValues) {
        if (fieldValues) {
            Object.keys(fieldValues).forEach(function (id) {
                var el = document.getElementById(id);
                if (el) {
                    if (el.tagName === 'INPUT' || el.tagName === 'SELECT' || el.tagName === 'TEXTAREA') {
                        el.value = fieldValues[id] || '';
                    } else {
                        el.textContent = fieldValues[id] || '';
                    }
                }
            });
        }
        window.showModal(modalId);
    };
})();
