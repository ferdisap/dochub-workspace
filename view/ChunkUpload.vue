<template>
    <div class="upload-manager">
        <!-- Status Environment -->
        <div class="env-badge" :class="envClass">
            <span>Environment: {{ environment }}</span>
            <span>Strategy: {{ strategy }}</span>
        </div>

        <!-- Upload Zone -->
        <div
            class="upload-zone"
            @dragover.prevent
            @drop.prevent="handleDrop"
            @click="openFilePicker"
        >
            <div v-if="!uploading">
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
                <p>Drag & drop ZIP file here</p>
                <p class="hint">or click to browse</p>
                <p class="limits">Max: {{ formatBytes(maxSize) }} • ZIP only</p>
            </div>

            <!-- Progress -->
            <div v-else class="progress-container">
                <div class="progress-bar">
                    <div
                        class="progress-fill"
                        :style="{ width: `${progress}%` }"
                    ></div>
                </div>
                <div class="progress-info">
                    <div class="progress-percent-speed">
                      <span class="progress-percent">{{ progress }}%</span>&#160;<span class="progress-speed" v-if="speed">{{ formatBytes(speed) }}/s</span>
                    </div>
                    <button @click.stop="pauseResume" class="control-btn">
                        {{ isPaused ? "Resume" : "Pause" }}
                    </button>
                    <button @click.stop="cancel" class="control-btn cancel">
                        Cancel
                    </button>
                </div>
            </div>
        </div>

        <!-- Results -->
        <div v-if="result" class="result" :class="result.type">
            <div class="result-icon">
                <svg
                    v-if="result.type === 'success'"
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
                    <span>Job ID: {{ result.jobId }}</span>
                    <button @click="checkStatus" class="btn-sm">
                        Check Status
                    </button>
                </div>
            </div>
        </div>

        <!-- Hidden file input -->
        <input
            type="file"
            ref="fileInput"
            @change="handleFileSelect($event as InputEvent)"
            accept=".zip,.tar,.gz"
            class="hidden"
        />
    </div>
</template>

<script setup lang="ts">
import { ref, onMounted, computed } from "vue";
import {
    ChunkedUploadManager,
    EndData,
    ErrorData,
    formatBytes,
    ProgressData,
} from "./ChunkUploadManager";

const environment = ref("detecting...");
const envClass = computed(() => ({
    "env-shared": environment.value === "shared",
    "env-dedicated": environment.value === "dedicated",
    "env-container": environment.value === "container",
}));

