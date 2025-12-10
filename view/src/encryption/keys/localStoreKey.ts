/**
 * ------------
 * GLOBAL VAR
 * ------------
 */
const DB_NAME = 'secure-db';
const STORE_NAME = 'keys';
const KEK_RECORD_NAME = 'kek';
const IV_STORAGE_NAME = "ferdi-pv-iv";
const CIPHER_STORAGE_NAME = "ferdi-pv-ciphertext";

/**
 * ----------------------------------------------
 * Helper untuk IndexedDB (native, tanpa library)
 * ----------------------------------------------
 */
// const __openDBConnections: IDBDatabase[] = [];
const __openDBConnections: Array<IDBDatabase & { isClosed?: boolean }> = [];
function openDB(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, 1);

    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains(STORE_NAME)) {
        db.createObjectStore(STORE_NAME);
      }
    };

    req.onerror = () => reject(req.error);

    req.onsuccess = () => {
      const db = req.result as IDBDatabase & { isClosed?: boolean };
      db.isClosed = false;

      // catat ke registry
      __openDBConnections.push(db);

      // bila browser memaksa versionchange → auto close
      db.onversionchange = () => {
        db.close();
        db.isClosed = true;
      };

      // override close() agar flag ikut berubah
      const realClose = db.close.bind(db);
      db.close = () => {
        db.isClosed = true;
        realClose();
      };

      resolve(db);
    };
  });
}


async function saveKeyToDB(key: CryptoKey) {
  const db = await openDB();
  const tx = db.transaction(STORE_NAME, "readwrite");
  tx.objectStore(STORE_NAME).put(key, KEK_RECORD_NAME);
  return new Promise((resolve) => (tx.oncomplete = resolve));
}

async function loadKEKFromDB(): Promise<CryptoKey | null> {
  const db = await openDB();
  const tx = db.transaction(STORE_NAME, "readonly");
  const request = tx.objectStore(STORE_NAME).get(KEK_RECORD_NAME);

  return new Promise((resolve) => {
    request.onsuccess = () => resolve(request.result || null);
    request.onerror = () => resolve(null);
  });
}

// hapus semua di secure-db
export function clearDB() {
  closeAllDBConnections(); // penting!

  return new Promise((resolve, reject) => {
    const req = indexedDB.deleteDatabase(DB_NAME);

    req.onblocked = () => {
      console.warn("Delete blocked — masih ada tab/connection. Tunggu...");
    };

    req.onsuccess = () => {
      console.log("Database berhasil dihapus.");
      resolve(true);
    };

    req.onerror = () => {
      console.error("Gagal hapus DB:", req.error);
      reject(req.error);
    };
  });
}

// Hapus hanya object store tertentu, yaitu keys
export async function erase() {
  const db = await openDB();
  const tx = db.transaction(STORE_NAME, "readwrite");
  tx.objectStore(STORE_NAME).clear();   // menghapus semua record
  return new Promise(resolve => tx.oncomplete = resolve);
}

// hapus kek saja
export async function eraseKEK() {
  const db = await openDB();
  const tx = db.transaction(STORE_NAME, "readwrite");
  tx.objectStore(STORE_NAME).delete(KEK_RECORD_NAME);

  return new Promise(resolve => tx.oncomplete = resolve);
}


export function listDBConnections() {
  return __openDBConnections.filter(db => !db.closed);
}

export function closeAllDBConnections() {
  console.log("Closing all IndexedDB connections...");

  for (const db of __openDBConnections) {
    try {
      if (!db.closed) {
        db.close();
      }
    } catch (e) {
      console.warn("Error closing db:", e);
    }
  }
}


/**
 * ---------------------------------------
 * Generate KEK (AES-GCM, non-extractable)
 * ---------------------------------------
 */
async function generateKEK(): Promise<CryptoKey> {
  return crypto.subtle.generateKey(
    {
      name: "AES-GCM",
      length: 256
    },
    false, // <-- non-extractable
    ["encrypt", "decrypt"]
  );
}

