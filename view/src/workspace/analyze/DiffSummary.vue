<script setup lang="ts">
	import { ref, computed } from "vue";
	import { DiffResult } from "./compare";

  type FilterType = "all" | "added" | "changed" | "deleted";

	const props = defineProps<{
		diff: DiffResult;
		loading?: boolean;
		total: number;
	}>();

	const search = ref("");
	const filter = ref<FilterType>("all");

	const filteredChanges = computed(() => {
		let list = props.diff.changes;

		if (search.value) {
			const q = search.value.toLowerCase();
			list = list.filter((c) => c.path.toLowerCase().includes(q));
		}

		if (filter.value !== "all") {
			list = list.filter((c) => c.action === filter.value);
		}

		return list;
	});

	// Helper: format angka dengan koma ribuan
	function formatNumber(n: number): string {
		return n.toLocaleString("id-ID");
	}

	// Helper format bytes (sama seperti Laravel)
	function formatBytes(bytes: number): string {
		if (bytes === 0) return "0";
		const sign = bytes < 0 ? "-" : "+";
		const abs = Math.abs(bytes);

		if (abs < 1024) return `${sign}${abs}B`;
		if (abs < 1048576) return `${sign}${(abs / 1024).toFixed(1)}K`;
		return `${sign}${(abs / 1048576).toFixed(1)}M`;
	}

	// Ikon & warna berdasarkan aksi
	const actionConfig = {
		added: {
			label: "Ditambahkan",
			icon: "➕",
			color: "text-emerald-600 bg-emerald-50",
		},
		deleted: {
			label: "Dihapus",
			icon: "🗑️",
			color: "text-rose-600 bg-rose-50",
		},
		changed: {
			label: "Diubah",
			icon: "✏️",
			color: "text-amber-600 bg-amber-50",
		},
	};
</script>

