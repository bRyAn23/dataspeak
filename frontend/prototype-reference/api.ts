import type { Analysis, DataProfile, FieldDefinition, Report } from '../types';

const delay = (ms: number) => new Promise((r) => setTimeout(r, ms));

export async function login(
  email: string,
  password: string,
): Promise<{ user: { id: string; name: string; email: string } }> {
  await delay(600);
  if (!email || !password) throw new Error('Invalid credentials');
  return { user: { id: 'u1', name: 'Zeyar W.', email } };
}

export async function register(
  name: string,
  email: string,
  _password: string,
): Promise<{ user: { id: string; name: string; email: string } }> {
  await delay(800);
  return { user: { id: 'u1', name, email } };
}

export async function listAnalyses(): Promise<Analysis[]> {
  await delay(400);
  return [
    {
      id: 'a1',
      title: 'Retail Sales 2025',
      fileName: 'retail_sales_2025.csv',
      rows: 48215,
      createdAt: '2025-12-01',
      status: 'completed',
    },
    {
      id: 'a2',
      title: 'Q3 Branch Performance',
      fileName: 'q3_branch_perf.csv',
      rows: 12440,
      createdAt: '2025-09-15',
      status: 'completed',
    },
    {
      id: 'a3',
      title: 'Supplier Costs',
      fileName: 'supplier_costs.csv',
      rows: 3280,
      createdAt: '2025-11-20',
      status: 'defined',
    },
    {
      id: 'a4',
      title: 'Marketing Spend Jan–Jun',
      fileName: 'marketing_jan_jun.csv',
      rows: 8910,
      createdAt: '2025-07-02',
      status: 'failed',
      failedReason: 'Column "spend_usd" has no numeric values in rows 1–840.',
    },
  ];
}

export async function uploadCsv(_file: File): Promise<{ analysisId: string }> {
  await delay(1200);
  return { analysisId: 'a_new' };
}

export async function getProfile(_analysisId: string): Promise<DataProfile> {
  await delay(500);
  return {
    rows: 48215,
    columns: 7,
    duplicates: 37,
    completeness: 98.6,
    columnProfiles: [
      {
        originalName: 'order_date',
        type: 'Date',
        missing: 0,
        missingPct: 0,
        distinct: 365,
        summary: '2025-01-01 – 2025-12-31',
      },
      {
        originalName: 'region',
        type: 'Category',
        missing: 0,
        missingPct: 0,
        distinct: 4,
        summary: 'North, Central, South',
      },
      {
        originalName: 'product_category',
        type: 'Category',
        missing: 212,
        missingPct: 0.4,
        distinct: 6,
        summary: 'Electronics, Home, Fashion',
      },
      {
        originalName: 'revenue_thb',
        type: 'Number',
        missing: 0,
        missingPct: 0,
        distinct: 48215,
        summary: '฿120 – ฿89,400 · avg ฿292.4',
      },
      {
        originalName: 'units',
        type: 'Number',
        missing: 0,
        missingPct: 0,
        distinct: 40,
        summary: '1 – 40 · avg 2.3',
      },
      {
        originalName: 'discount_pct',
        type: 'Number',
        missing: 6121,
        missingPct: 12.7,
        distinct: 46,
        summary: '0 – 45 · avg 8.2',
      },
      {
        originalName: 'customer_id',
        type: 'Text',
        missing: 0,
        missingPct: 0,
        distinct: 18904,
        summary: '18,904 unique values',
      },
    ],
  };
}

export async function getDefaultDefinitions(): Promise<FieldDefinition[]> {
  await delay(300);
  return [
    {
      originalName: 'order_date',
      businessName: 'Order Date',
      description: 'Date when the order was placed',
      role: 'Time',
      unit: 'None',
      suggested: true,
    },
    {
      originalName: 'region',
      businessName: 'Sales Region',
      description: 'Geographic sales region',
      role: 'Dimension',
      unit: 'None',
      suggested: true,
    },
    {
      originalName: 'product_category',
      businessName: 'Product Category',
      description: 'Category of product sold',
      role: 'Dimension',
      unit: 'None',
      suggested: true,
    },
    {
      originalName: 'revenue_thb',
      businessName: 'Revenue',
      description: 'Revenue in Thai Baht',
      role: 'Measure',
      unit: '฿ THB',
      aggregation: 'Sum',
      suggested: true,
    },
    {
      originalName: 'units',
      businessName: 'Units Sold',
      description: 'Number of units sold',
      role: 'Measure',
      unit: 'Units',
      aggregation: 'Sum',
      suggested: true,
    },
    {
      originalName: 'discount_pct',
      businessName: 'Discount',
      description: 'Discount percentage applied',
      role: 'Measure',
      unit: '%',
      aggregation: 'Average',
      suggested: true,
    },
    {
      originalName: 'customer_id',
      businessName: 'Customer ID',
      description: 'Unique customer identifier',
      role: 'Identifier',
      unit: 'None',
      suggested: true,
    },
  ];
}

