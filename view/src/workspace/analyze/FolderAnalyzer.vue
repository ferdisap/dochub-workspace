<script setup lang="ts">
	import { ref, computed, onMounted } from "vue";
	import FolderTreeNode from "./FolderTreeNode.vue";
	import { FileNode, countFiles, makeFileNodeByWorker } from "./folderUtils";
	import { DhFolderParam } from "../core/DhFile";
	import { DhWorkspace } from "../core/DhWorkspace";
	import { computeDiff, DiffResult } from "./compare";
	import DiffSummary from "./DiffSummary.vue";
	import GeneralPrompt from "../../components/Prompt/GeneralPrompt.vue";
	import { route_manifest_search } from "../../helpers/listRoute";
	import AccumulativeProgress from "./AccumulativeProgress.vue";
	import InlineNotification from "../../components/Notification/InlineNotification.vue";
	import TargetProgress from "../../upload/progress/TargetProgress.vue";
	import { EndPushData, ErrorPushData, PushManager } from "../push/PushManager";
	import {
		EndUploadData,
		ErrorUploadData,
		formatBytes,
		formatDuration,
		ProcessedUploadData,
		ProgressUploadData,
		UploadedData,
	} from "../../upload/ChunkUploadManager";

	const isAnalyzing = ref(false);
	const folderRoot = ref<FileNode | null>(null);
	const error = ref<string | null>(null);
	const selectedDirName = ref<string>("");
	const dhFolderParam = ref<DhFolderParam | null>(null);
	const fileProcessedQty = ref(0);
	const unitOfAccumulativeProgress = ref("file(s)");

	// loading param
	const isLoading = ref<boolean>(false);
	const isDoneScanning = ref<boolean>(false);
	const isDoneComparing = ref<boolean>(false);
	const visibilityNotification = ref<boolean>(false);

	// UI: format path (opsional)
	// const formatPath = (path: string) => {
	// 	return path.split("/").slice(1).join(" / ");
	// };

	// Action: pilih & scan folder
	async function scanFolder(): Promise<void> {
		isAnalyzing.value = true;
		error.value = null;
		folderRoot.value = null;
		isDoneScanning.value = false;
		isLoading.value = true;

		try {
			// @ts-ignore — types tersedia via `@types/wicg-file-system-access`
			dhFolderParam.value = await window.showDirectoryPicker();
			selectedDirName.value = dhFolderParam.value.name;

			const { worker, result } = await makeFileNodeByWorker(
				dhFolderParam.value,
				dhFolderParam.value.name,
				(entry) => {
					fileProcessedQty.value++;
				}
			);
			dhFolderParam.value.relativePath = dhFolderParam.value.name;
			folderRoot.value = result;
			if (worker) worker.terminate();
			isDoneScanning.value = true;
			isLoading.value = false;
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
	const diff = ref<DiffResult>({
		identical: 0,
		changed: 0,
		added: 0,
		deleted: 0,
		total_changes: 0,
		changes: [],
	});
	const querySearchManifest = ref<string>("");
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
		isLoading.value = true;
		fileProcessedQty.value = 1;
		// await new Promise((r) => setTimeout(() => r(true),5000));
		const manifest = (
			await fetch(route_manifest_search(query), {
				headers: {
					"X-Requested-With": "XMLHttpRequest",
				},
			}).then((r) => r.json())
		).manifests[0]; // perbaiki fungsi ini agar client bisa memilih
		isLoading.value = false;
		return manifest;
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
		isDoneComparing.value = false;
		isLoading.value = true;

		showGeneralPrompt.value = true;
		// eg: hash:aa4d977d2bf8d2775ae3c2fc93e97d2455f4ff52f8b082b1e24f86bd7eb18ba7
		// eg: label:v1.0.0
		try {
			querySearchManifest.value = await openPrompt();
		} catch (err) {
			compared.value = true;
			isDoneComparing.value = true;
			isLoading.value = true;
			return;
		}
		const targetManifest = await buildManifest();
		const sourceManifest = await fetchManifest(querySearchManifest.value);
		fileProcessedQty.value = 0;
		const computeResult = await computeDiff(
			sourceManifest.files,
			targetManifest.files,
			{},
			() => fileProcessedQty.value++
		);
		diff.value = computeResult;
		resultNotification.value = {
			message: "Compare success",
			title: "Compare workspace",
			type: "completed",
		};
		// await new Promise((r) => setTimeout(() => r(true),5000));
		isDoneComparing.value = true;
		isLoading.value = false;
		visibilityNotification.value = true;
	}

	/**
	 * --------
	 * PROMPT
	 * --------
	 */
	const showGeneralPrompt = ref(false);
	let promptActionResolve = (v: string) => {};
	let promptActionReject = () => {};
	async function openPrompt(): Promise<string> {
		showGeneralPrompt.value = true;
		return new Promise((resolve, reject) => {
			promptActionResolve = resolve;
			promptActionReject = reject;
		});
	}
	async function onPromptResult(value: string | null) {
		promptActionResolve(value ?? "");
	}
	async function onPromptCancel() {
		promptActionReject();
	}

	/**
	 * --------------
	 * NOTIFICATION
	 * --------------
	 */

	interface NotificationResult {
		type: "completed" | "failed" | "processing" | "uploaded";
		title: string;
		message: string;
		jobId?: string;
	}

	const resultNotification = ref<NotificationResult>({
		type: "completed",
		title: "Nothing",
		message: "No message",
	});

	/**
	 * ----------
	 * PUSHING
	 * ----------
	 */
	const pushing = ref<boolean>(false);
	const progressPushing = ref<number>(0);
	const speedPushing = ref<number>(0);
	const isPausedPushing = ref<boolean>(false);
	const pushManager = new PushManager();

	onMounted(() => {
		pushManager.onStartUpload = handleStartUpload;
		pushManager.onUploading = handleProgressUpload;
		pushManager.onUploaded = handleUploaded;
		pushManager.onProcessedUpload = handleProcessingUpload;
		pushManager.onEndUpload = handleCompletedUpload;
		pushManager.onErrorUpload = handleErrorUpload;
		pushManager.onEndPush = handleCompletedPush;
		pushManager.onErrorPush = handleErrorPush;
	});

	const uploadedBytes = ref<number>(0);
	const startTime = ref<number | null>(null);
	const handleStartUpload = () => {
		pushing.value = true;
		isPausedPushing.value = false;
		progressPushing.value = 0;
		speedPushing.value = 0;
		uploadedBytes.value = 0;
		startTime.value = Date.now();
		visibilityNotification.value = true;
		resultNotification.value = {
			type: "processing",
			title: "Push workspace",
			message: `Pushing workspace starts...`,
			jobId: "fetching...",
		};
	};
	const handleProgressUpload = (data: ProgressUploadData) => {
		// Calculate percentage 1 berdasarkan total size
		const percentage = (data.uploadedSize / data.totalBytes) * 100;
		progressPushing.value = Math.round(percentage);
		const chunkBytes = data.chunkSize;

		// Calculate speed 2
		const nowTime = Date.now();
		const elapsedTime = (Date.now() - startTime.value!) / 1000; // seconds
		if (elapsedTime > 1) {
			// > 1 agar di hitung per detik
			speedPushing.value = chunkBytes - (nowTime - startTime.value!) / 1000;
			startTime.value = Date.now();
		}
		return;
	};
	const handleUploaded = (data: UploadedData) => {
		const duration = formatDuration(Date.now() - startTime.value!);
		visibilityNotification.value = true;
		resultNotification.value = {
			type: "processing",
			title: "File Uploaded",
			message: `${data.fileName} is ${data.status}. Total size is ${formatBytes(data.totalBytes)}. Duration is ${duration}.`,
			jobId: "fetching...",
		};
	};
	const handleProcessingUpload = (data: ProcessedUploadData) => {
		const duration = formatDuration(Date.now() - startTime.value!);
		visibilityNotification.value = true;
		resultNotification.value = {
			type: "processing",
			title: "Processing Uploaded File",
			message: `${data.fileName} is ${data.status}. Total size is ${formatBytes(data.totalBytes)}. Duration is ${duration}.`,
			jobId: String(data.jobId),
		};
	};
	const handleCompletedUpload = (data: EndUploadData) => {
		const duration = formatDuration(Date.now() - startTime.value!);
		visibilityNotification.value = true;
		resultNotification.value = {
			type: "processing",
			title: "Uploaded File",
			message:
				"One file uploaded, " +
				`${data.fileName} is ${data.status}. Total size is ${formatBytes(data.totalBytes)}. Duration is ${duration}.`,
			jobId: data.jobId,
		};
	};
	const handleCompletedPush = (data: EndPushData) => {
		const duration = formatDuration(Date.now() - startTime.value!);
		visibilityNotification.value = true;
		resultNotification.value = {
			type: "completed",
			title: "Push workspace",
			message: data.message + `Push workspace takes time of ${duration}`,
			jobId: data.jobId,
		};
	};
	const handleErrorUpload = (data: ErrorUploadData) => {
		handleProgressUpload(data as ProgressUploadData);
		visibilityNotification.value = true;
		resultNotification.value = {
			type: data.status || "failed",
			title: "File Uploaded",
			message: data.error.message,
		};
	};
	const handleErrorPush = (data: ErrorPushData) => {
		const duration = formatDuration(Date.now() - startTime.value!);
		visibilityNotification.value = true;
		resultNotification.value = {
			type: "completed",
			title: "Push workspace",
			message: data.error.message + `Push workspace takes time of ${duration}`,
		};
	};
	const pauseResumePushing = () => {
		if (isPausedPushing.value) {
			pushManager.resume();
			isPausedPushing.value = false;
		} else {
			pushManager.pause();
			isPausedPushing.value = true;
		}
		return isPausedPushing.value;
	};
	const cancelPushing = () => {
		pushManager.cancel();
		pushing.value = false;
		isPausedPushing.value = false;
		resultNotification.value = {
			message: "Push failed",
			title: "Push workspace",
			type: "failed",
		};
	};
	async function pushManifest() {
		try {
			if (folderRoot.value) {
				try {
					querySearchManifest.value = await openPrompt();
				} catch (err) {
					visibilityNotification.value = true;
					resultNotification.value = {
						type: "failed",
						title: "Push workspace",
						message: "Push cancelled",
					};
				}
				const targetManifest = await buildManifest();
				const sourceManifest = await fetchManifest(querySearchManifest.value);
				await pushManager.push(
					sourceManifest,
					targetManifest,
					folderRoot.value
				);
			}
		} catch (err) {
			resultNotification.value = {
				type: "failed",
				title: "Push workspace",
				message: (err as Error).message,
			};
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

		<AccumulativeProgress
			:visible="isLoading"
			:total="fileProcessedQty"
			:unit="unitOfAccumulativeProgress"
			class="mt-4"
		/>

		<!-- Action Zone -->
		<div
			v-if="!isDoneScanning"
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
				v-if="!isAnalyzing"
				@click="scanFolder"
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
					Compare
				</button>
				<button
					@click="downloadManifest(null)"
					:disabled="isAnalyzing"
					class="ml-2 mt-4 px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-md transition flex items-center gap-2 disabled:opacity-50"
				>
					Download
				</button>
				<button
					@click="pushManifest"
					:disabled="isAnalyzing"
					class="ml-2 mt-4 px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-md transition flex items-center gap-2 disabled:opacity-50"
				>
					Push
				</button>
			</div>
		</div>

		<div class="process-zone mt-4" v-if="pushing">
			<TargetProgress
				:visible="pushing"
				:percentage="progressPushing"
				:speed="speedPushing"
			>
				<template #pause-resume>
					<button
						@click.stop="pauseResumePushing"
						:class="['control-btn', isPausedPushing ? 'resume' : 'pause']"
					>
						{{ isPausedPushing ? "Resume" : "Pause" }}
					</button>
					<button @click.stop="cancelPushing" class="control-btn cancel">
						Cancel
					</button>
				</template>
			</TargetProgress>
		</div>

		<InlineNotification
			:visible="visibilityNotification"
			:message="resultNotification.message"
			:title="resultNotification.title"
			:type="resultNotification.type"
			@close="visibilityNotification = false"
		>
		</InlineNotification>

		<!-- Diff Summary -->
		<div class="diff-summary mt-4" v-if="compared">
			<h1 class="text-2xl font-bold text-slate-800 mb-6">
				Perbandingan Workspace
			</h1>
			<DiffSummary
				:diff="diff"
				:loading="!isDoneComparing"
				:total="fileProcessedQty"
			/>
		</div>

		<!-- Prompt -->
		<GeneralPrompt
			v-model="showGeneralPrompt"
			title="Get Manifest"
			message="Masukkan id, hash, source, version, atau tag untuk mencari manifest:"
			placeholder="label:v1.0.0"
			ok-text="Buat"
			cancel-text="Batal"
			:default-value="querySearchManifest"
			@result="onPromptResult"
			@cancel="onPromptCancel"
		/>
	</div>
</template>

<style scoped>
	/* Gaya global konsisten dengan contoh Anda */
	.upload-manager {
		font-family:
			-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
	}
	.process-zone {
		border: 2px dashed #ccc;
		border-radius: 8px;
		padding: 3rem 2rem;
		text-align: center;
		cursor: pointer;
		transition: all 0.3s ease;
		background: #fafafa;
	}
</style>
