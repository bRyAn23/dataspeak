// FE-UPL-005 SampleDatasetAction: loads the committed synthetic sample CSV as a File.
import { useState } from 'react';
import { Button } from '@/components/layout/Button';
import { t } from '@/i18n';

const SAMPLE_URL = '/samples/retail_sales_sample.csv';

export function SampleDatasetAction({
  onLoaded,
  disabled,
}: {
  onLoaded: (file: File) => void;
  disabled?: boolean;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = async () => {
    setBusy(true);
    setError(null);
    try {
      const res = await fetch(SAMPLE_URL);
      if (!res.ok) throw new Error('sample unavailable');
      const blob = await res.blob();
      onLoaded(new File([blob], 'retail_sales_sample.csv', { type: 'text/csv' }));
    } catch {
      setError(t('The sample dataset could not be loaded.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="text-sm">
      <Button variant="ghost" onClick={load} loading={busy} disabled={disabled} className="px-2">
        {t('Use the sample retail dataset')}
      </Button>
      {error && (
        <p role="alert" className="mt-1 text-red-600">
          {error}
        </p>
      )}
    </div>
  );
}
