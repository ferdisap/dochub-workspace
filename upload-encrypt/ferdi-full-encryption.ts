// ========== DEPENDENCIES ==========
import { chacha20poly1305 } from '@noble/ciphers/chacha.js';
import { x25519 } from '@noble/curves/ed25519.js';
import { randomBytes } from '@noble/hashes/utils.js';
import { numberToBytesLE } from '@noble/curves/utils.js';
import { sha256 } from '@noble/hashes/sha2.js';
import { argon2id, createSHA256 } from 'hash-wasm';
import { hkdf } from '@noble/hashes/hkdf.js';

// ========== UTILS ==========
const enc = new TextEncoder();

function bytesToBase64(bytes: Uint8Array): string {
  return btoa(String.fromCharCode(...bytes));
}

function base64ToBytes(s: string): Uint8Array {
  return Uint8Array.from(atob(s), c => c.charCodeAt(0));
}

function uuidv4Bin(): Uint8Array {
  const b = randomBytes(16);
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  return b;
}

// ========== KEY DERIVATION (UNCHANGED) ==========
export async function deriveX25519KeyPair(
  passphrase: string,
  userId: string
): Promise<{ privateKey: Uint8Array; publicKey: Uint8Array }> {
  const salt = `ferdi:salt:${userId}`;
  const rawKey = await argon2id({
    password: passphrase,
    salt,
    iterations: 3,
    memorySize: 16384,
    parallelism: 1,
    hashLength: 32,
    outputType: 'binary',
  }) as Uint8Array;

  const priv = rawKey.slice();
  priv[0] &= 248;
  priv[31] &= 127;
  priv[31] |= 64;

  return {
    privateKey: priv,
    publicKey: x25519.getPublicKey(priv),
  };
}

export function deriveWrapKey(
  ownPriv: Uint8Array,
  recipientPub: Uint8Array,
  userId: string
): Uint8Array {
  const shared = x25519.getSharedSecret(ownPriv, recipientPub);
  return hkdf(
    sha256,
    shared,
    enc.encode('FRDI-wrap'),
    enc.encode(`wrap:${userId}`),
    32
  );
}

