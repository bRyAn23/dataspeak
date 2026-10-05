import js from '@eslint/js';
import globals from 'globals';
import reactHooks from 'eslint-plugin-react-hooks';
import reactRefresh from 'eslint-plugin-react-refresh';
import tseslint from 'typescript-eslint';
import prettier from 'eslint-config-prettier';
import { defineConfig, globalIgnores } from 'eslint/config';

export default defineConfig([
  globalIgnores(['dist', 'coverage', 'node_modules', 'prototype-reference']),
  {
    files: ['**/*.{ts,tsx}'],
    extends: [
      js.configs.recommended,
      tseslint.configs.recommended,
      reactHooks.configs.flat.recommended,
      reactRefresh.configs.vite,
      prettier,
    ],
    languageOptions: {
      globals: globals.browser,
    },
    rules: {
      '@typescript-eslint/no-unused-vars': [
        'error',
        { argsIgnorePattern: '^_', varsIgnorePattern: '^_', caughtErrorsIgnorePattern: '^_' },
      ],
      // Privacy baseline: raw CSV content must never reach the console or browser storage.
      // Use src/lib/logger.ts, which refuses File/Blob arguments.
      'no-console': 'error',
      'no-restricted-globals': ['error', 'localStorage', 'sessionStorage', 'indexedDB'],
    },
  },
  {
    files: ['src/lib/logger.ts', '**/*.test.{ts,tsx}', 'src/test/**'],
    rules: { 'no-console': 'off', 'no-restricted-globals': 'off' },
  },
  {
    // Generated Vite config from Figma Make. Relaxed, not exempt.
    files: ['vite.config.ts'],
    rules: { '@typescript-eslint/no-explicit-any': 'warn' },
  },
  {
    // Context providers export the provider component and its hook together.
    files: ['src/hooks/useAuth.tsx'],
    rules: { 'react-refresh/only-export-components': 'off' },
  },
  {
    // The one place allowed to touch browser storage: the auth token mirror (documented decision).
    files: ['src/lib/session.ts', 'src/lib/locale.ts'],
    rules: { 'no-restricted-globals': 'off' },
  },
]);
