# SynaPlan Rate Limiting System Documentation

## System Overview

The SynaPlan rate limiting system provides comprehensive usage control across all platform endpoints, including the main API, WhatsApp integration, and web widget. The system implements a dual-layer security model that differentiates between subscription-based limits and lifetime free limits.

## Performance Optimizations

### NEW User Fast-Path
The system includes critical performance optimizations for NEW users (users without subscriptions):

- **BPAYMENTDETAILS Parsing Skip**: For users with `BUSERLEVEL = 'NEW'`, the system skips expensive LONGTEXT parsing entirely
- **Guaranteed No Subscription**: NEW users cannot have active subscriptions by definition, eliminating unnecessary database operations
- **Direct Timeframe Counting**: NEW users get optimized counting logic without billing cycle calculations

**Performance Benefits:**
- ~80% reduction in LONGTEXT queries for NEW users
- Eliminated JSON parsing overhead for most users
- Faster response times with maintained functionality
- Reduced database load and memory usage

**Functions Optimized:**
- `isActiveSubscription()`: Returns false immediately for NEW users
- `countInCurrentBillingCycle()`: Uses direct timeframe counting for NEW users

## System Architecture

### Dual-Layer Security Model
1. **Subscription Limits**: For active paid users (Pro, Team, Business)
2. **Free Limits**: Lifetime limits available to all users (never reset)

### User Classification
- **NEW**: Free users with lifetime limits only
- **PRO/TEAM/BUSINESS**: Paid users with subscription + free limits
- **WIDGET**: Anonymous widget users with restricted limits

## Database Schema

### Core Tables

#### BCONFIG
Stores rate limit configurations per user level:
```sql
BGROUP = 'RATELIMITS_NEW'        -- Free user limits
BGROUP = 'RATELIMITS_PRO'        -- Pro subscription limits  
BGROUP = 'RATELIMITS_TEAM'       -- Team subscription limits
BGROUP = 'RATELIMITS_BUSINESS'   -- Business subscription limits
BGROUP = 'RATELIMITS_WIDGET'     -- Widget user limits
```

#### BUSELOG
Tracks all user operations:
- `BUSERID`: User identifier
- `BOPERATIONTYPE`: Operation type (images, audio, etc.)
- `BSUBSCRIPTION_ID`: Subscription ID (NULL for free usage)
- `BTIMESTAMP`: Operation timestamp

#### BUSER
User data with subscription details:
- `BUSERLEVEL`: User tier (NEW, PRO, TEAM, BUSINESS)
- `BPAYMENTDETAILS`: JSON with subscription status and timestamps

## Rate Limiting Logic

### Limit Enforcement Flow

1. **Rate Limiting Check**: Verify if system is enabled
2. **User Classification**: Determine user level and subscription status
3. **Performance Optimization**: Skip unnecessary parsing for NEW users
4. **Limit Selection**: Choose appropriate limits based on user status
5. **Usage Counting**: Count relevant operations with type filtering
6. **Limit Enforcement**: Block or allow based on current usage

### User Status Determination

```php
// Performance optimized subscription check
if ($userLevel === 'NEW') {
    // Fast path: NEW users cannot have active subscriptions
    return false;
}

// Only parse BPAYMENTDETAILS for non-NEW users
$paymentDetails = json_decode($bpaymentdetails, true);
```

### Dual-Layer Logic

**For Active Paid Users:**
- Check subscription limits against paid usage only
- Free limits available as bonus if subscription exhausted

**For Inactive/NEW Users:**
- Check free limits against free usage only
- Subscription limits not applicable

## Key Functions

### Core Rate Limiting Functions

#### `isActiveSubscription($userId): bool`
**Performance Optimized** - Determines if user has active subscription
- **Fast Path**: Returns false immediately for NEW users
- **Parameters**: User ID
- **Returns**: Boolean subscription status
- **Logic**: Checks BUSERLEVEL first, then validates subscription status and timestamps

#### `checkOperationLimit($msgArr, $operation, $timeframe, $maxCount): array`
Main rate limiting enforcement function
- **Parameters**: Message array, operation type, timeframe, limit
- **Returns**: Array with exceeded status and intelligent messaging
- **Logic**: Implements dual-layer checking with subscription awareness

#### `countInCurrentBillingCycle($userId, $timeframe, $operationType): int`
**Performance Optimized** - Counts operations within billing cycles
- **Fast Path**: Direct timeframe counting for NEW users (skips billing cycle parsing)
- **Parameters**: User ID, timeframe in seconds, operation type
- **Returns**: Current usage count
- **Logic**: Respects subscription billing cycles for paid users, simple timeframe for NEW users

#### `getCurrentSubscriptionId($userId): ?string`
Gets active subscription ID for usage tracking
- **Parameters**: User ID
- **Returns**: Subscription ID or NULL for free usage
- **Logic**: Returns NULL for NEW users or inactive subscriptions

### Intelligent Messaging Functions

#### `getIntelligentRateLimitMessage($userId, $operation, $current, $max, $timeframe): array`
Generates context-aware user messages
- **Parameters**: User details, operation info, usage counts
- **Returns**: Message, action type, URL, reset time
- **Logic**: Analyzes subscription status and provides appropriate guidance

## Configuration

### Environment Variables

#### Rate Limiting Control
```env
RATE_LIMITING_ENABLED=true          # Enable/disable rate limiting
```

