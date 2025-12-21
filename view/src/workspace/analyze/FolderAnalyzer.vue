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
	import { computeDiff, DiffResult } from "./compare";
	import DiffSummary from "./DiffSummary.vue";
	import GeneralPrompt from "../../components/Prompt/GeneralPrompt.vue";
  import { route_manifest_search } from "../../helpers/listRoute";

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

			const { worker, result } = await makeFileNodeByWorker(
				dhFolderParam.value,
				dhFolderParam.value.name
			);
			dhFolderParam.value.relativePath = dhFolderParam.value.name;
			folderRoot.value = result;
			if (worker) worker.terminate();
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

	/**
	 * ------------------
	 * CREATE AND BUILD MANIFEST
	 * ------------------
	 */

	const loading = ref(false);
	const diff = ref<DiffResult>({
		identical: 0,
		changed: 0,
		added: 0,
		deleted: 0,
		total_changes: 0,
		changes: [],
	});
	const compared = ref(false);

	async function buildManifest() {
		if (dhFolderParam.value) {
			const workspace = new DhWorkspace(dhFolderParam.value);
			const manifest = await workspace.getManifest();
			return manifest;
		}
		error.value = "Failed to build manifest";
		throw new Error("Failed to build manifest");
	}

	async function fetchManifest(query: string) {
		return (await fetch(route_manifest_search(query)).then((r) => r.json())).manifest;
	}

	async function downloadManifest(query: string | null) {
		const manifest = await (query ? fetchManifest(query) : buildManifest());
		const jsonBlob = new Blob([JSON.stringify(manifest)], {
			type: "application/json",
		});
		const url = URL.createObjectURL(jsonBlob);
		const a = document.createElement("a");
		a.href = url;
		a.download = "manifest.json";
		a.style.display = "none";
		document.body.appendChild(a);
		a.click();
		document.body.removeChild(a);
		URL.revokeObjectURL(url);
	}

	async function compareManifest() {
    compared.value = true;
		loading.value = true;

    showGeneralPrompt.value = true;
    // eg: hash:aa4d977d2bf8d2775ae3c2fc93e97d2455f4ff52f8b082b1e24f86bd7eb18ba7
    // eg: label:v1.0.0
    const querySearchManifest = await onPromptOpen(); 

		const targetManifest = await buildManifest();
		const sourceManifest = await fetchManifest(querySearchManifest);
		const computeResult = await computeDiff(
			sourceManifest.files,
			targetManifest.files
		);
		diff.value = computeResult;
		loading.value = false;
	}

  /**
   * PROMPT
   */
  const showGeneralPrompt = ref(false);
  let promptActionResolve = (v:string) => {};
  async function onPromptOpen():Promise<string>{
    return new Promise((resolve) => {
      promptActionResolve = resolve;
    })  
  }
  async function onPromptResult(value:string | null){
    promptActionResolve(value ?? '');
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

			<div class="mt-4 text-sm text-gray-500 flex">
				<button
					@click="compareManifest"
					:disabled="isAnalyzing"
					class="ml-2 mt-4 px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-md transition flex items-center gap-2 disabled:opacity-50"
				>
					Compare Manifest
				</button>
				<button
					@click="downloadManifest(null)"
					:disabled="isAnalyzing"
					class="ml-2 mt-4 px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-md transition flex items-center gap-2 disabled:opacity-50"
				>
					Download Manifest
				</button>
			</div>
		</div>

		<!-- Diff Summary -->
		<div class="diff-summary mt-4" v-if="compared">
			<h1 class="text-2xl font-bold text-slate-800 mb-6">
				Perbandingan Workspace
			</h1>
			<DiffSummary :diff="diff" :loading="loading" />
		</div>

		<!-- Prompt -->
		<GeneralPrompt
			v-model="showGeneralPrompt"
			title="Get Manifest"
			message="Masukkan id, hash, source, version, atau tag untuk mencari manifest:"
			placeholder="label:v1.0.0"
			ok-text="Buat"
			cancel-text="Batal"
			@result="onPromptResult"
		/>
	</div>
</template>

<style scoped>
	/* Gaya global konsisten dengan contoh Anda */
	.upload-manager {
		font-family:
			-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
	}
</style>
