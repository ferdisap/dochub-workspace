import { FileNode, makeFileNode } from "../workspace/analyze/folderUtils";
import { DhFolderParam } from "../workspace/core/DhFile";
import { ManifestObject } from "../workspace/core/DhManifest";

type WorkerType = "makeFileNode" | "createManifest";

export interface WorkerMakeFileNodePayload {
  dirHandle: DhFolderParam,
  currentPath: string,
}
export type WorkerMakeFileNodeResult = FileNode[]
export interface WorkerCreateManifestPayload {}
export type WorkerCreateManifestResult = ManifestObject
export interface WorkerAnalyzePayload {
  type: WorkerType;
  payload: WorkerMakeFileNodePayload | WorkerCreateManifestPayload;
}

export interface WorkerAnalyzeResult {
  type: WorkerType;
  result: WorkerMakeFileNodeResult | WorkerCreateManifestResult;
}

globalThis.onmessage = async (e: MessageEvent<WorkerAnalyzePayload>) => {
  switch (e.data.type) {
    case "makeFileNode":
      const result = await makeFileNode((e.data.payload as WorkerMakeFileNodePayload).dirHandle, (e.data.payload as WorkerMakeFileNodePayload).currentPath);
      globalThis.postMessage({
        type: e.data.type,
        result
      })
      break;
    case "createManifest": 
      break;  
    default:
      break;
  }
}