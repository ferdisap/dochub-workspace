<script setup
        lang="ts">
        import { ref, computed } from 'vue';

        // Tipe sesuai dengan respons Laravel
        type TreeNodeType = 'file' | 'directory';
        type TreeFlat = [path: string, type: TreeNodeType];

        // Simulasi props — nanti diisi dari Laravel via `@props` atau `window.LaravelData`
        // const props = defineProps<{
        //   treeFlat: TreeFlat[];
        // }>();

        const dummyTreeFlat: [string, 'file' | 'directory'][] = [
          // Root-level directories
          ['documents/', 'directory'],
          ['images/', 'directory'],
          ['reports/', 'directory'],
          ['uploads/', 'directory'],
          ['backups/', 'directory'],

          // Root-level files
          ['README.md', 'file'],
          ['config.json', 'file'],
          ['manifest.bin', 'file'],

          // documents/
          ['documents/', 'directory'], // duplikat diizinkan (grouping tetap aman)
          ['documents/contract_v2.pdf.enc', 'file'],
          ['documents/invoice_2025Q3.pdf.enc', 'file'],
          ['documents/specs/', 'directory'],
          ['documents/specs/api_v1.yaml', 'file'],
          ['documents/specs/db_schema.sql', 'file'],
          ['documents/drafts/', 'directory'],
          ['documents/drafts/meeting_notes.txt', 'file'],
          ['documents/drafts/proposal_v0.enc', 'file'],

          // images/
          ['images/logo.png', 'file'],
          ['images/banner.jpg', 'file'],
          ['images/icons/', 'directory'],
          ['images/icons/favicon.ico', 'file'],
          ['images/icons/social/', 'directory'],
          ['images/icons/social/twitter.svg', 'file'],
          ['images/icons/social/telegram.png', 'file'],

          // reports/
          ['reports/', 'directory'],
          ['reports/monthly/', 'directory'],
          ['reports/monthly/january_2025.pdf', 'file'],
          ['reports/monthly/february_2025.pdf.enc', 'file'], // encrypted
          ['reports/summary_2024.xlsx', 'file'],
          ['reports/audit_log.bin.sig', 'file'], // signature file

          // uploads/ (realistis: hasil upload user)
          ['uploads/', 'directory'],
          ['uploads/user_123/', 'directory'],
          ['uploads/user_123/file_a.bin.enc', 'file'],
          ['uploads/user_123/metadata.json', 'file'],
          ['uploads/user_456/', 'directory'],
          ['uploads/user_456/report_final.pdf.enc', 'file'],
          ['uploads/temp_upload_session_7a3b.bin', 'file'], // session chunk

          // backups/
          ['backups/', 'directory'],
          ['backups/db_20251210.sql.gz', 'file'],
          ['backups/keys/', 'directory'],
          ['backups/keys/x25519_pubkey.bin', 'file'],   // sesuai minat Anda
          ['backups/keys/chacha20_key.enc', 'file'],    // encrypted key
          ['backups/keys/file_id_16b.bin', 'file'],     // 16-byte deterministic ID
        ];

        // Helper: generate TreeFlat dari struktur nested (opsional)
        // const generateTreeFlat = (root = '') => {
        //   return dummyTreeFlat
        //     .map(([path, type]) => [root + path, type] as [string, 'file' | 'directory'])
        //     .filter(([path]) => path !== ''); // hindari path kosong
        // };

        const treeFlat = ref<TreeFlat[]>(dummyTreeFlat);



        // State UI
        const expandedDirs = ref<Set<string>>(new Set(['/'])); // root selalu terbuka

        // Helper: ekstrak dir name dari path (untuk grouping)
        const getDirname = (path: string): string => {
          return path.endsWith('/') ? path.slice(0, -1) : path.substring(0, path.lastIndexOf('/') + 1) || '/';
        };

        // Grouping: dari flat list → nested view logic (tanpa ubah struktur)
        const groupedTree = computed(() => {
          const groups: Record<string, TreeFlat[]> = {};
          for (const item of treeFlat.value) {
            const dir = getDirname(item[0]);
            if (!groups[dir]) groups[dir] = [];
            groups[dir].push(item);
          }
          return groups;
        });

        const toggleDir = (dirPath: string) => {
          if (expandedDirs.value.has(dirPath)) {
            expandedDirs.value.delete(dirPath);
          } else {
            expandedDirs.value.add(dirPath);
          }
        };

        const isExpanded = (dirPath: string): boolean => {
          return expandedDirs.value.has(dirPath);
        };

        const getIndent = (path: string): number => {
          return path.split('/').filter(Boolean).length - 1;
        };
</script>

<template>
  <div class="file-list">
    <!-- Header -->
    <div class="card-header">
      <h2 class="title">File Browser</h2>
      <p>List of your uploaded files</p>
    </div>

    <div class="upload-manager">
      <!-- Env badge (opsional, bisa dihilangkan atau di-props) -->
      <div class="env-badge env-shared">
        <span>📁 Shared Environment</span>
      </div>

      <!-- File List -->
      <div class="mt-6">
        <div v-for="(items, dir) in groupedTree" :key="dir" class="mb-2">
          <!-- Directory Header -->
          <div v-if="dir !== '/'" class="flex items-center gap-2 py-2 cursor-pointer" @click="toggleDir(dir)">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-600" viewBox="0 0 20 20"
              fill="currentColor">
              <path fill-rule="evenodd"
                d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                clip-rule="evenodd" />
            </svg>
            <span class="font-medium text-blue-700">{{ dir }}</span>
            <span class="text-xs text-gray-500">
              ({{ items.length }} items)
            </span>
          </div>

          <!-- Items in this directory -->
          <div v-show="isExpanded(dir) || dir === '/'" class="pl-4 border-l-2 border-gray-100 ml-6">
            <div v-for="[path, type] in items" :key="path" class="flex items-center gap-2 py-1.5 group" :class="{
              'pl-4': type === 'file',
            }">
              <!-- Icon -->
              <div class="flex-shrink-0 w-5">
                <svg v-if="type === 'directory'" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-yellow-600"
                  fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z" />
                </svg>
                <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-gray-600" fill="none"
                  viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
              </div>

              <!-- Path (basename only) -->
              <span class="text-sm font-mono" :class="{
                'font-semibold text-blue-800': type === 'directory',
                'text-gray-700': type === 'file',
              }">
                {{ path.split('/').pop() || path }}
              </span>

              <!-- Action (opsional) -->
              <button v-if="type === 'file'" class="btn-sm blue opacity-0 group-hover:opacity-100 transition-opacity">
                Download
              </button>
              <button v-if="type === 'file'" class="btn-sm red opacity-0 group-hover:opacity-100 transition-opacity">
                Delete
              </button>
              <button v-if="type === 'file'" class="btn-sm green opacity-0 group-hover:opacity-100 transition-opacity">
                To Workspace
              </button>
            </div>
          </div>
        </div>

        <!-- Empty state -->
        <div v-if="treeFlat.length === 0" class="text-center py-8 text-gray-500">
          <p>No files uploaded yet.</p>
        </div>
      </div>
    </div>
  </div>
</template>