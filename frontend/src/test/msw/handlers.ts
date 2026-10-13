// MSW handlers implementing an in-memory fake of the Laravel /api/v1 contract. Tests only; never bundled.
import { HttpResponse, http } from 'msw';
import type {
  Analysis,
  AnalysisDetail,
  AnalysisStatus,
  StatusResponse,
  User,
} from '@/types/analysis.types';
import type { ProfileResponse } from '@/types/dataset.types';
import type { ChartOption, Claim, Report, Visualization } from '@/types/report.types';

export const BASE = 'http://localhost:8000/api/v1';
export const SENTINEL = 'SENTINEL-CELL-ค่า-တန်ဖိုး-9471';

const verifiedUser: User = {
  id: '1',
  name: 'Zeyar Wint',
  email: 'zywint@gmail.com',
  role: 'USER',
  email_verified: true,
  email_verified_at: '2026-10-04T00:00:00Z',
  created_at: '2026-10-04T00:00:00Z',
};
let user: User = verifiedUser;
/** Mirrors REQUIRE_VERIFIED_EMAIL on the server; tests flip it with setVerificationRequired(). */
let verificationRequired = true;
export function setVerificationRequired(required: boolean): void {
  verificationRequired = required;
}
/** Flip the signed-in user to verified (what clicking the email link does on the server). */
export function markVerified(): void {
  user = { ...user, email_verified: true, email_verified_at: '2026-10-04T00:05:00Z' };
}
const EV = 'ev_aaaaaaaaaaa1';

interface State {
  /** Languages the report of an analysis has been translated into (RPT-API-004). */
  translatedTo: Map<string, Set<string>>;
  /** Language of the last generated report per analysis (FEAT-I18N-003). */
  reportLanguage: Map<string, 'en' | 'th' | 'zh' | 'my'>;
  /** Per analysis: chart selection and user-added charts (FEAT-VIS-003). */
  charts: Map<string, Visualization[]>;
  token: string | null;
  analyses: Map<string, AnalysisDetail>;
  pollsLeft: Map<string, number>;
  /** When true the next /run stays ANALYZING until the test advances it. */
  claimsForReport: Claim[];
}

export const state: State = {
  translatedTo: new Map(),
  reportLanguage: new Map(),
  charts: new Map(),
  token: null,
  analyses: new Map(),
  pollsLeft: new Map(),
  claimsForReport: [],
};

export function resetState(): void {
  verificationRequired = true;
  user = verifiedUser;
  state.charts.clear();
  state.reportLanguage.clear();
  state.translatedTo.clear();
  state.token = null;
  state.analyses.clear();
  state.pollsLeft.clear();
  state.claimsForReport = [];
}

function base(id: string, name: string): AnalysisDetail {
  return {
    id,
    name,
    status: 'CREATED',
    current_stage: 'UPLOAD',
    dataset: {
      file_name: null,
      size_bytes: null,
      sha256: null,
      row_count: null,
      column_count: null,
      completeness_pct: null,
      uploaded_at: null,
      purged_at: null,
      transient_file_present: false,
    },
    chart_theme: 'emerald',
    last_error: null,
    created_at: '2026-10-04T01:00:00Z',
    updated_at: '2026-10-04T01:00:00Z',
    job: null,
    stages: [],
    has_definitions: false,
    has_report: false,
    can_rebuild_report: false,
  };
}

const error = (
  status: number,
  code: string,
  message: string,
  details?: { field: string; issue: string }[],
) => HttpResponse.json({ error: { code, message, ...(details ? { details } : {}) } }, { status });

function authed(request: Request): boolean {
  return request.headers.get('authorization') === `Bearer ${state.token}` && state.token !== null;
}

function setStatus(
  a: AnalysisDetail,
  status: AnalysisStatus,
  stage: AnalysisDetail['current_stage'],
) {
  a.status = status;
  a.current_stage = stage;
}

/** An analysis whose report job died (JOB_INTERRUPTED) after the statistics were calculated. */
export function seedInterruptedReport(id: string, name: string): AnalysisDetail {
  const a = seedAnalysis(id, name, 'FAILED');
  a.current_stage = 'AI_SYNTHESIS';
  a.has_definitions = true;
  a.can_rebuild_report = true;
  a.dataset.transient_file_present = false;
  a.last_error = {
    code: 'JOB_INTERRUPTED',
    message: 'This step stopped before it finished.',
  };
  return a;
}

