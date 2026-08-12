(function () {
"use strict";

const loadScript = (id, source) => {
    const existing = document.getElementById(id);
    if (existing) {
        if (existing.dataset.loaded === "1") return Promise.resolve();
        return new Promise((resolve, reject) => {
            existing.addEventListener("load", resolve, { once: true });
            existing.addEventListener("error", reject, { once: true });
        });
    }
    return new Promise((resolve, reject) => {
        const script = document.createElement("script");
        script.id = id;
        script.src = source;
        script.async = true;
        script.addEventListener("load", () => { script.dataset.loaded = "1"; resolve(); }, { once: true });
        script.addEventListener("error", reject, { once: true });
        document.head.appendChild(script);
    });
};

function highlightRawMarkdown(root = document) {
    if (!window.hljs?.highlight) return;
    const scope = root?.querySelectorAll ? root : document;
    scope.querySelectorAll(".mdpaste-raw-markdown code:not([data-mdpaste-highlighted])").forEach(code => {
        const source = code.textContent || "";
        const frontMatter = source.match(/^---[ \t]*\r?\n[\s\S]*?\r?\n---[ \t]*(?:\r?\n|$)/)?.[0] || "";
        const body = source.slice(frontMatter.length);
        const highlightedFrontMatter = frontMatter && window.hljs.getLanguage?.("yaml")
            ? window.hljs.highlight(frontMatter, { language: "yaml", ignoreIllegals: true }).value
            : frontMatter.replace(/[&<>]/g, character => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;" })[character]);
        const highlightedBody = window.hljs.highlight(body, { language: "markdown", ignoreIllegals: true }).value;
        code.innerHTML = highlightedFrontMatter + highlightedBody;
        code.classList.remove("nohighlight");
        code.classList.add("hljs", "language-markdown");
        code.dataset.mdpasteHighlighted = "1";
    });
}

async function enhanceMdpaste(root = document) {
    const scope = root?.querySelectorAll ? root : document;
    if (typeof window.initInlineMediaPlayers === "function") window.initInlineMediaPlayers(scope);
    if (typeof window.initTooltips === "function") window.initTooltips();

    const math = scope.querySelectorAll(".mdpaste-math:not([data-mdpaste-rendered])");
    if (math.length) {
        window.MathJax = window.MathJax || {
            tex: { inlineMath: [["\\(", "\\)"]], displayMath: [["\\[", "\\]"]] },
            options: { skipHtmlTags: ["script", "noscript", "style", "textarea", "pre", "code"] }
        };
        try {
            await loadScript("mdpaste-mathjax", "https://cdn.jsdelivr.net/npm/mathjax@4/tex-mml-chtml.js");
            await window.MathJax?.typesetPromise?.([scope]);
            math.forEach(node => { node.dataset.mdpasteRendered = "1"; });
        } catch (_) { /* Keep readable TeX when the renderer is unavailable. */ }
    }

    const diagrams = scope.querySelectorAll(".mdpaste-mermaid:not([data-processed])");
    if (diagrams.length) {
        try {
            await loadScript("mdpaste-mermaid-library", "https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js");
            if (window.mermaid && !window.mdpasteMermaidInitialized) {
                window.mermaid.initialize({
                    startOnLoad: false,
                    securityLevel: "strict",
                    theme: "dark",
                    themeVariables: { fontFamily: "MainRegular, monospace" }
                });
                window.mdpasteMermaidInitialized = true;
            }
            await window.mermaid?.run?.({ nodes: diagrams, suppressErrors: true });
        } catch (_) { /* Keep the source readable when the renderer is unavailable. */ }
    }
}

function initPasteActions(root = document) {
    const scope = root?.querySelector ? root : document;
    const sourceData = scope.querySelector("#mdpaste-source-data");
    const downloadButton = scope.querySelector("#mdpaste-download");
    const fontButton = scope.querySelector("#mdpaste-font-toggle");
    const toggleButton = scope.querySelector("#mdpaste-format-toggle");
    const formatted = scope.querySelector("#mdpaste-formatted");
    const raw = scope.querySelector("#mdpaste-raw");
    const pasteView = scope.querySelector(".mdpaste-view");
    if (!sourceData) {
        if (root !== document) document.body.classList.remove("mdpaste-shared-page");
        return;
    }
    let payload = {};
    try { payload = JSON.parse(sourceData.textContent || "{}"); } catch (_) { return; }
    if (!payload.preserveLayout) document.body.classList.add("mdpaste-shared-page");

    if (downloadButton && downloadButton.dataset.mdpasteBound !== "1") {
        downloadButton.dataset.mdpasteBound = "1";
        downloadButton.addEventListener("click", () => {
            const blob = new Blob([String(payload.markdown || "")], { type: "text/markdown;charset=utf-8" });
            const url = URL.createObjectURL(blob);
            const anchor = document.createElement("a");
            anchor.href = url;
            anchor.download = String(payload.filename || "mdpaste.md");
            document.body.appendChild(anchor);
            anchor.click();
            anchor.remove();
            window.setTimeout(() => URL.revokeObjectURL(url), 0);
        });
    }

    if (fontButton && pasteView && fontButton.dataset.mdpasteBound !== "1") {
        fontButton.dataset.mdpasteBound = "1";
        fontButton.addEventListener("click", () => {
            const useFira = pasteView.classList.toggle("mdpaste-fira-font");
            fontButton.setAttribute("aria-pressed", String(useFira));
            fontButton.setAttribute("aria-label", "toggle serif font");
            fontButton.setAttribute("data-tooltip", "toggle serif font");
        });
    }

    if (toggleButton && formatted && raw && toggleButton.dataset.mdpasteBound !== "1") {
        toggleButton.dataset.mdpasteBound = "1";
        toggleButton.addEventListener("click", () => {
            const showRaw = raw.hidden;
            raw.hidden = !showRaw;
            formatted.hidden = showRaw;
            toggleButton.setAttribute("aria-pressed", String(showRaw));
            toggleButton.setAttribute("aria-label", showRaw ? "toggle formatting" : "toggle formatting");
            toggleButton.setAttribute("data-tooltip", showRaw ? "toggle formatting" : "toggle formatting");
            const icon = toggleButton.querySelector("i");
            if (icon) icon.className = showRaw ? "fa-solid fa-eye" : "fa-solid fa-code";
        });
    }
}

window.fridg3RenderMdpasteEnhancements = enhanceMdpaste;
window.fridg3InitMdpasteView = async function (root = document) {
    initPasteActions(root);
    highlightRawMarkdown(root);
    await enhanceMdpaste(root);
};
window.fridg3InitMdpasteView(document);
if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => highlightRawMarkdown(document), { once: true });
}
})();
