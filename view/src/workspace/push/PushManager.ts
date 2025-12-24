import { route_workspace_check_chunk, route_workspace_push_init, route_workspace_push_process, route_workspace_push_status, route_workspace_upload_chunk } from "../../helpers/listRoute";
import { ChunkUploadManager, StartUploadData, UploadStatus } from "../../upload/ChunkUploadManager";
import { getCSRFToken } from "../../helpers/toDom";
import { DhFileParam, FileObject } from "../core/DhFile";
import { ManifestObject } from "../core/DhManifest";
import { FileNode, loopFolder } from "../analyze/folderUtils";

type PushStatus = "processing" | "failed" | "completed";

export interface ErrorPushData {
  status: PushStatus,
  error: Error,
}

export interface ProcessingPushData {
  pushId: string;
  jobId: string;
  url: string;
  status: PushStatus;
}

export interface EndPushData {
  jobId: string,
  status: string,
  message: string,
}

export class PushManager extends ChunkUploadManager {

  private _pushId: string | null = null;
  private _processedFiles: FileObject[] = [];
  private _targetManifest: ManifestObject | null = null;
  private _sourceManifest: ManifestObject | null = null;

  // additional callbacks
  onStartPush?: () => void;
  onProcessingPush?: (data: ProcessingPushData) => void;
  onEndPush?: (data: EndPushData) => void;
  onErrorPush?: (data: ErrorPushData) => void;

  // source (lama) dan target (baru)
  async push(sourceManifest: ManifestObject, targetManifest: ManifestObject, file: FileNode): Promise<boolean> {
    try {
      if (!targetManifest || !sourceManifest) throw Error('Target and source manifest must be set');
      if (file.kind !== 'directory') throw Error('File must be a directory type');

      this._targetManifest = targetManifest;
      this._sourceManifest = sourceManifest;

      // #1. init
      await this.initializePush();
      if (this.onStartPush) this.onStartPush();

      // #2, #3, #4, #5, #6 get config and upload file
      const processedFilesMap = new Map<string, FileObject>();
      for (const f of this._processedFiles) processedFilesMap.set(f.relative_path, f);
      let uploadedFiles = <string[]>[]
      await loopFolder(file, async (node) => {
        await this._waitController.wait();
        // upload file jika ada di processFile map
        if (node.handler.relativePath && processedFilesMap.has(node.handler.relativePath)) {
          const file = await (node.handler as DhFileParam).getFile();
          let uploadedSize = 0;
          try {
            const uploadId = await this.upload(file);
            uploadedFiles.push(uploadId);
            await this.pollStatusUpload(uploadId);
            uploadedSize += file.size;
          } catch (err) {
            if (this.onErrorUpload) {
              const startData = {
                uploadId: this._uploadId,
                fileName: file.name,
                totalBytes: file.size,
                totalChunks: this._metadata?.totalChunks || 0,
              };
              this.onErrorUpload({
                // start data
                ...startData,
                // progress data
                chunkSize: this._configUpload.chunk_size,
                totalBytes: file.size,
                uploadId: this._uploadId!,
                uploadedSize: uploadedSize,
                status: "failed",
                error: err as Error,
              });
            }
          }
        }
      });

      // #7. push
      if (this._processedFiles.length === uploadedFiles.length) {
        const processingPushData = await this.processPush();
        if (this.onProcessingPush) this.onProcessingPush(processingPushData);
      } else {
        throw new Error("File uploaded not completed");
      }

      // #8. status push
      return await this.pollStatusPush(this._pushId!);
    } catch (error: any) {
      if (this.onErrorPush) this.onErrorPush({
        status: "failed",
        error
      })
      this._targetManifest = null;
      this._sourceManifest = null;
      throw error;
    }
  }

