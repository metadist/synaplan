import js from '@eslint/js'
import prettier from 'eslint-config-prettier'
import globals from 'globals'

/**
 * Plugin frontends are not part of the Vue app. index.js files are browser
 * ES modules; the Jitsi loader is a classic script with Jitsi's APP global.
 */
export default [
  {
    ignores: ['**/locales/**', '**/*.json', '**/backend/**', 'synaform/**'],
  },
  js.configs.recommended,
  prettier,
  {
    files: ['**/frontend/**/*.js'],
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      globals: {
        ...globals.browser,
        ...globals.es2021,
      },
    },
  },
  {
    files: ['**/jitsi/loader.js'],
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'script',
      globals: {
        ...globals.browser,
        ...globals.es2021,
        APP: 'readonly',
        JitsiMeetJS: 'readonly',
      },
    },
  },
]
