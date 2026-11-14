<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Icon from '@/components/Icon.vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { type GuideTopic, guideFor } from '@/lib/helpGuides';

defineProps<{ topic: GuideTopic }>();
</script>

<template>
    <Tooltip>
        <TooltipTrigger as-child>
            <Link
                :href="`/help/${topic}`"
                class="inline-flex size-11 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors outline-none hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                :aria-label="$t(`help.${topic}.linkLabel`)"
            >
                <Icon name="help-outline" class="size-5" aria-hidden="true" />
            </Link>
        </TooltipTrigger>
        <TooltipContent>
            {{
                $t('help.common.tooltip', {
                    guide: $t(`help.${topic}.guideName`),
                    n: guideFor(topic).minutes,
                })
            }}
        </TooltipContent>
    </Tooltip>
</template>
