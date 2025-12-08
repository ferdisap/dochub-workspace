// Anggap Anda sudah mengimpor semua dari ferdi-encryption.ts
import { downloadAndOpenPdf, renderLargePdf, renderPdfToCanvas, printTextFile, readAndDecryptFile, readAndOpenPdf } from './ferdi-decryption';
import {
  // encryptFile,
  // uploadChunks,
  deriveX25519KeyPair,
  createSalt,
  uploadFile,
} from './ferdi-encryption';
import { encryptAndSaveFile } from './ferdi-full-encryption';

// Simulasi: ambil file dari input
const input = document.getElementById('fileInput') as HTMLInputElement;
input.onchange = () => {
  const file = input.files?.[0];
  if (!file) return;

  // === 1. Siapkan kunci publik penerima (Budi & Citra) ===
  // Di dunia nyata: ambil dari server / localStorage / IndexedDB
  // Di sini kita generate dulu untuk demo (deterministik dari passphrase + userId)
  const recipients = {
    budi: { userId: 'budi123', passphrase: 'budi-secret-pass' },
    citra: { userId: 'citra456', passphrase: 'citra-secret-pass' },
  };


  // === 2. Siapkan kunci pengirim (Ayu) ===
  // jangan ada karakter non ascii kalau bisa biar tidak repot membersihkan
  const userId = 'ayu123'; // ayu789 => b5cd30fc-1f8b-4779-a863-8a4722572911, ayu123 => e9ccdea4-035d-4f90-bfcd-70a17a4411c5
  const passphrase = 'ayu-secret-pass';

  // // enrcyptAndSave(file, recipients, passphrase, userId);
  encryptAndUpload(file, recipients, passphrase, userId);
  // readDecryptedFile(file);
}

async function enrcyptAndSave(file: File, recipients: Record<string, any>, passphrase: string, userId: string) {
  const recipientPublicKeys: Record<string, Uint8Array> = {};
  for (const [name, { userId, passphrase }] of Object.entries(recipients)) {
    const { publicKey } = await deriveX25519KeyPair(passphrase, userId);
    recipientPublicKeys[userId] = publicKey;
  }

  try {
    await encryptAndSaveFile(file, recipientPublicKeys, passphrase, userId);    
    console.log('🎉 Upload selesai! File siap diunduh.');
  }catch (err) {
    console.error(err);
    console.error('❌ Gagal:', (err as Error).message);
    // alert('Gagal enkripsi/upload: ' + (err as Error).message);
  }
}

async function encryptAndUpload(file: File, recipients: Record<string, any>, passphrase: string, userId: string) {

  const recipientPublicKeys: Record<string, Uint8Array> = {};
  for (const [name, { userId, passphrase }] of Object.entries(recipients)) {
    const { publicKey } = await deriveX25519KeyPair(passphrase, userId);
    recipientPublicKeys[userId] = publicKey;
  }

  // === 3. Enkripsi file (streaming, low RAM) ===
  try {

    await uploadFile(file, recipientPublicKeys, passphrase, userId);
    console.log('🎉 Upload selesai! File siap diunduh.');

    // Opsional: auto-download setelah upload
    // window.location.href = `/download-encrypt-file/${metadata.filename}.fenc`;

  } catch (err) {
    console.error(err);
    console.error('❌ Gagal:', (err as Error).message);
    // alert('Gagal enkripsi/upload: ' + (err as Error).message);
  }
}

function readDecryptedFile(file:File){
  const passphrase = "budi-secret-pass";
  const userId = "budi123";
  readAndOpenPdf(file,passphrase, userId);
}

function openNewWindow(fileId: string) {
  const passphrase = "budi-secret-pass";
  const userId = "budi123";
  return downloadAndOpenPdf(fileId, passphrase, userId);
}
function openInCanvas(fileId: string) {
  const passphrase = "budi-secret-pass";
  const userId = "budi123";
  const canvasId = "pdf-canvas";
  return renderPdfToCanvas(fileId, passphrase, userId, canvasId);
}
function renderStreamPdf(fileId: string) {
  const passphrase = "budi-secret-pass";
  const userId = "budi123";
  const canvasId = "pdf-canvas";
  return renderLargePdf(fileId, passphrase, userId, canvasId);
}
function printFile(fileId: string) {
  const passphrase = "budi-secret-pass";
  const userId = "budi123";
  return printTextFile(fileId, passphrase, userId);
}

const btnDownload = document.getElementById('downloadFile') as HTMLButtonElement;
btnDownload.onclick = () => {
  // const id = "b5cd30fc-1f8b-4779-a863-8a4722572911"; // soal tes cpns
  const id = "e9ccdea4-035d-4f90-bfcd-70a17a4411c5"; // soal tes cpns
  // const id = "973772ee-5bf4-496e-8518-ca39d9ed1abd"; // ipot manual
  // const id = "bbefe38a-9a8d-47f1-8b1a-2fbf098a7640"; // DMC-MALE-A-16-00-01-00A-018A-A_000-01_EN-EN
  // const id = "manual.pdf"; // ipot manual
  // openInCanvas(id);
  // printFile(id);
  openNewWindow(id);
  // renderStreamPdf(id);
}
// top.download = download