// FE-PG-006 DataDefinitionPage: map columns to business meanings (DEF-API-001).
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Languages } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { definitionService } from '@/services/definition.service';
import { ApiError } from '@/services/apiClient';
import { keys, useProfile } from '@/hooks/useAnalyses';
import { useAnalysisGuard } from './useAnalysisGuard';
import { AnalysisStepper } from '@/components/layout/AnalysisStepper';
import { PageHeader } from '@/components/layout/PageHeader';
import { LoadingState } from '@/components/layout/LoadingState';
import { ErrorState } from '@/components/layout/ErrorState';
import { FieldDefinitionTable } from '@/components/define/FieldDefinitionTable';
import { DefinitionSaveAction } from '@/components/define/DefinitionSaveAction';
import { initialDefinitions, validateDefinitions } from '@/components/define/DefinitionValidation';
import { Button } from '@/components/layout/Button';
import { hasNonLatinScript } from '@/utils/naming';
import type { FieldDefinition, SuggestionMeta } from '@/types/dataset.types';
import { t } from '@/i18n';

export function DataDefinitionPage() {
  const { id, query: analysis } = useAnalysisGuard(3);
  const profile = useProfile(id, Boolean(analysis.data));
  const navigate = useNavigate();
  const qc = useQueryClient();
  const [edited, setEdited] = useState<FieldDefinition[] | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saveError, setSaveError] = useState<unknown>(null);
  const [saving, setSaving] = useState(false);
  const [naming, setNaming] = useState<{
    status: 'idle' | 'loading' | 'done' | 'failed';
    meta?: SuggestionMeta;
  }>({ status: 'idle' });
  // Suggested definitions are derived from the profile until the user edits a row.
  const defs: FieldDefinition[] | null =
    edited ?? (profile.data ? initialDefinitions(profile.data.columns) : null);

  // Headers in Thai, Myanmar, ... would otherwise appear verbatim in the English report. Profiling already
  // suggested English names and meanings (FEAT-DEF-003); the page only asks again when a name is still foreign.
  const foreignHeaders = useMemo(
    () => profile.data?.columns.some((c) => hasNonLatinScript(c.name)) ?? false,
    [profile.data],
  );
  const needsEnglish = useMemo(
    () => defs?.some((d) => hasNonLatinScript(d.business_name)) ?? false,
    [defs],
  );
  const profiledByAi = profile.data?.suggestions?.ai_status === 'ready';

  const suggestNames = useCallback(async () => {
    const columns = profile.data?.columns;
    if (!columns) return;
    setNaming({ status: 'loading' });
    try {
      const result = await definitionService.suggestNames(id);
      setEdited((prev) =>
        (prev ?? initialDefinitions(columns)).map((d) => {
          const s = result.data.find((x) => x.column === d.column);
          // Only replace rows the user has not edited themselves.
          if (!s || !(d.is_suggested || hasNonLatinScript(d.business_name))) return d;
          return {
            ...d,
            business_name: s.business_name,
            semantic_type: s.semantic_type,
            unit: s.unit ?? '',
            aggregation: s.aggregation,
            description: s.description ?? d.description,
            is_suggested: true,
            name_source: s.source,
          };
        }),
      );
      setNaming({ status: 'done', meta: result.meta });
    } catch {
      setNaming({ status: 'failed' });
    }
  }, [id, profile.data]);

  // Run the suggestion once when the page opens with untouched definitions (deferred, outside the render pass).
  const autoRan = useRef(false);
  useEffect(() => {
    if (!profile.data || !needsEnglish || autoRan.current || edited !== null) return;
    autoRan.current = true;
    const timer = setTimeout(() => void suggestNames(), 0);
    return () => clearTimeout(timer);
  }, [profile.data, needsEnglish, edited, suggestNames]);

  const update = (index: number, patch: Partial<FieldDefinition>) => {
    setEdited((prev) => (prev ?? defs ?? []).map((d, i) => (i === index ? { ...d, ...patch } : d)));
    setErrors((prev) => {
      const next = { ...prev };
      Object.keys(next).forEach((k) => k.startsWith(`definitions.${index}.`) && delete next[k]);
      delete next.form;
      return next;
    });
  };

  const save = async () => {
    if (!defs) return;
    const clientErrors = validateDefinitions(defs);
    setErrors(clientErrors);
    if (Object.keys(clientErrors).length) return;
    setSaving(true);
    setSaveError(null);
    try {
      const payload = defs.map((d) => ({
        column: d.column,
        business_name: d.business_name.trim(),
        description: d.description?.trim() || undefined,
        semantic_type: d.semantic_type,
        unit: d.unit?.trim() || undefined,
        aggregation: d.aggregation ?? undefined,
      }));
      await definitionService.save(id, payload as FieldDefinition[]);
      await Promise.all([
        qc.invalidateQueries({ queryKey: keys.analysis(id) }),
        qc.invalidateQueries({ queryKey: keys.profile(id) }),
      ]);
      navigate(`/analyses/${id}/analyze`);
    } catch (err) {
      if (err instanceof ApiError && err.status === 422)
        setErrors({ ...err.fieldErrors(), form: err.message });
      else setSaveError(err);
    } finally {
      setSaving(false);
    }
  };

  return (
    <>
      <AnalysisStepper current={3} analysisId={id} status={analysis.data?.status} />
      <PageHeader
        title={analysis.data?.name ?? t('Define fields')}
        subtitle={t(
          'Give each column a business name and meaning. Measures are analysed; identifiers and free text are left out.',
        )}
      />
      {(analysis.isLoading || profile.isLoading) && <LoadingState label={t('Loading columns…')} />}
      {profile.isError && <ErrorState error={profile.error} onRetry={() => profile.refetch()} />}
      {saveError ? (
        <ErrorState error={saveError} onRetry={save} title={t('Definitions were not saved')} />
      ) : null}
      {profile.data && defs && (
        <div className="space-y-5">
          {(foreignHeaders || profiledByAi) && (
            <div
              role="status"
              className="flex flex-col gap-3 rounded-xl border border-brand-100 bg-brand-50 p-4 text-sm text-slate-800 sm:flex-row sm:items-center sm:justify-between"
            >
              <div className="flex items-start gap-3">
                <Languages
                  size={18}
                  className="mt-0.5 shrink-0 text-brand-700"
                  aria-hidden="true"
                />
                <p>
                  {naming.status === 'loading' && t('Asking the AI model for names and meanings…')}
                  {naming.status === 'done' &&
                    (naming.meta?.ai_status === 'ready'
                      ? t(
                          'Names, meanings, units and aggregations were suggested{model}. They decide what is analysed and charted, so check them before saving.',
                          {
                            model: naming.meta.model
                              ? t(' by the AI model ({model})', { model: naming.meta.model })
                              : '',
                          },
                        )
                      : t(
                          'The AI model is not available right now. Type English business names and pick the meaning yourself so the report reads well.',
                        ))}
                  {naming.status === 'failed' &&
                    t(
                      'Suggestions could not be fetched right now. Type English business names and pick the meaning yourself.',
                    )}
                  {naming.status === 'idle' &&
                    (profiledByAi
                      ? t(
                          'Names and meanings were suggested when the file was profiled{model}. They decide what is analysed and charted, so check them before saving.',
                          {
                            model: profile.data?.suggestions?.model
                              ? t(' (AI model {model})', { model: profile.data.suggestions.model })
                              : '',
                          },
                        )
                      : t(
                          'Some headers are in another language. English business names make the report easier to read.',
                        ))}
                </p>
              </div>
              <Button
                variant="secondary"
                onClick={suggestNames}
                loading={naming.status === 'loading'}
                disabled={saving}
              >
                {t('Suggest again')}
              </Button>
            </div>
          )}
          <FieldDefinitionTable
            columns={profile.data.columns}
            definitions={defs}
            errors={errors}
            onChange={update}
            disabled={saving}
          />
          <DefinitionSaveAction onSave={save} saving={saving} formError={errors.form} />
        </div>
      )}
    </>
  );
}