/** Charts of an analysis: the fixture recommendation plus whatever the chart review changed. */
export function chartsFor(id: string): Visualization[] {
  return state.charts.get(id) ?? report(id, 'x').visualizations.map((v) => ({ ...v }));
}

export const CHART_OPTIONS: ChartOption[] = [
  {
    key: 'bar_mean:ev_aaaaaaaaaaa1',
    type: 'BAR',
    title: 'Average ยอดขาย by ဒေသ',
    reason:
      'ยอดขาย was defined as a property of each record (averaged, never totalled), so the average per ဒေသ is compared.',
    x_label: 'ဒေသ',
    y_label: 'ยอดขาย',
    unit: 'THB',
    points: 2,
    evidence_ids: ['ev_aaaaaaaaaaa1'],
    insight_rank: 1,
    x: { field: 'group', label: 'ဒေသ', unit: null },
    y: { field: 'mean', label: 'ยอดขาย', unit: 'THB' },
    unit_missing: false,
    series: [
      { group: 'North', mean: 420.5 },
      { group: 'South', mean: 380.2 },
    ],
  },
  {
    key: 'pie:ev_aaaaaaaaaaa1',
    type: 'PIE',
    title: 'Share of ยอดขาย by ဒေသ',
    x_label: 'ဒေသ',
    y_label: 'ยอดขาย',
    unit: 'THB',
    points: 2,
    evidence_ids: ['ev_aaaaaaaaaaa1'],
    insight_rank: 1,
    x: { field: 'group', label: 'ဒေသ', unit: null },
    y: { field: 'sum', label: 'ยอดขาย', unit: 'THB' },
    unit_missing: false,
    series: [
      { group: 'North', sum: 54665 },
      { group: 'South', sum: 41800 },
    ],
  },
  {
    key: 'line:ev_aaaaaaaaaaa2',
    type: 'LINE',
    title: 'ยอดขาย by month',
    x_label: 'order_date',
    y_label: 'ยอดขาย',
    unit: 'THB',
    points: 12,
    evidence_ids: ['ev_aaaaaaaaaaa2'],
    insight_rank: null,
    x: { field: 'period', label: 'Order Date', unit: null },
    y: { field: 'value', label: 'ยอดขาย', unit: 'THB' },
    unit_missing: false,
    series: [
      { period: '2025-01', value: 1000 },
      { period: '2025-02', value: 1200 },
    ],
  },
];

export function seedAnalysis(id: string, name: string, status: AnalysisStatus): AnalysisDetail {
  const a = base(id, name);
  a.status = status;
  if (status !== 'CREATED') {
    a.dataset = {
      ...a.dataset,
      file_name: 'sales.csv',
      size_bytes: 1024,
      sha256: 'x'.repeat(64),
      row_count: 240,
      column_count: 3,
      completeness_pct: 97.9,
      uploaded_at: '2026-10-04T01:00:00Z',
      transient_file_present: status !== 'COMPLETED',
    };
  }
  if (['DEFINED', 'ANALYZING', 'ANALYZED', 'REPORTING', 'COMPLETED', 'FAILED'].includes(status))
    a.has_definitions = true;
  if (status === 'COMPLETED') a.has_report = true;
  state.analyses.set(id, a);
  return a;
}

const profile = (id: string): ProfileResponse => ({
  analysis_id: id,
  status: 'PROFILED',
  dataset: { rows: 240, columns: 3, completeness_pct: 97.9 },
  columns: [
    {
      name: 'order_date',
      position: 0,
      inferred_type: 'DATE',
      null_count: 0,
      null_rate: 0,
      distinct_count: 240,
      stats: { min: '2025-01-01', max: '2025-12-31' },
      definition: {
        business_name: 'Order Date',
        description: 'Date the order was placed.',
        semantic_type: 'DATE',
        unit: null,
        aggregation: null,
        is_suggested: true,
        suggestion_reason: 'Detected as a quantity that adds up across records.',
      },
    },
    {
      name: 'ဒေသ',
      position: 1,
      inferred_type: 'CATEGORY',
      null_count: 0,
      null_rate: 0,
      distinct_count: 3,
      stats: { top_share: 0.54, top_count: 130 },
      definition: {
        business_name: 'Region',
        description: 'Sales region of the order.',
        semantic_type: 'CATEGORY',
        unit: null,
        aggregation: null,
        is_suggested: true,
        suggestion_reason: 'Detected as a quantity that adds up across records.',
      },
    },
    {
      name: 'ยอดขาย_thb',
      position: 2,
      inferred_type: 'NUMERIC',
      null_count: 35,
      null_rate: 0.146,
      distinct_count: 220,
      stats: { min: 120, max: 890, mean: 292.4 },
      definition: {
        business_name: 'Sales (THB)',
        description: 'Sales amount per order.',
        semantic_type: 'NUMBER', // the integration test upgrades this to CURRENCY and supplies the unit
        unit: null,
        aggregation: 'SUM',
        is_suggested: true,
        suggestion_reason: 'Detected as a quantity that adds up across records.',
      },
    },
  ],
  suggestions: { ai_status: 'ready', model: 'qwen3:8b', ai_count: 3 },
});

