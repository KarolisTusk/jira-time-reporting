<script setup lang="ts">
import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem, SidebarMenuSub, SidebarMenuSubButton, SidebarMenuSubItem } from '@/components/ui/sidebar';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronRight } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps<{
    items: NavItem[];
}>();


const page = usePage();
const openItems = ref<Set<string>>(new Set());

const toggleItem = (title: string) => {
    if (openItems.value.has(title)) {
        openItems.value.delete(title);
    } else {
        openItems.value.add(title);
    }
};

const isItemOpen = (title: string) => openItems.value.has(title);
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>Platform</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in props.items" :key="item.title">
                <!-- Items with subitems -->
                <template v-if="item.items && item.items.length > 0">
                    <Collapsible :open="isItemOpen(item.title)" @update:open="() => toggleItem(item.title)">
                        <CollapsibleTrigger as-child>
                            <SidebarMenuButton :tooltip="item.title">
                                <component :is="item.icon" />
                                <span>{{ item.title }}</span>
                                <ChevronRight class="ml-auto transition-transform duration-200" :class="{ 'rotate-90': isItemOpen(item.title) }" />
                            </SidebarMenuButton>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <SidebarMenuSub>
                                <SidebarMenuSubItem v-for="subItem in item.items" :key="subItem.title">
                                    <SidebarMenuSubButton as-child :is-active="subItem.href === page.url">
                                        <Link :href="subItem.href || '#'">
                                            <component v-if="subItem.icon" :is="subItem.icon" />
                                            <span>{{ subItem.title }}</span>
                                        </Link>
                                    </SidebarMenuSubButton>
                                </SidebarMenuSubItem>
                            </SidebarMenuSub>
                        </CollapsibleContent>
                    </Collapsible>
                </template>
                
                <!-- Single items without subitems -->
                <template v-else>
                    <SidebarMenuButton as-child :is-active="item.href === page.url" :tooltip="item.title">
                        <Link :href="item.href || '#'">
                            <component :is="item.icon" />
                            <span>{{ item.title }}</span>
                        </Link>
                    </SidebarMenuButton>
                </template>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
