import { nextTick, onBeforeUnmount, shallowReactive, type ShallowRef } from "vue";

/** 意図せず通り過ぎただけで出ないよう、少しとどまってから表示する。 */
const TOOLTIP_DELAY_MS = 200;
const TOOLTIP_GAP_PX = 6;
const VIEWPORT_MARGIN_PX = 8;

/**
 * セルにマウスを乗せたとき（またはフォーカスしたとき）に出すツールチップ。セルの下に右端をそろえて出し、画面からはみ出すときは
 * 上に出し、左右は画面内に収める。表示する中身（content）は大きなデータのことがあるため、深く監視しない。
 */
export function useAnchoredTooltip<T>(tooltipElement: Readonly<ShallowRef<HTMLElement | null>>) {
    const tooltip = shallowReactive<{ content: T | null; left: number; top: number }>({ content: null, left: 0, top: 0 });
    let timer: ReturnType<typeof setTimeout> | null = null;

    function clearTimer(): void {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    }

    function show(event: Event, content: T, delay = TOOLTIP_DELAY_MS): void {
        clearTimer();
        const target = event.currentTarget as HTMLElement;
        timer = setTimeout(async () => {
            tooltip.content = content;
            const anchor = target.getBoundingClientRect();
            tooltip.left = anchor.right;
            tooltip.top = anchor.bottom + TOOLTIP_GAP_PX;
            await nextTick();
            const size = tooltipElement.value?.getBoundingClientRect();
            if (!size) {
                return;
            }
            tooltip.left = Math.min(Math.max(VIEWPORT_MARGIN_PX, anchor.right - size.width), window.innerWidth - size.width - VIEWPORT_MARGIN_PX);
            if (anchor.bottom + TOOLTIP_GAP_PX + size.height > window.innerHeight - VIEWPORT_MARGIN_PX) {
                tooltip.top = Math.max(VIEWPORT_MARGIN_PX, anchor.top - TOOLTIP_GAP_PX - size.height);
            }
        }, delay);
    }

    function hide(): void {
        clearTimer();
        tooltip.content = null;
    }

    onBeforeUnmount(clearTimer);

    return { tooltip, show, hide };
}
