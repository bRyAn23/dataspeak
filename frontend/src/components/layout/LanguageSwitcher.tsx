// FE-CMP-009 LanguageSwitcher: interface language (English, Thai, Chinese, Burmese). The report is produced in
// the language active when Analyze (or Rewrite summary) is pressed.
import { Languages } from 'lucide-react';
import { useI18n } from '@/i18n';
import { LOCALES, LOCALE_NAMES, isLocale } from '@/lib/locale';

export function LanguageSwitcher({ compact = false }: { compact?: boolean }) {
  const { locale, setLocale, t } = useI18n();
  return (
    <label className="inline-flex items-center gap-2 text-sm text-slate-600">
      <Languages size={16} aria-hidden="true" />
      {!compact && <span className="sr-only">{t('Language')}</span>}
      <select
        aria-label={t('Language')}
        value={locale}
        onChange={(e) => isLocale(e.target.value) && setLocale(e.target.value)}
        className="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-brand-700"
      >
        {LOCALES.map((l) => (
          <option key={l} value={l}>
            {LOCALE_NAMES[l]}
          </option>
        ))}
      </select>
    </label>
  );
}