const evidence = {
  evidence_id: EV,
  analysis_family: 'GROUP_COMPARISON' as const,
  metric: 'top_group_share',
  method: 'share_of_total',
  value: 0.543,
  display_value: '54.3%',
  source_columns: ['ဒေသ', 'ยอดขาย_thb'],
  parameters: { aggregation: 'sum' },
  quality: { status: 'ACCEPTABLE' as const },
  payload: { top_group: 'North' },
  created_at: null,
};

export const supportedClaim: Claim = {
  id: 'claim-1',
  kind: 'EXECUTIVE_SUMMARY',
  statement: 'North accounts for 54.3% of ยอดขาย across ဒေသ.',
  statement_template: `{${EV}.group} accounts for {${EV}.display_value} of {${EV}.label} across {${EV}.dimension}.`,
  claimed_value: null,
  computed_value: '54.3%',
  status: 'SUPPORTED',
  validation: { status: 'SUPPORTED', method: 'rule_based_v1', reasons: [] },
  in_report: true,
  position: 1,
  evidence: [evidence],
  primary_evidence_id: EV,
  generation: { id: '1', model: 'qwen3:8b', prompt_version: 'exec-interp-v2', status: 'SUCCEEDED' },
};

const rejectedClaim: Claim = {
  ...supportedClaim,
  id: 'claim-2',
  kind: 'FINDING',
  statement: 'Revenue was 14 million in 2025.',
  statement_template: 'Revenue was 14 million in 2025.',
  status: 'REJECTED',
  validation: {
    status: 'REJECTED',
    method: 'rule_based_v1',
    reasons: ['DIGITS_OUTSIDE_PLACEHOLDER'],
  },
  in_report: false,
};

const report = (id: string, name: string, display = 'en'): Report => ({
  language: state.reportLanguage.get(id) ?? 'en',
  chart_theme: state.analyses.get(id)?.chart_theme ?? 'emerald',
  display_language: display as Report['display_language'],
  translation: {
    complete:
      display === (state.reportLanguage.get(id) ?? 'en') ||
      (state.translatedTo.get(id)?.has(display) ?? false),
    translated: state.translatedTo.get(id)?.has(display) ? 3 : 0,
    total: 3,
    in_progress: false,
  },
  id: '1',
  analysis_id: id,
  status: 'READY',
  title: name,
  dataset_name: 'sales.csv',
  generated_at: '2026-10-04T02:00:00Z',
  claims_total: 2,
  claims_supported: 1,
  ai: {
    status: 'ready',
    note: null,
    model: 'qwen3:8b',
    prompt_version: 'exec-interp-v2',
    generation_id: '1',
  },
  summary_metrics: [
    {
      evidence_key: 'ev_aaaaaaaaaaa4',
      label: 'Revenue mean',
      display_value: '292.4 THB',
      unit: 'THB',
    },
  ],
  sections: [
    {
      key: 'executive_summary',
      title: 'Executive summary',
      ai_status: 'ready',
      claim_ids: ['claim-1'],
    },
  ],
  data_notes: [
    { severity: 'WARNING', metric: 'null_rate', columns: ['ยอดขาย_thb'], display_value: '14.6%' },
  ],
  insights: [
    {
      rank: 1,
      insight_type: 'CONCENTRATION',
      headline: 'North accounts for 54.3% of ยอดขาย across ဒေသ',
      description: null,
      score: 0.8,
      is_selected: true,
      evidence_id: EV,
      evidence_ids: [EV],
      evidence,
    },
  ],
  visualizations: [
    {
      id: '1',
      type: 'BAR',
      title: 'Total ยอดขาย by ဒေသ',
      x: { field: 'group', label: 'ဒေသ' },
      y: { field: 'sum', label: 'ยอดขาย', unit: 'THB' },
      unit_missing: false,
      series: [
        { group: 'North', sum: 7600000 },
        { group: 'South', sum: 6400000 },
      ],
      evidence_ids: [EV],
      insight_rank: 1,
      position: 0,
      source: 'RECOMMENDED',
      is_selected: true,
      summary: [
        {
          text: 'North accounts for 54.3% of ยอดขาย across ဒေသ.',
          claim_id: 'claim-1',
          source: 'narrative',
        },
      ],
    },
  ],
  // Only SUPPORTED claims come back from the API.
  claims: [supportedClaim],
});

