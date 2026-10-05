import { describe, expect, it } from 'vitest';
import { distinguishLabels, truncateLabel } from '@/utils/format';
import { hasNonLatinScript } from '@/utils/naming';

describe('chart labels and script detection', () => {
  it('shortens long labels with an ellipsis and detects non-Latin headers', () => {
    expect(truncateLabel('North', 10)).toBe('North');
    expect(truncateLabel('ตุลาคม 2569 สรุปยอดรถยนต์ประมูล', 10)).toBe('ตุลาคม 25…');
    expect(truncateLabel(null, 5)).toBe('');
    expect(hasNonLatinScript('ซีซี')).toBe(true);
    expect(hasNonLatinScript('ဒေသ')).toBe(true);
    expect(hasNonLatinScript('Revenue (THB) – café')).toBe(false);
  });

  it('shows long labels that share a prefix by their distinguishing tail', () => {
    const labels = [
      'Car Auction List Sunday, October 4, 2026, Rangsit Branch (Image) (Lot 3) Start Time 12:00:00',
      'Car Auction List Sunday, October 4, 2026, Rangsit Branch (Lot 1 Truck) (10.30 am) Start Time 10:30:00',
    ];
    expect(distinguishLabels(labels)).toEqual([
      '…Image) (Lot 3) Start Time 12:00:00',
      '…Lot 1 Truck) (10.30 am) Start Time 10:30:00',
    ]);
    expect(distinguishLabels(['North', 'South'])).toEqual(['North', 'South']);
    expect(distinguishLabels(['Northern Region Office', 'Southern Region Office'])).toEqual([
      'Northern Region Office',
      'Southern Region Office',
    ]);
  });
});
