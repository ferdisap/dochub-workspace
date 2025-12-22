<script setup lang="ts">
	import { ref, onMounted, computed } from "vue";
	import {
		ChunkedUploadManager,
		EndData,
		ErrorData,
		formatBytes,
		formatDuration,
		ProcessedData,
		ProgressData,
		UploadedData,
	} from "./ChunkUploadManager";
	import EncryptDecrypt from "../encryption/EncryptDecrypt.vue";
	import TargetProgress from "./progress/TargetProgress.vue";
	import InlineNotification from "../components/Notification/inlineNotification.vue";

	const environment = ref("detecting...");
	const envClass = computed(() => {
		switch (environment.value) {
			case "shared":
				return "env-shared";
			case "serverless":
				return "env-serverless";
			case "dedicated":
				return "env-dedicated";
			case "container":
				return "env-container";
			case "development":
				return "env-development";
			default:
				return "env-development";
		}
	});

	const fileInput = ref<HTMLInputElement | null>(null);
	const strategy = ref<string>("auto");
	const result = ref<null | {
		type: "completed" | "failed" | "processing" | "uploaded";
		title: string;
		message: string;
		jobId?: string;
	}>(null);
	const maxSize = ref<number>(0);
	const uploading = ref(false);
	const isPaused = ref(false);
	const progress = ref(0);
	const startTime = ref<number | null>(null);
	const uploadedBytes = ref(0);
	const fileBag = ref<File[] | FileList | null>(null);

	const speed = ref(0);
	const status = ref<{ uploadId: string; status: string } | null>(null);
	const uploadManager = new ChunkedUploadManager();

  const visibleNotification = ref<boolean>(false);

	const browseInfo = computed(() => {
		if (fileBag.value) {
			const files = fileBag.value;
			if (files) {
				const total = files?.length;
				const size = formatBytes(
					Array.from(files, (file) => file.size).reduce(
						(accumulator, currentValue) => accumulator + currentValue,
						0
					)
				);
				const names = Array.from(files, (file) => file.name).join(", ");
				return `Total ${size}, ${total} file. \n ${names.substring(0, 50)}`;
			}
		}
	});

	onMounted(async () => {
		uploadManager.onStart = handleStart;
		uploadManager.onProgress = handleProgress;
		uploadManager.onUploaded = handleUploaded;
		uploadManager.onProcessed = handleProcessing;
		uploadManager.onEnd = handleCompleted;
		uploadManager.onError = handleError;

		await uploadManager.initialize();
		strategy.value = uploadManager.config().driver;
		maxSize.value = uploadManager.config().max_size;
		environment.value = uploadManager.config().environment;
	});

	const openFilePicker = () => {
		if (isPaused.value) return;
		fileInput.value!.click();
	};

	const handleFileSelect = async (event: InputEvent) => {
		const fileList = fileInput.value!.files!;
		if (fileList.length === 0) return;
		if (fileList) {
			fileBag.value = fileList;
		}
	};

	const handleDrop = (event: DragEvent) => {
		if (isPaused.value) return;
		const fileList = event.dataTransfer?.files;
		// const files = Array.from(fileList!);
		if (fileList) {
			fileBag.value = fileList;
		}
	};

	const submitUpload = async () => {
		if (fileBag.value) await processFiles(fileBag.value!);
		fileInput.value!.value = "";
	};

	const handleStart = () => {
		uploading.value = true;
		isPaused.value = false;
		progress.value = 0;
		speed.value = 0;
		uploadedBytes.value = 0;
		startTime.value = Date.now();
		result.value = null;
	};

	const handleProgress = (data: ProgressData) => {
		// Calculate percentage 1 berdasarkan total size
		const percentage = (data.uploadedSize / data.totalBytes) * 100;
		progress.value = Math.round(percentage);
		const chunkBytes = data.chunkSize;

		// Calculate speed 2
		const nowTime = Date.now();
		const elapsedTime = (Date.now() - startTime.value!) / 1000; // seconds
		if (elapsedTime > 1) {
			// > 1 agar di hitung per detik
			speed.value = chunkBytes - (nowTime - startTime.value!) / 1000;
			startTime.value = Date.now();
		}
		return;

		// Calculate speed 1, waktu didapat sejak awal sebelum upload chunk pertama. Jadi semakin selesai diupload, semakin kecil speednya
		// const bytesUploaded = data.uploadedSize;
		// const now = Date.now();
		// const elapsed = (now - startTime.value!) / 1000; // seconds
		// if (elapsed > 1) {
		//   speed.value =
		//     ((bytesUploaded - uploadedBytes.value) / ((now - startTime.value!)/1000));
		//   uploadedBytes.value = bytesUploaded;

		//   // startTime.value = Date.now();
		//   console.log(speed.value, data.chunkSize, data.uploadedSize);
		// }
	};

	const handleCompleted = (data: EndData) => {
		uploading.value = false;
		const duration = formatDuration(Date.now() - startTime.value!);
    showError(
      "Upload Completed", 
      `${data.fileName} is ${data.status}. Total size is ${formatBytes(data.totalBytes)}. Duration is ${duration}.`, 
      "completed", 
      data.jobId
    );
	};

	const handleUploaded = (data: UploadedData) => {
		const duration = formatDuration(Date.now() - startTime.value!);
		result.value = {
			type: data.status,
			title: "File Uploaded",
			message: `${data.fileName} is ${data.status}. Total size is ${formatBytes(data.totalBytes)}. Duration is ${duration}.`,
			jobId: "fetching...",
		};
	};

	const handleProcessing = (data: ProcessedData) => {
		const duration = formatDuration(Date.now() - startTime.value!);
		result.value = {
			type: data.status,
			title: "Processing Uploaded File",
			message: `${data.fileName} is ${data.status}. Total size is ${formatBytes(data.totalBytes)}. Duration is ${duration}.`,
			jobId: String(data.jobId),
		};
	};

	const handleError = (data: ErrorData) => {
		handleProgress(data as ProgressData);
		handleCompleted({
			fileName: data.fileName,
			uploadId: data.uploadId,
			totalChunks: data.totalChunks,
			totalBytes: data.totalBytes,
			jobId: "0",
			status: data.status,
			url: "",
		});
		showError("Upload error", data.error.message);
	};

	const processFiles = async (files: FileList | Array<File>) => {
		if (files.length === 0) return;

		const file = files[0];

		// Validate file
		if (
			!file.name.toLowerCase().endsWith(".zip") &&
			!file.name.toLowerCase().endsWith(".tar") &&
			!file.name.toLowerCase().endsWith(".gz")
		) {
			showError("Invalid file type", "Only ZIP, TAR, GZ files allowed");
			return;
		}

		if (file.size > maxSize.value) {
			showError("File too large", `Max size: ${formatBytes(maxSize.value)}`);
			return;
		}

		let uploadId: string;
		try {
			uploadId = await uploadManager.upload(file);
			await pollStatus(uploadId);
		} catch (error) {
			console.error((error as Error).message);
			showError("Upload failed", (error as Error).message);
			// console.error(error);
		}
	};

	const showError = (title: string, message: string, type: "completed" | "failed" | "processing" | "uploaded" = "failed", jobId = "") => {
		result.value = { type: type, title, message, jobId:jobId };
  	visibleNotification.value = true;
	};

	const pollStatus = async (uploadId: string) => {
		const interval = setInterval(async () => {
			if (uploading.value || isPaused.value) {
				try {
					const stt = await uploadManager.getStatus(uploadId);
					// console.log(stat);
					status.value = { uploadId, status: stt.status };
					if (stt.status === "completed" || stt.failed) {
						clearInterval(interval);
					}
				} catch (error) {
					console.error("Status check failed:", error);
					clearInterval(interval);
				}
			} else {
				clearInterval(interval);
			}
		}, 2000);
	};

	const cancel = () => {
		uploadManager.cancel();
		uploading.value = false;
		isPaused.value = false;
		showError("Upload Failed", "Upload Cancelled");
	};

	const pauseResume = () => {
		if (isPaused.value) {
			uploadManager.resume();
			isPaused.value = false;
		} else {
			uploadManager.pause();
			isPaused.value = true;
		}

		return isPaused.value;
	};

	const checkStatus = async () => {
		if (status.value?.uploadId) {
			const stt = await uploadManager.getStatus(status.value.uploadId);
			status.value = { uploadId: status.value?.uploadId, status: stt.status };
		}
	};

	const onSuccessEncrypted = (filesName: { name: string }[] = []) => {
		result.value = {
			type: "completed",
			title: "Encryption success",
			message: "All files has is encrypted",
		};
	};
	const onFailEncrypted = (filesName: { name: string }[] = []) => {
		result.value = {
			type: "failed",
			title: "Encryption failed",
			message: filesName.map((file) => file.name).join(", "),
		};
	};
	const onSuccessDecrypted = (filesName: { name: string }[] = []) => {
		result.value = {
			type: "completed",
			title: "Decryption success",
			message: "All files has is decrypted",
		};
	};
	const onFailDecrypted = (filesName: { name: string }[] = []) => {
		result.value = {
			type: "failed",
			title: "Decryption failed",
			message: filesName.map((file) => file.name).join(", "),
		};
	};
	const onError = (message: string) => {
		result.value = {
			type: "failed",
			title: "Encrypt / Decrypt failed",
			message: message,
		};
	};