export const handlers = [
  http.post(`${BASE}/auth/login`, async ({ request }) => {
    const body = (await request.json()) as { email: string; password: string };
    if (body.password !== 'secret-pass-1')
      return error(401, 'INVALID_CREDENTIALS', 'The email or password is incorrect.');
    state.token = 'tok-123';
    return HttpResponse.json({ data: { token: state.token, user } });
  }),
  http.post(`${BASE}/auth/register`, async ({ request }) => {
    const body = (await request.json()) as { email: string; password: string };
    if (body.password.length < 8)
      return error(422, 'VALIDATION_FAILED', 'The request could not be validated.', [
        { field: 'password', issue: 'The password must be at least 8 characters.' },
      ]);
    state.token = 'tok-123';
    user = {
      ...verifiedUser,
      id: '2',
      name: (body as { name?: string }).name ?? 'New User',
      email: body.email,
      email_verified: false,
      email_verified_at: null,
      verification_required: verificationRequired,
    };
    return HttpResponse.json(
      {
        data: {
          token: state.token,
          user,
          verification: { required: verificationRequired, email_sent: verificationRequired },
        },
      },
      { status: 201 },
    );
  }),
  http.post(`${BASE}/auth/email/resend`, ({ request }) => {
    if (!authed(request)) return error(401, 'UNAUTHENTICATED', 'Authentication required.');
    if (user.email_verified) return HttpResponse.json({ data: { status: 'already_verified' } });
    return HttpResponse.json({ data: { status: 'sent', email: user.email } }, { status: 202 });
  }),
  http.post(`${BASE}/auth/logout`, ({ request }) =>
    authed(request)
      ? HttpResponse.json({ data: { revoked: true } })
      : error(401, 'UNAUTHENTICATED', 'Authentication required.'),
  ),
  http.get(`${BASE}/me`, ({ request }) =>
    authed(request)
      ? HttpResponse.json({ data: user })
      : error(401, 'UNAUTHENTICATED', 'Authentication required.'),
  ),

  http.get(`${BASE}/analyses`, ({ request }) => {
    if (!authed(request)) return error(401, 'UNAUTHENTICATED', 'Authentication required.');
    if (!user.email_verified)
      return error(403, 'EMAIL_NOT_VERIFIED', 'Please confirm your email address first.');
    const list: Analysis[] = [...state.analyses.values()];
    return HttpResponse.json({
      data: list,
      meta: { current_page: 1, last_page: 1, per_page: 20, total: list.length },
    });
  }),
  http.post(`${BASE}/analyses`, async ({ request }) => {
    if (!authed(request)) return error(401, 'UNAUTHENTICATED', 'Authentication required.');
    const body = (await request.json()) as { name?: string };
    if (!body.name)
      return error(422, 'VALIDATION_FAILED', 'The request could not be validated.', [
        { field: 'name', issue: 'The name field is required.' },
      ]);
    const id = `a-${state.analyses.size + 1}`;
    const a = base(id, body.name);
    state.analyses.set(id, a);
    return HttpResponse.json({ data: a }, { status: 201 });
  }),
  http.get(`${BASE}/analyses/:id`, ({ request, params }) => {
    if (!authed(request)) return error(401, 'UNAUTHENTICATED', 'Authentication required.');
    const a = state.analyses.get(params.id as string);
    return a ? HttpResponse.json({ data: a }) : error(404, 'NOT_FOUND', 'Resource not found.');
  }),
  http.delete(`${BASE}/analyses/:id`, ({ params }) => {
    state.analyses.delete(params.id as string);
    return new HttpResponse(null, { status: 204 });
  }),
  http.post(`${BASE}/analyses/:id/dataset`, async ({ request, params }) => {
    const a = state.analyses.get(params.id as string);
    if (!a) return error(404, 'NOT_FOUND', 'Resource not found.');
    const form = await request.formData();
    const file = form.get('file') as File | null;
    if (!file)
      return error(422, 'VALIDATION_FAILED', 'A CSV file is required.', [
        { field: 'file', issue: 'A CSV file is required.' },
      ]);
    a.dataset = {
      ...a.dataset,
      file_name: file.name,
      size_bytes: file.size,
      sha256: 'x'.repeat(64),
      uploaded_at: '2026-10-04T01:00:00Z',
      transient_file_present: true,
    };
    setStatus(a, 'UPLOADED', 'UPLOAD');
    const xlsx = /\.xlsx$/i.test(file.name);
    return HttpResponse.json(
      {
        data: a,
        meta: {
          source: xlsx
            ? { format: 'xlsx', sheet: 'Sheet1', sheets_total: 2, rows: 240, columns: 7 }
            : { format: 'csv' },
        },
      },
      { status: 201 },
    );
  }),
  http.post(`${BASE}/analyses/:id/validate`, ({ params }) => {
    const a = state.analyses.get(params.id as string);
    if (!a) return error(404, 'NOT_FOUND', 'Resource not found.');
    if (a.status !== 'UPLOADED')
      return error(409, 'STEP_NOT_READY', 'Validation requires an upload.');
    setStatus(a, 'VALIDATED', 'VALIDATION');
    a.dataset.row_count = 240;
    a.dataset.column_count = 3;
    return HttpResponse.json({
      data: {
        analysis_id: a.id,
        status: 'VALIDATED',
        valid: true,
        row_count: 240,
        column_count: 3,
        warnings: [{ code: 'DUPLICATE_ROWS', message: '2 duplicate rows found.', column: null }],
        errors: [],
      },
    });
  }),
  http.post(`${BASE}/analyses/:id/profile`, ({ params }) => {
    const a = state.analyses.get(params.id as string);
    if (!a) return error(404, 'NOT_FOUND', 'Resource not found.');
    if (a.status !== 'VALIDATED')
      return error(
        409,
        'STEP_NOT_READY',
        'Profiling requires the analysis to be at least VALIDATED.',
      );
    // Asynchronous like the real API: the status handler walks PROFILING -> DEFINITION -> PROFILED.
    a.job = {
      job_id: 'job_profile',
      kind: 'profile',
      status: 'RUNNING',
      stage: 'PROFILING',
      progress: 10,
      updated_at: 'now',
    };
    state.pollsLeft.set(a.id, 2);
    return HttpResponse.json(
      { data: { analysis_id: a.id, job_id: 'job_profile', status: 'QUEUED' } },
      { status: 202 },
    );
  }),
  http.get(`${BASE}/analyses/:id/profile`, ({ params }) => {
    const a = state.analyses.get(params.id as string);
    if (!a) return error(404, 'NOT_FOUND', 'Resource not found.');
    if (['CREATED', 'UPLOADED', 'VALIDATED'].includes(a.status))
      return error(409, 'STEP_NOT_READY', 'The dataset has not been profiled yet.');
    return HttpResponse.json({ data: profile(a.id) });
  }),
  http.post(`${BASE}/analyses/:id/definitions/suggest-names`, ({ params }) => {
    const a = state.analyses.get(params.id as string);
    if (!a) return error(404, 'NOT_FOUND', 'Resource not found.');
    return HttpResponse.json({
      data: [
        {
          column: 'order_date',
          business_name: 'Order Date',
          semantic_type: 'DATE',
          unit: null,
          aggregation: null,
          description: null,
          reason: 'Sales amounts add up across orders, so they are totalled.',
          source: 'software',
        },
        {
          column: 'ဒေသ',
          business_name: 'Region',
          semantic_type: 'CATEGORY',
          unit: null,
          aggregation: null,
          description: 'Sales region of the order.',
          reason: 'Sales amounts add up across orders, so they are totalled.',
          source: 'ai',
        },
        {
          column: 'ยอดขาย_thb',
          business_name: 'Sales Amount',
          semantic_type: 'CURRENCY',
          unit: 'THB',
          aggregation: 'SUM',
          description: 'Sales amount per order.',
          reason: 'Sales amounts add up across orders, so they are totalled.',
          source: 'ai',
        },
      ],
      meta: {
        ai_status: 'ready',
        model: 'qwen3:8b',
        prompt_version: 'column-definitions-v1',
        ai_count: 2,
      },
    });
  }),
  http.put(`${BASE}/analyses/:id/definitions`, async ({ request, params }) => {
    const a = state.analyses.get(params.id as string);
    if (!a) return error(404, 'NOT_FOUND', 'Resource not found.');
    const body = (await request.json()) as {
      definitions: { column: string; semantic_type: string; unit?: string }[];
    };
    const bad = body.definitions.findIndex((d) => d.semantic_type === 'CURRENCY' && !d.unit);
    if (bad >= 0)
      return error(422, 'VALIDATION_FAILED', 'The request could not be validated.', [
        {
          field: `definitions.${bad}.unit`,
          issue: 'A currency column requires a unit (for example THB).',
        },
      ]);
    setStatus(a, 'DEFINED', 'DEFINITION');
    a.has_definitions = true;
    return HttpResponse.json({ data: body.definitions, meta: { status: 'DEFINED' } });
  }),
  http.post(`${BASE}/analyses/:id/run`, ({ params }) => {
    const a = state.analyses.get(params.id as string);
    if (!a) return error(404, 'NOT_FOUND', 'Resource not found.');
    if (!a.has_definitions)
      return error(409, 'STEP_NOT_READY', 'Save field definitions before running the analysis.');
    setStatus(a, 'ANALYZING', 'STATISTICAL_ANALYSIS');
    state.pollsLeft.set(a.id, 2);
    return HttpResponse.json(
      { data: { analysis_id: a.id, job_id: 'job_1', status: 'QUEUED' } },
      { status: 202 },
    );
  }),
  http.get(`${BASE}/analyses/:id/status`, ({ params }) => {
    const a = state.analyses.get(params.id as string);
    if (!a) return error(404, 'NOT_FOUND', 'Resource not found.');
    const left = state.pollsLeft.get(a.id) ?? 0;
    if (a.status === 'VALIDATED' && a.job?.kind === 'profile' && a.job.status === 'RUNNING') {
      if (left > 1) {
        state.pollsLeft.set(a.id, left - 1);
        a.job = { ...a.job, stage: 'DEFINITION', progress: 60 };
      } else {
        setStatus(a, 'PROFILED', 'PROFILING');
        a.job = { ...a.job, status: 'SUCCEEDED', progress: 100 };
      }
      return HttpResponse.json({
        data: {
          analysis_id: a.id,
          status: a.status,
          stage: a.current_stage,
          progress: a.job.progress,
          job: a.job,
          is_terminal: false,
          last_error: null,
          poll_seconds: 0.15,
        } satisfies StatusResponse,
      });
    }
    if (a.status === 'ANALYZING' || a.status === 'REPORTING') {
      if (left > 0) state.pollsLeft.set(a.id, left - 1);
      else if (a.status === 'ANALYZING') {
        // The run stops at ANALYZED for chart review (FEAT-VIS-003); the report is a separate request.
        setStatus(a, 'ANALYZED', 'VISUALIZATION');
      } else {
        setStatus(a, 'COMPLETED', 'COMPLETE');
        a.has_report = true;
      }
    }
    const body: StatusResponse = {
      analysis_id: a.id,
      status: a.status,
      stage: a.current_stage,
      progress: a.status === 'ANALYZING' || a.status === 'REPORTING' ? 55 : 100,
      job:
        a.status === 'ANALYZING' || a.status === 'REPORTING'
          ? {
              job_id: 'job_1',
              status: 'RUNNING',
              stage: a.current_stage,
              progress: 55,
              updated_at: 'now',
            }
          : null,
      is_terminal: a.status === 'COMPLETED' || a.status === 'FAILED',
      last_error: a.last_error,
      poll_seconds: 0.15, // fast polling for tests; the real API advertises 2
    };
    return HttpResponse.json({ data: body });
  }),
  http.post(`${BASE}/analyses/:id/report`, async ({ params, request }) => {
    const a = state.analyses.get(params.id as string);
    if (!a) return error(404, 'NOT_FOUND', 'Resource not found.');
    const body = (await request.json().catch(() => ({}))) as {
      language?: 'en' | 'th' | 'zh' | 'my';
    };
    if (body.language) state.reportLanguage.set(a.id, body.language);
    const rebuild = a.status === 'FAILED' && a.can_rebuild_report;
    if (!rebuild && a.status !== 'ANALYZED' && a.status !== 'COMPLETED')
      return error(
        409,
        'STEP_NOT_READY',
        'Report generation requires the analysis to be at least ANALYZED.',
      );
    setStatus(a, 'REPORTING', 'AI_SYNTHESIS');
    a.last_error = null;
    a.can_rebuild_report = false;
    state.pollsLeft.set(a.id, 1);
    return HttpResponse.json(
      { data: { analysis_id: a.id, job_id: 'job_2', status: 'QUEUED' } },
      { status: 202 },
    );
  }),
  http.get(`${BASE}/analyses/:id/report`, ({ params, request }) => {
    const a = state.analyses.get(params.id as string);
    if (!a) return error(404, 'NOT_FOUND', 'Resource not found.');
    if (a.status !== 'COMPLETED')
      return error(409, 'STEP_NOT_READY', 'The report has not been generated yet.');
    return HttpResponse.json({
      data: report(a.id, a.name, request.headers.get('x-dataspeak-language') ?? 'en'),
    });
  }),
  http.post(`${BASE}/analyses/:id/report/translate`, async ({ params, request }) => {
    const a = state.analyses.get(params.id as string);
    if (!a) return error(404, 'NOT_FOUND', 'Resource not found.');
    const body = (await request.json()) as { language: string };
    const set = state.translatedTo.get(a.id) ?? new Set<string>();
    set.add(body.language);
    state.translatedTo.set(a.id, set);
    return HttpResponse.json({
      data: { language: body.language, translated: 3, pending: 0, total: 3, ai_status: 'ready' },
    });
  }),
  http.get(`${BASE}/analyses/:id/report/pdf`, ({ params }) => {
    const a = state.analyses.get(params.id as string);
    if (!a) return error(404, 'NOT_FOUND', 'Resource not found.');
    if (a.status !== 'COMPLETED')
      return error(409, 'STEP_NOT_READY', 'The report has not been generated yet.');
    return new HttpResponse('%PDF-1.7 fake', {
      headers: {
        'Content-Type': 'application/pdf',
        'Content-Disposition': `attachment; filename="DataSpeak-${a.name}-20261004.pdf"`,
      },
    });
  }),
  http.get(`${BASE}/analyses/:id/claims/:claimId`, ({ params }) => {
    if (params.claimId === 'claim-1') return HttpResponse.json({ data: supportedClaim });
    if (params.claimId === 'claim-2') return HttpResponse.json({ data: rejectedClaim });
    return error(404, 'NOT_FOUND', 'Resource not found.');
  }),
  http.get(`${BASE}/analyses/:id/insights`, () =>
    HttpResponse.json({ data: report('x', 'x').insights }),
  ),
  http.get(`${BASE}/analyses/:id/visualizations`, ({ params }) =>
    HttpResponse.json({ data: chartsFor(params.id as string) }),
  ),
  http.get(`${BASE}/analyses/:id/visualizations/options`, ({ params }) => {
    const present = new Set(chartsFor(params.id as string).map((c) => c.title));
    return HttpResponse.json({
      data: CHART_OPTIONS.filter((o) => !present.has(o.title)),
    });
  }),
  http.put(`${BASE}/analyses/:id/visualizations/selection`, async ({ params, request }) => {
    const body = (await request.json()) as { selected: string[]; add?: string[]; theme?: string };
    const id = params.id as string;
    const owner = state.analyses.get(id);
    if (owner && body.theme) owner.chart_theme = body.theme;
    const current = chartsFor(id).map((c) => ({ ...c, is_selected: body.selected.includes(c.id) }));
    for (const key of body.add ?? []) {
      const o = CHART_OPTIONS.find((x) => x.key === key);
      if (!o) return error(422, 'UNKNOWN_CHART_OPTION', 'Unknown chart option.');
      current.push({
        id: `viz-${key}`,
        type: o.type,
        title: o.title,
        x: o.x,
        y: o.y,
        unit_missing: o.unit_missing,
        series: o.series,
        evidence_ids: o.evidence_ids,
        insight_rank: o.insight_rank,
        position: current.length,
        source: 'USER',
        is_selected: true,
      });
    }
    if (!current.some((c) => c.is_selected))
      return error(422, 'NO_CHARTS_SELECTED', 'Keep or add at least one chart for the report.');
    state.charts.set(id, current);
    return HttpResponse.json({ data: current });
  }),
];
