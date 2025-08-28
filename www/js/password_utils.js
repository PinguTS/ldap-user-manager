/**
 * Unified Password Utilities for LDAP User Manager
 * 
 * This file provides consistent password strength checking, generation,
 * and validation across all forms in the application.
 * 
 * @package LDAP User Manager
 * @version 1.0
 */

/**
 * Password strength levels and their descriptions
 */
const PASSWORD_STRENGTH_LEVELS = {
    0: { name: 'Very Weak', class: 'danger', description: 'Easily cracked' },
    1: { name: 'Weak', class: 'danger', description: 'Can be cracked quickly' },
    2: { name: 'Fair', class: 'warning', description: 'Moderate security' },
    3: { name: 'Good', class: 'success', description: 'Good security' },
    4: { name: 'Strong', class: 'success', description: 'Excellent security' }
};

/**
 * Default configuration for password strength requirements
 */
const DEFAULT_PASSWORD_CONFIG = {
    minScore: 2,                    // Minimum strength score (0-4)
    minLength: 8,                   // Minimum password length
    requireUppercase: true,         // Require uppercase letters
    requireLowercase: true,         // Require lowercase letters
    requireNumbers: true,           // Require numbers
    requireSymbols: false,          // Require symbols
    showStrengthMeter: true,        // Show visual strength meter
    showScore: true,                // Show numerical score
    updateHiddenField: true,        // Update hidden pass_score field
    hiddenFieldId: 'pass_score'     // ID of hidden field to update
};

/**
 * Initialize password strength checking for a form
 * @param {Object} options - Configuration options
 * @param {string} options.passwordFieldId - ID of password input field
 * @param {string} options.confirmFieldId - ID of confirm password field (optional)
 * @param {string} options.strengthMeterId - ID of strength meter element (optional)
 * @param {string} options.scoreDisplayId - ID of score display element (optional)
 * @param {Object} options.config - Password strength configuration
 */
function initializePasswordStrength(options = {}) {
    const config = { ...DEFAULT_PASSWORD_CONFIG, ...options.config };
    const passwordField = document.getElementById(options.passwordFieldId);
    
    if (!passwordField) {
        console.warn('Password field not found:', options.passwordFieldId);
        return;
    }

    // Create strength meter if requested and not provided
    let strengthMeter = null;
    if (config.showStrengthMeter) {
        if (options.strengthMeterId) {
            strengthMeter = document.getElementById(options.strengthMeterId);
        } else {
            strengthMeter = createStrengthMeter(passwordField, options);
        }
    }

    // Create score display if requested and not provided
    let scoreDisplay = null;
    if (config.showScore) {
        if (options.scoreDisplayId) {
            scoreDisplay = document.getElementById(options.scoreDisplayId);
        } else {
            scoreDisplay = createScoreDisplay(passwordField, options);
        }
    }

    // Add event listeners
    passwordField.addEventListener('input', () => {
        updatePasswordStrength(passwordField, strengthMeter, scoreDisplay, config);
    });

    // Add confirm password validation if confirm field exists
    if (options.confirmFieldId) {
        const confirmField = document.getElementById(options.confirmFieldId);
        if (confirmField) {
            confirmField.addEventListener('input', () => {
                validatePasswordMatch(passwordField, confirmField);
            });
        }
    }

    // Initial strength check
    updatePasswordStrength(passwordField, strengthMeter, scoreDisplay, config);
}

/**
 * Create a strength meter element
 * @param {HTMLElement} passwordField - Password input field
 * @param {Object} options - Configuration options
 * @returns {HTMLElement} Created strength meter
 */
function createStrengthMeter(passwordField, options) {
    const container = document.createElement('div');
    container.className = 'password-strength-meter mt-2';
    
    const progressBar = document.createElement('div');
    progressBar.className = 'progress';
    progressBar.innerHTML = `
        <div class="progress-bar" role="progressbar" style="width: 0%;" 
             aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
            Enter a password to see strength
        </div>
    `;
    
    container.appendChild(progressBar);
    
    // Insert after password field
    passwordField.parentNode.insertBefore(container, passwordField.nextSibling);
    
    return progressBar.querySelector('.progress-bar');
}

/**
 * Create a score display element
 * @param {HTMLElement} passwordField - Password input field
 * @param {Object} options - Configuration options
 * @returns {HTMLElement} Created score display
 */