export async function saveDefinitions(
  _analysisId: string,
  _definitions: FieldDefinition[],
): Promise<void> {
  await delay(400);
}

export async function getStatus(_analysisId: string): Promise<{ stage: number; failed: boolean }> {
  await delay(300);
  return { stage: 5, failed: false };
}

export async function getReport(_analysisId: string): Promise<Report> {
  await delay(600);
  return {
    title: 'Retail Sales 2025',
    datasetName: 'retail_sales_2025.csv',
    generatedAt: '1 Dec 2025',
    totalClaims: 12,
    verifiedClaims: 12,
    executiveClaims: [
      {
        id: 'es1',
        sentence:
          'Total revenue reached ฿14.10M across 48,215 orders in 2025, with the North region contributing the largest share at 34.2%.',
        status: 'supported',
        evidenceId: 'ev_004',
        claimedValue: '฿14.10M · North 34.2%',
        calculatedValue: '฿14,100,000 · North ฿4,820,000 (34.2%)',
      },
      {
        id: 'es2',
        sentence:
          'Monthly revenue grew at an average rate of 3.2% per month, rising from ฿0.98M in January to ฿1.37M in December.',
        status: 'supported',
        evidenceId: 'ev_007',
        claimedValue: '+3.2%/month · ฿0.98M → ฿1.37M',
        calculatedValue: '+3.2% CAGR · Jan ฿980,000 → Dec ฿1,370,000',
      },
      {
        id: 'es3',
        sentence: 'Q4 was the strongest quarter, with revenue 12.4% higher than Q3.',
        status: 'supported',
        evidenceId: 'ev_009',
        claimedValue: 'Q4 +12.4% vs Q3',
        calculatedValue: '฿4,050,000 vs ฿3,610,000 (+12.2%)',
      },
      {
        id: 'es4',
        sentence:
          'Electronics was the top-performing product category by units sold, accounting for 31.5% of total volume.',
        status: 'supported',
        evidenceId: 'ev_011',
        claimedValue: 'Electronics 31.5%',
        calculatedValue: '15,188 of 48,215 units (31.5%)',
      },
    ],
    insights: [
      {
        rank: 1,
        headline: 'North leads revenue',
        evidenceId: 'ev_004',
        chart: {
          type: 'bar',
          xKey: 'region',
          yKey: 'revenue',
          highlight: 'North',
          data: [
            { region: 'North', revenue: 4820000 },
            { region: 'Central', revenue: 4100000 },
            { region: 'South', revenue: 3050000 },
            { region: 'East', revenue: 2130000 },
          ],
        },
        claims: [
          {
            id: 'c1',
            sentence:
              'The North region generated ฿4.82M in revenue, the highest of any region at 34.2% of total sales.',
            status: 'supported',
            evidenceId: 'ev_004',
            claimedValue: '฿4.82M (34.2%)',
            calculatedValue: '฿4,820,000 (34.2%)',
          },
          {
            id: 'c2',
            sentence:
              'Central followed at ฿4.10M (29.1%), with South at ฿3.05M (21.6%) and East at ฿2.13M (15.1%).',
            status: 'supported',
            evidenceId: 'ev_004',
            claimedValue: 'Central ฿4.10M, South ฿3.05M, East ฿2.13M',
            calculatedValue: 'Central ฿4,100,000, South ฿3,050,000, East ฿2,130,000',
          },
        ],
      },
      {
        rank: 2,
        headline: 'Revenue grew steadily through 2025',
        evidenceId: 'ev_007',
        chart: {
          type: 'line',
          xKey: 'month',
          yKey: 'revenue',
          data: [
            { month: 'Jan', revenue: 980000 },
            { month: 'Feb', revenue: 1010000 },
            { month: 'Mar', revenue: 1040000 },
            { month: 'Apr', revenue: 1070000 },
            { month: 'May', revenue: 1100000 },
            { month: 'Jun', revenue: 1130000 },
            { month: 'Jul', revenue: 1160000 },
            { month: 'Aug', revenue: 1200000 },
            { month: 'Sep', revenue: 1230000 },
            { month: 'Oct', revenue: 1280000 },
            { month: 'Nov', revenue: 1330000 },
            { month: 'Dec', revenue: 1370000 },
          ],
        },
        claims: [
          {
            id: 'c3',
            sentence: 'Monthly revenue grew at an average rate of 3.2% per month throughout 2025.',
            status: 'supported',
            evidenceId: 'ev_007',
            claimedValue: '+3.2% per month',
            calculatedValue: '+3.2% per month (CAGR)',
          },
          {
            id: 'c4',
            sentence:
              'Revenue rose from ฿0.98M in January to ฿1.37M in December, a 39.8% increase over the year.',
            status: 'supported',
            evidenceId: 'ev_007',
            claimedValue: '฿0.98M → ฿1.37M',
            calculatedValue: '฿980,000 → ฿1,370,000 (+39.8%)',
          },
        ],
      },
      {
        rank: 3,
        headline: 'Q4 was the strongest quarter',
        evidenceId: 'ev_009',
        chart: {
          type: 'bar',
          xKey: 'quarter',
          yKey: 'revenue',
          highlight: 'Q4',
          data: [
            { quarter: 'Q1', revenue: 3030000 },
            { quarter: 'Q2', revenue: 3300000 },
            { quarter: 'Q3', revenue: 3610000 },
            { quarter: 'Q4', revenue: 4050000 },
          ],
        },
        claims: [
          {
            id: 'c5',
            sentence:
              'Q4 revenue was 12.4% higher than Q3, making it the strongest quarter of 2025.',
            status: 'supported',
            evidenceId: 'ev_009',
            claimedValue: '+12.4% Q4 vs Q3',
            calculatedValue: '฿4,050,000 vs ฿3,610,000 (+12.2%)',
          },
          {
            id: 'c6',
            sentence: 'The South region also saw strong Q4 growth, rising 11.6% compared to Q3.',
            status: 'supported',
            evidenceId: 'ev_009',
            claimedValue: 'South +11.6%',
            calculatedValue: 'South: Q3 ฿720,000 → Q4 ฿803,520 (+11.6%)',
          },
        ],
      },
      {
        rank: 4,
        headline: 'Electronics drives the most units',
        evidenceId: 'ev_011',
        chart: {
          type: 'horizontalBar',
          xKey: 'units',
          yKey: 'category',
          data: [
            { category: 'Electronics', units: 31.5 },
            { category: 'Home', units: 24.8 },
            { category: 'Fashion', units: 20.3 },
            { category: 'Others', units: 23.4 },
          ],
        },
        claims: [
          {
            id: 'c7',
            sentence:
              'Electronics accounted for 31.5% of all units sold in 2025, the largest share of any product category.',
            status: 'supported',
            evidenceId: 'ev_011',
            claimedValue: 'Electronics 31.5%',
            calculatedValue: '31.5% (15,188 of 48,215 units)',
          },
          {
            id: 'c8',
            sentence: 'Home (24.8%) and Fashion (20.3%) ranked second and third by unit volume.',
            status: 'supported',
            evidenceId: 'ev_011',
            claimedValue: 'Home 24.8%, Fashion 20.3%',
            calculatedValue: 'Home 11,957 units, Fashion 9,788 units',
          },
        ],
      },
    ],
    dataNotes: [
      'discount_pct (Discount) has 6,121 missing values (12.7%). Rows with missing discount were excluded from discount-related calculations.',
      'product_category (Product Category) has 212 missing values (0.4%). These rows are included in revenue and unit totals but excluded from category breakdowns.',
      '37 duplicate rows were detected and removed before analysis.',
    ],
    allClaims: [
      {
        id: 'es1',
        sentence:
          'Total revenue reached ฿14.10M across 48,215 orders in 2025, with the North region contributing the largest share at 34.2%.',
        status: 'supported',
        evidenceId: 'ev_004',
      },
      {
        id: 'es2',
        sentence:
          'Monthly revenue grew at an average rate of 3.2% per month, rising from ฿0.98M in January to ฿1.37M in December.',
        status: 'supported',
        evidenceId: 'ev_007',
      },
      {
        id: 'es3',
        sentence: 'Q4 was the strongest quarter, with revenue 12.4% higher than Q3.',
        status: 'supported',
        evidenceId: 'ev_009',
      },
      {
        id: 'es4',
        sentence:
          'Electronics was the top-performing product category by units sold, accounting for 31.5% of total volume.',
        status: 'supported',
        evidenceId: 'ev_011',
      },
      {
        id: 'c1',
        sentence:
          'The North region generated ฿4.82M in revenue, the highest of any region at 34.2% of total sales.',
        status: 'supported',
        evidenceId: 'ev_004',
      },
      {
        id: 'c2',
        sentence:
          'Central followed at ฿4.10M (29.1%), with South at ฿3.05M (21.6%) and East at ฿2.13M (15.1%).',
        status: 'supported',
        evidenceId: 'ev_004',
      },
      {
        id: 'c3',
        sentence: 'Monthly revenue grew at an average rate of 3.2% per month throughout 2025.',
        status: 'supported',
        evidenceId: 'ev_007',
      },
      {
        id: 'c4',
        sentence:
          'Revenue rose from ฿0.98M in January to ฿1.37M in December, a 39.8% increase over the year.',
        status: 'supported',
        evidenceId: 'ev_007',
      },
      {
        id: 'c5',
        sentence: 'Q4 revenue was 12.4% higher than Q3, making it the strongest quarter of 2025.',
        status: 'supported',
        evidenceId: 'ev_009',
      },
      {
        id: 'c6',
        sentence: 'The South region also saw strong Q4 growth, rising 11.6% compared to Q3.',
        status: 'supported',
        evidenceId: 'ev_009',
      },
      {
        id: 'c7',
        sentence:
          'Electronics accounted for 31.5% of all units sold in 2025, the largest share of any product category.',
        status: 'supported',
        evidenceId: 'ev_011',
      },
      {
        id: 'c8',
        sentence: 'Home (24.8%) and Fashion (20.3%) ranked second and third by unit volume.',
        status: 'supported',
        evidenceId: 'ev_011',
      },
      {
        id: 'cx1',
        sentence: 'South region revenue grew 18% in Q4.',
        status: 'rejected',
        evidenceId: 'ev_009',
        claimedValue: '18%',
        calculatedValue: '11.6%',
        rejectionReason:
          'Calculated value (11.6%) does not match claimed value (18%). Claim removed from report.',
      },
    ],
  };
}

