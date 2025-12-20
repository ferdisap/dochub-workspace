import { getUserEmail } from "../../helpers/toDom";

let USER_ID = 0;
let USER_EMAIL = '';

export type AuthDataComposable = {
  "userId": string | number,
  "userEmail": string,
} 

export async function useAuthData() :Promise<AuthDataComposable> {
  return {
    "userId": USER_ID,
    "userEmail": getUserEmail(),
  }
}