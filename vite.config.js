import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue'
import path from 'path';

export default defineConfig({
    plugins: [
        laravel({
            input: [
              'view/src/upload.ts',
            ],
            refresh: true,
        }),
        vue(),
        tailwindcss(),
    ],    
    build: {
       outDir: 'view/dist', // Custom output directory
        rollupOptions: {
            output: {
                // Menentukan format nama file untuk entry points (JS/CSS utama)
                entryFileNames: `assets/[name].js`,
                // Menentukan format nama file untuk chunks (kode yang dipecah)
                chunkFileNames: `assets/[name].js`,
                // Menentukan format nama file untuk aset lainnya (gambar, font, dll)
                assetFileNames: `assets/[name].[ext]`,
            },
        },
    },
});