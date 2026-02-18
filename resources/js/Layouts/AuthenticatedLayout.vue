<script setup>
import { ref } from 'vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import { Link } from '@inertiajs/vue3';

const showingNavigationDropdown = ref(false);
</script>

<template>
    <div class="app-page">
        <div class="mx-auto w-full max-w-6xl px-4 pb-8 pt-4 sm:px-6 lg:px-8">
            <nav class="app-card animate-rise mb-5 px-4 py-3 sm:px-5">
                <div class="flex items-center justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <Link
                            :href="route('dashboard')"
                            class="app-brand rounded-full border border-teal-200/70 bg-teal-50/90 px-4 py-2 text-xs text-teal-800"
                        >
                            Dandash
                        </Link>

                        <div class="hidden items-center gap-2 sm:flex">
                            <NavLink
                                :href="route('dashboard')"
                                :active="route().current('dashboard')"
                            >
                                Списки
                            </NavLink>
                            <NavLink
                                :href="route('profile.edit')"
                                :active="route().current('profile.edit')"
                            >
                                Аккаунт
                            </NavLink>
                        </div>
                    </div>

                    <div class="hidden items-center sm:flex">
                        <Dropdown align="right" width="48">
                            <template #trigger>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-2 rounded-full border border-slate-200/80 bg-white/80 px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:bg-white"
                                >
                                    <span class="max-w-32 truncate">
                                        {{ $page.props.auth.user.name }}
                                    </span>

                                    <svg
                                        class="h-4 w-4"
                                        xmlns="http://www.w3.org/2000/svg"
                                        viewBox="0 0 20 20"
                                        fill="currentColor"
                                    >
                                        <path
                                            fill-rule="evenodd"
                                            d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                            clip-rule="evenodd"
                                        />
                                    </svg>
                                </button>
                            </template>

                            <template #content>
                                <DropdownLink :href="route('profile.edit')">
                                    Профиль
                                </DropdownLink>
                                <DropdownLink
                                    :href="route('logout')"
                                    method="post"
                                    as="button"
                                >
                                    Выйти
                                </DropdownLink>
                            </template>
                        </Dropdown>
                    </div>

                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-xl border border-slate-200/80 bg-white/70 p-2 text-slate-500 transition hover:border-slate-300 hover:bg-white sm:hidden"
                        @click="showingNavigationDropdown = !showingNavigationDropdown"
                    >
                        <svg
                            class="h-6 w-6"
                            stroke="currentColor"
                            fill="none"
                            viewBox="0 0 24 24"
                        >
                            <path
                                :class="{ hidden: showingNavigationDropdown, 'inline-flex': !showingNavigationDropdown }"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M4 6h16M4 12h16M4 18h16"
                            />
                            <path
                                :class="{ hidden: !showingNavigationDropdown, 'inline-flex': showingNavigationDropdown }"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M6 18L18 6M6 6l12 12"
                            />
                        </svg>
                    </button>
                </div>

                <div
                    v-if="showingNavigationDropdown"
                    class="mt-3 space-y-3 border-t border-slate-200/80 pt-3 sm:hidden"
                >
                    <ResponsiveNavLink
                        :href="route('dashboard')"
                        :active="route().current('dashboard')"
                    >
                        Списки
                    </ResponsiveNavLink>
                    <ResponsiveNavLink
                        :href="route('profile.edit')"
                        :active="route().current('profile.edit')"
                    >
                        Аккаунт
                    </ResponsiveNavLink>

                    <div class="space-y-1 border-t border-slate-200/80 pt-3">
                        <div class="px-1 text-sm font-semibold text-slate-700">
                            {{ $page.props.auth.user.name }}
                        </div>
                        <div class="px-1 text-xs text-slate-500">
                            {{ $page.props.auth.user.email }}
                        </div>

                        <ResponsiveNavLink :href="route('logout')" method="post" as="button">
                            Выйти
                        </ResponsiveNavLink>
                    </div>
                </div>
            </nav>

            <header
                v-if="$slots.header"
                class="app-card animate-pop mb-5 px-5 py-4"
            >
                <slot name="header" />
            </header>

            <main>
                <slot />
            </main>
        </div>
    </div>
</template>
