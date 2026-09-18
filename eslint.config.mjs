export default [
    {
        files: ['modules/esm/**/*.mjs'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: {
                clearTimeout: 'readonly',
                console: 'readonly',
                CustomEvent: 'readonly',
                document: 'readonly',
                Element: 'readonly',
                globalThis: 'readonly',
                innerHeight: 'readonly',
                innerWidth: 'readonly',
                matchMedia: 'readonly',
                MutationObserver: 'readonly',
                navigator: 'readonly',
                Node: 'readonly',
                NodeFilter: 'readonly',
                performance: 'readonly',
                requestAnimationFrame: 'readonly',
                setTimeout: 'readonly'
            }
        },
        linterOptions: { reportUnusedDisableDirectives: 'error' },
        rules: {
            'eqeqeq': ['error', 'always'],
            'no-eval': 'error',
            'no-new-func': 'error',
            'no-implied-eval': 'error',
            'no-var': 'error',
            'prefer-const': 'error',
            'no-undef': 'error',
            'no-unreachable': 'error',
            'no-duplicate-imports': 'error'
        }
    }
];
