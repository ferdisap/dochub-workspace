<script lang="ts" setup>
import { ref, Ref } from "vue";
import SearchableDropdown from "./../components/Dropdown/SearchableDropdown.vue";

interface SearchUser {
    email: number;
    pubKey: string;
}

/** key is email, value is pubKey */
const receipentsSearchResult = ref<SearchUser[]>([
    { email: 1, pubKey: "Apple" },
    { email: 2, pubKey: "Banana" },
    { email: 3, pubKey: "Cherry" },
    { email: 4, pubKey: "Durian" },
]);
const receipentsPubKey: Ref<SearchUser[]> = ref([]);
/** key is email, value is pubKey */

function onSelect(user: SearchUser) {
    if (!receipentsPubKey.value.find((selectedUser) => selectedUser.email)) {
        receipentsPubKey.value.push(user);
    }
}

function onSearch(query: string) {
    console.log(query);
}
</script>

<template>
    <div class="encrypt-manager">
      <div class="ecrypt-zone">
          <!-- receipent zone-->
          <div class="receipents">
              <div class="dropdown-container">
                <SearchableDropdown
                    :options="receipentsSearchResult"
                    item-label="pubKey"
                    :clearable="true"
                    placeholder="Pilih target user.."
                    value-key="email"
                    @select="onSelect"
                    @search="onSearch"
                />
              </div>
  
              <div class="receipent-user" v-if="receipentsPubKey.length">
                <span v-for="receipent in receipentsPubKey" :key="receipent.email">&nbsp;{{ receipent.email }},</span>
              </div>
          </div>
          <!-- action -->
          <div class="action-btn-wrapper">
              <button class="encrypt-btn" title="encrypt">🔐 Encrypt</button>
              <button class="decrypt-btn" title="decrypt">🔓 Decrypt</button>
          </div>
        </div>
    </div>
</template>

<style scoped>
.encrypt-manager {
    max-width: 600px;
    margin: 2rem auto;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}

.ecrypt-zone {
  border: 2px dashed #ccc;
	border-radius: 8px;
	padding: 3rem 2rem;
	text-align: center;
	cursor: pointer;
	transition: all 0.3s ease;
	background: #fafafa;
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
  transition: background 0.25s ease, box-shadow 0.25s ease;
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
