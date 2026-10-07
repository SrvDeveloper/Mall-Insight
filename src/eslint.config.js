import js from "@eslint/js";
import vue from "eslint-plugin-vue";
import tseslint from "typescript-eslint";
import prettier from "eslint-config-prettier";

const browserGlobals = {
    window: "readonly",
    document: "readonly",
    console: "readonly",
    navigator: "readonly",
    localStorage: "readonly",
    sessionStorage: "readonly",
    fetch: "readonly",
    setTimeout: "readonly",
    clearTimeout: "readonly",
    setInterval: "readonly",
    clearInterval: "readonly",
    MouseEvent: "readonly",
    KeyboardEvent: "readonly",
    HTMLElement: "readonly",
    HTMLInputElement: "readonly",
    HTMLButtonElement: "readonly",
    HTMLDivElement: "readonly",
    requestAnimationFrame: "readonly",
    cancelAnimationFrame: "readonly",
    Event: "readonly",
    File: "readonly",
    FormData: "readonly",
};

export default tseslint.config(
    {
        ignores: ["vendor/**", "node_modules/**", "public/build/**"],
    },
    js.configs.recommended,
    ...tseslint.configs.recommended,
    ...vue.configs["flat/recommended"],
    {
        languageOptions: {
            globals: browserGlobals,
        },
    },
    {
        files: ["**/*.vue"],
        languageOptions: {
            parserOptions: {
                parser: tseslint.parser,
            },
        },
    },
    prettier,
    {
        rules: {
            "vue/multi-word-component-names": "off",
            "vue/require-default-prop": "off",
        },
    },
);
