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
    'mt-2 h-2 w-full shrink-0 cursor-nwse-resize touch-none select-none rounded-full bg-input-border transition-colors hover:bg-text-quaternary';

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

export function useResizableDropdown() {
    const size = useStorage<DropdownSize>(RESIZABLE_DROPDOWN_STORAGE_KEY, AUTO_SIZE);
    const panel = ref<HTMLElement | null>(null);
    const { width: windowWidth, height: windowHeight } = useWindowSize();

    // Never let a stored size push the popover off screen, even if the window shrank since.
    const maxWidth = computed(() =>
        Math.max(MIN_WIDTH, Math.min(MAX_WIDTH, (windowWidth.value || MAX_WIDTH) - 48))
    );
    const maxHeight = computed(() =>
        Math.max(MIN_HEIGHT, Math.min(MAX_HEIGHT, (windowHeight.value || MAX_HEIGHT) - 140))
    );

    /**
     * `maxWidth`/`maxHeight` are reset because the panel keeps its Tailwind caps for the default
     * (never resized) state – without that reset those caps would silently clamp the panel back.
     */
    const resizablePanelStyle = computed<CSSProperties>(() => {
        const style: CSSProperties = {};
        if (size.value.width > 0) {
            style.width = `${clamp(size.value.width, MIN_WIDTH, maxWidth.value)}px`;
            style.maxWidth = 'none';
        }
        if (size.value.height > 0) {
            style.height = `${clamp(size.value.height, MIN_HEIGHT, maxHeight.value)}px`;
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
                width: clamp(startWidth + moveEvent.clientX - startX, MIN_WIDTH, maxWidth.value),
                height: clamp(
                    startHeight + moveEvent.clientY - startY,
                    MIN_HEIGHT,
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

    const resizeHandleProps = {
        class: HANDLE_CLASS,
        title: 'Drag to resize',
        'aria-hidden': true,
        onPointerdown,
    };

    return { setResizablePanel, resizablePanelStyle, resizeHandleProps };
}
