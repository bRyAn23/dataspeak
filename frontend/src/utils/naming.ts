export function nameFromFile(fileName: string): string {
  return humanize(fileName.replace(/\.(csv|xlsx)$/i, ''));
}

export function humanize(column: string): string {
  return column
    .replace(/[_-]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .replace(/\b\w/g, (c) => c.toUpperCase());
}

/** True when the text uses a script other than Latin (Thai, Myanmar, ...); such headers get English names suggested. */
export function hasNonLatinScript(text: string): boolean {
  return /[^\u0020-\u024F\u2000-\u206F\u20A0-\u20CF\s]/u.test(text);
}