const fileInput = ref<HTMLElement | null>(null);
const strategy = ref<string>("auto");
const result = ref<null | {
    type: string;
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

const speed = ref(0);
// const status = ref(null);
const uploadManager = new ChunkedUploadManager();

onMounted(async () => {
    uploadManager.onStart = handleStart;
    uploadManager.onProgress = handleProgress;
    uploadManager.onEnd = handleUploadComplete;
    uploadManager.onError = handleError;

    await uploadManager.initialize();
    strategy.value = uploadManager.config().driver;
    maxSize.value = uploadManager.config().max_size;
});

const openFilePicker = () => {
    if(isPaused.value) return;
    fileInput.value!.click();
};

const handleFileSelect = async (event: InputEvent) => {
    const files = (event.target as HTMLInputElement).files!;
    if (files.length === 0) return;
    // const file = files[0];
    await processFiles(files);
    (event.target as HTMLInputElement).value = "";
};

const handleDrop = (event: DragEvent) => {
    if(isPaused.value) return;
    const filelist = event.dataTransfer?.files;
    const files = Array.from(filelist!);
    processFiles(files);
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

const handleUploadComplete = (data: EndData) => {
    uploading.value = false;
    result.value = {
        type: data.status
            ? data.status === "error"
                ? data.status
                : "success"
            : "success",
        title: "Upload Completed",
        message: `${data.fileName} is ${data.status}.`,
        jobId: data.jobId,
    };
};

const handleError = (data: ErrorData) => {
    handleProgress(data as ProgressData);
    handleUploadComplete({
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

const showError = (title: string, message: string) => {
    result.value = { type: "error", title, message, jobId: "" };
};

// const upload = async (file: File): Promise<string> => {
//   try {
//     return await uploadManager.upload(file); // uploadId
//     // await pollStatus(uploadId);
//   } catch (error) {
//     console.error("Upload failed:", error);
//     alert(`Upload failed: ${(error as Error).message}`);
//     throw error;
//   }
// };

const pollStatus = async (uploadId: string) => {
    // const interval = setInterval(async () => {
    //     try {
    //         const stat = await uploadManager.getStatus(uploadId);
    //         status.value = stat;
    //         if (stat.status === "completed" || stat.error) {
    //             clearInterval(interval);
    //         }
    //     } catch (error) {
    //         console.error("Status check failed:", error);
    //         clearInterval(interval);
    //     }
    // }, 2000);
};

const cancel = () => {
    uploadManager.cancel();
    uploading.value = false;
    isPaused.value = false;
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
    // if (status.value?.id) {
    //     status.value = await uploadManager.getStatus(status.value.id);
    // }
};

// const formatBytes = (bytes: number) => {
//   if (bytes === 0) return "0 Bytes";
//   const k = 1024;
//   const sizes = ["Bytes", "KB", "MB", "GB"];
//   const i = Math.floor(Math.log(bytes) / Math.log(k));
//   return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + " " + sizes[i];
// };
</script>

<style scoped>
.upload-manager {
    max-width: 600px;
    margin: 2rem auto;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
        sans-serif;
}

.env-badge {
    background: #f0f0f0;
    border-radius: 4px;
    padding: 8px 12px;
    margin-bottom: 1rem;
    font-size: 0.875rem;
    display: flex;
    gap: 1rem;
    justify-content: center;
}

.env-shared {
    background: #fff3cd;
    border-left: 4px solid #ffc107;
}

.env-dedicated {
    background: #d4edda;
    border-left: 4px solid #28a745;
}

.env-container {
    background: #cce5ff;
    border-left: 4px solid #007bff;
}

.upload-zone {
    border: 2px dashed #ccc;
    border-radius: 8px;
    padding: 3rem 2rem;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
    background: #fafafa;
}

.upload-zone:hover {
    border-color: #007bff;
    background: #f8f9fa;
}

.upload-zone svg {
    stroke: #6c757d;
    margin-bottom: 1rem;
}

.upload-zone p {
    margin: 0.5rem 0;
    color: #495057;
}

.hint {
    font-size: 0.875rem;
    color: #6c757d;
}

.limits {
    font-size: 0.75rem;
    color: #6c757d;
    margin-top: 1rem;
}

.progress-container {
    text-align: left;
}

.progress-bar {
    height: 8px;
    background: #e9ecef;
    border-radius: 4px;
    margin-bottom: 1rem;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #007bff, #0056b3);
    transition: width 0.3s ease;
}

.progress-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.875rem;
}

.progress-percent {
  font-weight: bold;
  margin-right: 1rem;
}

.control-btn {
    padding: 4px 8px;
    background: #6c757d;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.75rem;
}

.control-btn:hover {
    background: #545b62;
}

.control-btn.cancel {
    background: #dc3545;
}

.control-btn.cancel:hover {
    background: #c82333;
}

.result {
    margin-top: 1.5rem;
    padding: 1rem;
    border-radius: 4px;
}

.result.success {
    background: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

.result.error {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}

.result-icon {
    float: left;
    margin-right: 1rem;
}

.result-icon svg {
    width: 24px;
    height: 24px;
    stroke-width: 2;
}

.result.success .result-icon svg {
    stroke: #28a745;
}

.result.error .result-icon svg {
    stroke: #dc3545;
}

.result-content h3 {
    margin: 0 0 0.5rem 2rem;
    font-size: 1.125rem;
}

.result-content p {
    margin: 0 0 0.5rem 2rem;
    font-size: 0.875rem;
}

.job-info {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-top: 0.5rem;
    font-size: 0.875rem;
}

.btn-sm {
    padding: 2px 6px;
    font-size: 0.75rem;
    background: #007bff;
    color: white;
    border: none;
    border-radius: 3px;
    cursor: pointer;
}

.hidden {
    display: none;
}
</style>
