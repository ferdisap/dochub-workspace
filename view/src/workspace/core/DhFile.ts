import { DhBlob } from "./DhBlob";

export interface DhFileParam extends FileSystemFileHandle {
  relativePath?:string
}

export interface DhFolderParam extends FileSystemDirectoryHandle {
  relativePath?:string
}

export interface FileObject {
  "relative_path": string,
  "sha256": string,
  "size_bytes": number,
  "file_modified_at": number,
  "message"?:string,
}

export class DhFile {

  protected fileSystem:DhFileParam;
  protected file:File | null = null;
  protected dhBlob :DhBlob | null = null;

  constructor(fileSystem:DhFileParam)
  {
    this.fileSystem = fileSystem;
  }

  getBlob(){
    return this.dhBlob ? this.dhBlob : (this.dhBlob = new DhBlob(this.fileSystem));
  }

  async getPath(){
    if(this.fileSystem.relativePath){
      return this.fileSystem.relativePath;
    } else {
      if(!this.dhBlob) this.getBlob();
      return (await this.dhBlob!.getFile()).webkitRelativePath
    }
  }

  // sama dengan php filemtime()
  async getMtime(){
    if(!this.dhBlob) this.getBlob();
    return ((await this.dhBlob!.getFile()).lastModified) / 1000;
  }

  async getSize(){
    if(!this.dhBlob) this.getBlob();
    return ((await this.dhBlob!.getFile()).size);
  }

  async toObject():Promise<FileObject>{
    if(!this.dhBlob) this.getBlob();
    const hash = await this.dhBlob!.resolveHash();
    const file = await this.dhBlob!.getFile();
    return {
      "relative_path": await this.getPath(),
      'sha256': hash,
      "size_bytes": file.size,
      "file_modified_at": await this.getMtime(),
    }
  }

  toJson(){
    return JSON.stringify(this.toObject());
  }
}