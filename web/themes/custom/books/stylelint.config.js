module.exports = {
  extends: ['stylelint-config-standard'],
  ignoreFiles: ['build/**/*.css', 'node_modules/**/*.css'],
  rules: {
    'at-rule-no-unknown': [
      true,
      {
        ignoreAtRules: [
          'tailwind',
          'apply',
          'variants',
          'responsive',
          'screen',
          'config',
          'theme',
          'source',
          'utility',
          'variant',
          'plugin',
          'reference',
          'custom-media',
          'custom-selector',
        ],
      },
    ],
    // Tailwind/Vite inline these @imports at build time, so the browser never
    // sees them and their position is not a spec violation. Hoisting them above
    // @theme/@layer to satisfy the rule would reorder the cascade.
    'no-invalid-position-at-import-rule': null,
    'at-rule-empty-line-before': null,
    'declaration-empty-line-before': null,
    'rule-empty-line-before': null,
    'no-descending-specificity': null,
    'import-notation': null,
    'selector-class-pattern': null,
    'function-url-quotes': null,
    'media-feature-range-notation': null,
    'color-function-notation': null,
    'alpha-value-notation': null,
    'hue-degree-notation': null,
    'property-no-vendor-prefix': null,
  },
};
