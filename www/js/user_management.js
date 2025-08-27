/**
 * User Management JavaScript Utilities
 * 
 * This file contains consolidated JavaScript functions that were previously
 * duplicated across multiple user management pages. These functions provide
 * consistent behavior for user forms, password generation, and field updates.
 * 
 * @package LDAP User Manager
 * @version 1.0
 */

/**
 * Update the common name (cn) field based on given name and surname
 * @param {string} givenNameField - ID of the given name input field
 * @param {string} surnameField - ID of the surname input field
 * @param {string} cnField - ID of the common name input field
 */
function updateCommonName(givenNameField = 'givenname', surnameField = 'sn', cnField = 'cn') {
    const givenName = document.getElementById(givenNameField)?.value || '';
    const surname = document.getElementById(surnameField)?.value || '';
    
    const cnFieldElement = document.getElementById(cnField);
    if (cnFieldElement) {
        if (givenName && surname) {
            cnFieldElement.value = givenName + ' ' + surname;
        } else if (givenName) {
            cnFieldElement.value = givenName;
        } else if (surname) {
            cnFieldElement.value = surname;
        } else {
            cnFieldElement.value = '';
        }
    }
}

/**
 * Update the account UID field based on email
 * @param {string} emailField - ID of the email input field
 * @param {string} uidField - ID of the UID input field
 */
function updateAccountUid(emailField = 'mail', uidField = 'uid') {
    const email = document.getElementById(emailField)?.value || '';
    const uidFieldElement = document.getElementById(uidField);
    
    if (uidFieldElement && email) {
        uidFieldElement.value = email;
    }
}

/**
 * Generate a secure password with word-based approach
 * @param {string} passwordField - ID of the password input field
 * @param {string} confirmField - ID of the confirm password field (optional)
 */
function generatePassword(passwordField = 'password', confirmField = null) {
    const adjectives = [
        'bright', 'swift', 'gentle', 'brave', 'clever', 'mighty', 'calm', 'wise',
        'quick', 'strong', 'kind', 'bold', 'smart', 'noble', 'pure', 'fair'
    ];
    
    const nouns = [
        'river', 'mountain', 'forest', 'ocean', 'star', 'moon', 'sun', 'cloud',
        'eagle', 'lion', 'wolf', 'bear', 'dragon', 'phoenix', 'unicorn', 'griffin'
    ];
    
    const symbols = ['!', '@', '#', '$', '%', '^', '&', '*', '?', '+'];
    
    // Generate random components
    const adjective = adjectives[Math.floor(Math.random() * adjectives.length)];
    const noun = nouns[Math.floor(Math.random() * nouns.length)];
    const number = Math.floor(Math.random() * 1000);
    const symbol = symbols[Math.floor(Math.random() * symbols.length)];
    
    // Combine into password
    const password = adjective.charAt(0).toUpperCase() + adjective.slice(1) + 
                    noun.charAt(0).toUpperCase() + noun.slice(1) + 
                    number + symbol;
    
    // Set password field
    const passwordElement = document.getElementById(passwordField);
    if (passwordElement) {
        passwordElement.value = password;
        passwordElement.dispatchEvent(new Event('input')); // Trigger change events
    }
    
    // Set confirm field if provided
    if (confirmField) {
        const confirmElement = document.getElementById(confirmField);
        if (confirmElement) {
            confirmElement.value = password;
            confirmElement.dispatchEvent(new Event('input')); // Trigger change events
        }
    }
}

/**
 * Update display name in real-time as user types
 * @param {string} givenNameField - ID of the given name input field
 * @param {string} surnameField - ID of the surname input field
 * @param {string} displayField - ID of the display name field
 */
function updateDisplayName(givenNameField = 'givenname', surnameField = 'sn', displayField = 'cn') {
    const givenNameElement = document.getElementById(givenNameField);
    const surnameElement = document.getElementById(surnameField);
    
    if (givenNameElement && surnameElement) {
        const updateDisplay = () => {
            updateCommonName(givenNameField, surnameField, displayField);
        };
        
        givenNameElement.addEventListener('input', updateDisplay);
        surnameElement.addEventListener('input', updateDisplay);
        
        // Initial update
        updateDisplay();
    }
}

