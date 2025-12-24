<script setup lang="ts">
	import { getFilename } from "../../helpers/uri";
	import {
		route_file_delete,
		route_file_download,
		route_upload_get_list,
		route_upload_make_workspace,
		route_upload_make_workspace_status,
	} from "../../helpers/listRoute";
	import { getCSRFToken } from "../../helpers/toDom";
	import { ref, computed, onMounted } from "vue";
import InlineNotification from "../../components/Notification/InlineNotification.vue";

	// Tipe sesuai dengan respons Laravel
	type TreeNodeType = "file" | "directory";
	type TreeFlat = [
		path: string,
		type: TreeNodeType,
		hash: string,
		hashManifest: string,
		created_at?: string,
	];

	const treeFlat = ref<TreeFlat[]>([]);
	const result = ref<null | {
		type: "completed" | "failed" | "processing";
		title: string;
		message: string;
		jobId?: string;
	}>(null);

	// result.value = {
	//   "type": 'processing',
	//   "title": 'FUFU',
	//   "message": 'fafa',
	//   "jobId": '0'
	// };

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

	const downloadFile = (file: TreeFlat) => {
		const endpoint = route_file_download(file[2]);
		// Create a temporary link element
		const link = document.createElement("a");
		link.href = endpoint;
		link.setAttribute("download", getFilename(file[0])); // The 'download' attribute is useful but the server response handles the name
		// Append to the DOM (necessary for Firefox)
		document.body.appendChild(link);
		// Trigger the click
		link.click();
		// Clean up the element
		document.body.removeChild(link);
		result.value = {
			type: "completed",
			title: "File Downloaded",
			message: `${file[0]} is downloaded.`,
			jobId: "0",
		};
	};
	const deleteFile = async (file: TreeFlat) => {
		const index = treeFlat.value.indexOf(file);
		if (index > -1) {
			const endpoint = route_file_delete(file[2], file[3]);
			await fetch(endpoint, {
				method: "POST",
				headers: {
					"X-Requested-With": "XMLHttpRequest",
					"X-CSRF-TOKEN": getCSRFToken(),
					"Content-Type": "application/json",
				},
			}).then((response) => response.json());
			treeFlat.value.splice(index, 1);
			result.value = {
				type: "completed",
				title: "File Deleted",
				message: `${file[0]} is deleted.`,
				jobId: "0",
			};
		}
	};
	const toWorkspace = async (file: TreeFlat) => {
		const manifestId = file[3];
		const endpoint = route_upload_make_workspace(manifestId);
		// const result =
		await fetch(endpoint, {
			method: "POST",
			headers: {
				"X-Requested-With": "XMLHttpRequest",
				"X-CSRF-TOKEN": getCSRFToken(),
				"Content-Type": "application/json",
			},
		});
		// .then((response) => response.json());
		// if (result.status === "processing") {
		// result.value = {
		//   type: "processing",
		//   title: "File is being processed to imported workspace",
		//   message: `${file[0]} is deleted.`,
		//   jobId: "0",
		// };
		// } else {
		//   const workspaceName = result.workspace.name;
		//   result.value = {
		//     type: result.status,
		//     title: "File is imported to workspace",
		//     message: `${file[0]} is deleted.`,
		//     jobId: "0",
		//   };
		//   alert(`File ${file[0]} is added to workspace ${workspaceName}`);
		// }
		result.value = {
			type: 'processing',
			title: "File is being imported to workspace",
			message: `${file[0]} is processed.`,
			jobId: "0",
		};
		await pollStatus(file);
	};

	const pollStatus = async (file: TreeFlat) => {
    const manifestId = file[3];
		const interval = setInterval(async () => {
			const stt = await fetch(
				route_upload_make_workspace_status(manifestId), {
          method: 'GET',
          headers: {
            "X-Requested-With": "XMLHttpRequest",
          }
        }
			).then((r) => r.json());
			if (stt.status === "completed" || stt.failed) {
				clearInterval(interval);
				result.value = {
					type: stt.status,
					title: "Make Worksapce",
					message: `Make workspace from uploaded ${file[0]} is done.`,
					jobId: stt.job_id,
				};
			}
		}, 2000);
	};

  const checkStatus = () => {}

	onMounted(async () => {
		// fetch list
		const data = await fetch(route_upload_get_list(), {
			method: "POST",
			headers: {
				"X-Requested-With": "XMLHttpRequest",
				"X-CSRF-TOKEN": getCSRFToken(),
				"Content-Type": "application/json",
			},
		}).then((response) => response.json());
		treeFlat.value = data.list.map((l: any) => {
			const hashManifest = l.hash_manifest;
			const hashFile = l.hash_blob;
			const path = l.path;
			const created_at = l.created_at;
			return [path, "file", hashFile, hashManifest, created_at] as TreeFlat;
		});
	});
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

      <InlineNotification v-if="result" :visible="Boolean(result)" :type="result?.type" :title="result.title" :message="result.message">
        <template #job-info>
          <div v-if="result.jobId" class="job-info">
						<span class="mr-1 text-sm">Job ID: {{ result.jobId }}</span>
						<button @click="checkStatus" class="btn-sm">Check Status</button>
					</div>
        </template>
      </InlineNotification>
			<!-- <div v-if="result" class="result" :class="result.type">
				<div class="result-icon">
					<svg
						v-if="result.type === 'completed'"
						xmlns="http://www.w3.org/2000/svg"
						width="24"
						height="24"
						viewBox="0 0 24 24"
						fill="none"
						stroke="currentColor"
					>
						<polyline points="20 6 9 17 4 12"></polyline>
					</svg>
					<svg
						v-else
						xmlns="http://www.w3.org/2000/svg"
						width="24"
						height="24"
						viewBox="0 0 24 24"
						fill="none"
						stroke="currentColor"
					>
						<circle cx="12" cy="12" r="10"></circle>
						<line x1="12" y1="8" x2="12" y2="12"></line>
						<line x1="12" y1="16" x2="12.01" y2="16"></line>
					</svg>
				</div>
				<div class="result-content">
					<h3>{{ result.title }}</h3>
					<p>{{ result.message }}</p>
					<div v-if="result.jobId" class="job-info">
						<span class="mr-1 text-sm">Job ID: {{ result.jobId }}</span>
						<button @click="checkStatus" class="btn-sm">Check Status</button>
					</div>
				</div>
			</div> -->

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
								@click.stop="toWorkspace(file)"
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
