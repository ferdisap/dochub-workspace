<script setup lang="ts">
	import { ListValue } from "view/src/workspace/analyze/wsUtils";
import { ref, watch, nextTick } from "vue";

	const props = withDefaults(
		defineProps<{
			visibility?: boolean;
			title?: string;
			message?: string;
			okText?: string;
			cancelText?: string;
			list: ListValue[];
		}>(),
		{
			title: "List",
			message: "Select one of follow list:",
			okText: "OK",
			cancelText: "Batal",
		}
	);

	const emit = defineEmits<{
		(e: "update:visibility", value: boolean): void;
		(e: "select", value: any): void;
		(e: "close"): void;
		(e: "cancel"): void;
	}>();

	const selectedIndex = ref(0);
	const listContainer = ref<HTMLElement | null>(null);

	// Sinkronisasi internal visible
	const visible = ref(props.visibility);
	watch(
		() => props.visibility,
		(val) => {
			visible.value = val;
			if (val) {
				selectedIndex.value = 0; // Reset ke item pertama saat buka
				// Beri fokus ke kontainer agar keyboard event langsung tertangkap
				nextTick(() => {
					listContainer.value?.focus();
				});
			}
		}
	);

	watch(visible, (val) => {
		emit("update:visibility", val);
		if (!val) emit("close");
	});

	const close = () => {
		visible.value = false;
	};

	const submit = () => {
		const item = props.list[selectedIndex.value];
		if (item) {
			emit("select", item.value);
			close();
		}
	};

	const cancel = () => {
		emit("cancel");
		close();
	};

	// Logika Navigasi Keyboard (Infinite Loop)
	const handleKeydown = (e: KeyboardEvent) => {
		if (e.key === "ArrowDown") {
			e.preventDefault();
			selectedIndex.value = (selectedIndex.value + 1) % props.list.length;
		} else if (e.key === "ArrowUp") {
			e.preventDefault();
			selectedIndex.value =
				(selectedIndex.value - 1 + props.list.length) % props.list.length;
		} else if (e.key === "Enter") {
			e.preventDefault();
			submit();
		} else if (e.key === "Escape") {
			cancel();
		}
	};

	const selectClick = (index: number) => {
		selectedIndex.value = index;
		// Jika ingin klik langsung submit, panggil submit() di sini
	};
</script>

<template>
	<Teleport to="body">
		<Transition name="fade">
			<div
				v-if="visible"
				class="fixed inset-0 z-50 flex items-center justify-center p-4"
			>
				<div
					class="absolute inset-0 bg-black/20 backdrop-blur-sm"
					@click="cancel"
				></div>

				<Transition name="scale">
					<div
						v-if="visible"
						class="relative w-full max-w-md bg-white dark:bg-slate-800 rounded-xl shadow-xl border border-slate-200 dark:border-slate-700 overflow-hidden z-10"
						role="dialog"
					>
						<!-- Header -->
						<div
							class="px-6 py-4 border-b border-slate-200 dark:border-slate-700"
						>
							<h2
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

						<!-- Body: Kontainer Utama Keyboard -->
						<div
							ref="listContainer"
							class="px-6 py-4 outline-none"
							tabindex="0"
							@keydown="handleKeydown"
						>
							<div class="space-y-1">
								<div
									v-for="(item, index) in props.list"
									:key="item.id"
									class="w-full px-3 py-2.5 rounded-lg cursor-pointer transition-colors whitespace-nowrap overflow-auto"
									:class="
										selectedIndex === index
											? 'bg-blue-600 text-white shadow-md'
											: 'hover:bg-slate-100 dark:bg-slate-700 dark:text-white dark:hover:bg-slate-600'
									"
									@click="selectClick(index)"
									@dblclick="submit"
								>
									{{ item.text }}
								</div>
							</div>
						</div>

						<!-- Footer -->
						<div
							class="px-6 py-4 bg-slate-50 dark:bg-slate-700/50 border-t border-slate-200 dark:border-slate-700 flex justify-end gap-3"
						>
							<button
								type="button"
								@click="cancel"
								class="px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-600 rounded-lg"
							>
								{{ cancelText }}
							</button>
							<button
								type="button"
								@click="submit"
								class="px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg"
							>
								{{ okText }}
							</button>
						</div>
					</div>
				</Transition>
			</div>
		</Transition>
	</Teleport>
</template>
