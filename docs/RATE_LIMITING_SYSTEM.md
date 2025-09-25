# Rate Limiting System Documentation

## Overview

The Rate Limiting System is a comprehensive security and resource management solution that controls user access to AI operations (image generation, video generation, audio generation, file analysis) based on their subscription level and usage patterns. The system implements a dual-layer approach with subscription-based limits and lifetime free usage limits.

## System Architecture

### Core Components

1. **XSControl Class** (`app/inc/_xscontrol.php`)
   - Main rate limiting engine
   - User limit retrieval and validation
   - Usage tracking and counting
   - Intelligent limit messaging

2. **Database Tables**
   - `BCONFIG`: Rate limit configuration storage
   - `BUSELOG`: Usage tracking and operation logging
   - `BUSER`: User subscription levels and payment details
   - `BCAPABILITIES`: Operation type mapping

3. **Configuration Files**
   - `BRATELIMITS_CONFIG.sql`: Rate limit definitions per subscription tier

## Database Schema

### BCONFIG Table
Stores rate limiting configuration per subscription level:
```sql
-- Structure: (BOWNERID, BGROUP, BSETTING, BVALUE)
-- Example: (0, 'RATELIMITS_PRO', 'IMAGES_2592000S', '20')
```

**Rate Limit Groups:**
- `RATELIMITS_NEW`: Free tier users
- `RATELIMITS_PRO`: Pro subscription users
- `RATELIMITS_TEAM`: Team subscription users
- `RATELIMITS_BUSINESS`: Business subscription users
- `RATELIMITS_WIDGET`: Anonymous/widget users

**Setting Pattern:**
- Format: `{OPERATION}_{TIMEFRAME}S`
- Operations: `IMAGES`, `VIDEOS`, `AUDIOS`, `FILE_ANALYSIS`, `MESSAGES`, `FILEBYTES`, `APICALLS`
- Timeframes: `120S` (2 min), `3600S` (1 hour), `86400S` (1 day), `2592000S` (30 days)

### BUSELOG Table
Tracks all user operations for rate limiting:
```sql
-- Key fields:
BUSERID          -- User identifier
BTIMESTAMP       -- Operation timestamp
BOPERATIONTYPE   -- Type: 'text2pic', 'text2vid', 'text2sound', 'analyzefile', 'general'
BSUBSCRIPTION_ID -- Stripe subscription ID (NULL for free usage)
BMSGID           -- Associated message ID
```

### BUSER Table
Stores user subscription information:
```sql
-- Key fields:
BUSERLEVEL       -- Subscription level: 'NEW', 'PRO', 'TEAM', 'BUSINESS'
BPAYMENTDETAILS  -- JSON: subscription status, timestamps, stripe IDs
```

## Rate Limiting Logic

### Dual-Layer Security Model

The system implements two independent limit checks:

#### 1. Subscription Limits (Monthly Reset)
- Applied to **paid usage** (`BSUBSCRIPTION_ID IS NOT NULL`)
- Reset monthly based on subscription billing cycle
- Different limits per subscription tier
- Used for operations performed with active subscription

#### 2. Free Limits (Lifetime)
- Applied to **free usage** (`BSUBSCRIPTION_ID IS NULL`)
- **Never reset** - lifetime limit per account
- Same limit values for all users (based on NEW tier)
- Bonus allowance in addition to subscription limits

### User Classification

#### NEW Users
- Only subject to NEW tier limits
- All operations counted against monthly limits
- No subscription benefits

#### Paid Users (PRO/TEAM/BUSINESS)
- Subject to **both** subscription AND free limits
- Subscription limits: Monthly reset, tier-specific
- Free limits: Lifetime bonus, never reset
- Both limits enforced independently

### Limit Enforcement Flow

```
User Request → Input Validation → User Level Check → Limit Validation → Response
```

1. **Input Validation**
   - Validate user ID (must be > 0)
   - Validate operation type (must exist)
   - Block invalid requests immediately

2. **User Level Determination**
   - Read `BUSERLEVEL` from `BUSER` table
   - Parse `BPAYMENTDETAILS` JSON for subscription status
   - Handle edge cases (empty, malformed data)

3. **Limit Checking**
   - **NEW Users**: Check only subscription limits (monthly)
   - **Paid Users**: Check subscription limits (monthly) AND free limits (lifetime)
   - Both checks are independent and enforced

4. **Usage Counting**
   - **Paid Usage**: Count within timeframe, filter by `BSUBSCRIPTION_ID IS NOT NULL`
   - **Free Usage**: Count lifetime, filter by `BSUBSCRIPTION_ID IS NULL`

