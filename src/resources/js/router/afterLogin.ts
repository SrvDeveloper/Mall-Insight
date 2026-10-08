import type { RouteLocationNormalizedLoaded, RouteLocationRaw } from "vue-router";

/** ログインした後に戻る画面。ログイン画面の redirect に、このアプリの中のパスがあればそこへ戻る（B-009）。 */
export function afterLoginLocation(route: RouteLocationNormalizedLoaded): RouteLocationRaw {
    const redirect = route.query.redirect;
    return typeof redirect === "string" && redirect.startsWith("/") && !redirect.startsWith("//") ? redirect : "/";
}
