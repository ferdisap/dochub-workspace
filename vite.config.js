import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue'
import path from 'path';

export default defineConfig({
  base: "vendor/dochub",
  plugins: [
    laravel({
      input: [
        'view/src/upload/upload.ts',
        'view/src/upload/list/upload-list.ts',
        'view/src/encryption/keys/createKey.ts',
        'view/src/workspace/analyze/analyze.ts',
      ],
      refresh: true,
    }),
    vue(),
    tailwindcss(),
  ],
  worker: {
    format: 'es', // Ensures the worker is built as an ES module
    rollupOptions: {
      output: {
        // Gunakan logika yang sama untuk worker
        entryFileNames: (chunkInfo) => {
          const relativePath = chunkInfo.facadeModuleId
            ?.replace(/\\/g, '/')
            ?.split('/src/')?.[1]
            ?.replace(/\.([jt]s|mjs)$/, '');

          return relativePath
            ? `assets/js/${relativePath}.js`
            : 'assets/js/worker/[name]-[hash].js';
        },
        // Jika worker Anda melakukan dynamic import, atur juga chunk-nya
        chunkFileNames: 'assets/js/worker/chunks/[name]-[hash].js',
      }
    }
  },
  build: {
    outDir: 'view/dist', // Custom output directory
    rollupOptions: {
      output: {
        // Menentukan format nama file untuk entry points (JS/CSS utama)
        // entryFileNames: `assets/[name].js`,
        entryFileNames: (chunkInfo) => {
          // chunkInfo.facadeModuleId berisi path absolut file sumber
          // Kita bisa membersihkannya untuk mendapatkan path relatif dari 'src'
          const relativePath = chunkInfo.facadeModuleId
            ?.replace(/\\/g, '/') // Normalisasi backslash (Windows) ke slash
            ?.split('/src/')?.[1] // Ambil path setelah folder 'src'
            ?.replace(/\.([jt]s|mjs)$/, '') // Hapus ekstensi .ts atau .js

          return relativePath
            ? `assets/js/${relativePath}.js`
            : 'assets/js/[name]-[hash].js'; // Fallback jika path tidak ditemukan
        },

        // Menentukan format nama file untuk aset lainnya (gambar, font, dll)
        // assetFileNames: `assets/[name].[ext]`,
        assetFileNames: (assetInfo) => {
          // Jika file adalah CSS
          if (assetInfo.name?.endsWith('.css')) {
            // Anda bisa mencoba memetakan nama file jika tersedia
            // Catatan: Jika CSS di-import di dalam JS, Vite mungkin menggabungkannya (bundling)
            return 'assets/css/[name]-[hash][extname]';
          }
          // if worker
          else if (assetInfo.name?.includes(".worker.")) {
            return 'assets/js/worker/[name][extname]';
          }
          // Default untuk gambar, font, dll
          return 'assets/css/[name]-[hash][extname]';
        },

        // Menentukan format nama file untuk chunks (kode yang dipecah)
        // chunkFileNames: `assets/[name].js`,
        chunkFileNames: (chunkInfo) => {
          // chunkInfo.facadeModuleId berisi path absolut file sumber
          // Kita bisa membersihkannya untuk mendapatkan path relatif dari 'src'
          const relativePath = chunkInfo.facadeModuleId
            ?.replace(/\\/g, '/') // Normalisasi backslash (Windows) ke slash
            ?.split('/src/')?.[1] // Ambil path setelah folder 'src'
            ?.replace(/\.([jt]s|mjs)$/, '') // Hapus ekstensi .ts atau .js
          return relativePath
            ? `assets/chunk/${relativePath}.js`
            : 'assets/chunk/[name]-[hash].js'; // Fallback jika path tidak ditemukan
        },

      },
    },
  },
});