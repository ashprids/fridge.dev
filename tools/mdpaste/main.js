"use strict";

const mdfpasteDebugLog = message => window.fridg3DebugClientLog?.(`[mdpaste] ${message}`);

const fileInput = document.getElementById("markdown-file");
const markdownInput = document.getElementById("markdown-input");
const preview = document.getElementById("markdown-preview-body");
const form = document.getElementById("mdpaste-form");
const passwordInput = document.getElementById("password-input");
const statusLine = document.getElementById("status-line");
const resultBox = document.getElementById("result-box");
const shareLink = document.getElementById("share-link");
const copyLinkButton = document.getElementById("copy-link-btn");
const createButton = document.getElementById("create-link-btn");
const hardBreaksInput = document.getElementById("hard-breaks-input");

if (!fileInput || !markdownInput || !preview) {
	// Do nothing when this script is loaded on a page without the mdfpaste UI.
} else {
	fileInput.addEventListener("change", handleFileUpload);
	markdownInput.addEventListener("input", updatePreview);
	if (hardBreaksInput) {
		hardBreaksInput.addEventListener("change", updatePreview);
	}
	if (form) {
		form.addEventListener("submit", createPaste);
	}
	if (copyLinkButton && shareLink) {
		copyLinkButton.addEventListener("click", copyShareLink);
	}
	updatePreview();
	mdfpasteDebugLog("editor initialized");
}

