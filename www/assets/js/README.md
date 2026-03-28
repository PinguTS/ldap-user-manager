# JavaScript Files

This directory contains JavaScript files for the LDAP User Manager web application.

## File Structure

### Original Source Files (Development)
- `user_management.js` - Consolidated user management utilities
- `table-search.js` - Shared client-side filter for user tables (`initializeTableSearch`)
- `generate_passphrase.js` - Password generation using word lists
- `zxcvbn-bootstrap-strength-meter.js` - Password strength meter plugin
- `wordlist.js` - Dictionary of words for password generation

### Minified Files (Production)
- `user_management.min.js` - Minified version of user management utilities
- `generate_passphrase.min.js` - Minified version of password generation
- `zxcvbn-bootstrap-strength-meter.min.js` - Minified version of strength meter
- `jquery-3.6.0.min.js` - jQuery library (already minified)
- `zxcvbn.min.js` - Password strength library (already minified)

## Minification Process

### Manual Minification
To create minified versions of JavaScript files:

1. **Remove unnecessary whitespace and comments**
2. **Combine multiple lines into single lines where possible**
3. **Preserve function names and functionality**
4. **Test the minified version to ensure it works correctly**

### Automated Minification (Recommended)
For production environments, consider using automated tools:

```bash
# Using Node.js and UglifyJS
npm install -g uglify-js
uglifyjs user_management.js -o user_management.min.js

# Using YUI Compressor
java -jar yuicompressor.jar user_management.js -o user_management.min.js

# Using Google Closure Compiler
java -jar closure-compiler.jar --js user_management.js --js_output_file user_management.min.js
```

## Usage in Web App

The web application automatically uses minified versions:

```html
<!-- Development (original files) -->
<script src="/assets/js/user_management.js"></script>

<!-- Production (minified files) -->
<script src="/assets/js/user_management.min.js"></script>
```

## File Sizes

| File | Original | Minified | Reduction |
|------|----------|----------|-----------|
| `user_management.js` | 9.0KB | 4.8KB | 47% |
| `generate_passphrase.js` | 1.7KB | 669B | 61% |
| `zxcvbn-bootstrap-strength-meter.js` | 2.8KB | 1.0KB | 64% |

## Best Practices

1. **Always keep original source files** for development and debugging
2. **Use minified versions in production** for better performance
3. **Test minified versions thoroughly** before deployment
4. **Update minified files** whenever source files change
5. **Consider source maps** for debugging minified code in production

## Maintenance

When updating JavaScript functionality:

1. **Edit the original source file** (e.g., `user_management.js`)
2. **Create a new minified version** using your preferred minification tool
3. **Test both versions** to ensure functionality is preserved
4. **Update the web app** to use the new minified version
5. **Commit both files** to version control

## Notes

- The `jquery-3.6.0.min.js` and `zxcvbn.min.js` files are third-party libraries that are already minified
- The `wordlist.js` file contains a large array and is already quite compact
- All custom JavaScript functions are now consolidated in `user_management.js` and `user_management.min.js`