export const evidenceMap: Record<
  string,
  {
    id: string;
    type: string;
    method: string;
    sourceColumns: { businessName: string; originalName: string }[];
    calculatedValues: { label: string; value: string }[];
  }
> = {
  ev_001: {
    id: 'ev_001',
    type: 'Descriptive',
    method: 'Sum aggregation',
    sourceColumns: [{ businessName: 'Revenue', originalName: 'revenue_thb' }],
    calculatedValues: [{ label: 'Total Revenue', value: '฿14,100,000' }],
  },
  ev_004: {
    id: 'ev_004',
    type: 'Category comparison',
    method: 'Group by region, sum revenue',
    sourceColumns: [
      { businessName: 'Sales Region', originalName: 'region' },
      { businessName: 'Revenue', originalName: 'revenue_thb' },
    ],
    calculatedValues: [
      { label: 'North', value: '฿4,820,000 (34.2%)' },
      { label: 'Central', value: '฿4,100,000 (29.1%)' },
      { label: 'South', value: '฿3,050,000 (21.6%)' },
      { label: 'East', value: '฿2,130,000 (15.1%)' },
    ],
  },
  ev_007: {
    id: 'ev_007',
    type: 'Trend',
    method: 'Monthly aggregation, compound growth rate',
    sourceColumns: [
      { businessName: 'Order Date', originalName: 'order_date' },
      { businessName: 'Revenue', originalName: 'revenue_thb' },
    ],
    calculatedValues: [
      { label: 'Jan revenue', value: '฿980,000' },
      { label: 'Dec revenue', value: '฿1,370,000' },
      { label: 'Avg monthly growth', value: '+3.2%' },
    ],
  },
  ev_009: {
    id: 'ev_009',
    type: 'Period change',
    method: 'Quarter aggregation, period-over-period comparison',
    sourceColumns: [
      { businessName: 'Order Date', originalName: 'order_date' },
      { businessName: 'Revenue', originalName: 'revenue_thb' },
      { businessName: 'Sales Region', originalName: 'region' },
    ],
    calculatedValues: [
      { label: 'Q3 revenue', value: '฿3,610,000' },
      { label: 'Q4 revenue', value: '฿4,050,000' },
      { label: 'Q4 vs Q3 change', value: '+12.4%' },
      { label: 'South Q4 vs Q3', value: '+11.6%' },
    ],
  },
  ev_011: {
    id: 'ev_011',
    type: 'Category comparison',
    method: 'Group by product category, sum units',
    sourceColumns: [
      { businessName: 'Product Category', originalName: 'product_category' },
      { businessName: 'Units Sold', originalName: 'units' },
    ],
    calculatedValues: [
      { label: 'Electronics', value: '31.5% (15,188 units)' },
      { label: 'Home', value: '24.8% (11,957 units)' },
      { label: 'Fashion', value: '20.3% (9,788 units)' },
      { label: 'Others', value: '23.4% (11,282 units)' },
    ],
  },
};
