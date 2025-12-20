export function getCSRFToken(): string {
  const meta = document.querySelector('meta[name="csrf-token"]');
  return meta ? meta.getAttribute("content") || "" : "";
}
export function getUserEmail(): string {
  const meta = document.querySelector('meta[name="user-email"]');
  return meta ? meta.getAttribute("content") || "" : "";
}