function createScoreDisplay(passwordField, options) {
    const container = document.createElement('div');
    container.className = 'password-score-display mt-1';
    container.innerHTML = `
        <small class="text-muted">
            Password strength: <span class="strength-text">Very Weak</span>
            <span class="strength-score">(0/4)</span>
        </small>
    `;
    
    // Insert after password field
    passwordField.parentNode.insertBefore(container, passwordField.nextSibling);
    
    return container;
}

/**
 * Update password strength display
 * @param {HTMLElement} passwordField - Password input field
 * @param {HTMLElement} strengthMeter - Strength meter element
 * @param {HTMLElement} scoreDisplay - Score display element
 * @param {Object} config - Configuration options
 */
function updatePasswordStrength(passwordField, strengthMeter, scoreDisplay, config) {
    const password = passwordField.value;
    
    if (!password) {
        resetStrengthDisplay(strengthMeter, scoreDisplay);
        updateHiddenField(0, config);
        return;
    }

    try {
        // Use zxcvbn if available, otherwise fall back to basic assessment
        let result;
        if (typeof zxcvbn === 'function') {
            result = zxcvbn(password);
        } else {
            result = assessPasswordStrengthBasic(password);
        }

        // Update strength meter
        if (strengthMeter) {
            updateStrengthMeter(strengthMeter, result.score);
        }

        // Update score display
        if (scoreDisplay) {
            updateScoreDisplay(scoreDisplay, result.score, result.feedback);
        }

        // Update hidden field
        if (config.updateHiddenField) {
            updateHiddenField(result.score, config);
        }

        // Validate against requirements
        validatePasswordRequirements(password, result.score, config);

    } catch (error) {
        console.error('Error assessing password strength:', error);
        resetStrengthDisplay(strengthMeter, scoreDisplay);
        updateHiddenField(0, config);
    }
}

/**
 * Basic password strength assessment (fallback when zxcvbn is not available)
 * @param {string} password - Password to assess
 * @returns {Object} Strength assessment result
 */
function assessPasswordStrengthBasic(password) {
    let score = 0;
    let feedback = '';

    // Length check
    if (password.length >= 8) score++;
    if (password.length >= 12) score++;
    
    // Character variety checks
    if (/[a-z]/.test(password)) score++;
    if (/[A-Z]/.test(password)) score++;
    if (/[0-9]/.test(password)) score++;
    if (/[^A-Za-z0-9]/.test(password)) score++;
    
    // Cap at 4
    score = Math.min(score, 4);
    
    // Determine feedback
    if (score === 0) feedback = 'Very Weak';
    else if (score === 1) feedback = 'Weak';
    else if (score === 2) feedback = 'Fair';
    else if (score === 3) feedback = 'Good';
    else feedback = 'Strong';

    return { score, feedback };
}

/**
 * Update strength meter visual display
 * @param {HTMLElement} strengthMeter - Strength meter element
 * @param {number} score - Password strength score (0-4)
 */
function updateStrengthMeter(strengthMeter, score) {
    const level = PASSWORD_STRENGTH_LEVELS[score];
    const percentage = ((score + 1) * 20);
    
    // Update progress bar
    strengthMeter.style.width = percentage + '%';
    strengthMeter.setAttribute('aria-valuenow', percentage);
    
    // Update classes
    strengthMeter.className = `progress-bar progress-bar-${level.class}`;
    
    // Update text
    strengthMeter.textContent = level.name;
}

/**
 * Update score display
 * @param {HTMLElement} scoreDisplay - Score display element
 * @param {number} score - Password strength score (0-4)
 * @param {string} feedback - User-friendly feedback
 */
function updateScoreDisplay(scoreDisplay, score, feedback) {
    const strengthText = scoreDisplay.querySelector('.strength-text');
    const strengthScore = scoreDisplay.querySelector('.strength-score');
    
    if (strengthText) {
        strengthText.textContent = feedback;
        strengthText.className = `strength-text text-${PASSWORD_STRENGTH_LEVELS[score].class}`;
    }
    
    if (strengthScore) {
        strengthScore.textContent = `(${score}/4)`;
    }
}

