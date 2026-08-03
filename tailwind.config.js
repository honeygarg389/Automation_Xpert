import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Space Grotesk', ...defaultTheme.fontFamily.sans],
            },
            // WhatsMine palette — brand (forest-green #467235), accent (amber
            // #FFBF00 / light-yellow #FFF78D), secondary (dark-green #283F24).
            // Source of truth: ./.branding
            colors: {
                surface: {
                    DEFAULT: '#f7faec',
                    subtle: '#eef4dd',
                },
                secondary: {
                    50: '#eef2ec',
                    100: '#d3ddce',
                    200: '#b3c4ab',
                    300: '#8da583',
                    400: '#6c8760',
                    500: '#4f6b43',
                    600: '#3d5534',
                    700: '#32462b',
                    800: '#283f24',
                    900: '#20321d',
                    950: '#121d10',
                },
                brand: {
                    50: '#f2f8ec',
                    100: '#dfeecf',
                    200: '#c0dca6',
                    300: '#9bc476',
                    400: '#76a84e',
                    500: '#5a8b38',
                    600: '#467235',
                    700: '#38592a',
                    800: '#2f4a25',
                    900: '#283f24',
                    950: '#162610',
                },
                // Accent (amber #FFBF00 at 500, light-yellow #FFF78D at 200)
                accent: {
                    50: '#fffdeb',
                    100: '#fffbc4',
                    200: '#fff78d',
                    300: '#ffe24a',
                    400: '#ffcf1f',
                    500: '#ffbf00',
                    600: '#e29400',
                    700: '#bb6c02',
                    800: '#985308',
                    900: '#7c450b',
                    950: '#482400',
                },
                // Danger / destructive (coral-red)
                coral: {
                    50: '#fff3f1',
                    100: '#ffe4df',
                    200: '#ffcabf',
                    300: '#ffa593',
                    400: '#fb7355',
                    500: '#f04e2e',
                    600: '#d8331a',
                    700: '#b32512',
                    800: '#931f13',
                    900: '#7a1e16',
                    950: '#420a07',
                },
                neutral: {
                    50: '#fafafa',
                    100: '#f4f4f5',
                    200: '#e4e4e7',
                    300: '#d4d4d8',
                    400: '#a1a1aa',
                    500: '#71717a',
                    600: '#52525b',
                    700: '#3f3f46',
                    800: '#27272a',
                    900: '#18181b',
                    950: '#0a0a0b',
                },
            },
            // Soft borders
            borderWidth: {
                soft: '1px',
            },
            borderColor: {
                DEFAULT: 'rgb(228 228 231 / 0.8)',
                soft: 'rgb(228 228 231 / 0.6)',
                muted: 'rgb(228 228 231 / 0.4)',
            },
            borderRadius: {
                soft: '0.5rem',
                'soft-lg': '0.75rem',
                'soft-xl': '1rem',
            },
            // Subtle shadows
            boxShadow: {
                soft: '0 1px 2px 0 rgb(0 0 0 / 0.04), 0 1px 2px -1px rgb(0 0 0 / 0.04)',
                'soft-md': '0 4px 6px -1px rgb(0 0 0 / 0.05), 0 2px 4px -2px rgb(0 0 0 / 0.05)',
                'soft-lg': '0 10px 15px -3px rgb(0 0 0 / 0.06), 0 4px 6px -4px rgb(0 0 0 / 0.06)',
                'soft-xl': '0 20px 25px -5px rgb(0 0 0 / 0.06), 0 8px 10px -6px rgb(0 0 0 / 0.06)',
                inner: 'inset 0 1px 2px 0 rgb(0 0 0 / 0.04)',
            },
            // Spacing scale (align with design)
            spacing: {
                '4.5': '1.125rem',
                '13': '3.25rem',
                '15': '3.75rem',
                '18': '4.5rem',
                '22': '5.5rem',
                '30': '7.5rem',
            },
            transitionDuration: {
                150: '150ms',
                250: '250ms',
            },
            transitionTimingFunction: {
                smooth: 'cubic-bezier(0.4, 0, 0.2, 1)',
            },
        },
    },

    plugins: [forms],
};
