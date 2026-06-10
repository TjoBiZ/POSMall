
export default {
    root: '.',
    defaultLocale: 'en',
    namespace: 'kodzero.posmall',
    localeDir: 'lang',
    files: [
        '**/*.php',
        '**/*.htm',
        '**/*.html',
        '**/*.yaml',
        '!assets/**/*',
        '!lang/**/*',
        '!node_modules/**/*',
        '!tests/**/*',
        '!updates/*',
        '!vendor/**/*',
    ],
    theme: {
        name: 'POSMall Translations',
        logo: 'assets/images/orders-icon.svg',
    },
    server: {
        port: 3005,
    }
};
