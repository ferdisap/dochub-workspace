import { createApp } from 'vue'
import ChunkUpload from './ChunkUpload.vue'
import "./upload.css";

import EncryptDecrypt from './encryption/EncryptDecrypt.vue'

createApp(ChunkUpload).mount('#upload-app')
createApp(EncryptDecrypt).mount('#encrypt-app')