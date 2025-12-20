<script setup lang="ts">
	import { ref, computed } from "vue";
	import FolderTreeNode from "./FolderTreeNode.vue";
	import {
		makeFileNode,
		FileNode,
		countFiles,
		makeFileNodeByWorker,
	} from "./folderUtils";
	import { DhFolderParam } from "../core/DhFile";
	import { DhWorkspace } from "../core/DhWorkspace";

	const isAnalyzing = ref(false);
	const folderRoot = ref<FileNode | null>(null);
	const error = ref<string | null>(null);
	const selectedDirName = ref<string>("");
	const isDone = ref<boolean>(false);
	const dhFolderParam = ref<DhFolderParam | null>(null);

	// UI: format path (opsional)
	const formatPath = (path: string) => {
		return path.split("/").slice(1).join(" / ");
	};

	// Action: pilih & scan folder
	async function analyzeFolder(): Promise<void> {
		isAnalyzing.value = true;
		error.value = null;
		folderRoot.value = null;

		try {
			// @ts-ignore — types tersedia via `@types/wicg-file-system-access`
			dhFolderParam.value = await window.showDirectoryPicker();
			selectedDirName.value = dhFolderParam.value.name;

				const { worker, result } = await makeFileNodeByWorker(dhFolderParam.value,dhFolderParam.value.name);
				dhFolderParam.value.relativePath = dhFolderParam.value.name;
				folderRoot.value = result;
				if(worker) worker.terminate();
				isDone.value = true;
		} catch (err) {
			if ((err as Error).name === "AbortError") {
				error.value = "Pengguna membatalkan pemilihan folder.";
			} else {
				console.error("❌ Error:", err);
				error.value = `Gagal: ${(err as Error).message}`;
			}
		} finally {
			isAnalyzing.value = false;
		}
	}

	// Helper: toggle semua
	const toggleAll = () => {
		if (!folderRoot.value) return;
		const toggleRecursively = (node: FileNode, expand: boolean) => {
			if (node.kind === "directory") {
				node.expanded = expand;
				node.children?.forEach((child) => toggleRecursively(child, expand));
			}
		};
		const shouldBeExpanded = !folderRoot.value.expanded;
		toggleRecursively(folderRoot.value, shouldBeExpanded);
		folderRoot.value.expanded = shouldBeExpanded;
	};

	// Computed stats
	const totalItems = computed(() => {
		return folderRoot.value ? countFiles(folderRoot.value) : 0;
	});

	async function makeManifest() {
		if (dhFolderParam.value) {
			const workspace = new DhWorkspace(dhFolderParam.value);
			const manifest = await workspace.getManifest();
			console.log((top.manifest = manifest));
		}
	}
</script>

<template>
	<div class="upload-manager max-w-4xl mx-auto">
		<!-- Header -->
		<!-- <div class="card-header">
			<h1 class="title">📁 Analisis Struktur Folder</h1>
			<p class="mt-2 text-gray-600 text-sm">
				Pilih folder project untuk dianalisis — semua file & subfolder akan
				dipindai secara rekursif.
			</p>
		</div> -->

		<!-- Environment-like Badge -->
		<div class="env-badge env-development mt-4">
			<span>📁 Folder: {{ selectedDirName || "Belum dipilih" }}</span>
			<span v-if="folderRoot">📁 {{ totalItems }} items</span>
		</div>

		<!-- Action Zone -->
		<div
			v-if="!isDone"
			class="upload-zone flex flex-col items-center justify-center py-12"
			:class="{ 'cursor-not-allowed opacity-75': isAnalyzing }"
		>
			<p class="info text-lg font-medium text-gray-700">
				{{
					isAnalyzing
						? "Sedang memindai..."
						: "Klik tombol di bawah untuk memilih folder"
				}}
			</p>

			<p v-if="!isAnalyzing" class="hint mt-2">
				Hanya didukung di Chrome/Edge (File System Access API)
			</p>

			<p v-if="!isAnalyzing" class="hint mt-2">
				Does not support symbolic link
			</p>

			<button
				@click="analyzeFolder"
				:disabled="isAnalyzing"
				class="mt-4 px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-md transition flex items-center gap-2 disabled:opacity-50"
			>
				Pilih & Analisis Folder
			</button>
		</div>

		<!-- Error Alert -->
		<div v-if="error" class="result failed mt-6">
			<div class="result-icon">
				<svg
					xmlns="http://www.w3.org/2000/svg"
					width="24"
					height="24"
					viewBox="0 0 24 24"
					fill="none"
					stroke="currentColor"
				>
					<circle cx="12" cy="12" r="10" />
					<line x1="12" y1="8" x2="12" y2="12" />
					<line x1="12" y1="16" x2="12.01" y2="16" />
				</svg>
			</div>
			<div class="result-content">
				<h3 class="font-bold">Gagal Memindai Folder</h3>
				<p>{{ error }}</p>
			</div>
		</div>

		<!-- Tree View -->
		<div v-if="folderRoot && !isAnalyzing" class="mt-8">
			<div class="flex justify-between items-center mb-3">
				<h2 class="text-xl font-bold text-gray-800">Struktur Folder</h2>
				<button
					@click="toggleAll"
					class="text-sm text-blue-600 hover:text-blue-800"
				>
					{{ folderRoot.expanded ? "Tutup Semua" : "Buka Semua" }}
				</button>
			</div>

			<div
				class="bg-white border border-gray-200 rounded-lg overflow-hidden shadow-sm"
			>
				<FolderTreeNode :node="folderRoot" />
			</div>

			<div class="mt-4 text-sm text-gray-500">
				<p>
					📁 Root: <code>{{ folderRoot.name }}</code>
				</p>
				<p>
					📝 Total item: <span class="font-mono">{{ totalItems }}</span>
				</p>
			</div>

			<div class="mt-4 text-sm text-gray-500">
				<button
					@click="makeManifest"
					:disabled="isAnalyzing"
					class="mt-4 px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-md transition flex items-center gap-2 disabled:opacity-50"
				>
					Create Manifest
				</button>
			</div>
		</div>
	</div>
</template>

<style scoped>
	/* Gaya global konsisten dengan contoh Anda */
	.upload-manager {
		font-family:
			-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
	}
</style>
