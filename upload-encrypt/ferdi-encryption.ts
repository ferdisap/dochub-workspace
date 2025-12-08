// ========== DEPENDENCIES (gunakan import map atau bundler)
import { chacha20poly1305 } from '@noble/ciphers/chacha.js';
import { blake3 } from '@noble/hashes/blake3.js';
import { x25519 } from '@noble/curves/ed25519.js';
import { randomBytes, bytesToHex } from '@noble/hashes/utils.js';
import { numberToBytesLE } from '@noble/curves/utils.js';
import { sha512, sha256 } from '@noble/hashes/sha2.js';
import { argon2id, createSHA256 } from 'hash-wasm';
import { hkdf } from '@noble/hashes/hkdf.js';


// ========== UTILS
const enc = new TextEncoder();
const dec = new TextDecoder();

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

// output hex string
export async function hashFileThreshold(file: File, thresholdMB = 1) {
  const threshold = thresholdMB * 1024 * 1024; // 1 MB

  // Jika file kecil → hash full
  if (file.size <= threshold * 2) {
    // jika kurang dari 2mb
    const buffer = await file.arrayBuffer();
    // return sha256(buffer);
    const hash = sha256(ensureUint8Array(buffer)); // ✅ noble terima ArrayBuffer juga
    return bytesToHex(hash);
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

async function deriveFileIdBin(file: File, userId: string): Promise<{
  str: string;   // UUID-like hex string (32 char), BUKAN teks bebas
  bin: Uint8Array; // Uint8Array(16)
}> {
  // 1. Hash file → 32-byte hex (misal SHA-256)
  const fileHashHex = await hashFileThreshold(file); // pastikan ini hex string 64 karakter

  // 2. Gabung userId + fileHash → jadi input deterministic
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
  const endpoint = "/api/upload-encrypt-start";
  const totalChunks = metaJson.total_chunks;
  const res = await fetch(endpoint, {
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
  const endpoint = "/api/upload-encrypt-chunk";
  const hash = await hashChunk(encryptedChunk);
  try {
    const res = await fetch(endpoint, {
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
  const endpoint = "/api/upload-encrypt-process";
  const res = await fetch(endpoint, {
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
  currentUserPassphrase: string,
  currentUserId: string | number,
  chunkSize = 1_000_000 // 1MB default
) {

  // 1. Ephemeral symmetric key
  const symKey = randomBytes(32);
  const { str: fileId, bin: fileIdBin } = await deriveFileIdBin(file, String(currentUserId));

  // 2. Encrypt symKey for each recipient (X25519 + ChaCha20-Poly1305)
  const { privateKey: ownPriv, publicKey:ownPub } = await deriveX25519KeyPair(currentUserPassphrase, currentUserId);
  // console.log(currentUserPassphrase, currentUserId, bytesToBase64(ownPriv),bytesToBase64(ownPub));
  // throw new Error("aa"); 
  const encryptedSymKeys: Record<string, string> = {};
  for (const [userId, pubKey] of Object.entries(recipientPublicKeys)) {
    const wrapKey = deriveWrapKey(ownPriv, pubKey, userId);
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
    owner_pub_key: bytesToBase64(ownPub),
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