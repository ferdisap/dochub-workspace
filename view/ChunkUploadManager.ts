interface UploadConfig {
  driver: string;
  environment: string;
  max_size: number;
  chunk_size: number;
  expiration: number;
}

interface ChunkMetadata {
  uploadId: string;
  fileName: string;
  fileSize: number;
  chunkSize: number;
  totalChunks: number;
  uploadedChunks: number;
  createdAt: number;
}

export type UploadStatus = "uploaded" | "error" | "success";

export interface StartData {
  uploadId: string;
  fileName: string;
  totalBytes: number; // total bytes
  totalChunks: number;
}

export interface ProgressData extends StartData {
  chunkId: string;
  chunkIndex: number;
  chunkSize: number; // bytes
  uploadedSize: number; // total bytes chunk uploaded
  status: UploadStatus; // eg: uploaded
}

export interface EndData extends StartData {
  jobId: string;
  status: UploadStatus; // eg: uploaded
  url: string;
}

export interface ErrorData extends StartData {
  chunkId?: string;
  chunkIndex?: number;
  chunkSize?: number; // bytes
  uploadedSize: number; // total bytes chunk uploaded
  status: UploadStatus; // eg: uploaded
  error: Error;
}

async function sha256(buffer: ArrayBuffer | Uint8Array<ArrayBuffer>) {
  const hashBuffer = await crypto.subtle.digest("SHA-256", buffer);
  return Array.from(new Uint8Array(hashBuffer))
    .map((b) => b.toString(16).padStart(2, "0"))
    .join("");
}

export async function hashString(message: string) {
  const msgBuffer = new TextEncoder().encode(message);
  return sha256(msgBuffer);
}
export async function hashBuffer(
  buffer: ArrayBuffer | Uint8Array<ArrayBuffer>
) {
  return sha256(buffer);
}
export async function hashFile(file: File) {
  const buffer = await file.arrayBuffer();
  return sha256(buffer);
}
// const file = upload.files[0]
// const slice = file.slice(0,100);
// const strHashed = hashString(slice);
// const buffer = await slice.arrayBuffer();
// const bufferHashed = hashBuffer(buffer);
async function hashFileTreshold(file: File, thresholdMB = 1) {
  const threshold = thresholdMB * 1024 * 1024; // 1 MB

  // Jika file kecil → hash full
  if (file.size <= threshold * 2) {
    // jika kurang dari 2mb
    const buffer = await file.arrayBuffer();
    return sha256(buffer);
  }

  // Jika besar → hash 1MB awal + 1MB akhir
  const firstSlice = file.slice(0, threshold);
  const lastSlice = file.slice(file.size - threshold, file.size);

  const firstBuffer = await firstSlice.arrayBuffer();
  const lastBuffer = await lastSlice.arrayBuffer();

  // Gabungkan 2 buffer menjadi 1
  const joined = new Uint8Array(
    firstBuffer.byteLength + lastBuffer.byteLength
  );
  joined.set(new Uint8Array(firstBuffer), 0);
  joined.set(new Uint8Array(lastBuffer), firstBuffer.byteLength);

  return sha256(joined);
}
// const file = upload.files[0];
// const hash = await hashFileSmart(file);
// console.log(hash);

let waitResolve: () => void = () => { };
let waitReject: () => void = () => { };
let waitPromise = Promise.resolve(true);

async function wait() {
  try {
    await waitPromise;
    return true;
  } catch (error) {
    throw new Error("Upload cancelled");
  }
}
async function makeWait() {
  waitPromise = new Promise((res, rej) => {
    waitResolve = async () => res(true);
    waitReject = async () => rej(true);
  });
}

export class WaitController {
  private resolveFn: (() => void) | null = null;
  private rejectFn: (() => void) | null = null;
  private promise: Promise<boolean> = Promise.resolve(true);
  private cancelled = false;

  private message = "Upload cancelled";

  /**
   * Dipanggil dari loop async
   * Loop akan berhenti jika pause() dipanggil
   */
  public async wait(): Promise<boolean> {
    if (this.cancelled) {
      throw new Error(this.message);
    }
    return await this.promise;
  }

  /**
   * Masuk ke state pause
   * Loop async akan menunggu pada wait()
   */
  public pause(): void {
    if (this.cancelled) return;

    this.promise = new Promise<boolean>((resolve, reject) => {
      this.resolveFn = () => resolve(true);
      this.rejectFn = () => reject(new Error(this.message));
    });
  }

