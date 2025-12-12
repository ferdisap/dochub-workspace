<?php

namespace Dochub\Upload\Models\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class Upload extends JsonResource
{
  /**
   * Transform the resource into an array.
   *
   * @return array<string, mixed>
   */
  public function toArray(Request $request): array
  {
    // return parent::toArray($request);
    // if ($this->resource->owner) {
    //   $this->resource->owner->makeHidden(["owner_id"]);
    // }
    // return [
    //   'id' => $this->id,
    //   'path' => $this->relative_path,
    //   'created_at' => $this->created_at,
    // ];
    // $this adalah File Model
    // return [
    //   'hash' => $this->blob_hash,
    //   'path' => $this->relative_path,
    //   'created_at' => $this->created_at,
    // ];

    
    // $this adalah Manifest Model
    $manifestArray = $this->content; // getContentAttribute
    return [
      'hash_manifest' => $this->hash_tree_sha256,
      'hash_blob' => $manifestArray->files[0]->sha256,
      'path' => $manifestArray->files[0]->relative_path,
      'created_at' => $this->created_at,
    ];
  }
}
