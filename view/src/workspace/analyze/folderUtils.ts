import { WorkerAnalyzePayload, WorkerAnalyzeResult, WorkerMakeFileNodePayload, WorkerMakeFileNodeResult } from "view/src/worker/analyze.worker";
import { DhFileParam, DhFolderParam } from "../core/DhFile";
import AnalyzeWorker from './../../worker/analyze.worker.ts?worker';

// Interface tetap sama
export interface FileNode {
  id: string;
  name: string;
  kind: 'file' | 'directory';
  handler: DhFileParam | DhFolderParam,
  children?: FileNode[];
  expanded?: boolean;
}

// Hitung total node (file + folder)
export function countNodes(node: FileNode): number {
  if (!node.children?.length) return 1;
  return 1 + node.children.reduce((sum, child) => sum + countNodes(child), 0);
}

export function countFiles(node: FileNode): number {
  if (node.kind === 'file') {
    return 1;
  }
  // folder: jumlahkan file di dalam children
  if (node.children?.length) {
    return node.children.reduce((sum, child) => sum + countFiles(child), 0);
  }
  return 0; // folder kosong → 0 file
}

// Scan direktori — reusable
// export async function scanDirectory(
//   dirHandle: FileSystemDirectoryHandle,
//   currentPath: string
// ): Promise<FileNode[]> {
//   const nodes: FileNode[] = [];
//   for await (const entry of dirHandle.values()) {
//     const entryPath = `${currentPath}/${entry.name}`;
//     if (entry.kind === 'file') {
//       nodes.push({
//         id: crypto.randomUUID(),
//         name: entry.name,
//         kind: 'file',
//         relativePath: entryPath,
//       });
//     } else if (entry.kind === 'directory') {
//       const children = await scanDirectory(entry, entryPath);
//       nodes.push({
//         id: crypto.randomUUID(),
//         name: entry.name,
//         kind: 'directory',
//         relativePath: entryPath,
//         children,
//         expanded: false,
//       });
//     }
//   }
//   return nodes;
// }

export async function scanDirectory(
  dirHandle: DhFolderParam,
  currentPath: string,
  onFound: (entry: FileSystemFileHandle | FileSystemDirectoryHandle, relativePath: string) => Promise<undefined>
): Promise<undefined> {
  for await (const entry of dirHandle.values()) {
    const entryPath = `${currentPath}/${entry.name}`;
    await onFound(entry, entryPath);
  }
}

export async function makeFileNode(
  dirHandle: DhFolderParam,
  currentPath: string,
): Promise<FileNode[]> {
  const nodes: FileNode[] = [];

  await scanDirectory(dirHandle, currentPath, async (entry, relativePath) => {
    try {
      if (entry.kind === 'file') {
        // Opsional: coba getFile() untuk validasi (tapi ini berat & tidak perlu untuk struktur saja)
        // const file = await entry.getFile(); ← hindari kalau hanya butuh nama/path
        const handler = entry as DhFileParam;
        handler.relativePath = relativePath;
        nodes.push({
          id: crypto.randomUUID(),
          name: entry.name,
          kind: 'file',
          handler,
        });
      } else if (entry.kind === 'directory') {
        const handler = entry as DhFolderParam;
        handler.relativePath = relativePath;
        let children = await makeFileNode(entry, relativePath);
        nodes.push({
          id: crypto.randomUUID(),
          name: entry.name,
          kind: 'directory',
          handler,
          children,
          expanded: false,
        });
      }
    } catch (err) {
      if ((err as DOMException).name === 'NotFoundError') {
        console.warn(`[SKIP] Item tidak ditemukan: ${relativePath}`, err);
        // Opsional: tambahkan placeholder error node
        dirHandle.relativePath = relativePath;
        nodes.push({
          id: crypto.randomUUID(),
          name: `${entry.name} (hilang)`,
          kind: 'file',
          handler: dirHandle,
        });
      }
      throw err; // error lain → bubble up
    }
  });
  return nodes;
}

export function makeFileNodeByWorker(
  dirHandle: DhFolderParam,
  currentPath: string,
): Promise<{ worker: Worker | null, result: FileNode }> {
  return new Promise(async (resolve, reject) => {
    let result: FileNode;
    try {
      const worker = new AnalyzeWorker();
      worker.postMessage({
        "type": "makeFileNode",
        "payload": {
          "dirHandle": dirHandle,
          "currentPath": currentPath,
        } as WorkerMakeFileNodePayload
      } as WorkerAnalyzePayload);
      worker.onmessage = async (ev: MessageEvent<WorkerAnalyzeResult>) => {
        const children = (ev.data.result as WorkerMakeFileNodeResult);
        dirHandle.relativePath = dirHandle.name;
        result = {
          id: "root",
          name: dirHandle.name,
          kind: "directory",
          handler: dirHandle,
          children,
          expanded: true,
        };
        resolve({
          worker: worker,
          result: result,
        })
      }
      worker.onerror = (e) => reject(e);
    } catch (err) {
      // manual 
      const children = await makeFileNode(dirHandle, currentPath);
      const result = {
        id: "root",
        name: dirHandle.name,
        kind: "directory",
        handler: dirHandle,
        children,
        expanded: true,
      } as FileNode;
      resolve({
        worker: null,
        result
      }) ;
    }
  })
}