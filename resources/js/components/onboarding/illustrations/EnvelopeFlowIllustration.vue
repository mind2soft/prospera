<script setup lang="ts">
defineProps<{ variant?: 'default' | 'locked' }>();
</script>

<template>
    <!-- One paycheck flows into four purposes; savings rises like the logo. -->
    <svg
        viewBox="0 0 320 200"
        fill="none"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        focusable="false"
        class="h-auto w-full rtl:-scale-x-100"
    >
        <g :class="variant === 'locked' && 'opacity-60'">
            <g class="stroke-brand/35">
                <path d="M112 100 C168 100 172 38 232 38" stroke-width="10" />
                <path d="M112 100 C168 100 176 82 232 82" stroke-width="7" />
                <path d="M112 100 C168 100 176 126 232 126" stroke-width="5" />
                <path
                    d="M112 100 C168 100 172 170 232 170"
                    stroke-width="3.5"
                />
            </g>
            <rect
                x="24"
                y="68"
                width="88"
                height="64"
                rx="6"
                class="fill-card stroke-foreground"
                stroke-width="2.4"
            />
            <path
                d="M24 74 L68 104 L112 74"
                class="stroke-foreground"
                stroke-width="2.4"
            />
            <path
                d="M52 58 L68 46 L84 58"
                class="stroke-brand"
                stroke-width="3"
            />
            <path
                d="M100 52h10M105 47v10"
                class="stroke-chart-revenue"
                stroke-width="2.4"
            />
            <g class="fill-card stroke-foreground" stroke-width="2">
                <rect x="232" y="20" width="64" height="36" rx="5" />
                <rect x="232" y="64" width="64" height="36" rx="5" />
                <rect x="232" y="108" width="64" height="36" rx="5" />
                <rect x="232" y="152" width="64" height="36" rx="5" />
            </g>
            <g class="stroke-foreground" stroke-width="2">
                <path d="M232 24 L264 41 L296 24" />
                <path d="M232 68 L264 85 L296 68" />
                <path d="M232 112 L264 129 L296 112" />
                <path d="M232 156 L264 173 L296 156" />
            </g>
            <circle cx="244" cy="48" r="3" class="fill-brand" />
            <circle cx="244" cy="92" r="3" class="fill-warning" />
            <circle cx="244" cy="136" r="3" class="fill-chart-expense" />
            <path
                d="M276 146 L284 140 L292 146"
                class="stroke-brand"
                stroke-width="2.4"
            />
        </g>
    </svg>
</template>
