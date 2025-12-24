import { DhFile, FileObject, DhFileParam, DhFolderParam } from "./DhFile";
import { useAuthData } from "../utils/auth";
import {
	deriveFileIdBin,
	hash as encryptionHash,
} from "../../encryption/ferdi-encryption";
import { scanDirectory } from "../analyze/folderUtils";

/**
 * --------------------
 * ManifestSourceType
 * --------------------
 */
export enum ManifestSourceType {
	CMS = "cms",
	API = "api",
	UPLOAD = "upload",
	CI = "ci",
	BACKUP = "backup",
	MIGRATION = "migration",
}

export namespace ManifestSourceType {
	/**
	 * Dapatkan semua nilai enum sebagai array string
	 */
	export function values(): string[] {
		return Object.values(ManifestSourceType) as string[];
	}

	/**
	 * Cek apakah string adalah nilai enum yang valid
	 */
	export function isValid(type: string): boolean {
		return values().includes(type);
	}
}

/**
 * --------------------
 * ManifestSourceParser
 * --------------------
 *
 * import { ManifestSourceType, ManifestSourceParser } from './manifest';
 * const userId = '12345';
 * const source = ManifestSourceParser.makeSource(
 *   ManifestSourceType.UPLOAD,
 *   `user-${userId}`
 * );
 * console.log(source); // ✅ "upload:user-12345"
 */

// Pola regex (sama persis dengan PHP)
// const TYPE_PATTERN = /^[a-z]+$/;
const IDENTIFIER_PATTERN = /^[a-z0-9-]+$/;
const ENV_PATTERN = /^(prod|staging|dev|test)$/;
const VERSION_PATTERN = /^v?\d+(\.\d+)*[a-z0-9-]*$/i;

/**
 * Hasil parsing source
 */
export interface ParsedSource {
	type: string;
	identifier: string;
	environment: string | null;
	version: string | null;
}

/**
 * Parser untuk manifest source string (e.g., 'upload:user-123', 'cms:client-a-prod-v2')
 */
export class ManifestSourceParser {
	/**
	 * Buat source string dari komponen
	 *
	 * Format: `{type}:{identifier}[-{environment}][-{version}]`
	 * Contoh: 'upload:user-123', 'cms:client-a-prod', 'api:service-dev-rc1'
	 *
	 * @throws Error jika input tidak valid
	 */
	static makeSource(
		type: string,
		identifier: string,
		environment?: string | null,
		version?: string | null
	): string {
		// Validasi type (harus enum value)
		if (!ManifestSourceType.isValid(type)) {
			throw new Error(
				`Invalid type '${type}'. Must be one of: ${ManifestSourceType.values().join(", ")}`
			);
		}

		// Validasi identifier
		if (!IDENTIFIER_PATTERN.test(identifier)) {
			throw new Error(
				`Invalid identifier '${identifier}'. Must be lowercase alphanumeric with hyphens (e.g., 'client-a').`
			);
		}

		// Validasi environment
		if (environment != null && !ENV_PATTERN.test(environment)) {
			throw new Error(
				`Invalid environment '${environment}'. Must be 'prod', 'staging', 'dev', or 'test'.`
			);
		}

		// Validasi version
		if (version != null && !VERSION_PATTERN.test(version)) {
			throw new Error(
				`Invalid version '${version}'. Must be like 'v1', '2.1.0', 'rc-1'.`
			);
		}

		// Bangun string: type:identifier[-env][-ver]
		const parts = [identifier];
		if (environment) parts.push(environment);
		if (version) parts.push(version);

		return `${type}:${parts.join("-")}`;
	}

