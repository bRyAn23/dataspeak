# Data Speak frontend

React 19 + Vite + TypeScript (strict) + Tailwind CSS v4 + React Router 7 + TanStack Query 5 + Recharts.
Production implementation of the Frontend Program List; the original Figma Make export lives in
`prototype-reference/` for visual reference only and is excluded from tooling.

## Rules that are enforced

- The browser talks only to the Laravel API at `VITE_API_BASE_URL` (`src/config/env.ts`). There is no
  FastAPI URL anywhere; a test asserts it.
- Raw CSV content never reaches `console`, `localStorage`, `sessionStorage` or IndexedDB. ESLint bans
  `console` and browser storage outside `src/lib/logger.ts` and `src/lib/session.ts`.
- The Sanctum token lives in memory and is mirrored to `sessionStorage` only.
- Backend enum values are never rendered; `src/utils/statusLabels.ts` and `src/utils/evidenceWording.ts`
  hold every label.
- Charts render the backend specification; nothing is computed in the browser.
- Only backend-validated claims are shown; the report is laid out for business readers.

## Where things are

- `src/app/` App (providers + router), `routes.tsx`, `guards.tsx`
- `src/pages/` one page per route (FE-PG-001..009); `useAnalysisGuard.ts` redirects to the furthest allowed step
- `src/components/{layout,upload,profile,define,analyze,report}/` programs by area, FE id in the top comment
- `src/services/` `apiClient.ts` plus one service per resource group
- `src/hooks/` `useAuth`, `useAnalysisStatus` (2 s polling, stops on terminal states), `useAnalyses`
- `src/types/` mirror the Laravel API resources exactly
- `src/test/` Vitest setup, MSW fake of the Laravel contract (tests only), integration, privacy and axe tests

## Commands

`npm run dev` (port 5173), `npm run lint`, `npm run format:check`, `npm run typecheck`, `npm test`, `npm run build`.
In Docker the container serves the bind-mounted source; after changing `package.json` recreate it with
`docker compose up -d --build --force-recreate -V frontend`.
