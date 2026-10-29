/* assets/js/treatment-icons.js — illustrated icons for treatment types.
 *
 * Every treatment type carries an icon key (treatments.icon) chosen when the
 * type is made: the treatment picker on a case shows it on the type's card,
 * and the treatment-type dialogs (case page, settings) offer the chooser below.
 * A type made before icons existed has none; guess() picks one from its name
 * and category so the picker never shows a blank card.
 *
 * The icons are small SVGs (viewBox 64×64) drawn from shared gradients that
 * live once in a hidden sprite (ensureDefs) — not display:none, which would
 * stop the gradients from painting — so thirty icons cost one <defs>.
 *
 *   TreatmentIcons.svg(key)            → '<svg class="ti">…</svg>'
 *   TreatmentIcons.keyFor(treatment)   → its icon, or a guess ({name, category, icon})
 *   TreatmentIcons.chooser(el, opts)   → icon grid; {get, set, suggest}
 *
 * A work type (work_types.icon, the new-visit form's «جۆری ئیش» cards) takes
 * these and the complaint icons in VISIT_ICONS — pain, a broken tooth … —
 * with guessWork() for a type saved without one.
 */
(function () {
    'use strict';

    // ── shapes (64×64, crown up) ──
    const MOLAR = 'M20,36 C16.2,33 13,28.2 13,21 C13,13 16.2,8.6 22,7.7 C26,7.1 28.6,8.6 30.6,10.6 Q32,11.7 33.4,10.6 C35.4,8.6 38,7.1 42,7.7 C47.8,8.6 51,13 51,21 C51,28.2 47.8,33 44,36 C44.2,43 43,50 40.8,56 C39.8,58.6 37.2,58.8 36.6,56 C36,51 35.4,46.2 34,42.6 Q32,39.6 30,42.6 C28.6,46.2 28,51 27.4,56 C26.8,58.8 24.2,58.6 23.2,56 C21,50 19.8,43 20,36 Z';
    const MOLAR_CROWN = 'M20,36 C16.2,33 13,28.2 13,21 C13,13 16.2,8.6 22,7.7 C26,7.1 28.6,8.6 30.6,10.6 Q32,11.7 33.4,10.6 C35.4,8.6 38,7.1 42,7.7 C47.8,8.6 51,13 51,21 C51,28.2 47.8,33 44,36 Q32,39.4 20,36 Z';
    const OCCLUSAL = 'M17,15.5 C21,19 26,18.6 32,16 C38,18.6 43,19 47,15.5';
    const INCISOR = 'M24,33 C22.4,26 21,18 21,10.5 Q21,6.4 25,6 L39,6 Q43,6.4 43,10.5 C43,18 41.6,26 40,33 C39.6,42 37,51 33.8,57 Q32,60 30.2,57 C27,51 24.4,42 24,33 Z';
    const PULP = 'M25.6,22.4 C25.4,18.6 28.4,16.8 32,16.8 C35.6,16.8 38.6,18.6 38.4,22.4 C38.2,26.4 37.4,29.4 37,32 C38.4,38 39.4,45 39.4,52 Q38.8,54.4 38,52 C37.4,45.6 36,39.6 33.8,35.4 L30.2,35.4 C28,39.6 26.6,45.6 26,52 Q25.2,54.4 24.6,52 C24.6,45 25.6,38 27,32 C26.6,29.4 25.8,26.4 25.6,22.4 Z';
    const GUM = 'M5,46 C5,41.6 8,38.8 12.2,38.8 C15.6,38.8 17.6,40.2 19.8,41.8 C24.8,45.4 39.2,45.4 44.2,41.8 C46.4,40.2 48.4,38.8 51.8,38.8 C56,38.8 59,41.6 59,46 L59,55 Q59,59 55,59 L9,59 Q5,59 5,55 Z';
    const CAVITY = 'M24.5,15.8 C25.6,12.6 29.6,12.4 32,14.4 C34.4,12.4 38.6,12.8 39.6,16 C40.6,19.2 37.6,22 33.8,21.4 C30.6,23.4 26.2,22.6 25,19.6 C24.5,18.4 24.2,17 24.5,15.8 Z';

    const g = (inner, transform) => transform ? `<g transform="${transform}">${inner}</g>` : inner;

    /** A molar: enamel crown over an ivory root, shaded round, with a shine. */
    function molar(transform) {
        return g(
            `<path d="${MOLAR}" fill="url(#ti-tooth)"/>` +
            `<path d="${MOLAR}" fill="url(#ti-shade)"/>` +
            `<ellipse cx="22" cy="17" rx="4.2" ry="7" fill="url(#ti-gloss)"/>` +
            `<path d="${OCCLUSAL}" fill="none" stroke="#d2d9e2" stroke-width="1.1" stroke-linecap="round"/>` +
            `<path d="${MOLAR}" fill="none" stroke="#b7c0cb" stroke-width="1"/>`, transform);
    }
    function incisor(transform) {
        return g(
            `<path d="${INCISOR}" fill="url(#ti-tooth)"/>` +
            `<path d="${INCISOR}" fill="url(#ti-shade)"/>` +
            `<ellipse cx="27.5" cy="15" rx="3" ry="6.5" fill="url(#ti-gloss)"/>` +
            `<path d="${INCISOR}" fill="none" stroke="#b7c0cb" stroke-width="1"/>`, transform);
    }
    /** A crown cap (white, metal …) sitting on the tooth's neck. */
    function cap(fill, transform) {
        return g(
            `<path d="${MOLAR_CROWN}" fill="${fill}"/>` +
            `<path d="${MOLAR_CROWN}" fill="url(#ti-shade)"/>` +
            `<ellipse cx="22.5" cy="16.5" rx="4.6" ry="7.4" fill="url(#ti-gloss)"/>` +
            `<path d="${MOLAR_CROWN}" fill="none" stroke="#a9b3bf" stroke-width="1"/>` +
            `<path d="M20.4,36.4 Q32,39.8 43.6,36.4" fill="none" stroke="#8f9aa6" stroke-width="1.2" stroke-linecap="round"/>`, transform);
    }
    function gum() {
        return `<path d="${GUM}" fill="url(#ti-gum)"/>` +
            `<path d="M9,45 C10.6,42 14,41.2 16.8,42.4" fill="none" stroke="#fff" stroke-opacity=".55" stroke-width="1.6" stroke-linecap="round"/>` +
            `<path d="${GUM}" fill="none" stroke="#b83f47" stroke-opacity=".35" stroke-width=".8"/>`;
    }
    function sparkle(cx, cy, r, fill) {
        const k = r * 0.2;
        return `<path d="M${cx},${cy - r} Q${cx + k},${cy - k} ${cx + r},${cy} Q${cx + k},${cy + k} ${cx},${cy + r} Q${cx - k},${cy + k} ${cx - r},${cy} Q${cx - k},${cy - k} ${cx},${cy - r} Z" fill="${fill}"/>`;
    }
    const arrow = (d) => `<path d="${d}" fill="none" stroke="#3b82f6" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>`;

    function rootCanal() {
        return molar() +
            `<path d="${PULP}" fill="url(#ti-pulp)"/>` +
            `<path d="${PULP}" fill="none" stroke="#b42c3a" stroke-opacity=".5" stroke-width=".7"/>` +
            `<path d="M46.4,9 L35.6,29" stroke="#8d97a3" stroke-width="1.5" stroke-linecap="round"/>` +
            `<rect x="45.8" y="0.6" width="6" height="9" rx="2" transform="rotate(29 48.8 5.1)" fill="url(#ti-blue)"/>`;
    }

    function bridge() {
        const SC = 'M2,16 C0.7,13 0,10 0,7.4 C0,3.2 2.2,0.7 6,0.5 C7.8,0.4 8.8,1.2 9.5,2.2 C10.2,1.2 11.2,0.4 13,0.5 C16.8,0.7 19,3.2 19,7.4 C19,10 18.3,13 17,16 Q9.5,17.6 2,16 Z';
        const ROOTS = 'M3.4,16 C3.2,22 4,28 5.4,33 Q6.3,35.4 7.2,33 C7.8,28.6 8.4,24.4 9.5,21.6 C10.6,24.4 11.2,28.6 11.8,33 Q12.7,35.4 13.6,33 C15,28 15.8,22 15.6,16 Z';
        const unit = (x, roots) => `<g transform="translate(${x},13)">` +
            (roots ? `<path d="${ROOTS}" fill="url(#ti-root)" stroke="#c9b48c" stroke-width=".8"/>` : '') +
            `<path d="${SC}" fill="url(#ti-enamel)"/><path d="${SC}" fill="url(#ti-shade)"/>` +
            `<ellipse cx="6" cy="6" rx="2.4" ry="3.6" fill="url(#ti-gloss)"/>` +
            `<path d="${SC}" fill="none" stroke="#a9b3bf" stroke-width=".9"/></g>`;
        return `<rect x="19.6" y="19" width="5" height="7" rx="1.5" fill="#e4e9ef"/><rect x="39.6" y="19" width="5" height="7" rx="1.5" fill="#e4e9ef"/>` +
            unit(2.5, true) + unit(22.5, false) + unit(42.5, true);
    }

    function implant() {
        const CROWN = 'M19,25 C15.6,22 13.6,18 13.6,13.4 C13.6,7.6 17.2,4.6 22.6,4.2 C26,4 28.4,5.2 30.4,6.8 Q32,7.8 33.6,6.8 C35.6,5.2 38,4 41.4,4.2 C46.8,4.6 50.4,7.6 50.4,13.4 C50.4,18 48.4,22 45,25 Q32,28 19,25 Z';
        let threads = '';
        for (let y = 35.5; y <= 52; y += 4) {
            const inset = (y - 32) * 0.073;
            threads += `<path d="M${(26 + inset - 1.4).toFixed(1)},${(y + 1.2).toFixed(1)} L${(38 - inset + 1.4).toFixed(1)},${(y - 1.4).toFixed(1)}" stroke="#6f7a86" stroke-width="1.2" stroke-linecap="round"/>`;
        }
        return `<path d="M26,32 L38,32 L36.4,54 Q32,60.5 27.6,54 Z" fill="url(#ti-steel)" stroke="#6f7a86" stroke-width=".6"/>` + threads +
            `<path d="M25.4,25.6 L38.6,25.6 L37.4,32.4 L26.6,32.4 Z" fill="url(#ti-metal)" stroke="#8d97a3" stroke-width=".6"/>` +
            `<path d="${CROWN}" fill="url(#ti-enamel)"/><path d="${CROWN}" fill="url(#ti-shade)"/>` +
            `<ellipse cx="23" cy="12.5" rx="4.4" ry="5.6" fill="url(#ti-gloss)"/>` +
            `<path d="${CROWN}" fill="none" stroke="#a9b3bf" stroke-width="1"/>`;
    }

    function denture() {
        let teeth = '';
        const n = 8;
        for (let i = 0; i < n; i++) {
            const deg = 160 - i * (140 / (n - 1));
            const t = deg * Math.PI / 180;
            const x = 32 + 20.5 * Math.cos(t), y = 50 - 31 * Math.sin(t);
            teeth += `<rect x="-3.7" y="-4.4" width="7.4" height="8.8" rx="2.6" transform="translate(${x.toFixed(1)} ${y.toFixed(1)}) rotate(${(90 - deg).toFixed(1)})" fill="url(#ti-enamel)" stroke="#aeb8c4" stroke-width=".7"/>`;
        }
        return `<path d="M5.5,52 A26.5,38 0 0 1 58.5,52 L46.5,52 A14.5,25 0 0 0 17.5,52 Z" fill="url(#ti-gum)" stroke="#b83f47" stroke-opacity=".35" stroke-width=".8"/>` + teeth;
    }

    // ── the set, in the order the chooser shows it ──
    const ICONS = [
        { key: 'exam', label: 'پشکنین', body: () => molar('translate(-3,4) scale(.94)') +
            `<path d="M41,24.2 L25,55" stroke="#8d97a3" stroke-width="3.4" stroke-linecap="round"/><path d="M41,24.2 L25,55" stroke="#e6eaee" stroke-width="1.1" stroke-linecap="round"/>` +
            `<circle cx="46.5" cy="15.5" r="10" fill="url(#ti-metal)"/><circle cx="46.5" cy="15.5" r="7.8" fill="url(#ti-glass)"/>` +
            `<ellipse cx="43.6" cy="12.4" rx="3.2" ry="2" transform="rotate(-30 43.6 12.4)" fill="#fff" opacity=".75"/>` },
        { key: 'filling', label: 'پڕکردنەوە', body: () => molar() +
            `<path d="${CAVITY}" fill="url(#ti-cavity)"/><ellipse cx="29.4" cy="15.8" rx="2.4" ry="1" fill="#fff" opacity=".35"/>` },
        { key: 'filling_cosmetic', label: 'پڕکردنەوەی جوانکاری', body: () => molar() +
            `<path d="${CAVITY}" fill="url(#ti-composite)" stroke="#cdb48a" stroke-width=".8"/>` + sparkle(49, 9, 5, 'url(#ti-blue)') },
        { key: 'temporary', label: 'ددانی کاتی', body: () => molar() +
            `<path d="M13.3,19.5 C13.4,12.8 16.4,8.7 22,7.7 C26,7.1 28.6,8.6 30.6,10.6 Q32,11.7 33.4,10.6 C35.4,8.6 38,7.1 42,7.7 C47.6,8.7 50.6,12.8 50.7,19.5 C46.4,22.4 41.2,20.6 37,22.4 C34,23.6 30,23.6 27,22.4 C22.8,20.6 17.6,22.4 13.3,19.5 Z" fill="url(#ti-coral)" stroke="#c2453d" stroke-opacity=".6" stroke-width=".7"/>` +
            `<ellipse cx="22" cy="12.6" rx="4.2" ry="2.2" transform="rotate(-12 22 12.6)" fill="#fff" opacity=".6"/>` },
        { key: 'cleaning', label: 'پاککردنەوە / شوشتن', body: () => molar() + gum() +
            `<circle cx="53" cy="12" r="3.4" fill="#eaf6ff" stroke="#7cc0ff" stroke-width="1"/><circle cx="57" cy="21.5" r="2.1" fill="#eaf6ff" stroke="#7cc0ff" stroke-width=".9"/><circle cx="47.5" cy="5.5" r="1.9" fill="#eaf6ff" stroke="#7cc0ff" stroke-width=".9"/>` +
            sparkle(11, 11, 5, 'url(#ti-blue)') },
        { key: 'extraction', label: 'کێشان', body: () => molar('translate(0,-6)') +
            `<ellipse cx="32" cy="44.6" rx="9.5" ry="2.2" fill="#8f2d36" opacity=".55"/>` + gum() +
            arrow('M57,31 V14 M52.2,18.8 L57,14 L61.8,18.8') },
        { key: 'surgical_extraction', label: 'کێشان بە نەشتەرگەری', body: () => molar('translate(-3,0)') + gum() +
            `<g transform="rotate(38 51 20)"><rect x="47.8" y="-2" width="6.4" height="21" rx="3.2" fill="url(#ti-metal)" stroke="#8d97a3" stroke-width=".6"/>` +
            `<path d="M48.4,19 H53.6 L52.2,31 Q51,34.6 49.8,31 Z" fill="#eef2f6" stroke="#8d97a3" stroke-width=".7"/></g>` },
        { key: 'root_canal', label: 'چارەسەری دەمار', body: rootCanal },
        { key: 'retreatment', label: 'دووبارە چارەسەری دەمار', body: () => rootCanal() +
            `<circle cx="12.5" cy="12" r="9" fill="#fff" stroke="#dbe5ef" stroke-width="1"/>` +
            `<path d="M8.2,14.6 A5,5 0 1 0 9.4,8.2" fill="none" stroke="#3b82f6" stroke-width="2" stroke-linecap="round"/>` +
            `<path d="M6.6,6.8 L9.8,7.9 L8.9,11.2" fill="none" stroke="#3b82f6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>` },
        { key: 'post', label: 'پۆست / بزمار', body: () => incisor() +
            `<path d="M30.6,13 L33.4,13 L33,48 Q32,51 31,48 Z" fill="url(#ti-steel)" stroke="#6f7a86" stroke-width=".6"/>` +
            `<rect x="27.6" y="9.6" width="8.8" height="5" rx="1.5" fill="url(#ti-metal)" stroke="#7d8894" stroke-width=".6"/>` },
        { key: 'crown', label: 'کیف / تاج', body: () => `<path d="${MOLAR}" fill="url(#ti-tooth)" stroke="#b7c0cb" stroke-width="1"/>` +
            cap('url(#ti-enamel)', 'translate(32 22) scale(1.05) translate(-32 -22)') },
        { key: 'crown_metal', label: 'کیفی میتەڵ', body: () => `<path d="${MOLAR}" fill="url(#ti-tooth)" stroke="#b7c0cb" stroke-width="1"/>` +
            cap('url(#ti-metal)', 'translate(32 22) scale(1.05) translate(-32 -22)') },
        { key: 'cementation', label: 'چەسپاندنی کیف', body: () => `<path d="${MOLAR}" fill="url(#ti-tooth)" stroke="#b7c0cb" stroke-width="1"/>` +
            `<path d="M19.6,30.2 Q32,34 44.4,30.2" fill="none" stroke="#f2c94c" stroke-width="2.4" stroke-linecap="round"/>` +
            cap('url(#ti-enamel)', 'translate(0,-8) translate(32 22) scale(.98) translate(-32 -22)') +
            arrow('M6.5,24 V35 M3,31.5 L6.5,35 L10,31.5') + arrow('M57.5,24 V35 M54,31.5 L57.5,35 L61,31.5') },
        { key: 'bridge', label: 'پرد', body: bridge },
        { key: 'implant', label: 'چاندن (ئیمپلانت)', body: implant },
        { key: 'orthodontic', label: 'ڕاستکردنەوە', body: () => molar() +
            `<path d="M2,24 Q32,19.6 62,24" fill="none" stroke="#9aa5b1" stroke-width="1.8"/>` +
            `<rect x="25" y="15" width="14" height="13" rx="2.6" fill="url(#ti-metal)" stroke="#7d8894" stroke-width=".8"/>` +
            `<path d="M25,21.4 H39" stroke="#7d8894" stroke-width="1.3"/>` +
            `<path d="M2,24 Q32,19.6 62,24" fill="none" stroke="#c1c9d2" stroke-width=".7"/>` },
        { key: 'whitening', label: 'سپیکردنەوە', body: () => `<circle cx="32" cy="30" r="30" fill="url(#ti-glow)"/>` + molar() +
            sparkle(50, 11, 7.5, 'url(#ti-blue)') + sparkle(13, 8, 3.6, '#9fd2ff') + sparkle(56, 27, 2.8, '#9fd2ff') },
        { key: 'veneer', label: 'ڤینیر / جوانکاری', body: () => incisor('translate(-3,0)') +
            `<g transform="translate(4.5,4)"><path d="M21.6,11.5 Q21.8,7.6 25.6,7.4 L38.4,7.4 Q42.2,7.6 42.4,11.5 C42.4,18.5 41.2,26 39.8,31.6 Q32,33.8 24.2,31.6 C22.8,26 21.6,18.5 21.6,11.5 Z" fill="url(#ti-enamel)" fill-opacity=".96" stroke="#8fc0ea" stroke-width="1.1"/>` +
            `<ellipse cx="27" cy="15" rx="2.4" ry="5.4" fill="url(#ti-gloss)"/></g>` },
        { key: 'gem', label: 'جوانکاری تر', body: () => molar() +
            `<path d="M34,13.6 L44,13.6 L47.6,17.6 L39,26.4 L30.4,17.6 Z" fill="url(#ti-diamond)" stroke="#1767c7" stroke-width=".6"/>` +
            `<path d="M30.4,17.6 H47.6 M34,13.6 L36.6,17.6 L39,26.4 L41.4,17.6 L44,13.6" fill="none" stroke="#fff" stroke-opacity=".7" stroke-width=".7"/>` +
            sparkle(50, 8.5, 3.2, '#7cc8ff') },
        { key: 'pediatric', label: 'منداڵان', body: () => g(molar() +
            `<ellipse cx="25.5" cy="20" rx="1.8" ry="2.4" fill="#3d2f2b"/><ellipse cx="38.5" cy="20" rx="1.8" ry="2.4" fill="#3d2f2b"/>` +
            `<circle cx="26.1" cy="19.2" r=".6" fill="#fff"/><circle cx="39.1" cy="19.2" r=".6" fill="#fff"/>` +
            `<path d="M27.6,25 Q32,29.4 36.4,25" fill="none" stroke="#3d2f2b" stroke-width="1.6" stroke-linecap="round"/>` +
            `<circle cx="21.4" cy="25" r="2.6" fill="#ff9aa2" opacity=".55"/><circle cx="42.6" cy="25" r="2.6" fill="#ff9aa2" opacity=".55"/>`,
            'translate(32 34) scale(.94) translate(-32 -34)') },
        { key: 'surgery', label: 'نەشتەرگەری', body: () => molar() + gum() +
            `<path d="M12,51 Q32,56 52,51" fill="none" stroke="#8e2430" stroke-width="1.3" stroke-linecap="round"/>` +
            [[17, 52.1], [24.5, 53.1], [32, 53.5], [39.5, 53.1], [47, 52.1]].map(([x, y]) =>
                `<path d="M${x - 1.4},${y - 3} L${x + 1.4},${y + 3}" stroke="#1f2937" stroke-width="1.2" stroke-linecap="round"/>`).join('') },
        { key: 'denture', label: 'تاخم', body: denture },
        { key: 'xray', label: 'تیشک (ئێکس ڕەی)', body: () =>
            `<rect x="9" y="6" width="46" height="52" rx="5" fill="url(#ti-film)"/>` +
            `<rect x="12" y="9" width="40" height="46" rx="3" fill="none" stroke="#fff" stroke-opacity=".14"/>` +
            g(`<path d="${MOLAR}" fill="#e8f1f7" opacity=".92"/><path d="${PULP}" fill="#6f8798" opacity=".7"/>`, 'translate(32 33) scale(.72) translate(-32 -33)') },
        { key: 'other', label: 'تر', body: () =>
            `<circle cx="32" cy="32" r="22" fill="#eef2f7"/>` +
            `<circle cx="22.5" cy="32" r="3.8" fill="#475569"/><circle cx="32" cy="32" r="3.8" fill="#475569"/><circle cx="41.5" cy="32" r="3.8" fill="#475569"/>` }
    ];

    // ── what a patient comes in with: a work type (جۆری ئیش) may also be a complaint ──
    const bolt = transform => `<path transform="${transform}" d="M3,0 L10,0 L6.5,7.5 L11,7.5 L2,21 L4.5,11 L0,11 Z" fill="url(#ti-bolt)" stroke="#be123c" stroke-width=".6" stroke-linejoin="round"/>`;
    const drop = (cx, cy, s) => `<g transform="translate(${cx} ${cy}) scale(${s})"><path d="M0,-6.5 C2.6,-2.8 4.2,-.4 4.2,2.2 A4.2,4.2 0 0 1 -4.2,2.2 C-4.2,-.4 -2.6,-2.8 0,-6.5 Z" fill="url(#ti-blood)"/>` +
        `<ellipse cx="-1.5" cy="1" rx="1" ry="1.6" fill="#fff" opacity=".55"/></g>`;
    /** A horseshoe (an arch seen from above) between two half-ellipses round (32, cy). */
    const arch = (cy, rx, ry, rx2, ry2) => `M${32 - rx},${cy} A${rx},${ry} 0 0 1 ${32 + rx},${cy} L${32 + rx2},${cy} A${rx2},${ry2} 0 0 0 ${32 - rx2},${cy} Z`;
    const VISIT_ICONS = [
        { key: 'pain', label: 'ئازار', body: () => `<circle cx="32" cy="27" r="29" fill="url(#ti-ache)"/>` +
            molar('translate(32 36) scale(.9) translate(-32 -36)') + bolt('translate(1.5 5)') + bolt('translate(62.5 5) scale(-1 1)') },
        { key: 'broken', label: 'شکاو', body: () => `<g clip-path="url(#ti-chipped)">${molar()}</g>` +
            `<path d="M41.4,7.9 L44.5,14 L48.5,15.5 L50.7,21" fill="none" stroke="#9aa5b1" stroke-width="1" stroke-linejoin="round"/>` +
            `<path d="M31,12.5 L34,18 L30.4,23.5 L33.6,30" fill="none" stroke="#7b8794" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>` +
            `<path transform="translate(50 1.5) rotate(22)" d="M0,0 L8.4,1 L9.4,7.6 L4,9.4 Z" fill="url(#ti-enamel)" stroke="#a9b3bf" stroke-width=".9" stroke-linejoin="round"/>` },
        { key: 'loose', label: 'شل بوون', body: () => molar('translate(32 47) rotate(-10) scale(.82) translate(-32 -47)') + gum() +
            `<path d="M7.5,12 Q2.5,21 7.5,30 M11,15.5 Q8.6,21 11,26.5 M53,8 Q58,17 53,26 M49,11.5 Q51.4,17 49,22.5" fill="none" stroke="#f59e0b" stroke-width="2.2" stroke-linecap="round"/>` },
        { key: 'bleeding', label: 'خوێن', body: () => molar() + gum() +
            `<path d="M20.5,42.6 Q32,47 43.5,42.6" fill="none" stroke="#b91c1c" stroke-width="2.2" stroke-linecap="round"/>` +
            drop(52, 13, 1.3) + drop(58.5, 28, .8) },
        { key: 'swelling', label: 'هەوکردن / ئاوسان', body: () => molar() + gum() +
            `<ellipse cx="49.5" cy="47.5" rx="9" ry="7.6" fill="url(#ti-abscess)" stroke="#b91c1c" stroke-opacity=".45" stroke-width=".8"/>` +
            `<ellipse cx="47" cy="44.6" rx="2.8" ry="1.6" fill="#fff" opacity=".6"/>` +
            `<path d="M49.5,36.4 V33 M57.8,40 L60.6,37.6 M41.2,40 L38.4,37.6" stroke="#ef4444" stroke-width="1.7" stroke-linecap="round"/>` },
        { key: 'sensitive', label: 'هەستیاری (سارد و گەرم)', body: () => molar('translate(32 40) scale(.84) translate(-32 -40)') +
            `<g transform="translate(10.5 11)" fill="none" stroke="#38bdf8" stroke-linecap="round"><path d="M0,-8 V8 M-6.9,-4 L6.9,4 M-6.9,4 L6.9,-4" stroke-width="2"/>` +
            `<path d="M-2.4,-6.6 L0,-4.4 L2.4,-6.6 M-2.4,6.6 L0,4.4 L2.4,6.6" stroke-width="1.4" stroke-linejoin="round"/></g>` +
            `<path d="M53,1.5 C57.5,6.5 61,10.5 60.2,15.5 A7.2,7.2 0 0 1 45.8,15.8 C45.6,12 48,9.6 49.6,7 C50.4,9.2 51.4,10.4 52.6,11.2 C53.6,8.4 54,5 53,1.5 Z" fill="url(#ti-flame)"/>` +
            `<path d="M53,10.5 C55.4,13 56.6,15 56,17 A3.2,3.2 0 0 1 49.8,17 C49.8,15 51.4,13.4 53,10.5 Z" fill="#ffe08a"/>` },
        { key: 'wisdom', label: 'ددانی ئەقڵ', body: () => molar('translate(35 31) rotate(28) scale(.9) translate(-32 -32)') + gum() +
            `<circle cx="12" cy="12" r="8.5" fill="url(#ti-blue)"/><text x="12" y="15.8" font-size="11" font-weight="700" text-anchor="middle" fill="#fff" font-family="Arial, sans-serif">8</text>` },
        { key: 'followup', label: 'مراجعە', body: () =>
            `<rect x="19" y="5" width="4" height="10" rx="2" fill="#64748b"/><rect x="41" y="5" width="4" height="10" rx="2" fill="#64748b"/>` +
            `<rect x="8" y="10" width="48" height="47" rx="6" fill="#fff" stroke="#cbd5e1" stroke-width="1.2"/>` +
            `<path d="M8,23 V16 Q8,10 14,10 H50 Q56,10 56,16 V23 Z" fill="url(#ti-blue)"/>` +
            `<circle cx="21" cy="16.5" r="1.6" fill="#fff" opacity=".8"/><circle cx="43" cy="16.5" r="1.6" fill="#fff" opacity=".8"/>` +
            molar('translate(30 40) scale(.46) translate(-32 -33)') +
            `<circle cx="49" cy="50" r="9" fill="#22c55e" stroke="#fff" stroke-width="2"/><path d="M44.6,50 L47.8,53.2 L53.4,46.8" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>` },
        { key: 'impression', label: 'قاڵب گرتن', body: () => {
            let marks = '';
            for (let i = 0; i < 7; i++) {
                const t = (160 - i * (140 / 6)) * Math.PI / 180;
                marks += `<ellipse cx="${(32 + 20.25 * Math.cos(t)).toFixed(1)}" cy="${(55 - 28.5 * Math.sin(t)).toFixed(1)}" rx="2.3" ry="1.7" fill="#6d28d9" opacity=".35"/>`;
            }
            return `<rect x="27.5" y="3" width="9" height="18" rx="2.5" fill="url(#ti-metal)" stroke="#8d97a3" stroke-width=".7"/>` +
                `<path d="${arch(55, 27, 36, 13.5, 21)}" fill="url(#ti-metal)" stroke="#8d97a3" stroke-width=".7"/>` +
                `<path d="${arch(55, 23.5, 32.5, 17, 24.5)}" fill="url(#ti-alginate)"/>` + marks; } },
        { key: 'retainer', label: 'ریتێنەر', body: () => {
            let teeth = '';
            for (let i = 0; i < 8; i++) {
                const deg = 160 - i * (140 / 7), t = deg * Math.PI / 180;
                teeth += `<rect x="-3.7" y="-4.4" width="7.4" height="8.8" rx="2.6" transform="translate(${(32 + 20.5 * Math.cos(t)).toFixed(1)} ${(52 - 31 * Math.sin(t)).toFixed(1)}) rotate(${(90 - deg).toFixed(1)})" fill="url(#ti-enamel)" stroke="#aeb8c4" stroke-width=".7"/>`;
            }
            return teeth + `<path d="${arch(52, 25.5, 36.5, 15.5, 25.5)}" fill="#bae6fd" fill-opacity=".5" stroke="#38bdf8" stroke-width="1.2"/>` +
                sparkle(54, 10, 5, 'url(#ti-blue)'); } },
        { key: 'consult', label: 'پرسیار / ڕاوێژ', body: () =>
            `<path d="M11,7 H53 Q59,7 59,13 V37 Q59,43 53,43 H31 L19,54 L21,43 H11 Q5,43 5,37 V13 Q5,7 11,7 Z" fill="url(#ti-blue)"/>` +
            `<path d="M11,9.5 H40" stroke="#fff" stroke-opacity=".35" stroke-width="2" stroke-linecap="round"/>` +
            `<text x="32" y="35" font-size="28" font-weight="700" text-anchor="middle" fill="#fff" font-family="Tahoma, Arial, sans-serif">؟</text>` }
    ];
    const BY_KEY = {};
    ICONS.concat(VISIT_ICONS).forEach(i => { BY_KEY[i.key] = i; });
    const bodies = {};                       // drawn once, on first use
    const bodyOf = key => bodies[key] || (bodies[key] = (BY_KEY[key] || BY_KEY.other).body());

    const DEFS = `
        <linearGradient id="ti-tooth" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffffff"/><stop offset=".52" stop-color="#f5f7fa"/><stop offset=".6" stop-color="#f2e6cc"/><stop offset="1" stop-color="#dcc59b"/></linearGradient>
        <linearGradient id="ti-enamel" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffffff"/><stop offset="1" stop-color="#e6ecf2"/></linearGradient>
        <linearGradient id="ti-root" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#f6ecd8"/><stop offset="1" stop-color="#dcc59b"/></linearGradient>
        <linearGradient id="ti-shade" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#3f4b5c" stop-opacity=".28"/><stop offset=".18" stop-color="#3f4b5c" stop-opacity=".05"/><stop offset=".36" stop-color="#ffffff" stop-opacity=".55"/><stop offset=".54" stop-color="#ffffff" stop-opacity="0"/><stop offset=".82" stop-color="#3f4b5c" stop-opacity=".08"/><stop offset="1" stop-color="#3f4b5c" stop-opacity=".3"/></linearGradient>
        <radialGradient id="ti-gloss"><stop offset="0" stop-color="#ffffff" stop-opacity=".95"/><stop offset="1" stop-color="#ffffff" stop-opacity="0"/></radialGradient>
        <linearGradient id="ti-gum" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#f6a19f"/><stop offset=".45" stop-color="#e4676a"/><stop offset="1" stop-color="#c6454d"/></linearGradient>
        <linearGradient id="ti-metal" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#f4f6f9"/><stop offset=".45" stop-color="#b9c1cb"/><stop offset=".7" stop-color="#eef1f5"/><stop offset="1" stop-color="#8e98a5"/></linearGradient>
        <linearGradient id="ti-steel" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#7f8a97"/><stop offset=".45" stop-color="#e9edf1"/><stop offset="1" stop-color="#76818e"/></linearGradient>
        <linearGradient id="ti-coral" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffb3a7"/><stop offset=".55" stop-color="#f26a5e"/><stop offset="1" stop-color="#d9473f"/></linearGradient>
        <linearGradient id="ti-pulp" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ff9a9a"/><stop offset="1" stop-color="#d63a4a"/></linearGradient>
        <radialGradient id="ti-cavity" cx=".45" cy=".4" r=".7"><stop offset="0" stop-color="#8a6552"/><stop offset=".6" stop-color="#5a3d30"/><stop offset="1" stop-color="#3b2820"/></radialGradient>
        <linearGradient id="ti-composite" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fdf6ea"/><stop offset="1" stop-color="#ead6b2"/></linearGradient>
        <radialGradient id="ti-glass" cx=".4" cy=".35" r=".75"><stop offset="0" stop-color="#f2f9ff"/><stop offset="1" stop-color="#8fbfe8"/></radialGradient>
        <linearGradient id="ti-blue" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#7cc0ff"/><stop offset="1" stop-color="#2f7fe0"/></linearGradient>
        <linearGradient id="ti-diamond" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#bff0ff"/><stop offset=".5" stop-color="#3fb6f2"/><stop offset="1" stop-color="#1767c7"/></linearGradient>
        <linearGradient id="ti-film" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#455a64"/><stop offset="1" stop-color="#1f2a30"/></linearGradient>
        <radialGradient id="ti-glow"><stop offset="0" stop-color="#cfe9ff" stop-opacity=".95"/><stop offset="1" stop-color="#cfe9ff" stop-opacity="0"/></radialGradient>
        <radialGradient id="ti-ache"><stop offset="0" stop-color="#ffc2c2" stop-opacity=".95"/><stop offset=".6" stop-color="#ffd6d6" stop-opacity=".5"/><stop offset="1" stop-color="#ffe4e4" stop-opacity="0"/></radialGradient>
        <linearGradient id="ti-bolt" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fb7185"/><stop offset="1" stop-color="#e11d48"/></linearGradient>
        <linearGradient id="ti-blood" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#f87171"/><stop offset="1" stop-color="#b91c1c"/></linearGradient>
        <radialGradient id="ti-abscess" cx=".42" cy=".38" r=".7"><stop offset="0" stop-color="#fff3b0"/><stop offset=".45" stop-color="#fdba74"/><stop offset="1" stop-color="#dc2626"/></radialGradient>
        <linearGradient id="ti-flame" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fbbf24"/><stop offset=".55" stop-color="#f97316"/><stop offset="1" stop-color="#ef4444"/></linearGradient>
        <linearGradient id="ti-alginate" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ede9fe"/><stop offset="1" stop-color="#a78bfa"/></linearGradient>
        <clipPath id="ti-chipped" clipPathUnits="userSpaceOnUse"><path clip-rule="evenodd" d="M-8,-8 H72 V72 H-8 Z M40,1 L62,1 L62,26 L50.7,21 L48.5,15.5 L44.5,14 L41.4,7.9 Z"/></clipPath>`;

    const CSS = `
        svg.ti { display: block; width: 100%; height: 100%; overflow: visible; filter: drop-shadow(0 3px 4px rgba(40, 60, 90, .16)); }
        .ti-chooser { display: grid; grid-template-columns: repeat(auto-fill, minmax(52px, 1fr)); gap: 6px; }
        .ti-tile { position: relative; display: grid; place-items: center; height: 52px; padding: 5px; border: 1.5px solid #e2e8f0;
                   border-radius: 10px; background: #fff; cursor: pointer; transition: border-color .15s, box-shadow .15s, transform .15s; }
        .ti-tile:hover { border-color: #93c5fd; transform: translateY(-1px); }
        .ti-tile.sug { border-style: dashed; border-color: #60a5fa; }
        .ti-tile.on { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, .16); background: #f5f9ff; }
        .ti-tile.on::after { content: '\\2713'; position: absolute; top: -6px; left: -6px; width: 16px; height: 16px; border-radius: 50%;
                             background: #2563eb; color: #fff; font-size: 10px; line-height: 16px; text-align: center; }
        .ti-chooser.ti-missing .ti-tile { border-color: #fca5a5; }`;

    function ensureDefs() {
        if (document.getElementById('ti-defs') || !document.body) return;
        const box = document.createElement('div');
        box.innerHTML = `<svg id="ti-defs" aria-hidden="true" focusable="false" style="position:absolute;width:0;height:0;overflow:hidden"><defs>${DEFS}</defs></svg>`;
        document.body.appendChild(box.firstChild);
        const style = document.createElement('style');
        style.textContent = CSS;
        document.head.appendChild(style);
    }

    function svg(key) {
        ensureDefs();
        return `<svg class="ti" viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">${bodyOf(BY_KEY[key] ? key : 'other')}</svg>`;
    }

    // ── an icon for a type saved before icons existed ──
    // Names are typed in several spellings (Arabic ك/ي, ه + ZWNJ for ە, ڕ or ر …),
    // so they are folded to one before matching.
    function fold(s) {
        return String(s || '').toLowerCase()
            .replace(/ك/g, 'ک').replace(/ي/g, 'ی').replace(/ه‌/g, 'ە').replace(/‌/g, '')
            .replace(/ڕ/g, 'ر').replace(/ێ/g, 'ی').replace(/ۆ/g, 'و').replace(/ڵ/g, 'ل');
    }
    const RULES = [
        [/دوبارە|دووبارە|retreat|re-?endo/, 'retreatment'],
        [/دەمار|endo|pulp|rct|root canal/, 'root_canal'],
        [/تاخم|denture/, 'denture'],
        [/میتەل|metal|pfm/, 'crown_metal'],
        [/چەسپاند|cement/, 'cementation'],
        [/کیف|تاج|crown|زیرکون|zircon|e-?max/, 'crown'],
        [/پرد|bridge/, 'bridge'],
        [/چاندن|ئیمپلانت|implant/, 'implant'],
        [/screw|post|پوست|بزمار/, 'post'],
        [/راستکردنەوە|ortho|brace|تیل|زنجیر/, 'orthodontic'],   // تێل / زنجیر: the wire, the braces
        [/سپیکردنەوە|whiten|bleach/, 'whitening'],
        [/ڤینیر|veneer|لامینیت|laminate/, 'veneer'],
        [/کاتی|temporary/, 'temporary'],
        [/(کیشان|extract).*(نەشتەرگەری|surg)|(نەشتەرگەری|surg).*(کیشان|extract)/, 'surgical_extraction'],
        [/کیشان|extract/, 'extraction'],
        [/شوشتن|پاککردنەوە|clean|scal|polish/, 'cleaning'],
        [/نەشتەرگەری|surg/, 'surgery'],
        [/(پرکردنەوە|fill).*(جوانکاری|کومپوزیت|composite)|کومپوزیت|composite/, 'filling_cosmetic'],
        [/پرکردنەوە|fill|amalgam/, 'filling'],
        [/تیشک|x-?ray|opg/, 'xray'],
        [/مندال|child|pedo/, 'pediatric'],
        [/پشکنین|exam|check|consult/, 'exam'],
        [/جوانکاری|cosmetic/, 'veneer']
    ];
    const BY_CATEGORY = {
        examination: 'exam', cleaning: 'cleaning', filling: 'filling', extraction: 'extraction',
        root_canal: 'root_canal', crown: 'crown', bridge: 'bridge', implant: 'implant',
        orthodontic: 'orthodontic', whitening: 'whitening', cosmetic: 'veneer', surgery: 'surgery', other: 'other'
    };
    function guess(name, category) {
        const n = fold(name);
        for (const [re, key] of RULES) if (re.test(n)) return key;
        return BY_CATEGORY[category] || 'other';
    }
    function keyFor(t) {
        return t && BY_KEY[t.icon] ? t.icon : guess(t && t.name, t && t.category);
    }

    // A work type is often what the patient says («ئازاری هەیە», «ددانی شکاوە»):
    // complaints first, then the treatment words, then the vague ones.
    const WORK_RULES = [
        [/ئەقلی|عەقلی|wisdom/, 'wisdom'],
        [/ریتینەر|retainer/, 'retainer'],
        [/قالب|impression/, 'impression'],
        [/خوین|bleed/, 'bleeding'],
        [/ئیلتهاب|هەوکردن|کیسە|ئاوسا|swell|abscess/, 'swelling'],
        [/هەستیار|سارد|گەرم|دەتەزی|sensitiv/, 'sensitive'],
        [/شکا|broke|fractur/, 'broken'],
        [/شل ?بو|loose/, 'loose'],
        [/ئیشاعە/, 'xray'],
        [/داو ?دەر|stitch|suture|عەمەلیات/, 'surgery']
    ];
    const WORK_LATE = [
        [/ئازار|کیشەی|مشکیلە|طەواری|تەواری|pain|ache|emergency/, 'pain'],
        [/مراجعە|follow|چیک/, 'followup'],
        [/پسیار|پرسیار|راویژ|consult/, 'consult']
    ];
    function guessWork(name) {
        const n = fold(name);
        for (const [re, key] of WORK_RULES) if (re.test(n)) return key;
        const k = guess(name);
        if (k !== 'other') return k;
        for (const [re, key] of WORK_LATE) if (re.test(n)) return key;
        return 'other';
    }

    /**
     * The icon grid for a treatment-type form. opts: {value, onChange(key)}.
     * suggest(key) rings the icon the name points at, without choosing it
     * («other» — the name matched nothing — rings nothing).
     * opts.set 'work' offers the complaint icons too, before the treatments.
     */
    function chooser(el, opts) {
        opts = opts || {};
        let value = BY_KEY[opts.value] ? opts.value : '';
        el.classList.add('ti-chooser');
        el.innerHTML = (opts.set === 'work' ? VISIT_ICONS.concat(ICONS) : ICONS).map(i =>
            `<button type="button" class="ti-tile" data-icon="${i.key}" title="${i.label}" aria-label="${i.label}">${svg(i.key)}</button>`).join('');
        const paint = () => el.querySelectorAll('.ti-tile').forEach(b => b.classList.toggle('on', b.dataset.icon === value));
        el.addEventListener('click', e => {
            const b = e.target.closest('.ti-tile');
            if (!b) return;
            value = b.dataset.icon;
            el.classList.remove('ti-missing');
            paint();
            if (opts.onChange) opts.onChange(value);
        });
        paint();
        return {
            get: () => value,
            set: k => { value = BY_KEY[k] ? k : ''; paint(); },
            suggest: k => el.querySelectorAll('.ti-tile').forEach(b =>
                b.classList.toggle('sug', !!k && k !== 'other' && b.dataset.icon === k && k !== value)),
            missing: () => el.classList.add('ti-missing')
        };
    }

    window.TreatmentIcons = {
        list: ICONS.map(i => ({ key: i.key, label: i.label })),
        label: key => (BY_KEY[key] || BY_KEY.other).label,
        has: key => !!BY_KEY[key],
        svg: svg,
        fold: fold,
        guess: guess,
        guessWork: guessWork,
        keyFor: keyFor,
        chooser: chooser,
        ensureDefs: ensureDefs
    };
})();
