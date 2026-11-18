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
            colors: {
                brand: {
                    50: '#eef6fb', 100: '#d5e8f4', 200: '#acd0e8', 300: '#79b1d7', 400: '#468cc0',
                    500: '#2a70a6', 600: '#1f598a', 700: '#1a4870', 800: '#163c5d', 900: '#0f2c45', 950: '#0a1d2e',
                },
            },
        },
    },
    plugins: [forms],
};