<template>
	<div class="space-y-6">
		<!-- 🔍 Tabel Ringkasan Metrik (versi CLI-like) -->
		<div
			v-if="!loading"
			class="overflow-hidden rounded-lg border border-slate-200 shadow-sm max-w-md"
		>
			<div class="bg-slate-50 px-4 py-2 border-b border-slate-200">
				<h3 class="font-medium text-slate-700 text-sm">
					📊 Ringkasan Perbandingan
				</h3>
			</div>
			<table class="min-w-full text-sm">
				<thead>
					<tr class="bg-slate-100">
						<th class="px-4 py-2 text-left font-medium text-slate-600">
							Metrik
						</th>
						<th class="px-4 py-2 text-right font-medium text-slate-600">
							Jumlah
						</th>
					</tr>
				</thead>
				<tbody class="divide-y divide-slate-100">
					<tr>
						<td class="px-4 py-2 font-medium text-slate-800">File di source</td>
						<td
							class="px-4 py-2 text-right font-mono font-medium text-slate-900"
						>
							{{ formatNumber(diff.identical + diff.changed + diff.deleted) }}
						</td>
					</tr>
					<tr>
						<td class="px-4 py-2 font-medium text-slate-800">File di target</td>
						<td
							class="px-4 py-2 text-right font-mono font-medium text-slate-900"
						>
							{{ formatNumber(diff.identical + diff.changed + diff.added) }}
						</td>
					</tr>
					<tr class="bg-emerald-50">
						<td class="px-4 py-2 font-medium text-emerald-700">Sama</td>
						<td
							class="px-4 py-2 text-right font-mono font-medium text-emerald-700"
						>
							{{ formatNumber(diff.identical) }}
						</td>
					</tr>
					<tr class="bg-amber-50">
						<td class="px-4 py-2 font-medium text-amber-700">Berubah</td>
						<td
							class="px-4 py-2 text-right font-mono font-medium text-amber-700"
						>
							{{ formatNumber(diff.changed) }}
						</td>
					</tr>
					<tr class="bg-emerald-50">
						<td class="px-4 py-2 font-medium text-emerald-700">Ditambahkan</td>
						<td
							class="px-4 py-2 text-right font-mono font-medium text-emerald-700"
						>
							{{ formatNumber(diff.added) }}
						</td>
					</tr>
					<tr class="bg-rose-50">
						<td class="px-4 py-2 font-medium text-rose-700">Dihapus</td>
						<td
							class="px-4 py-2 text-right font-mono font-medium text-rose-700"
						>
							{{ formatNumber(diff.deleted) }}
						</td>
					</tr>
					<tr class="border-t border-slate-200 bg-slate-50 font-semibold">
						<td class="px-4 py-2 text-slate-800">Total perubahan</td>
						<td class="px-4 py-2 text-right font-mono text-slate-900">
							{{ formatNumber(diff.total_changes) }}
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- 🔢 Card Grid (Opsional — untuk visual cepat) -->
		<!-- Bisa dihilangkan jika terlalu redundan, atau dipertahankan untuk glanceable insight -->
		<div
			v-if="!loading"
			class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-3"
		>
			<div class="bg-white rounded-lg border p-4 shadow-sm">
				<p class="text-xs text-slate-500 wrap-break-word h-[30px]">File di source</p>
				<p class="text-xl font-bold text-slate-800">
					{{ formatNumber(diff.identical + diff.changed + diff.deleted) }}
				</p>
			</div>
			<div class="bg-white rounded-lg border p-4 shadow-sm">
				<p class="text-xs text-slate-500 wrap-break-word h-[30px]">File di target</p>
				<p class="text-xl font-bold text-slate-800">
					{{ formatNumber(diff.identical + diff.changed + diff.added) }}
				</p>
			</div>
			<div class="bg-white rounded-lg border p-4 shadow-sm">
				<p class="text-xs text-slate-500 wrap-break-word h-[30px]">Sama</p>
				<p class="text-xl font-bold text-emerald-600">
					{{ formatNumber(diff.identical) }}
				</p>
			</div>
			<div class="bg-white rounded-lg border p-4 shadow-sm">
				<p class="text-xs text-slate-500 wrap-break-word h-[30px]">Berubah</p>
				<p class="text-xl font-bold text-amber-600">
					{{ formatNumber(diff.changed) }}
				</p>
			</div>
			<div class="bg-white rounded-lg border p-4 shadow-sm">
				<p class="text-xs text-slate-500 wrap-break-word h-[30px]">Ditambahkan</p>
				<p class="text-xl font-bold text-emerald-600">
					{{ formatNumber(diff.added) }}
				</p>
			</div>
			<div class="bg-white rounded-lg border p-4 shadow-sm">
				<p class="text-xs text-slate-500 wrap-break-word h-[30px]">Dihapus</p>
				<p class="text-xl font-bold text-rose-600">
					{{ formatNumber(diff.deleted) }}
				</p>
			</div>
		</div>

		<!-- Filter & Search -->
		<div class="flex flex-col sm:flex-row gap-3 items-start sm:items-center">
			<div class="flex-1 w-full">
				<label for="search-path" class="sr-only">Cari path</label>
				<div class="relative">
					<div
						class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"
					>
						<svg
							class="h-5 w-5 text-slate-400"
							fill="none"
							stroke="currentColor"
							viewBox="0 0 24 24"
						>
							<path
								stroke-linecap="round"
								stroke-linejoin="round"
								stroke-width="2"
								d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
							/>
						</svg>
					</div>
					<input
						id="search-path"
						v-model="search"
						type="text"
						placeholder="Cari path file…"
						class="block w-full pl-10 pr-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
					/>
				</div>
			</div>

			<div class="flex flex-wrap gap-2">
				<button
					v-for="opt in [
						{ value: 'all', label: 'Semua' },
						{ value: 'added', label: 'Tambah' },
						{ value: 'changed', label: 'Ubah' },
						{ value: 'deleted', label: 'Hapus' },
					]"
					:key="opt.value"
					@click="filter = (opt.value as FilterType)"
					:class="[
						'px-3 py-1.5 text-sm font-medium rounded-md transition',
						filter === opt.value
							? 'bg-blue-600 text-white'
							: 'bg-slate-100 text-slate-700 hover:bg-slate-200',
					]"
				>
					{{ opt.label }}
				</button>
			</div>
		</div>

		<!-- Tabel Perubahan -->
		<div class="overflow-hidden rounded-lg border border-slate-200 shadow-sm">
			<div class="overflow-x-auto">
				<table class="min-w-full divide-y divide-slate-200">
					<thead class="bg-slate-50">
						<tr>
							<th
								scope="col"
								class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider"
							>
								Aksi
							</th>
							<th
								scope="col"
								class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider"
							>
								Path
							</th>
							<th
								scope="col"
								class="px-4 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider"
							>
								Ukuran
							</th>
							<th
								v-if="diff.changes.some((c) => c.diff_preview)"
								scope="col"
								class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider"
							>
								Preview
							</th>
						</tr>
					</thead>
					<tbody class="bg-white divide-y divide-slate-200">
						<tr v-if="loading">
							<td colspan="4" class="px-4 py-6 text-center">
								<div class="inline-flex items-center space-x-2 text-slate-500">
									<svg
										class="animate-spin h-5 w-5"
										xmlns="http://www.w3.org/2000/svg"
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
									<span>Memuat perbandingan…</span>
									<span>{{ props.total }} files</span>
								</div>
							</td>
						</tr>

						<tr v-else-if="filteredChanges.length === 0">
							<td colspan="4" class="px-4 py-8 text-center">
								<div class="text-slate-500">
									<svg
										class="mx-auto h-12 w-12 text-slate-400"
										fill="none"
										stroke="currentColor"
										viewBox="0 0 24 24"
									>
										<path
											stroke-linecap="round"
											stroke-linejoin="round"
											stroke-width="2"
											d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
										/>
									</svg>
									<p class="mt-2 font-medium">Tidak ada perubahan ditemukan</p>
									<p class="mt-1 text-sm">Coba ubah filter atau pencarian.</p>
								</div>
							</td>
						</tr>

						<template v-else>
							<tr
								v-for="change in filteredChanges"
								:key="change.path"
								class="hover:bg-slate-50 transition-colors"
							>
								<td class="px-4 py-3 whitespace-nowrap">
									<span
										:class="[
											'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
											actionConfig[change.action].color,
										]"
									>
										{{ actionConfig[change.action].icon }}
										{{ actionConfig[change.action].label }}
									</span>
								</td>
								<td class="px-4 py-3 max-w-[300px] lg:max-w-none">
									<div class="flex items-center">
										<code class="text-sm font-mono text-slate-800 truncate">{{
											change.path
										}}</code>
									</div>
								</td>
								<td
									class="px-4 py-3 text-right font-mono text-sm font-semibold whitespace-nowrap"
								>
									<span
										:class="{
											'text-emerald-600': change.size_change > 0,
											'text-rose-600': change.size_change < 0,
											'text-slate-500': change.size_change === 0,
										}"
									>
										{{ formatBytes(change.size_change) }}
									</span>
								</td>
								<td
									v-if="diff.changes.some((c) => c.diff_preview)"
									class="px-4 py-3 text-sm font-mono"
								>
									<span
										v-if="change.diff_preview"
										class="bg-slate-100 px-1.5 py-0.5 rounded"
									>
										{{ change.diff_preview }}
									</span>
									<span v-else class="text-slate-400">–</span>
								</td>
							</tr>
						</template>
					</tbody>
				</table>
			</div>

			<!-- Catatan footer -->
			<div
				v-if="!loading && filteredChanges.length > 0"
				class="px-4 py-3 bg-slate-50 text-sm text-slate-500 border-t"
			>
				Menampilkan {{ filteredChanges.length }} dari
				{{ diff.changes.length }} perubahan.
				<span
					v-if="diff.total_changes === 0"
					class="ml-2 text-emerald-600 font-medium"
					>✅ Tidak ada perubahan — identik.</span
				>
			</div>
		</div>
	</div>
</template>
