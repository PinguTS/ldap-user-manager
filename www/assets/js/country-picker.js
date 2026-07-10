/**
 * Enhance org country <select> with accessible type-ahead search.
 */
function initCountryPickers() {
    if (typeof accessibleAutocomplete === 'undefined' || !accessibleAutocomplete.enhanceSelectElement) {
        return;
    }

    document.querySelectorAll('[data-country-picker]').forEach(function (select) {
        if (select.tagName !== 'SELECT' || select.dataset.countryPickerInit === '1') {
            return;
        }

        var placeholder = select.getAttribute('data-placeholder') || 'Type to search a country';
        var noResults = select.getAttribute('data-no-results') || 'No matching country';
        var assistiveHint = select.getAttribute('data-assistive-hint') ||
            'When autocomplete results are available use up and down arrows to review and enter to select. Touch device users, explore by touch or with swipe gestures.';

        accessibleAutocomplete.enhanceSelectElement({
            selectElement: select,
            showAllValues: true,
            preserveNullOptions: true,
            confirmOnBlur: true,
            defaultValue: '',
            placeholder: placeholder,
            tNoResults: function () {
                return noResults;
            },
            tAssistiveHint: function () {
                return assistiveHint;
            }
        });

        select.dataset.countryPickerInit = '1';
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCountryPickers);
} else {
    initCountryPickers();
}
