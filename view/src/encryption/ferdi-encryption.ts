// ========== DEPENDENCIES (gunakan import map atau bundler)
import { chacha20poly1305 } from '@noble/ciphers/chacha.js';
import { blake3 } from '@noble/hashes/blake3.js';
import { x25519 } from '@noble/curves/ed25519.js';
import { randomBytes, bytesToHex } from '@noble/hashes/utils.js';
import { numberToBytesLE } from '@noble/curves/utils.js';
import { sha512, sha256 } from '@noble/hashes/sha2.js';
import { argon2id, createSHA256 } from 'hash-wasm';
import { hkdf } from '@noble/hashes/hkdf.js';
import { route_encryption_upload_chunk, route_encryption_upload_process, route_encryption_upload_start } from '../helpers/listRoute';


// ========== UTILS
const enc = new TextEncoder();
const dec = new TextDecoder();
const uploadChunkUrl = route_encryption_upload_chunk();
const encryptStartUrl = route_encryption_upload_start();
const processChunkUrl = route_encryption_upload_process();

// Hex lebih mudah dibaca/debug — cocok untuk hashname, file ID, logging.. 
// Tapi tidak bisa menghandle karakter non AsCII (>= 128 seperti "," "_" dll);
// export function bytesToHex(bytes: Uint8Array): string {
//   return Array.from(bytes)
//     .map(b => b.toString(16).padStart(2, '0'))
//     .join('');
// }

// Base64 lebih hemat space (~33% lebih pendek dari hex)
export function bytesToBase64(bytes: Uint8Array): string {
  return btoa(Array.from(bytes, b => String.fromCharCode(b)).join(''));
}
export function base64ToBytes(s: string): Uint8Array {
  return Uint8Array.from(atob(s), c => c.charCodeAt(0));
}

function ensureUint8Array(data: ArrayBuffer | Uint8Array): Uint8Array {
  return data instanceof Uint8Array ? data : new Uint8Array(data);
}

export function hexToBytes(hex: string): Uint8Array {
  if (hex.length % 2 !== 0) {
    throw new Error("Hex string must have even length");
  }
  const bytes = new Uint8Array(hex.length / 2);
  for (let i = 0; i < hex.length; i += 2) {
    bytes[i / 2] = parseInt(hex.slice(i, i + 2), 16);
  }
  return bytes;
}

// bukan 16 byte, tapi tergantung stringnya
export function stringToBytes(str: string): Uint8Array {
  return new TextEncoder().encode(str);
}

async function stringTo16Bytes(str: string) {
  // Asumsi str sudah di hash
  // Hash sekali lagi ke 16+ byte
  const encoder = new TextEncoder();
  const data = encoder.encode(str);
  const hashBuffer = await crypto.subtle.digest('SHA-256', data);
  const hashBytes = new Uint8Array(hashBuffer);
  // Ambil 16 byte pertama → fileIdBin
  return hashBytes.slice(0, 16); // ✅ Uint8Array(16)
}

// export function mimeTextList() {
//   return [
//     "application/javascript",
//     "application/json",
//     "application/xml",
//     "application/xhtml+xml",
//     "application/manifest+json",
//     "application/ld+json",
//     "application/soap+xml",
//     "application/vnd.api+json",
//     "application/atom+xml",
//     // // walaupun docx tapi ini adalah binary karena di zip
//     // "application/msword",
//     // "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
//     // "application/vnd.ms-excel",
//     // "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
//     // "application/vnd.ms-powerpoint",
//     // "application/vnd.openxmlformats-officedocument.presentationml.presentation",
//     // "application/vnd.oasis.opendocument.text",
//     "application/rss+xml",
//     // "application/pkcs7-mime",
//     // "application/pgp-signature",
//     "application/yaml",
//     "application/toml",
//     "application/x-www-form-urlencoded",
//     "application/pgp-signature",
//     "application/pkcs7-mime",
//     // "multipart/form-data",
//     "image/svg+xml",
//     "image/vnd.dxf",
//     "model/step",
//     "model/step+xml",
//     // "model/step+zip",
//     // "model/step-xml+zi",
//     // "model/iges",
//     "model/obj",
//     // "model/stl",
//     "model/gltf+json",
//     "model/vnd.collada+xml",
//   ];
// }

