import js from '@eslint/js';
import globals from 'globals';
import tseslint from 'typescript-eslint';
import prettierRecommended from 'eslint-plugin-prettier/recommended';

export default [
  {
    ignores: ['build/**', 'node_modules/**', '.vite/**'],
  },

  js.configs.recommended,

  // Theme source: ES modules bundled by Vite, running in the browser.
  {
    files: ['js/**/*.js'],
    languageOptions: {
      ecmaVersion: 2024,
      sourceType: 'module',
      globals: {
        ...globals.browser,
        Alpine: 'readonly',
        Drupal: 'readonly',
        drupalSettings: 'readonly',
        once: 'readonly',
      },
    },
  },

  // Vite reads its config as ESM but runs it in Node, and injects __dirname.
  {
    files: ['vite.config.js'],
    languageOptions: {
      sourceType: 'module',
      globals: {
        ...globals.node,
        __dirname: 'readonly',
      },
    },
  },

  // package.json declares no "type", so the tooling configs are CommonJS.
  // This file is .mjs precisely so it stays ESM regardless of that.
  {
    files: ['prettier.config.js', 'stylelint.config.js'],
    languageOptions: {
      sourceType: 'commonjs',
      globals: globals.node,
    },
  },

  {
    files: ['eslint.config.mjs'],
    languageOptions: {
      sourceType: 'module',
      globals: globals.node,
    },
  },

  ...tseslint.config({
    files: ['**/*.ts'],
    extends: [tseslint.configs.recommended],
  }),

  // Must stay last: switches off rules that would fight Prettier.
  prettierRecommended,
];
