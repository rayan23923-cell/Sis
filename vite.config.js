import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import arabicI18n, { arabicNodeTransform } from './resources/js/i18n/arabic-i18n.js';

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            refresh: true,
        }),
        arabicI18n(),
        vue({
            template: {
                compilerOptions: { nodeTransforms: [arabicNodeTransform] },
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
});