// ========== STREAMING SAVE (TRUE STREAMING) ==========
async function saveStreaming(
  file: File,
  metaJson: any,
  fileIdBin: Uint8Array,
  symKey: Uint8Array,
  nonceBase: Uint8Array,
  chunkSize: number,
  useFilePicker: boolean,
  filename: string
): Promise<void> {
  // ✅ Init streaming hasher (only hashes: fileId + meta_len + meta_json + chunks)
  const hasher = await createSHA256();
  hasher.init();

  // Encode metadata
  const metaJsonStr = JSON.stringify(metaJson);
  const metaLenBuf = new Uint8Array(4);
  new DataView(metaLenBuf.buffer).setUint32(0, metaJsonStr.length, true);

  // Update hash: fileId (16B) + meta_len (4B) + meta_json
  hasher.update(fileIdBin);
  hasher.update(metaLenBuf);
  hasher.update(enc.encode(metaJsonStr));

  // ✅ Prepare header (with placeholder checksum)
  const magic = enc.encode('FRDI');
  const version = new Uint8Array([1]);
  const placeholderChecksum = new Uint8Array(32); // zeros

  const headerWithoutChecksum = new Uint8Array([
    ...fileIdBin,
    ...metaLenBuf,
    ...enc.encode(metaJsonStr),
  ]);

  const fullHeader = new Uint8Array([
    ...magic,
    ...version,
    ...placeholderChecksum,
    ...headerWithoutChecksum,
  ]);

  // ✅ Get writable stream
  let writableStream: WritableStream<Uint8Array>;
  let writer: WritableStreamDefaultWriter<Uint8Array>;

  if (useFilePicker) {
    const handle = await window.showSaveFilePicker({
      suggestedName: filename,
      types: [{ description: 'Encryption', accept: { 'application/octet-stream': ['.fnc'] } }],
    });
    const writable = await handle.createWritable();
    writableStream = writable;
    writer = writable.getWriter();
  } else {
    // Fallback: Build in-memory (for Blob fallback later)
    const chunks: Uint8Array[] = [];
    writableStream = new WritableStream({
      write(chunk) {
        chunks.push(chunk);
      },
      close() {
        // Build full file after close
        const header = new Uint8Array([
          ...magic,
          ...version,
          ...new Uint8Array(32), // placeholder
          ...headerWithoutChecksum,
        ]);
        const totalSize = header.length + chunks.reduce((sum, c) => sum + c.length, 0);
        const full = new Uint8Array(totalSize);
        let pos = 0;
        full.set(header, pos); pos += header.length;
        for (const c of chunks) {
          full.set(c, pos);
          pos += c.length;
        }
        // Overwrite checksum
        const checksum = hasher.digest('binary') as Uint8Array;
        full.set(checksum, 5);
        // Trigger download
        const blob = new Blob([full], { type: 'application/octet-stream' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        a.style.display = 'none';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
      }
    });
    writer = writableStream.getWriter();
  }
  // ✅ Write header (with placeholder)
  await writer.write(fullHeader);

  // ✅ Stream process & write chunks
  let offset = 0;
  let chunkIndex = 0;

  while (offset < file.size) {
    const slice = file.slice(offset, offset + chunkSize);
    const data = new Uint8Array(await slice.arrayBuffer());

    // Build nonce: [nonceBase (4B)][counter (8B LE)]
    const nonce = new Uint8Array(12);
    nonce.set(nonceBase, 0);
    nonce.set(numberToBytesLE(BigInt(chunkIndex), 8), 4);

    // Encrypt → [cipher][tag (16B)]
    const encrypted = chacha20poly1305(symKey, nonce).encrypt(data);

    // Update hash & write immediately
    hasher.update(encrypted);
    await writer.write(encrypted);

    chunkIndex++;
    offset += slice.size;
  }

  // ✅ Finalize
  await writer.close();

  // 🔁 Only for file picker: overwrite checksum
  if (useFilePicker) {
    const globalChecksum = hasher.digest('binary') as Uint8Array;

    // Reopen & patch
    const handle = await window.showOpenFilePicker({ multiple: false });
    const [fileHandle] = handle;
    const file = await fileHandle.getFile();
    const arrayBuffer = await file.arrayBuffer();
    const bytes = new Uint8Array(arrayBuffer);

    // Overwrite bytes 5..36 (checksum field)
    bytes.set(globalChecksum, 5);

    const finalWritable = await fileHandle.createWritable({ keepExistingData: false });
    await finalWritable.write(bytes);
    await finalWritable.close();
  }
}

// ========== MAIN EXPORT (STREAMING, NO LOGIC CHANGE) ==========
export async function encryptAndSaveFile(
  file: File,
  recipientPublicKeys: Record<string, string | Uint8Array>,
  currentUserPassphrase: string,
  currentUserId: string,
  chunkSize = 1_000_000
): Promise<void> {
  // --- 1. Derive own key pair (same) ---
  const { privateKey: ownPriv, publicKey: ownPub } = await deriveX25519KeyPair(
    currentUserPassphrase,
    currentUserId
  );

  // --- 2. Ephemeral sym key (same) ---
  const symKey = randomBytes(32);
  const fileIdBin = uuidv4Bin();

  // --- 3. Encrypt symKey per recipient (same) ---
  const encryptedSymKeys: Record<string, string> = {};
  for (const [userId, pubKeyB64OrBytes] of Object.entries(recipientPublicKeys)) {
    const pubKey = typeof pubKeyB64OrBytes === 'string'
      ? base64ToBytes(pubKeyB64OrBytes)
      : pubKeyB64OrBytes;

    const wrapKey = deriveWrapKey(ownPriv, pubKey, userId);
    const nonce = randomBytes(12);
    const wrapped = chacha20poly1305(wrapKey, nonce).encrypt(symKey);
    encryptedSymKeys[userId] = bytesToBase64(new Uint8Array([...nonce, ...wrapped]));
  }

  // --- 4. Metadata (same) ---
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
      size: file.size,
    },
  };

  // --- 5. Stream save ---
  const useFilePicker = 'showSaveFilePicker' in window;
  const filename = `${file.name}.fnc`;

  try {
    await saveStreaming(
      file,
      metaJson,
      fileIdBin,
      symKey,
      nonceBase,
      chunkSize,
      useFilePicker,
      filename
    );
    console.log(`✅ Encrypted & saved: ${filename}`);
  } catch (err) {
    if (err instanceof DOMException && err.name === 'AbortError') {
      console.warn('User cancelled save.');
      return;
    }
    console.error('❌ Save failed:', err);
    throw err;
  }
}