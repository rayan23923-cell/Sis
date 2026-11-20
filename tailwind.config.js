import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Cairo', ...defaultTheme.fontFamily.sans],
            },
            // Telegram / FileNest palette (2026-11-20, by request): one clear blue accent
            // (#2AABEE, as in the FileNest apps) on clean neutral surfaces. No gradients
            // and no purple/indigo "AI" tones; semantic colours (green/red/amber) only for status.
            colors: {
                brand: {
                    50: '#ebf6fd', 100: '#d4ecfa', 200: '#aad9f5', 300: '#75c3f0', 400: '#45b3ee',
                    500: '#2aabee', 600: '#229ed9', 700: '#1a87bd', 800: '#176e99', 900: '#155a7c', 950: '#0e3a51',
                },
                // Telegram's neutral greys instead of Tailwind's blue-tinted slate.
                slate: {
                    50: '#f4f4f5', 100: '#ececed', 200: '#e1e2e4', 300: '#cdcfd2', 400: '#a2a6ab',
                    500: '#707579', 600: '#575c61', 700: '#3f4448', 800: '#2a2e32', 900: '#1b1e21', 950: '#111315',
                },
            },
        },
    },
    plugins: [forms],
};
