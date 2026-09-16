# Google Tag Manager / Google Analytics Integration

## Overview

Google Tag Manager (GTM) and Google Analytics 4 (GA4) integration for tracking user interactions on login and signup pages. The implementation is **opt-in only** - it only loads when explicitly enabled and configured, ensuring self-hosters are not affected.

## Implementation Date

2024-12-19

## Features

- ✅ Conditional script injection (only if enabled and configured)
- ✅ Supports both Google Tag Manager (`GTM-XXXXXXX`) and Google Analytics 4 (`G-XXXXXXXXXX`)
- ✅ Automatic injection on login and signup pages
- ✅ No impact on self-hosters (disabled by default)
- ✅ Configuration stored in database (Config table)
- ✅ Runtime configuration (no build-time changes needed)

## Architecture

### Backend Changes

**File**: `backend/src/Controller/ConfigController.php`

- Added `googleTag` configuration to `/api/v1/config/runtime` endpoint
- Reads from `BCONFIG` table:
  - `BGROUP = 'GOOGLE_TAG'`
  - `BSETTING = 'ENABLED'` (value: `'1'` to enable, `'0'` or empty to disable)
  - `BSETTING = 'TAG_ID'` (value: Google Tag ID, e.g., `'G-XXXXXXXXXX'` or `'GTM-XXXXXXX'`)
- Uses `BOWNERID = 0` for global configuration
- Updated OpenAPI annotations to document the new config structure

**Response Structure**:
```json
{
  "googleTag": {
    "enabled": true,
    "tagId": "G-XXXXXXXXXX"
  }
}
```

### Frontend Changes

**Files Modified**:
1. `frontend/src/stores/config.ts` - Added Google Tag config getters
2. `frontend/src/composables/useGoogleTag.ts` - New composable for Google Tag injection
3. `frontend/src/views/LoginView.vue` - Added Google Tag auto-injection
4. `frontend/src/views/RegisterView.vue` - Added Google Tag auto-injection
5. `frontend/src/services/api/httpClient.ts` - Updated default config to include Google Tag

**New Composable**: `useGoogleTag.ts`

- `useGoogleTagAuto()` - Automatically injects Google Tag on component mount
- Supports both GTM and GA4 formats
- Includes event tracking helper function
- Only injects if `enabled === true` and `tagId` is not empty

## Configuration

### Database Configuration

To enable Google Tag tracking, set the following values in the `BCONFIG` table:

```sql
-- Enable Google Tag
INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE) 
VALUES (0, 'GOOGLE_TAG', 'ENABLED', '1')
ON DUPLICATE KEY UPDATE BVALUE = '1';

-- Set your Google Tag ID
-- For Google Analytics 4:
INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE) 
VALUES (0, 'GOOGLE_TAG', 'TAG_ID', 'G-XXXXXXXXXX')
ON DUPLICATE KEY UPDATE BVALUE = 'G-XXXXXXXXXX';

-- OR for Google Tag Manager:
INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE) 
VALUES (0, 'GOOGLE_TAG', 'TAG_ID', 'GTM-XXXXXXX')
ON DUPLICATE KEY UPDATE BVALUE = 'GTM-XXXXXXX';
```

### Configuration Values

| Setting | Value | Description |
|---------|-------|-------------|
| `GOOGLE_TAG.ENABLED` | `'1'` or `'0'` | Enable/disable Google Tag tracking |
| `GOOGLE_TAG.TAG_ID` | `'G-XXXXXXXXXX'` or `'GTM-XXXXXXX'` | Google Tag ID (GA4 or GTM) |

## Usage

### Automatic Injection (Login/Register Pages)

The Google Tag script is automatically injected when users visit:
- `/login` - Login page
- `/register` - Registration page

No additional code needed - the pages use `useGoogleTagAuto()` composable.

### Manual Event Tracking

If you need to track custom events, you can use the composable:

```typescript
import { useGoogleTag } from '@/composables/useGoogleTag'

const { trackEvent } = useGoogleTag()

// Track a custom event
trackEvent('custom_event_name', {
  event_category: 'engagement',
  event_label: 'button_click',
  value: 1
})
```

## Implementation Details

### Google Tag Manager (GTM-XXXXXXX)

When a GTM ID is detected, the following scripts are injected:

1. **Head Script**: Google Tag Manager initialization script
2. **Body Noscript**: Fallback iframe for users with JavaScript disabled

### Google Analytics 4 (G-XXXXXXXXXX)

When a GA4 ID is detected, the following scripts are injected:

1. **gtag.js**: Google Analytics 4 library
2. **Initialization Script**: Configures the tracking ID

### Safety Checks

The implementation includes multiple safety checks:

1. **Config Check**: Only injects if `enabled === true` and `tagId` is not empty
2. **Duplicate Prevention**: Checks if script already exists before injecting
3. **Format Validation**: Automatically detects GTM vs GA4 format
4. **Default Values**: Defaults to `enabled: false` and `tagId: ''` if not configured

## Self-Hosting Compatibility

✅ **Fully compatible with self-hosting**:

- Default configuration is disabled (`enabled: false`, `tagId: ''`)
- No scripts are loaded if not configured
- No errors or warnings if configuration is missing
- No build-time dependencies or environment variables required

Self-hosters can simply not configure these values, and the feature will remain inactive.

## Testing

### Manual Testing

1. **Enable Configuration**:
   ```sql
   INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE) 
   VALUES (0, 'GOOGLE_TAG', 'ENABLED', '1');
   
   INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE) 
   VALUES (0, 'GOOGLE_TAG', 'TAG_ID', 'G-TEST123');
   ```

2. **Visit Login/Register Pages**:
   - Navigate to `/login` or `/register`
   - Open browser DevTools → Network tab
   - Verify `gtag.js` or `gtm.js` is loaded (for GA4 or GTM respectively)

3. **Verify in Google Analytics/Tag Manager**:
   - Check Real-Time reports in GA4
   - Verify events are being tracked

### Testing Disabled State

1. **Disable Configuration**:
   ```sql
   UPDATE BCONFIG SET BVALUE = '0' WHERE BGROUP = 'GOOGLE_TAG' AND BSETTING = 'ENABLED';
   ```

2. **Verify No Scripts Loaded**:
   - Visit `/login` or `/register`
   - Check Network tab - no Google Tag scripts should be loaded
   - No console errors

## Files Changed

### Backend
- `backend/src/Controller/ConfigController.php` - Added Google Tag config to runtime endpoint

### Frontend
- `frontend/src/stores/config.ts` - Added Google Tag config getters
- `frontend/src/composables/useGoogleTag.ts` - New composable (created)
- `frontend/src/views/LoginView.vue` - Added `useGoogleTagAuto()`
- `frontend/src/views/RegisterView.vue` - Added `useGoogleTagAuto()`
- `frontend/src/services/api/httpClient.ts` - Updated default config

## Future Enhancements

Potential improvements for future versions:

1. **Additional Pages**: Extend tracking to other pages (dashboard, settings, etc.)
2. **Custom Events**: Track specific user actions (form submissions, button clicks)
3. **User Properties**: Send user metadata to Google Analytics
4. **Conversion Tracking**: Track signup/login conversions
5. **Admin UI**: Add configuration UI in admin panel (instead of database only)

## Notes

- The implementation follows the existing pattern used for reCAPTCHA configuration
- Configuration is runtime-based (no build-time environment variables)
- Compatible with open-source self-hosting use case
- No breaking changes for existing installations

## References

- [Google Tag Manager Documentation](https://developers.google.com/tag-manager)
- [Google Analytics 4 Documentation](https://developers.google.com/analytics/devguides/collection/ga4)

