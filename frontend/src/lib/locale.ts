// Interface language preference (FEAT-I18N-003). A convenience setting, not data: localStorage is allowed here only.
export const LOCALES = ['en', 'th', 'zh', 'my'] as const;
export type Locale = (typeof LOCALES)[number];
export const LOCALE_NAMES: Record<Locale, string> = {
  en: 'English',
  th: 'ไทย',
  zh: '中文',
  my: 'မြန်မာ',
};

const KEY = 'dataspeak.locale';
let current: Locale | null = null;

export function isLocale(value: unknown): value is Locale {
  return typeof value === 'string' && (LOCALES as readonly string[]).includes(value);
}

export function readLocale(): Locale {
  if (current) return current;
  try {
    const stored = typeof localStorage !== 'undefined' ? localStorage.getItem(KEY) : null;
    if (isLocale(stored)) return (current = stored);
  } catch {
    /* storage unavailable */
  }
  const browser = typeof navigator !== 'undefined' ? navigator.language.slice(0, 2) : 'en';
  return (current = isLocale(browser) ? browser : 'en');
}

export function writeLocale(locale: Locale): void {
  current = locale;
  try {
    localStorage.setItem(KEY, locale);
  } catch {
    /* storage unavailable: memory only */
  }
}