/**
 * Auto-select threshold vs full hashing:
 * - Jika file.size ≤ 2 * threshold → hash full
 * - Selain itu → hash threshold
 * @returns string
 */
export async function hashFile(file: File, thresholdMB = 1) :Promise<string>{
  const threshold = thresholdMB * 1024 * 1024; // 1 MB

  // jika binary dan sizenya kurang dari limit (2x threshold) maka hash full
  if (file.size > (threshold * 2)) {
    return hashFileThreshold(file, thresholdMB);
  } else {
    return hashFileFull(file);
  }
}

/**
 * tidak melibatkan file mtime (file_modified_at), murni isi file saja
 * Hitung SHA-256 full file (RAM-heavy untuk file besar — gunakan hanya jika file kecil)
 * @param file File object (browser File API)
 * @returns Hex string SHA-256 (64 lowercase chars)
 */
export async function hashFileFull(file: File | ArrayBuffer | Uint8Array<ArrayBuffer> ) :Promise<string>{
  let buffer :ArrayBuffer | Uint8Array<ArrayBuffer>;
  if((file as File).size){
    buffer = await (file as File).arrayBuffer();
  } else {
    buffer = (file as ArrayBuffer);
  }
  const hash = sha256(ensureUint8Array(buffer)); // ✅ noble terima ArrayBuffer juga
  return bytesToHex(hash);
}

export function hash(source:string):string
{
  return bytesToHex(sha256(stringToBytes(source)));
}


/**
 * Hitung SHA-256 threshold (RAM-efficient):
 * - Hash = head (1MB) + pack('J', size) + pack('J', mtime) + tail (1MB)
 * - Sesuai dengan PHP: pack('J', $size) → big-endian uint64
 *                 pack('J', $mtime) → big-endian uint64
 * 
 * output hex string, diubah karena agar mendukung pack() menghindari collision attack
 *
 * @param file File object
 * @param thresholdMB default 1 (MB)
 * @returns Hex string SHA-256 (64 lowercase chars)
 */
export async function hashFileThreshold(file: File, thresholdMB = 1) :Promise<string>{
  const threshold = thresholdMB * 1024 * 1024;

  // Inisialisasi hasher dari noble-hashes
  const hasher = sha256.create();

  // 1. Baca Head (Awal File)
  const firstSlice = file.slice(0, threshold);
  const firstBuffer = await firstSlice.arrayBuffer();
  hasher.update(new Uint8Array(firstBuffer));

  // 2. Padanan pack('J', $size) - 64-bit Unsigned Big Endian untuk menghindari collision attack
  const sizeBuffer = new ArrayBuffer(16);
  const view = new DataView(sizeBuffer);
  // Menggunakan BigInt untuk 64-bit dan false untuk Big Endian (sesuai format 'J' PHP)
  view.setBigUint64(0, BigInt(file.size), false);  // false = big-endian
  
  // Konversi lastModified ke detik (seperti filemtime PHP)
  const mtimeInSeconds = Math.floor(file.lastModified / 1000); // menghindari file yang diedit meski mengganti 1 huruf
  view.setBigUint64(8, BigInt(mtimeInSeconds), false); // Offset 8, big-endian
  hasher.update(new Uint8Array(sizeBuffer));

  // 3. Baca Tail (Akhir File) - Seperti fseek
  if (file.size > threshold) {
    const startPos = Math.max(0, file.size - threshold);
    const lastSlice = file.slice(startPos);
    const lastBuffer = await lastSlice.arrayBuffer();
    hasher.update(new Uint8Array(lastBuffer));
  }

  // Selesaikan proses hash dan ubah ke format hex string
  const hashResult = hasher.digest();

  // Helper untuk mengubah Uint8Array ke Hex String
  return bytesToHex(hashResult);
  // return Array.from(hashResult)
  //   .map((b:any) => b.toString(16).padStart(2, '0'))
  //   .join('');
}

