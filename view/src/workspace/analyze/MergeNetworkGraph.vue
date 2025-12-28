<script setup lang="ts">
	import { onMounted, onUnmounted, ref, watch } from "vue";
	import * as d3 from "d3";
	import * as dagreD3 from "dagre-d3";
	import { TreeMergeNode } from "./wsUtils";
import { formatDate } from "./folderUtils";

	const props = defineProps<{
		treeData: TreeMergeNode[]; // root-level nodes (array)
	}>();

	const svgContainer = ref<HTMLElement | null>(null);
	let svg: d3.Selection<SVGSVGElement, unknown, null, undefined> | null = null;
	let inner: d3.Selection<SVGGElement, unknown, null, undefined> | null = null;
	let zoom: d3.ZoomBehavior<Element, unknown> | null = null;

	const initGraph = () => {
		if (!svgContainer.value) return;

		// Bersihkan SVG sebelumnya
		d3.select(svgContainer.value).selectAll("*").remove();

		// Buat SVG
		svg = d3
			.select(svgContainer.value)
			.append("svg")
			.attr(
				"class",
				"w-full h-full bg-white dark:bg-gray-900 rounded-lg border border-slate-200 dark:border-gray-700 shadow-sm"
			)
			.attr("viewBox", "0 0 800 600")
			.attr("preserveAspectRatio", "xMidYMid meet");

		inner = svg.append("g");

		// Zoom behavior
		zoom = d3.zoom<SVGSVGElement, unknown>().on("zoom", (event: any) => {
			if (inner) inner.attr("transform", event.transform);
		});

		svg.call(zoom);

		// Bangun DAG
		const g = new dagreD3.graphlib.Graph()
			.setGraph({
				rankdir: "TB", // Top to Bottom
				ranksep: 60,
				nodesep: 40,
			})
			.setDefaultEdgeLabel(() => ({}));

		// Helper: tambahkan node & edge rekursif
		const addNode = (node: TreeMergeNode) => {
			g.setNode(node.id, {
				label: node.label || node.id.slice(0, 8),
				class: "merge-node",
				width: 120,
				height: 40,
			});

			node.children.forEach((child) => {
				g.setEdge(node.id, child.id, { class: "merge-edge" });
				addNode(child);
			});
		};

		props.treeData.forEach((root) => addNode(root));

		// Render dengan dagre-d3
		const render = new dagreD3.render();
		render(inner!, g);

		// Styling node & edge
		inner!.selectAll("g.merge-node").each(function (this:SVGGElement) {
			const nodeSel = d3.select(this);
			const nodeId = nodeSel.datum() as string; // ← string ID langsung

			// Cari data node asli dari treeData (rekursif)
			const nodeData = findNodeById(nodeId, props.treeData);
			if (!nodeData) return;

			// ... (lanjutkan seperti sebelumnya, ganti `data.id` → `nodeId`)
			const fo = nodeSel
				.append("foreignObject")
				.attr("width", 150)
				.attr("height", 80)
				.attr("x", -60)
				.attr("y", -20);

			const div = fo
				.append("xhtml:div")
				.attr(
					"class",
					"flex flex-col items-center justify-center w-full h-full px-2 py-1 text-xs font-medium text-gray-800 bg-blue-100 border border-blue-300 rounded-md cursor-pointer hover:bg-blue-200 dark:bg-blue-900/50 dark:border-blue-700 dark:text-blue-200 dark:hover:bg-blue-800/70 transition-colors"
				)
				.on("click", () => {
					alert(`Merge ID: ${nodeId}`); // ✅ nodeId = string
				});

			div.append("div").attr('class', 'w-full font-bold text-lg text-left ').text(nodeData.label || nodeId.slice(0, 8));
			div.append("div").text(formatDate(new Date(nodeData.merged_at)));

			// Tooltip
			div
				.on("mouseenter", function (event:PointerEvent) {
					if (!nodeData.message) return;
					d3.select("body")
						.append("div")
						.attr("id", "tooltip")
						.attr(
							"class",
							"fixed z-50 max-w-xs p-3 text-sm bg-gray-800 text-white rounded shadow-lg pointer-events-none opacity-0 transition-opacity"
						)
						.html(
							`<strong>${nodeData.label || "Merge"}</strong><br/>${nodeData.message
								.substring(0, 120)
								.replace(
									/(?:\r\n|\r|\n)/g,
									"<br/>"
								)}${nodeData.message.length > 120 ? "…" : ""}`
						)
						.style("left", event.clientX + 10 + "px")
						.style("top", event.clientY + 10 + "px")
						.transition()
						.duration(200)
						.style("opacity", 1);
				})
				.on("mousemove", function (event:PointerEvent) {
					d3.select("#tooltip")
						.style("left", event.clientX + 10 + "px")
						.style("top", event.clientY + 10 + "px");
				})
				.on("mouseleave", function () {
					d3.select("#tooltip").remove();
				});

			// Hapus elemen default (rect + text) dari dagre-d3
			nodeSel.selectAll("rect, text").remove();
		});

		// Styling edge
		inner!
			.selectAll("path.merge-edge")
			.attr(
				"class",
				"stroke-gray-400 stroke-1 fill-none dark:stroke-gray-600 hover:stroke-blue-500 transition-colors"
			);

		// Auto-fit ke viewport
		const graphWidth = g.graph().width ?? 800;
		const graphHeight = g.graph().height ?? 600;
		const scale = Math.min(780 / graphWidth, 560 / graphHeight, 1);
		const translate = [
			(800 - graphWidth * scale) / 2,
			(600 - graphHeight * scale) / 2,
		];

		if (inner && zoom) {
			const transform = d3.zoomIdentity
				.translate(translate[0], translate[1])
				.scale(scale);
			inner.attr("transform", transform);
			// Set zoom transform awal
			svg.call(zoom.transform, transform);
		}
	};

	// Helper: cari node by ID dari treeData
	const findNodeById = (
		id: string,
		nodes: TreeMergeNode[]
	): TreeMergeNode | undefined => {
		for (const node of nodes) {
			if (node.id === id) return node;
			const found = findNodeById(id, node.children);
			if (found) return found;
		}
		return undefined;
	};

	// Re-render saat treeData berubah
	watch(
		() => props.treeData,
		() => {
			if (svgContainer.value) {
				initGraph();
			}
		},
		{ deep: true }
	);

	onMounted(() => {
		if (svgContainer.value) {
			initGraph();
		}
	});

	onUnmounted(() => {
		d3.select(svgContainer.value).selectAll("*").remove();
	});
