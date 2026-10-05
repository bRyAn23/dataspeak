import { afterEach, describe, expect, it, vi } from 'vitest';
import { logger } from './logger';

const SENTINEL = 'SENTINEL-CELL-ค่า-တန်ဖိုး-9471';

describe('logger privacy baseline', () => {
  afterEach(() => vi.restoreAllMocks());

  it('never emits forbidden keys or object values', () => {
    const spy = vi.spyOn(console, 'info').mockImplementation(() => {});
    logger.info('upload', {
      analysis_id: 'a1',
      file: SENTINEL,
      rows: SENTINEL,
      nested: { x: SENTINEL } as unknown as string,
    });
    const output = spy.mock.calls.map((c) => String(c[0])).join('\n');
    expect(output).not.toContain(SENTINEL);
    expect(output).toContain('"analysis_id":"a1"');
  });
});
