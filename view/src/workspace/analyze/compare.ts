export interface FileModel {
  relative_path: string;
  sha256: string; // blob hash
  size_bytes: number;
  file_modified_at: number; // timestamp (milidetik atau detik — tidak dipakai di diff, hanya ada di model)
}

export type DiffAction = 'added' | 'deleted' | 'changed';

export interface DiffChange {
  action: DiffAction;
  path: string;
  blob?: string;
  size_change: number; // + untuk nambah, - untuk kurang
  diff_preview?: string; // opsional
}

export interface DiffResult {
  identical: number;
  changed: number;
  added: number;
  deleted: number;
  total_changes: number;
  changes: DiffChange[];
}

export interface ComputeDiffOptions {
  /**
   * Batas ukuran file (dalam byte) untuk izinkan generate diff preview.
   * Default: 10_240 (10 KB)
   */
  maxDiffPreviewSize?: number;

  /**
   * Opsional: fungsi async untuk menghasilkan diff preview berbasis blob hash.
   * Dipanggil hanya untuk file "changed" dengan size < maxDiffPreviewSize.
   * Contoh signature: `(oldHash: string, newHash: string) => Promise<string>`
   * Jika tidak diberikan, `diff_preview` tidak diisi.
   */
  generateDiffPreview?: (oldHash: string, newHash: string) => Promise<string | undefined>;
}

/**
 * Hitung perbedaan antara dua kumpulan file berdasarkan relative_path dan blob hash.
 * Logika identik dengan Laravel: path adalah primary key pembanding.
 *
 * @param sourceFiles FileModel[] — sumber (lama)
 * @param targetFiles FileModel[] — target (baru)
 * @param options Opsional: konfigurasi diff preview
 * @returns DiffResult — ringkasan + daftar perubahan
 */
export async function computeDiff(
  sourceFiles: FileModel[],
  targetFiles: FileModel[],
  options: ComputeDiffOptions = {}
): Promise<DiffResult> {
  const {
    maxDiffPreviewSize = 10 * 1024, // 10 KB
    generateDiffPreview,
  } = options;

  // Ubah ke map untuk lookup O(1)
  const sourceMap = new Map<string, FileModel>();
  const targetMap = new Map<string, FileModel>();

  for (const f of sourceFiles) sourceMap.set(f.relative_path, f);
  for (const f of targetFiles) targetMap.set(f.relative_path, f);

  const changes: DiffChange[] = [];
  let identical = 0;
  let changed = 0;
  let added = 0;
  let deleted = 0;

  // 1. Added: ada di target, tidak di source
  for (const [path, targetFile] of targetMap) {
    if(!path) console.log(path, targetFile, 'abc');
    if (!sourceMap.has(path)) {
      changes.push({
        action: 'added',
        path,
        blob: targetFile.sha256,
        size_change: targetFile.size_bytes,
      });
      added++;
    }
  }

  // 2. Deleted & Changed: iterasi source
  for (const [path, sourceFile] of sourceMap) {
    if(!path) console.log(path, 'abc');
    const targetFile = targetMap.get(path);
    if (!targetFile) {
      // Dihapus
      changes.push({
        action: 'deleted',
        blob: sourceFile.sha256,
        path,
        size_change: -sourceFile.size_bytes,
      });
      deleted++;
    } else {
      // Ada di kedua → bandingkan blob hash
      if (sourceFile.sha256 === targetFile.sha256) {
        identical++;
      } else {
        // Berubah
        const sizeDiff = targetFile.size_bytes - sourceFile.size_bytes;
        const change: DiffChange = {
          action: 'changed',
          path,
          size_change: sizeDiff,
        };

        // Generate diff preview jika diminta & memenuhi syarat ukuran
        if (
          generateDiffPreview &&
          targetFile.size_bytes < maxDiffPreviewSize
        ) {
          try {
            const preview = await generateDiffPreview(sourceFile.sha256, targetFile.sha256);
            if (preview !== undefined) {
              change.diff_preview = preview;
            }
          } catch (e) {
            // Opsional: log error, atau biarkan tanpa preview
            // change.diff_preview = '[diff error]';
          }
        }

        changes.push(change);
        changed++;
      }
    }
  }

  // Urutkan perubahan berdasarkan path (seperti Laravel)
  changes.sort((a, b) => a.path.localeCompare(b.path));

  return {
    identical,
    changed,
    added,
    deleted,
    total_changes: changed + added + deleted,
    changes,
  };
}