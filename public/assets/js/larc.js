class LarcBus {
  constructor() {
    this.listeners = new Map();
    this.anyListeners = new Set();
  }

  on(eventName, handler) {
    if (!this.listeners.has(eventName)) {
      this.listeners.set(eventName, new Set());
    }

    this.listeners.get(eventName).add(handler);

    return () => {
      this.off(eventName, handler);
    };
  }

  onAny(handler) {
    this.anyListeners.add(handler);

    return () => {
      this.anyListeners.delete(handler);
    };
  }

  off(eventName, handler) {
    if (!this.listeners.has(eventName)) {
      return;
    }

    this.listeners.get(eventName).delete(handler);
  }

  emit(eventName, detail = {}) {
    const payload = {
      event: eventName,
      detail,
      emittedAt: new Date().toISOString(),
    };

    const scoped = this.listeners.get(eventName) || [];
    scoped.forEach((listener) => listener(payload));
    this.anyListeners.forEach((listener) => listener(payload));
  }
}

export const LARC = new LarcBus();
