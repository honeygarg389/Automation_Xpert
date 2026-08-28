import js from '@eslint/js';
import reactPlugin from 'eslint-plugin-react';
import reactHooksPlugin from 'eslint-plugin-react-hooks';
import prettierConfig from 'eslint-config-prettier';

export default [
    js.configs.recommended,
    {
        // ⚠️ The vitest globals, for the test directory only.
        //
        // `setup.jsx` has always used `vi` and `global` and has always failed
        // no-undef — invisible while the runner itself was broken (JSX under a
        // `.js` extension meant ZERO front-end tests ran, so nobody looked).
        // Unblocking the runner in Smart QR slice 3b made these the only lint
        // errors in `resources/js`, so they are declared rather than left.
        //
        // Scoped to __tests__: `vi` must stay undefined in application code.
        files: ['resources/js/__tests__/**/*.{js,jsx}'],
        languageOptions: {
            globals: {
                vi: 'readonly',
                global: 'readonly',
                describe: 'readonly',
                it: 'readonly',
                expect: 'readonly',
                beforeEach: 'readonly',
                afterEach: 'readonly',
            },
        },
    },
    {
        files: ['resources/js/**/*.{js,jsx}'],
        plugins: {
            react: reactPlugin,
            'react-hooks': reactHooksPlugin,
        },
        languageOptions: {
            globals: {
                window:    'readonly',
                document:  'readonly',
                navigator: 'readonly',
                console:   'readonly',
                setTimeout: 'readonly',
                clearTimeout: 'readonly',
                // ⚠️ Beside setTimeout. Absent until the batch detail page
                // polled, though Broadcasting/Campaigns had been using both
                // since before this config existed — those six no-undef errors
                // were sitting in the lint baseline, not a sign nobody used them.
                setInterval: 'readonly',
                clearInterval: 'readonly',
                requestAnimationFrame: 'readonly',
                Event:     'readonly',
                // ⚠️ Beside Event — the DOM interface object, needed for
                // Node.DOCUMENT_POSITION_* when asserting rendered order.
                Node:      'readonly',
                DOMParser: 'readonly',
                fetch:     'readonly',
                AbortController: 'readonly',
                localStorage: 'readonly',
                Intl:      'readonly',
                route:     'readonly',
                confirm:   'readonly',
                FormData:  'readonly',
                Blob:      'readonly',
                // ⚠️ Beside Blob, which it extends. Missing only because
                // nothing had taken a File until ImageUploadField.
                File:      'readonly',
                URL:       'readonly',
            },
            parserOptions: {
                ecmaVersion: 2022,
                sourceType:  'module',
                ecmaFeatures: { jsx: true },
            },
        },
        settings: {
            react: { version: 'detect' },
        },
        rules: {
            ...reactPlugin.configs.recommended.rules,
            ...reactHooksPlugin.configs.recommended.rules,
            'react/prop-types': 'off',
            'react/react-in-jsx-scope': 'off',
            'no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],
            'no-console': 'warn',
        },
    },
    prettierConfig,
];
