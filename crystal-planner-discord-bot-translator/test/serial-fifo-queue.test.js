import test from 'node:test';
import assert from 'node:assert/strict';
import { SerialFifoQueue } from '../src/queue/SerialFifoQueue.js';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

test('SerialFifoQueue executes asynchronous tasks strictly in enqueue order', async () => {
  const queue = new SerialFifoQueue();
  const events = [];

  const first = queue.enqueue(async () => {
    events.push('A:start');
    await sleep(30);
    events.push('A:end');
  });

  const second = queue.enqueue(async () => {
    events.push('B:start');
    await sleep(1);
    events.push('B:end');
  });

  const third = queue.enqueue(async () => {
    events.push('C:start');
    events.push('C:end');
  });

  await Promise.all([first, second, third]);

  assert.deepEqual(events, [
    'A:start', 'A:end',
    'B:start', 'B:end',
    'C:start', 'C:end'
  ]);
  assert.equal(queue.size, 0);
});

test('SerialFifoQueue continues after a failed task', async () => {
  const queue = new SerialFifoQueue();
  const events = [];

  const failed = queue.enqueue(async () => {
    events.push('A');
    throw new Error('expected failure');
  });

  const next = queue.enqueue(async () => {
    events.push('B');
  });

  await assert.rejects(failed, /expected failure/);
  await next;

  assert.deepEqual(events, ['A', 'B']);
  assert.equal(queue.size, 0);
});
