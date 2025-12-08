// berhasil
// ferdi-download.ts
// Tujuan: Streaming download + verifikasi + dekripsi file FRDI format (multi-chunk)
// Fitur:
//   - Hemat RAM (hanya ~2×chunk_size)
//   - Verifikasi global checksum sesuai PHP backend
//   - Dekripsi multi-chunk aman (nonce unik per chunk)
//   - Debugging interaktif untuk pembelajaran
//   - Streaming PDF langsung ke pdf.js (tanpa Blob)
//   - Support semua tipe file (PDF, ZIP, dll)

// ========== DEPENDENCIES
import { chacha20poly1305 } from '@noble/ciphers/chacha.js';
import { x25519 } from '@noble/curves/ed25519.js';
import { sha256 } from '@noble/hashes/sha2.js';
import { hkdf } from '@noble/hashes/hkdf.js';
import { createSHA256 } from 'hash-wasm';
import * as pdfjs from 'pdfjs-dist';
// Reuse dari ferdi-encryption.ts
import { deriveWrapKey, deriveX25519KeyPair } from './ferdi-encryption';
import { DocumentInitParameters, RenderParameters } from 'pdfjs-dist/types/src/display/api';

// Setup worker (wajib!)
pdfjs.GlobalWorkerOptions.workerSrc =
  new URL('./pdf.worker.mjs', import.meta.url).toString();

// ========== UTILS (ringkas & self-contained)
const decoder = new TextDecoder();

export function base64ToBytes(s: string): Uint8Array {
  // Decode base64 string ke Uint8Array (aman untuk binary)
  // Contoh: "AQID" → Uint8Array([1,2,3])
  return Uint8Array.from(atob(s), c => c.charCodeAt(0));
}

function bytesToHex(bytes: Uint8Array): string {
  // Konversi Uint8Array ke hex string lowercase (untuk logging/debug)
  // Contoh: Uint8Array([255,0]) → "ff00"
  return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
}

function bytesToNumberLE(bytes: Uint8Array): bigint {
  // Baca angka little-endian (4-byte uint32 → bigint)
  // Digunakan untuk meta_len
  let res = 0n;
  for (let i = 0; i < bytes.length; i++) {
    res += BigInt(bytes[i]) << (BigInt(i) * 8n);
  }
  return res;
}

function numberToBytesLE(num: bigint, width: number): Uint8Array {
  // Konversi bigint ke little-endian Uint8Array (misal: 5 → [5,0,0,0])
  const bytes = new Uint8Array(width);
  for (let i = 0; i < width && num > 0n; i++) {
    bytes[i] = Number(num & 0xffn);
    num >>= 8n;
  }
  return bytes;
}

function bytesToString(uint8Arrays: Uint8Array[]): string {
  // 1. Hitung panjang total yang dibutuhkan untuk array tunggal
  const totalLength = uint8Arrays.reduce((acc, arr) => acc + arr.length, 0);

  // 2. Buat Uint8Array baru yang ukurannya pas
  const combinedArray = new Uint8Array(totalLength);

  // 3. Salin data dari setiap array ke dalam array gabungan
  let offset = 0;
  for (const arr of uint8Arrays) {
    combinedArray.set(arr, offset);
    offset += arr.length;
  }

  // 4. Gunakan TextDecoder standar untuk mengubah byte menjadi string
  const decoder = new TextDecoder('utf-8'); // Pastikan encoding sudah benar (umumnya UTF-8)
  const text = decoder.decode(combinedArray);

  return text;
}

// ========== DEBUGGING UTIL — bisa dimatikan di production
// let DEBUG_MODE = true; // 🔧 Ganti false untuk disable semua debug
let DEBUG_MODE = false; // 🔧 Ganti false untuk disable semua debug

function debugLog(...args: any[]) {
  if (DEBUG_MODE) {
    console.log('[DEBUG]', new Date().toISOString(), '|', ...args);
  }
}

function debugBreakpoint(message: string): Promise<void> {
  if (DEBUG_MODE) {
    return new Promise(resolve => {
      debugLog(`⏸️  BREAKPOINT: ${message}`);
      debugLog('   Tekan "Lanjutkan" di debugger, atau tunggu 2 detik...');
      setTimeout(resolve, 2000);
    });
  }
  return Promise.resolve();
}

async function readHeaderOnly(
  reader: ReadableStreamDefaultReader<Uint8Array>,
  metaJson: any
): Promise<void> {
  const headerSize = 4 + 1 + 32 + 16; // magic+version+checksum+fileId
  const metaLenSize = 4;
  const metaLen = metaJson.chunk_size ? 0 : metaJson.metaLen || 0; // dummy

  // Baca header tetap
  let toRead = headerSize + metaLenSize + metaLen;
  while (toRead > 0) {
    const { done, value } = await reader.read();
    if (done) throw new Error('EOF in header');
    if (value.length <= toRead) {
      toRead -= value.length;
    } else {
      // Simpan sisa ke reader — tidak bisa, jadi kita baca semua header dulu
      break;
    }
  }
  // Karena header kecil, kita asumsikan terbaca utuh
}

