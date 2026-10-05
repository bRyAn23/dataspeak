// Sanctum token storage (documented decision): held in memory and mirrored to sessionStorage so a
// page refresh keeps the session. Cleared when the tab closes. Never localStorage. Nothing else in
// src/ may touch browser storage (ESLint no-restricted-globals).

const KEY = 'dataspeak.session';

interface SessionData {
  token: string;
  userId: string;
}

let memory: SessionData | null = null;

function read(): SessionData | null {
  if (memory) return memory;
  try {
    const raw = typeof sessionStorage !== 'undefined' ? sessionStorage.getItem(KEY) : null;
    memory = raw ? (JSON.parse(raw) as SessionData) : null;
  } catch {
    memory = null;
  }
  return memory;
}

export const session = {
  token(): string | null {
    return read()?.token ?? null;
  },
  userId(): string | null {
    return read()?.userId ?? null;
  },
  set(data: SessionData): void {
    memory = data;
    try {
      sessionStorage.setItem(KEY, JSON.stringify(data));
    } catch {
      /* storage unavailable: memory only */
    }
  },
  clear(): void {
    memory = null;
    try {
      sessionStorage.removeItem(KEY);
    } catch {
      /* ignore */
    }
  },
};