	/**
	 * Parse source string menjadi komponen
	 *
	 * @throws Error jika format tidak valid
	 */
	static parseSource(source: string): ParsedSource {
		// Validasi format dasar: type:identifier[-...]
		if (!/^[a-z]+:[a-z0-9-]+(?:-[a-z0-9-]+)*$/.test(source)) {
			throw new Error(
				"Invalid source format. Expected 'type:identifier[-env][-ver]' (e.g., 'cms:client-a-prod-v1')."
			);
		}

		const [typePart, restPart] = source.split(":", 2);
		if (restPart === undefined) {
			throw new Error("Source must contain exactly one ':'");
		}

		const type = typePart;
		const segments = restPart.split("-");

		if (segments.length === 0) {
			throw new Error("Identifier cannot be empty");
		}

		let identifier = segments[0];
		let environment: string | null = null;
		let version: string | null = null;

		// Proses dari belakang: cek version dulu, lalu environment
		const remaining = segments.slice(1); // Semua setelah identifier

		// Cek version (yang terakhir)
		if (remaining.length > 0) {
			const last = remaining[remaining.length - 1];
			if (VERSION_PATTERN.test(last)) {
				version = last;
				remaining.pop();
			}
		}

		// Cek environment (yang terakhir sekarang)
		if (remaining.length > 0) {
			const last = remaining[remaining.length - 1];
			if (ENV_PATTERN.test(last)) {
				environment = last;
				remaining.pop();
			}
		}

		// Gabungkan sisa sebagai bagian identifier
		if (remaining.length > 0) {
			identifier += "-" + remaining.join("-");
		}

		// Validasi akhir
		if (!ManifestSourceType.isValid(type)) {
			throw new Error(`Invalid type in parsed source: '${type}'`);
		}
		if (!IDENTIFIER_PATTERN.test(identifier)) {
			throw new Error(`Invalid identifier in parsed source: '${identifier}'`);
		}

		return { type, identifier, environment, version };
	}

	/**
	 * Helper: Cek apakah source string valid
	 */
	static isValid(source: string): boolean {
		try {
			this.parseSource(source);
			return true;
		} catch {
			return false;
		}
	}

	/**
	 * Helper: Dapatkan environment dari source
	 */
	static getEnvironment(source: string): string | null {
		return this.parseSource(source).environment;
	}

	/**
	 * Helper: Dapatkan version dari source
	 */
	static getVersion(source: string): string | null {
		return this.parseSource(source).version;
	}
}

/**
 * --------------------
 * ManifestVersionParser
 * --------------------
 * Parser versi manifest berbasis ISO 8601.
 * Format yang didukung: `YYYY-MM-DDTHH:mm:ss.SSSZ`
 * Contoh: "2025-12-20T15:30:45.123Z"
 *
 * // Buat versi baru (saat ini)
 * const version1 = ManifestVersionParser.makeVersion();
 * console.log(version1); // "2025-12-20T15:30:45.123Z"
 *
 * // Validasi
 * console.log(ManifestVersionParser.isValid("2025-12-20T15:30:45.123Z")); // true
 * console.log(ManifestVersionParser.isValid("2025-13-01T00:00:00.000Z")); // false (bulan 13)
 * console.log(ManifestVersionParser.isValid("2025-12-20T15:30:45Z"));      // false (tidak ada .ms)
 *
 * // Konversi dari berbagai input
 * console.log(ManifestVersionParser.timestampToIso9601("2025-01-01"));
 * // → "2025-01-01T00:00:00.000Z"
 *
 * console.log(ManifestVersionParser.timestampToIso9601(1735603200));
 * // → "2025-01-01T00:00:00.000Z" (jika Unix detik)
 *
 * console.log(ManifestVersionParser.timestampToIso9601(new Date()));
 * // → versi saat ini
 */
export class ManifestVersionParser {
	/** Saat ini hanya mendukung ISO 8601 */
	public static readonly method = "ISO 8601";

	/** Format ISO 8601 dengan milidetik dan Z (UTC) */
	private static readonly isoFormat = "YYYY-MM-DDTHH:mm:ss.SSSZ";

	/** Regex untuk validasi struktur (tanpa validasi logika tanggal) */
	private static readonly isoPattern =
		/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/;

	/**
	 * Buat versi baru berdasarkan waktu saat ini (UTC)
	 * Format: YYYY-MM-DDTHH:mm:ss.SSSZ
	 *
	 * @returns string versi ISO 8601, e.g. "2025-12-20T15:30:45.123Z"
	 */
	public static makeVersion(): string {
		const now = new Date();

		const year = now.getUTCFullYear();
		const month = String(now.getUTCMonth() + 1).padStart(2, "0");
		const day = String(now.getUTCDate()).padStart(2, "0");
		const hours = String(now.getUTCHours()).padStart(2, "0");
		const minutes = String(now.getUTCMinutes()).padStart(2, "0");
		const seconds = String(now.getUTCSeconds()).padStart(2, "0");
		const milliseconds = String(now.getUTCMilliseconds()).padStart(3, "0");

		return `${year}-${month}-${day}T${hours}:${minutes}:${seconds}.${milliseconds}Z`;
	}

