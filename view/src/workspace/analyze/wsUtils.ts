import { ManifestObject } from "../core/DhManifest";

export interface TreeMergeNode {
  id: string;
  label: string;
  merged_at: string; // ISO 8601
  message?: string | null;
  children: TreeMergeNode[];
}

export interface ManifestModel {
  content: ManifestObject,
  created_at: string,
  updated_at: string,
}

export interface WorkspaceNode {
  name: string,
  visibility: string,
  created_at: string,
  updated_at: string,
  manifests: ManifestModel[]
}

export interface ListValue {
  id: string;
  text: string;
  value: any;
}