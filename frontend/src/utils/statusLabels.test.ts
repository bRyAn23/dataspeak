import { describe, expect, it } from 'vitest';
import { ANALYSIS_STAGES, ANALYSIS_STATUSES } from '@/types/analysis.types';
import { STAGE_LABELS, STATUS_LABELS, describeReason } from './statusLabels';
import { continuePath, furthestStep } from './steps';

describe('status labels (Section 12 behaviour rules)', () => {
  it('maps every dictionary status and stage to a business label that is not the raw enum', () => {
    for (const s of ANALYSIS_STATUSES) {
      expect(STATUS_LABELS[s]).toBeTruthy();
      expect(STATUS_LABELS[s]).not.toBe(s);
    }
    for (const s of ANALYSIS_STAGES) expect(STAGE_LABELS[s]).not.toBe(s);
  });

  it('gates steps from status', () => {
    expect(furthestStep('CREATED')).toBe(1);
    expect(furthestStep('VALIDATED')).toBe(2);
    expect(furthestStep('PROFILED')).toBe(3);
    expect(furthestStep('DEFINED')).toBe(4);
    expect(furthestStep('FAILED')).toBe(4);
    expect(furthestStep('REPORTING')).toBe(5); // the report page shows the (re)write progress
    expect(furthestStep('COMPLETED')).toBe(5);
    expect(continuePath('abc', 'PROFILED')).toBe('/analyses/abc/define');
  });

  it('describes validation reason codes', () => {
    expect(describeReason('DIGITS_OUTSIDE_PLACEHOLDER')).toMatch(/number not taken from evidence/);
    expect(describeReason('CAUSAL_LANGUAGE:because_of')).toMatch(/cause/);
  });
});
