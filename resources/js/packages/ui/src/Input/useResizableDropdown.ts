import { useStorage, useWindowSize } from '@vueuse/core';
import { computed, ref, type ComponentPublicInstance, type CSSProperties } from 'vue';

/**
 * Makes the scrollable result panel of a dropdown resizable by dragging a handle below it.
 *
 * The size is shared by every dropdown that uses this composable and persisted in localStorage,
 * so long entries (for example project names like "01568-33809 - PO233033 - 12pcs QT9.50") stay
 * readable across reloads instead of being truncated by the default panel width.
 *
 * All the logic lives here so that the shared component only needs a handful of changed lines.
 */
export const RESIZABLE_DROPDOWN_STORAGE_KEY = 'multiselect-dropdown-size';
export const projectPickerStorageKey = (userId: string) => `project-picker-size:${userId}`;

const MIN_WIDTH = 240;
const MAX_WIDTH = 1000;
const MIN_HEIGHT = 120;
const MAX_HEIGHT = 800;

type DropdownSize = {
    width: number;
    height: number;
};

/** `0` means "never resized" – the panel then keeps the size defined by its CSS classes. */
const AUTO_SIZE: DropdownSize = { width: 0, height: 0 };

/** Always visible so the panel advertises that it can be dragged wider and taller. */
const HANDLE_CLASS =
    'ml-auto flex size-11 shrink-0 cursor-nwse-resize touch-none select-none items-end justify-end rounded-br-lg p-2 after:block after:size-3 after:border-b-2 after:border-r-2 after:border-input-border hover:after:border-text-quaternary';

function clamp(value: number, min: number, max: number): number {
    return Math.min(Math.max(value, min), max);
}

function resolveElement(target: Element | ComponentPublicInstance | null): HTMLElement | null {
    const candidate: unknown = target && '$el' in target ? target.$el : target;
    if (candidate instanceof HTMLElement) {
        return candidate;
    }
    // Components with a fragment root expose their leading anchor node instead of the element.
    if (candidate instanceof Text && candidate.nextElementSibling instanceof HTMLElement) {
        return candidate.nextElementSibling;
    }
    return null;
}

export function useResizableDropdown(storageKey = RESIZABLE_DROPDOWN_STORAGE_KEY) {
    const size = useStorage<DropdownSize>(storageKey, AUTO_SIZE);
    const panel = ref<HTMLElement | null>(null);
    const { width: windowWidth, height: windowHeight } = useWindowSize();

    // Never let a stored size push the popover off screen, even if the window shrank since.
    const maxWidth = computed(() =>
        Math.max(1, Math.min(MAX_WIDTH, (windowWidth.value || MAX_WIDTH) - 48))
    );
    const maxHeight = computed(() =>
        Math.max(1, Math.min(MAX_HEIGHT, (windowHeight.value || MAX_HEIGHT) - 140))
    );
    const minWidth = computed(() => Math.min(MIN_WIDTH, maxWidth.value));
    const minHeight = computed(() => Math.min(MIN_HEIGHT, maxHeight.value));

    /**
     * `maxWidth`/`maxHeight` are reset because the panel keeps its Tailwind caps for the default
     * (never resized) state – without that reset those caps would silently clamp the panel back.
     */
    const resizablePanelStyle = computed<CSSProperties>(() => {
        const style: CSSProperties = {};
        if (Number.isFinite(size.value?.width) && size.value.width > 0) {
            style.width = `${clamp(size.value.width, minWidth.value, maxWidth.value)}px`;
            style.maxWidth = 'none';
        }
        if (Number.isFinite(size.value?.height) && size.value.height > 0) {
            style.height = `${clamp(size.value.height, minHeight.value, maxHeight.value)}px`;
            style.maxHeight = 'none';
        }
        return style;
    });

    function setResizablePanel(target: Element | ComponentPublicInstance | null) {
        panel.value = resolveElement(target);
    }

    function onPointerdown(event: PointerEvent) {
        const element = panel.value;
        const target = event.currentTarget;
        if (!element || !(target instanceof HTMLElement) || event.button !== 0) {
            return;
        }
        const handle: HTMLElement = target;
        event.preventDefault();
        event.stopPropagation();

        const startX = event.clientX;
        const startY = event.clientY;
        const startWidth = element.offsetWidth;
        const startHeight = element.offsetHeight;

        function onPointermove(moveEvent: PointerEvent) {
            size.value = {
                width: clamp(
                    startWidth + moveEvent.clientX - startX,
                    minWidth.value,
                    maxWidth.value
                ),
                height: clamp(
                    startHeight + moveEvent.clientY - startY,
                    minHeight.value,
                    maxHeight.value
                ),
            };
        }

        function onPointerup() {
            handle.removeEventListener('pointermove', onPointermove);
            handle.removeEventListener('pointerup', onPointerup);
            handle.removeEventListener('pointercancel', onPointerup);
            if (handle.hasPointerCapture(event.pointerId)) {
                handle.releasePointerCapture(event.pointerId);
            }
        }

        handle.setPointerCapture(event.pointerId);
        handle.addEventListener('pointermove', onPointermove);
        handle.addEventListener('pointerup', onPointerup);
        handle.addEventListener('pointercancel', onPointerup);
    }

    function onKeydown(event: KeyboardEvent) {
        const element = panel.value;
        if (!element || !['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) {
            return;
        }
        event.preventDefault();

        const storedWidth = Number.isFinite(size.value?.width) ? size.value.width : 0;
        const storedHeight = Number.isFinite(size.value?.height) ? size.value.height : 0;
        const width = storedWidth > 0 ? storedWidth : element.offsetWidth;
        const height = storedHeight > 0 ? storedHeight : element.offsetHeight;
        const widthDelta = event.key === 'ArrowLeft' ? -20 : event.key === 'ArrowRight' ? 20 : 0;
        const heightDelta = event.key === 'ArrowUp' ? -20 : event.key === 'ArrowDown' ? 20 : 0;

        size.value = {
            width: clamp(width + widthDelta, minWidth.value, maxWidth.value),
            height: clamp(height + heightDelta, minHeight.value, maxHeight.value),
        };
    }

    const resizeHandleProps = {
        class: HANDLE_CLASS,
        title: 'Drag or use arrow keys to resize',
        role: 'button',
        tabindex: 0,
        'aria-label': 'Resize dropdown',
        onPointerdown,
        onKeydown,
    };

    return { setResizablePanel, resizablePanelStyle, resizeHandleProps };
}