/**
 * Reset strength display to initial state
 * @param {HTMLElement} strengthMeter - Strength meter element
 * @param {HTMLElement} scoreDisplay - Score display element
 */
function resetStrengthDisplay(strengthMeter, scoreDisplay) {
    if (strengthMeter) {
        strengthMeter.style.width = '0%';
        strengthMeter.setAttribute('aria-valuenow', 0);
        strengthMeter.className = 'progress-bar';
        strengthMeter.textContent = 'Enter a password to see strength';
    }
    
    if (scoreDisplay) {
        const strengthText = scoreDisplay.querySelector('.strength-text');
        const strengthScore = scoreDisplay.querySelector('.strength-score');
        
        if (strengthText) {
            strengthText.textContent = 'Very Weak';
            strengthText.className = 'strength-text text-muted';
        }
        
        if (strengthScore) {
            strengthScore.textContent = '(0/4)';
        }
    }
}

/**
 * Update hidden field with password score
 * @param {number} score - Password strength score
 * @param {Object} config - Configuration options
 */
function updateHiddenField(score, config) {
    if (config.updateHiddenField && config.hiddenFieldId) {
        const hiddenField = document.getElementById(config.hiddenFieldId);
        if (hiddenField) {
            hiddenField.value = score;
        }
    }
}

/**
 * Validate password against requirements
 * @param {string} password - Password to validate
 * @param {number} score - Password strength score
 * @param {Object} config - Configuration options
 * @returns {Object} Validation result
 */
function validatePasswordRequirements(password, score, config) {
    const errors = [];
    
    // Check minimum score
    if (score < config.minScore) {
        errors.push(`Password strength must be at least ${PASSWORD_STRENGTH_LEVELS[config.minScore].name} (score ${config.minScore})`);
    }
    
    // Check minimum length
    if (password.length < config.minLength) {
        errors.push(`Password must be at least ${config.minLength} characters long`);
    }
    
    // Check character requirements
    if (config.requireUppercase && !/[A-Z]/.test(password)) {
        errors.push('Password must contain at least one uppercase letter');
    }
    
    if (config.requireLowercase && !/[a-z]/.test(password)) {
        errors.push('Password must contain at least one lowercase letter');
    }
    
    if (config.requireNumbers && !/[0-9]/.test(password)) {
        errors.push('Password must contain at least one number');
    }
    
    if (config.requireSymbols && !/[^A-Za-z0-9]/.test(password)) {
        errors.push('Password must contain at least one symbol');
    }
    
    return {
        isValid: errors.length === 0,
        errors: errors
    };
}

/**
 * Validate password confirmation match
 * @param {HTMLElement} passwordField - Password input field
 * @param {HTMLElement} confirmField - Confirm password field
 */
function validatePasswordMatch(passwordField, confirmField) {
    const password = passwordField.value;
    const confirm = confirmField.value;
    
    if (password && confirm) {
        if (password === confirm) {
            passwordField.classList.remove('is-invalid');
            confirmField.classList.remove('is-invalid');
            passwordField.classList.add('is-valid');
            confirmField.classList.add('is-valid');
        } else {
            passwordField.classList.remove('is-valid');
            confirmField.classList.remove('is-valid');
            passwordField.classList.add('is-invalid');
            confirmField.classList.add('is-invalid');
        }
    } else {
        passwordField.classList.remove('is-valid', 'is-invalid');
        confirmField.classList.remove('is-valid', 'is-invalid');
    }
}

/**
 * Generate a secure password
 * @param {Object} options - Generation options
 * @param {string} options.type - Password type: 'word', 'random', or 'mixed'
 * @param {number} options.length - Password length (for random type)
 * @param {number} options.words - Number of words (for word type)
 * @param {string} options.separator - Word separator (for word type)
 * @param {string} options.passwordFieldId - ID of password field to update
 * @param {string} options.confirmFieldId - ID of confirm field to update
 */
