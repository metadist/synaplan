# Password Security Upgrade Documentation

## Overview

The application's password security has been upgraded from **MD5 hashing** (insecure) to **bcrypt hashing** (secure and modern) while maintaining full backward compatibility with existing user accounts.

## What Changed

### Security Improvements

- **Before**: Passwords were hashed using MD5, which is cryptographically broken and vulnerable to rainbow table attacks
- **After**: Passwords are now hashed using bcrypt with automatic salting and configurable cost factor (currently 10)
- **Benefit**: Bcrypt is designed for password hashing, is resistant to brute-force attacks, and adjusts computational cost as hardware improves

### Backward Compatibility

The upgrade maintains **100% backward compatibility**:

1. **Existing users** with MD5 passwords can still log in normally
2. **Automatic upgrade**: When a user with an MD5 password logs in successfully, their password is automatically upgraded to bcrypt
3. **No database migration needed**: The upgrade happens transparently during login
4. **No user action required**: Users don't need to reset passwords or take any action

## Implementation Details

### New PasswordHelper Class

A new centralized password management class was created at:
- **File**: `/app/inc/auth/passwordhelper.php`

**Key Methods:**

```php
// Hash a new password (always uses bcrypt)
PasswordHelper::hash($password)

// Verify password against stored hash (supports both MD5 and bcrypt)
PasswordHelper::verify($password, $storedHash)

// Check if hash needs upgrading (true for MD5 or outdated bcrypt)
PasswordHelper::needsRehash($hash)

// Upgrade user's password in database
PasswordHelper::upgradeUserPassword($userId, $plainPassword)
```

### Updated Files

The following files were updated to use the new PasswordHelper:

1. **`/app/inc/auth/passwordhelper.php`** - NEW: Core password security class
2. **`/app/inc/_coreincludes.php`** - Added PasswordHelper to includes
3. **`/app/inc/_frontend.php`** - Updated login to verify passwords and auto-upgrade
4. **`/app/inc/auth/userregistration.php`** - Updated registration and password reset
5. **`/frontend/c_settings.php`** - Updated password change functionality
6. **`/app/inc/integrations/wordpresswizard.php`** - Updated WordPress integration
7. **`/public/admin/test-createuser.php`** - Updated test user creation

### How It Works

#### New User Registration
```php
// New users always get bcrypt hashes
$passwordHash = PasswordHelper::hash($password);
// Stored in database: $2y$10$... (bcrypt format)
```

#### User Login (Auto-Upgrade)
```php
// 1. Query user by email
$user = getUser($email);

// 2. Verify password (works for both MD5 and bcrypt)
if (PasswordHelper::verify($password, $user['BPW'])) {
    
    // 3. Check if upgrade needed (MD5 detected)
    if (PasswordHelper::needsRehash($user['BPW'])) {
        // 4. Automatically upgrade to bcrypt
        PasswordHelper::upgradeUserPassword($user['BID'], $password);
    }
    
    // 5. Login successful
}
```

#### Hash Detection Logic
```php
// MD5 hashes are exactly 32 hexadecimal characters
// Example: 5f4dcc3b5aa765d61d8327deb882cf99

// Bcrypt hashes start with $2y$ and are 60 characters
// Example: $2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy
```

## Database Schema

**No changes required!** The `BUSER.BPW` field already stores variable-length strings and accommodates both formats:

- **MD5**: 32 characters (e.g., `5f4dcc3b5aa765d61d8327deb882cf99`)
- **Bcrypt**: 60 characters (e.g., `$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZA...`)

## Testing

### Manual Testing Steps

1. **Test existing user login** (MD5 password)
   - User should log in successfully
   - Check database - password should now be bcrypt format ($2y$...)
   
2. **Test new user registration**
   - Create new user
   - Check database - password should be bcrypt format
   
3. **Test password reset**
   - Request password reset
   - Check database - new password should be bcrypt format
   
