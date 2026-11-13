<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';

// A dropdown drawn in the console's own look (the browser's is not styleable
// once open). It behaves like a select: a label's `for` reaches it, arrows,
// Home/End, Enter, Escape and typing a letter all work, and it speaks as a
// listbox to a screen reader.
const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    // [{ value, label, disabled? }] — or plain strings
    options: { type: Array, required: true },
    id: { type: String, default: undefined },
    small: { type: Boolean, default: false }, // the compact one beside a table
    fit: { type: Boolean, default: false },   // as wide as its text, not its container
    disabled: { type: Boolean, default: false },
    label: { type: String, default: undefined }, // when there is no visible <label>
});
const emit = defineEmits(['update:modelValue', 'change']);

const uid = 'sb-' + Math.random().toString(36).slice(2, 8);
const items = computed(() => props.options.map((o) => (typeof o === 'object' ? o : { value: o, label: String(o) })));
const selected = computed(() => items.value.find((o) => String(o.value) === String(props.modelValue)));

const open = ref(false);
const active = ref(-1);
const button = ref(null);
const list = ref(null);
const place = ref({});

function position() {
    const box = button.value.getBoundingClientRect();
    const height = Math.min(items.value.length * 44 + 2, 320);
    const below = window.innerHeight - box.bottom;
    const up = below < height + 8 && box.top > below; // not enough room below: open upwards
    place.value = {
        left: Math.max(8, Math.min(box.left, window.innerWidth - Math.max(box.width, 180) - 8)) + 'px',
        minWidth: box.width + 'px',
        maxHeight: Math.max(132, Math.min(320, (up ? box.top : below) - 12)) + 'px',
        ...(up ? { bottom: window.innerHeight - box.top + 4 + 'px' } : { top: box.bottom + 4 + 'px' }),
    };
}

function show() {
    if (props.disabled || open.value) return;
    position();
    open.value = true;
    active.value = Math.max(0, items.value.findIndex((o) => String(o.value) === String(props.modelValue)));
    window.addEventListener('scroll', onScroll, true);
    window.addEventListener('resize', close);
    document.addEventListener('pointerdown', outside, true);
    document.addEventListener('keydown', onEscape, true);
    nextTick(reveal);
}
function close() {
    if (!open.value) return;
    open.value = false;
    window.removeEventListener('scroll', onScroll, true);
    window.removeEventListener('resize', close);
    document.removeEventListener('pointerdown', outside, true);
    document.removeEventListener('keydown', onEscape, true);
}
// The page moved under the list: close it. Scrolling the list itself is not that.
const onScroll = (event) => !(list.value && list.value.contains(event.target)) && close();
// Escape closes it wherever the focus is (Safari does not focus a button that was clicked).
function onEscape(event) {
    if (event.key !== 'Escape') return;
    event.preventDefault();
    event.stopPropagation();
    close();
}
const outside = (event) => !button.value?.contains(event.target) && !list.value?.contains(event.target) && close();
const reveal = () => list.value?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' });

function choose(option) {
    if (!option || option.disabled) return;
    close();
    button.value?.focus();
    if (String(option.value) !== String(props.modelValue)) {
        emit('update:modelValue', option.value);
        emit('change', option.value);
    }
}

function move(step, from = active.value) {
    const n = items.value.length;
    for (let i = 1; i <= n; i++) {
        const next = (from + step * i + n * i) % n;
        if (!items.value[next].disabled) {
            active.value = next;
            return nextTick(reveal);
        }
    }
}

let typed = '';
let typedAt = 0;
function onKey(event) {
    const { key } = event;
    if (key === 'Tab') return close();
    if (['ArrowDown', 'ArrowUp', 'Home', 'End', 'Enter', ' '].includes(key)) {
        event.preventDefault();
        if (!open.value) return show();
        if (key === 'ArrowDown') return move(1);
        if (key === 'ArrowUp') return move(-1);
        if (key === 'Home') return move(1, -1);
        if (key === 'End') return move(-1, items.value.length);
        return choose(items.value[active.value]);
    }
    if (key.length === 1) {
        // Type the first letters of an option to jump to it.
        typed = Date.now() - typedAt > 700 ? key : typed + key;
        typedAt = Date.now();
        const match = items.value.findIndex((o) => !o.disabled && o.label.toLowerCase().startsWith(typed.toLowerCase()));
        if (match >= 0) {
            if (open.value) {
                active.value = match;
                nextTick(reveal);
            } else choose(items.value[match]);
        }
    }
}

onBeforeUnmount(close);
</script>

<template>
    <div class="relative" :class="fit ? 'inline-block' : 'block w-full'">
        <button
            :id="id"
            ref="button"
            type="button"
            role="combobox"
            aria-haspopup="listbox"
            :aria-expanded="open"
            :aria-controls="uid"
            :aria-activedescendant="open && active >= 0 ? `${uid}-${active}` : undefined"
            :aria-label="label"
            :disabled="disabled"
            class="flex w-full items-center justify-between gap-3 border bg-white text-left text-ink disabled:cursor-not-allowed disabled:opacity-60"
            :class="[small ? 'min-h-10 px-3 py-1.5 text-sm' : 'min-h-11 px-3 py-2 text-base', open ? 'border-ink' : 'border-[#8a8a8a] hover:border-ink']"
            @click="open ? close() : show()"
            @keydown="onKey"
        >
            <span class="min-w-0 truncate">{{ selected ? selected.label : '' }}</span>
            <svg aria-hidden="true" class="size-4 flex-none transition-transform" :class="open ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 9l7 7 7-7" /></svg>
        </button>

        <Teleport to="body">
            <ul
                v-if="open"
                :id="uid"
                ref="list"
                role="listbox"
                :aria-labelledby="id"
                class="fixed z-[60] overflow-y-auto border border-ink bg-white py-1 text-ink shadow-[0_18px_40px_rgba(0,0,0,0.22)]"
                :class="small ? 'text-sm' : 'text-[15px]'"
                :style="place"
            >
                <li
                    v-for="(option, i) in items"
                    :id="`${uid}-${i}`"
                    :key="String(option.value)"
                    role="option"
                    :aria-selected="String(option.value) === String(modelValue)"
                    :aria-disabled="option.disabled || undefined"
                    :data-active="i === active"
                    class="flex min-h-11 cursor-pointer items-center gap-2.5 border-l-4 px-3 py-2"
                    :class="[
                        option.disabled ? 'cursor-not-allowed text-[#8a8a8a]' : '',
                        String(option.value) === String(modelValue) ? 'border-gold font-semibold' : 'border-transparent',
                        i === active && !option.disabled ? 'bg-[#fff3d1]' : '',
                    ]"
                    @click="choose(option)"
                    @mousemove="!option.disabled && (active = i)"
                >
                    <span class="min-w-0 flex-1">{{ option.label }}</span>
                    <svg v-if="String(option.value) === String(modelValue)" aria-hidden="true" class="size-4 flex-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 12l5 5 9-10" /></svg>
                </li>
            </ul>
        </Teleport>
    </div>
</template>
