# Rate Limiting System Documentation

## Overview

The Rate Limiting System is a comprehensive security and resource management solution that controls user access to AI operations (image generation, video generation, audio generation, file analysis) based on their subscription level and usage patterns. The system implements a sophisticated dual-layer approach with subscription-based limits and lifetime free usage limits, combined with intelligent context-aware messaging that adapts to user subscription status, expiration scenarios, and auto-renewal settings.

## System Architecture

### Core Components

1. **XSControl Class** (`app/inc/_xscontrol.php`)
   - Main rate limiting engine
   - User limit retrieval and validation
   - Usage tracking and counting
   - Intelligent context-aware limit messaging
   - Subscription status analysis and edge case handling
   - Auto-renewal awareness for expired subscriptions

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
BPAYMENTDETAILS  -- JSON: subscription status, timestamps, stripe IDs, auto_renew settings
```

**BPAYMENTDETAILS JSON Structure:**
```json
{
  "plan": "PRO",
  "status": "active|deactive",
  "start_timestamp": 1758614400,
  "end_timestamp": 1759228907,
  "stripe_subscription_id": "sub_xxx",
  "stripe_customer_id": "cus_xxx",
  "auto_renew": true|false
}
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
- All operations counted against monthly limits (for true new users)
- All operations counted as lifetime limits (for users with deactivated/expired subscriptions)
- No subscription benefits
- Intelligent messaging based on subscription history

#### Paid Users (PRO/TEAM/BUSINESS)
- Subject to **both** subscription AND free limits
- Subscription limits: Monthly reset, tier-specific
- Free limits: Lifetime bonus, never reset
- Both limits enforced independently
- Only applied when subscription is truly active (status='active' AND current_time <= end_timestamp)

### Intelligent Messaging System

The system provides context-aware messages and actions based on user subscription status:

#### NEW Users with No Subscription History
- **Message**: "Get unlimited access with a subscription 🚀"
- **Action**: Upgrade (links to pricing)
- **Timer**: "never" (lifetime limits)

#### Users with Deactivated Subscriptions
- **Message**: "Reactivate your [PLAN] subscription for unlimited access"
- **Action**: Reactivate (links to account page)
- **Timer**: "never" (lifetime limits)

#### Users with Expired Subscriptions (auto_renew = true)
- **Message**: "Renewal failed. Please update your payment method"
- **Action**: Renew (links to account page)
- **Timer**: "never" (lifetime limits)

#### Users with Expired Subscriptions (auto_renew = false)
- **Message**: "Your [PLAN] subscription expired. Renew or enable auto-renew"
- **Action**: Renew (links to pricing)
- **Timer**: "never" (lifetime limits)

#### Active Paid Users (Exhausted Both Limits)
- **Message**: "All limits exhausted. Consider upgrading your plan"
- **Action**: Upgrade (links to upgrade page)
- **Timer**: Based on next billing cycle

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

3. **Subscription Status Validation**
   - Parse `BPAYMENTDETAILS` JSON for subscription status
   - Validate active status: `status === 'active' AND current_time <= end_timestamp`
   - Handle edge cases: deactive, expired, malformed data

4. **Limit Checking**
   - **NEW Users (or users with inactive subscriptions)**: Check only NEW tier limits (lifetime for ex-subscribers)
   - **Active Paid Users**: Check subscription limits (monthly) first, then free limits (lifetime) if not limited
   - Both checks are independent and enforced
   - Critical security: inactive subscriptions never get paid limit benefits

5. **Usage Counting**
   - **Paid Usage**: Count within timeframe, filter by `BSUBSCRIPTION_ID IS NOT NULL`
   - **Free Usage**: Count lifetime, filter by `BSUBSCRIPTION_ID IS NULL`

