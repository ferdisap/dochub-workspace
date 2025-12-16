<script lang="ts" setup>
	import { ref, Ref } from "vue";
	import SearchableDropdown from "./../components/Dropdown/SearchableDropdown.vue";
	import { encryptAndSaveFile } from "./ferdi-full-encryption";
	import { fetchPublicKey, getPrivateKey, getPublicKey } from "./keys/key";
	import { decryptAndSaveFile } from "./ferdi-decryption";
import { route_encryption_search_user } from "../helpers/listRoute";

	interface SearchUser {
		email: number;
		pubKey: string; // base64
	}

	interface Props {
		files?: File[] | FileList | null;
	}

	const props = defineProps<Props>();
	const emit = defineEmits([
		"successEncrypted",
		"failEncrypted",
		"successDecrypted",
		"failDecrypted",
    "error"
	]);

	/** key is email, value is pubKey */
	const receipentsSearchResult = ref<SearchUser[]>([]);
	const receipentsPubKey: Ref<SearchUser[]> = ref([]);
	/** key is email, value is pubKey */

	function onSelect(user: SearchUser) {
		if (!receipentsPubKey.value.find((selectedUser) => selectedUser.email)) {
			receipentsPubKey.value.push(user);
		}
	}

	let to = 0;
	const delay = (action: () => any) => {
		clearTimeout(to);
		to = setTimeout(action, 500);
	};
	async function onSearch(query: string) {
		delay(async () => {
			const response = await fetch(
				route_encryption_search_user(query),
				{
					method: "GET",
					headers: {
						"Content-Type": "application/json",
						"X-Requested-With": "XMLHttpRequest",
					},
				}
			);
			if (response.ok) {
				const data = await response.json();
				for (const user of data.users) {
					if (
						!receipentsSearchResult.value.find(
							(rec) => rec.email === user.email
						)
					) {
						receipentsSearchResult.value.unshift({
							email: user.email,
							pubKey: user.encryption_key.public_key,
						});
					}
				}
				while (receipentsSearchResult.value.length > 4) {
					receipentsSearchResult.value.pop();
				}
			} else {
        emit("error", "Failed to fetch public key");
      }
		});
	}

	const authEmail = () => {
		const meta = document.querySelector('meta[name="user-email"]');
		return meta ? meta.getAttribute("content") || "" : "";
	};
	async function encrypt() {
		if (!props.files || props.files.length < 1) {
      emit('error', "File must exist")
      return;
    };
    if(receipentsPubKey.value.length < 1){
      emit('error', "Receipent or reader must exist")
      return;
    }
    const failedEncrypted = <{name:string}[]>[]
		for (const file of props.files!) {
			const ownerEmail = authEmail();
			const ownerPubKey = await getPublicKey(ownerEmail);
			const ownerPrivateKey = await getPrivateKey();
			const receipents: Record<string, string> = {};
			for (const rp of receipentsPubKey.value) {
				receipents[rp.email] = rp.pubKey;
			}
      try {
        encryptAndSaveFile(
          file,
          receipents,
          ownerPrivateKey,
          ownerPubKey,
          ownerEmail
        );
      } catch(err) {
        failedEncrypted.push({"name": file.name})
        continue;
      }
		}
    if(failedEncrypted.length > 0){
      emit("failEncrypted", failedEncrypted)
    } else {
      emit("successEncrypted");
    }
	}

	async function decrypt() {
		if (!props.files || props.files.length < 1) {
      emit('error', "File must exist")
      return;
    };
    const failedDecrypted = <{name:string}[]>[]
		for (const file of props.files!) {
			const readerEmail = authEmail();
			// const readerPubKey = await getPublicKey(readerEmail);
			const readerPrivateKey = await getPrivateKey();
      try {
        decryptAndSaveFile(file, readerPrivateKey, readerEmail);
      } catch (err) {
        failedDecrypted.push({"name": file.name})
        continue;
      }
		}
    if(failedDecrypted.length > 0){
      emit("failDecrypted", failedDecrypted)
    } else {
      console.log('success decryption');
      emit("successDecrypted");
    }
	}
</script>

<template>
	<div class="ecrypt-zone">
		<!-- receipent zone-->
		<div class="info">
			<p>E2EE encryption file</p>
			<p class="hint">Only targetted user can decrypt the file</p>
      <p class="hint">If you dont have the key, register <a href="/dochub/encryption/register/public-key" class="underline text-blue-500">here</a></p>
		</div>
		<div class="receipents">
			<div class="dropdown-container">
				<SearchableDropdown
					:options="receipentsSearchResult"
					item-label="email"
					:clearable="true"
					placeholder="Pilih target user email.."
					value-key="email"
					@select="onSelect"
					@search="onSearch"
				/>
			</div>

			<div class="receipent-user" v-if="receipentsPubKey.length">
				<span v-for="receipent in receipentsPubKey" :key="receipent.email"
					>&nbsp;{{ receipent.email }},</span
				>
			</div>
		</div>
		<!-- action -->
		<div class="action-btn-wrapper">
			<button class="encrypt-btn" title="encrypt" @click.stop="encrypt">
				🔐 Encrypt
			</button>
			<button class="decrypt-btn" title="decrypt" @click.stop="decrypt">
				🔓 Decrypt
			</button>
		</div>
	</div>
</template>
