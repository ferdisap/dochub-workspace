// resources/js/components/tus-client.js
import { Uppy } from '@uppy/core'
import Tus from '@uppy/tus'
import XHRUpload from '@uppy/xhr-upload'
import { toRaw } from 'vue'

export class UploadManager {
  constructor(options = {}) {
    this.options = {
      endpoint: '/dochub/upload',
      chunkSize: 10 * 1024 * 1024, // 10 MB
      retryDelays: [0, 1000, 3000, 5000],
      events: {},
      ...options
    }

    this.uppy = this.createUppy()
    this.currentUploadId = null
  }

  async initialize() {
    try {
      // Detect environment & strategy
      const config = await this.getUploadConfig()
      this.strategy = config.driver
      this.maxSize = config.max_size
      this.chunkSize = config.chunk_size || this.options.chunkSize

      // Reconfigure Uppy
      this.uppy = this.createUppy()

      console.trace('Upload initialized:', {
        strategy: this.strategy,
        max_size: this.maxSize,
        chunk_size: this.chunkSize
      })

      return config
    } catch (error) {
      console.error('Failed to initialize upload:', error)
      throw error
    }
  }

  async getUploadConfig() {
    const response = await fetch('/dochub/upload/config')
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`)
    }
    return response.json()
  }

  createUppy() {
    const uppy = new Uppy({
      debug: import.meta.env.DEV,
      autoProceed: false,
      restrictions: {
        maxFileSize: this.maxSize || 5 * 1024 * 1024 * 1024, // 5 GB
        maxNumberOfFiles: 1,
        allowedFileTypes: ['.zip', '.tar', '.gz']
      }
    })

    // Remove existing plugins
    // uppy.getPlugins().forEach(plugin => {
    //   uppy.removePlugin(plugin.id)
    // })

    if (uppy.getPlugin('Tus')) uppy.getPlugin('Tus').uninstall();

    // Add strategy-specific plugin
    if (this.strategy === 'tus') {
      uppy.use(Tus, {
        endpoint: this.options.endpoint,
        chunkSize: this.chunkSize,
        retryDelays: this.options.retryDelays,
        removeFingerprintOnSuccess: true,
        limit: 4, // concurrent uploads
        headers: {
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        },
        onBeforeRequest: (req) => {
          // Add tus-specific headers
          req.setHeader('Upload-Metadata', `filename ${btoa(file.name)}`)
        },
      })
    } else {
      uppy.use(XHRUpload, {
        endpoint: this.options.endpoint,
        fieldName: 'file',
        headers: {
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        },
        getResponseData: (responseText, response) => {
          try {
            const data = JSON.parse(responseText)
            if (data.upload_id) {
              this.currentUploadId = data.upload_id
            }
            return data
          } catch (e) {
            return { error: 'Invalid response' }
          }
        }
      })
    }

    // setup event
    for (const event of Object.keys(this.options.events)) {
      uppy.on(event, this.options.events[event]);
    }
    // this.uploadManager.on('upload-progress', this.handleProgress)
    return uppy
  }

  // on(event, callback) {
    // this.uppy.on(event, callback)
    // this.uppy.on("upload-progress", (file, progress) => { })
  // }

  upload(file) {
    // this.uppy.addFile({ data: file })
    // console.log(this.uppy.addFile);
    toRaw(this.uppy).addFile(file);
    // console.log('a');
    // try{
    //   // this.uppy.addFile.bind(this.uppy)(file);
    //   // this.uppy.addFile.apply(this.uppy, [file]);
    // } catch(e){
    //   console.error(e);
    //   return;
    // }
    // console.log('b');
    // // this.uppy.addFile({
    // //   name: 'my-file.jpg', // file name
    // //   type: 'image/jpeg', // file type
    // //   data: 'blob', // file blob
    // //   // meta: {
    // //     // optional, store the directory path of a file so Uppy can tell identical files in different directories apart.
    // //     // relativePath: webkitFileSystemEntry.relativePath,
    // //   // },
    // //   source: 'Local', // optional, determines the source of the file, for example, Instagram.
    // //   isRemote: false, // optional, set to true if actual file is not in the browser, but on some remote server, for example,
    // //   // when using companion in combination with Instagram.
    // // });
    // // this.uppy.addFile.apply(this.uppy, [file]);
    // // this.uppy.addFile.bind(this.uppy)(file);
    // alert('upload')
    return toRaw(this.uppy).upload()
  }

  pause() {
    this.uppy.pauseAll()
  }

  resume() {
    this.uppy.resumeAll()
  }

  cancel() {
    this.uppy.cancelAll()
  }
}