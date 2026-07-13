# Password Strength Configuration Guide

## Overview

The LDAP User Manager includes a flexible password strength system that helps ensure your users create secure passwords. This system can be customized to match your organization's security requirements without requiring any code changes.

## What You Can Configure

### Password Strength Levels

The system uses a scoring system from 0 to 4 to measure password strength:

- **0**: Very Weak — easily guessed or cracked
- **1**: Weak — can be cracked quickly by automated tools
- **2**: Fair — moderate security, suitable for most organizations
- **3**: Good — strong security, recommended for sensitive environments
- **4**: Strong — excellent security, maximum protection

### Password Requirements

You can control several aspects of password requirements:

- **Minimum Strength Score**: The lowest acceptable password strength level
- **Minimum Length**: The shortest password allowed
- **Character Types**: Whether to require specific types of characters

## Configuration Options

### Environment Variables

Set these variables in your environment to control password requirements:

| Setting | Description | Default | Example Values |
|---------|-------------|---------|----------------|
| `PASSWORD_STRENGTH_MIN_SCORE` | Minimum strength level required | `2` | `0`, `1`, `2`, `3`, `4` |
| `PASSWORD_STRENGTH_MIN_LENGTH` | Minimum password length | `8` | `4`, `6`, `8`, `12`, `16` |
| `PASSWORD_STRENGTH_REQUIRE_UPPERCASE` | Require capital letters | `TRUE` | `TRUE`, `FALSE` |
| `PASSWORD_STRENGTH_REQUIRE_LOWERCASE` | Require small letters | `TRUE` | `TRUE`, `FALSE` |
| `PASSWORD_STRENGTH_REQUIRE_NUMBERS` | Require numbers | `TRUE` | `TRUE`, `FALSE` |
| `PASSWORD_STRENGTH_REQUIRE_SYMBOLS` | Require special characters | `FALSE` | `TRUE`, `FALSE` |

### Legacy Setting

- `ACCEPT_WEAK_PASSWORDS` — if set to `TRUE`, allows any password regardless of strength score

## Configuration Examples

### 1. Development/Testing Environment

Use these settings when you need to test the system quickly:

```bash
# Allow any password for testing
export PASSWORD_STRENGTH_MIN_SCORE=0
export PASSWORD_STRENGTH_MIN_LENGTH=4
export ACCEPT_WEAK_PASSWORDS=TRUE

# No character requirements
export PASSWORD_STRENGTH_REQUIRE_UPPERCASE=FALSE
export PASSWORD_STRENGTH_REQUIRE_LOWERCASE=FALSE
export PASSWORD_STRENGTH_REQUIRE_NUMBERS=FALSE
export PASSWORD_STRENGTH_REQUIRE_SYMBOLS=FALSE
```

**Result**: Users can create very simple passwords like "test" or "1234"

### 2. Production Environment (Strict)

Use these settings for maximum security:

```bash
# Require strong passwords
export PASSWORD_STRENGTH_MIN_SCORE=3
export PASSWORD_STRENGTH_MIN_LENGTH=12
export ACCEPT_WEAK_PASSWORDS=FALSE

# Require all character types
export PASSWORD_STRENGTH_REQUIRE_UPPERCASE=TRUE
export PASSWORD_STRENGTH_REQUIRE_LOWERCASE=TRUE
export PASSWORD_STRENGTH_REQUIRE_NUMBERS=TRUE
export PASSWORD_STRENGTH_REQUIRE_SYMBOLS=TRUE
```

**Result**: Users must create complex passwords like "SecurePass123!" that are at least 12 characters long

### 3. Balanced Environment (Recommended)

Use these settings for most organizations:

```bash
# Moderate security requirements
export PASSWORD_STRENGTH_MIN_SCORE=2
export PASSWORD_STRENGTH_MIN_LENGTH=8
export ACCEPT_WEAK_PASSWORDS=FALSE

# Require mixed case and numbers
export PASSWORD_STRENGTH_REQUIRE_UPPERCASE=TRUE
export PASSWORD_STRENGTH_REQUIRE_LOWERCASE=TRUE
export PASSWORD_STRENGTH_REQUIRE_NUMBERS=TRUE
export PASSWORD_STRENGTH_REQUIRE_SYMBOLS=FALSE
```

**Result**: Users must create passwords like "Password123" that are at least 8 characters with mixed case and numbers

## Docker Configuration

### docker-compose.yml Example

```yaml
version: '3.8'
services:
  ldap-user-manager:
    environment:
      # Password strength configuration
      - PASSWORD_STRENGTH_MIN_SCORE=2
      - PASSWORD_STRENGTH_MIN_LENGTH=8
      - PASSWORD_STRENGTH_REQUIRE_UPPERCASE=TRUE
      - PASSWORD_STRENGTH_REQUIRE_LOWERCASE=TRUE
      - PASSWORD_STRENGTH_REQUIRE_NUMBERS=TRUE
      - PASSWORD_STRENGTH_REQUIRE_SYMBOLS=FALSE
      - ACCEPT_WEAK_PASSWORDS=FALSE
      
      # Other configuration
      - PASSWORD_HASH=ARGON2
      - LDAP_ADMIN_ROLE=administrator
      - LDAP_MAINTAINER_ROLE=maintainer
```

### Dockerfile Example