	/**
	 * Validasi apakah string adalah versi ISO 8601 yang valid
	 *
	 * @param version String versi, e.g. "2025-12-20T15:30:45.123Z"
	 * @param method Hanya 'ISO 8601' yang didukung
	 * @returns boolean
	 */
	public static isValid(version: string, method: string = "ISO 8601"): boolean {
		if (method !== "ISO 8601") {
			return false;
		}
		return this.isIsoFormat(version);
	}

	/**
	 * Konversi input timestamp/string ke format ISO 8601 target
	 *
	 * @param ts Input: string tanggal (ISO, RFC2822, Unix, dll) atau kosong
	 * @returns string dalam format "YYYY-MM-DDTHH:mm:ss.SSSZ"
	 */
	public static timestampToIso9601(ts?: string | number | Date): string {
		let date: Date;

		if (ts === undefined || ts === null) {
			date = new Date();
		} else if (ts instanceof Date) {
			date = ts;
		} else if (typeof ts === "number") {
			// Anggap sebagai Unix timestamp (detik atau milidetik)
			date = new Date(ts * (ts > 1e10 ? 1 : 1000)); // >10^10 → milidetik
		} else {
			// string: coba parse
			date = new Date(ts);
			if (isNaN(date.getTime())) {
				throw new Error(`Invalid timestamp input: '${ts}'`);
			}
		}

		return this.formatIso(date);
	}

	// ─── PRIVATE ───────────────────────────────────────────────────────

	/**
	 * Validasi string sesuai pola dan parsing aman ke Date
	 */
	private static isIsoFormat(ts: string): boolean {
		if (!this.isoPattern.test(ts)) {
			return false;
		}

		// Coba parse dengan format eksplisit → deteksi overflow (e.g. bulan 13)
		const match = ts.match(
			/(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})\.(\d{3})Z/
		);
		if (!match) return false;

		const [, y, m, d, hh, mm, ss, ms] = match.map(Number);

		// Validasi range manual (karena new Date() tidak strict)
		if (
			m < 1 ||
			m > 12 ||
			d < 1 ||
			d > 31 ||
			hh < 0 ||
			hh > 23 ||
			mm < 0 ||
			mm > 59 ||
			ss < 0 ||
			ss > 59 ||
			ms < 0 ||
			ms > 999
		) {
			return false;
		}

		// Buat tanggal UTC dan bandingkan round-trip
		const date = new Date(Date.UTC(y, m - 1, d, hh, mm, ss, ms));
		return this.formatIso(date) === ts;
	}

	/**
	 * Format Date → string ISO 8601 dengan milidetik dan 'Z'
	 */
	private static formatIso(date: Date): string {
		const year = date.getUTCFullYear();
		const month = String(date.getUTCMonth() + 1).padStart(2, "0");
		const day = String(date.getUTCDate()).padStart(2, "0");
		const hours = String(date.getUTCHours()).padStart(2, "0");
		const minutes = String(date.getUTCMinutes()).padStart(2, "0");
		const seconds = String(date.getUTCSeconds()).padStart(2, "0");
		const ms = String(date.getUTCMilliseconds()).padStart(3, "0");
		return `${year}-${month}-${day}T${hours}:${minutes}:${seconds}.${ms}Z`;
	}
}

export interface HistoryObject extends FileObject {}

/**
 * ---------
 * DhHistory
 * ---------
 */
// export class DhHistory {

//   protected dhFile: DhFile;

//   constructor(dhFile: DhFile) {
//     this.dhFile = dhFile;
//   }

//   async getId() {
//     const { userEmail } = await useAuthData();
//     const userId = encryptionHash(userEmail);
//     return (await deriveFileIdBin(await this.dhFile.getBlob().getFile(), userId.toString())).str;
//   }

//   async toObject() {
//     return []
//     // const id = await this.getId();
//     // const obj = {} as Record<string, FileObject[]>
//     // obj[id] = [await (new DhFile(this.fileParam)).toObject()]; // hanya berisi satu history

//   }
// }

export interface ManifestObject {
	source: string;
	version: string;
	total_files: number;
	total_size_bytes: number; // file asli
	hash_tree_sha256: string; //  diambil dari prop["files"]
	files: FileObject[];
	// "histories": Record<string, FileObject[]> // string adalah $id file, bisa dapat dari deriveFileId()
}

