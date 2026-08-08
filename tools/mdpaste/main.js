(function () {
"use strict";

const debugLog = message => window.fridg3DebugClientLog?.(`[mdpaste] ${message}`);
const fileInput = document.getElementById("markdown-file");
const uploadButton = document.getElementById("markdown-upload-button");
const markdownInput = document.getElementById("markdown-input");
const preview = document.getElementById("markdown-preview");
const previewToggle = document.getElementById("markdown-preview-toggle");
const formattingActions = document.getElementById("markdown-formatting-actions");
const form = document.getElementById("mdpaste-form");
const passwordInput = document.getElementById("password-input");
const statusLine = document.getElementById("status-line");
const resultBox = document.getElementById("result-box");
const shareLink = document.getElementById("share-link");
const copyButton = document.getElementById("copy-link-btn");
const createButton = form?.querySelector('button[type="submit"]');
const hardBreaksInput = document.getElementById("hard-breaks-input");
let previewOpen = false;

if (fileInput && markdownInput && preview && previewToggle) {
    fileInput.addEventListener("change", handleFileUpload);
    uploadButton?.addEventListener("click", () => fileInput.click());
    previewToggle.addEventListener("click", togglePreview);
    formattingActions?.addEventListener("click", applyMarkdownFormatting);
    formattingActions?.addEventListener("change", applyMarkdownFormatting);
    hardBreaksInput?.addEventListener("change", refreshOpenPreview);
    form?.addEventListener("submit", createPaste);
    copyButton?.addEventListener("click", copyShareLink);
    debugLog("editor initialized");
}

function togglePreview() {
    previewOpen = !previewOpen;
    if (previewOpen) renderPreview();
    markdownInput.hidden = previewOpen;
    preview.hidden = !previewOpen;
    previewToggle.setAttribute("aria-pressed", String(previewOpen));
    previewToggle.classList.toggle("active", previewOpen);
    previewToggle.setAttribute("data-tooltip", previewOpen ? "return to editor" : "toggle preview");
    if (formattingActions) formattingActions.hidden = previewOpen;
    if (!previewOpen) markdownInput.focus();
    debugLog(previewOpen ? "preview opened" : "preview closed");
}

function applyMarkdownFormatting(event) {
    const control = event.target.closest("[data-markdown-action], [data-markdown-select]");
    if (!control || !formattingActions?.contains(control) || previewOpen) return;
    if (control.matches("select") && event.type !== "change") return;
    if (!control.matches("select") && event.type !== "click") return;
    const action = control.dataset.markdownAction || control.value;
    if (control.matches("select")) control.value = "";
    if (!action) return;
    const start = markdownInput.selectionStart;
    const end = markdownInput.selectionEnd;
    const selected = markdownInput.value.slice(start, end);
    if (action === "article-header") {
        const now = new Date();
        const date = [now.getFullYear(), String(now.getMonth() + 1).padStart(2, "0"), String(now.getDate()).padStart(2, "0")].join("-");
        const title = (selected.trim() || "Article title").replace(/\s+/g, " ").replace(/"/g, '\\"');
        if (selected) markdownInput.setRangeText("", start, end, "start");
        markdownInput.setSelectionRange(0, 0);
        const beforeTitle = '---\ntitle: "';
        const header = `${beforeTitle}${title}"\nauthor: "Author"\ndate: ${date}\ntags:\n  - tag\n---\n\n`;
        replaceEditorSelection(header, beforeTitle.length, title.length);
        return;
    }
    const wrappers = {
        bold: ["**", "**", "bold text"],
        italic: ["*", "*", "italic text"],
        underline: ["<u>", "</u>", "underlined text"],
        strikethrough: ["~~", "~~", "strikethrough text"],
        highlight: ["==", "==", "highlighted text"],
        code: ["`", "`", "code"]
    };
    if (wrappers[action]) {
        const [before, after, placeholder] = wrappers[action];
        replaceEditorSelection(before + (selected || placeholder) + after, before.length, (selected || placeholder).length);
        return;
    }
    if (action === "link") {
        const label = selected || "link text";
        replaceEditorSelection(`[${label}](https://example.com)`, 1, label.length);
        return;
    }
    if (action === "image") {
        const alt = selected || "image description";
        replaceEditorSelection(`![${alt}](https://example.com/image.png)`, 2, alt.length);
        return;
    }
    if (action === "code-block") {
        const code = selected || "code";
        replaceEditorSelection(`\n\`\`\`\n${code}\n\`\`\`\n`, 5, code.length);
        return;
    }
    if (action === "horizontal-rule") {
        replaceEditorSelection("\n---\n", 5, 0);
        return;
    }
    if (action === "table") {
        const table = "| heading | heading |\n| --- | --- |\n| cell | cell |";
        replaceEditorSelection(table, 2, 7);
        return;
    }
    const prefixes = {
        task: "- [ ] ",
        quote: "> "
    };
    const headingMatch = action.match(/^heading-([1-6])$/);
    const prefix = headingMatch ? "#".repeat(Number(headingMatch[1])) + " " : prefixes[action];
    if (!prefix) return;
    const lineStart = markdownInput.value.lastIndexOf("\n", start - 1) + 1;
    const nextLineBreak = markdownInput.value.indexOf("\n", end);
    const lineEnd = nextLineBreak === -1 ? markdownInput.value.length : nextLineBreak;
    const lines = markdownInput.value.slice(lineStart, lineEnd).split("\n");
    const replacement = lines.map(line => prefix + line).join("\n");
    markdownInput.setSelectionRange(lineStart, lineEnd);
    replaceEditorSelection(replacement, prefix.length, replacement.length - prefix.length);
}

function replaceEditorSelection(replacement, selectionOffset, selectionLength) {
    const start = markdownInput.selectionStart;
    const end = markdownInput.selectionEnd;
    markdownInput.setRangeText(replacement, start, end, "end");
    const selectionStart = start + selectionOffset;
    markdownInput.setSelectionRange(selectionStart, selectionStart + selectionLength);
    markdownInput.focus();
}

function refreshOpenPreview() {
    if (previewOpen) renderPreview();
}

async function renderPreview() {
    preview.innerHTML = "<p>rendering preview...</p>";
    try {
        const response = await fetch("/tools/mdpaste/", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                action: "preview",
                markdown: markdownInput.value,
                hardBreaks: !!hardBreaksInput?.checked
            })
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.ok) throw new Error(data.error || "could not render preview.");
        preview.innerHTML = data.html;
        if (typeof window.fridg3RenderMdpasteEnhancements === "function") await window.fridg3RenderMdpasteEnhancements(preview);
        if (typeof window.hljs !== "undefined") {
            preview.querySelectorAll("pre code").forEach(block => window.hljs.highlightElement(block));
        }
    } catch (error) {
        preview.textContent = error.message || "could not render preview.";
    }
}

function handleFileUpload(event) {
    const target = event.target;
    const file = target.files?.[0];
    if (!file) return;
    if (!/\.(md|txt)$/i.test(file.name)) {
        setStatus("only .md and .txt files are allowed.", true);
        target.value = "";
        return;
    }
    const reader = new FileReader();
    reader.onload = () => {
        markdownInput.value = String(reader.result || "");
        markdownInput.dispatchEvent(new Event("input", { bubbles: true }));
        refreshOpenPreview();
        setStatus("file loaded.", false);
        debugLog("local Markdown file loaded");
    };
    reader.onerror = () => {
        setStatus("could not read the file. try again.", true);
        target.value = "";
    };
    reader.readAsText(file, "utf-8");
}

async function createPaste(event) {
    event.preventDefault();
    const markdown = markdownInput.value || "";
    if (!markdown.trim()) {
        setStatus("error: empty file!", true);
        return;
    }
    const unsafeTags = findUnsupportedTags(markdown);
    if (unsafeTags.length && typeof window.showSitePopup === "function") {
        const elementName = unsafeTags.join(", ");
        const confirmed = await window.showSitePopup({
            title: "unsupported tags",
            detail: `Your paste contains ${elementName} tags, which aren't supported due to security issues.`,
            cancelText: "go back",
            okText: "upload anyway"
        });
        if (!confirmed) return;
    }
    if (createButton) {
        createButton.disabled = true;
        createButton.textContent = "creating...";
    }
    setStatus("saving paste...", false);
    try {
        const response = await fetch("/tools/mdpaste/", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                markdown,
                password: passwordInput?.value || "",
                hardBreaks: !!hardBreaksInput?.checked
            })
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.ok) throw new Error(data.error || "could not create paste.");
        if (shareLink && resultBox) {
            shareLink.value = new URL(data.url, window.location.origin).toString();
            resultBox.hidden = false;
            shareLink.focus();
            shareLink.select();
        }
        setStatus(data.encrypted ? "encrypted paste created. send the password separately." : "paste created.", false);
    } catch (error) {
        setStatus(error.message || "could not create paste.", true);
    } finally {
        if (createButton) {
            createButton.disabled = false;
            createButton.textContent = "create link";
        }
    }
}

function findUnsupportedTags(markdown) {
    const unsafe = new Set(["script", "iframe", "object", "embed", "form", "input", "button", "textarea", "select", "option", "style", "link", "meta", "base", "frame", "frameset", "svg"]);
    const found = new Set();
    String(markdown).replace(/<\/?([a-z][a-z0-9-]*)\b[^>]*>/gi, (_match, tag) => {
        const name = tag.toLowerCase();
        if (unsafe.has(name)) found.add(name);
        return _match;
    });
    return Array.from(found);
}

async function copyShareLink() {
    if (!shareLink?.value) return;
    try {
        await navigator.clipboard.writeText(shareLink.value);
        setStatus("copied. delicious.", false);
    } catch (_) {
        shareLink.focus();
        shareLink.select();
        setStatus("copy failed, but the link is selected.", true);
    }
}

function setStatus(message, isError) {
    if (!statusLine) return;
    statusLine.textContent = message;
    statusLine.classList.toggle("is-error", !!isError);
}

})();
