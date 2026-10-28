/* assets/js/tooth-sound.js — a soft note as the mouse moves onto a tooth of the
 * chart (#smartDentalChart), and the speaker button (#toothSoundBtn) that turns
 * it off. Shared by cases/view.php and treatment-plans/view.php. */
// ── A soft note as the mouse moves onto a tooth ─────────────────────
// Each tooth plays one note of a pentatonic scale, so any run of teeth is in
// tune: front teeth highest, back teeth lowest, the upper jaw a fourth above
// the lower — running the mouse along the arch plays a little tune. The notes
// are synthesised (Web Audio), nothing is downloaded. Browsers keep a page
// silent until it has been clicked or typed on, so hovers before the first
// click make no sound. The speaker button by the chart title turns it off;
// the choice is remembered on this computer.
const TOOTH_SOUND_KEY = 'dentHisab.toothHoverSound';
const TOOTH_SOUND_VOLUME = 0.06;
const TOOTH_SOUND_STEPS = [0, 2, 4, 7, 9, 12, 14, 16];   // semitones, back tooth → front tooth
let toothSoundOn = true;
try { toothSoundOn = localStorage.getItem(TOOTH_SOUND_KEY) !== 'off'; } catch (e) { /* private mode */ }
let toothAudio = null, toothAudioLast = -1;   // -1: a context's clock starts at 0, and its first note must play

function toothAudioContext() {
    if (!toothAudio) {
        const AC = window.AudioContext || window.webkitAudioContext;
        if (!AC) return null;
        toothAudio = new AC();
    }
    if (toothAudio.state === 'suspended') toothAudio.resume();
    return toothAudio;
}

function toothNoteFreq(toothEl) {
    const pos = parseInt(toothEl.dataset.tooth, 10) % 10;          // 1 front … 8 back (milk teeth 1-5)
    const step = TOOTH_SOUND_STEPS[Math.max(0, Math.min(7, 8 - pos))];
    const base = (toothEl.dataset.quadrant || '').charAt(0) === 'U' ? 523.25 : 392;   // C5 / G4
    return base * Math.pow(2, step / 12);
}

function playToothNote(freq) {
    const c = toothAudio;
    if (!toothSoundOn || !c || c.state !== 'running') return;
    const now = c.currentTime;
    if (now - toothAudioLast < 0.035) return;      // a fast sweep stays a ripple, not a buzz
    toothAudioLast = now;
    // a soft mallet: the note rings, two overtones flash and die away
    [[1, 1, 0.42], [3, 0.2, 0.14], [5.04, 0.06, 0.07]].forEach(([mult, peak, decay]) => {
        const osc = c.createOscillator(), gain = c.createGain();
        osc.type = 'sine';
        osc.frequency.value = freq * mult;
        gain.gain.setValueAtTime(0.0001, now);
        gain.gain.exponentialRampToValueAtTime(peak * TOOTH_SOUND_VOLUME, now + 0.006);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + decay);
        osc.connect(gain).connect(c.destination);
        osc.start(now);
        osc.stop(now + decay + 0.02);
    });
}

function renderToothSoundBtn() {
    const btn = document.getElementById('toothSoundBtn');
    if (!btn) return;
    btn.classList.toggle('is-off', !toothSoundOn);
    btn.setAttribute('aria-pressed', toothSoundOn ? 'true' : 'false');
    btn.querySelector('i').className = 'fas ' + (toothSoundOn ? 'fa-volume-up' : 'fa-volume-mute');
}

function toggleToothSound() {
    toothSoundOn = !toothSoundOn;
    try { localStorage.setItem(TOOTH_SOUND_KEY, toothSoundOn ? 'on' : 'off'); } catch (e) { /* private mode */ }
    renderToothSoundBtn();
    if (toothSoundOn && toothAudioContext()) playToothNote(659.25);   // one note to say it is on
}

document.addEventListener('DOMContentLoaded', function () {
    const chart = document.getElementById('smartDentalChart');
    if (!chart) return;
    renderToothSoundBtn();
    // a click or key press is what lets the page make sound
    const unlock = function () { if (toothSoundOn) toothAudioContext(); };
    document.addEventListener('pointerdown', unlock, true);
    document.addEventListener('keydown', unlock, true);
    let current = null;
    chart.addEventListener('pointerover', function (e) {
        if (e.pointerType !== 'mouse') return;
        const toothEl = e.target.closest('.tooth[data-tooth]');
        if (toothEl === current) return;
        current = toothEl;
        if (toothEl) playToothNote(toothNoteFreq(toothEl));
    });
    chart.addEventListener('pointerleave', function () { current = null; });
});
