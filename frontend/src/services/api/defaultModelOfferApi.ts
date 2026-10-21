import type { z } from 'zod'
import { GetApiConfigModelsDefaultOffersResponseSchema } from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export type DefaultModelOffersResponse = z.infer<
  typeof GetApiConfigModelsDefaultOffersResponseSchema
>
export type DefaultModelOffer = DefaultModelOffersResponse['offers'][number]

/** Defaults unreachable more than twice in the last hour. Reading this changes nothing. */
export const getDefaultModelOffers = async (): Promise<DefaultModelOffersResponse> => {
  return httpClient('/api/v1/config/models/default-offers', {
    schema: GetApiConfigModelsDefaultOffersResponseSchema,
  })
}