4. **Test password change**
   - Change password in settings
   - Check database - password should be bcrypt format

### Verification Query

To check password hash formats in your database:

```sql
-- Count users by hash type
SELECT 
    CASE 
        WHEN LENGTH(BPW) = 32 AND BPW REGEXP '^[a-f0-9]{32}$' THEN 'MD5 (legacy)'
        WHEN BPW LIKE '$2y$%' THEN 'bcrypt (secure)'
        ELSE 'unknown'
    END as hash_type,
    COUNT(*) as user_count
FROM BUSER
GROUP BY hash_type;
```

## Security Benefits

### MD5 Vulnerabilities (Fixed)
- ❌ Fast to compute (enables brute-force attacks)
- ❌ No built-in salt (vulnerable to rainbow tables)
- ❌ Cryptographically broken (collision attacks exist)
- ❌ Not designed for password hashing

### Bcrypt Advantages (New)
- ✅ Slow by design (resistant to brute-force)
- ✅ Automatic salting (each password has unique hash)
- ✅ Configurable work factor (adjustable security)
- ✅ Industry standard for password storage

## Migration Progress

The migration happens **automatically and gradually**:

```
Day 0:  100% MD5 passwords
Day 1:   90% MD5 (10% of active users logged in)
Week 1:  50% MD5 (50% of active users logged in)
Month 1: 10% MD5 (90% of active users logged in)
```

**No forced password reset needed!** Users naturally upgrade as they log in.

## Rollback Plan (Emergency Only)

If critical issues arise, you can temporarily revert to MD5-only verification:

1. Comment out the auto-upgrade code in `/app/inc/_frontend.php`:
   ```php
   // if (PasswordHelper::needsRehash($uArr['BPW'])) {
   //     PasswordHelper::upgradeUserPassword($uArr['BID'], $password);
   // }
   ```

2. This stops new upgrades but allows both formats to work
3. **DO NOT** change new registrations back to MD5

## FAQ

**Q: Will existing users notice any changes?**  
A: No. The upgrade is completely transparent. They log in normally and their password is automatically upgraded.

**Q: What if a user never logs in?**  
A: Their MD5 password continues to work. When they eventually log in, it will be upgraded.

**Q: Can I force all users to upgrade immediately?**  
A: Not recommended without the plain-text passwords. The auto-upgrade only works during login when we have the plain-text password to re-hash.

**Q: How do I know the upgrade is working?**  
A: Check the database using the verification query above. You'll see the count of bcrypt hashes increase over time.

**Q: Is this change PCI DSS compliant?**  
A: Yes. Bcrypt is an approved password hashing algorithm under PCI DSS requirements.

**Q: What about password reset?**  
A: Password reset generates a new password which is automatically hashed with bcrypt.

## Additional Security Recommendations

Consider implementing these additional security measures:

1. **Password Strength Requirements**
   - Minimum 8-12 characters (currently 6)
   - Require mix of uppercase, lowercase, numbers, special chars
   - Check against common password lists

2. **Rate Limiting**
   - Limit login attempts per IP/account
   - Implement account lockout after failed attempts
   - Add exponential backoff

3. **Two-Factor Authentication (2FA)**
   - TOTP-based 2FA
   - SMS or email verification codes
   - Backup codes

4. **Session Security**
   - Implement session timeout
   - Rotate session IDs after login
   - Use secure, httponly cookies

5. **Password History**
   - Prevent password reuse
   - Store last 5-10 password hashes

## Support

For questions or issues related to this upgrade:

1. Check the application logs for any password-related errors
2. Review this documentation
3. Test in a staging environment first if concerned
4. Contact the development team

## Version History

- **v1.0** (2025-10-21): Initial password security upgrade
  - Implemented bcrypt hashing
  - Added backward compatibility with MD5
  - Auto-upgrade on login
  - Updated all password operations

---

**Security Note**: This upgrade significantly improves password security. The automatic migration approach ensures no user disruption while progressively securing all accounts.