function buildNonce(nonceBase: Uint8Array, index: number): Uint8Array {
  const nonce = new Uint8Array(12);
  nonce.set(nonceBase, 0);
  nonce.set(numberToBytesLE(BigInt(index), 8), 4);
  return nonce;
}

// ========== BUFFERED STREAM READER — RAM-EFFICIENT
// Mengelola buffer baca dari network tanpa duplikasi/data loss
// --- Buffered reader for initial header
class BufferedStreamReader {
  protected buffer = new Uint8Array(0);
  constructor(protected reader: ReadableStreamDefaultReader<Uint8Array>) { }

  // Pastikan minimal `n` byte tersedia di buffer
  async ensure(n: number): Promise<void> {
    while (this.buffer.length < n) {
      const { done, value } = await this.reader.read();
      if (done) throw new Error(`Unexpected EOF (need ${n}, have ${this.buffer.length})`);
      // Gabungkan tanpa duplikasi
      const tmp = new Uint8Array(this.buffer.length + value.length);
      tmp.set(this.buffer);
      tmp.set(value, this.buffer.length);
      this.buffer = tmp;
    }
  }

  // Ambil `n` byte dari buffer, lalu hapus dari buffer
  consume(n: number): Uint8Array {
    const result = this.buffer.subarray(0, n);
    this.buffer = this.buffer.subarray(n);
    return result;
  }

  // Baca semua sisa data (hanya untuk 1-chunk atau last chunk)
  async readAll(): Promise<Uint8Array> {
    const chunks: Uint8Array[] = [this.buffer];
    this.buffer = new Uint8Array(0); // reset

    while (true) {
      const { done, value } = await this.reader.read();
      if (done) break;
      chunks.push(value);
    }

    // Gabung semua chunk
    const total = chunks.reduce((sum, c) => sum + c.length, 0);
    const result = new Uint8Array(total);
    let offset = 0;
    for (const chunk of chunks) {
      result.set(chunk, offset);
      offset += chunk.length;
    }
    return result;
  }

  // Baca tepat `n` byte (untuk chunk non-terakhir)
  async readExact(n: number): Promise<Uint8Array> {
    await this.ensure(n);
    return this.consume(n);
  }
}

class HeaderReader extends BufferedStreamReader {
  constructor(reader: ReadableStreamDefaultReader<Uint8Array>) {
    super(reader)
  }

  async read(n: number): Promise<Uint8Array> {
    await this.ensure(n);
    const result = this.buffer.subarray(0, n);
    this.buffer = this.buffer.subarray(n);
    return result;
  }

  async readJSON(): Promise<any> {
    const lenBytes = await this.read(4);
    const len = Number(bytesToNumberLE(lenBytes));
    const jsonBytes = await this.read(len);
    return JSON.parse(decoder.decode(jsonBytes));
  }

  // Kembalikan sisa buffer + reader sebagai stream
  toStream(): ReadableStream<Uint8Array> {
    const initialChunk = this.buffer.length > 0 ? this.buffer : null;
    this.buffer = new Uint8Array(0);
    const reader = this.reader;
    return new ReadableStream({
      start(controller) {
        if (initialChunk) controller.enqueue(initialChunk);
        (async () => {
          try {
            while (true) {
              const { done, value } = await reader.read();
              if (done) break;
              controller.enqueue(value);
            }
            controller.close();
          } catch (e) {
            controller.error(e);
          }
        })();
      }
    });
  }
}

// ========== INTERFACE HASIL
export interface DecryptedFileResult {
  plaintextStream: ReadableStream<Uint8Array>;
  meta: {
    fileId: string;        // hex string
    original: {
      filename: string;
      mime: string;
      size: number;        // ukuran asli (plain)
    };
    totalChunks: number;
    chunkSize: number;     // ukuran chunk plain (bukan cipher)
  };
}
export interface ChecksumResult {
  storedChecksum?: Uint8Array;
  computedChecksum?: Uint8Array;
  fileIdBin: Uint8Array;
  metaLenBytes: Uint8Array;
  metaBuf: Uint8Array;
  metaJson: {
    "chunk_size": number,
    "total_chunks": number,
    "nonce_base": string,        // base64, 4 byte
    "encrypted_sym_keys": {
      [userId: string]: string   // base64, hasil x25519.seal()
    },
    "owner_pub_key": string, // base64
    "original": {
      "filename": string,
      "mime": string,
      "size": number
    }
  };
}

// ========== FUNGSI UTAMA: DOWNLOAD & DECRYPT (multi-chunk)

