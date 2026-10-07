import { describe, expect, it, vi } from "vitest";
import { mount } from "@vue/test-utils";
import { defineComponent, h, shallowRef } from "vue";
import { useHorizontalDragScroll } from "./useHorizontalDragScroll";

function setup(width = 1200) {
    const element = document.createElement("div");
    Object.defineProperties(element, { scrollWidth: { value: width }, clientWidth: { value: 600 }, clientHeight: { value: 400 } });
    element.setPointerCapture = vi.fn();
    element.hasPointerCapture = vi.fn(() => true);
    element.releasePointerCapture = vi.fn();
    const hideTooltip = vi.fn();
    let drag!: ReturnType<typeof useHorizontalDragScroll>;
    const wrapper = mount(
        defineComponent({
            setup() {
                drag = useHorizontalDragScroll(shallowRef(element), hideTooltip);
                return () => h("div");
            },
        }),
    );
    function pointer(x: number, overrides: Partial<PointerEvent> = {}) {
        return { pointerId: 1, pointerType: "mouse", button: 0, clientX: x, clientY: 20, target: element, preventDefault: vi.fn(), ...overrides } as unknown as PointerEvent;
    }
    return { element, hideTooltip, drag, wrapper, pointer };
}

describe("useHorizontalDragScroll", () => {
    it("drags horizontally from the existing position and stops on release", () => {
        const { element, hideTooltip, drag, wrapper, pointer } = setup();
        element.scrollLeft = 150;
        drag.onPointerDown(pointer(200));
        drag.onPointerMove(pointer(198));
        expect(element.scrollLeft).toBe(150);
        expect(hideTooltip).not.toHaveBeenCalled();
        drag.onPointerMove(pointer(100));
        expect(element.scrollLeft).toBe(250);
        expect(drag.isDragging.value).toBe(true);
        expect(hideTooltip).toHaveBeenCalledOnce();
        drag.onPointerEnd(pointer(100));
        drag.onPointerMove(pointer(50));
        expect(element.scrollLeft).toBe(250);
        expect(drag.isDragging.value).toBe(false);
        expect(element.releasePointerCapture).toHaveBeenCalledWith(1);
        wrapper.unmount();
    });

    it.each(["touch", "right button", "no overflow", "interactive target"])("leaves %s interactions alone", (kind) => {
        const { element, drag, wrapper, pointer } = setup(kind === "no overflow" ? 600 : 1200);
        drag.onPointerDown(
            pointer(200, {
                pointerType: kind === "touch" ? "touch" : "mouse",
                button: kind === "right button" ? 2 : 0,
                target: kind === "interactive target" ? document.createElement("button") : element,
            }),
        );
        drag.onPointerMove(pointer(100));
        expect(element.scrollLeft).toBe(0);
        expect(drag.isDragging.value).toBe(false);
        wrapper.unmount();
    });

    it("releases capture on cancellation and unmount", () => {
        const { element, drag, wrapper, pointer } = setup();
        drag.onPointerDown(pointer(200));
        drag.onPointerMove(pointer(100));
        drag.onPointerEnd(pointer(100));
        expect(drag.isDragging.value).toBe(false);
        drag.onPointerDown(pointer(200));
        drag.onPointerMove(pointer(100));
        wrapper.unmount();
        expect(element.releasePointerCapture).toHaveBeenCalledTimes(2);
    });
});
