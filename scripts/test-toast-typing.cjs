const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync('js/chat-conversation.js', 'utf8');
const code = source.slice(source.indexOf('    var typingRevealTimer='), source.indexOf('    var typingObserver='));
let timers = new Map(), next = 1;
const context = {root: {isConnected: true}, typingEl: {textContent: '', style: {}}, presenceEl: {}, syncComposer() {}, label() {return 'toast';},
    setTimeout(fn, delay) {const id = next++; timers.set(id, {fn, delay}); return id;}, clearTimeout(id) {timers.delete(id);}};
vm.createContext(context); vm.runInContext(code, context);
const pending = {ok: true, otherTyping: true, typingStartsAtMs: 11750, serverTimeMs: 10000};
context.renderPresence(pending);
assert.equal(context.typingEl.style.display, 'none');
assert.equal([...timers.values()][0].delay, 1750);
context.renderPresence({...pending, serverTimeMs: 11000});
assert.equal(timers.size, 1);
assert.equal([...timers.values()][0].delay, 1750); // Original timer is not restarted.
[...timers.values()][0].fn(); timers.clear();
assert.equal(context.typingEl.textContent, 'toast is typing...');
context.renderPresence({...pending, otherTyping: false});
assert.equal(context.typingEl.style.display, 'none');
context.renderPresence({...pending, serverTimeMs: 11500});
assert.equal([...timers.values()][0].delay, 250); // Remaining delay after reload.
context.renderPresence({...pending, otherTyping: false});
assert.equal(timers.size, 0);
context.renderPresence({...pending, typingStartsAtMs: 15000});
context.root.isConnected = false;
[...timers.values()][0].fn();
assert.equal(context.typingEl.style.display, 'none');
console.log('Toast typing checks passed.');