function generateSecurePassword(options = {}) {
    const config = {
        type: 'word',
        length: 16,
        words: 4,
        separator: ' ',
        passwordFieldId: 'password',
        confirmFieldId: null,
        ...options
    };
    
    let password = '';
    
    switch (config.type) {
        case 'word':
            password = generateWordBasedPassword(config.words, config.separator);
            break;
        case 'random':
            password = generateRandomPassword(config.length);
            break;
        case 'mixed':
            password = generateMixedPassword(config.length);
            break;
        default:
            password = generateWordBasedPassword(config.words, config.separator);
    }
    
    // Update password field
    const passwordField = document.getElementById(config.passwordFieldId);
    if (passwordField) {
        passwordField.value = password;
        passwordField.dispatchEvent(new Event('input'));
    }
    
    // Update confirm field if provided
    if (config.confirmFieldId) {
        const confirmField = document.getElementById(config.confirmFieldId);
        if (confirmField) {
            confirmField.value = password;
            confirmField.dispatchEvent(new Event('input'));
        }
    }
    
    return password;
}

/**
 * Generate word-based password
 * @param {number} wordCount - Number of words
 * @param {string} separator - Word separator
 * @returns {string} Generated password
 */
function generateWordBasedPassword(wordCount = 4, separator = ' ') {
    const adjectives = [
        'bright', 'swift', 'gentle', 'brave', 'clever', 'mighty', 'calm', 'wise',
        'quick', 'strong', 'kind', 'bold', 'smart', 'noble', 'pure', 'fair',
        'happy', 'brave', 'clever', 'mighty', 'calm', 'wise', 'quick', 'strong'
    ];
    
    const nouns = [
        'river', 'mountain', 'forest', 'ocean', 'star', 'moon', 'sun', 'cloud',
        'eagle', 'lion', 'wolf', 'bear', 'dragon', 'phoenix', 'unicorn', 'griffin',
        'castle', 'tower', 'bridge', 'garden', 'meadow', 'valley', 'island', 'cave'
    ];
    
    const words = [];
    
    for (let i = 0; i < wordCount; i++) {
        if (i === 0) {
            // First word: adjective
            const word = adjectives[Math.floor(Math.random() * adjectives.length)];
            words.push(word.charAt(0).toUpperCase() + word.slice(1));
        } else if (i === wordCount - 1) {
            // Last word: noun + number + symbol
            const noun = nouns[Math.floor(Math.random() * nouns.length)];
            const number = Math.floor(Math.random() * 1000);
            const symbols = ['!', '@', '#', '$', '%', '^', '&', '*', '?', '+'];
            const symbol = symbols[Math.floor(Math.random() * symbols.length)];
            words.push(noun.charAt(0).toUpperCase() + noun.slice(1) + number + symbol);
        } else {
            // Middle words: random choice
            const word = Math.random() < 0.5 ? 
                adjectives[Math.floor(Math.random() * adjectives.length)] :
                nouns[Math.floor(Math.random() * nouns.length)];
            words.push(word.charAt(0).toUpperCase() + word.slice(1));
        }
    }
    
    return words.join(separator);
}

/**
 * Generate random password
 * @param {number} length - Password length
 * @returns {string} Generated password
 */
function generateRandomPassword(length = 16) {
    const charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
    let password = '';
    
    for (let i = 0; i < length; i++) {
        password += charset.charAt(Math.floor(Math.random() * charset.length));
    }
    
    return password;
}

/**
 * Generate mixed password (words + random)
 * @param {number} length - Target length
 * @returns {string} Generated password
 */
function generateMixedPassword(length = 16) {
    const wordPassword = generateWordBasedPassword(2, '');
    const randomPassword = generateRandomPassword(Math.max(4, length - wordPassword.length));
    
    return wordPassword + randomPassword;
}

/**
 * Check if password meets minimum requirements
 * @param {string} password - Password to check
 * @param {Object} config - Configuration options
 * @returns {boolean} True if password meets requirements
 */
function isPasswordValid(password, config = DEFAULT_PASSWORD_CONFIG) {
    if (!password) return false;
    
    try {
        let result;
        if (typeof zxcvbn === 'function') {
            result = zxcvbn(password);
        } else {
            result = assessPasswordStrengthBasic(password);
        }
        
        const validation = validatePasswordRequirements(password, result.score, config);
        return validation.isValid;
    } catch (error) {
        console.error('Error validating password:', error);
        return false;
    }
}

// Export functions for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        initializePasswordStrength,
        generateSecurePassword,
        isPasswordValid,
        validatePasswordRequirements,
        PASSWORD_STRENGTH_LEVELS,
        DEFAULT_PASSWORD_CONFIG
    };
}