## Key Functions

### Core Rate Limiting Functions

#### `checkOperationLimit($msgArr, $operation)`
**Purpose**: Main rate limiting entry point
**Parameters**:
- `$msgArr`: Array containing `BUSERID`
- `$operation`: Operation type ('text2pic', 'text2vid', etc.)

**Return**: Array with limit status and user-friendly messages

**Logic Flow**:
1. Validate inputs (fail-secure for invalid data)
2. Determine user subscription level
3. For NEW users: Check only monthly limits
4. For paid users: Check both subscription and free limits
5. Return appropriate limit exceeded message

#### `getUserLimits($userId)`
**Purpose**: Retrieve subscription-specific rate limits
**Parameters**: User ID
**Return**: Array of limits for user's subscription level

**Logic**:
- Query `BCONFIG` table with `BGROUP = 'RATELIMITS_{LEVEL}'`
- Emergency fallback if no limits found (extremely restrictive)

#### `getFreeLimits()`
**Purpose**: Retrieve lifetime free usage limits
**Return**: Array of NEW tier limits (used as free bonus)

**Logic**:
- Query `BCONFIG` table with `BGROUP = 'RATELIMITS_NEW'`
- Emergency fallback if no limits found

#### `countUsageByType($userId, $timeframe, $operationType, $usageType)`
**Purpose**: Count user operations by usage type
**Parameters**:
- `$userId`: User identifier
- `$timeframe`: Time window (ignored for free usage)
- `$operationType`: Operation type to count
- `$usageType`: 'paid' or 'free'

**Logic**:
- **Paid usage**: Count within timeframe AND `BSUBSCRIPTION_ID IS NOT NULL`
- **Free usage**: Count lifetime (no timeframe) AND `BSUBSCRIPTION_ID IS NULL`

### Usage Tracking Functions

#### `countThis($userId, $msgId, $operationType)`
**Purpose**: Log new operation to BUSELOG
**Logic**:
- Insert record with current subscription ID
- Use `getCurrentSubscriptionId()` to determine subscription context

#### `updateOperationType($userId, $msgId, $operationType)`
**Purpose**: Update operation type after message processing
**Logic**:
- Called after successful AI generation
- Updates `BOPERATIONTYPE` and `BSUBSCRIPTION_ID`

### User Management Functions

#### `getUserSubscriptionLevel($userId)`
**Purpose**: Determine effective user subscription level
**Logic**:
- Read `BUSERLEVEL` from `BUSER` table
- Parse `BPAYMENTDETAILS` to check subscription status
- Handle deactive/expired subscriptions (fallback to NEW)

#### `getCurrentSubscriptionId($userId)`
**Purpose**: Get current Stripe subscription ID
**Return**: Subscription ID or NULL for free users

## Configuration

### Subscription Tier Limits

#### NEW (Free Tier)
```
Messages: 5/2min, 30/hour
Images: 5/month
Videos: 3/month
Audio: 5/month
File Analysis: 5/day
```

#### PRO
```
Messages: 15/2min, 100/hour
Images: 20/month + 5 free lifetime
Videos: 5/month + 3 free lifetime
Audio: 10/month + 5 free lifetime
File Analysis: 10/day + 5 free lifetime
```

#### TEAM
```
Messages: 30/2min, 200/hour
Images: 100/month + 5 free lifetime
Videos: 20/month + 3 free lifetime
Audio: 50/month + 5 free lifetime
File Analysis: 50/day + 5 free lifetime
```

#### BUSINESS
```
Messages: 100/2min, 1000/hour
Images: 500/month + 5 free lifetime
Videos: 100/month + 3 free lifetime
Audio: 300/month + 5 free lifetime
File Analysis: 200/day + 5 free lifetime
```

### Environment Configuration

#### Required Environment Variables
```bash
RATE_LIMITING_ENABLED=true  # Master switch for rate limiting
```

#### URL Configuration
```bash
SYSTEM_PRICING_URL=pricing    # Upgrade page URL
SYSTEM_ACCOUNT_URL=account    # Account management URL
APP_URL=https://domain.com    # Base application URL
```

## Security Features

### Input Validation
- All user IDs validated (must be > 0)
- All operation types validated against whitelist
- Invalid requests immediately blocked with secure failure

### SQL Injection Protection
- All database inputs properly escaped
- Parameterized queries where applicable
- No direct user input in SQL strings

