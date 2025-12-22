<script lang="ts" setup>
	interface Props {
		visible: boolean;
    total?: number;
    unit?: string;
	}

	const props = withDefaults(defineProps<Props>(), {
		visible: false,
		total: 0,
	});
</script>

<template>
	<div v-if="props.visible" class="progress-container">
		<div class="progress-circle">
			<div class="progress-rolling-recursive"></div>
		</div>
    <div class="progress-info" v-if="props.total">
      <div class="progress-accumulative-quantity">
        <span class="progress-total">{{ props.total }}&#160;{{ props.unit }}</span>
      </div>
    </div>
	</div>
</template>

<style>
	.progress-container {
		text-align: left;
	}

	.progress-circle {
    display: flex;
    justify-content: center;
		margin-bottom: 1rem;
	}
  
	.progress-rolling-recursive {
		border: 4px solid #f3f3f3; /* Light grey border */
		border-top: 4px solid #3498db; /* Blue top border to create the spin effect */
		border-radius: 50%; /* Makes it a circle */
		width: 40px;
		height: 40px;
		animation: spin 1s linear infinite; /* Animation name, duration, timing, repetition */
	}

  .progress-accumulative-quantity {
    text-align: center;
  }

  .progress-total {
    margin-right: 1rem;
  }

	@keyframes spin {
		0% {
			transform: rotate(0deg);
		}
		100% {
			transform: rotate(360deg);
		}
	}
</style>
