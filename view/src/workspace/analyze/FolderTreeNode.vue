<script setup lang="ts">
import { ref, computed } from 'vue';
import FolderTreeNode from './FolderTreeNode.vue'; // recursive self-import
import { FileNode } from './folderUtils';

const props = defineProps<{
  node: FileNode;
  depth?: number;
}>();

const depth = computed(() => props.depth ?? 0);

const toggleExpand = () => {
  if (props.node.kind === 'directory') {
    props.node.expanded = !props.node.expanded;
  }
};

const indentClass = computed(() => {
  return depth.value > 0 ? `pl-${4 + depth.value * 4}` : '';
});
</script>

<template>
  <div :class="['tree-node', indentClass]">
    <div
      class="flex items-center py-1 px-2 rounded hover:bg-gray-50 cursor-pointer"
      :class="{
        'font-medium text-gray-800': node.kind === 'directory',
        'text-gray-600': node.kind === 'file',
      }"
      @click="toggleExpand"
    >
      <!-- Icon -->
      <svg
        v-if="node.kind === 'directory'"
        xmlns="http://www.w3.org/2000/svg"
        width="16"
        height="16"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        class="mr-2 shrink-0 flex-shrink-0"
      >
        <path
          v-if="node.expanded"
          d="M6 10l12 0"
          stroke="currentColor"
        />
        <path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2Z" />
      </svg>

      <svg
        v-else
        xmlns="http://www.w3.org/2000/svg"
        width="16"
        height="16"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        class="mr-2 shrink-0"
      >
        <path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z" />
        <path d="M14 2v4a2 2 0 0 0 2 2h4" />
      </svg>

      <!-- Name -->
      <span class="truncate flex-1">{{ node.name }}</span>

      <!-- Counter (directory only) -->
      <span
        v-if="node.kind === 'directory'"
        class="text-xs text-gray-500 pl-2 whitespace-nowrap"
      >
        ({{ node.children?.length || 0 }})
      </span>
    </div>

    <!-- Children (recursive) -->
    <div
      v-if="node.kind === 'directory' && node.expanded && node.children"
      class="ml-4 border-l-2 border-gray-100"
    >
      <FolderTreeNode
        v-for="child in node.children"
        :key="child.id"
        :node="child"
        :depth="depth + 1"
      />
    </div>
  </div>
</template>

<style scoped>
.tree-node {
  font-size: 0.875rem;
  line-height: 1.5;
}
</style>