  /**
   * Lanjutkan proses yang sedang menunggu wait()
   */
  public resume(): void {
    if (this.resolveFn) {
      this.resolveFn();
      this.resolveFn = null;
      this.rejectFn = null;
    }
  }

  /**
   * Gagalkan seluruh proses dan buat wait() melempar error
   */
  public cancel(): void {
    this.cancelled = true;
    if (this.rejectFn) {
      this.rejectFn();
      this.pause();
      this.resolveFn = null;
      this.rejectFn = null;
    }
  }

  /**
   * Siapkan ulang untuk task berikutnya
   */
  public reset(): void {
    this.cancelled = false;
    this.promise = Promise.resolve(true);
    this.resolveFn = null;
    this.rejectFn = null;
  }

  /**
   * Untuk mengetahui status (optional)
   */
  public get isCancelled(): boolean {
    return this.cancelled;
  }
}

export class ChunkedUploadManager {
  private _config: UploadConfig;
  private endpoint: string;
  private uploadId: string | null = null;
  private abortController: AbortController | null = null;
  private metadata: ChunkMetadata | null = null;

  private waitController: WaitController;

  constructor(endpoint: string = "/dochub/upload") {
    this.endpoint = endpoint;
    this._config = {
      driver: "native",
      environment: "development",
      max_size: 5 * 1024 * 1024 * 1024, // 5 GB
      chunk_size: 1 * 1024 * 1024, // 1 MB
      expiration: 604800,
    };
    this.waitController = new WaitController;
  }

  /**
   * Inisialisasi dengan konfigurasi dari server
   */
  async initialize(): Promise<UploadConfig> {
    this.uploadId = null;
    // makeWait();
    try {
      const response = await fetch(`${this.endpoint}/config`);
      if (!response.ok) throw new Error(`HTTP ${response.status}`);

      const config = await response.json();
      this._config = {
        ...this._config,
        ...config,
        driver: config.driver || this._config.driver,
        chunk_size: config.chunk_size || this._config.chunk_size,
        max_size: config.max_size || this._config.max_size,
      };

      return this._config;
    } catch (error) {
      console.warn(
        "Failed to fetch upload config, using defaults:",
        error
      );
      return this._config;
    }
  }

  config() {
    return this._config;
  }

  /**
   * Mulai upload file dengan chunking
   */
  async upload(file: File): Promise<string> {
    // Validasi
    if (file.size > this._config.max_size) {
      throw new Error(
        `File too large. Max: ${formatBytes(this._config.max_size)}`
      );
    }

    // Generate upload ID
    // this.uploadId = `native_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
    const hashname = await hashFileTreshold(file);
    this.uploadId = `native_${hashname}`;
    // this.uploadId = hashname;
    // console.log(hashname);

    // const rcode = await this.checkUpload();
    // if(rcode === 102) {
    //   throw new Error('File is being uploaded');
    // } else if(rcode === 202) {
    //   throw new Error('File is being processed');
    // } else if(rcode === 304) {
    //   throw new Error('File is has been uploaded');
    // }

    // Hitung chunk
    const chunkSize = this._config.chunk_size;
    const totalChunks = Math.ceil(file.size / chunkSize);

    this.metadata = {
      uploadId: this.uploadId,
      fileName: file.name,
      fileSize: file.size,
      chunkSize: chunkSize,
      totalChunks: totalChunks,
      uploadedChunks: 0,
      createdAt: Date.now(),
    };

    const startData = {
      fileName: file.name,
      totalChunks: totalChunks,
      totalBytes: file.size,
      uploadId: this.uploadId,
    };
    if (this.onStart) {
      this.onStart(startData);
    }

    this.abortController = new AbortController();

    let uploadedSize = 0;
    // Upload chunk per chunk
    for (let i = 0; i < totalChunks; i++) {
      // try {
      //   await this.waitController.wait();
      // } catch (error) {
      //   console.log("cancel await on looping");
      //   throw error;
      // }
      let id: string;
      let size: number;
      let status: UploadStatus;
      try {
        await this.waitController.wait();
        ({ id, size, status } = await this.uploadChunk(
          file,
          i,
          totalChunks
        ));
        uploadedSize += size;
        if (this.onProgress) {
          const progressData = {
            // start data
            fileName: file.name,
            chunkId: id,
            chunkIndex: i,
            totalChunks: totalChunks,
            // progress data
            chunkSize: size,
            totalBytes: file.size,
            uploadId: this.uploadId,
            uploadedSize: uploadedSize,
            status: status,
          };
          this.onProgress(progressData);
        }
      } catch (e) {
        if (this.onError) {
          this.onError({
            // start data
            fileName: file.name,
            chunkId: id!,
            chunkIndex: i,
            totalChunks: totalChunks,
            // progress data
            chunkSize: size!,
            totalBytes: file.size,
            uploadId: this.uploadId,
            uploadedSize: uploadedSize,
            status: "error",
            error: e as Error,
          });
        }
        throw e;
      }
    }

    await this.waitController.wait();
    // Trigger processing
    let jobId: string;
    let url: string;
    let status: UploadStatus;
    try {
      ({ jobId, url, status } = await this.triggerProcessing());
    } catch (e) {
      jobId = "";
      url = "";
      status = "error";

      if (this.onError) {
        this.onError({
          ...startData,
          totalBytes: file.size,
          uploadId: this.uploadId,
          uploadedSize: uploadedSize,
          status: status,
          error: e as Error,
        });
      }
    }
    if (this.onEnd) {
      this.onEnd({
        ...startData,
        jobId,
        url,
        status,
      });
    }

    this.initialize();
    return this.uploadId;
  }