</script>

<template>
	<div class="upload-manager">
		<!-- Status Environment -->
		<div class="env-badge" :class="envClass">
			<span>Environment: {{ environment }}</span>
			<span>Strategy: {{ strategy }}</span>
		</div>

		<!-- Upload Zone -->
		<div class="upload-zone" @dragover.prevent @drop.prevent="handleDrop">
			<div v-if="!uploading" class="file">
				<div class="svg" title="browse" @click="openFilePicker">
					<svg
						xmlns="http://www.w3.org/2000/svg"
						width="48"
						height="48"
						viewBox="0 0 24 24"
						fill="none"
						stroke="currentColor"
					>
						<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
						<polyline points="17 8 12 3 7 8"></polyline>
						<line x1="12" y1="3" x2="12" y2="15"></line>
					</svg>
				</div>
				<div class="info">
					<p>Drag & drop ZIP file here</p>
					<p class="hint">or click to browse</p>
					<p class="limits">Max: {{ formatBytes(maxSize) }}</p>
					<p class="browse-info">{{ browseInfo }}</p>
					<p class="hint">
						open your list uploaded here
						<a class="underline text-blue-500" href="/dochub/upload/list"
							>here</a
						>
					</p>
				</div>
				<div class="svg" title="upload" @click.stop="submitUpload">
					<svg
						xmlns="http://www.w3.org/2000/svg"
						width="48"
						height="48"
						viewBox="0 0 24 24"
						fill="none"
						stroke="currentColor"
						stroke-width="2"
						stroke-linecap="round"
						stroke-linejoin="round"
						class="lucide lucide-send-icon lucide-send"
					>
						<path
							d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"
						/>
						<path d="m21.854 2.147-10.94 10.939" />
					</svg>
				</div>
			</div>

			<EncryptDecrypt
				v-if="!uploading"
				:files="fileBag"
				@success-encrypted="onSuccessEncrypted"
				@fail-encrypted="onFailEncrypted"
				@success-decrypted="onSuccessDecrypted"
				@fail-decrypted="onFailDecrypted"
				@error="onError"
			/>

			<!-- Progress -->
			<TargetProgress
				:visible="uploading"
				:percentage="progress"
				:speed="speed"
			>
				<template #pause-resume>
					<button
						@click.stop="pauseResume"
						:class="['control-btn', isPaused ? 'resume' : 'pause']"
					>
						{{ isPaused ? "Resume" : "Pause" }}
					</button>
					<button @click.stop="cancel" class="control-btn cancel">
						Cancel
					</button>
				</template>
			</TargetProgress>
		</div>

		<!-- Results -->
    <InlineNotification v-if="true"
			:visible="Boolean(result) && visibleNotification"
			:type="result?.type || 'completed'"
			:title="result?.title || 'asasa'"
			:message="result?.message || 'asasa'"
      @close="visibleNotification = false"
		>
      <template #job-info>
        <div v-if="result?.jobId" class="job-info">
          <span>Job ID: {{ result.jobId }}</span>
          <button @click="checkStatus" class="btn-sm">Check Status</button>
        </div>
      </template>
		</InlineNotification>

		<!-- Hidden file input -->
		<input
			type="file"
			ref="fileInput"
			multiple
			@change="handleFileSelect($event as InputEvent)"
			class="hidden"
		/>
		<!-- accept=".zip,.tar,.gz" -->
	</div>
</template>
