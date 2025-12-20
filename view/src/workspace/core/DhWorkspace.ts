import { hash } from "../../encryption/ferdi-encryption";
import { scanDirectory } from "../analyze/folderUtils";
import { useAuthData } from "../utils/auth";
import { DhFolderParam } from "./DhFile";
import { DhManifest, ManifestObject, ManifestSourceParser, ManifestSourceType, ManifestVersionParser } from "./DhManifest";

interface DhWorkspaceParam extends DhFolderParam { }

export class DhWorkspace {

  protected dhWorkspaceParam: DhWorkspaceParam;
  protected manifest:ManifestObject | null = null;

  constructor(dhWorkspaceParam: DhWorkspaceParam) {
    this.dhWorkspaceParam = dhWorkspaceParam;
  }

  private async setManifest()
  {
    const { userEmail } = await useAuthData();
    const userId = hash(userEmail);
    const source = ManifestSourceParser.makeSource(ManifestSourceType.UPLOAD, userId.toString());
    const version = ManifestVersionParser.makeVersion();
    const dhManifest = new DhManifest(source, version);
    this.manifest = await dhManifest.toObject(this.dhWorkspaceParam);
  }

  async getManifest() :Promise<ManifestObject>{
    if(!this.manifest) await this.setManifest();
    return this.manifest!;
  }

  
}