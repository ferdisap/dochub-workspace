<!--
Vue 3 Single File Component (SFC)
Features:
- v-model support (selected value)
- searchable input to filter options
- keyboard navigation: ArrowUp / ArrowDown / Enter / Escape
- mouse support + scrolling (selected item is scrolled into view)
- accessible: basic ARIA attributes

Usage:
<template>
  <SearchableDropdown
    v-model="selected"
    :options="items"
    placeholder="Pilih item..."
    item-label="name"       
    :clearable="true"
  />
</template>
-->

<!-- <script setup lang="ts">
import SearchableDropdown from './SearchableDropdown.vue'
import { ref } from 'vue'

const items = [
  { id: 1, name: 'Apple' },
  { id: 2, name: 'Banana' },
  { id: 3, name: 'Cherry' },
  // ...
]
const selected = ref(null) // or the object/value you want
</script> -->

<template>
	<div class="dropdown" ref="root" @keydown.stop.prevent="onKeydown">
		<div class="control" @click="toggleOpen" :class="{ open: open }">
			<input
				ref="input"
				v-model="query"
				:placeholder="placeholder"
				@focus="openList"
				@input="onInput"
				@keydown.stop
        @click.stop
				:aria-expanded="String(open)"
				:aria-controls="listId"
				:aria-autocomplete="'list'"
			/>
			<button
				v-if="clearable"
				class="clear-btn"
				@click.stop="clear"
				aria-label="Clear selection"
			>
				✕
			</button>
			<span class="chev">▾</span>
		</div>

		<ul
			v-show="open"
			:id="listId"
			class="list"
			role="listbox"
			:aria-activedescendant="activeId"
			ref="list"
		>
			<li
				v-for="(opt, i) in filteredOptions"
				:key="optionKey(opt, i)"
				:id="optionId(i)"
				:class="{ option: true, active: i === activeIndex }"
				role="option"
				@mousedown.prevent="onOptionClick(i)"
				@mouseenter="activeIndex = i"
			>
				<slot name="option" :option="opt">{{ labelFor(opt) }}</slot>
			</li>
			<li v-if="filteredOptions.length === 0" class="empty">No results</li>
		</ul>
	</div>
</template>