```dockerfile
# Set default password strength for production
ENV PASSWORD_STRENGTH_MIN_SCORE=2
ENV PASSWORD_STRENGTH_MIN_LENGTH=8
ENV PASSWORD_STRENGTH_REQUIRE_UPPERCASE=TRUE
ENV PASSWORD_STRENGTH_REQUIRE_LOWERCASE=TRUE
ENV PASSWORD_STRENGTH_REQUIRE_NUMBERS=TRUE
ENV PASSWORD_STRENGTH_REQUIRE_SYMBOLS=FALSE
ENV ACCEPT_WEAK_PASSWORDS=FALSE
```

## Testing Your Configuration

### Test Different Password Types

Use these example passwords to test your configuration:

| Password | Expected Score | Will Pass (minScore=2) |
|----------|----------------|---------------------------|
| `password` | 0 | ❌ No (common word) |
| `Password1` | 0–1 | ❌ No (common pattern) |
| `Password123` | 1–2 | ⚠️ May pass length/rules; often weak |
| `SecurePass123!` | 2–3 | ✅ Usually yes |
| `correct horse battery staple` | 3–4 | ✅ Yes (strong passphrase) |
| `a` | 0 | ❌ No (too short) |

### Check Your Current Settings

You can verify your configuration by looking at the password forms in the web interface. The system will show the current requirements and provide real-time feedback as users type passwords.

## Security Recommendations

### For Production Use

- **Minimum Score**: Use 2 or higher
- **Minimum Length**: Use 8 or more characters
- **Character Requirements**: Require mixed case and numbers
- **Symbols**: Consider requiring symbols for high-security environments

### For Development/Testing

- **Minimum Score**: Use 0 or 1 for easier testing
- **Minimum Length**: Use 4–6 characters
- **Character Requirements**: Relax requirements for convenience
- **Weak Passwords**: Enable if needed for testing

### For Different Organizations

- **Small Organizations**: Score 2, 8+ characters
- **Medium Organizations**: Score 2–3, 10+ characters
- **Large Organizations**: Score 3, 12+ characters
- **High-Security**: Score 3–4, 12+ characters, symbols required

## Understanding Password Strength

### How the System Measures Strength

Scoring uses the [zxcvbn](https://github.com/dropbox/zxcvbn) library (bundled in `password.min.js`), which estimates real-world crack resistance:

1. **Dictionary and breach lists** — common passwords score low
2. **Pattern recognition** — keyboard walks, repeats, dates
3. **Length and entropy** — longer unpredictable strings score higher
4. **User context** — optional related inputs can lower scores

Character requirements (`PASSWORD_STRENGTH_REQUIRE_*`) are enforced separately in the browser. A password must satisfy both the minimum score and any configured character rules.

### What Makes a Strong Password

- **Length**: At least 8 characters (12+ for high security)
- **Variety**: Mix of uppercase, lowercase, numbers, and symbols
- **Unpredictability**: Avoid common words, names, or patterns
- **Uniqueness**: Do not reuse passwords from other accounts

### Examples of Strong Passwords

- `BrightRiver847!` (word-based, memorable)
- `K9#mN2$pL8@vX` (random, maximum security)
- `MyFavoriteColorIsBlue2024!` (passphrase style)

## Common Issues and Solutions

### Password Rejected as Too Weak

**Problem**: Users get "password not strong enough" errors

**Solutions**:
1. Check your `PASSWORD_STRENGTH_MIN_SCORE` setting
2. Verify `PASSWORD_STRENGTH_MIN_LENGTH` requirements
3. Ensure character requirements are reasonable
4. Consider lowering requirements for testing

### Passwords Too Complex for Users

**Problem**: Users struggle to create acceptable passwords

**Solutions**:
1. Lower the minimum score requirement
2. Reduce character type requirements
3. Provide password generation tools
4. Offer user training on password creation

### Configuration Not Taking Effect

**Problem**: Changes to environment variables do not seem to work

**Solutions**:
1. Restart your web server or Docker container
2. Verify environment variables are set correctly
3. Check for typos in variable names
4. Ensure proper syntax (TRUE/FALSE, not true/false)

## Best Practices

### 1. Start Conservative

Begin with moderate requirements and increase them gradually:
- Start with score 2 and 8 characters
- Increase to score 3 and 10+ characters over time
- Monitor user feedback and adjust accordingly

### 2. Consider Your Users

- **Technical Users**: Can handle complex requirements
- **General Users**: Keep requirements reasonable
- **High-Security Users**: Use maximum requirements

### 3. Balance Security and Usability

- **Too Strict**: Users may create weak passwords or write them down
- **Too Lenient**: Security risks from easily guessed passwords
- **Just Right**: Strong enough for security, reasonable for users

### 4. Provide Tools and Guidance

- Enable password generation features
- Show real-time strength feedback
- Provide clear requirements explanation
- Offer password creation tips

## Summary

The password strength configuration system provides:

- **Flexible Requirements**: Customize password policies for your needs
- **Environment-Specific**: Different settings for development, testing, and production
- **Easy Adjustment**: Change requirements without code modifications
- **Security Control**: Balance security needs with user convenience
- **Real-Time Feedback**: Help users create better passwords

This system allows you to create the right balance of security and usability for your organization while maintaining the flexibility to adjust requirements as needed.