### Emergency Fallbacks
- System defaults to extremely restrictive limits on errors
- Database corruption handled gracefully
- Network issues fail secure (block access)

### Edge Case Handling
- Invalid user levels → Emergency mode (1 image/month)
- Malformed payment data → Fallback to NEW limits
- Missing configuration → Ultra-restrictive emergency limits
- Database errors → Fail secure

## Operation Flow

### Typical Request Flow

1. **User Action**: User requests AI generation (image, video, etc.)

2. **Pre-Processing**: 
   - Message saved to database
   - Initial BUSELOG entry created with 'general' type

3. **Rate Limit Check**:
   - `checkOperationLimit()` called with operation type
   - System determines user level and checks appropriate limits
   - Returns limit status and user-friendly message

4. **Processing Decision**:
   - If limited: Return rate limit message to user
   - If allowed: Continue to AI generation

5. **Post-Processing**:
   - After successful generation: Update BUSELOG with specific operation type
   - Failed generations remain as 'general' (don't count against specific limits)

### Subscription Change Handling

1. **Upgrade**: User immediately gains higher limits
2. **Downgrade**: User retains current billing cycle limits until next renewal
3. **Cancellation**: User falls back to NEW limits
4. **Expiration**: User automatically moved to NEW limits

### Free Usage Mechanics

- Free usage tracked separately from subscription usage
- Never resets (lifetime allowance)
- Available to all users as bonus
- Enforced even for users with active subscriptions

## Monitoring and Logging

### Error Logging
- Critical errors logged with context
- Security violations logged with user details
- Database issues logged for system monitoring

### Performance Considerations
- Indexed database queries for fast lookups
- Efficient counting queries with proper WHERE clauses
- Minimal database hits per rate limit check

## Integration Points

### Frontend Integration
- JavaScript receives structured rate limit responses
- Dynamic countdown timers based on subscription billing cycles
- Intelligent action buttons (upgrade, reactivate, account management)

### API Integration
- RESTful rate limit responses
- Consistent error codes and messages
- Support for multiple client types (web, mobile, API)

### Payment System Integration
- Stripe subscription ID tracking
- Automatic limit updates on subscription changes
- Grace period handling for payment issues

## Troubleshooting

### Common Issues

#### User Sees Wrong Limits
- Check `BUSERLEVEL` in `BUSER` table
- Verify `BPAYMENTDETAILS` JSON structure
- Confirm subscription status and timestamps

#### Limits Not Enforcing
- Verify `RATE_LIMITING_ENABLED=true` in environment
- Check `BCONFIG` table for subscription tier limits
- Validate BUSELOG entries have correct `BSUBSCRIPTION_ID`

#### Emergency Mode Triggered
- Check error logs for "CRITICAL" messages
- Verify `RATELIMITS_NEW` exists in `BCONFIG`
- Confirm database connectivity

### Debugging Tools

#### Check User's Current Status
```php
$userLevel = XSControl::getUserSubscriptionLevel($userId);
$limits = XSControl::getUserLimits($userId);
```

#### Check Usage Counts
```php
$freeUsage = XSControl::countUsageByType($userId, 0, 'text2pic', 'free');
$paidUsage = XSControl::countUsageByType($userId, 2592000, 'text2pic', 'paid');
```

#### Test Rate Limiting
```php
$result = XSControl::checkOperationLimit(['BUSERID' => $userId], 'text2pic');
```

## Maintenance

### Regular Tasks
- Monitor error logs for security violations
- Review usage patterns for abuse detection
- Update subscription tier limits as needed
- Clean old BUSELOG entries (optional, for performance)

### Database Maintenance
- Ensure BCONFIG table has all required rate limit entries
- Monitor BUSELOG table growth
- Index optimization for query performance

### Security Audits
- Regular review of emergency fallback triggers
- Validation of input sanitization
- Testing of edge cases and malicious inputs

## Future Enhancements

### Potential Improvements
- Rate limiting by IP address for anonymous users
- Dynamic limit adjustments based on system load
- Usage analytics and reporting dashboard
- API rate limiting with token bucket algorithm
- Geographical rate limiting considerations

### Scalability Considerations
- Redis caching for high-frequency limit checks
- Database sharding for large user bases
- Asynchronous usage logging
- CDN integration for static limit responses

---

## Summary

The Rate Limiting System provides comprehensive, secure, and scalable control over AI operation access. It successfully balances user experience with resource protection through intelligent limit enforcement, graceful degradation, and robust security measures. The dual-layer approach ensures fair usage while providing flexibility for different subscription tiers and maintaining strict security boundaries.