// // rekomendasi jika berjalan di Node.js karena fs.stat selalu membersihkan cache, bermanfaat saat mengambil mtime file
// export async function hashFileThreshold(filePath: string, thresholdMB = 1) {
//   const threshold = thresholdMB * 1024 * 1024;
  
//   // Ambil metadata terbaru (Identik dengan clearstatcache di PHP)
//   // stat() di Node.js selalu mengambil data terbaru dari OS
//   const stats = await fs.stat(filePath);
//   const size = stats.size;
//   const mtime = Math.floor(stats.mtimeMs / 1000); // Konversi ms ke detik

//   const hash = createHash('sha256');
//   const handle = await fs.open(filePath, 'r');

//   try {
//     // 1. Baca Head
//     const headBuffer = Buffer.alloc(threshold);
//     const { bytesRead: headBytes } = await handle.read(headBuffer, 0, threshold, 0);
//     hash.update(headBuffer.subarray(0, headBytes));

//     // 2. Tambahkan Metadata (Size & Mtime) - Identik pack('J')
//     const metaBuffer = Buffer.alloc(16);
//     metaBuffer.writeBigUInt64BE(BigInt(size), 0);
//     metaBuffer.writeBigUInt64BE(BigInt(mtime), 8);
//     hash.update(metaBuffer);

//     // 3. Baca Tail (fseek)
//     if (size > threshold) {
//       const tailBuffer = Buffer.alloc(threshold);
//       const startPos = Math.max(0, size - threshold);
//       const { bytesRead: tailBytes } = await handle.read(tailBuffer, 0, threshold, startPos);
//       hash.update(tailBuffer.subarray(0, tailBytes));
//     }

//     return hash.digest('hex');
//   } finally {
//     await handle.close();
//   }
// }


// juga determinsitik, jadi aman untuk setiap kali aksess 
// async function deriveFileIdBin(file: File, userId: string) {
//   const hashname = await hashFileThreshold(file); // hex string
//   // jika di kasi "_" bisa simpan di storage, tapi gagal menambahkan ke meta file binary
//   // jika di kasi ":" tidak bisa simpan di storage
//   const fileIdStr = `userid${userId}hashname${hashname}`;   // versi + user + hash → deterministik & unik
//   return {
//     str: fileIdStr,
//     bin: await stringTo16Bytes(fileIdStr),
//   };
// }
// Anda sebut pakai `hashFileThreshold`, asumsikan mengembalikan hex string SHA-256
// Contoh: "a1b2c3d4e5f6..." (64 karakter hex)

export async function deriveFileIdBin(file: File, userId: string): Promise<{
  str: string;   // UUID-like hex string (32 char), BUKAN teks bebas
  bin: Uint8Array; // Uint8Array(16)
}> {
  // 1. Hash file → 32-byte hex (misal SHA-256)
  const fileHashHex = await hashFile(file); // pastikan ini hex string 64 karakter

  // 2. Gabung userId + fileHash → jadi input deterministic
  if(userId.length != 64) userId = hash(userId);
  const input = `ferdi:v1:${userId}:${fileHashHex}`; // aman: hanya ASCII tanpa `:` di akhir

  // 3. Hash ulang → 32-byte → ambil 16-byte pertama
  const encoder = new TextEncoder();
  const data = encoder.encode(input);
  const hashBuf = await crypto.subtle.digest('SHA-256', data);
  const hashBytes = new Uint8Array(hashBuf);
  const fileIdBin = hashBytes.slice(0, 16); // ✅ 16-byte binary

  // 4. Format sebagai UUID v4-like string (hex + `-`) → kompatibel Laravel & hex2bin()
  const hex = Array.from(fileIdBin)
    .map(b => b.toString(16).padStart(2, '0'))
    .join('');

  // RFC4122-compliant UUID v4-like (bukan random, tapi format valid)
  const fileIdStr = [
    hex.slice(0, 8),
    hex.slice(8, 12),
    '4' + hex.slice(13, 16), // versi 4
    (parseInt(hex[16], 16) & 0x3 | 0x8).toString(16) + hex.slice(17, 20), // variant 10xx
    hex.slice(20)
  ].join('-');

  return {
    str: fileIdStr,   // ✅ Contoh: "a1b2c3d4-e5f6-4789-9abc-def012345678"
    bin: fileIdBin,   // ✅ Uint8Array(16)
  };
}