  /**
   * to check the file has uploaded or not
   * @returns status code 102 = user / user lain sedang mengupload. Walau di tunda status masih 102
   * @returns status code 202 = zip sedang di proses untuk di merge/extract
   * @returns status code 304 = sudah ada chunk
   * @returns status code 404 = lanjut upload chunk
   */
  private async checkUpload(): Promise<number> {
    const opt = {
      method: "POST",
      headers: {
        "X-Upload-ID": this.uploadId!,
        "X-CSRF-TOKEN": getCSRFToken(),
      },
    };
    let response: Response;
    try {
      response = await fetch(`${this.endpoint}/check`, opt);
      return response.status; // jika 202,304,404, bahkan 500
    } catch (e) {
      // jika response 102
      return 102;
    }
  }

  /**
   * to check the chunk has uploaded or not
   * @param chunkId string
   * @returns status code 304 = sudah ada chunk
   * @returns status code 404 = lanjut upload chunk
   */
  private async checkChunk(chunkId: string): Promise<number> {
    const opt = {
      method: "POST",
      headers: {
        "X-Requested-With": "XMLHttpRequest",
        "X-Upload-ID": this.uploadId!,
        "X-Chunk-ID": chunkId,
        "X-CSRF-TOKEN": getCSRFToken(),
      },
      signal: this.abortController ? this.abortController.signal : null,
    };
    let response: Response;
    try {
      response = await fetch(`${this.endpoint}/chunk/check`, opt);
      return response.status; // jika 202,304,404, bahkan 500
    } catch (e) {
      console.error(e);
      return 0;
    }
  }

  /**
   * Upload satu chunk
   */
  private async uploadChunk(
    file: File,
    chunkIndex: number,
    totalChunks: number
  ): Promise<{ id: string; size: number; status: UploadStatus }> {

    // try {
    //   await this.waitController.wait();
    // } catch (error) {
    //   console.log("cancel await on upload chunk");
    //   throw error;
    // }

    const start = chunkIndex * this.metadata!.chunkSize;
    const end = Math.min(
      start + this.metadata!.chunkSize,
      this.metadata!.fileSize
    );

    // 🔑 Baca chunk sebagai Blob
    const chunk = file.slice(start, end);
    const chunkSize = chunk.size;
    const chunkId = await hashBuffer(await chunk.arrayBuffer());

    let response: Response;
    try {
      // 🔑 Check the chunk
      try {
        let responseCheckChunk: number = await this.checkChunk(chunkId);
        // console.log(responseCheckChunk);
        if (responseCheckChunk === 304) {
          return { id: chunkId, size: chunkSize, status: "uploaded" };
        }
      } catch (error) {
        // console.error(error);
        throw error;
      }

      // 🔑 Pakai fetch dengan Blob + custom headers
      response = await fetch(`${this.endpoint}/chunk`, {
        method: "POST",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
          "X-Upload-ID": this.uploadId!,
          "X-Chunk-ID": chunkId!,
          "X-Chunk-Index": chunkIndex.toString(),
          "X-Chunk-Size": String(chunkSize),
          "X-Total-Chunks": totalChunks.toString(),
          "X-File-Name": this.metadata!.fileName,
          "X-File-Size": this.metadata!.fileSize.toString(),
          "Content-Type": "application/octet-stream",
          "X-CSRF-TOKEN": getCSRFToken(),
        },
        body: chunk, // 🔑 Langsung kirim Blob
        signal: this.abortController ? this.abortController.signal : null,
      });
    } catch (error) {
      if ((error as Error).name === "AbortError") {
        throw new Error("Upload Error");
      }
      throw error;
    }