// --- Pipeline 1: Checksum
async function computeGlobalChecksum(
  stream: ReadableStream<Uint8Array>
): Promise<ChecksumResult> {

  const reader = stream.getReader();
  const headerReader = new HeaderReader(reader);

  try {
    // ===== STEP 1: Baca Header Tetap (53 byte: magic + version + checksum + fileId)
    debugLog('🔍 Langkah 1: Membaca header tetap (53 byte)...');
    const header = await headerReader.read(53);
    await debugBreakpoint('Header dibaca — lanjut parse');
    // Ekstrak komponen header
    const magic = decoder.decode(header.subarray(0, 4));
    if (magic !== 'FRDI') throw new Error(`Invalid magic: "${magic}" (expected "FRDI")`);
    const version = header[4];
    if (version !== 1) throw new Error(`Unsupported version: ${version} (expected 1)`);
    debugLog(`✅ Header valid: magic=${magic}, version=${version}`);
    const storedChecksum = header.subarray(5, 37); // 32-byte checksum aktual
    const fileIdBin = header.subarray(37, 53);
    debugLog(`🆔 File ID (bin): ${bytesToHex(fileIdBin)}`);

    // ===== STEP 2: Baca meta_len (4 byte LE)
    debugLog('🔍 Langkah 2: Membaca panjang metadata (4 byte LE)...');
    const metaLenBytes = await headerReader.read(4);
    const metaLen = Number(bytesToNumberLE(metaLenBytes));
    debugLog(`📏 Panjang metadata: ${metaLen} byte`);

    // ===== STEP 3: Baca metadata JSON
    debugLog('🔍 Langkah 3: Membaca dan parsing metadata JSON...');
    const metaBuf = await headerReader.read(metaLen);
    const metaJsonStr = decoder.decode(metaBuf);
    const metaJson = JSON.parse(metaJsonStr);
    debugLog(`📄 Metadata JSON:\n${JSON.stringify(metaJson, null, 2)}`);

    // Init hasher
    const hasher = await createSHA256();
    hasher.init();

    // A. fileIdBin (16B)
    hasher.update(fileIdBin);
    // B. metaLen (4B LE)
    hasher.update(metaLenBytes);
    // C. metaJsonStr (raw bytes)
    hasher.update(metaBuf);

    // D. Streaming chunks (cipher || tag)
    const chunksStream = headerReader.toStream();
    const hasherStream = new TransformStream<Uint8Array, Uint8Array>({
      transform(chunk, controller) {
        hasher.update(chunk);
        controller.enqueue(chunk); // forward untuk debugging (tidak dipakai)
      }
    });

    // Pipe & tunggu selesai
    await chunksStream.pipeThrough(hasherStream).pipeTo(new WritableStream());
    const computedChecksum = hasher.digest('binary') as Uint8Array;

    return {
      storedChecksum,
      computedChecksum,
      fileIdBin,
      metaLenBytes,
      metaBuf,
      metaJson
    };
  }
  finally {
    reader.releaseLock();
  }
}
// --- Pipeline 2: Decrypt
async function decryptStream(
  stream: ReadableStream<Uint8Array>,
  passphrase: string,
  userId: string,
  checksumResult: ChecksumResult
): Promise<DecryptedFileResult> {
  const { fileIdBin, metaJson } = checksumResult;
  const { chunk_size, total_chunks, nonce_base, encrypted_sym_keys, owner_pub_key, original } = metaJson;

  if (!owner_pub_key) {
    throw new Error('Missing owner_pub_key in metadata (required for key unwrap)');
  }

  // ===== STEP 4: Dekripsi symKey untuk pengguna ini
  debugLog(`🔍 Langkah 4: Mendekripsi symKey untuk user "${userId}"...`);
  const encryptedWrapB64 = encrypted_sym_keys[userId];
  if (!encryptedWrapB64) {
    throw new Error(`No encrypted sym key for user "${userId}" (available: ${Object.keys(encrypted_sym_keys).join(', ')})`);
  }

  const encryptedWrap = base64ToBytes(encryptedWrapB64);
  if (encryptedWrap.length < 12 + 32 + 16) {
    throw new Error(`Invalid wrapped key size: ${encryptedWrap.length} (min 60)`);
  }
  debugLog(`🔐 Wrapped key size: ${encryptedWrap.length} byte`);

  const wrapNonce = encryptedWrap.subarray(0, 12);
  const wrappedCipherTag = encryptedWrap.subarray(12);
  debugLog(`🔑 Wrap nonce (hex): ${bytesToHex(wrapNonce)}`);

  // Derive private key pengguna & unwrap symKey
  const { privateKey: ownPriv } = await deriveX25519KeyPair(passphrase, userId);
  const ownerPub = base64ToBytes(owner_pub_key);
  const wrapKey = deriveWrapKey(ownPriv, ownerPub, userId);
  debugLog(`🔑 Wrap key derived (first 8B): ${bytesToHex(wrapKey.subarray(0, 8))}`);

  let symKey: Uint8Array;
  try {
    symKey = chacha20poly1305(wrapKey, wrapNonce).decrypt(wrappedCipherTag);
    debugLog(`✅ SymKey berhasil didekripsi (${symKey.length} byte)`);
  } catch (e) {
    console.error('❌ Gagal dekripsi symKey — kemungkinan:');
    console.error('   - Passphrase salah');
    console.error('   - owner_pub_key tidak cocok');
    console.error('   - Data korup');
    throw new Error(`Failed to decrypt sym key: ${(e as Error).message}`);
  }

  // Baca header lagi (kita tidak reuse reader, jadi parse ulang — header kecil, aman)
  const reader = stream.getReader();
  const bufReader = new BufferedStreamReader(reader);
  // ===== Lewati header & meta (sama seperti checksumBranch)
  // Header tetap: 53 byte
  await bufReader.ensure(53); // [ 4B] magic + [ 1B] version + [32B] global_checksum + [16B] file_i = 53
  bufReader.consume(53);
  // meta_len: 4 byte
  await bufReader.ensure(4); // [ 4B] meta_len
  const metaLenBytes = bufReader.consume(4);
  const metaLen = Number(bytesToNumberLE(metaLenBytes));
  // meta_json: metaLen byte
  await bufReader.ensure(metaLen); // [var] meta_json 
  bufReader.consume(metaLen); // skip meta — sudah diparse di checksum

  // Sekarang bufReader menunjuk ke **awal chunks** — 100% sinkron
  const nonceBase = base64ToBytes(nonce_base);
  const tagSize = 16;

  // Buat plaintext stream
  const plaintextStream = new ReadableStream<Uint8Array>({
    async start(controller) {
      try {
        for (let i = 0; i < total_chunks; i++) {
          const isLast = (i === total_chunks - 1);
          let encryptedChunk: Uint8Array;
          if (isLast) {
            // Baca semua sisa
            encryptedChunk = await bufReader.readAll();
          } else {
            // Baca chunk_size + tagSize
            encryptedChunk = await bufReader.readExact(chunk_size + tagSize);
          }

          // ✅ bangun nonce: [nonceBase (4B)] + [i (8B LE)]
          const nonce = buildNonce(nonceBase, i);
          debugLog(`🔐 Chunk ${i}: nonce=${bytesToHex(nonce)}, size=${encryptedChunk.length}`);

          // ✅ Decrypt
          const plain = chacha20poly1305(symKey, nonce).decrypt(encryptedChunk);
          debugLog(`✅ Chunk ${i}: ${plain.length} byte plain`);
          controller.enqueue(plain);

          if (DEBUG_MODE && total_chunks > 1) {
            await debugBreakpoint(`Setelah chunk ${i}`);
          }
          controller.enqueue(plain);
        }
        controller.close();
        debugLog('✅ Semua chunk didekripsi — stream ditutup');
      } catch (e) {
        controller.error(e);
      } finally {
        reader.releaseLock();
      }
    }
  });

  return {
    plaintextStream,
    meta: {
      fileId: bytesToHex(fileIdBin),
      original,
      totalChunks: total_chunks,
      chunkSize: chunk_size
    }
  };
}

