<script setup lang="ts">
	import { getFilename } from "../../helpers/uri";
  import { route_file_delete, route_file_download, route_upload_get_list, route_upload_make_workspace } from "../../helpers/listRoute";
  import { getCSRFToken } from "../../helpers/toDom";
  import { ref, computed, onMounted } from "vue";

	// Tipe sesuai dengan respons Laravel
	type TreeNodeType = "file" | "directory";
	type TreeFlat = [path: string, type: TreeNodeType, hash:string, hashManifest:string, created_at?:string];

	const dummyTreeFlat: TreeFlat[] = [
		// Root-level directories
		["documents/", "directory", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["images/", "directory", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["reports/", "directory", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["uploads/", "directory", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["backups/", "directory", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],

		// Root-level files
		["README.md", "file", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["config.json", "file", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["manifest.bin", "file", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],

		// documents/
		["documents/", "directory", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'], // duplikat diizinkan (grouping tetap aman)
		["documents/contract_v2.pdf.enc", "file", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["documents/invoice_2025Q3.pdf.enc", "file", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["documents/specs/", "directory", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["documents/specs/api_v1.yaml", "file", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["documents/specs/db_schema.sql", "file", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["documents/drafts/", "directory", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["documents/drafts/meeting_notes.txt", "file", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["documents/drafts/proposal_v0.enc", "file", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],

		// images/
		["images/logo.png", "file", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["images/banner.jpg", "file", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["images/icons/", "directory", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["images/icons/favicon.ico", "file", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["images/icons/social/", "directory", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["images/icons/social/twitter.svg", "file", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["images/icons/social/telegram.png", "file", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],

		// reports/
		["reports/", "directory", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["reports/monthly/", "directory", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["reports/monthly/january_2025.pdf", "file", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["reports/monthly/february_2025.pdf.enc", "file", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'], // encrypted
		["reports/summary_2024.xlsx", "file", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["reports/audit_log.bin.sig", "file", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'], // signature file

		// uploads/ (realistis: hasil upload user)
		["uploads/", "directory", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["uploads/user_123/", "directory", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["uploads/user_123/file_a.bin.enc", "file", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["uploads/user_123/metadata.json", "file", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["uploads/user_456/", "directory", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["uploads/user_456/report_final.pdf.enc", "file", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["uploads/temp_upload_session_7a3b.bin", "file", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'], // session chunk

		// backups/
		["backups/", "directory", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["backups/db_20251210.sql.gz", "file", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["backups/keys/", "directory", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'],
		["backups/keys/x25519_pubkey.bin", "file", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'], // sesuai minat Anda
		["backups/keys/chacha20_key.enc", "file", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'], // encrypted key
		["backups/keys/file_id_16b.bin", "file", 'abcdefghijklmn', "0", '2025-12-12T04:27:13.000000Z'], // 16-byte deterministic ID
	];

	const treeFlat = ref<TreeFlat[]>(dummyTreeFlat);

	// State UI
	const expandedDirs = ref<Set<string>>(new Set(["/"])); // root selalu terbuka

	// Helper: ekstrak dir name dari path (untuk grouping)
	const getDirname = (path: string): string => {
		return path.endsWith("/")
			? path.slice(0, -1)
			: path.substring(0, path.lastIndexOf("/") + 1) || "/";
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
		return path.split("/").filter(Boolean).length - 1;
	};

  const downloadFile = (file:TreeFlat) => {
    const endpoint = route_file_download(file[2]);
    // Create a temporary link element
    const link = document.createElement('a');
    link.href = endpoint;
    link.setAttribute('download', getFilename(file[0])); // The 'download' attribute is useful but the server response handles the name
    // Append to the DOM (necessary for Firefox)
    document.body.appendChild(link);
    // Trigger the click
    link.click();
    // Clean up the element
    document.body.removeChild(link);
  }
  const deleteFile = async (file:TreeFlat) => {
    const index = treeFlat.value.indexOf(file);
    if(index > -1){
      const endpoint = route_file_delete(file[2], file[3]);
      await fetch(endpoint,{
        method: 'POST',
        headers: {
          "X-Requested-With": "XMLHttpRequest",
          "X-CSRF-TOKEN": getCSRFToken(),
          "Content-Type": "application/json",
        }
      }).then(response => response.json());
      treeFlat.value.splice(index, 1);
      alert(`File ${file[0]} is deleted`);
    }
  }
  const toWorkspace = async (file:TreeFlat) => {
    const endpoint = route_upload_make_workspace();
    const result = await fetch(endpoint).then(response => response.json());
    const workspaceName = result.workspace.name;
    alert(`File ${file[0]} is added to workspace ${workspaceName}`);
  }

  onMounted(async () => {
    // fetch list
    const data = await fetch(route_upload_get_list(), {
      method: 'POST',
      headers: {
        "X-Requested-With": "XMLHttpRequest",
        "X-CSRF-TOKEN": getCSRFToken(),
        "Content-Type": "application/json",
      }
    }).then(response => response.json());
    treeFlat.value = data.list.map((l:any) => {
      const hashManifest = l.hash_manifest;
      const hashFile = l.hash_blob;
      const path = l.path;
      const created_at = l.created_at;
      return [path, 'file', hashFile, hashManifest, created_at] as TreeFlat;
    });
  })
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
					<div
						v-if="dir !== '/'"
						class="flex items-center gap-2 py-2 cursor-pointer"
						@click="toggleDir(dir)"
					>
						<svg
							xmlns="http://www.w3.org/2000/svg"
							class="h-5 w-5 text-blue-600"
							viewBox="0 0 20 20"
							fill="currentColor"
						>
							<path
								fill-rule="evenodd"
								d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
								clip-rule="evenodd"
							/>
						</svg>
						<span class="font-medium text-blue-700">{{ dir }}</span>
						<span class="text-xs text-gray-500">
							({{ items.length }} items)
						</span>
					</div>

					<!-- Items in this directory -->
					<div
						v-show="isExpanded(dir) || dir === '/'"
						class="pl-4 border-l-2 border-gray-100 ml-6"
					>
          <!-- file = [path, type, hash, hashManifest, created_at] -->
						<div
							v-for="file in items"
							:key="file[0]"
							class="flex items-center gap-2 py-1.5 group"
							:class="{
								'pl-4': file[1] === 'file',
							}"
						>
							<!-- Icon -->
							<div class="flex-shrink-0 w-5">
								<svg
									v-if="file[1] === 'directory'"
									xmlns="http://www.w3.org/2000/svg"
									class="h-4 w-4 text-yellow-600"
									fill="none"
									viewBox="0 0 24 24"
									stroke="currentColor"
								>
									<path
										stroke-linecap="round"
										stroke-linejoin="round"
										stroke-width="2"
										d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z"
									/>
								</svg>
								<svg
									v-else
									xmlns="http://www.w3.org/2000/svg"
									class="h-4 w-4 text-gray-600"
									fill="none"
									viewBox="0 0 24 24"
									stroke="currentColor"
								>
									<path
										stroke-linecap="round"
										stroke-linejoin="round"
										stroke-width="2"
										d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
									/>
								</svg>
							</div>

							<!-- Path (basename only) -->
							<span
								class="text-sm font-mono"
								:class="{
									'font-semibold text-blue-800': file[1] === 'directory',
									'text-gray-700': file[1] === 'file',
								}"
							>
								{{ file[0].split("/").pop() || file[0] }}
							</span>

							<!-- Action (opsional) -->
							<button
								v-if="file[1] === 'file'"
                @click.stop="downloadFile(file)"
								class="btn-sm blue opacity-0 group-hover:opacity-100 transition-opacity"
							>
								Download
							</button>
							<button
								v-if="file[1] === 'file'"
                @click.stop="deleteFile(file)"
								class="btn-sm red opacity-0 group-hover:opacity-100 transition-opacity"
							>
								Delete
							</button>
							<button
								v-if="file[1] === 'file'"
								class="btn-sm green opacity-0 group-hover:opacity-100 transition-opacity"
							>
								To Workspace
							</button>
						</div>
					</div>
				</div>

				<!-- Empty state -->
				<div
					v-if="treeFlat.length === 0"
					class="text-center py-8 text-gray-500"
				>
					<p>No files uploaded yet.</p>
				</div>
			</div>
		</div>
	</div>
</template>
