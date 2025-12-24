<script setup lang="ts">
	import { ref, onMounted, onUnmounted, nextTick, watch } from "vue";

	const props = withDefaults(
		defineProps<{
			modelValue?: boolean; // v-model:visible
			title?: string;
			message?: string;
			placeholder?: string;
			okText?: string;
			cancelText?: string;
			type?: "text" | "textarea" | "password" | "number";
			defaultValue?: string;
			required?: boolean;
			autofocus?: boolean;
		}>(),
		{
			title: "Prompt",
			message: "",
			placeholder: "Masukkan nilai...",
			okText: "OK",
			cancelText: "Batal",
			type: "text",
			defaultValue: "",
			required: false,
			autofocus: true,
			modelValue: false,
		}
	);

	const emit = defineEmits<{
		(e: "update:modelValue", value: boolean): void;
		(e: "result", value: string | null): void;
		(e: "close"): void;
		(e: "cancel"): void;
	}>();

	const inputRef = ref<HTMLInputElement | HTMLTextAreaElement | null>(null);
	const inputValue = ref(props.defaultValue ? props.defaultValue : null);
	const isSubmitting = ref(false);

	// Sinkronisasi modelValue → internal visible
	const visible = ref(props.modelValue);
	watch(
		() => props.modelValue,
		(val) => {
			visible.value = val;
		}
	);

	// Jaga sinkronisasi balik ke parent
	watch(visible, (val) => {
		emit("update:modelValue", val);
		if (!val) emit("close");
	});

	// Focus otomatis saat terbuka
	onMounted(() => {
		if (props.autofocus) {
			watch(visible, async (isOpen) => {
				if (isOpen) {
					await nextTick();
					inputRef.value?.focus();
				}
			});
		}
	});

	// Tutup dengan ESC
	const handleKeydown = (e: KeyboardEvent) => {
		if (e.key === "Escape" && visible.value) {
			close();
		}
	};

	onMounted(() => document.addEventListener("keydown", handleKeydown));
	onUnmounted(() => document.removeEventListener("keydown", handleKeydown));

	// Tutup modal
	const close = () => {
		visible.value = false;
	};

	// Kirim hasil & tutup
	const submit = async () => {
		if (props.required && (inputValue.value && !inputValue.value.trim())) {
			inputRef.value?.focus();
			return;
		}

		isSubmitting.value = true;
		try {
			emit("result", inputValue.value);
			close();
		} finally {
			isSubmitting.value = false;
		}
	};

	// Cancel → kirim null
	const cancel = () => {
    emit("cancel");
		close();
	};
</script>

<template>
	<Teleport to="body">
		<!-- Backdrop transparan + blur -->
		<Transition name="fade">
			<div
				v-if="visible"
				class="fixed inset-0 z-50 flex items-center justify-center p-4"
				@click.self="cancel"
			>
				<!-- Backdrop blur -->
				<div
					class="absolute inset-0 bg-black/20 backdrop-blur-sm"
					aria-hidden="true"
				></div>

				<!-- Modal -->
				<Transition name="scale">
					<div
						v-if="visible"
						class="relative w-full max-w-md bg-white dark:bg-slate-800 rounded-xl shadow-xl border border-slate-200 dark:border-slate-700 overflow-hidden z-10"
						role="dialog"
						aria-modal="true"
						:aria-labelledby="`prompt-title-${Date.now()}`"
					>
						<!-- Header -->
						<div
							class="px-6 py-4 border-b border-slate-200 dark:border-slate-700"
						>
							<h2
								:id="`prompt-title-${Date.now()}`"
								class="text-lg font-semibold text-slate-800 dark:text-slate-100"
							>
								{{ title }}
							</h2>
							<p
								v-if="message"
								class="mt-1 text-sm text-slate-600 dark:text-slate-400"
							>
								{{ message }}
							</p>
						</div>

						<!-- Body -->
						<div class="px-6 py-4">
							<div class="space-y-3">
								<label
									v-if="type !== 'textarea'"
									:for="`prompt-input-${Date.now()}`"
									class="sr-only"
									>{{ placeholder }}</label
								>

								<input
									v-if="type !== 'textarea'"
									:id="`prompt-input-${Date.now()}`"
									ref="inputRef"
									v-model="inputValue"
									:type="type"
									:placeholder="placeholder"
									:required="required"
									class="w-full px-3 py-2.5 border border-slate-300 dark:border-slate-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-slate-700 dark:text-white placeholder-slate-400"
									@keyup.enter="submit"
								/>

								<textarea
									v-else
									:id="`prompt-input-${Date.now()}`"
									ref="inputRef"
									v-model="inputValue"
									:placeholder="placeholder"
									:required="required"
									rows="3"
									class="w-full px-3 py-2.5 border border-slate-300 dark:border-slate-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-slate-700 dark:text-white placeholder-slate-400 resize-none"
									@keyup.enter.ctrl="submit"
								/>
							</div>
						</div>

						<!-- Footer -->
						<div
							class="px-6 py-4 bg-slate-50 dark:bg-slate-700/50 border-t border-slate-200 dark:border-slate-700 flex justify-end gap-3"
						>
							<button
								type="button"
								@click="cancel"
								class="px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-600 rounded-lg transition"
							>
								{{ cancelText }}
							</button>
							<button
								type="button"
								@click="submit"
								:disabled="isSubmitting || (required && Boolean(inputValue && !inputValue.trim()))"
								class="px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-slate-800 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed transition"
							>
								<span v-if="isSubmitting" class="flex items-center">
									<svg
										class="animate-spin -ml-1 mr-2 h-4 w-4 text-white"
										fill="none"
										viewBox="0 0 24 24"
									>
										<circle
											class="opacity-25"
											cx="12"
											cy="12"
											r="10"
											stroke="currentColor"
											stroke-width="4"
										></circle>
										<path
											class="opacity-75"
											fill="currentColor"
											d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
										></path>
									</svg>
									Memproses...
								</span>
								<span v-else>{{ okText }}</span>
							</button>
						</div>
					</div>
				</Transition>
			</div>
		</Transition>
	</Teleport>
</template>

<style scoped>
	.fade-enter-active,
	.fade-leave-active {
		transition: all 0.2s ease;
	}
	.fade-enter-from,
	.fade-leave-to {
		opacity: 0;
	}

	.scale-enter-active,
	.scale-leave-active {
		transition: all 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
	}
	.scale-enter-from,
	.scale-leave-to {
		opacity: 0;
		transform: scale(0.95);
	}
</style>


<!-- cara pakai -->
<!-- <template>
  <div>
    <button 
      @click="showPrompt = true"
      class="px-4 py-2 bg-blue-600 text-white rounded-lg"
    >
      Buka Prompt
    </button>

    <ModalPrompt
      v-model="showPrompt"
      title="Nama Workspace"
      message="Masukkan nama untuk workspace baru:"
      placeholder="Contoh: Projek Q3"
      ok-text="Buat"
      cancel-text="Batal"
      @result="onPromptResult"
    />
  </div>
</template>

<script setup lang="ts">
  import { ref } from 'vue';
  import ModalPrompt from './ModalPrompt.vue';

  const showPrompt = ref(false);
  const showGeneralPrompt = ref(false);
  
  showPrompt.value = true; // opening prompt
  const querySearchManifest = await onPromptOpen(); //waiting result

  const promptResult = {
    resolve: (v:string) => {},
  }
  async function onPromptOpen():Promise<string>{
    return new Promise((resolve) => {
      promptResult.resolve = resolve;
    })  
  }
  async function onPromptResult(value:string | null){
    promptResult.resolve(value ?? '');
  }
</script> -->