/**
 * Hitung 4-byte mini checksum dari encrypted chunk (cipher + tag)
 * untuk deteksi korupsi jaringan/disk.
 * @param chunk Uint8Array berisi [cipher][tag] (misal: 524288 + 16 byte)
 * @returns string hex 8 karakter, lowercase (misal: "a1b2c3d4")
 */
// ✅ Tanpa cache — langsung buat baru (aman & sederhana)
export async function hashChunk(data: Uint8Array): Promise<string> {
  const hasher = await createSHA256();
  hasher.init().update(data);
  const fullHash = hasher.digest('binary') as Uint8Array; // Uint8Array(32)
  const miniHash = fullHash.subarray(0, 4); // 4 byte
  return Array.from(miniHash)
    .map(b => b.toString(16).padStart(2, '0'))
    .join(''); // contoh: "a1b2c3d4"
}

// UUID v4 (16-byte binary, RFC 4122)
function uuidv4() {
  const b = randomBytes(16);
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  return b;
}

// ========== KEY DERIVATION (deterministic dari passphrase + userId)
export function createSalt(userId: string | number): Uint8Array {
  return enc.encode(`ferdi:salt:${userId}`);
}
// ferdi-kdf.ts
// import { argon2id } from 'hash-wasm';
// import { x25519 } from '@noble/curves/ed25519.js';
export async function deriveX25519KeyPair(
  passphrase: string,
  userId: string
): Promise<{
  privateKey: Uint8Array;
  publicKey: Uint8Array;
}> {
  const salt = `ferdi:salt:${userId}`;

  // Argon2id — memory-hard, cepat di WASM
  const rawKey = await argon2id({
    password: passphrase,
    salt,
    iterations: 3,
    memorySize: 16384, // 16 MB — lebih ringan untuk HP
    parallelism: 1,
    hashLength: 32,
    outputType: 'binary'
  }) as Uint8Array;

  // RFC 7748 adjustment
  const priv = rawKey.slice(); // copy
  priv[0] &= 248;
  priv[31] &= 127;
  priv[31] |= 64;

  return {
    privateKey: priv,
    publicKey: x25519.getPublicKey(priv)
  };
}

export function deriveWrapKey(ownPriv: Uint8Array, pubKey: Uint8Array, userId: string) {
  // console.log(ownPriv, pubKey);
  const shared = x25519.getSharedSecret(ownPriv, pubKey); // Uint8Array(32)
  // Derive 32-byte wrapKey
  const wrapKey = hkdf(
    sha256,           // hash
    shared,           // ikm (input key material)
    new TextEncoder().encode('FRDI-wrap'), // salt (bisa acak, tapi fixed juga oke)
    new TextEncoder().encode(`wrap:${userId}`), // info (domain separation)
    32                // dkLen
  );
  return wrapKey;
}

