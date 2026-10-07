import { onBeforeUnmount, ref, type ShallowRef } from "vue";

/** 横にはみ出す表をマウスでつかんで移動する。タッチ操作はブラウザーに任せる。 */
export function useHorizontalDragScroll(scrollBox: Readonly<ShallowRef<HTMLElement | null>>, onDrag?: () => void) {
    const isDragging = ref(false);
    let startX = 0;
    let startScrollLeft = 0;
    let pointerId: number | null = null;

    function onPointerDown(event: PointerEvent): void {
        const element = scrollBox.value;
        if (!element || event.pointerType !== "mouse" || event.button !== 0 || element.scrollWidth <= element.clientWidth) return;
        if ((event.target as Element).closest("button, a, input, select, textarea")) return;
        const bounds = element.getBoundingClientRect();
        if (event.clientX >= bounds.left + element.clientWidth || event.clientY >= bounds.top + element.clientHeight) return;
        pointerId = event.pointerId;
        startX = event.clientX;
        startScrollLeft = element.scrollLeft;
    }

    function onPointerMove(event: PointerEvent): void {
        const element = scrollBox.value;
        if (!element || event.pointerId !== pointerId) return;
        const distance = event.clientX - startX;
        if (!isDragging.value) {
            if (Math.abs(distance) < 4) return;
            element.setPointerCapture(event.pointerId);
            isDragging.value = true;
            onDrag?.();
        }
        event.preventDefault();
        element.scrollLeft = startScrollLeft - distance;
    }

    function onPointerEnd(event: PointerEvent): void {
        if (event.pointerId !== pointerId) return;
        const element = scrollBox.value;
        pointerId = null;
        isDragging.value = false;
        if (element?.hasPointerCapture(event.pointerId)) element.releasePointerCapture(event.pointerId);
    }

    function onPointerLeave(event: PointerEvent): void {
        if (!isDragging.value) onPointerEnd(event);
    }

    onBeforeUnmount(() => {
        if (pointerId !== null && scrollBox.value?.hasPointerCapture(pointerId)) scrollBox.value.releasePointerCapture(pointerId);
    });

    return { isDragging, onPointerDown, onPointerMove, onPointerEnd, onPointerLeave };
}
