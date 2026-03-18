#!/bin/bash

# JavaScript Minification Script for LDAP User Manager
# This script creates minified versions of JavaScript files

set -e

echo "🔧 JavaScript Minification Script"
echo "=================================="

# Function to minify a JavaScript file
minify_js() {
    local source_file="$1"
    local min_file="${source_file%.js}.min.js"
    
    if [[ ! -f "$source_file" ]]; then
        echo "❌ Source file not found: $source_file"
        return 1
    fi
    
    echo "📝 Minifying: $source_file -> $min_file"
    
    # Simple minification: remove comments, extra whitespace, and combine lines
    # This is a basic approach - for production, consider using proper minification tools
    
    # Remove single-line comments (// ...)
    # Remove multi-line comments (/* ... */)
    # Remove extra whitespace and newlines
    # Preserve function names and basic structure
    
    cat "$source_file" | \
        sed 's|//.*$||g' | \
        sed '/\/\*/,/\*\//d' | \
        sed 's/^[[:space:]]*//g' | \
        sed 's/[[:space:]]*$//g' | \
        sed '/^$/d' | \
        sed 's/[[:space:]]\+/ /g' | \
        sed 's/;[[:space:]]*}/}/g' | \
        sed 's/}[[:space:]]*else/}else/g' | \
        sed 's/}[[:space:]]*catch/}catch/g' | \
        sed 's/}[[:space:]]*finally/}finally/g' > "$min_file"
    
    # Get file sizes
    local source_size=$(wc -c < "$source_file")
    local min_size=$(wc -c < "$min_file")
    local reduction=$((100 - (min_size * 100 / source_size)))
    
    echo "✅ Minified: $source_file ($source_size bytes) -> $min_file ($min_size bytes) - ${reduction}% reduction"
}

# Check if we're in the right directory
if [[ ! -f "user_management.js" ]]; then
    echo "❌ Please run this script from the www/assets/js/ directory"
    exit 1
fi

# Files to minify (excluding already minified files)
files_to_minify=(
    "user_management.js"
    "generate_passphrase.js"
    "zxcvbn-bootstrap-strength-meter.js"
)

# Minify each file
for file in "${files_to_minify[@]}"; do
    if [[ -f "$file" ]]; then
        minify_js "$file"
    else
        echo "⚠️  Skipping: $file (not found)"
    fi
done

echo ""
echo "🎉 Minification complete!"
echo ""
echo "📋 Summary:"
echo "   - Original files preserved for development"
echo "   - Minified versions created for production"
echo "   - Web app automatically uses minified versions"
echo ""
echo "💡 For better minification, consider using:"
echo "   - UglifyJS: npm install -g uglify-js"
echo "   - YUI Compressor: https://github.com/yui/yuicompressor"
echo "   - Google Closure Compiler: https://developers.google.com/closure/compiler"
