import '@testing-library/jest-dom/vitest';
import { afterAll, afterEach, beforeAll } from 'vitest';
import { server } from './msw/server';
import { resetState } from './msw/handlers';
import { session } from '@/lib/session';
import { Blob as NodeBlob, File as NodeFile } from 'node:buffer';

// jsdom's File/Blob/FormData are not accepted by Node's fetch (used by MSW in tests). Use Node's
// implementations so multipart uploads behave as they do in a browser.
const nodeFormData = await new Response('a=1', {
  headers: { 'content-type': 'application/x-www-form-urlencoded' },
}).formData();
Object.defineProperty(globalThis, 'FormData', {
  value: nodeFormData.constructor,
  writable: true,
  configurable: true,
});
Object.defineProperty(globalThis, 'File', { value: NodeFile, writable: true, configurable: true });
Object.defineProperty(globalThis, 'Blob', { value: NodeBlob, writable: true, configurable: true });

// jsdom lacks these; provide minimal versions for Recharts and <dialog>.
class RO {
  observe() {}
  unobserve() {}
  disconnect() {}
}
Object.defineProperty(globalThis, 'ResizeObserver', { value: RO, writable: true });
if (!HTMLDialogElement.prototype.showModal) {
  HTMLDialogElement.prototype.showModal = function () {
    this.setAttribute('open', '');
  };
  HTMLDialogElement.prototype.close = function () {
    this.removeAttribute('open');
  };
}

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }));
afterEach(() => {
  server.resetHandlers();
  resetState();
  session.clear();
});
afterAll(() => server.close());

// Recharts measures angled tick text through a canvas; jsdom has none. Returning null keeps the layout path quiet.
Object.defineProperty(HTMLCanvasElement.prototype, 'getContext', {
  value: () => null,
  writable: true,
});
