// resources/js/components/UploadManager.vue
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
      <div v-if="!isUploading">
        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor">
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
          <span>{{ progress }}%</span>
          <span v-if="speed">{{ formatBytes(speed) }}/s</span>
          <button @click="pauseResume" class="control-btn">
            {{ isPaused ? 'Resume' : 'Pause' }}
          </button>
          <button @click="cancel" class="control-btn cancel">Cancel</button>
        </div>
      </div>
    </div>

    <!-- Results -->
    <div v-if="result" class="result" :class="result.type">
      <div class="result-icon">
        <svg v-if="result.type === 'success'" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor">
          <polyline points="20 6 9 17 4 12"></polyline>
        </svg>
        <svg v-else xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor">
          <circle cx="12" cy="12" r="10"></circle>
          <line x1="12" y1="8" x2="12" y2="12"></line>
          <line x1="12" y1="16" x2="12.01" y2="16"></line>
        </svg>
      </div>
      <div class="result-content">
        <h3>{{ result.title }}</h3>
        <p>{{ result.message }}</p>
        <div v-if="result.job_id" class="job-info">
          <span>Job ID: {{ result.job_id }}</span>
          <button @click="checkStatus" class="btn-sm">Check Status</button>
        </div>
      </div>
    </div>

    <!-- Hidden file input -->
    <input 
      type="file" 
      ref="fileInput" 
      @change="handleFileSelect" 
      accept=".zip,.tar,.gz" 
      class="hidden"
    >
  </div>
</template>

<script>
import { UploadManager } from './tus-client'

export default {
  name: 'UploadManager',
  data() {
    return {
      uploadManager: null,
      environment: 'detecting...',
      strategy: 'auto',
      maxSize: 5 * 1024 * 1024 * 1024, // 5 GB
      isUploading: false,
      isPaused: false,
      progress: 0,
      speed: 0,
      result: null,
      startTime: null,
      uploadedBytes: 0
    }
  },

  computed: {
    envClass() {
      return {
        'env-shared': this.environment === 'shared',
        'env-dedicated': this.environment === 'dedicated',
        'env-container': this.environment === 'container'
      }
    }
  },

  async mounted() {
    await this.initialize()
  },

  methods: {
    async initialize() {
      try {
        this.uploadManager = new UploadManager({
          endpoint: '/dochub/upload',
          events: {
            // Setup event listeners
            'upload-progress':  this.handleProgress,
            'complete':  this.handleComplete,
            'error':  this.handleError,
            'upload':  this.handleStart,
          }
        })
        
        // Detect environment
        const config = await this.uploadManager.initialize()
        this.environment = config.environment || 'unknown'
        this.strategy = config.driver
        this.maxSize = config.max_size
      } catch (error) {
        this.showError('Initialization failed', error.message)
        console.error('Upload init error:', error)
      }
    },

    handleStart() {
      this.isUploading = true
      this.isPaused = false
      this.progress = 0
      this.speed = 0
      this.uploadedBytes = 0
      this.startTime = Date.now()
      this.result = null
    },

    handleProgress(file, progress) {
      this.progress = Math.round(progress.percentage)
      
      // Calculate speed
      const now = Date.now()
      const elapsed = (now - this.startTime) / 1000 // seconds
      if (elapsed > 1) {
        this.speed = (progress.bytesUploaded - this.uploadedBytes) / (now - this.startTime) * 1000
        this.uploadedBytes = progress.bytesUploaded
      }
    },

    handleComplete(result) {
      this.isUploading = false
      this.result = {
        type: 'success',
        title: 'Upload Completed',
        message: `File uploaded successfully. Processing started...`,
        job_id: result.successful[0]?.response?.uploadURL?.split('/').pop() || result.successful[0]?.response?.job_id
      }
    },

    handleError(error, file) {
      this.isUploading = false
      this.result = {
        type: 'error',
        title: 'Upload Failed',
        message: error.message || 'Unknown error occurred'
      }
      console.error('Upload error:', error)
    },

    handleDrop(event) {
      const files = Array.from(event.dataTransfer.files)
      this.processFiles(files)
    },

    handleFileSelect(event) {
      const files = Array.from(event.target.files)
      this.processFiles(files)
      event.target.value = '' // Reset input
    },

    openFilePicker() {
      this.$refs.fileInput.click()
    },

    async processFiles(files) {
      if (files.length === 0) return

      const file = files[0]
      
      // Validate file
      if (!file.name.toLowerCase().endsWith('.zip') && 
          !file.name.toLowerCase().endsWith('.tar') && 
          !file.name.toLowerCase().endsWith('.gz')) {
        this.showError('Invalid file type', 'Only ZIP, TAR, GZ files allowed')
        return
      }

      if (file.size > this.maxSize) {
        this.showError('File too large', `Max size: ${this.formatBytes(this.maxSize)}`)
        return
      }

      try {
        await this.uploadManager.upload(file)
      } catch (error) {
        this.showError('Upload failed', error.message)
      }
    },

    pauseResume() {
      if (this.isPaused) {
        this.uploadManager.resume()
        this.isPaused = false
      } else {
        this.uploadManager.pause()
        this.isPaused = true
      }
    },

    cancel() {
      this.uploadManager.cancel()
      this.isUploading = false
      this.isPaused = false
    },

    checkStatus() {
      // Implement status checking
      alert('Status checking not implemented yet')
    },

    showError(title, message) {
      this.result = { type: 'error', title, message }
    },

    formatBytes(bytes) {
      if (bytes === 0) return '0 Bytes'
      const k = 1024
      const sizes = ['Bytes', 'KB', 'MB', 'GB']
      const i = Math.floor(Math.log(bytes) / Math.log(k))
      return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
    }
  }
}
</script>

<style scoped>
.upload-manager {
  max-width: 600px;
  margin: 2rem auto;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
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

.env-shared { background: #fff3cd; border-left: 4px solid #ffc107; }
.env-dedicated { background: #d4edda; border-left: 4px solid #28a745; }
.env-container { background: #cce5ff; border-left: 4px solid #007bff; }

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