function handleFileUpload(event) {
	const target = event.target;
	const file = target.files && target.files[0];

	if (!file) {
		return;
	}

	const filename = file.name.toLowerCase();
	if (!filename.endsWith(".md") && !filename.endsWith(".txt")) {
		setStatus("only .md and .txt files are allowed.", true);
		target.value = "";
		return;
	}

	const reader = new FileReader();
	reader.onload = function onLoad() {
		markdownInput.value = String(reader.result || "");
		setStatus("file loaded. preview updated.", false);
		updatePreview();
		mdfpasteDebugLog("local markdown file loaded");
	};
	reader.onerror = function onError() {
		setStatus("could not read the file. try again.", true);
		target.value = "";
		mdfpasteDebugLog("local markdown file read failed");
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

	if (createButton) {
		createButton.disabled = true;
		createButton.textContent = "creating...";
	}
	setStatus("saving paste...", false);
	mdfpasteDebugLog("paste creation requested");

	try {
		const response = await fetch("/tools/mdpaste/", {
			method: "POST",
			headers: {
				"Content-Type": "application/json"
			},
			body: JSON.stringify({
				markdown,
				password: passwordInput ? passwordInput.value : "",
				hardBreaks: hardBreaksInput ? hardBreaksInput.checked : false
			})
		});
		const data = await response.json().catch(function () {
			return {};
		});

		if (!response.ok || !data.ok) {
			throw new Error(data.error || "could not create paste.");
		}

		const absoluteUrl = new URL(data.url, window.location.origin).toString();
		if (shareLink && resultBox) {
			shareLink.value = absoluteUrl;
			resultBox.hidden = false;
			shareLink.focus();
			shareLink.select();
		}
		setStatus(data.encrypted ? "encrypted paste created. send the password separately." : "paste created.", false);
		mdfpasteDebugLog(`paste created${data.encrypted ? " with encryption" : ""}`);
	} catch (error) {
		setStatus(error.message || "could not create paste.", true);
		mdfpasteDebugLog(`paste creation failed: ${error.message || "unknown error"}`);
	} finally {
		if (createButton) {
			createButton.disabled = false;
			createButton.textContent = "create link";
		}
	}
}

async function copyShareLink() {
	if (!shareLink || !shareLink.value) {
		return;
	}

	try {
		await navigator.clipboard.writeText(shareLink.value);
		setStatus("copied. delicious.", false);
		mdfpasteDebugLog("share link copied");
	} catch (error) {
		shareLink.focus();
		shareLink.select();
		setStatus("copy failed, but the link is selected.", true);
		mdfpasteDebugLog("clipboard copy failed");
	}
}

function setStatus(message, isError) {
	if (!statusLine) {
		return;
	}
	statusLine.textContent = message;
	statusLine.classList.toggle("is-error", Boolean(isError));
}

function updatePreview() {
	const raw = markdownInput.value || "";
	preview.innerHTML = renderMarkdown(raw, hardBreaksInput ? hardBreaksInput.checked : false);
}

function escapeHtml(value) {
	return value
		.replace(/&/g, "&amp;")
		.replace(/</g, "&lt;")
		.replace(/>/g, "&gt;")
		.replace(/\"/g, "&quot;")
		.replace(/'/g, "&#39;");
}

function applyInline(text) {
	let out = escapeHtml(text);
	out = out.replace(/`([^`]+)`/g, "<code>$1</code>");
	out = out.replace(/\*\*([^*]+)\*\*/g, "<strong>$1</strong>");
	out = out.replace(/\*([^*]+)\*/g, "<em>$1</em>");
	out = out.replace(/~~([^~]+)~~/g, "<del>$1</del>");
	out = out.replace(/!\[\[([^\]]+)\]\]/g, function renderObsidianImage(match, target) {
		const src = safeObsidianImageUrl(target);
		return src ? '<img src="' + escapeAttribute(src) + '" alt="">' : match;
	});
	out = out.replace(/!\[([^\]]*)\]\((https?:\/\/[^\s)]+|\/data\/images\/[^\s)]+)\)/g, '<img src="$2" alt="$1">');
	out = out.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noreferrer">$1</a>');
	return out;
}

function escapeAttribute(value) {
	return String(value).replace(/"/g, "&quot;");
}

function safeObsidianImageUrl(target) {
	const cleanTarget = String(target || "").replace(/\|.*$/, "").trim();
	if (/^https?:\/\//i.test(cleanTarget) || cleanTarget.startsWith("/data/images/")) {
		return cleanTarget;
	}
	if (/^[A-Za-z0-9][A-Za-z0-9._ -]+\.(png|jpe?g|gif|webp|svg)$/i.test(cleanTarget)) {
		return "/data/images/" + encodeURIComponent(cleanTarget);
	}
	return "";
}

function renderMarkdown(markdown, hardBreaks) {
	const lines = markdown.replace(/\r\n/g, "\n").split("\n");
	const html = [];
	let paragraph = [];
	let inCode = false;
	let codeBuffer = [];
	let inUl = false;
	let inOl = false;
	let inBlockquote = false;

	const flushParagraph = function flushParagraph() {
		if (!paragraph.length) {
			return;
		}
		html.push("<p>" + applyInline(paragraph.join(hardBreaks ? "\n" : " ")).replace(/\n/g, "<br>") + "</p>");
		paragraph = [];
	};

	const closeLists = function closeLists() {
		if (inUl) {
			html.push("</ul>");
			inUl = false;
		}
		if (inOl) {
			html.push("</ol>");
			inOl = false;
		}
	};

	const closeQuote = function closeQuote() {
		if (inBlockquote) {
			html.push("</blockquote>");
			inBlockquote = false;
		}
	};

	for (let i = 0; i < lines.length; i += 1) {
		const line = lines[i];
		const trimmed = line.trim();

		if (trimmed.startsWith("```")) {
			flushParagraph();
			closeLists();
			closeQuote();
			if (!inCode) {
				inCode = true;
				codeBuffer = [];
			} else {
				html.push("<pre><code>" + escapeHtml(codeBuffer.join("\n")) + "</code></pre>");
				inCode = false;
			}
			continue;
		}

		if (inCode) {
			codeBuffer.push(line);
			continue;
		}

		if (!trimmed) {
			flushParagraph();
			closeLists();
			closeQuote();
			continue;
		}

		const heading = trimmed.match(/^(#{1,6})\s+(.+)$/);
		if (heading) {
			flushParagraph();
			closeLists();
			closeQuote();
			const level = heading[1].length;
			html.push("<h" + level + ">" + applyInline(heading[2]) + "</h" + level + ">");
			continue;
		}

		if (trimmed === "---" || trimmed === "***") {
			flushParagraph();
			closeLists();
			closeQuote();
			html.push("<hr>");
			continue;
		}

		const ul = trimmed.match(/^[-*]\s+(.+)$/);
		if (ul) {
			flushParagraph();
			closeQuote();
			if (inOl) {
				html.push("</ol>");
				inOl = false;
			}
			if (!inUl) {
				html.push("<ul>");
				inUl = true;
			}
			html.push("<li>" + applyInline(ul[1]) + "</li>");
			continue;
		}

		const ol = trimmed.match(/^\d+\.\s+(.+)$/);
		if (ol) {
			flushParagraph();
			closeQuote();
			if (inUl) {
				html.push("</ul>");
				inUl = false;
			}
			if (!inOl) {
				html.push("<ol>");
				inOl = true;
			}
			html.push("<li>" + applyInline(ol[1]) + "</li>");
			continue;
		}

		const quote = trimmed.match(/^>\s?(.*)$/);
		if (quote) {
			flushParagraph();
			closeLists();
			if (!inBlockquote) {
				html.push("<blockquote>");
				inBlockquote = true;
			}
			html.push("<p>" + applyInline(quote[1]) + "</p>");
			continue;
		}

		if (inBlockquote) {
			closeQuote();
		}

		paragraph.push(trimmed);
	}

	flushParagraph();
	closeLists();
	closeQuote();

	if (inCode) {
		html.push("<pre><code>" + escapeHtml(codeBuffer.join("\n")) + "</code></pre>");
	}

	return html.length ? html.join("\n") : "<p>your formatted markdown will appear here automatically.</p>";
}
