<script setup lang="ts">
	import { ref, computed, onMounted, toRaw, nextTick } from "vue";
	import FolderTreeNode from "./FolderTreeNode.vue";
	import {
		FileNode,
		countFiles,
		formatDate,
		makeFileNodeByWorker,
	} from "./folderUtils";
	import { DhFolderParam } from "../core/DhFile";
	import { DhWorkspace } from "../core/DhWorkspace";
	import { computeDiff, DiffResult } from "./compare";
	import DiffSummary from "./DiffSummary.vue";
	import InputPrompt from "../../components/Prompt/InputPrompt.vue";
	import { route_workspace_search, route_workspace_tree } from "../../helpers/listRoute";
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
	import { ManifestObject } from "../core/DhManifest";
	import {
		ListValue,
		ManifestModel,
		TreeMergeNode,
		WorkspaceNode,
	} from "./wsUtils";
	import MergeNetworkGraph from "./MergeNetworkGraph.vue";
	import ListPrompt from "../../components/Prompt/ListPrompt.vue";

	const isAnalyzing = ref(false);
	const folderRoot = ref<FileNode | null>(null);
	const sourceManifest = ref<Record<string, string>>({});
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
		// error.value = null;
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
				visibilityNotification.value = true;
				resultNotification.value = {
					message: "Pengguna membatalkan pemilihan folder",
					title: "Scan folder",
					type: "failed",
				};
				isLoading.value = false;
			} else {
				visibilityNotification.value = true;
				resultNotification.value = {
					message: `❌ Error: ${(err as Error).message}`,
					title: "Scan folder",
					type: "failed",
				};
				isLoading.value = false;
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
		visibilityNotification.value = true;
		resultNotification.value = {
			message: "Failed to build manifest",
			type: "failed",
			title: "Build manifest",
		};
		throw new Error("Failed to build manifest");
	}

	async function searchWorkspace(query: string) {
		// await new Promise((r) => setTimeout(() => r(true),5000));
		const result = await fetch(route_workspace_search(query), {
			headers: {
				"X-Requested-With": "XMLHttpRequest",
			},
		}).then((r) => r.json());
		const workspaceNodes = result.workspaces; // perbaiki fungsi ini agar client bisa memilih
		if (!workspaceNodes) {
			if (!result.ok || result.message) {
				throw new Error(result.message || result.data.message);
			}
		} else if (workspaceNodes.length < 1) {
			throw new Error(`Source manifest with ${query} not found`);
		}
		// open prompt to select workspace
		const workspaceNode = await openListPrompt<WorkspaceNode>(
			workspaceNodes.map(
				(node: WorkspaceNode) =>
					({
						id: node.created_at || node.name,
						text: node.name,
						value: node,
					}) as ListValue
			)
		);
		return workspaceNode;

	}

	async function fetchManifest(query: string) {
		isLoading.value = true;
		fileProcessedQty.value = 1;
		// open prompt to select workspace
		const workspaceNode = await searchWorkspace(query);
		// open prompt to select manifest
		const manifestObject = await openListPrompt<ManifestObject>(
			workspaceNode.manifests.map(
				(nodeManifest: ManifestModel) =>
					({
						id: nodeManifest.created_at,
						text: `version = ${nodeManifest.content.version}, create at = ${formatDate(new Date(nodeManifest.created_at))}`,
						value: nodeManifest.content,
					}) as ListValue
			)
		);
		isLoading.value = false;
		return manifestObject;
	}
	// async function fetchManifest(query: string) {
	// 	isLoading.value = true;
	// 	fileProcessedQty.value = 1;
	// 	// await new Promise((r) => setTimeout(() => r(true),5000));
	// 	const result = await fetch(route_manifest_search(query), {
	// 		headers: {
	// 			"X-Requested-With": "XMLHttpRequest",
	// 		},
	// 	}).then((r) => r.json());
	// 	const manifests = result.manifests; // perbaiki fungsi ini agar client bisa memilih
	// 	if (!manifests) {
	// 		if (!result.ok || result.message) {
	// 			throw new Error(result.message || result.data.message);
	// 		}
	// 	} else if (manifests.length < 1) {
	// 		throw new Error(`Source manifest with ${query} not found`);
	// 	}
	// 	isLoading.value = false;
	// 	return manifests[0];
	// }

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

		try {
			querySearchManifest.value = await openInputPrompt();
		} catch (err) {
			compared.value = true;
			isDoneComparing.value = true;
			isLoading.value = false;
			throw err;
		}
		const targetManifest = await buildManifest();
		let sourceManifest: ManifestObject;
		try {
			sourceManifest = await fetchManifest(querySearchManifest.value);
		} catch (err) {
			console.error(err);
			visibilityNotification.value = true;
			resultNotification.value = {
				message: (err as Error).message,
				title: "Compare workspace",
				type: "failed",
			};
			isDoneComparing.value = true;
			isLoading.value = false;
			throw err;
		}
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
	 * INPUT PROMPT
	 * --------
	 */
	const showInputPrompt = ref(false);
	let inputPromptActionResolve = (v: any) => {};
	let inputPromptActionReject = (err: string) => {};
	async function openInputPrompt(): Promise<string> {
		showInputPrompt.value = true;
		return new Promise((resolve, reject) => {
			inputPromptActionResolve = resolve;
			inputPromptActionReject = reject;
		});
	}
	const onPromptResult = async (value: any | null) => {
		showListPrompt.value = false;
		inputPromptActionResolve(value ?? "");
	};
	const onPromptCancel = async () => {
		showListPrompt.value = false;
		inputPromptActionReject("Cancelled");
	};

	/**
	 * ------------
	 * LIST PROMPT
	 * ------------
	 */
	// {id: 'fufu', text: 'fufu juga', value: {aaa:'bbb'}},
	// {id: 'fufu2', text: 'fufu2 juga', value: {aaa:'ccc'}},
	// {id: 'fufu3', text: 'fufu3 juga', value: {aaa:'bbb'}},
	// {id: 'fufu4', text: 'fufu4 juga', value: {aaa:'ccc'}},
	// {id: 'fufu5', text: 'fufu5 juga', value: {aaa:'bbb'}},
	// {id: 'fufu6', text: 'fufu6 juga', value: {aaa:'ccc'}},
	const showListPrompt = ref(false);
	const listPrompt = ref<ListValue[]>([]);
	async function openListPrompt<TData>(listValue: ListValue[]): Promise<TData> {
		listPrompt.value = listValue;
		showListPrompt.value = true;
		return new Promise((resolve, reject) => {
			inputPromptActionResolve = resolve;
			inputPromptActionReject = reject;
		});
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
			message: data.message + `. Push workspace takes time of ${duration}`,
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
					querySearchManifest.value = await openInputPrompt();
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

	/**
	 * --------------
	 * NETWORK GRAPH
	 * --------------
	 */
  // {
  // 	id: "m1",
  // 	label: "v1.0",
  // 	merged_at: "2025-12-01T10:00:00Z",
  // 	message: "Initial workspace setup",
  // 	children: [
  // 		{
  // 			id: "m2",
  // 			label: "v1.1",
  // 			merged_at: "2025-12-02T11:00:00Z",
  // 			message: "Add auth module",
  // 			children: [
  // 				{
  // 					id: "m4",
  // 					label: "fix",
  // 					merged_at: "2025-12-03T09:00:00Z",
  // 					message: "Fix login bug",
  // 					children: [],
  // 				},
  // 			],
  // 		},
  // 		{
  // 			id: "m3",
  // 			label: "feat/login",
  // 			merged_at: "2025-12-02T14:00:00Z",
  // 			message: "WIP: New login UI",
  // 			children: [
  // 				{
  // 					id: "m5",
  // 					label: "v2.0",
  // 					merged_at: "2025-12-04T16:00:00Z",
  // 					message: "Major redesign",
  // 					children: [],
  // 				},
  // 			],
  // 		},
  // 	],
  // },

	const treeData = ref<TreeMergeNode[]>([
	]);

	async function drawNetworkWorkspace() {
		isLoading.value = true;
		try {
			querySearchManifest.value = await openInputPrompt();
		} catch (err) {
			isLoading.value = false;
			throw err;
		}

		let workspaceNode: WorkspaceNode;
		try {
			workspaceNode = await searchWorkspace(querySearchManifest.value);
		} catch (err) {
			visibilityNotification.value = true;
			resultNotification.value = {
				message: (err as Error).message,
				title: "Draw network workspace",
				type: "failed",
			};
			isLoading.value = false;
			throw err;
		}

    const tree = (await fetch(route_workspace_tree(workspaceNode.name), {
      headers: {
				"X-Requested-With": "XMLHttpRequest",
			},
		}).then((r) => r.json())).tree as TreeMergeNode[];

    treeData.value = tree;
    isLoading.value = false;
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
				<button
					@click="drawNetworkWorkspace"
					:disabled="isAnalyzing"
					class="ml-2 mt-4 px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-md transition flex items-center gap-2 disabled:opacity-50"
				>
					Network
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
				Workspace Comparation
			</h1>
			<DiffSummary
				:diff="diff"
				:loading="!isDoneComparing"
				:total="fileProcessedQty"
			/>
		</div>

		<!-- Network Graph -->
		<div class="p-6 max-w-6xl mx-auto" v-if="treeData.length">
			<h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-4">
				Workspace Merge Network
			</h1>
			<MergeNetworkGraph :tree-data="treeData" />
		</div>

		<!-- Prompt -->
		<InputPrompt
			v-model="showInputPrompt"
			title="Get Workspace"
			message="Masukkan id, hash, source, version, atau tag untuk mencari manifest:"
			placeholder="label:v1.0.0"
			ok-text="Buat"
			cancel-text="Batal"
			:default-value="querySearchManifest"
			@result="onPromptResult"
			@cancel="onPromptCancel"
		/>
		<ListPrompt
			:visibility="showListPrompt"
			:list="listPrompt"
			@select="onPromptResult"
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