6. **Intelligent Response Generation**
   - Analyze user subscription history and current status
   - Generate context-appropriate messages and action buttons
   - Provide accurate timer information (never vs. specific timestamps)
   - Direct users to appropriate actions (upgrade, reactivate, renew)

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
3. Check subscription status (active vs. inactive)
4. For NEW users (or inactive subscriptions): Check only lifetime limits with intelligent messaging
5. For active paid users: Check subscription limits first, then free limits if not exceeded
6. Generate context-aware response with appropriate action buttons and timers

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
**Logic**:
- Returns NULL if `BUSERLEVEL = 'NEW'` (security override)
- Returns NULL if subscription status is not 'active'
- Returns NULL if current time > end_timestamp
- Only returns subscription ID for truly active subscriptions

#### `isActiveSubscription($userId)`
**Purpose**: Determine if user has an active subscription
**Return**: Boolean (true for active, false for inactive/expired)
**Logic**:
- Checks `status === 'active'`
- Validates `current_time <= end_timestamp`
- Critical for security: prevents inactive subscriptions from getting paid benefits

#### `getIntelligentMessageForNewUser($userId, $limitType, $currentCount, $maxCount)`
**Purpose**: Generate context-aware rate limit messages for users with NEW level
**Parameters**:
- `$userId`: User identifier
- `$limitType`: Operation type that was limited
- `$currentCount`: Current usage count
- `$maxCount`: Maximum allowed count

**Return**: Array with intelligent message data
**Logic**:
- Analyzes subscription history from `BPAYMENTDETAILS`
- Handles deactivated, expired, and never-subscribed users differently
- Considers auto_renew settings for expired subscriptions
- Provides appropriate action buttons and URLs

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

1. **Upgrade**: User immediately gains higher limits for current billing cycle
2. **Downgrade**: User retains current billing cycle limits until next renewal
3. **Cancellation (deactive status)**: User immediately falls back to NEW lifetime limits with reactivation message
4. **Expiration (active status, expired timestamp)**:
   - **With auto_renew = true**: Shows "Renewal failed. Update payment method"
   - **With auto_renew = false**: Shows "Subscription expired. Renew or enable auto-renew"
   - User falls back to NEW lifetime limits until renewal
5. **Payment Issues**: User retains access until end_timestamp, then falls back to NEW limits

### Edge Case Handling

#### BUSERLEVEL vs. Subscription Status Conflicts
- If `BUSERLEVEL = 'NEW'` but subscription exists: Treats as NEW (security first)
- If `BUSERLEVEL = 'PRO'` but subscription deactive: Treats as NEW with reactivation message
- If `BUSERLEVEL = 'PRO'` but subscription expired: Treats as NEW with renewal message

#### Malformed Payment Data
- Missing `BPAYMENTDETAILS`: Treats as true NEW user
- Invalid JSON: Treats as true NEW user with upgrade message
- Missing critical fields: Falls back to NEW limits with appropriate message

#### Subscription ID Tracking
- Active subscriptions: Operations tagged with `BSUBSCRIPTION_ID`
- Inactive/expired: Operations tagged with `NULL` (free usage)
- Critical: System never accepts old subscription IDs for inactive accounts

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
- JavaScript receives structured rate limit responses with context-aware data
- Dynamic countdown timers: "never" for lifetime limits, accurate timestamps for active subscriptions
- Intelligent action buttons based on user status:
  - **🚀 Upgrade**: Blue button for new users (links to pricing)
  - **🔄 Reactivate**: Orange button for cancelled subscriptions (links to account)
  - **🆕 Renew**: Green button for expired subscriptions (links to pricing/account)
- Proper handling of `reset_time = 0` vs. actual timestamps
- Browser cache-resistant timer calculations

### API Integration
- RESTful rate limit responses
- Consistent error codes and messages
- Support for multiple client types (web, mobile, API)

### Payment System Integration
- Stripe subscription ID tracking
- Automatic limit updates on subscription changes
- Grace period handling for payment issues

## Troubleshooting

### Current Known Issues

#### User Level Not Updated After Subscription Expiration
- **Symptoms**: User shows `BUSERLEVEL = 'PRO'` but subscription is expired
- **Current behavior**: System correctly treats them as NEW user despite wrong level
- **Root cause**: `BUSERLEVEL` not automatically updated when subscription expires
- **Impact**: Cosmetic only - rate limiting still works correctly
- **Manual fix**: Update `BUSERLEVEL` to 'NEW' for expired users