// ========== MAIN ENCRYPTION (chunked, streaming, RAM-friendly)
async function requestUpload(fileId: string, metaJson: any) {
  const totalChunks = metaJson.total_chunks;
  const res = await fetch(encryptStartUrl, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-File-Id': fileId,
      'X-Total-Chunks': totalChunks,
    },
    body: JSON.stringify(metaJson),
    // Avoid timeout for large chunks
    signal: AbortSignal.timeout(30_000),
  });
}
async function uploadChunk(fileId: string, encryptedChunk: Uint8Array, chunkIndex: number) {
  const hash = await hashChunk(encryptedChunk);
  try {
    const res = await fetch(uploadChunkUrl, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/octet-stream',
        'X-Requested-With': 'XMLHttpRequest',
        'X-File-Id': fileId,
        // opsional: tambahkan checksum sementara per chunk
        'X-Chunk-Hash': hash,
        'X-Chunk-Index': String(chunkIndex),
      },
      body: encryptedChunk,
      // Avoid timeout for large chunks
      signal: AbortSignal.timeout(30_000),
    });
    if (res.ok) {
      localStorage.setItem(`file:upload:${fileId}:${hash}`, hash);
      return hash;
    }
    throw new Error('Upload Chunk Response not ok')
  } catch (error) {
    throw error;
  }
}
async function prosesChunk(fileId: string, hashes: string[]) {
  const res = await fetch(processChunkUrl, {
    method: 'POST',
    headers: {
      'Content-Type': 'application.json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-File-Id': fileId,
    },
    body: JSON.stringify(hashes),
    // Avoid timeout for large chunks
    signal: AbortSignal.timeout(30_000),
  });
  // nanti remove seluruh local storage
  if (res.ok) {
    hashes.forEach(hash => localStorage.removeItem(`file:upload:${fileId}:${hash}`));
    return true;
  }
  throw new Error('Proses Chunk Response not ok');
}
export async function uploadFile(
  file: File,
  recipientPublicKeys: Record<string, Uint8Array>,
  ownerPrivateKey: Uint8Array,
  ownerPublicKey: Uint8Array,
  currentUserId: string | number,
  chunkSize = 1_000_000 // 1MB default
) {

  // 1. Ephemeral symmetric key
  const symKey = randomBytes(32);
  const { str: fileId, bin: fileIdBin } = await deriveFileIdBin(file, String(currentUserId));

  // 2. Encrypt symKey for each recipient (X25519 + ChaCha20-Poly1305)
  // const { privateKey: ownPriv, publicKey:ownPub } = await deriveX25519KeyPair(currentUserPassphrase, currentUserId);
  // console.log(currentUserPassphrase, currentUserId, bytesToBase64(ownPriv),bytesToBase64(ownPub));
  // throw new Error("aa"); 
  const encryptedSymKeys: Record<string, string> = {};
  for (const [userId, pubKey] of Object.entries(recipientPublicKeys)) {
    const wrapKey = deriveWrapKey(ownerPrivateKey, pubKey, userId);
    // nonce unik per encryption (12B CSPRNG)
    const nonce = randomBytes(12);
    const wrapped = chacha20poly1305(wrapKey, nonce).encrypt(symKey);
    encryptedSymKeys[userId] = bytesToBase64(new Uint8Array([...nonce, ...wrapped]));
  }

  // 3. Metadata
  const totalChunks = Math.ceil(file.size / chunkSize);
  const nonceBase = randomBytes(4);
  const metaJson = {
    chunk_size: chunkSize,
    total_chunks: totalChunks,
    nonce_base: bytesToBase64(nonceBase),
    encrypted_sym_keys: encryptedSymKeys,
    owner_pub_key: bytesToBase64(ownerPublicKey),
    original: {
      filename: file.name,
      mime: file.type,
      size: file.size
    }
  };

  // #1. register the to the server
  await requestUpload(fileId, metaJson);

  // #2. enkripsi chunk
  let offset = 0;
  let chunkIndex = 0;
  let hashes: string[] = [];
  while (offset < file.size) {
    // Baca chunk
    const slice = file.slice(offset, offset + chunkSize);
    const arrayBuffer = await slice.arrayBuffer();
    const data = new Uint8Array(arrayBuffer);

    // Build 12B nonce: [nonceBase (4B)][counter (8B LE)]
    const nonce = new Uint8Array(12);
    nonce.set(nonceBase, 0);
    nonce.set(numberToBytesLE(BigInt(chunkIndex), 8), 4);
    const encryptedFile = chacha20poly1305(symKey, nonce).encrypt(data); // [cipher][16B tag]

    // alert(`${data.length + 16 } and ${encryptedFile.length}`); // sama
    const hash = await uploadChunk(fileId, encryptedFile, chunkIndex);
    hashes.push(hash);
    chunkIndex++;
    offset += slice.size;
  }

  const success = await prosesChunk(fileId, hashes);
  if (success) console.log("Upload Encrypted File success ");
  else console.log("Upload Encrypted File fail");
}