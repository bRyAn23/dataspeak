// Privacy-safe structured logger (NFR-OBS-001, FEAT-SEC-002).
// Rule: raw CSV content never reaches the console, telemetry or storage.
// This logger accepts only primitive fields and refuses File/Blob values.

type Primitive = string | number | boolean | null | undefined;
export type LogFields = Record<string, Primitive>;

const FORBIDDEN_KEYS = new Set([
  'file',
  'csv',
  'rows',
  'row',
  'cells',
  'cell',
  'content',
  'sample',
]);

function sanitize(fields: LogFields | undefined): LogFields {
  const out: LogFields = {};
  if (!fields) return out;
  for (const [key, value] of Object.entries(fields)) {
    if (FORBIDDEN_KEYS.has(key.toLowerCase())) {
      out[key] = '[redacted]';
      continue;
    }
    const isBinary = typeof Blob !== 'undefined' && (value as unknown) instanceof Blob;
    if (isBinary || typeof value === 'object') {
      out[key] = '[redacted]';
      continue;
    }
    out[key] = value;
  }
  return out;
}

function emit(level: 'debug' | 'info' | 'warn' | 'error', message: string, fields?: LogFields) {
  if (!import.meta.env.DEV) return; // no client-side log sink in production builds
  const line = {
    timestamp: new Date().toISOString(),
    service: 'dataspeak-frontend',
    level,
    message,
    ...sanitize(fields),
  };
  console[level === 'debug' ? 'log' : level](JSON.stringify(line));
}

export const logger = {
  debug: (m: string, f?: LogFields) => emit('debug', m, f),
  info: (m: string, f?: LogFields) => emit('info', m, f),
  warn: (m: string, f?: LogFields) => emit('warn', m, f),
  error: (m: string, f?: LogFields) => emit('error', m, f),
};