#### Old Subscription IDs in BUSELOG for Expired Users
- **Symptoms**: Recent BUSELOG entries have old `BSUBSCRIPTION_ID` instead of NULL
- **Current behavior**: System correctly blocks new operations
- **Root cause**: Historical entries from when subscription was active
- **Impact**: None on rate limiting, but affects usage analytics
- **Note**: Only new operations get NULL subscription ID correctly

#### Rate Limiting Completely Disabled
- **Cause**: `RATE_LIMITING_ENABLED=false` in `.env` file
- **Solution**: Set `RATE_LIMITING_ENABLED=true` and restart Docker container
- **Impact**: All rate limits completely bypassed

#### Missing Rate Limit Configuration
- **Symptoms**: Users get extremely restrictive emergency limits (1 image/month)
- **Cause**: Missing `RATELIMITS_NEW` or subscription tier limits in `BCONFIG` table
- **Solution**: Re-import `BRATELIMITS_CONFIG.sql` to restore configuration
- **Check**: Verify `RATELIMITS_NEW` exists with 6+ entries in `BCONFIG`

### Debugging Tools

#### Check User's Current Status
```php
$userLevel = XSControl::getUserSubscriptionLevel($userId);
$limits = XSControl::getUserLimits($userId);
$isActive = XSControl::isActiveSubscription($userId);
$subscriptionId = XSControl::getCurrentSubscriptionId($userId);

// Check raw payment details
$userSQL = "SELECT BUSERLEVEL, BPAYMENTDETAILS FROM BUSER WHERE BID = $userId";
$userRow = DB::FetchArr(DB::Query($userSQL));
$paymentDetails = json_decode($userRow['BPAYMENTDETAILS'], true);
```

#### Check Usage Counts
```php
$freeUsage = XSControl::countUsageByType($userId, 0, 'text2pic', 'free');
$paidUsage = XSControl::countUsageByType($userId, 2592000, 'text2pic', 'paid');

// Check recent BUSELOG entries
$logSQL = "SELECT BOPERATIONTYPE, BSUBSCRIPTION_ID, FROM_UNIXTIME(BTIMESTAMP) as created 
           FROM BUSELOG WHERE BUSERID = $userId ORDER BY BTIMESTAMP DESC LIMIT 10";
```

#### Test Rate Limiting
```php
$result = XSControl::checkOperationLimit(['BUSERID' => $userId], 'text2pic');
print_r($result); // Check message, action_type, reset_time, etc.

// Test intelligent messaging
$intelligentMsg = XSControl::getIntelligentMessageForNewUser($userId, 'IMAGES', 5, 2);
print_r($intelligentMsg);
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

The Rate Limiting System provides comprehensive, secure, and scalable control over AI operation access with intelligent context-aware messaging. It successfully balances user experience with resource protection through sophisticated limit enforcement, graceful degradation, and robust security measures. 

### Key Strengths

- **Dual-Layer Security**: Independent subscription and lifetime free limits
- **Intelligent Messaging**: Context-aware responses based on subscription history and auto-renewal settings
- **Bulletproof Security**: Invalid subscriptions never receive paid benefits
- **Edge Case Handling**: Comprehensive coverage of expiration, cancellation, and malformed data scenarios
- **User Experience**: Appropriate action buttons and accurate timer displays
- **Scalability**: Database-driven configuration with emergency fallbacks

### Security Guarantees

- **Zero Privilege Escalation**: Inactive subscriptions cannot access paid limits
- **Fail-Safe Design**: All errors default to most restrictive limits
- **Input Validation**: All user inputs validated before processing
- **Subscription Isolation**: BUSELOG tracking prevents cross-subscription usage counting

The system ensures that users always receive appropriate, actionable guidance while maintaining strict security boundaries and preventing any form of privilege escalation or limit bypass.
