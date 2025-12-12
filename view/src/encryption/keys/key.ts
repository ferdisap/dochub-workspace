import { route_encryption_get_publicKey } from "../../helpers/listRoute";
import { base64ToBytes, bytesToBase64 } from "../ferdi-encryption";
import { readLocal } from "./localStoreKey";

export async function fetchPublicKey(email: string | null = null) {
  const endPoint = route_encryption_get_publicKey(email);
  const response = await fetch(endPoint, {
    method: "GET",
    headers: {
      "Content-Type": "application/json",
      "X-Requested-With": "XMLHttpRequest",
    },
  });
  return response
}

export async function getPublicKey(email: string | null = null) {
  const responseFetch = await fetchPublicKey(email);
  if (!responseFetch) {
    throw new Error(`Cannot fetch public key ${email}`);
  }
  const responseData = await responseFetch.json();
  const publicKeyBase64 = responseData.key.public_key;
  return base64ToBytes(publicKeyBase64);
}

export async function getPrivateKey() {
  const pKey = await readLocal();
  return pKey;
}