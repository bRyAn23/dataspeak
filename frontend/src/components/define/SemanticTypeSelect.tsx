// FE-DEF-002 SemanticTypeSelect: choose the business meaning of a column (dictionary enum values).
import { SEMANTIC_TYPES, type SemanticType } from '@/types/dataset.types';
import { SEMANTIC_TYPE_LABELS } from '@/utils/statusLabels';
import { t as translate } from '@/i18n';

interface Props {
  id: string;
  value: SemanticType;
  onChange: (value: SemanticType) => void;
  disabled?: boolean;
}

export function SemanticTypeSelect({ id, value, onChange, disabled }: Props) {
  return (
    <select
      id={id}
      value={value}
      disabled={disabled}
      onChange={(e) => onChange(e.target.value as SemanticType)}
      className="w-full px-2.5 py-2 text-sm rounded-lg border border-slate-300 bg-white focus:outline-none focus:ring-2 focus:ring-brand-700"
    >
      {SEMANTIC_TYPES.map((t) => (
        <option key={t} value={t}>
          {translate(SEMANTIC_TYPE_LABELS[t])}
        </option>
      ))}
    </select>
  );
}
