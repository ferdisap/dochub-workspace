<script lang="ts" setup>
	interface PropsNotification {
		visible: boolean;
		type: "completed" | "failed" | "processing" | "uploaded";
		title: string;
		message: string;
	}

	const props = withDefaults(defineProps<PropsNotification>(), {
		visible: false,
		type: "completed",
		title: "Nothing",
		message: "No message",
	});

  const emit = defineEmits([
		"close",
	]);

  const closeNotif = () => {
    emit('close');
  }
</script>

<template>
	<div v-if="props.visible" class="result" :class="props.type">
		<div class="result-icon">
			<svg
				v-if="props.type === 'completed'"
				xmlns="http://www.w3.org/2000/svg"
				width="24"
				height="24"
				viewBox="0 0 24 24"
				fill="none"
				stroke="currentColor"
			>
				<polyline points="20 6 9 17 4 12"></polyline>
			</svg>
			<svg
				v-else
				xmlns="http://www.w3.org/2000/svg"
				width="24"
				height="24"
				viewBox="0 0 24 24"
				fill="none"
				stroke="currentColor"
			>
				<circle cx="12" cy="12" r="10"></circle>
				<line x1="12" y1="8" x2="12" y2="12"></line>
				<line x1="12" y1="16" x2="12.01" y2="16"></line>
			</svg>
		</div>
		<div class="result-content">
			<div class="flex justify-between">
				<h3>{{ props.title }}</h3>
				<button @click.stop="closeNotif" class="text-gray-300 hover:text-gray-600">
					<svg
						xmlns="http://www.w3.org/2000/svg"
						width="24"
						height="24"
						viewBox="0 0 24 24"
						fill="none"
						stroke="currentColor"
						stroke-width="2"
						stroke-linecap="round"
						stroke-linejoin="round"
						class="lucide lucide-x-icon lucide-x"
					>
						<path d="M18 6 6 18" />
						<path d="m6 6 12 12" />
					</svg>
				</button>
			</div>
			<p>{{ props.message }}</p>
			<slot name="job-info"></slot>
			<!-- <div v-if="props.jobId" class="job-info">
				<span class="mr-1 text-sm">Job ID: {{ props.jobId }}</span>
				<button @click="checkStatus" class="btn-sm">Check Status</button>
			</div> -->
		</div>
	</div>
</template>

<style scoped>
	.result {
		margin-top: 1.5rem;
		padding: 1rem;
		border-radius: 4px;
	}

	.result.completed {
		background: #d4edda;
		border: 1px solid #c3e6cb;
		color: #155724;
	}

	.result.failed {
		background: #f8d7da;
		border: 1px solid #f5c6cb;
		color: #721c24;
	}

	.result.processing,
	.result.uploaded {
		background: #f5f8d7;
		border: 1px solid #f6fac6;
		color: #1a1a1a;
	}

	.result-icon {
		float: left;
		margin-right: 1rem;
	}

	.result-icon svg {
		width: 24px;
		height: 24px;
		stroke-width: 2;
	}

	.result.completed .result-icon svg {
		stroke: #28a745;
	}

	.result.error .result-icon svg {
		stroke: #dc3545;
	}

	.result-content h3 {
		/* margin: 0 0 0.5rem 2rem; */
		font-weight: bold;
		font-size: 1.125rem;
	}

	.result-content p {
		margin: 0 0 0.5rem 2rem;
		font-size: 0.875rem;
		overflow: auto;
	}

	.job-info {
		margin: 0 0 0.5rem 2rem;
		display: flex;
		align-items: center;
		gap: 0.5rem;
		margin-top: 0.5rem;
		font-size: 0.875rem;
	}
</style>
