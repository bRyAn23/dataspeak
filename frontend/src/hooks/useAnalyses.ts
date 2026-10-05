// Query hooks for analysis resources (TanStack Query). Keys are centralised here.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { analysisService } from '@/services/analysis.service';
import { profileService } from '@/services/profile.service';
import { reportService } from '@/services/report.service';
import { newIdempotencyKey } from '@/utils/format';
import { currentLocale } from '@/i18n';

export const keys = {
  analyses: (page: number) => ['analyses', page] as const,
  analysis: (id: string) => ['analysis', id] as const,
  profile: (id: string) => ['analysis', id, 'profile'] as const,
  insights: (id: string) => ['analysis', id, 'insights'] as const,
  visualizations: (id: string) => ['analysis', id, 'visualizations'] as const,
  report: (id: string) => ['analysis', id, 'report'] as const,
  status: (id: string) => ['analysis', id, 'status'] as const,
  claim: (id: string, claimId: string) => ['analysis', id, 'claim', claimId] as const,
};

export function useAnalysesList(page: number) {
  return useQuery({ queryKey: keys.analyses(page), queryFn: () => analysisService.list(page) });
}

export function useAnalysis(id: string | undefined, enabled = true) {
  return useQuery({
    queryKey: keys.analysis(id ?? ''),
    queryFn: () => analysisService.get(id as string),
    enabled: Boolean(id) && enabled,
  });
}

export function useProfile(id: string | undefined, enabled = true) {
  return useQuery({
    queryKey: keys.profile(id ?? ''),
    queryFn: () => profileService.get(id as string),
    enabled: Boolean(id) && enabled,
  });
}

export function useInsights(id: string | undefined, enabled = true) {
  return useQuery({
    queryKey: [...keys.insights(id ?? ''), currentLocale()] as const, // localised by the API
    queryFn: () => reportService.insights(id as string),
    enabled: Boolean(id) && enabled,
  });
}

export function useVisualizations(id: string | undefined, enabled = true) {
  return useQuery({
    queryKey: [...keys.visualizations(id ?? ''), currentLocale()] as const, // localised by the API
    queryFn: () => reportService.visualizations(id as string),
    enabled: Boolean(id) && enabled,
  });
}

export function useChartOptions(id: string | undefined, enabled = true) {
  return useQuery({
    queryKey: [...keys.visualizations(id ?? ''), 'options'] as const,
    queryFn: () => reportService.chartOptions(id as string),
    enabled: Boolean(id) && enabled,
  });
}

export function useSelectCharts(id: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: { selected: string[]; add: string[]; theme?: string }) =>
      reportService.selectCharts(id, input.selected, input.add, input.theme),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: keys.visualizations(id) });
      void qc.invalidateQueries({ queryKey: keys.analysis(id) }); // chart_theme lives on the analysis
    },
  });
}

export function useReport(id: string | undefined, enabled = true) {
  return useQuery({
    queryKey: [...keys.report(id ?? ''), currentLocale()] as const, // localised by the API
    queryFn: () => reportService.get(id as string),
    enabled: Boolean(id) && enabled,
  });
}

export function useClaim(id: string | undefined, claimId: string | undefined) {
  return useQuery({
    queryKey: [...keys.claim(id ?? '', claimId ?? ''), currentLocale()] as const,
    queryFn: () => reportService.claim(id as string, claimId as string),
    enabled: Boolean(id && claimId),
  });
}

export function useInvalidateAnalysis() {
  const qc = useQueryClient();
  return (id: string) => qc.invalidateQueries({ queryKey: ['analysis', id] });
}

/** Starting a job: forget the previous job's status so a fresh poller cannot stop on stale data. */
export function useStartJob() {
  const qc = useQueryClient();
  const invalidate = useInvalidateAnalysis();
  return (id: string) => {
    qc.removeQueries({ queryKey: keys.status(id) });
    qc.removeQueries({ queryKey: keys.report(id) });
    return invalidate(id);
  };
}

export function useRunAnalysis(id: string) {
  const invalidate = useStartJob();
  return useMutation({
    mutationFn: () => analysisService.run(id, newIdempotencyKey()),
    onSuccess: () => invalidate(id),
  });
}

export function useGenerateReport(id: string) {
  const invalidate = useStartJob();
  return useMutation({
    mutationFn: () => reportService.generate(id, newIdempotencyKey()),
    onSuccess: () => invalidate(id),
  });
}

export function useDeleteAnalysis() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => analysisService.remove(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['analyses'] }),
  });
}
