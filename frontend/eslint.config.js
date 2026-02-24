import js from '@eslint/js'
import vue from 'eslint-plugin-vue'
import typescript from '@typescript-eslint/eslint-plugin'
import typescriptParser from '@typescript-eslint/parser'
import vueParser from 'vue-eslint-parser'
import prettier from 'eslint-config-prettier'
import globals from 'globals'

export default [
  js.configs.recommended,
  ...vue.configs['flat/recommended'],
  prettier,
  // Browser-only files (Vue components, frontend code)
  {
    files: ['src/**/*.{js,ts,vue}'],
    languageOptions: {
      parser: vueParser,
      parserOptions: {
        ecmaVersion: 'latest',
        parser: typescriptParser,
        sourceType: 'module',
      },
      globals: {
        ...globals.browser,
        ...globals.es2021,
      },
    },
    plugins: {
      '@typescript-eslint': typescript,
    },
    rules: {
      ...typescript.configs.recommended.rules,
      'vue/multi-word-component-names': 'off',
      '@typescript-eslint/no-explicit-any': 'warn',
      '@typescript-eslint/no-unused-vars': 'warn',
      '@typescript-eslint/no-unused-expressions': 'warn',
      '@typescript-eslint/no-empty-object-type': 'warn',
      'no-useless-escape': 'warn',
      'no-undef': 'off', // TypeScript handles this better
      'no-loss-of-precision': 'warn',
      'no-redeclare': 'warn', // Allow function overloads
    },
  },
  // Node.js config files (vite, tailwind, etc.)
  {
    files: ['*.config.{js,ts,mjs,cjs}', '*.setup.{js,ts}', 'scripts/**/*.{js,ts,mjs,cjs}'],
    languageOptions: {
      parser: typescriptParser,
      parserOptions: {
        ecmaVersion: 'latest',
        sourceType: 'module',
      },
      globals: {
        ...globals.node,
        ...globals.es2021,
      },
    },
    plugins: {
      '@typescript-eslint': typescript,
    },
    rules: {
      ...typescript.configs.recommended.rules,
      '@typescript-eslint/no-explicit-any': 'warn',
      '@typescript-eslint/no-unused-vars': 'warn',
      'no-undef': 'off',
      'no-loss-of-precision': 'warn',
    },
  },
  // Node stub (e2e WhatsApp stub runs in Docker/Node)
  {
    files: ['tests/e2e/stub/**/*.mjs'],
    languageOptions: {
      globals: {
        ...globals.node,
        ...globals.es2021,
      },
    },
  },
  // Test files (need both browser and node globals + vitest)
  {
    files: ['**/*.spec.ts', '**/*.spec.js', 'tests/**/*.{js,ts}'],
    languageOptions: {
      parser: typescriptParser,
      parserOptions: {
        ecmaVersion: 'latest',
        sourceType: 'module',
      },
      globals: {
        ...globals.browser,
        ...globals.es2021,
        ...globals.node,
        vitest: 'readonly',
        describe: 'readonly',
        it: 'readonly',
        expect: 'readonly',
        beforeEach: 'readonly',
        afterEach: 'readonly',
        vi: 'readonly',
      },
    },
    plugins: {
      '@typescript-eslint': typescript,
    },
    rules: {
      ...typescript.configs.recommended.rules,
      '@typescript-eslint/no-explicit-any': 'warn',
      '@typescript-eslint/no-unused-vars': 'warn',
      'no-undef': 'off',
    },
  },
  {
    ignores: [
      'dist/**',
      'dist-widget/**',
      'node_modules/**',
      'src/generated/**',
      'tests/e2e/test-results/**',
      'tests/e2e/reports/**',
    ],
  },
]
