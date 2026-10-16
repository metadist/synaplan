export interface ToolSummaryInput {
  count: number
  activeName: string | null
}

export function toolsSummaryLabel(
  input: ToolSummaryInput,
  translate: (key: string, params: Record<string, string | number>) => string
): string {
  if (input.activeName) {
    return translate('chatInput.toolsSummaryActive', {
      count: input.count,
      name: input.activeName,
    })
  }
  return translate('chatInput.toolsSummary', { count: input.count })
}
