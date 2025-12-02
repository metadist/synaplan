# Limit Reached Modal - Usage Example

## In any Vue component:

```vue
<template>
  <div>
    <!-- Your content -->
    <button @click="sendMessage">Send Message</button>

    <!-- Limit Modal -->
    <LimitReachedModal
      :is-open="showLimitModal"
      :limit-type="limitData?.limitType || 'lifetime'"
      :action-type="limitData?.actionType || 'MESSAGES'"
      :used="limitData?.used || 0"
      :current-limit="limitData?.limit || 0"
      :reset-time="limitData?.resetTime"
      :user-level="limitData?.userLevel || 'NEW'"
      :phone-verified="limitData?.phoneVerified || false"
      @close="closeLimitModal"
      @upgrade="handleUpgrade"
      @verify-phone="handleVerifyPhone"
    />
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import LimitReachedModal from '@/components/common/LimitReachedModal.vue'
import { useLimitCheck } from '@/composables/useLimitCheck'

const { showLimitModal, limitData, checkAndShowLimit, closeLimitModal } = useLimitCheck()

const sendMessage = async () => {
  try {
    const response = await fetch('/api/v1/messages', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ text: 'Hello' })
    })

    if (response.status === 429) {
      // Rate limit exceeded
      const data = await response.json()
      
      checkAndShowLimit({
        allowed: false,
        limitType: data.limit_type || 'lifetime',
        actionType: 'MESSAGES',
        used: data.used || 0,
        limit: data.limit || 0,
        remaining: 0,
        resetTime: data.reset_at || null,
        userLevel: data.user_level || 'NEW',
        phoneVerified: data.phone_verified || false
      })
      return
    }

    // Process successful response
    const data = await response.json()
    // ...
  } catch (error) {
    console.error('Failed to send message:', error)
  }
}

const handleUpgrade = () => {
  // Redirect to subscription page (handled by modal)
}

const handleVerifyPhone = () => {
  // Redirect to phone verification (handled by modal)
}
</script>
```

## API Response Format

When rate limit is exceeded (429 status):

```json
{
  "success": false,
  "error": "Rate limit exceeded",
  "limit": 50,
  "used": 50,
  "remaining": 0,
  "reset_at": 1234567890,
  "limit_type": "lifetime",
  "user_level": "NEW",
  "phone_verified": false
}
```

## Scenarios

### 1. NEW User (Lifetime Limit)
- Shows: "Free Limit Reached"
- No reset time
- Shows upgrade benefits
- Shows "Upgrade Now" button

### 2. ANONYMOUS User (Not Phone Verified)
- Shows: "Free Limit Reached"
- No reset time
- Shows "Verify Phone Number" button
- Shows "Upgrade Now" button

### 3. PRO/TEAM/BUSINESS (Hourly Limit)
- Shows: "Hourly Limit Reached"
- Shows reset time (e.g., "Resets in 1h 25m")
- Shows "Upgrade Now" button (for higher tier)

### 4. PRO/TEAM/BUSINESS (Monthly Limit)
- Shows: "Monthly Limit Reached"
- Shows reset time (e.g., "Resets in 5d 3h")
- Shows "Upgrade Now" button
```