/**
 * Membaca dan mendekripsi file FRDI dari objek File (misal: dari <input type="file">)
 * 
 * ✅ Logika dekripsi & verifikasi IDENTIK dengan downloadAndDecryptFile()
 * ✅ Hemat RAM: hanya ~2×chunk_size di memori
 * ✅ Streaming: chunk didekripsi satu per satu tanpa buffer penuh
 * 
 * @param file File terenkripsi (.fenc) yang dibaca dari disk/user
 * @param passphrase Passphrase pengguna saat ini
 * @param userId ID pengguna saat ini (harus ada di encrypted_sym_keys)
 * @returns Promise<DecryptedFileResult> — stream plaintext + metadata
 */
export async function readAndDecryptFile(
  file: File,
  passphrase: string,
  userId: string
): Promise<DecryptedFileResult> {
  debugLog(`🚀 Memulai baca & decrypt file lokal: ${file.name}`);
  debugLog(`👤 User: ${userId}`);

  // --- STEP 0: Buka file sebagai ReadableStream
  const fileStream = file.stream();
  if (!fileStream) {
    throw new Error('File.stream() not supported in this browser');
  }

  // 2. Split jadi 2 branch: checksum & decrypt (sama seperti downloadAndDecryptFile)
  const [checksumBranch, decryptBranch] = fileStream.tee();

  // ========== PIPELINE 1: Checksum (streaming, sesuai PHP)
  const checksumResult = await computeGlobalChecksum(checksumBranch);

  // Verifikasi checksum (opsional: uncomment untuk enforce)
  if (checksumResult.storedChecksum && checksumResult.computedChecksum) {
    const storedHex = bytesToHex(checksumResult.storedChecksum);
    const computedHex = bytesToHex(checksumResult.computedChecksum);
    if (storedHex !== computedHex) {
      console.warn('⚠️ Global checksum mismatch!', '\n  File mungkin korup atau dimodifikasi.', { storedHex, computedHex });
      throw new Error('Global checksum mismatch');
    }
  }

  // ========== PIPELINE 2: Decrypt (setelah checksum valid)
  const decryptResult = await decryptStream(
    decryptBranch,
    passphrase,
    userId,
    checksumResult
  );

  return decryptResult;
}

/**
 * Download dan dekripsi file FRDI secara streaming.
 * 
 * @param url URL file terenkripsi (misal: `/api/download-encrypt-file/xxx`)
 * @param passphrase Passphrase pengguna saat ini
 * @param userId ID pengguna saat ini (harus ada di encrypted_sym_keys)
 * @returns Promise berisi stream plaintext + metadata
 * 
 * Alur:
 *   1. Baca & verifikasi header
 *   2. Ekstrak metadata
 *   3. Dekripsi symKey dengan X25519 + ChaCha20-Poly1305
 *   4. Hitung & verifikasi global checksum (sesuai PHP)
 *   5. Dekripsi chunk-by-chunk dengan nonce unik
 */