/**
 * Update account UID in real-time as user types email
 * @param {string} emailField - ID of the email input field
 * @param {string} uidField - ID of the UID field
 */
function updateAccountUidRealTime(emailField = 'mail', uidField = 'uid') {
    const emailElement = document.getElementById(emailField);
    
    if (emailElement) {
        emailElement.addEventListener('input', () => {
            updateAccountUid(emailField, uidField);
        });
        
        // Initial update
        updateAccountUid(emailField, uidField);
    }
}

/**
 * Initialize all user management form enhancements
 * @param {Object} options - Configuration options
 */
function initializeUserManagementForms(options = {}) {
    const config = {
        givenNameField: options.givenNameField || 'givenname',
        surnameField: options.surnameField || 'sn',
        displayField: options.displayField || 'cn',
        emailField: options.emailField || 'mail',
        uidField: options.uidField || 'uid',
        passwordField: options.passwordField || 'password',
        confirmField: options.confirmField || null
    };
    
    // Initialize real-time updates
    updateDisplayName(config.givenNameField, config.surnameField, config.displayField);
    updateAccountUidRealTime(config.emailField, config.uidField);
    
    // Add password generation button if password field exists
    const passwordElement = document.getElementById(config.passwordField);
    if (passwordElement) {
        const generateButton = document.createElement('button');
        generateButton.type = 'button';
        generateButton.className = 'btn btn-secondary btn-sm';
        generateButton.textContent = 'Generate Password';
        generateButton.onclick = () => generatePassword(config.passwordField, config.confirmField);
        
        // Insert after password field
        passwordElement.parentNode.insertBefore(generateButton, passwordElement.nextSibling);
    }
}

/**
 * Common utility functions used across user management pages
 */

/**
 * Confirm deletion of a user
 * @param {string} uuid - User UUID
 * @param {string} accountIdentifier - User account identifier (email/username)
 * @returns {boolean} True if deletion confirmed
 */
function confirmDelete(uuid, accountIdentifier) {
    return confirm(`Are you sure you want to delete user ${accountIdentifier}?\n\nThis action cannot be undone and will remove the user from all roles and organizations.`);
}

/**
 * Confirm deletion of an organization
 * @param {string} orgName - Organization name
 * @param {string} orgUuid - Organization UUID (optional)
 * @returns {boolean} True if deletion confirmed
 */
function confirmDeleteOrganization(orgName, orgUuid = '') {
    const identifier = orgUuid || orgName;
    return confirm(`Are you sure you want to delete organization "${orgName}"?\n\nThis action cannot be undone and will remove all users and roles associated with this organization.`);
}

/**
 * Initialize search functionality for user tables
 * @param {string} searchInputId - ID of the search input field
 * @param {string} tableId - ID of the table to search
 */
function initializeUserSearch(searchInputId = 'user_search_input', tableId = 'user_table') {
    const searchInput = document.getElementById(searchInputId);
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            const value = this.value.toLowerCase();
            const rows = document.querySelectorAll(`#${tableId} tbody tr`);
            rows.forEach(function(row) {
                row.style.display = row.textContent.toLowerCase().indexOf(value) > -1 ? '' : 'none';
            });
        });
    }
}

/**
 * Auto-dismiss feedback messages after a specified time
 * @param {string} messageId - ID of the message element
 * @param {number} delay - Delay in milliseconds (default: 4000)
 */
function autoDismissMessage(messageId = 'msgbox', delay = 4000) {
    setTimeout(function() {
        const msg = document.getElementById(messageId);
        if (msg) { 
            msg.style.display = 'none'; 
        }
    }, delay);
}

/**
 * Initialize common user management page functionality
 * @param {Object} options - Configuration options
 */
function initializeUserManagementPage(options = {}) {
    const config = {
        searchInputId: options.searchInputId || 'user_search_input',
        tableId: options.tableId || 'user_table',
        messageId: options.messageId || 'msgbox',
        messageDelay: options.messageDelay || 4000
    };
    
    // Initialize search functionality
    initializeUserSearch(config.searchInputId, config.tableId);
    
    // Auto-dismiss messages
    autoDismissMessage(config.messageId, config.messageDelay);
}
