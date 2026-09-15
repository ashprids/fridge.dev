import assert from 'node:assert/strict';
import { deflateRawSync } from 'node:zlib';
import '../js/backup-archive.js';

// Entire fixture stays in memory; no subprocess or filesystem access is needed.
function fixture({ developer = false, marker = true } = {}) {
    const files = {
        'data/accounts/accounts.json': JSON.stringify({ accounts: developer ? [] : [{ username: 'admin' }, { username: 'alice' }] }),
        'data/feed/post.txt': 'post',
        'data/feed/replies/post.json': JSON.stringify({ replies: [{ id: '1' }, { id: '2' }, { id: '3' }] }),
        'data/feed/post-ips.json': '{}',
        'data/journal/1.md': 'journal', 'data/journal/2.txt': 'legacy', 'data/journal/drafts/1.md': 'draft',
        'data/guestbook/1.txt': 'entry', 'data/guestbook/ip_index.json': '{}',
        'data/chat/abcdefgh1.json': '{}', 'data/chat/.presence/abcdefgh1.json': '{}',
        'data/images/one.png': 'image', 'data/images/thumbnails/one.jpg': 'thumbnail',
        'data/mdpaste/0123456789abcdef.json': '{}'
    };
    if (developer) {
        files['data/journal/drafts/dev-placeholder.txt'] = 'Development placeholder draft';
        if (marker) files['data/.development-copy.json'] = JSON.stringify({ type: 'fridge.dev-development-data', version: 1 });
    }
    const locals = [], central = []; let offset = 0;
    for (const [path, text] of Object.entries(files)) {
        const name = Buffer.from(path), content = Buffer.from(text), compressed = deflateRawSync(content);
        const local = Buffer.alloc(30); local.writeUInt32LE(0x04034b50); local.writeUInt16LE(8, 8); local.writeUInt32LE(compressed.length, 18); local.writeUInt32LE(content.length, 22); local.writeUInt16LE(name.length, 26);
        const record = Buffer.alloc(46); record.writeUInt32LE(0x02014b50); record.writeUInt16LE(8, 10); record.writeUInt32LE(compressed.length, 20); record.writeUInt32LE(content.length, 24); record.writeUInt16LE(name.length, 28); record.writeUInt32LE(offset, 42);
        locals.push(local, name, compressed); central.push(record, name); offset += local.length + name.length + compressed.length;
    }
    const directory = Buffer.concat(central), end = Buffer.alloc(22);
    end.writeUInt32LE(0x06054b50); end.writeUInt16LE(Object.keys(files).length, 8); end.writeUInt16LE(Object.keys(files).length, 10); end.writeUInt32LE(directory.length, 12); end.writeUInt32LE(offset, 16);
    return Buffer.concat([...locals, directory, end]);
}
{
    const bytes = fixture();
    const file = new File([bytes], '01-09-26_12-00-00.zip');
    const info = await globalThis.FridgeBackupArchive.inspect(file, new Date(2026, 8, 15));
    assert.deepEqual(info, { date: '01/09/2026', days: 14, accounts: 2, feed: 1, replies: 3, journal: 2, guestbook: 1, chats: 1, images: 1, mdpaste: 1 });
    await assert.rejects(() => globalThis.FridgeBackupArchive.inspect(new File([bytes], '31-02-26_12-00-00.zip')), /invalid backup date/);
    await assert.rejects(() => globalThis.FridgeBackupArchive.inspect(new File([bytes.slice(0, 80)], file.name)), /complete ZIP/);
    await assert.rejects(
        () => globalThis.FridgeBackupArchive.inspect(new File([fixture({ developer: true })], file.name)),
        error => error?.code === 'development-copy'
    );
    await assert.rejects(
        () => globalThis.FridgeBackupArchive.inspect(new File([fixture({ developer: true, marker: false })], file.name)),
        error => error?.code === 'development-copy'
    );
    const corrupt = Buffer.from(bytes);
    const central = corrupt.indexOf(Buffer.from([0x50, 0x4b, 0x01, 0x02]));
    corrupt.write('evil', central + 46);
    await assert.rejects(() => globalThis.FridgeBackupArchive.inspect(new File([corrupt], file.name)), /unsafe paths/);
    console.log('Local counts, developer-copy rejection, date validation and malformed ZIP checks passed.');
}