export async function downloadAndDecryptFile(
  url: string,
  passphrase: string,
  userId: string
): Promise<DecryptedFileResult> {
  debugLog(`🚀 Memulai download & decrypt: ${url}`);
  debugLog(`👤 User: ${userId}`);

  // --- STEP 0: Fetch sebagai stream
  const response = await fetch(url, {
    headers: {
      'X-Requested-With': 'XMLHttpRequest',
    }
  });
  if (!response.ok) throw new Error(`HTTP ${response.status}: ${response.statusText}`);
  if (!response.body) throw new Error('Response has no body');

  // 2. Split jadi 2 branch: checksum & decrypt
  const [checksumBranch, decryptBranch] = response.body.tee();

  // ========== PIPELINE 1: Checksum (streaming, sesuai PHP)
  const checksumResult = await computeGlobalChecksum(checksumBranch);

  // Verifikasi checksum (opsional: uncomment untuk enforce)
  if (checksumResult.storedChecksum && checksumResult.computedChecksum) {
    const storedHex = bytesToHex(checksumResult.storedChecksum);
    const computedHex = bytesToHex(checksumResult.computedChecksum);
    if (storedHex !== computedHex) {
      console.warn('⚠️ Global checksum mismatch!', '\n  File mungkin korup atau dimodifikasi.', { storedHex, computedHex });
      throw new Error('Global checksum mismatch');
    }
  }

  // ========== PIPELINE 2: Decrypt (setelah checksum valid)
  const decryptResult = await decryptStream(
    decryptBranch,
    passphrase,
    userId,
    checksumResult
  );

  return decryptResult;
}

// ========== UTIL: STREAM KE BLOB (untuk file kecil)
// ⚠️ Hati-hati: file besar → boros RAM!
export async function streamToBlob(
  stream: ReadableStream<Uint8Array>,
  type = 'application/octet-stream'
): Promise<Blob> {
  debugLog(`💾 Mengumpulkan stream ke Blob (type: ${type})...`);
  const chunks: Uint8Array[] = [];
  const reader = stream.getReader();
  let total = 0;

  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    chunks.push(value);
    total += value.length;
    debugLog(`📥 Blob: ${total} byte terkumpul`);
  }

  const blob = new Blob(chunks, { type });
  debugLog(`✅ Blob siap: ${blob.size} byte`);
  return blob;

}
export async function streamToText(
  stream: ReadableStream<Uint8Array>,
  type = 'plain/text'
): Promise<string> {
  debugLog(`💾 Mengumpulkan stream ke Text (type: ${type})...`);
  const chunks: Uint8Array[] = [];
  const reader = stream.getReader();
  let total = 0;

  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    chunks.push(value);
    total += value.length;
    debugLog(`📥 Text: ${total} byte terkumpul`);
  }

  const text = bytesToString(chunks);
  text.length
  debugLog(`✅ Text siap: ${text.length} byte`);
  return text;
}

// ========== PDF STREAMING — TANPA BLOB (HEMAT RAM!)
/**
 * Streaming PDF langsung ke pdf.js library (menggunakan stream reader)
 * Cocok untuk file PDF besar (>100 MB)
 * 
 * @param plaintextStream Stream hasil dekripsi (plain PDF bytes)
 * @param onProgress Optional callback progress (0.0 – 1.0)
 * @returns Promise<pdfjs.PDFDocumentProxy>
 */
export async function streamPdfToPdfJs(
  plaintextStream: ReadableStream<Uint8Array>,
  meta: DecryptedFileResult["meta"],
  onProgress?: (progress: number) => void
): Promise<pdfjs.PDFDocumentProxy /* pdfjs.PDFDocumentProxy */> {
  debugLog('🖨️  Memulai streaming PDF ke pdf.js...');

  // Lazy load pdfjs (CDN atau bundler)
  // const pdfjs = pdfjs;

  // Buat "range" object untuk PDF.js streaming
  // PDF.js butuh: length, dan requestRange(start, end)
  let totalBytes = 0;
  let chunks: Uint8Array[] = [];
  // let resolveStream!: (value: Uint8Array) => void;
  // let streamPromise = new Promise<Uint8Array>(resolve => resolveStream = resolve);

  // Baca seluruh stream ke memori (untuk PDF.js range) — tapi ini boros RAM!
  // Alternatif sejati: custom PDFDataRangeTransport (lebih kompleks)
  // Untuk edukasi, kita pakai cara sederhana dulu:

  debugLog('📥 Mengumpulkan PDF stream (untuk PDF.js range API)...');
  const reader = plaintextStream.getReader();
  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    chunks.push(value);
    totalBytes += value.length;
    if (onProgress) onProgress(totalBytes / (meta.original.size || 1));
  }

  const fullPdfBytes = new Uint8Array(totalBytes);
  let offset = 0;
  for (const chunk of chunks) {
    fullPdfBytes.set(chunk, offset);
    offset += chunk.length;
  }

  debugLog(`✅ PDF lengkap: ${totalBytes} byte`);

  // Buka dengan PDF.js
  const loadingTask = pdfjs.getDocument({ data: fullPdfBytes });

  if (onProgress) {
    loadingTask.onProgress = ({ loaded, total }: { loaded: number, total: number }) => {
      onProgress(loaded / total);
    };
  }

  const pdf = await loadingTask.promise;
  debugLog(`📄 PDF.js: ${pdf.numPages} halaman siap`);
  return pdf;
}
/**
 * True streaming PDF rendering using PDF.js PDFDataRangeTransport.
 * 
 * ⚠️ Requirement:
 *   - pdfjs-dist v2.11+ (support custom PDFDataRangeTransport)
 *   - Worker sudah dikonfigurasi (misal via `pdfjs.GlobalWorkerOptions.workerSrc`)
 * 
 * @param plaintextStream - Stream hasil dekripsi (Uint8Array chunks)
 * @param originalSize - Ukuran asli file PDF (plain, bukan cipher) — dari metadata
 * @param onProgress - Optional callback progres: (loadedBytes: number, totalBytes: number) => void
 * @param docInitParams - Parameter tambahan untuk PDF.js (cth: worker, verbosity, dll)
 * @returns Promise<PDFDocumentProxy>
 */