  // #1. and #2. init push and get config by
  async initializePush(): Promise<void> {
    try {
      const data = await fetch(route_workspace_push_init(), {
        method: "POST",
        headers: {
          // 'Accept': 'application/json', // return must json response
          "X-Requested-With": "XMLHttpRequest",
          'Content-Type': 'application/json',
          "X-CSRF-TOKEN": getCSRFToken(),
          // manifest header
          'X-Source-Manifest-Hash': this._sourceManifest!.hash_tree_sha256,
        },
        body: JSON.stringify({
          target_manifest: this._targetManifest,
        }),
        signal: this._abortController ? this._abortController.signal : null,
      }).then(r => r.json());
      this._pushId = data.push_id;
      this._processedFiles = this._processedFiles.concat(data.processed_files);
    } catch (error) {
      console.warn("Failed to init push.", error);
    }
  }
  // #3. check uploaded chunk  
  public async checkChunk(chunkId: string): Promise<number> {

    // periksa di localStorage dulu sehingga tidak perlu fetch ke server
    const opt = {
      method: "POST",
      headers: {
        "X-Requested-With": "XMLHttpRequest",
        "X-CSRF-TOKEN": getCSRFToken(),
        "X-Upload-Id": this._uploadId!,
        "X-Chunk-Id": chunkId,
        'X-Target-Manifest-Hash': this._targetManifest!.hash_tree_sha256,
        "X-File-Hash": this._fileHash || '',
      },
      signal: this._abortController ? this._abortController.signal : null,
    };
    let response: Response;
    try {
      response = await fetch(route_workspace_check_chunk(), opt);
      if (response.status === 403) {
        throw new Error("File existed");
      }
      // else if (responseCheckChunk === 409 || responseCheckChunk === 404){
      //   // nothing because continue to upload chunk
      // }
      // else if (responseCheckChunk === 304) continue upload
      return response.status; // jika 202,304,404, bahkan 500
    } catch (e) {
      console.error(e);
      throw e;
    }
  }
  // #4. uploadchunk is done by parent
  // #5. processUpload by hitting 'dochub/workspace/chunk/process'
  public async processUpload(): Promise<{
    jobId: string;
    status: UploadStatus;
  }> {
    // alert('fufuafa');
    // console.log(this._metadata);
    if (!this._uploadId) throw new Error("No upload ID");

    const response = await fetch(route_workspace_upload_chunk(), {
      method: "POST",
      headers: {
        "X-Requested-With": "XMLHttpRequest",
        "Content-Type": "application/json",
        "X-CSRF-TOKEN": getCSRFToken(),
        'X-Target-Manifest-Hash': this._targetManifest!.hash_tree_sha256,
        'X-Source-Manifest-Hash': this._sourceManifest!.hash_tree_sha256
      },
      body: JSON.stringify({
        upload_id: this._uploadId,
        file_mtime: this._metadata!.fileMtime,
      }),
      signal: this._abortController ? this._abortController.signal : null,
    });

    if (!response.ok) {
      throw new Error(`Processing failed: ${response.status}`);
    }

    const result = await response.json();

    return {
      jobId: result.job_id,
      status: result.status || "completed",
    };
  }
  // #6. get upload status by parent @getStatusUpload
  // #7. processPush 
  public async processPush(): Promise<ProcessingPushData> {
    const response = await fetch(route_workspace_push_process(), {
      method: "POST",
      headers: {
        "X-Requested-With": "XMLHttpRequest",
        "Content-Type": "application/json",
        "X-CSRF-TOKEN": getCSRFToken(),
        'X-Target-Manifest-Hash': this._targetManifest!.hash_tree_sha256,
        'X-Source-Manifest-Hash': this._sourceManifest!.hash_tree_sha256
      },
      // body: JSON.stringify({
      // upload_id: this._uploadId,
      // label: '', // not required
      // tags: '', // not required
      // }),
      signal: this._abortController ? this._abortController.signal : null,
    });

    if (!response.ok) {
      throw new Error(`Processing failed: ${response.status}`);
    }

    const result = await response.json();

    return {
      pushId: result.push_id,
      jobId: result.job_id,
      url: result.url,
      status: result.status || "completed",
    };
  }
  // #8. get push status 
  async getStatusPush(pushId:string): Promise<any> {
    if (!this._pushId) throw new Error("No upload ID");
    const response = await fetch(route_workspace_push_status(pushId), {
      headers: {
        "X-Requested-With": "XMLHttpRequest",
      },
      signal: this._abortController ? this._abortController.signal : null,
    });
    if (!response.ok) {
      throw new Error(`Status check failed: ${response.status}`);
    }

    const data = await response.json() as Record<string, any>;
    if (data.status === 'completed' && this.onEndPush) {
      const result = {
        jobId: data.job_id,
        status: data.status,
        message: data.message,
      };
      this.onEndPush(result);
      this._targetManifest = null;
      this._sourceManifest = null;
    }
    return data;
  }

  async pollStatusUpload(uploadId:string, times = 100) :Promise<boolean>{
    return new Promise((resolve, reject) => {
      const interval = setInterval(async () => {
        if (times > 1) {
          try {
            const stt = await this.getStatusUpload(uploadId);
            if (stt.status === "completed" || stt.failed) {
              clearInterval(interval);
              resolve(true);
            }
          } catch (error) {
            console.error("Status check failed:", error);
            clearInterval(interval);
            reject(error);
          }
          times--;
        } else {
          clearInterval(interval);
        }
      }, 2000);
    })
  }
  async pollStatusPush(pushId:string, times = 100) :Promise<boolean>{
    return new Promise((resolve, reject) => {
      const interval = setInterval(async () => {
        if (times > 0) {
          try {
            const stt = await this.getStatusPush(pushId);
            if (stt.status === "completed" || stt.failed) {
              clearInterval(interval);
              resolve(true);
            }
          } catch (error) {
            console.error("Status check failed:", error);
            clearInterval(interval);
            reject(error);
          }
          times--;
        } else {
          clearInterval(interval);
        }
      }, 2000);
    })
  }
}