    let responseData;
    try {
      if (!response.ok) {
        const errorText = await response.text();
        throw new Error(
          `Chunk ${chunkIndex} failed: ${response.status} ${errorText}`
        );
      }
      responseData = await response.json();
    } catch (error) {
      if ((error as Error).name === "AbortError") {
        throw new Error("Upload cancelled by user");
      }
      throw error;
    }
    return {
      id: chunkId,
      size: responseData.size || chunkSize,
      status: responseData.status,
    };
  }

  /**
   * Trigger pemrosesan setelah semua chunk selesai
   */
  private async triggerProcessing(): Promise<{
    jobId: string;
    url: string;
    status: UploadStatus;
  }> {
    if (!this.uploadId) throw new Error("No upload ID");

    const response = await fetch(`${this.endpoint}/process`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-TOKEN": getCSRFToken(),
      },
      body: JSON.stringify({
        upload_id: this.uploadId,
        file_name: this.metadata!.fileName,
      }),
    });

    if (!response.ok) {
      throw new Error(`Processing failed: ${response.status}`);
    }

    const result = await response.json();
    // this._result.process = result;

    return {
      jobId: result.jobId,
      url: result.url,
      status: result.status || "success",
    };
  }

  // result() {
  //   return this._result;
  // }

  /**
   * Cek status upload
   */
  async getStatus(uploadId: string): Promise<any> {
    const response = await fetch(`${this.endpoint}/${uploadId}/status`);
    if (!response.ok) {
      throw new Error(`Status check failed: ${response.status}`);
    }
    return response.json();
  }

  // Callbacks
  onStart?: (data: StartData) => void;
  onProgress?: (data: ProgressData) => void;
  onEnd?: (data: EndData) => void;
  onError?: (data: ErrorData) => void;

  pause() {
    this.waitController.pause();
    // makeWait();
  }

  resume() {
    this.waitController.resume();
    // waitResolve();
  }

  /**
   * Batalkan upload
   */
  cancel(): void {
    // console.trace();
    // waitReject(); // agar wait yang lama telah reject, tidak menggantung
    // makeWait(); // dibuat lagi agar merefresh sehingga saat ada await wait(), mengacu ke promise yang sama
    // waitReject();
    this.waitController.cancel();
    if (this.abortController) {
      this.abortController.abort();
      this.abortController = null;
    }
    this.initialize();
    setTimeout(() => this.waitController.reset(), 1000); // timeout agar saat looping promise masih dalam keaadaan reject. di beri 1 detik agar aman
    // this.waitController.reset();
    console.log("Upload cancelled");
  }
}

export function getCSRFToken(): string {
  const meta = document.querySelector('meta[name="csrf-token"]');
  return meta ? meta.getAttribute("content") || "" : "";
}

// export function formatBytes(bytes: number): string {
//   if (bytes === 0) return '0 Bytes';
//   const k = 1024;
//   const sizes = ['Bytes', 'KB', 'MB', 'GB'];
//   const i = Math.floor(Math.log(bytes) / Math.log(k));
//   return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
// }

export function formatBytes(bytes: number): string {
  if (bytes === 0) return "0 Bytes";

  const units = ["Bytes", "KB", "MB", "GB", "TB"];
  const i = Math.floor(Math.log(bytes) / Math.log(1024));

  const value = bytes / Math.pow(1024, i);
  const formatted = value % 1 === 0 ? value.toFixed(0) : value.toFixed(1);

  return `${formatted} ${units[i]}`;
}