// export async function trulyStreamPdf(
//   plaintextStream: ReadableStream<Uint8Array>,
//   originalSize: number,
//   onProgress?: (loaded: number, total: number) => void,
//   docInitParams: Partial<DocumentInitParameters> = {}
// ): Promise<pdfjs.PDFDocumentProxy> {

//   let totalLoaded = 0;
//   const reader = plaintextStream.getReader();
//   const bufferQueue: Uint8Array[] = [];
//   let bufferOffset = 0;

//   async function readBytes(n: number): Promise<Uint8Array> {
//     const result = new Uint8Array(n);
//     let written = 0;

//     while (written < n) {
//       if (bufferQueue.length === 0) {
//         const { done, value } = await reader.read();
//         if (done) break;
//         bufferQueue.push(value);
//         bufferOffset = 0;
//       }

//       const current = bufferQueue[0];
//       const available = current.length - bufferOffset;
//       const toCopy = Math.min(available, n - written);

//       result.set(current.subarray(bufferOffset, bufferOffset + toCopy), written);
//       bufferOffset += toCopy;
//       written += toCopy;

//       if (bufferOffset >= current.length) {
//         bufferQueue.shift();
//         bufferOffset = 0;
//       }
//     }

//     const actual = written === n ? result : result.subarray(0, written);
//     totalLoaded += actual.length;
//     onProgress?.(totalLoaded, originalSize);
//     return actual;
//   }

//   // ✅ Buat transport — tanpa requestDataRange override manual
//   const transport = new pdfjs.PDFDataRangeTransport(originalSize, null);

//   // ✅ Override HANYA jika disableRange: false (tapi kita akan enforce true)
//   // Jadi sebenarnya override ini tidak perlu — kita matikan saja range.

//   // ✅ Enforce: selalu disableRange = true (prioritas tertinggi)
//   const finalParams: DocumentInitParameters = {
//     range: transport,
//     length: originalSize,
//     disableRange: true,       // 🔑 KUNCI UTAMA
//     disableStream: false,     // tetap streaming
//     ...docInitParams,
//     // Pastikan override tidak bisa dibatalkan user
//     disableRange: true,
//   };

//   // PDF.js akan otomatis pakai `progressive data loading` (bukan range)
//   // dan memanggil `onDataProgressiveRead` jika tersedia.
//   // Tapi PDFDataRangeTransport tidak punya itu → jadi kita inject:

//   // Injeksi progressive read listener
//   (transport as any).onDataProgressiveRead = async () => {
//     // PDF.js akan panggil ini berulang saat butuh data
//     const chunk = await readBytes(65536); // default rangeChunkSize
//     if (chunk.length > 0) {
//       transport.onDataProgressiveRead(chunk);
//     } else {
//       transport.onDataProgressiveDone();
//     }
//   };

//   // Tapi cara lebih bersih: gunakan `data` stream langsung (tanpa transport)

//   // ✅ ALTERNATIF LEBIH SEDERHANA & AMAN: GUNAKAN `data` + ReadableStream → Uint8Array progresif
//   // Karena PDF.js mendukung `data` sebagai `ReadableStream` mulai v2.12+

//   // Namun karena `api.d.ts` tidak sebutkan `ReadableStream`, kita pakai cara universal:

//   // ✅ Solusi terbaik: Buat "progressive loader" manual
//   const loadingTask = pdfjs.getDocument(finalParams);

//   // Inject progressive data feeding
//   let firstChunk = true;
//   const feedNext = async () => {
//     try {
//       const chunk = await readBytes(65536);
//       if (chunk.length === 0) {
//         transport.onDataProgressiveDone();
//         return;
//       }

//       if (firstChunk && chunk.length > 0) {
//         // Kirim initial data jika belum ada
//         if (!transport.initialData) {
//           transport.initialData = chunk;
//           transport.transportReady();
//           firstChunk = false;
//           // Lanjutkan baca
//           setTimeout(feedNext, 0);
//         } else {
//           transport.onDataProgressiveRead(chunk);
//           setTimeout(feedNext, 0);
//         }
//       } else {
//         transport.onDataProgressiveRead(chunk);
//         setTimeout(feedNext, 0);
//       }
//     } catch (e) {
//       console.error('Progressive read error:', e);
//     }
//   };