<script setup>
	import {
		ref,
		computed,
		watch,
		onMounted,
		onBeforeUnmount,
		nextTick,
	} from "vue";

	// props
	const props = defineProps({
		modelValue: { type: [String, Number, Object, null], default: null },
		options: { type: Array, default: () => [] }, // sumber data dropdown, digunakan untuk filtering, untuk navigasi keyboard, sebagai referensi vModel, tapi saya matikan.
		placeholder: { type: String, default: "Search..." },
		itemLabel: { type: String, default: null }, // items key jika option adalah Record<stirng,string>. Contoh { id: 10, name: "Apple" } name atau id adalah item label
		clearable: { type: Boolean, default: false }, // fitur untuk menghapus pilihan yang sudah dipilih
		filterFn: { type: Function, default: null }, // custom filtering function untuk menggantikan cara filter bawaan ketika user mengetik di dropdown. anpa filterFn, komponen akan melakukan filter berdasarkan itemLabel dan mencocokkan substring secara case-insensitive
		valueKey: { type: String, default: "id" }, // mengidentifikasi item secara unik. bisa id, email dan lain2
	});
	const emit = defineEmits([
		// "update:modelValue",
		"select",
		"open",
		"close",
		"search",
	]);

	// internal state
	const open = ref(false);
	const query = ref("");
	const input = ref(null);
	const list = ref(null);
	const root = ref(null);
	const activeIndex = ref(-1);

	// ids for accessibility
	const uid = Math.random().toString(36).slice(2, 9);
	const listId = `dropdown-list-${uid}`;
	const optionId = (i) => `dropdown-${uid}-option-${i}`;
	const activeId = computed(() =>
		activeIndex.value >= 0 ? optionId(activeIndex.value) : null
	);

	// derived
	const filteredOptions = computed(() => {
		const q = query.value.trim().toLowerCase();
		if (!q) return props.options.slice();
		if (props.filterFn)
			return props.options.filter((o) => props.filterFn(o, q));

		return props.options.filter((o) => {
			const lab = labelFor(o).toString().toLowerCase();
			return lab.includes(q);
		});
	});

	function labelFor(opt) {
		if (props.itemLabel && opt && typeof opt === "object")
			return opt[props.itemLabel];
		// if option is primitive
		if (opt && typeof opt === "object")
			return opt.label ?? opt.name ?? JSON.stringify(opt);
		return String(opt);
	}

	function optionKey(opt, i) {
		if (opt && typeof opt === "object" && props.valueKey in opt)
			return opt[props.valueKey];
		return i;
	}

	function openList() {
		open.value = true;
		emit("open");
	}
	function closeList() {
		open.value = false;
		activeIndex.value = -1;
		emit("close");
	}

	function toggleOpen() {
		open.value = !open.value;
		if (open.value) {
			emit("open");
			nextTick(() => input.value && input.value.focus());
		} else emit("close");
	}

	function onInput() {
		emit("search", query.value);
		// reset active index to first result
		activeIndex.value = filteredOptions.value.length ? 0 : -1;
	}

	function selectIndex(i) {
		const opt = filteredOptions.value[i];
		if (!opt) return;
		// emit("update:modelValue", opt);
		emit("select", opt);
		// set input text to label (useful when selecting)
		query.value = labelFor(opt);
		closeList();
	}

	function onOptionClick(i) {
		selectIndex(i);
	}

	function clear() {
		// emit("update:modelValue", null);
		query.value = "";
		input.value && input.value.focus();
	}

	// keyboard handling
	function onKeydown(e) {
		if (!open.value && (e.key === "ArrowDown" || e.key === "ArrowUp")) {
			openList();
			e.preventDefault();
			return;
		}

		if (!open.value) return;

		if (e.key === "ArrowDown") {
			if (filteredOptions.value.length === 0) return;
			activeIndex.value =
				(activeIndex.value + 1) % filteredOptions.value.length;
			scrollActiveIntoView();
		} else if (e.key === "ArrowUp") {
			if (filteredOptions.value.length === 0) return;
			activeIndex.value =
				(activeIndex.value - 1 + filteredOptions.value.length) %
				filteredOptions.value.length;
			scrollActiveIntoView();
		} else if (e.key === "Enter") {
			if (activeIndex.value >= 0) selectIndex(activeIndex.value);
		} else if (e.key === "Escape") {
			closeList();
		}
	}

	function scrollActiveIntoView() {
		nextTick(() => {
			const idx = activeIndex.value;
			if (idx < 0) return;
			const el = list.value && list.value.querySelector(`#${optionId(idx)}`);
			if (el && typeof el.scrollIntoView === "function") {
				// use nearest block to avoid jumping
				el.scrollIntoView({ block: "nearest" });
			}
		});
	}

	// sync modelValue -> query label
	watch(
		() => props.modelValue,
		(nv) => {
			if (nv == null) {
				query.value = "";
				return;
			}
			// if modelValue is an object, use label
			query.value = labelFor(nv);
		}
	);

	// click outside to close
	function onDocClick(e) {
		if (!root.value) return;
		if (!root.value.contains(e.target)) closeList();
	}

	onMounted(() => {
		document.addEventListener("click", onDocClick);
	});
	onBeforeUnmount(() => {
		document.removeEventListener("click", onDocClick);
	});
</script>

<style scoped>
	.dropdown {
		width: 260px;
		position: relative;
		font-family:
			system-ui,
			-apple-system,
			"Segoe UI",
			Roboto,
			"Helvetica Neue",
			Arial;
	}
	.control {
		display: flex;
		align-items: center;
		gap: 6px;
		border: 1px solid #cbd5e1;
		padding: 6px 8px;
		border-radius: 6px;
		background: white;
	}
	.control.open {
		box-shadow: 0 6px 16px rgba(2, 6, 23, 0.08);
	}
	.control input {
		border: none;
		outline: none;
		flex: 1;
	}
	.chev {
		user-select: none;
	}
	.clear-btn {
		background: transparent;
		border: none;
		cursor: pointer;
		padding: 4px;
	}
	.list {
		position: absolute;
		left: 0;
		right: 0;
		max-height: 220px;
		overflow: auto;
		margin-top: 6px;
		border: 1px solid #e2e8f0;
		border-radius: 8px;
		background: white;
		z-index: 50;
		padding: 6px 0;
	}
	.option {
		padding: 8px 12px;
		cursor: pointer;
    text-align: left;
	}
	.option.active {
		background: #eef2ff;
	}
	.empty {
		padding: 8px 12px;
		color: #64748b;
	}
</style>