</script>

<template>
	<div
		class="relative w-full h-[600px] overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800"
	>
		<!-- Kontrol Zoom -->
		<div class="absolute top-3 right-3 z-10 flex gap-1">
			<button
				@click="() => zoom?.scaleBy(svg as any, 1.2)"
				class="p-2 text-gray-700 bg-white rounded shadow hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
				aria-label="Zoom in"
			>
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" x2="16.65" y1="21" y2="16.65"/><line x1="11" x2="11" y1="8" y2="14"/><line x1="8" x2="14" y1="11" y2="11"/></svg>
			</button>
			<button
				@click="() => zoom?.scaleBy(svg as any, 0.8)"
				class="p-2 text-gray-700 bg-white rounded shadow hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
				aria-label="Zoom out"
			>
				<svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" x2="16.65" y1="21" y2="16.65"/><line x1="8" x2="14" y1="11" y2="11"/></svg>
			</button>
			<button
				@click="
					() => {
						if (svg && zoom) {
							const transform = d3.zoomIdentity;
							svg.call(zoom.transform, transform);
						}
					}
				"
				class="p-2 text-gray-700 bg-white rounded shadow hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
				aria-label="Reset zoom"
			>
				<svg
					class="w-4 h-4"
					fill="none"
					stroke="currentColor"
					viewBox="0 0 24 24"
				>
					<path
						stroke-linecap="round"
						stroke-linejoin="round"
						stroke-width="2"
						d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
					/>
				</svg>
			</button>
		</div>

		<!-- Placeholder saat loading -->
		<div
			v-if="!treeData || treeData.length === 0"
			class="absolute inset-0 flex items-center justify-center"
		>
			<div class="text-gray-500 dark:text-gray-400">No merge history yet.</div>
		</div>

		<!-- SVG container -->
		<div ref="svgContainer" class="w-full h-full"></div>
	</div>
</template>

<style scoped>
	/* Pastikan SVG tidak overflow */
	:deep(.merge-node rect) {
		fill: transparent !important;
		stroke: none !important;
	}
	:deep(.merge-edge path) {
		/* stroke: theme('colors.gray.400') !important; */
		stroke: gray;
		stroke-width: 1.5px !important;
	}
</style>
