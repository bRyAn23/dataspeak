// FE-PG-004 NewAnalysisPage: create an analysis, upload the CSV (transient) and validate it.
// Supports ?id=<analysis> to (re)upload for an existing analysis.
import { useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { ArrowRight } from 'lucide-react';
import { analysisService } from '@/services/analysis.service';
import { datasetService, type UploadSource } from '@/services/dataset.service';
import { ApiError } from '@/services/apiClient';
import { useAnalysis, useInvalidateAnalysis } from '@/hooks/useAnalyses';
import { AnalysisStepper } from '@/components/layout/AnalysisStepper';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/layout/Button';
import { ErrorState } from '@/components/layout/ErrorState';
import { CsvDropzone } from '@/components/upload/CsvDropzone';
import { AnalysisNameField } from '@/components/upload/AnalysisNameField';
import { nameFromFile } from '@/utils/naming';
import { PrivacyNotice } from '@/components/upload/PrivacyNotice';
import { SampleDatasetAction } from '@/components/upload/SampleDatasetAction';
import { UploadValidationSummary } from '@/components/upload/UploadValidationSummary';
import type { ValidateResponse } from '@/types/dataset.types';
import { t } from '@/i18n';

type Phase = 'idle' | 'creating' | 'uploading' | 'validating' | 'done';

export function NewAnalysisPage() {
  const [params] = useSearchParams();
  const existingId = params.get('id') ?? undefined;
  const existing = useAnalysis(existingId);
  const invalidate = useInvalidateAnalysis();
  const navigate = useNavigate();

  const [file, setFile] = useState<File | null>(null);
  const [name, setName] = useState('');
  const [nameTouched, setNameTouched] = useState(false);
  const [phase, setPhase] = useState<Phase>('idle');
  const [source, setSource] = useState<UploadSource | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [result, setResult] = useState<ValidateResponse | null>(null);
  const [analysisId, setAnalysisId] = useState<string | undefined>(existingId);

  const effectiveName = existingId ? (existing.data?.name ?? '') : name;
  const busy = phase !== 'idle' && phase !== 'done';

  const chooseFile = (f: File | null) => {
    setFile(f);
    setResult(null);
    setError(null);
    if (f && !nameTouched && !existingId) setName(nameFromFile(f.name));
  };

  const start = async () => {
    if (!file) return;
    setError(null);
    setResult(null);
    try {
      let id = analysisId;
      if (!id) {
        setPhase('creating');
        const created = await analysisService.create(effectiveName.trim());
        id = created.id;
        setAnalysisId(id);
      }
      setPhase('uploading');
      setSource(null);
      const uploaded = await datasetService.upload(id, file);
      setSource(uploaded.source);
      setPhase('validating');
      const validation = await datasetService.validate(id);
      setResult(validation);
      await invalidate(id);
      setPhase('done');
    } catch (err) {
      if (err instanceof ApiError && err.status === 422 && err.code === 'CSV_VALIDATION_FAILED') {
        setResult({
          analysis_id: analysisId ?? '',
          status: 'UPLOADED',
          valid: false,
          row_count: null,
          column_count: null,
          warnings: [],
          errors: err.details.map((d) => ({
            code: 'INVALID',
            message: d.issue,
            column: d.field === 'file' ? null : d.field,
          })),
        });
      } else {
        setError(err);
      }
      setPhase('idle');
    }
  };

  const nameError =
    !existingId && nameTouched && !name.trim() ? t('Give the analysis a name.') : undefined;
  const canStart = Boolean(file) && (Boolean(existingId) || Boolean(name.trim())) && !busy;
  const phaseLabel = {
    idle: '',
    creating: t('Creating analysis…'),
    uploading: t('Uploading…'),
    validating: t('Checking the file…'),
    done: '',
  }[phase];

  return (
    <>
      <AnalysisStepper current={1} analysisId={analysisId} status={existing.data?.status} />
      <PageHeader
        title={
          existingId
            ? t('Upload data for {name}', { name: effectiveName || t('this analysis') })
            : t('New analysis')
        }
        subtitle={t(
          'Upload a CSV or Excel file and Data Speak will check it, profile it and guide you through the rest.',
        )}
      />
      <div className="grid gap-6 lg:grid-cols-3">
        <div className="lg:col-span-2 space-y-5">
          {!existingId && (
            <AnalysisNameField
              value={name}
              onChange={(v) => {
                setName(v);
                setNameTouched(true);
              }}
              error={nameError}
              disabled={busy}
            />
          )}
          <CsvDropzone file={file} onChange={chooseFile} disabled={busy} />
          <SampleDatasetAction onLoaded={chooseFile} disabled={busy} />
          {error ? (
            <ErrorState error={error} onRetry={start} title={t('The upload did not go through')} />
          ) : null}
          {source?.format === 'xlsx' ? (
            <p role="status" className="text-xs text-slate-600">
              {t(
                'Excel workbook: using worksheet "{sheet}"{total} with {rows} rows and {columns} columns.',
                {
                  sheet: source.sheet,
                  total:
                    source.sheets_total > 1
                      ? t(' (1 of {n} sheets)', { n: source.sheets_total })
                      : '',
                  rows: source.rows.toLocaleString(),
                  columns: source.columns,
                },
              )}
            </p>
          ) : null}
          {result && <UploadValidationSummary result={result} />}
          <div className="flex items-center justify-between gap-3">
            <p className="text-sm text-slate-500" aria-live="polite">
              {phaseLabel}
            </p>
            {result?.valid && analysisId ? (
              <Button
                onClick={() => navigate(`/analyses/${analysisId}/profile`)}
                icon={<ArrowRight size={16} aria-hidden="true" />}
              >
                {t('Continue to profile')}
              </Button>
            ) : (
              <Button onClick={start} disabled={!canStart} loading={busy}>
                {existingId ? t('Upload and check') : t('Create and upload')}
              </Button>
            )}
          </div>
          {error instanceof ApiError &&
            error.status === 422 &&
            Object.keys(error.fieldErrors()).length > 0 && (
              <ul className="text-sm text-red-700 list-disc pl-5">
                {Object.entries(error.fieldErrors()).map(([k, v]) => (
                  <li key={k}>{v}</li>
                ))}
              </ul>
            )}
        </div>
        <div>
          <PrivacyNotice />
        </div>
      </div>
    </>
  );
}
