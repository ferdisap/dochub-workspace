<script lang="ts" setup>
	import { ref, Ref } from "vue";
	import SearchableDropdown from "./../components/Dropdown/SearchableDropdown.vue";
	import { encryptAndSaveFile } from "./ferdi-full-encryption";
	import { fetchPublicKey, getPrivateKey, getPublicKey } from "./keys/key";
	import { decryptAndSaveFile } from "./ferdi-decryption";

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
				`/dochub/encryption/get/users?q_mail=${query}`,
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
			<p>Only targetted user can decrypt the file</p>
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

<style scoped>
	.ecrypt-zone {
		border-radius: 8px;
		padding: 3rem 0 0 0;
		text-align: center;
		cursor: pointer;
		transition: all 0.3s ease;
		background: #fafafa;
	}

	.ecrypt-zone .info {
		margin: 0.5rem 0;
		color: #495057;
		width: 100%;
	}

	.dropdown-container {
		display: flex;
		justify-content: center;
		margin-bottom: 0.5rem;
	}

	.encrypt-manager .ecrypt-zone .receipent-user {
		margin: 0.5rem 0 0.5rem;
		color: #495057;
		width: 100%;
		text-align: left;
	}

	.encrypt-btn,
	.decrypt-btn {
		padding: 8px 16px;
		font-size: 0.875rem;
		border: none;
		border-radius: 6px;
		cursor: pointer;
		color: #fff;
		font-weight: 600;
		margin-left: 0.75rem;
		transition:
			background 0.25s ease,
			box-shadow 0.25s ease;
	}

	/* 🔐 Encryption button (blue-ish) */
	.encrypt-btn {
		background: #0d6efd;
		box-shadow: 0 2px 4px rgba(13, 110, 253, 0.2);
	}

	.encrypt-btn:hover {
		background: #0b5ed7;
		box-shadow: 0 3px 6px rgba(13, 110, 253, 0.35);
	}

	/* 🔓 Decryption button (orange-ish) */
	.decrypt-btn {
		background: #fd7e14;
		box-shadow: 0 2px 4px rgba(253, 126, 20, 0.2);
	}

	.decrypt-btn:hover {
		background: #e96c0f;
		box-shadow: 0 3px 6px rgba(253, 126, 20, 0.35);
	}

	/* Disabled state (opsional) */
	.encrypt-btn:disabled,
	.decrypt-btn:disabled {
		opacity: 0.6;
		cursor: not-allowed;
		box-shadow: none;
	}
</style>
