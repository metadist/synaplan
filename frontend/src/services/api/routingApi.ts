import { httpClient } from './httpClient'

/**
 * Submit a routing correction (user reports wrong use case classification).
 */
export async function submitRoutingFeedback(
  messageId: number,
  correctUseCase: string
): Promise<{ success: boolean }> {
  return httpClient<{ success: boolean }>('/api/v1/routing/feedback', {
    method: 'POST',
    body: JSON.stringify({
      message_id: messageId,
      correct_use_case: correctUseCase,
    }),
  })
}

/**
 * Fetch available use case labels for the feedback dropdown.
 */
export async function getRoutingUseCases(): Promise<string[]> {
  const data = await httpClient<{ use_cases: string[] }>('/api/v1/routing/use-cases')
  return data.use_cases
}
