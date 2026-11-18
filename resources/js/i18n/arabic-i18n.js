// Build-time i18n for an Arabic-first UI. The Arabic text in the source *is* the translation key:
//  - Arabic text nodes and static attributes in Vue templates become __('…') calls (template compiler transform);
//  - Arabic single-quoted and template literals in scripts and bound expressions become __('…', [params]).
// window.__ (defined in app.blade.php) looks the key up in lang/{locale}.json and falls back to the Arabic text.
// So nothing has to be wrapped by hand, and a missing translation shows the Arabic original.

const AR = /[؀-ۿ]/;
const esc = (s) => s.replace(/\\/g, '\\\\').replace(/'/g, "\\'");

/** Rewrites Arabic JS string literals in a .vue/.js source into __() calls. */
export function wrapLiterals(code) {
    let out = '';
    let i = 0;
    while (i < code.length) {
        const ch = code[i];
        if (ch === "'" || ch === '`') {
            // find the end of the literal (no newlines in single quotes)
            let j = i + 1;
            let depth = 0;
            while (j < code.length) {
                const c = code[j];
                if (c === '\\') { j += 2; continue; }
                if (ch === "'" && (c === "'" || c === '\n')) break;
                if (ch === '`') {
                    if (c === '$' && code[j + 1] === '{') { depth++; j += 2; continue; }
                    if (c === '}' && depth > 0) { depth--; j++; continue; }
                    if (c === '`' && depth === 0) break;
                }
                j++;
            }
            if (j >= code.length || code[j] !== ch) { out += ch; i++; continue; }
            const body = code.slice(i + 1, j);
            const already = /__\(\s*$/.test(out);
            if (!AR.test(body) || already) { out += code.slice(i, j + 1); i = j + 1; continue; }
            if (ch === "'") {
                out += `__('${body}')`;
            } else {
                // `text ${a} text ${b}` -> __('text :0 text :1', [a, b])
                const params = [];
                let key = '';
                let k = 0;
                while (k < body.length) {
                    if (body[k] === '$' && body[k + 1] === '{') {
                        let d = 1; let m = k + 2;
                        while (m < body.length && d > 0) { if (body[m] === '{') d++; else if (body[m] === '}') d--; m++; }
                        params.push(body.slice(k + 2, m - 1));
                        key += ':' + (params.length - 1);
                        k = m;
                    } else if (body[k] === '\\') { key += body.slice(k, k + 2); k += 2; }
                    else { key += body[k]; k++; }
                }
                key = key.replace(/'/g, "\\'").replace(/\n/g, '\\n');
                out += params.length ? `__('${key}', [${params.join(', ')}])` : `__('${key}')`;
            }
            i = j + 1;
            continue;
        }
        out += ch;
        i++;
    }
    return out;
}

/** Vue template compiler transform: Arabic text nodes and static attributes. */
export function arabicNodeTransform(node) {
    // 2 = TEXT, 1 = ELEMENT, 6 = ATTRIBUTE
    if (node.type === 2 && AR.test(node.content)) {
        const raw = node.content;
        const core = raw.trim();
        const lead = raw.startsWith(' ') || raw.startsWith('\n') ? "' ' + " : '';
        const trail = raw.endsWith(' ') || raw.endsWith('\n') ? " + ' '" : '';
        node.type = 5; // INTERPOLATION
        node.content = { type: 4, content: `${lead}__('${esc(core)}')${trail}`, isStatic: false, constType: 0, loc: node.loc };
        return;
    }
    if (node.type === 1 && node.props) {
        node.props = node.props.map((p) => {
            if (p.type !== 6 || !p.value || !AR.test(p.value.content)) return p;
            return {
                type: 7, name: 'bind', rawName: ':' + p.name, modifiers: [], loc: p.loc,
                arg: { type: 4, content: p.name, isStatic: true, constType: 3, loc: p.nameLoc ?? p.loc },
                exp: { type: 4, content: `__('${esc(p.value.content)}')`, isStatic: false, constType: 0, loc: p.value.loc },
            };
        });
    }
}

export default function arabicI18n() {
    return {
        name: 'arabic-i18n',
        enforce: 'pre',
        transform(code, id) {
            if (!/resources\/js\/.*\.(vue|js)$/.test(id) || id.includes('/i18n/') || !AR.test(code)) return null;
            if (id.endsWith('.vue')) {
                // only the <script> blocks and bound template expressions; leave plain template text to the compiler transform
                return { code: code.replace(/(<script[^>]*>)([\s\S]*?)(<\/script>)/g, (m, a, b, c) => a + wrapLiterals(b) + c)
                    .replace(/(<template>[\s\S]*<\/template>)/, (t) => t.replace(/(\s(?::|v-bind:|v-[a-z-]+|@)[\w.:-]*\s*=\s*")([^"]*)(")/g, (m, a, b, c) => a + wrapLiterals(b) + c)
                        .replace(/\{\{([\s\S]*?)\}\}/g, (m, b) => '{{' + wrapLiterals(b) + '}}')), map: null };
            }
            return { code: wrapLiterals(code), map: null };
        },
    };
}
