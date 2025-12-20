import { hashFileFull, hashFileThreshold } from "../../encryption/ferdi-encryption";
import { DhFile, DhFileParam } from "./DhFile";

export class DhBlob {

  protected partialVerifyThreshold:number = 0 ; // defaultnya 2 mb
  protected partialHashByte:number = 0; // defaultnya 1mb
  protected fileSystem:DhFileParam;
  protected file:File | null = null;
  protected hash:string | null = null;

  constructor(fileSystem: DhFileParam) {
    this.partialVerifyThreshold = 2 * 1024 * 1024;
    this.partialHashByte = 1 * 1024 * 1024;
    this.fileSystem = fileSystem;
  }

  async getFile():Promise<File>{
    if(!this.file) await this.fileSystem.getFile();
    return this.file!;
  }

  /**
 * Dapatkan SHA-256 hash file:
 * - Jika providedHash diberikan → verifikasi (full/partial tergantung ukuran)
 * - Jika tidak → hitung sendiri
 *
 * @param providedHash Opsional: SHA-256 dari pihak ketiga (64 hex chars)
 * @returns SHA-256 hex string (64 lowercase chars)
 */
  async resolveHash(providedHash?: string | null): Promise<string> {
    if(this.hash) return this.hash;
    if(!this.file) this.file = await this.fileSystem.getFile();
    if (providedHash) {
      providedHash = providedHash.trim().toLowerCase();

      // Validasi format SHA-256
      if (!/^[a-f0-9]{64}$/.test(providedHash)) {
        throw new Error('Invalid SHA-256 hash format');
      }

      // Full verify jika file ≤ threshold
      if (this.file.size <= this.partialVerifyThreshold) {
        const calculated = await hashFileFull(this.file);
        if (providedHash !== calculated) {
          throw new Error('Provided hash mismatch (full verify)');
        }
      } else {
        // Partial verify untuk file besar
        await this.verifyPartialHash(providedHash);
      }

      return providedHash;
    }

    // Hitung sendiri
    if (this.file.size <= this.partialVerifyThreshold) {
      return this.hash = await hashFileFull(this.file);
    } else {
      return this.hash = await hashFileThreshold(this.file, this.partialHashByte);
    }
  }

  /**
 * Verifikasi partial hash untuk file besar:
 * - Bandingkan hanya 12 karakter pertama (hex) dari hash threshold
 *
 * @param expectedHash SHA-256 full hash (64 hex chars, lowercase)
 * @throws Error jika gagal verifikasi
 */
  async verifyPartialHash(expectedHash: string): Promise<void> {
    // Validasi expectedHash
    if (!/^ [a - f0 - 9]{ 64 } $ /.test(expectedHash)) {
      throw new Error('Invalid SHA-256 hash format');
    }

    // Hitung partial hash (threshold-based)
    if(!this.file) this.file = await this.fileSystem.getFile();
    const sampleHash = await hashFileThreshold(this.file, this.partialHashByte);

    // Bandingkan hanya 12 karakter pertama — cukup untuk deteksi error
    const expectedPrefix = expectedHash.substring(0, 12);
    const actualPrefix = sampleHash.substring(0, 12);

    if (expectedPrefix !== actualPrefix) {
      throw new Error('Partial hash verification failed');
    }
  }
}