/**
 * ----------
 * DhManifest
 * ----------
 *  {
 *   "source": "...",
 *   "version": "...",
 *   "total_files": 128,
 *   "total_size_bytes": 4567890, // file asli
 *   "hash_tree_sha256": "...", //  diambil dari prop["files"]
 *   // files adalah semua file yang aktiv. Jika ada perubahan blob atau path maka yang lama dikeluarkan->dipindahkan ke modified_files
 *   "files": [
 *      {
 *        "path": "config/app.php",
 *        "sha256": "a1b2c3...",
 *        "size": 2048,
 *        "file_modified_at": "2025-11-20T14:00:00Z", // mtime dari file asli sebelum jadi blob
 *        "message?":"..."
 *      }
 *   ],
 *   // history adalah semua file yang tidak terpakai, di sort berdasarkan id file database.
 *   // berbeda dengan files yang tidak ada id file karena itu adalah semua file yang sedang aktif
 *   // setiap file di prop["files"] tidak ada di history
 *   // ada kemungkinan setiap active file akan di rollback ke history sebelumnya (sesuai index history).
 *   // jika synronizing maka ada kemungkinan "$id" berbeda jika pakai id number/incremented. Jadi solusinya pakai uuid
 *   // sepertinya history tidak dipakai
 *   "histories": {
 *     "$id": [
 *       {
 *         "path" : "...",
 *         "sha256": "...",
 *         "size": "...",
 *         "file_modified_at": "...",
 *         "message?": "..."
 *       }
 *     ]
 *   }
 * }
 */
export class DhManifest {
	protected source: string;
	protected version: string;
	protected totalFiles: number = 0;
	protected totalSizeBytes: number = 0;
	protected files: Set<DhFile> = new Set();

	// protected histories: DhHistory[] = [];

	constructor(source: string, version: string) {
		this.source = source;
		this.version = version;
		// this.files = files.map(f => new DhFile(f));
	}

	async setFiles(folder: DhFolderParam) {
		const { files } = await this.ifDir(folder);
		this.files = files;
	}
	async getFiles(folder: DhFolderParam | null = null) {
		if (this.files.size < 1 && folder) {
			this.setFiles(folder);
		}
		return this.files;
	}

	async getHistories(): Promise<Record<string, FileObject[]>> {
		const record: Record<string, FileObject[]> = {};
		// sementara history kosong dulu. TBD
		return record;
	}

	// input harus [{},{}] dalam bentuk string
	hash(source: string | FileObject[]): string {
		if (Array.isArray(source)) {
			return encryptionHash(JSON.stringify(source));
		} else {
			return encryptionHash(source);
		}
	}

	private async ifDir(
		dhFolderParam: DhFolderParam,
		files: Set<DhFile> = new Set()
	): Promise<{ files: Set<DhFile> }> {
		await scanDirectory(
			dhFolderParam,
			dhFolderParam.name,
			async (entry, relativePath) => {
				if (entry.kind === "file") {
					(entry as DhFileParam).relativePath = relativePath;
					files.add(new DhFile(entry));
				} else if (entry.kind === "directory") {
					(entry as DhFolderParam).relativePath = relativePath;
					const ifIsDir = await this.ifDir(entry, files);
					files = new Set([...files, ...ifIsDir.files]);
				}
			}
		);
		return {
			files,
		};
	}

	/**
	 * Versi hemat RAM: Mengurutkan array asli tanpa membuat salinan baru.
	 */
	sortFileDataInPlace(
		data: FileObject[],
		sortBy: keyof FileObject,
		order: "asc" | "desc" = "asc"
	): FileObject[] {
		const isAsc = order === "asc";

		return data.sort((a, b) => {
			const valA = a[sortBy] || 0;
			const valB = b[sortBy] || 0;

			if(valA === valB) return 0;

			// Pembanding standar lebih cepat & hemat memori daripada localeCompare
			const comparison = valA < valB ? -1 : 1;

			return isAsc ? comparison : -comparison;
		});
	}

	// harusnya sama dengan Workspace\Manifest.php
	async toObject(folder: DhFolderParam): Promise<ManifestObject> {
		if (this.files.size < 1) await this.setFiles(folder);
		let dhFileObjects: FileObject[] = [];
		for (const file of this.files) {
			dhFileObjects.push(await file.toObject());
		}
    dhFileObjects = this.sortFileDataInPlace(dhFileObjects, "size_bytes");
		return {
			source: this.source,
			version: this.version,
			total_files: this.totalFiles,
			total_size_bytes: this.totalSizeBytes,
			hash_tree_sha256: this.hash(dhFileObjects),
			files: dhFileObjects,
			histories: await this.getHistories(),
		} as ManifestObject;
	}
}
