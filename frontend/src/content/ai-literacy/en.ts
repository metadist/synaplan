export interface LiteracySection {
  title: string
  body: string
}

export const sections: LiteracySection[] = [
  {
    title: 'What a model is',
    body: 'A model predicts the next words from examples. It does not look things up, and it does not know what is true.',
  },
  {
    title: 'Wrong answers',
    body: 'A fluent answer can still be wrong. Check anything you would act on, especially numbers, names, and quotes.',
  },
  {
    title: 'Sources',
    body: 'Ask for the source, then open it. A citation the model invented is still an invention.',
  },
  {
    title: 'Personal data',
    body: 'Do not paste secrets, customer records, or health data unless your organisation has said that this tool may hold them.',
  },
  {
    title: 'Where the work runs',
    body: 'Some models run on this server. Others send the text to an outside provider. The model picker tells you which.',
  },
  {
    title: 'Prompts',
    body: 'Say what you want, who it is for, and what must be left out. A short prompt gets a vague answer.',
  },
  {
    title: 'Approvals',
    body: 'A draft is not a decision. Send, publish, or file only after a person has read it.',
  },
  {
    title: 'Bias',
    body: 'Models repeat patterns from their training. Read the result as if it might favour one group or one wording.',
  },
  {
    title: 'When not to use it',
    body: 'Do not use it for a final legal, medical, or financial decision, or for anything you cannot check.',
  },
  {
    title: 'Who to ask',
    body: 'If you are unsure whether a task belongs here, ask the person who runs this Synaplan for your organisation.',
  },
]
