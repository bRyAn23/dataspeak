// Step guard shared by workflow pages: loads the analysis and redirects when the step is not yet allowed.
import { useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useAnalysis } from '@/hooks/useAnalyses';
import { furthestStep, stepPath } from '@/utils/steps';

export function useAnalysisGuard(step: number) {
  const { id } = useParams<{ id: string }>();
  const query = useAnalysis(id);
  const navigate = useNavigate();

  useEffect(() => {
    if (!id || !query.data) return;
    const allowed = furthestStep(query.data.status);
    if (step > allowed) navigate(stepPath(id, allowed), { replace: true });
  }, [id, query.data, step, navigate]);

  return { id: id as string, query };
}
