// Dates are shown localised while ISO strings stay in state (Frontend Program List, Section 12).

export function formatDate(iso: string | null | undefined, withTime = false): string {
  if (!iso) return '—';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '—';
  return new Intl.DateTimeFormat(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    ...(withTime ? { hour: '2-digit', minute: '2-digit' } : {}),
  }).format(d);
}

export function formatInt(n: number | null | undefined): string {
  return n === null || n === undefined ? '—' : new Intl.NumberFormat().format(n);
}

export function formatPct(fraction: number | null | undefined, digits = 1): string {
  return fraction === null || fraction === undefined ? '—' : `${(fraction * 100).toFixed(digits)}%`;
}

export function formatBytes(bytes: number | null | undefined): string {
  if (!bytes) return '—';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

/** Axis/metric number with the unit from the backend spec; never a hard-coded currency. */
export function formatMeasure(
  value: number | string | null | undefined,
  unit?: string | null,
): string {
  if (value === null || value === undefined || value === '') return '—';
  const n = typeof value === 'number' ? value : Number(value);
  const text = Number.isFinite(n)
    ? new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(n)
    : String(value);
  return unit ? `${text} ${unit}` : text;
}

export function newIdempotencyKey(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto
    ? crypto.randomUUID()
    : `${Date.now()}-${Math.random()}`;
}

/** Shortens long category labels for chart axes; the tooltip still shows the full value. */
export function truncateLabel(value: unknown, max: number): string {
  const text = String(value ?? '');
  if (text.length <= max) return text;
  return `${text.slice(0, Math.max(1, max - 1)).trimEnd()}…`;
}

/**
 * Long category labels that share a long common prefix (auction round titles that differ only in the lot) are
 * shown by their distinguishing tail with a leading ellipsis. The full value stays available for tooltips.
 */
export function distinguishLabels(labels: string[]): string[] {
  if (labels.length < 2) return labels;
  const shortest = Math.min(...labels.map((l) => l.length));
  if (shortest <= 24) return labels;
  let prefix = 0;
  for (let i = 0; i < shortest; i++) {
    const ch = labels[0][i];
    if (labels.every((l) => l[i] === ch)) prefix = i + 1;
    else break;
  }
  let cut = prefix;
  while (cut > 0 && !/[\s(,/-]/.test(labels[0][cut - 1])) cut--;
  if (cut < 12) return labels;
  return labels.map((l) => `…${l.slice(cut).replace(/^[\s(,]+/, '')}`);
}
