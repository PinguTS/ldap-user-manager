# JavaScript assets

Client-side scripts for LDAP User Manager. Pages load minified bundles; edit source files and rebuild.

## Source files

| File | Purpose |
|------|---------|
| `country-picker.js` | Country select type-ahead (`initCountryPickers`) |
| `website-field.js` | Organization website URL widget (`initWebsiteFields`) |
| `password_utils.js` | Password strength, validation, generation (`initializePasswordStrength`) |
| `form-sync.js` | Display name and account field sync (`initFormSync`) |
| `modals.js` | Bootstrap modal helpers (`showModal`, `confirmAction`) |
| `table-search.js` | Client-side table filter (`initializeTableSearch`) |

## Vendor inputs (build-only)

Prepended verbatim into bundles; not loaded directly by PHP templates.

| File | Used in |
|------|---------|
| `accessible-autocomplete.min.js` | `org.min.js` |
| `zxcvbn.min.js` | `password.min.js` |

## Build outputs

| File | Contents | Loaded by |
|------|----------|-----------|
| `org.min.js` | autocomplete + country-picker + website-field | org add, org show |
| `password.min.js` | zxcvbn + password_utils | all password forms |
| `sync.min.js` | form-sync | user create/edit, org user add/index |
| `lists.min.js` | modals + table-search | user list, org user list |
| `modals.min.js` | modals | org index, org show |

jQuery (`jquery-3.6.0.min.js`) is loaded globally via `renderHeader()`.

## Build

From the repository root:

```bash
npm install
npm run build:js
```

Requires Node.js. If npm is unavailable locally, use Docker:

```bash
docker run --rm -v "$PWD:/app" -w /app node:22-alpine sh -c "npm install && npm run build:js"
```

Configuration: [`build.mjs`](build.mjs). UglifyJS mangle reserves globals called from inline PHP: `initializePasswordStrength`, `initFormSync`, `initializeTableSearch`, `showModal`, `confirmAction`, `initWebsiteFields`, `initCountryPickers`, `accessibleAutocomplete`, `zxcvbn`.

## Maintenance

1. Edit the relevant source `*.js` file.
2. Run `npm run build:js`.
3. Commit source changes and regenerated `*.min.js` bundles.

## File sizes (approx.)

| Output | Size |
|--------|------|
| `org.min.js` | ~37 KB |
| `password.min.js` | ~705 KB |
| `sync.min.js` | ~1 KB |
| `lists.min.js` | ~1 KB |
| `modals.min.js` | ~0.4 KB |
