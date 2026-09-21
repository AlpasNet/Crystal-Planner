/**
 * Strict in-process FIFO queue.
 *
 * Tasks are started one at a time, in the exact order enqueue() is called.
 * A failed task does not block the tasks that follow it.
 */
export class SerialFifoQueue {
  constructor({ onStart, onFinish } = {}) {
    this.tail = Promise.resolve();
    this.pending = 0;
    this.sequence = 0;
    this.onStart = onStart;
    this.onFinish = onFinish;
  }

  get size() {
    return this.pending;
  }

  enqueue(task, label = 'task') {
    if (typeof task !== 'function') {
      return Promise.reject(new TypeError('FIFO task must be a function.'));
    }

    const id = ++this.sequence;
    this.pending += 1;

    const run = async () => {
      this.onStart?.({ id, label, pending: this.pending });
      try {
        return await task();
      } finally {
        this.pending -= 1;
        this.onFinish?.({ id, label, pending: this.pending });
      }
    };

    const result = this.tail.then(run, run);

    // Keep an always-resolved private tail so one rejection cannot poison
    // the queue. The caller still receives the original rejecting promise.
    this.tail = result.catch(() => undefined);
    return result;
  }

  async drain() {
    await this.tail;
  }
}
