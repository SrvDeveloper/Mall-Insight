/** 販売目標の画面（B-110）で使う表示の整え方。 */

const quantityFormat = new Intl.NumberFormat("ja-JP", { maximumFractionDigits: 0 });
export const formatQuantity = (value: number): string => quantityFormat.format(Math.round(value));
export const monthLabel = (month: string): string => `${Number(month.slice(5, 7))}月`;
/** 1万分率を「7.05」のような百分率の文字列にする。 */
export const formatRatio = (value: number): string => (value / 100).toFixed(2);

const dateTimeFormat = new Intl.DateTimeFormat("ja-JP", { timeZone: "Asia/Tokyo", year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit" });
export const formatDateTime = (value: string): string => dateTimeFormat.format(new Date(value));

/** 入力欄の値（type="number" の v-model は数値、空欄は ""）を、0以上の整数か null にする。 */
export function toQuantity(value: number | string | null | undefined): number | null {
    if (value === null || value === undefined || String(value).trim() === "") {
        return null;
    }
    const number = Number(value);
    return Number.isFinite(number) && number >= 0 ? Math.round(number) : null;
}

/** 金額を千円単位にする（表の欄に収めるため）。 */
export const formatThousandYen = (value: number): string => quantityFormat.format(Math.round(value / 1000));

/** 合計などの大きな金額を「1億3,434万円」「5,520万円」のようにする。 */
export function formatLargeYen(value: number): string {
    const man = Math.round(value / 10000);
    if (man >= 10000) {
        const oku = Math.floor(man / 10000);
        const rest = man % 10000;
        return rest === 0 ? `${oku}億円` : `${oku}億${quantityFormat.format(rest)}万円`;
    }
    return `${quantityFormat.format(man)}万円`;
}

export const formatYen = (value: number): string => `${quantityFormat.format(Math.round(value))}円`;
