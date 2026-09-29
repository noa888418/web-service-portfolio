import js from '@eslint/js'
import ts from 'typescript-eslint'
import globals from 'globals'

export default [
  { ignores: ['dist/**', 'node_modules/**'] },
  js.configs.recommended, ...ts.configs.recommended,
  { languageOptions: { globals: { ...globals.browser, ...globals.node } } },
]