/**
 * -----------------------------
 * Encrypt PrivateKey pakai KEK
 * -----------------------------
 */
async function encryptPrivateKey(kek: CryptoKey, privateKey: Uint8Array) {
  const iv = crypto.getRandomValues(new Uint8Array(12));

  const ciphertext = new Uint8Array(
    await crypto.subtle.encrypt(
      {
        name: "AES-GCM",
        iv
      },
      kek,
      privateKey
    )
  );

  return { ciphertext, iv };
}

/**
 * -----------------------------
 * Decrypt kembali privateKey
 * -----------------------------
 */
async function decryptPrivateKey(
  kek: CryptoKey,
  ciphertext: Uint8Array,
  iv: Uint8Array
) {
  const plaintext = new Uint8Array(
    await crypto.subtle.decrypt(
      {
        name: "AES-GCM",
        iv
      },
      kek,
      ciphertext
    )
  );

  return plaintext;
}

export async function storeLocal(privateKey: Uint8Array) {
  const kek = await generateKEK();
  await saveKeyToDB(kek);
  const { ciphertext, iv } = await encryptPrivateKey(kek, privateKey);
  // ciphertext + iv aman disimpan di localStorage
  localStorage.setItem(CIPHER_STORAGE_NAME, btoa(String.fromCharCode(...ciphertext)));
  localStorage.setItem(IV_STORAGE_NAME, btoa(String.fromCharCode(...iv)));
}
export async function readLocal(): Promise<Uint8Array> {
  const kek = await loadKEKFromDB();
  if (!kek) {
    throw new Error("KEK tidak ditemukan — berarti belum setup pertama.");
  }

  const ciphertext = Uint8Array.from(
    atob(localStorage.getItem(CIPHER_STORAGE_NAME) || ""),
    (c) => c.charCodeAt(0)
  );

  const iv = Uint8Array.from(
    atob(localStorage.getItem(IV_STORAGE_NAME) || ""),
    (c) => c.charCodeAt(0)
  );

  const privateKey = await decryptPrivateKey(kek, ciphertext, iv);

  return privateKey;
}

// contoh simulasi
// const privateKey = new Uint8Array([1, 2, 3, 4, 5, 6]); // didapat dari deriveX25519KeyPair
// async function firstTimeSetup(privateKey: Uint8Array) {
//   console.log("1) Generate KEK...");
//   const kek = await generateKEK();

//   console.log("2) Simpan KEK ke IndexedDB...");
//   await saveKeyToDB(kek);

//   console.log("3) Encrypt privateKey...");
//   const { ciphertext, iv } = await encryptPrivateKey(kek, privateKey);

//   // ciphertext + iv aman disimpan di localStorage
//   localStorage.setItem("ciphertext", btoa(String.fromCharCode(...ciphertext)));
//   localStorage.setItem("iv", btoa(String.fromCharCode(...iv)));

//   console.log("Done. Reload browser untuk test decrypt.");
// }

// async function unlockPrivateKey() {
//   console.log("1) Load KEK dari IndexedDB...");
//   const kek = await loadKEKFromDB();
//   if (!kek) {
//     console.error("KEK tidak ditemukan — berarti belum setup pertama.");
//     return;
//   }

//   console.log("2) Load ciphertext + iv dari localStorage...");
//   const ciphertext = Uint8Array.from(
//     atob(localStorage.getItem("ciphertext") || ""),
//     (c) => c.charCodeAt(0)
//   );

//   const iv = Uint8Array.from(
//     atob(localStorage.getItem("iv") || ""),
//     (c) => c.charCodeAt(0)
//   );

//   console.log("3) Decrypt privateKey...");
//   const privateKey = await decryptPrivateKey(kek, ciphertext, iv);

//   console.log("PrivateKey berhasil didecrypt:", privateKey);
//   return privateKey;
// }
