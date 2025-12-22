<script lang="ts" setup>
  import { formatBytes } from "../ChunkUploadManager";

	interface Props {
		visible: boolean;
		percentage: number; // max 100
		speed?: number; // in bytes
	}

	const props = withDefaults(defineProps<Props>(), {
		visible: false,
		percentage: 0,
	});

</script>

<template>
	<!-- Progress -->
	<div v-if="props.visible" class="progress-container">
		<div class="progress-bar">
			<div
				class="progress-fill"
				:style="{ width: `${props.percentage}%` }"
			></div>
		</div>
		<div class="progress-info">
			<div class="progress-percent-speed">
				<span class="progress-percent">{{ props.percentage }}%</span>&#160;<span
					class="progress-speed"
					v-if="props.speed"
					>{{ formatBytes(props.speed) }}/s</span
				>
			</div>
      <slot name="pause-resume"></slot> 
			<!-- <button v-if="hasPauseResume"
				@click.stop="pauseResume"
				:class="['control-btn', props.paused ? 'resume' : 'pause']"
			>
				{{ props.paused ? "Resume" : "Pause" }}
			</button>
			<button @click.stop="cancel" class="control-btn cancel">Cancel</button> -->
		</div>
	</div>
</template>

<style scoped src="./targetProgress.css"></style>
