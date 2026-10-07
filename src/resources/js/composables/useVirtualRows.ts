import { computed, nextTick, onBeforeUnmount, ref, watch, type Ref, type ShallowRef } from "vue";

/** 描く行の高さ（px）の既定値。見えている行だけを描くため、1つの表の行はすべて同じ高さにする。 */
export const VIRTUAL_ROW_HEIGHT = 37;
/** 見えている範囲の前後に余分に描く行数。速くスクロールしても空白が見えにくいようにする。 */
const OVERSCAN_ROWS = 15;
/** 枠の高さが測れないとき（テストなど）の高さ。 */
const FALLBACK_VIEWPORT_PX = 800;

/**
 * 大きな表で、スクロールする枠に見えている行（と前後の少し）だけを描く。行の高さはすべて rowHeight にそろえ、
 * 見えていない行の分は topSpacerHeight・bottomSpacerHeight の高さの空行で埋める（スクロールバーは全行を描いたときと同じになる）。
 * 需要予測（B-006）・在庫推移（B-008）の表で使う。
 */
export function useVirtualRows<T>(rows: Ref<T[]>, scrollBox: Readonly<ShallowRef<HTMLElement | null>>, onScrolled?: () => void, rowHeight = VIRTUAL_ROW_HEIGHT) {
    const scrollTop = ref(0);
    const viewportHeight = ref(FALLBACK_VIEWPORT_PX);
    let frame = 0;

    function measure(): void {
        const element = scrollBox.value;
        if (element) {
            scrollTop.value = element.scrollTop;
            viewportHeight.value = element.clientHeight || FALLBACK_VIEWPORT_PX;
        }
    }

    /** 枠の scroll イベントに渡す。1回の描画につき1回だけ測る。 */
    function onScroll(): void {
        onScrolled?.();
        if (frame === 0) {
            frame = requestAnimationFrame(() => {
                frame = 0;
                measure();
            });
        }
    }

    watch(scrollBox, () => nextTick(measure));
    onBeforeUnmount(() => cancelAnimationFrame(frame));

    const range = computed(() => {
        const start = Math.max(0, Math.floor(scrollTop.value / rowHeight) - OVERSCAN_ROWS);
        const end = Math.min(rows.value.length, start + Math.ceil(viewportHeight.value / rowHeight) + OVERSCAN_ROWS * 2);
        return { start, end };
    });

    return {
        onScroll,
        renderedRows: computed(() => rows.value.slice(range.value.start, range.value.end)),
        topSpacerHeight: computed(() => range.value.start * rowHeight),
        bottomSpacerHeight: computed(() => (rows.value.length - range.value.end) * rowHeight),
    };
}