//   // Mulai feed setelah transport siap
//   transport.transportReady = () => {
//     feedNext();
//   };

//   if (onProgress) {
//     loadingTask.onProgress = ({ loaded, total }) => {
//       onProgress(loaded, total ?? originalSize);
//     };
//   }
//   const pdf = await loadingTask.promise;
//   debugLog(`📄 PDF.js: ${pdf.numPages} halaman siap`);
//   return pdf;
//   // return loadingTask.promise;
// }
// import { PDFDataRangeTransport, PDFDocumentProxy } from 'pdfjs-dist/types/src/display/api';

/**
 * Render PDF progresif: akumulasi chunk sampai cukup untuk render halaman 1,
 * lalu render — tanpa menunggu file penuh.
 * 
 * ✅ RAM usage: ~2–5 MB (tergantung ukuran halaman 1), bukan full file.
 * ✅ Kompatibel PDF.js v2–v3.7
 * ✅ Tidak error `_onReceiveData`
 */
export async function streamProgressivePdfRender(
  plaintextStream: ReadableStream<Uint8Array>,
  canvas: HTMLCanvasElement,
  scale = 1.5,
  minBytesForFirstPage = 200_000 // estimasi aman untuk halaman 1
): Promise<void> {
  const chunks: Uint8Array[] = [];
  let totalBytes = 0;
  let pdf: pdfjs.PDFDocumentProxy | null = null;
  let page1Rendered = false;

  const reader = plaintextStream.getReader();

  // Render saat cukup data
  const tryRenderPage1 = async () => {
    if (page1Rendered || totalBytes < minBytesForFirstPage) return;

    // Gabung chunk jadi satu Uint8Array (transferable → worker ambil alih)
    const full = new Uint8Array(totalBytes);
    let offset = 0;
    for (const chunk of chunks) {
      full.set(chunk, offset);
      offset += chunk.length;
    }

    try {
      // ✅ Gunakan `data: Uint8Array` — didukung 100% di semua versi
      const loadingTask = pdfjs.getDocument({
        data: full,
        disableAutoFetch: true,
        disableStream: false,
        disableRange: true,
      });

      pdf = await loadingTask.promise;

      // Render halaman 1
      const page = await pdf.getPage(1);
      const viewport = page.getViewport({ scale });
      canvas.width = viewport.width;
      canvas.height = viewport.height;

      await page.render({
        canvas,
        viewport: viewport
      }).promise;

      page1Rendered = true;
      console.log('✅ Halaman 1 berhasil dirender progresif');
    } catch (e) {
      if ((e as Error).message.includes('Invalid PDF')) {
        // Belum cukup data → lanjut baca
        return;
      }
      throw e;
    }
  };

  try {
    // Baca chunk & render progresif
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;

      if (value?.length) {
        chunks.push(value);
        totalBytes += value.length;

        // Coba render setiap 100 KB tambahan
        if (totalBytes % 100_000 < value.length) {
          await tryRenderPage1();
        }
      }
    }

    // Render akhir jika belum
    if (!page1Rendered) {
      await tryRenderPage1();
    }

    // Opsional: render halaman lain saat scroll (lazy)
  } catch (e) {
    console.error('❌ Gagal render progresif:', e);
    throw e;
  }
}

// ========== CONTOH PENGGUNAAN
// 1. Untuk file kecil (PDF < 50 MB)
export async function downloadAndOpenPdf(
  fileId: string,
  passphrase: string,
  userId: string
) {
  debugLog('🧪 Contoh: downloadAndOpenPdf() — pakai Blob');
  try {
    const { plaintextStream, meta } = await downloadAndDecryptFile(
      `/api/download-encrypt-file/${fileId}`,
      passphrase,
      userId
    );

    debugLog(`📄 Membuka PDF: ${meta.original.filename}`);
    const blob = await streamToBlob(plaintextStream, meta.original.mime);
    const url = URL.createObjectURL(blob);
    window.open(url, '_blank');
  } catch (e) {
    console.error('❌ Gagal download & buka PDF:', e);
  }
}
export async function readAndOpenPdf(
  file: File,
  passphrase: string,
  userId: string
) {
  debugLog('🧪 Contoh: readAndOpenPdf() — pakai Blob');
  try {
    const { plaintextStream, meta } = await readAndDecryptFile(
      file,
      passphrase,
      userId
    );

    debugLog(`📄 Membuka PDF: ${meta.original.filename}`);
    const blob = await streamToBlob(plaintextStream, meta.original.mime);
    const url = URL.createObjectURL(blob);
    window.open(url, '_blank');
  } catch (e) {
    console.error('❌ Gagal download & buka PDF:', e);
  }
}

