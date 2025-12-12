<!-- resources/js/components/RegisterKeys.vue -->
<script setup lang="ts">
	import { ref } from "vue";
	import {
		base64ToBytes,
		bytesToBase64,
		deriveX25519KeyPair,
	} from "../ferdi-encryption";
	import { clearDB, erase, eraseKEK, readLocal, storeLocal } from "./localStoreKey";
  import { fetchPublicKey, getPrivateKey } from "./key";
import { getCSRFToken } from "view/src/helpers/toDom";
import { route_encryption_register_publicKey, route_encryption_search_user } from "view/src/helpers/listRoute";

	const userId = ref(null);
	const passphrase = ref<string>("");
	const passphraseConfirmation = ref<string>("");
	const publicKey = ref<string>(""); // base64
	const privateKey = ref<string>(""); // base64
	const loading = ref<boolean>(false);
	const error = ref<string>("");
	const success = ref<string>("");

	async function generateKeys() {
    // eraseKEK(); // cukup menghapus KEK saja
    // erase();
    // clearDB();
    // console.log(await readLocalPrivateKey());
    // console.log(await readServerPublicKey());
    // return;
		error.value = "";
		success.value = "";

		try {
			await readLocalPrivateKey();
      await readServerPublicKey();
			error.value = "You have generated the key";
			loading.value = false;
      return;
		} catch (e) {
			if (!userId.value) {
				try {
					await fetch(route_encryption_search_user()).then(async (response) => {
						const data = await response.json();
						userId.value = data.user.email;
					});
				} catch (e) {
					error.value = "User must logged in";
					throw e;
				}
			}
			if (!passphrase.value || !passphraseConfirmation.value) {
				error.value = "Passphrase is required";
				return;
			}

			if (passphraseConfirmation.value !== passphrase.value) {
				error.value = "Passphrase must confirmed";
				return;
			}

			loading.value = true;
			try {
				const { privateKey: pvKey, publicKey: pbKey } =
					await deriveX25519KeyPair(passphrase.value, userId.value!);
				privateKey.value = bytesToBase64(pvKey);
				publicKey.value = bytesToBase64(pbKey);
				console.log(privateKey.value);
				console.log(publicKey.value);
				success.value = "Keys generated successfully!";
			} catch (e) {
				error.value = "Failed to generate keys";
			}
			loading.value = false;
		}
	}

	async function storeKeys() {
    loading.value = true;
		await storeServerPublicKey();
		storeLocalPrivateKey();
    loading.value = false;
		success.value = "Public and Private key is stored.";
	}

	async function storeServerPublicKey() {
		success.value = "";
		loading.value = true;
		const response = await fetch(route_encryption_register_publicKey(), {
			method: "POST",
			headers: {
				"Content-Type": "application/json",
				"X-Requested-With": "XMLHttpRequest",
				"X-CSRF-TOKEN": getCSRFToken(),
			},
			body: JSON.stringify({ public_key: publicKey.value }),
		});
		loading.value = false;
		success.value = "Public key stored to server.";
		return response.ok ? true : false;
	}

	async function readServerPublicKey(): Promise<string | undefined> {
		success.value = "";
		loading.value = true;
		const response = await fetchPublicKey();
		let pbKey64 = undefined;
		if (response.ok) {
			const data = await response.json();
			const publicKeyBase64 = data.key.public_key;
			publicKey.value = publicKeyBase64;
			pbKey64 = publicKeyBase64;
			success.value = `Your public key is ${publicKeyBase64}`;
		} else {
			publicKey.value = "";
			success.value = "Fail to load public key";
		}
		loading.value = false;
		return pbKey64;
	}

	function storeLocalPrivateKey() {
		if (privateKey.value) {
			const privateKeyBin = base64ToBytes(privateKey.value);
			storeLocal(privateKeyBin);
		}
	}

	async function readLocalPrivateKey() {
		const pKey = await getPrivateKey();
		privateKey.value = bytesToBase64(pKey);
		return pKey;
	}
</script>

<template>
	<div class="create-key-wrapper">
		<div class="create-key-manager">
			<div class="card-header">
				<div class="title">Register Encryption Keys</div>
			</div>
			<!-- passphrase -->
			<div class="mt-6">
				<label class="block font-semibold mb-1">Passphrase</label>
				<input
					v-model="passphrase"
					type="password"
					class="w-full border rounded p-2"
					placeholder="Enter passphrase"
				/>
				<input
					v-model="passphraseConfirmation"
					type="password"
					class="w-full border rounded p-2 mt-1"
					placeholder="Enter passphrase confirmation"
				/>
			</div>
			<div class="flex justify-center">
				<button
					@click="generateKeys"
					:disabled="loading"
					class="mr-2 mt-4 bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700"
				>
					{{ loading ? "Generating..." : "Generate Keys" }}
				</button>
				<button
					@click="storeKeys"
					:disabled="loading"
					class="mr-2 mt-4 bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700"
				>
					{{ loading ? "Saving..." : "Save Keys" }}
				</button>
			</div>
			<!-- public key -->
			<div class="mt-6">
				<h3 class="font-semibold">Public Key</h3>
				<p class="break-all">{{ publicKey ? publicKey : "-" }}</p>
			</div>
			<div class="flex justify-center">
				<button
					@click="readServerPublicKey"
					:disabled="loading"
					class="mr-2 mt-4 bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700"
				>
					{{ loading ? "Loading..." : "Load Public Key" }}
				</button>
			</div>
			<!-- private key -->
			<div class="mt-6">
				<h3 class="font-semibold">Private Key (stored in browser)</h3>
				<p class="break-all">{{ privateKey ? privateKey : "-" }}</p>
			</div>
			<div class="flex justify-center">
				<button
					@click="readLocalPrivateKey()"
					:disabled="loading"
					class="mr-2 mt-4 bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700"
				>
					{{ loading ? "Loading..." : "Load Private Key" }}
				</button>
			</div>

			<p v-if="error" class="result failed mt-4 p-2">{{ error }}</p>
			<p v-if="success" class="result completed mt-4 p-2">{{ success }}</p>
		</div>
	</div>
</template>
