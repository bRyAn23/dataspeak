// FEAT-I18N-003 interface languages. Keys are the English source strings; dictionaries map them to Thai,
// Chinese and Burmese. Missing entries fall back to English. `{name}` tokens are interpolated.
import { readLocale, type Locale } from '@/lib/locale';
import { th } from './th';
import { zh } from './zh';
import { my } from './my';

export type Dictionary = Record<string, string>;
const DICTIONARIES: Record<Locale, Dictionary> = { en: {}, th, zh, my };

let activeLocale: Locale = readLocale();

export type Vars = Record<string, string | number>;

/** Translate anywhere (label tables, wording helpers, components). The provider remounts the tree on change. */
export function t(text: string, vars?: Vars): string {
  const translated = DICTIONARIES[activeLocale][text] ?? text;
  if (!vars) return translated;
  return translated.replace(/\{(\w+)\}/g, (m, k: string) => (k in vars ? String(vars[k]) : m));
}

export function currentLocale(): Locale {
  return activeLocale;
}

/** Internal: the provider (and tests) switch the active dictionary through this. */
export function activateLocale(l: Locale): void {
  activeLocale = l;
}