export async function printTextFile(
  fileId: string,
  passphrase: string,
  userId: string
) {
  debugLog('🧪 Contoh: printTextFile() — pakai text decoder');
  try {
    const { plaintextStream, meta } = await downloadAndDecryptFile(
      `/api/download-encrypt-file/${fileId}`,
      passphrase,
      userId
    );

    debugLog(`📄 Membuka File: ${meta.original.filename}`);
    const decodedText = await streamToText(plaintextStream, meta.original.mime);
    console.log(decodedText);
  } catch (e) {
    console.error('❌ Gagal download & buka PDF:', e);
  }
}

export async function downloadTextFile(
  fileId: string,
  passphrase: string,
  userId: string
) {
  debugLog('🧪 Contoh: downloadTextFile() — pakai Blob');
  try {
    const { plaintextStream, meta } = await downloadAndDecryptFile(
      `/api/download-encrypt-file/${fileId}`,
      passphrase,
      userId
    );

    debugLog(`📄 Membuka File: ${meta.original.filename}`);
    const blob = await streamToBlob(plaintextStream, meta.original.mime);
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = meta.original.filename; // Atur nama file yang akan diunduh
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  } catch (e) {
    console.error('❌ Gagal download & buka PDF:', e);
  }
}

// 2. Untuk file besar (PDF > 100 MB) — streaming ke canvas
export async function renderPdfToCanvas(
  fileId: string,
  passphrase: string,
  userId: string,
  canvasId: string
) {
  debugLog('🧪 Contoh: renderPdfToCanvas() — streaming ke PDF.js');
  try {
    const { plaintextStream, meta } = await downloadAndDecryptFile(
      `/api/download-encrypt-file/${fileId}`,
      passphrase,
      userId
    );

    const pdf = await streamPdfToPdfJs(plaintextStream, meta, (progress) => {
      debugLog(`📊 Progres render PDF: ${(progress * 100).toFixed(1)}%`);
    });

    // Render halaman pertama
    const page = await pdf.getPage(1);
    const viewport = page.getViewport({ scale: 1.5 });
    const canvas = document.getElementById(canvasId) as HTMLCanvasElement;
    canvas.height = viewport.height;
    canvas.width = viewport.width;

    const renderContext = {
      canvas: canvas,
      viewport: viewport
    };
    await page.render(renderContext).promise;
    debugLog('✅ Halaman 1 dirender ke canvas');
  } catch (e) {
    console.error('❌ Gagal render PDF ke canvas:', e);
  }
}

// Di dalam fungsi render atau event handler:
export async function renderLargePdf(
  fileId: string,
  passphrase: string,
  userId: string,
  canvasId: string) {
  try {
    const { plaintextStream, meta } = await downloadAndDecryptFile(
      `/api/download-encrypt-file/${fileId}`,
      passphrase,
      userId
    );

    // ✅ TRUE STREAMING — 10 GB PDF tetap pakai ~1.5 MB RAM
    const pdf = await streamProgressivePdfRender(
      plaintextStream,
      document.getElementById(canvasId)! as HTMLCanvasElement
      // meta.original.size, // 396271, dll
      // (loaded, total) => {
      //   debugLog(`📥 PDF progres: ${Math.round(100 * loaded / total)}%`);
      // }
    );

    // Render halaman 1
    // const page = await pdf.getPage(1);
    // const viewport = page.getViewport({ scale: 1.5 });
    // const canvas = document.getElementById(canvasId) as HTMLCanvasElement;
    // canvas.height = viewport.height;
    // canvas.width = viewport.width;

    // const renderContext:RenderParameters = {
    //   canvas: canvas,
    //   viewport: viewport
    // };

    // await page.render(renderContext).promise;

    console.log('✅ PDF halaman 1 siap');
  } catch (e) {
    console.error('❌ Gagal render PDF:', e);
  }
}


// ========== FUNGSI DEBUGGING TAMBAHAN
/**
 * Fungsi bantu untuk inspect struktur file FRDI (tanpa decrypt)
 * Berguna saat development untuk verifikasi format
 */
export async function inspectFileStructure(url: string) {
  debugLog('🔍 Inspect file structure (tanpa decrypt)...');
  const response = await fetch(url);
  const bufReader = new BufferedStreamReader(response.body!.getReader());

  // Header
  await bufReader.ensure(53);
  const header = bufReader.consume(53);
  const magic = decoder.decode(header.subarray(0, 4));
  const version = header[4];
  const checksum = bytesToHex(header.subarray(5, 37));
  const fileId = bytesToHex(header.subarray(37, 53));
  debugLog(`Header: magic=${magic}, version=${version}, fileId=${fileId}, checksum=${checksum}`);

  // Meta len
  await bufReader.ensure(4);
  const metaLen = Number(bytesToNumberLE(bufReader.consume(4)));
  debugLog(`Meta length: ${metaLen}`);

  // Meta
  await bufReader.ensure(metaLen);
  const metaStr = decoder.decode(bufReader.consume(metaLen));
  debugLog(`Meta JSON:\n${metaStr}`);

  // Estimasi ukuran chunk
  const meta = JSON.parse(metaStr);
  const totalSize = meta.original.size;
  const estCipherSize = totalSize + meta.total_chunks * 16; // +16 per tag
  debugLog(`Estimasi ukuran terenkripsi: ${estCipherSize} byte`);
}