// Route map (Frontend Program List, Section 3).
import { Navigate, type RouteObject } from 'react-router-dom';
import { AppShell } from '@/components/layout/AppShell';
import { RedirectIfAuthenticated, RequireAuth, RequireVerified } from './guards';
import { LoginPage } from '@/pages/LoginPage';
import { RegisterPage } from '@/pages/RegisterPage';
import { VerifyEmailPage } from '@/pages/VerifyEmailPage';
import { AnalysisDashboardPage } from '@/pages/AnalysisDashboardPage';
import { NewAnalysisPage } from '@/pages/NewAnalysisPage';
import { DataProfilePage } from '@/pages/DataProfilePage';
import { DataDefinitionPage } from '@/pages/DataDefinitionPage';
import { AnalysisRunPage } from '@/pages/AnalysisRunPage';
import { ReportPage } from '@/pages/ReportPage';

export const routes: RouteObject[] = [
  {
    element: <RedirectIfAuthenticated />,
    children: [
      { path: '/login', element: <LoginPage /> }, // FE-PG-001
      { path: '/register', element: <RegisterPage /> }, // FE-PG-002
    ],
  },
  {
    element: <RequireAuth />,
    children: [
      { path: '/verify-email', element: <VerifyEmailPage /> }, // FE-PG-010
      {
        element: <RequireVerified />,
        children: [
          {
            element: <AppShell />,
            children: [
              { path: '/', element: <Navigate to="/analyses" replace /> },
              { path: '/analyses', element: <AnalysisDashboardPage /> }, // FE-PG-003
              { path: '/analyses/new', element: <NewAnalysisPage /> }, // FE-PG-004
              { path: '/analyses/:id/profile', element: <DataProfilePage /> }, // FE-PG-005
              { path: '/analyses/:id/define', element: <DataDefinitionPage /> }, // FE-PG-006
              { path: '/analyses/:id/analyze', element: <AnalysisRunPage /> }, // FE-PG-007
              { path: '/analyses/:id/report', element: <ReportPage /> }, // FE-PG-008
              { path: '/analyses/:id/report/claims/:claimId', element: <ReportPage /> }, // FE-PG-009
            ],
          },
        ],
      },
    ],
  },
  { path: '*', element: <Navigate to="/analyses" replace /> },
];
