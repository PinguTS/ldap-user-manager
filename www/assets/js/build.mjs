#!/usr/bin/env node
/**
 * Build minified JS bundles for LDAP User Manager.
 * Run from repo root: npm run build:js
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { minify } from 'uglify-js';

const __dirname = dirname(fileURLToPath(import.meta.url));

const RESERVED = [
    'initializePasswordStrength',
    'initFormSync',
    'initializeTableSearch',
    'showModal',
    'confirmAction',
    'initWebsiteFields',
    'initCountryPickers',
    'accessibleAutocomplete',
    'zxcvbn',
];

const UGLIFY_OPTS = {
    compress: { passes: 2, drop_console: true },
    mangle: { toplevel: true, reserved: RESERVED },
    output: { comments: false },
};

/** @type {Array<{ out: string, vendor?: string[], sources: string[] }>} */
const BUNDLES = [
    {
        out: 'org.min.js',
        vendor: ['accessible-autocomplete.min.js'],
        sources: ['country-picker.js', 'website-field.js'],
    },
    {
        out: 'password.min.js',
        vendor: ['zxcvbn.min.js'],
        sources: ['password_utils.js'],
    },
    {
        out: 'sync.min.js',
        sources: ['form-sync.js'],
    },
    {
        out: 'lists.min.js',
        sources: ['modals.js', 'table-search.js'],
    },
    {
        out: 'modals.min.js',
        sources: ['modals.js'],
    },
];

function readJs(name) {
    return readFileSync(join(__dirname, name), 'utf8');
}

/** Vendor files may end with //# sourceMappingURL=... — strip so bundles do not 404 in devtools. */
function readVendor(name) {
    return readJs(name).replace(/\/\/[#@]\s*sourceMappingURL=.*$/gm, '');
}

function minifySources(sources) {
    const code = sources.map((name) => readJs(name)).join('\n;\n');
    const result = minify(code, UGLIFY_OPTS);
    if (result.error) {
        throw result.error;
    }
    return result.code;
}

function buildBundle(bundle) {
    const parts = [];
    for (const name of bundle.vendor ?? []) {
        parts.push(readVendor(name));
    }
    if (bundle.sources.length > 0) {
        parts.push(minifySources(bundle.sources));
    }
    const outPath = join(__dirname, bundle.out);
    writeFileSync(outPath, parts.join('\n;\n'));
    const bytes = Buffer.byteLength(parts.join('\n;\n'), 'utf8');
    console.log(`  ${bundle.out} (${bytes} bytes)`);
}

console.log('Building JS bundles...');
for (const bundle of BUNDLES) {
    buildBundle(bundle);
}
console.log('Done.');
