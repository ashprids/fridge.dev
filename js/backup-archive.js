/* Read the ZIP directory and selected JSON records locally; never unpack files to disk. */
(function (scope) {
    'use strict';
    const u64 = (view, offset) => {
        const value = Number(view.getBigUint64(offset, true));
        if (!Number.isSafeInteger(value)) throw new Error('archive is too large');
        return value;
    };
    async function bytes(file, offset, length) {
        if (offset < 0 || length < 0 || offset + length > file.size) throw new Error('truncated ZIP archive');
        return new DataView(await file.slice(offset, offset + length).arrayBuffer());
    }
    async function directory(file) {
        const start = Math.max(0, file.size - 65557);
        const tail = await bytes(file, start, file.size - start);
        let eocd = -1;
        for (let p = tail.byteLength - 22; p >= 0; p--) {
            if (tail.getUint32(p, true) === 0x06054b50 && p + 22 + tail.getUint16(p + 20, true) === tail.byteLength) { eocd = p; break; }
        }
        if (eocd < 0) throw new Error('not a complete ZIP archive');
        if (tail.getUint16(eocd + 4, true) || tail.getUint16(eocd + 6, true)) throw new Error('split ZIP archives are unsupported');
        let count = tail.getUint16(eocd + 10, true);
        let size = tail.getUint32(eocd + 12, true);
        let offset = tail.getUint32(eocd + 16, true);
        if (count === 65535 || size === 0xffffffff || offset === 0xffffffff) {
            const locator = await bytes(file, start + eocd - 20, 20);
            if (locator.getUint32(0, true) !== 0x07064b50 || locator.getUint32(16, true) !== 1) throw new Error('invalid ZIP64 archive');
            const record = await bytes(file, u64(locator, 8), 56);
            if (record.getUint32(0, true) !== 0x06064b50) throw new Error('invalid ZIP64 directory');
            count = u64(record, 32); size = u64(record, 40); offset = u64(record, 48);
        }
        if (size > 128 * 1024 * 1024 || count > 1000000) throw new Error('archive directory is too large to inspect');
        const view = await bytes(file, offset, size);
        const decoder = new TextDecoder();
        const entries = new Map();
        let p = 0;
        for (let index = 0; index < count; index++) {
            if (p + 46 > size || view.getUint32(p, true) !== 0x02014b50) throw new Error('invalid ZIP directory');
            const nameLength = view.getUint16(p + 28, true), extraLength = view.getUint16(p + 30, true), commentLength = view.getUint16(p + 32, true);
            const end = p + 46 + nameLength + extraLength + commentLength;
            if (end > size) throw new Error('truncated ZIP directory');
            const name = decoder.decode(new Uint8Array(view.buffer, p + 46, nameLength));
            const type = (view.getUint32(p + 38, true) >>> 16) & 0xf000;
            if (!name.startsWith('data/') || /[\\\x00-\x1f\x7f]/.test(name) || name.replace(/\/$/, '').split('/').some(part => !part || part === '.' || part === '..') || ![0, 0x8000, 0x4000].includes(type)) throw new Error('archive contains unsafe paths');
            if (entries.has(name) || (view.getUint16(p + 8, true) & 1)) throw new Error('duplicate or encrypted ZIP entries are unsupported');
            const entry = { name, method: view.getUint16(p + 10, true), compressed: view.getUint32(p + 20, true), size: view.getUint32(p + 24, true), offset: view.getUint32(p + 42, true) };
            for (let x = p + 46 + nameLength; x + 4 <= p + 46 + nameLength + extraLength;) {
                const tag = view.getUint16(x, true), length = view.getUint16(x + 2, true);
                if (x + 4 + length > p + 46 + nameLength + extraLength) throw new Error('invalid ZIP extra field');
                if (tag === 1) {
                    let pos = x + 4;
                    for (const field of ['size', 'compressed', 'offset']) if (entry[field] === 0xffffffff) {
                        if (pos + 8 > x + 4 + length) throw new Error('invalid ZIP64 entry');
                        entry[field] = u64(view, pos); pos += 8;
                    }
                }
                x += 4 + length;
            }
            entries.set(name, entry); p = end;
        }
        return entries;
    }
    async function jsonEntry(file, entry) {
        if (!entry || entry.size > 32 * 1024 * 1024) throw new Error('missing or excessively large backup metadata');
        const header = await bytes(file, entry.offset, 30);
        if (header.getUint32(0, true) !== 0x04034b50) throw new Error('invalid ZIP file header');
        const offset = entry.offset + 30 + header.getUint16(26, true) + header.getUint16(28, true);
        if (offset + entry.compressed > file.size) throw new Error('truncated archive entry');
        let stream = file.slice(offset, offset + entry.compressed).stream();
        if (entry.method === 8) stream = stream.pipeThrough(new DecompressionStream('deflate-raw'));
        else if (entry.method !== 0) throw new Error('unsupported ZIP compression');
        const reader = stream.getReader(), chunks = [];
        let length = 0;
        while (true) {
            const { value, done } = await reader.read();
            if (done) break;
            length += value.length;
            if (length > entry.size || length > 32 * 1024 * 1024) { await reader.cancel(); throw new Error('invalid metadata size'); }
            chunks.push(value);
        }
        if (length !== entry.size) throw new Error('truncated backup metadata');
        return JSON.parse(await new Blob(chunks).text());
    }
    async function inspect(file, now = new Date()) {
        const match = file.name.match(/^(\d{2})-(\d{2})-(\d{2})_(\d{2})-(\d{2})-(\d{2})\.zip$/);
        if (!match) throw new Error('select a ZIP named by the automatic data backup action');
        const [, day, month, year, hour, minute, second] = match.map(Number);
        const date = new Date(2000 + year, month - 1, day, hour, minute, second);
        if (date.getFullYear() !== 2000 + year || date.getMonth() !== month - 1 || date.getDate() !== day || hour > 23 || minute > 59 || second > 59) throw new Error('invalid backup date');
        const entries = await directory(file);
        const accounts = await jsonEntry(file, entries.get('data/accounts/accounts.json'));
        if (!Array.isArray(accounts.accounts)) throw new Error('invalid backup accounts file');
        if (entries.has('data/.development-copy.json') || (
            accounts.accounts.length === 0 && entries.has('data/journal/drafts/dev-placeholder.txt')
        )) {
            const error = new Error('development data copies cannot be restored as backups');
            error.code = 'development-copy';
            throw error;
        }
        const names = [...entries.keys()];
        const count = pattern => names.filter(name => pattern.test(name)).length;
        let replies = 0;
        for (const name of names.filter(name => /^data\/feed\/replies\/[^/]+\.json$/.test(name))) {
            const data = await jsonEntry(file, entries.get(name));
            if (!Array.isArray(data.replies)) throw new Error('invalid feed replies record');
            replies += data.replies.length;
        }
        const days = Math.floor((Date.UTC(now.getFullYear(), now.getMonth(), now.getDate()) - Date.UTC(date.getFullYear(), date.getMonth(), date.getDate())) / 86400000);
        return {
            date: `${String(day).padStart(2, '0')}/${String(month).padStart(2, '0')}/${2000 + year}`, days,
            accounts: accounts.accounts.length,
            feed: count(/^data\/feed\/[^/]+\.txt$/), replies,
            journal: count(/^data\/journal\/[^/]+\.(?:txt|md)$/),
            guestbook: count(/^data\/guestbook\/[^/]+\.txt$/),
            chats: count(/^data\/chat\/[^/.][^/]*\.json$/),
            images: count(/^data\/images\/[^/]+\.(?:png|jpe?g|gif|webp|avif|svg|bmp)$/i),
            mdpaste: count(/^data\/mdpaste\/[a-f0-9]{16}\.json$/)
        };
    }
    scope.FridgeBackupArchive = { inspect, directory };
})(typeof window === 'undefined' ? globalThis : window);
