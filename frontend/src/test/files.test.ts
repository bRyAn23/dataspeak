import { describe, expect, it } from 'vitest';
import { acceptFile, isSpreadsheet } from '@/utils/files';
import { nameFromFile } from '@/utils/naming';

describe('file acceptance (DATA-API-001 mirror)', () => {
  it('accepts csv and xlsx, rejects other types', () => {
    expect(acceptFile(new File(['a,b'], 'sales.csv'))).toBeNull();
    expect(acceptFile(new File(['x'], 'Q1 Sales.XLSX'))).toBeNull();
    expect(acceptFile(new File(['x'], 'sales.xls'))).toMatch(/CSV or Excel/);
    expect(acceptFile(new File([], 'empty.csv'))).toBe('The file is empty.');
    expect(isSpreadsheet('q1.xlsx')).toBe(true);
    expect(nameFromFile('retail_sales_2025.xlsx')).toBe('Retail Sales 2025');
  });
});
