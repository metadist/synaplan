import assert from 'node:assert/strict'
import test from 'node:test'

import { createTurnAssembler, spokenText, TURN_SETTLE_MS } from '../src/turns.js'

function recorder(options = {}) {
  let clock = 0
  const events = []
  const turns = createTurnAssembler((turn) => events.push(turn), { now: () => clock, ...options })
  return {
    turns,
    events,
    finals: () => events.filter((turn) => turn.final).map((turn) => [turn.role, turn.text]),
    at: (ms) => {
      clock = ms
    },
  }
}

test('a turn streams every piece and ends once with the whole text', () => {
  const { turns, events, at } = recorder()
  turns.append('assistant', 'Hello ')
  at(200)
  turns.append('assistant', 'there.')
  turns.commit('assistant')
  assert.deepEqual(
    events.map((turn) => [turn.text, turn.final]),
    [
      ['Hello', false],
      ['Hello there.', false],
      ['Hello there.', true],
    ],
  )
})

test('the other speaker ends a turn only after it has gone quiet', () => {
  const { turns, finals, at } = recorder()
  turns.append('user', 'What time ')
  at(400)
  turns.append('assistant', 'It')
  assert.deepEqual(finals(), [])
  at(400 + TURN_SETTLE_MS)
  turns.append('assistant', ' is four.')
  assert.deepEqual(finals(), [['user', 'What time']])
})

test('sound tags are not words and never become a message', () => {
  const { turns, events } = recorder()
  turns.append('user', '[burp] [groan]')
  turns.commit('user')
  turns.append('assistant', '[hum] One moment.')
  turns.commit('assistant')
  assert.deepEqual(
    events.map((turn) => [turn.role, turn.text, turn.final]),
    [
      ['assistant', 'One moment.', false],
      ['assistant', 'One moment.', true],
    ],
  )
  assert.equal(spokenText('Okay [laughs] fine'), 'Okay fine')
})

test('a speaker who comes back after the other one starts a new turn', () => {
  const { turns, finals, at } = recorder()
  turns.append('user', 'First question.')
  at(100)
  turns.append('assistant', 'Answer.')
  at(100 + TURN_SETTLE_MS)
  turns.append('user', 'Second question.')
  turns.commitAll()
  assert.deepEqual(finals(), [
    ['user', 'First question.'],
    ['assistant', 'Answer.'],
    ['user', 'Second question.'],
  ])
})

test('a finished reply closes the open sentence so the next one is its own message', () => {
  const { turns, finals, at } = recorder()
  turns.append('user', 'Ich wollte mal kurz fragen')
  at(200)
  turns.append('assistant', 'Ja, bitte?')
  turns.commit('assistant')
  at(500)
  turns.append('user', 'Kannst du mir was über den Zweiten Weltkrieg erzählen?')
  turns.commit('user')
  assert.deepEqual(finals(), [
    ['user', 'Ich wollte mal kurz fragen'],
    ['assistant', 'Ja, bitte?'],
    ['user', 'Kannst du mir was über den Zweiten Weltkrieg erzählen?'],
  ])
})

test('a pause by the same speaker stays one turn', () => {
  const { turns, finals, at } = recorder()
  turns.append('assistant', 'One moment. ')
  at(6000)
  turns.append('assistant', 'Here it is.')
  turns.commitAll()
  assert.deepEqual(finals(), [['assistant', 'One moment. Here it is.']])
})

test('the provider text wins and a repeated done is ignored', () => {
  const { turns, finals } = recorder({ autoCommit: false })
  turns.replace('user', 'What tim')
  turns.commit('user', 'What time is it?')
  turns.commit('user', 'What time is it?')
  assert.deepEqual(finals(), [['user', 'What time is it?']])
})

test('explicit providers never end a turn on their own', () => {
  const { turns, finals, at } = recorder({ autoCommit: false })
  turns.append('user', 'Hello')
  at(10 * TURN_SETTLE_MS)
  turns.append('assistant', 'Hi')
  assert.deepEqual(finals(), [])
  assert.equal(turns.text('user'), 'Hello')
})
