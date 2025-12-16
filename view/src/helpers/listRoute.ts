/**
 * ----------------
 * ENCRYPTION ROUTE
 * ----------------
 */
export function route_encryption_search_user(query: string|null = null) {
  return `/dochub/encryption/get/users` + (query ? `?q_mail=${query}` : ''); // GET
}
export function route_encryption_get_user() {
  return `/dochub/encryption/get/user`; // GET
}
export function route_encryption_download_file(fileId: string) {
  return `/dochub/encryption/download-file/${fileId}`;
}
export function route_encryption_upload_chunk() {
  return '/dochub/encryption/upload-chunk';
}
export function route_encryption_upload_start() {
  return '/dochub/encryption/upload-start';
}
export function route_encryption_upload_process() {
  return '/dochub/encryption/upload-process';
}
export function route_encryption_register_publicKey() {
  return '/dochub/encryption/register/public-key';
}
export function route_encryption_get_publicKey(email:string | null){
  return '/dochub/encryption/get/public-key' + (email ? `?q_mail=${email}` : ''); // GET
}

/**
 * -------------
 * UPLOAD ROUTE
 * -------------
 */
export function route_upload_get_config(){
  return '/dochub/upload/config'; // GET
}
export function route_upload_check_chunk(){
  return '/dochub/upload/chunk/check'; // GET
}
export function route_upload_per_chunk(){
  return '/dochub/upload/chunk'; // POST
}
export function route_upload_process_chunk(){
  return '/dochub/upload/process'; // POST
}
export function route_upload_status_process(uploadId: string){
  return `/dochub/upload/${uploadId}/status`; // GET
}
export function route_upload_get_list(){
  return '/dochub/upload/list'; // POST
}
export function route_upload_make_workspace(manifestId:string){
  return `/dochub/upload/make/workspace/${manifestId}`; // POST
}
export function route_upload_make_workspace_status(manifestId:string){
  return `/dochub/upload/make/workspace/status/${manifestId}`; // GET
}

/**
 * -----------
 * FILE ROUTE
 * -----------
 */
export function route_file_download(hash:string){
  return `/dochub/file/download/${hash}`; // GET
}
export function route_file_delete(hash:string, manifestId:string){
  // return `/dochub/file/delete/${hash}/${manifestId}`; // POST
  return `/dochub/file/delete/${manifestId}/${hash}`; // POST
}