#### System URLs (Centralized)
```env
SYSTEM_PRICING_URL=/pricing         # Pricing page
SYSTEM_ACCOUNT_URL=/account         # Account management  
SYSTEM_UPGRADE_URL=/upgrade         # Upgrade page
APP_URL=https://www.synaplan.com    # Base URL for relative paths
```

### Subscription Tier Limits

#### FREE Tier (RATELIMITS_NEW)
```sql
('RATELIMITS_NEW', 'IMAGES_2592000S', '5', 0)     -- 5 images/month
('RATELIMITS_NEW', 'AUDIO_2592000S', '5', 0)      -- 5 audio/month
('RATELIMITS_NEW', 'MESSAGES_86400S', '50', 0)    -- 50 messages/day
```

#### PRO Tier (RATELIMITS_PRO)  
```sql
('RATELIMITS_PRO', 'IMAGES_2592000S', '500', 0)   -- 500 images/month
('RATELIMITS_PRO', 'AUDIO_2592000S', '500', 0)    -- 500 audio/month
('RATELIMITS_PRO', 'MESSAGES_86400S', '1000', 0)  -- 1000 messages/day
```

## Security Features

### Dual-Layer Protection
- Independent validation of subscription and free limits
- Subscription status verification with timestamps
- Proper NULL handling for subscription IDs

### Input Validation
- SQL injection protection with proper escaping
- User ID validation and sanitization
- Operation type validation against capabilities

### Fail-Safe Mechanisms
- Emergency restrictive limits for missing configuration
- Graceful degradation when rate limiting disabled
- Comprehensive error logging and handling

## Operation Flow

### Message Processing
1. **Pre-processing**: Validate user and message
2. **Rate Check**: Call `checkMessagesLimit()`
3. **Limit Enforcement**: Block if exceeded, allow if within limits
4. **Usage Tracking**: Log operation with appropriate subscription ID
5. **Response**: Return result with intelligent messaging

### API Request Flow
1. **Authentication**: Verify user session
2. **Rate Limiting**: Check operation-specific limits
3. **Processing**: Execute requested operation
4. **Logging**: Track usage in BUSELOG
5. **Response**: JSON with limit status and guidance

## Monitoring and Logging

### Usage Tracking
- All operations logged in BUSELOG with timestamps
- Subscription ID tracking for paid vs free usage
- Operation type categorization for granular limits

### Performance Monitoring
- NEW user fast-path reduces database load
- LONGTEXT parsing optimization for subscription checks
- Billing cycle calculations only for paid users

### Error Logging
- Comprehensive error handling with debug output
- Subscription validation failures logged
- Database operation errors captured

## Integration Points

### Main Application (`public/index.php`)
- Dynamic URL injection for frontend JavaScript
- Rate limiting integration in message processing

### API Endpoints (`public/api.php`)
- JSON response format with limit status
- Intelligent action URLs and messages
- Reset time information for frontend timers

### WhatsApp Integration
- Operation-specific rate limiting
- Subscription-aware message formatting
- Proper usage tracking with subscription IDs

### Web Widget (`public/widget.php`)
- Anonymous user rate limiting
- Restricted widget-specific limits
- Dynamic system URL injection

## Troubleshooting

### Critical Issues

#### Rate Limiting Completely Disabled
- **Cause**: `RATE_LIMITING_ENABLED=false` in `.env` file
- **Solution**: Set `RATE_LIMITING_ENABLED=true` and restart Docker container
- **Impact**: All rate limits completely bypassed - CRITICAL SECURITY RISK

#### Missing Rate Limit Configuration (Emergency Mode)
- **Symptoms**: Users get extremely restrictive emergency limits (1 image/month)
- **Cause**: Missing `RATELIMITS_NEW` or subscription tier limits in `BCONFIG` table
- **Solution**: Re-import `BRATELIMITS_CONFIG.sql` to restore configuration
- **Check**: Verify `RATELIMITS_NEW` exists with 6+ entries in `BCONFIG`
- **Note**: System fails safe but user experience severely degraded

### Debugging Tools

#### Check User's Current Status
```php
// Get user level and subscription status
$userLevel = XSControl::getUserLevel($userId);
$isActive = XSControl::isActiveSubscription($userId);
$subscriptionId = XSControl::getCurrentSubscriptionId($userId);
```

#### Check Current Usage
```php
// Check specific operation usage
$currentUsage = XSControl::countInCurrentBillingCycle($userId, 2592000, 'images');
$limits = XSControl::getUserLimits($userId);
```

#### Verify Configuration
```sql
-- Check if rate limit config exists
SELECT * FROM BCONFIG WHERE BGROUP LIKE 'RATELIMITS_%';

-- Check user's recent operations
SELECT * FROM BUSELOG WHERE BUSERID = ? ORDER BY BTIMESTAMP DESC LIMIT 10;
```

## Maintenance

### Regular Tasks
- Monitor BUSELOG table growth and implement archiving
- Review and update rate limit configurations
- Verify subscription status synchronization
- Performance monitoring of optimized paths

### Configuration Updates
- Update limits in BCONFIG table for new requirements
- Modify environment variables for system URLs
- Test rate limiting after configuration changes
- Backup configuration before major updates

## Future Enhancements

### Planned Improvements
- Automatic limit updates on subscription changes
- Grace period handling for payment issues
- Advanced analytics and usage reporting
- Dynamic limit adjustments based on system load

---

*This documentation covers the complete rate limiting system implementation as of the latest updates. For technical support, refer to the troubleshooting section or contact the development team.*
