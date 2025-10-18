<template>
	<span v-bind="$attrs"
		:aria-hidden="!title"
		:aria-label="title"
		class="material-design-icon itop-icon"
		role="img"
		v-on="$listeners">
		<svg :fill="fillColor"
			class="material-design-icon__svg"
			:width="size"
			:height="size"
			viewBox="0 0 24 24">
			<path d="M12 2L2 7v10c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-10-5zm0 10h7c-.53 4.12-3.28 7.79-7 8.94V12H5V9h7V4.06l7 3.5V12z">
				<title v-if="title">{{ title }}</title>
			</path>
		</svg>
	</span>
</template>

<script>
export default {
	name: 'ItopIcon',
	props: {
		title: {
			type: String,
			default: '',
		},
		fillColor: {
			type: String,
			default: 'currentColor',
		},
		size: {
			type: Number,
			default: 24,
		},
	},
}
</script>
