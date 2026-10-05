// File-level checks mirroring DATA-API-001 controls (extension, non-empty, size).
import { t } from '@/i18n';

export const MAX_UPLOAD_MB = 50;
/** CSV is native; an Excel workbook is converted to CSV by the API (the worksheet with the largest table). */
export const ACCEPTED_EXTENSIONS = ['.csv', '.xlsx'] as const;

export function isSpreadsheet(fileName: string): boolean {
  return /\.xlsx$/i.test(fileName);
}

export function acceptFile(file: File): string | null {
  if (!/\.(csv|xlsx)$/i.test(file.name)) return t('Only CSV or Excel (.xlsx) files are supported.');
  if (file.size === 0) return t('The file is empty.');
  if (file.size > MAX_UPLOAD_MB * 1024 * 1024)
    return t('The file exceeds {mb} MB.', { mb: MAX_UPLOAD_MB });
  return null;
}
