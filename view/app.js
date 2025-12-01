// resources/js/app.js
import { createApp } from 'vue'
// import UploadManager from './UploadManager.vue'
import ChunkUpload from './ChunkUpload.vue'

// Register globally
// window.UploadManager = UploadManager

// Auto-init if element exists
// document.addEventListener('DOMContentLoaded', () => {
//   const el = document.getElementById('upload-manager')
//   if (el) {
//     createApp({
//       components: { UploadManager }
//     }).mount(el)
//   }
// })

// const { createApp } = Vue
// createApp({
//   components: {
//     UploadManager: window.UploadManager
//   }
// }).mount('#app')
// const { createApp } = Vue
// createApp(UploadManager).mount('#app')
createApp(ChunkUpload).mount('#app')