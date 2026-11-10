<?php
/**
 * دەرمانی نەخۆش — the prescription dialog: the clinic's paper sheet on screen,
 * the doses, sending to the pharmacy, the earlier requests. One copy for
 * every page that writes medicine:
 *
 *   cases/view.php            — the case's own (case_id set)
 *   treatment-plans/view.php  — a plan's patient, no case
 *   waiting-room.php          — the doctor's room: a patient examined who
 *                               only needs medicine, no case and no plan;
 *                               openMedicineDialog({...}) per patient
 *
 * Set $rxCtx before including: patient_id, case_id (0 = none), patient_name,
 * doctor_id, doctor_name. Without a case the prescription stands on the
 * patient (erp2_prescriptions.case_id NULL) and the history lists the
 * patient's. Talks to erp2/api/prescriptions.php.
 */
$rxCtx = (isset($rxCtx) && is_array($rxCtx) ? $rxCtx : []) + [
    'patient_id' => 0, 'case_id' => 0, 'patient_name' => '', 'doctor_id' => 0, 'doctor_name' => '',
];
$rxCanPrescribe = Permission::getInstance()->can('erp2.prescriptions.create');
$rxCanSee       = Permission::getInstance()->can('erp2.prescriptions.view');
// «ڕێکخستنی پەڕە»: the admin (or a role given the right) arranges the sheet
$rxCanArrange   = $rxCanPrescribe && Permission::getInstance()->can('erp2.rx_sheet.manage');
if (!$rxCanPrescribe && !$rxCanSee) {
    return;
}
?>
<style>
/* دەرمان — a large dialog (openMedicineDialog); the sheet scrolls inside it */
/* Full screen: the whole sheet in sight, every column side by side */
.rx-dialog { max-width: none; width: 100vw; height: 100vh; height: 100dvh; max-height: none; border-radius: 0; }
/* A big close button, easy to hit */
.rx-dialog-header .dialog-close { flex: 0 0 auto; width: 48px; height: 48px; border-radius: 12px; border: 2px solid #fecaca;
    background: #fff; color: #dc2626; font-size: 24px !important; box-shadow: 0 2px 8px rgba(15, 23, 42, .15); }
.rx-dialog-header .dialog-close i { font-size: 24px !important; }
.rx-dialog-header .dialog-close:hover, .rx-dialog-header .dialog-close:focus-visible { background: #dc2626; border-color: #dc2626; color: #fff; outline: none; }
.rx-dialog-header h4 { display: flex; align-items: center; gap: 10px; margin: 0; }
.rx-dialog-header h4 .badge { font-size: 11px; font-weight: 600; }
/* Fill the tall dialog: the page-wide .dialog-body cap (85vh - 110px) left an
   empty band under a clipped sheet. min-height:0 lets it scroll inside. */
.rx-dialog-body { padding: 14px 18px 18px; max-height: none; min-height: 0; }
/* Roomy columns in the dialog (4 across on a wide screen): the paper's six
   across at ~150px squeezed names and spilled the type labels into the
   neighbouring group. Beats the .rxs container query by specificity. */
.rx-dialog .rxs-grid { grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 8px; }
/* Compact type in the dialog — the paper-sheet sizes were too big on screen */
/* !important on the sizes: the app's text-size setting (body.app-font-scaled)
   forces its own size on every div/span/label/button with !important; these
   selectors are more specific, so the dialog stays compact either way. */
/* The project font everywhere in the dialog — buttons and inputs do not
   inherit it on their own (their "font: 14px inherit" shorthand is invalid). */
.rx-dialog, .rx-dialog button, .rx-dialog input, .rx-dialog select, .rx-dialog textarea { font-family: var(--app-font-family) !important; }
.rx-dialog .rxs-pt, .rx-dialog .rxs-pt bdi { font-size: 15px !important; }
.rx-dialog .rxs-legend, .rx-dialog .rxs-find input { font-size: 13px !important; }
.rx-dialog .rxs-head { padding: 8px 10px 7px; min-height: 0; }
.rx-dialog .rxs-head h5 { font-size: 16px; margin-bottom: 6px; }
.rx-dialog .rxs-types { gap: 3px 7px; }
.rx-dialog .rxs-t { font-size: 13px !important; gap: 6px; }
.rx-dialog .rxs-t::before { width: 13px; height: 13px; }
.rx-dialog .rxs-body { padding: 6px 7px 9px; margin-top: 5px; }
.rx-dialog .rxs-it, .rx-dialog .rxs-it span { font-size: 13px !important; }
.rx-dialog .rxs-it { padding: 3px 5px; gap: 6px; line-height: 1.35; }
.rx-dialog .rxs-it .bx { width: 16px; height: 16px; font-size: 10px !important; }
.rx-dialog .rxs-it .tp, .rx-dialog .rxs-it .ds { font-size: 11px !important; }
.rx-dialog .rxs-q, .rx-dialog .rxs-it .rxs-q { height: 17px; min-width: 21px; padding: 0 5px; font-size: 11px !important; }
/* Two steps: هەڵبژاردن (the sheet) / ناردن (doses, notes, send, history) */
.rx-tabs { display: flex; gap: 4px; border-bottom: 2px solid #e2e8f0; margin: -4px 0 12px; }
.rx-tab { display: flex; align-items: center; gap: 7px; margin-bottom: -2px; padding: 9px 18px; border: 0; border-bottom: 3px solid transparent;
    background: none; color: #64748b; font-size: 14px !important; font-weight: 700; cursor: pointer; }
.rx-tab:hover { color: #334155; }
.rx-tab.on { color: var(--win-accent, #0078D4); border-bottom-color: var(--win-accent, #0078D4); }
.rx-tab-count { display: inline-grid; place-items: center; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 10px;
    background: #e2e8f0; color: #334155; font-size: 11px !important; font-weight: 800; }
.rx-tab.on .rx-tab-count { background: var(--win-accent, #0078D4); color: #fff; }
.rx-pane[hidden] { display: none; }
/* A circled type filters its group (renderSheet) */
.rxs-it.typed-out { display: none; }
.rx-dialog .rxs-col.is-filtered .rxs-head { box-shadow: inset 0 -3px 0 #2e7d32; }
.rx-dialog .rxs-t.on { color: #1b5e20; }
/* a ticked medicine of this type: its round choice in the title carries a
   green tick (or the count, for more than one) on a green pill, so the head
   of the column shows what is chosen */
.rx-dialog .rxs-t { border-radius: 999px; padding: 1px 6px 1px 1px; }
.rxs-t.has-pick { background: #dcfce7; box-shadow: inset 0 0 0 1px #86efac; color: #14532d; font-weight: 800; }
.rx-dialog .rxs-t.has-pick { color: #14532d; }
.rxs-t.has-pick::before { display: none; }
.rxs-tk { flex: 0 0 auto; display: inline-grid; place-items: center; width: 16px; height: 16px; border-radius: 50%;
    background: #16a34a; color: #fff; font-weight: 800; line-height: 1; box-shadow: 0 0 0 2px #fff; }
.rx-dialog .rxs-tk, .rx-dialog .rxs-tk i { font-size: 9px !important; }
.rxs-none { padding: 8px 6px; color: #64748b; font-size: 11.5px !important; text-align: center; direction: rtl; }
/* The way on to step two, kept in view while scrolling the sheet */
.rx-next { position: sticky; bottom: -18px; z-index: 2; display: flex; align-items: center; justify-content: space-between; gap: 12px;
    margin-top: 10px; padding: 10px 14px; border: 1px solid #a7f3d0; border-radius: 10px; background: #ecfdf5; color: #065f46;
    box-shadow: 0 -6px 16px rgba(15, 23, 42, .08); }
.rx-next[hidden] { display: none; }
.rx-next span { font-size: 14px !important; font-weight: 700; }
.rx-search-wrap { position: relative; margin-bottom: 14px; }
.rx-label { display:block; font-size:12px; color:#666; margin-bottom:5px; font-weight:600; }
.rx-input { width:100%; border:1.5px solid #e0e0e0; border-radius:8px; padding:9px 11px; font:13px inherit; background:#fff; color:#222; }
.rx-input:focus { outline:none; border-color:var(--win-accent, #0078d4); box-shadow:0 0 0 3px rgba(var(--win-accent-rgb, 0, 120, 212), .1); }
.rx-ac { position:absolute; z-index:60; left:0; right:0; background:#fff; border:1px solid #ccc; border-radius:8px;
         box-shadow:0 8px 22px rgba(0,0,0,.16); max-height:280px; overflow:auto; margin-top:4px; }
.rx-ac-item { padding:9px 12px; cursor:pointer; border-bottom:1px solid #f2f2f2; display:flex; justify-content:space-between; gap:10px; }
.rx-ac-item:hover, .rx-ac-item.on { background:#e8f4fd; }
.rx-ac-item .nm { font-size:13px; font-weight:600; }
.rx-ac-item .mt { font-size:11px; color:#888; }
.rx-ac-item .st { font-size:12px; color:#2e7d32; font-weight:700; direction:ltr; white-space:nowrap; }
.rx-table-wrap { border:1px solid #e6e6e6; border-radius:8px; overflow:hidden; }
.rx-table { width:100%; border-collapse:collapse; font-size:13px; }
.rx-table th { background:#f8f9fa; padding:9px 10px; text-align:right; font-size:12px; font-weight:600; color:#444; border-bottom:1px solid #e0e0e0; }
.rx-table td { padding:6px 10px; border-bottom:1px solid #f2f2f2; }
.rx-table tr:last-child td { border-bottom:0; }
.rx-table input { width:100%; border:1px solid #e2e2e2; border-radius:6px; padding:6px 8px; font:13px inherit; }
.rx-table input:focus { outline:none; border-color:var(--win-accent, #0078d4); }
/* دەرمانی دەرەوە — a line by name, bought outside */
.rx-add-outside { display:flex; align-items:center; gap:10px; width:100%; padding:10px 14px; border:0; border-top:1px dashed #f0c27a;
                  background:#fffaf0; color:#8a5a00; font:inherit; text-align:right; cursor:pointer; }
.rx-add-outside:hover { background:#fff3dc; }
.rx-add-outside > i { width:26px; height:26px; border-radius:50%; display:grid; place-items:center; background:#f59e0b; color:#fff; font-size:12px; flex:0 0 auto; }
.rx-add-outside span { display:flex; flex-direction:column; }
.rx-add-outside b { font-size:13.5px; }
.rx-add-outside small { font-size:11.5px; color:#a0742a; }
.rx-row-outside td { background:#fffcf5; }
.rx-row-outside .rx-out-name { border-color:#f0c27a; background:#fff; }
.rx-outside-tag { display:inline-flex; align-items:center; gap:5px; padding:2px 9px; border-radius:999px; background:#fff1d6; color:#9a5b00;
                  font-size:11.5px; font-weight:700; white-space:nowrap; }
.rxs-out-sug { display:inline-flex; align-items:center; gap:6px; padding:5px 12px; border:1px dashed #f0c27a; border-radius:999px;
               background:#fffaf0; color:#8a5a00; font:inherit; font-size:12.5px; cursor:pointer; }
.rxs-out-sug:hover { background:#fff3dc; }
.rxs-out-sug[hidden] { display:none; }
.rx-del { color:#c62828; cursor:pointer; text-align:center; }
.rx-stock { direction:ltr; text-align:left; font-variant-numeric:tabular-nums; color:#2e7d32; font-weight:600; }
.rx-empty { text-align:center; color:#999; padding:22px 12px; font-size:13px; }
.rx-foot { display:flex; gap:10px; align-items:center; margin-top:12px; }
.rx-foot .rx-input { flex:1; }
.rx-msg { margin-top:8px; font-size:12px; }
.rx-msg.ok { color:#2e7d32; }
.rx-msg.err { color:#c62828; }
.rx-msg.warn { color:#b45309; font-weight:600; }
.rx-out { color:#c62828; font-weight:700; }
.rx-preset { display:block; width:100%; margin-top:4px; border:1px solid #c7d2fe; border-radius:6px; padding:4px 6px; font:12px inherit; background:#f5f7ff; color:#3730a3; }
.rx-form { display:inline-block; margin-inline-start:6px; font-size:11px; font-weight:700; padding:1px 7px; border-radius:999px; background:#e0f2fe; color:#0369a1; }

/* the paper prescription sheet — laid out left to right exactly like the
   printed one: Antibiotic, Analgesic, Toothpaste, Brush, Mouth Wash, Other */
.rxs { border:1px solid #cfe3ef; border-radius:12px; padding:14px; background:#fff; margin-bottom:14px; }
.rxs-top { display:flex; align-items:center; gap:14px; flex-wrap:wrap; padding-bottom:10px; margin-bottom:12px; border-bottom:2px solid #2b7bb0; }
.rxs-pt { font-size:16px; font-weight:800; color:#0f172a; direction:ltr; }
.rxs-pt bdi { font-weight:700; color:#0369a1; }
.rxs-find { position:relative; margin-inline-start:auto; width:min(260px, 100%); }
.rxs-find i { position:absolute; top:50%; inset-inline-start:10px; transform:translateY(-50%); color:#94a3b8; font-size:12px; }
.rxs-find input { width:100%; height:34px; border:1.5px solid #dbe3ea; border-radius:9px; padding-inline:30px 10px; font:13px inherit; }
.rxs-find input:focus { outline:none; border-color:#2b7bb0; }
.rxs-legend { font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; }
.rxs-dot { width:8px; height:8px; border-radius:50%; background:#f59e0b; display:inline-block; }
/* six across like the paper when the tab is wide; otherwise an even 3 + 3 */
.rxs { container-type:inline-size; }
.rxs-grid { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:6px; direction:ltr; text-align:left; }
@container (min-width: 1050px) { .rxs-grid { grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); } }
@container (max-width: 560px)  { .rxs-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); } }
.rxs-col { display:flex; flex-direction:column; min-width:0; }
.rxs-head { background:#bde0f2; padding:10px 12px 9px; min-height:122px; }
.rxs-head h5 { margin:0 0 8px; font-size:22px; font-weight:800; color:#0b0b0b; text-align:center; letter-spacing:.2px; }
/* two per row while two fit, else one: a long name («PARACETAMOL 1000MG») wraps
   at its spaces inside its own cell instead of running past the column's edge */
.rxs-types { display:grid; grid-template-columns:repeat(auto-fit, minmax(min(100%, 9em), 1fr)); gap:3px 8px; }
.rxs-t { display:flex; align-items:center; gap:6px; min-width:0; border:0; background:none; padding:1px 0; font:14px inherit; color:#111; cursor:pointer; text-align:left;
    overflow-wrap:break-word; line-height:1.25; }
.rxs-t::before { content:""; width:15px; height:15px; flex:0 0 auto; border-radius:50%; border:1.5px solid #2e7d32; background:#fff; box-sizing:border-box; }
.rxs-t.on { font-weight:800; }
.rxs-t.on::before { background:#2e7d32; box-shadow:inset 0 0 0 2.5px #fff; }
.rxs-body { background:#dff0f8; padding:8px 8px 10px; margin-top:6px; flex:1; }
.rxs-it { position:relative; display:flex; align-items:center; gap:7px; padding:4px 5px; border-radius:6px; cursor:pointer; font-size:13.5px; color:#111; line-height:1.35; user-select:none; }
.rxs-it:hover { background:#cbe6f3; }
.rxs-it input { position:absolute; opacity:0; pointer-events:none; }
.rxs-it .bx { width:18px; height:18px; flex:0 0 auto; border:1.5px solid #2e7d32; background:#fff; display:grid; place-items:center; color:#fff; font-size:11px; }
.rxs-it.on { background:#c8e6c9; font-weight:700; }
.rxs-it.on .bx { background:#2e7d32; }
.rxs-it input:focus-visible + .bx { outline:2px solid #2b7bb0; outline-offset:2px; }
.rxs-it .nm { min-width:0; overflow:hidden; text-overflow:ellipsis; }
.rxs-it .tp { margin-left:auto; font-size:10.5px; font-weight:700; color:#0369a1; white-space:nowrap; }
/* stock on the pharmacy shelf, beside every name: green with the count, amber 0 when out */
.rxs-q { flex:0 0 auto; min-width:22px; height:18px; padding:0 6px; border-radius:9px; display:inline-grid; place-items:center;
    background:#dcfce7; color:#166534; font-size:11px; font-weight:800; line-height:1; direction:ltr; font-variant-numeric:tabular-nums; }
.rxs-q.zero { background:#fef3c7; color:#b45309; }
.rxs-legend .rxs-q { margin-inline-start:4px; }
.rxs-it.dim { opacity:.32; }
/* a ticked item: its dose under the name, and the dose button beside it */
.rxs-it .nmw { display:flex; flex-direction:column; min-width:0; flex:1; }
.rxs-it .nmw .nm { overflow:visible; white-space:normal; }
.rxs-it.on .rxs-dose { margin-left:auto; }
.rxs-it .ds { font-size:11px; font-weight:600; color:#0f766e; line-height:1.3; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%; direction:rtl; text-align:left; }
.rxs-it .ds.none { color:#b45309; }
.rxs-dose { flex:0 0 auto; width:24px; height:24px; padding:0; border-radius:7px; border:1px solid #99f6e4; background:#fff; color:#0f766e;
    display:grid; place-items:center; font-size:11px; cursor:pointer; transition:background .12s, color .12s; }
.rxs-dose:not(.set) { border-color:#fcd34d; color:#b45309; background:#fffbeb; }
.rxs-dose:hover, .rxs-dose:focus-visible { background:#0f766e; border-color:#0f766e; color:#fff; outline:none; }
/* «ڕێکخستنی پەڕە» — the admin arranges the sheet (erp2_rx_sheet_layout) */
.rxs-arrange { display:inline-flex; align-items:center; gap:6px; padding:5px 12px; border:1px solid #fcd34d; border-radius:999px;
    background:#fffbeb; color:#92400e; font-size:12.5px !important; font-weight:700; cursor:pointer; }
.rxs-arrange:hover { background:#fef3c7; }
.rxs-arrange.on { background:#f59e0b; border-color:#f59e0b; color:#fff; }
.rxs-editbar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:-4px 0 10px; padding:8px 12px; border:1px dashed #f59e0b;
    border-radius:10px; background:#fffbeb; color:#92400e; direction:rtl; }
.rxs-editbar[hidden] { display:none; }
.rxs-editbar > span { flex:1; min-width:220px; font-size:12.5px !important; line-height:1.6; }
.rxs-editbar button { display:inline-flex; align-items:center; gap:6px; padding:5px 12px; border-radius:8px; font-size:12.5px !important; font-weight:700; cursor:pointer; }
.rxs-editbar .rs { border:1px solid #fecaca; background:#fff; color:#b91c1c; }
.rxs-editbar .rs:hover { background:#fef2f2; }
.rxs-editbar .dn { border:0; background:#0f766e; color:#fff; }
.rxs-editbar .st { font-size:12px !important; font-weight:700; }
.rxs-editbar .st.ok { color:#15803d; }
.rxs-editbar .st.err { color:#b91c1c; }
#rxSheet.is-editing ~ .rx-next { display:none !important; }
.rxs-grid.is-editing .rxs-col.ed { outline:1px dashed #94a3b8; outline-offset:2px; }
.rxs-col.ed.off { opacity:.55; }
.rxs-col.ed.off .rxs-head { background:repeating-linear-gradient(135deg, #e2e8f0 0 8px, #f1f5f9 8px 16px); }
.rxs-col .offtag { margin:-2px 0 6px; text-align:center; font-size:11px !important; font-weight:800; color:#b45309; direction:rtl; }
.rxs-eh { display:flex; align-items:center; justify-content:center; gap:6px; cursor:pointer; border-radius:6px; }
.rxs-eh:hover, .rxs-eh:focus-visible { background:rgba(255,255,255,.55); outline:none; }
.rxs-grid.is-editing .gr { color:#64748b; font-size:11px; cursor:grab; }
.rxs-grid.is-editing .rn { font-size:9px; color:#b45309; }
.rxs-grid.is-editing .mv { font-size:10px; color:#7c3aed; }
.rxs-t.ed { cursor:grab; border-radius:5px; }
.rxs-t.ed:hover, .rxs-t.ed:focus-visible { background:rgba(255,255,255,.6); outline:none; }
.rxs-t.ed.off { opacity:.45; text-decoration:line-through; }
.rxs-it.ed { cursor:grab; }
.rxs-it.ed:focus-visible { outline:2px solid #f59e0b; outline-offset:1px; }
.rxs-it.ed .rxs-q { margin-left:auto; }
.rxs-it.ed.off { opacity:.5; }
.rxs-it.ed.off .nm { text-decoration:line-through; }
.rxs-eye { flex:0 0 auto; width:22px; height:22px; padding:0; border:0; border-radius:6px; background:none; color:#94a3b8; font-size:11px; cursor:pointer; }
.rxs-it.ed:hover .rxs-eye, .rxs-eh:hover .rxs-eye { color:#0369a1; }
.rxs-eye:hover { background:#fff; color:#0369a1; }
.rxs-eye.off { color:#b45309; }
.rxs-eh .rxs-eye { font-size:12px; }
.rxs-grid .dragging { opacity:.35; }
.rxs-it.drop-before { box-shadow:0 -3px 0 #f59e0b; }
.rxs-it.drop-after { box-shadow:0 3px 0 #f59e0b; }
.rxs-t.drop-before, .rxs-col.drop-before { box-shadow:-3px 0 0 #f59e0b; }
.rxs-t.drop-after, .rxs-col.drop-after { box-shadow:3px 0 0 #f59e0b; }
.rxs-body.drop-in { outline:2px dashed #f59e0b; outline-offset:-2px; }
/* دەرمانی دەرەوە on the sheet: no stock, a purple «دەرەوە» tag instead */
.rxs-ox { flex:0 0 auto; display:inline-flex; align-items:center; gap:4px; height:18px; padding:0 7px; border-radius:9px;
    background:#f3e8ff; color:#6b21a8; font-size:10.5px; font-weight:800; white-space:nowrap; direction:rtl; line-height:1; }
.rxs-ox i { font-size:9px; }
.rx-dialog .rxs-ox, .rx-dialog .rxs-it .rxs-ox { height:17px; font-size:10.5px !important; }
.rx-dialog .rxs-ox i { font-size:9px !important; }
.rxs-it.ed .rxs-ox { margin-left:auto; }
.rxs-addox { display:flex; align-items:center; justify-content:center; gap:6px; width:100%; margin-top:6px; padding:5px 8px;
    border:1.5px dashed #c4b5fd; border-radius:8px; background:rgba(255,255,255,.65); color:#6b21a8; font-weight:700; cursor:pointer; direction:rtl; }
.rx-dialog .rxs-addox { font-size:12px !important; }
.rxs-addox:hover, .rxs-addox:focus-visible { background:#faf5ff; border-color:#a855f7; outline:none; }
.rxs-ed .tp.ox { background:#f3e8ff; color:#6b21a8; }
.rxs-ed .hd > i.fa-store-slash { color:#7c3aed; }
.rxs-ed .oxnote { margin-bottom:10px; padding:7px 9px; border-radius:9px; background:#faf5ff; color:#6b21a8; font-size:11.5px; line-height:1.6; }
.rxs-ed input.tx { width:100%; height:36px; padding:0 9px; border:1.5px solid #dbe3ea; border-radius:9px; font-size:13px; box-sizing:border-box; }
.rxs-ed input.tx:focus { outline:none; border-color:#7c3aed; box-shadow:0 0 0 3px rgba(124,58,237,.12); }
.rxs-ed .okw { display:flex; align-items:center; justify-content:center; gap:7px; width:100%; margin-bottom:8px; padding:8px; border:0;
    border-radius:9px; background:#0f766e; color:#fff; font-size:13px; font-weight:700; cursor:pointer; }
.rxs-ed .row2 { display:flex; gap:6px; }
.rxs-ed .row2 .hide { flex:1; }
.rxs-ed .del { flex:0 0 auto; display:flex; align-items:center; gap:6px; padding:8px 12px; border:1px solid #fecaca; border-radius:9px;
    background:#fff; color:#b91c1c; font-size:13px; font-weight:700; cursor:pointer; }
.rxs-ed .del:hover { background:#fef2f2; }
/* the panel for one thing on the sheet */
.rxs-pop.rxs-ed { width:380px; }
.rxs-ed .fl { display:grid; gap:4px; margin-bottom:8px; font-size:12px; font-weight:700; color:#475569; }
.rxs-ed select { height:36px; padding:0 8px; border:1.5px solid #dbe3ea; border-radius:9px; background:#fff; font-size:13px; }
.rxs-ed .erp { margin:-3px 0 10px; font-size:11.5px; color:#64748b; }
.rxs-ed .erp bdi { font-weight:700; color:#334155; }
.rxs-ed .lnk { border:0; padding:0; background:none; color:#0369a1; font-size:11.5px; font-weight:700; cursor:pointer; text-decoration:underline; }
.rxs-ed .ord { display:flex; align-items:center; gap:5px; flex-wrap:wrap; margin-bottom:10px; font-size:12px; font-weight:700; color:#475569; }
.rxs-ed .ord > span { margin-inline-end:2px; }
.rxs-ed .ord button { display:inline-flex; align-items:center; gap:5px; padding:5px 9px; border:1px solid #dbe3ea; border-radius:8px; background:#fff;
    font-size:12px; font-weight:700; color:#334155; cursor:pointer; }
.rxs-ed .ord button:hover:not(:disabled) { border-color:#0f766e; color:#0f766e; }
.rxs-ed .ord button:disabled { opacity:.4; cursor:default; }
.rxs-ed .hide { display:flex; align-items:center; justify-content:center; gap:7px; width:100%; padding:8px; border:1px solid #fecaca; border-radius:9px;
    background:#fef2f2; color:#b91c1c; font-size:13px; font-weight:700; cursor:pointer; }
.rxs-ed .hide.back { border-color:#bbf7d0; background:#f0fdf4; color:#15803d; }
.rxs-ed .nt { margin-top:6px; font-size:11px; color:#94a3b8; line-height:1.5; }
/* the dose panel (and the arranging panel): above the case page's dialogs,
   which stand at 3000 here */
.rxs-pop { position:fixed; z-index:3100; width:330px; max-width:calc(100vw - 16px); padding:10px; direction:rtl; background:#fff;
    border:1px solid #cfe3ef; border-radius:14px; box-shadow:0 22px 48px -16px rgba(15,23,42,.45); }
.rxs-pop[hidden] { display:none; }
.rxs-pop .hd { display:flex; align-items:center; gap:7px; padding:0 2px 8px; margin-bottom:8px; border-bottom:1px solid #eef2f6; }
.rxs-pop .hd > i { color:#0f766e; }
.rxs-pop .hd bdi { font-size:14px; font-weight:800; color:#0f172a; }
.rxs-pop .hd .tp { font-size:10.5px; font-weight:800; padding:1px 7px; border-radius:999px; background:#e0f2fe; color:#0369a1; }
.rxs-pop .hd .x { margin-inline-start:auto; width:26px; height:26px; border:0; border-radius:8px; background:#f1f5f9; color:#64748b; font-size:16px; line-height:1; cursor:pointer; }
.rxs-pop .ps { display:grid; gap:5px; margin-bottom:8px; }
.rxs-pop .pr { display:flex; align-items:center; gap:7px; width:100%; padding:8px 9px; border:1.5px solid #e2e8f0; border-radius:10px;
    background:#f8fafc; text-align:start; font:12.5px inherit; color:#1e293b; cursor:pointer; }
.rxs-pop .pr:hover, .rxs-pop .pr:focus-visible { border-color:#0f766e; background:#f0fdfa; outline:none; }
.rxs-pop .pr.on { border-color:#0f766e; background:#ccfbf1; font-weight:700; }
.rxs-pop .pr .tx { flex:1; min-width:0; }
.rxs-pop .pr > i { color:#0f766e; }
.rxs-pop .pr .lb { flex:0 0 auto; padding:1px 7px; border-radius:999px; background:#ede9fe; color:#5b21b6; font-size:10.5px; font-weight:800; }
.rxs-pop .nops { margin-bottom:8px; font-size:12px; color:#94a3b8; }
.rxs-pop .cu { display:flex; gap:6px; }
.rxs-pop .cu input { flex:1; min-width:0; height:36px; padding:0 9px; border:1.5px solid #dbe3ea; border-radius:9px; font:13px inherit; }
.rxs-pop .cu input:focus { outline:none; border-color:#0f766e; box-shadow:0 0 0 3px rgba(15,118,110,.12); }
.rxs-pop .cu .ok { width:36px; height:36px; border:0; border-radius:9px; background:#0f766e; color:#fff; cursor:pointer; }
.rxs-pop .qt { display:flex; align-items:center; gap:8px; margin-top:8px; font-size:12.5px; font-weight:700; color:#475569; }
.rxs-pop .qt button { width:30px; height:30px; border:1px solid #dbe3ea; border-radius:8px; background:#fff; font-size:16px; font-weight:800; cursor:pointer; }
.rxs-pop .qt button:hover { border-color:#0f766e; color:#0f766e; }
.rxs-pop .qt b { min-width:24px; text-align:center; font-size:15px; color:#0f172a; direction:ltr; }
.rxs-pop .qt small { color:#94a3b8; font-weight:600; }
.rxs-it.gone { display:none; }
.rxs-doc { margin-top:16px; text-align:center; direction:rtl; }
.rxs-doc h5 { margin:0 0 4px; font-size:20px; font-weight:800; color:#0b0b0b; direction:ltr; }
.rxs-doc div { font-size:13px; font-weight:700; color:#0369a1; border-bottom:1px dotted #94a3b8; padding-bottom:6px; }
.rx-history .rx-card { border:1px solid #e6e6e6; border-radius:8px; padding:10px 12px; margin-bottom:8px; }
.rx-history .rx-card .top { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; }
.rx-history .rx-card .items { font-size:12px; color:#666; margin-top:6px; line-height:1.8; }
.rx-badge { border-radius:10px; padding:2px 9px; font-size:11px; font-weight:700; }
.rx-badge.pending { background:#fff4e5; color:#8a5a00; }
.rx-badge.sold { background:#e8f5e9; color:#2e7d32; }
.rx-badge.cancelled { background:#f5f5f5; color:#777; }
.rx-badge.outside { background:#ede9fe; color:#5b21b6; }
.rx-card-print { width:28px; height:28px; border:1px solid #cfe3ef; border-radius:7px; background:#fff; color:#0369a1; display:inline-grid; place-items:center; font-size:12px; text-decoration:none; }
.rx-card-print:hover { background:#e0f2fe; }
button.rx-card-print { cursor:pointer; padding:0; }
.rx-msg-print { margin-inline-start:10px; padding:4px 12px; border:1px solid #9cc4e0; border-radius:8px; background:#fff; color:#0369a1; font:inherit; font-size:12.5px !important; font-weight:700; cursor:pointer; }
.rx-msg-print:hover { background:#e0f2fe; }
.rx-card-del { width:28px; height:28px; border:1px solid #f1c4c4; border-radius:7px; background:#fff; color:#c62828; cursor:pointer; font-size:12px; }
.rx-card-del:hover { background:#fff1f2; }
@media (max-width: 767px) {
    .rx-foot { flex-direction: column; align-items: stretch; }
}
</style>

            <!-- دەرمان Tab — prescribe from دەرمانخانە stock, send to the till -->
            <!-- دەرمان: a large dialog (openMedicineDialog), moved under <body> on load. -->
            <div class="dialog-overlay rx-dialog-overlay" id="medicineDialog">
                <div class="dialog rx-dialog">
                    <div class="dialog-header rx-dialog-header">
                        <h4><i class="fas fa-pills"></i> دەرمانی نەخۆش
                            <span class="badge badge-blue">لە کۆگای سەرەکیی دەرمانخانە</span></h4>
                        <button type="button" class="dialog-close" onclick="closeDialog('medicineDialog')" title="داخستن" aria-label="داخستن"><i class="fas fa-times"></i></button>
                    </div>
                    <div class="dialog-body rx-dialog-body">

                    <?php if ($rxCanPrescribe): ?>
                    <!-- Two steps: pick the medicines, then check doses and send -->
                    <div class="rx-tabs" role="tablist">
                        <button type="button" class="rx-tab on" data-rx-tab="pick" role="tab" aria-selected="true">
                            <i class="fas fa-list-check"></i> هەڵبژاردن
                        </button>
                        <button type="button" class="rx-tab" data-rx-tab="send" role="tab" aria-selected="false">
                            <i class="fas fa-paper-plane"></i> ناردن <span class="rx-tab-count" id="rxSendCount">0</span>
                        </button>
                    </div>

                    <div class="rx-pane" data-rx-pane="pick">
                    <!-- The clinic's paper prescription sheet: tick the items. A header
                         circle filters its group to that form/brand (and is the form of
                         ticked items that have none of their own). -->
                    <div class="rxs" id="rxSheet">
                        <div class="rxs-top">
                            <div class="rxs-pt">Patient Name : <bdi id="rxPatientName"><?= htmlspecialchars((string)$rxCtx['patient_name']) ?></bdi></div>
                            <div class="rxs-find">
                                <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                                <input type="search" id="rxFind" autocomplete="off" placeholder="گەڕان لە پەڕەکەدا…" aria-label="گەڕان لە پەڕەکەدا">
                            </div>
                            <!-- the search found nothing: offer it as a medicine bought outside -->
                            <button type="button" class="rxs-out-sug" id="rxFindOutside" hidden></button>
                            <div class="rxs-legend"><span class="rxs-q">12</span> بەردەست لە کۆگا <span class="rxs-q zero">0</span> لە کۆگا نییە
                                <span class="rxs-ox"><i class="fas fa-store-slash"></i> دەرەوە</span> نەخۆش لە دەرەوە دەیکڕێت</div>
                            <?php if ($rxCanArrange): ?>
                            <button type="button" class="rxs-arrange" id="rxArrange" aria-pressed="false"
                                    title="ڕێکخستنی پەڕەکە: ناو، ڕیز، ستوون، لابردن — تەنها بۆ بەڕێوەبەر">
                                <i class="fas fa-pen-ruler"></i> ڕێکخستنی پەڕە
                            </button>
                            <?php endif; ?>
                        </div>
                        <?php if ($rxCanArrange): ?>
                        <div class="rxs-editbar" id="rxEditBar" hidden>
                            <i class="fas fa-pen-ruler"></i>
                            <span><b>دۆخی ڕێکخستن</b> — ڕایبکێشە بۆ گۆڕینی ڕیز (دەرمانێک بۆ ستوونێکی تریش)، کلیک لە هەر شتێک بکە بۆ ناو و ڕیز و لابردن.
                                بە <b>«+ دەرمانی دەرەوە»</b> لە خوارەوەی هەر ستوونێک دەرمانێک زیاد بکە کە لە کۆگا نییە و نەخۆش لە دەرەوە دەیکڕێت.
                                تەنها ئەم پەڕەیە دەگۆڕێت؛ دەرمانخانە و ڕەچەتەی چاپکراو ناوی ڕاستەقینە دەهێڵنەوە.</span>
                            <span class="st" id="rxEditMsg" role="status"></span>
                            <button type="button" class="rs" id="rxEditReset"><i class="fas fa-rotate-left"></i> گەڕاندنەوەی هەموو</button>
                            <button type="button" class="dn" id="rxEditDone"><i class="fas fa-check"></i> تەواو</button>
                        </div>
                        <?php endif; ?>
                        <div class="rxs-grid" id="rxGrid"><div class="rx-empty">بارکردن…</div></div>
                    </div>
                    <!-- Shown once something is ticked: the way on to the second step -->
                    <div class="rx-next" id="rxNext" hidden>
                        <span><i class="fas fa-check-circle"></i> <b id="rxNextCount">0</b> دەرمان هەڵبژێردرا</span>
                        <button type="button" class="btn btn-primary" data-rx-tab="send">
                            ناردن <i class="fas fa-arrow-left"></i>
                        </button>
                    </div>
                    </div>

                    <div class="rx-pane" data-rx-pane="send" hidden>
                    <div class="rx-table-wrap">
                        <table class="rx-table" id="rxTable">
                            <thead>
                                <tr>
                                    <th style="width:36px">#</th>
                                    <th>دەرمان</th>
                                    <th style="width:110px">بڕ</th>
                                    <th style="width:360px">دۆز / ڕێنمایی</th>
                                    <th style="width:110px">لە کۆگا</th>
                                    <th style="width:40px"></th>
                                </tr>
                            </thead>
                            <tbody id="rxRows"></tbody>
                        </table>
                        <div id="rxEmpty" class="rx-empty">هێشتا هیچ دەرمانێک دیاری نەکراوە — لە تابی «هەڵبژاردن» کاڵاکان دیاری بکە.</div>
                        <!-- دەرمانی دەرەوە: a medicine the system does not have, by name,
                             for the patient to buy outside (printed on the sheet, not sold here) -->
                        <button type="button" class="rx-add-outside" id="rxAddOutside">
                            <i class="fas fa-plus"></i>
                            <span><b>دەرمانێکی دەرەوە زیاد بکە</b><small>ئەوەی لە سیستەم نییە — لەسەر ڕەچەتە دەنووسرێت و نەخۆش لە دەرەوە دەیکڕێت</small></span>
                        </button>
                    </div>

                    <div class="rx-foot">
                        <input type="text" id="rxNotes" class="rx-input" placeholder="تێبینی بۆ دەرمانخانە (ئیختیاری)…">
                        <button type="button" class="btn btn-primary" id="rxSendBtn" onclick="rxSend()" disabled>
                            <i class="fas fa-paper-plane"></i> ناردن بۆ دەرمانخانە
                        </button>
                    </div>
                    <div id="rxMsg" class="rx-msg"></div>
                    <?php endif; ?>

                    <h4 style="margin:22px 0 10px;font-size:14px;">
                        <i class="fas fa-clock-rotate-left"></i> داواکاریە پێشووەکان
                    </h4>
                    <div id="rxHistory" class="rx-history">
                        <div class="rx-empty">بارکردن…</div>
                    </div>
                    <script src="<?= asset_url('assets/js/rx-print.js') ?>" data-app="<?= APP_URL ?>"></script>
                    <?php if ($rxCanPrescribe): ?>
                    </div><!-- /send pane -->
                    <?php endif; ?>
                    </div>
                </div>
            </div>

<script>
/* ---------------------------------------------------------------------------
 * دەرمان tab — the clinic's paper prescription sheet on screen. Six columns
 * (Antibiotic, Analgesic, Toothpaste, Brush, Mouth Wash, Other), each with its
 * types as circles in the header and its items as tick boxes, in the paper's
 * order. Ticking adds the line below (quantity, dose); a header circle is the
 * form/brand for that group's ticked items that have no form of their own —
 * «Amoxicillin 250 mg» + ○Syrup is sent as Amoxicillin 250 mg (Syrup).
 *
 * Every active item is on the sheet, as on paper. What the main warehouse
 * lacks is marked, and sending it is allowed with a warning, so the counter
 * knows what to bring in. Talks to erp2/api/prescriptions.php.
 * ------------------------------------------------------------------------ */
(function () {
    const RX_API     = '<?= APP_URL ?>/erp2/api/prescriptions.php';
    // who the sheet is for — fixed on a case or a plan; the doctor's room
    // sets them per patient (openMedicineDialog({...}))
    let RX_CASE_ID   = <?= (int)$rxCtx['case_id'] ?>;
    let RX_PATIENT   = <?= (int)$rxCtx['patient_id'] ?>;
    let RX_DOCTOR_ID = <?= (int)$rxCtx['doctor_id'] ?>;
    let RX_DOCTOR    = <?= json_encode((string)$rxCtx['doctor_name'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const CAN_WRITE  = <?= $rxCanPrescribe ? 'true' : 'false' ?>;
    const RX_CAN_DELETE = <?= Permission::getInstance()->can('erp2.delete') ? 'true' : 'false' ?>;
    const RX_ME      = <?= (int)($_SESSION['user_id'] ?? 0) ?>;
    let rxEdit = false;    // «ڕێکخستنی پەڕە» on (the admin arranging the sheet)

    let lines = [];        // [{product_id, group_id, name, type_name, quantity, dosage, stock, unit}]
                           // or a medicine bought outside: {outside: true, product_id: null, name, quantity, dosage}
    let groups = [];       // the sheet, from ?action=sheet
    const groupForm = {};  // group id → chosen header type id

    const $ = id => document.getElementById(id);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const nf = n => new Intl.NumberFormat('en-US', { maximumFractionDigits: 3 }).format(Number(n) || 0);

    function msg(text, kind) {
        const box = $('rxMsg');
        if (!box) return;
        box.className = 'rx-msg ' + (kind || '');
        box.textContent = text || '';
    }

    /** The header circle chosen for a group, as {id, name} or null. */
    function formOf(gid) {
        const g = groups.find(x => x.id === gid);
        return g ? (g.types.find(t => t.id === groupForm[gid]) || null) : null;
    }
    /** What a line is sent as: its own form, else the group's circled one. */
    function lineForm(l) { return l.outside ? '' : (l.type_name || (formOf(l.group_id) || {}).name || ''); }

    /* ---------- the sheet ---------- */
    async function loadSheet() {
        const grid = $('rxGrid');
        if (!grid) return;
        try {
            const res  = await fetch(RX_API + '?action=sheet' + (rxEdit ? '&edit=1' : ''));
            const data = await res.json();
            if (!data || !data.ok) { grid.innerHTML = '<div class="rx-empty">' + esc((data && data.error) || 'نەتوانرا بارببرێت') + '</div>'; return; }
            groups = data.groups || [];
            renderSheet();
        } catch (e) {
            grid.innerHTML = '<div class="rx-empty">نەتوانرا پەڕەکە بارببرێت</div>';
        }
    }

    function renderSheet() {
        const grid = $('rxGrid');
        if (!grid) return;
        if (rxEdit) { renderEditSheet(); return; }
        if (!groups.length) { grid.innerHTML = '<div class="rx-empty">هیچ کاڵایەک لە دەرمانخانە نییە</div>'; return; }
        const lineOf = {};
        lines.forEach(l => { lineOf[l.product_id] = l; });
        // A ticked medicine marks its type in the column title: a green tick in
        // the round choice (and how many, when more than one).
        const pickedOfType = {};
        lines.forEach(l => {
            const hit = l.product_id ? findItem(l.product_id) : null;
            const tid = hit && hit.p.type_id;
            if (tid) pickedOfType[tid] = (pickedOfType[tid] || 0) + 1;
        });
        grid.innerHTML = groups.map((g, gi) => {
            const chosen = groupForm[g.id] || 0;
            const types = g.types.length
                ? '<div class="rxs-types">' + g.types.map(t =>
                    '<button type="button" class="rxs-t' + (t.id === chosen ? ' on' : '') + (pickedOfType[t.id] ? ' has-pick' : '') + '" data-g="' + g.id + '" data-t="' + t.id + '"'
                    + ' role="radio" aria-checked="' + (t.id === chosen) + '"'
                    + (pickedOfType[t.id] ? ' title="' + esc(pickedOfType[t.id] + ' دەرمانی ئەم جۆرە هەڵبژێردراوە') + '"' : '') + '>'
                    // the circle: a tick for one, the count for more
                    + (pickedOfType[t.id] ? '<span class="rxs-tk" aria-hidden="true">' + (pickedOfType[t.id] > 1 ? pickedOfType[t.id] : '<i class="fas fa-check"></i>') + '</span>' : '')
                    + esc(t.name) + '</button>').join('') + '</div>'
                : '';
            // A circled form FILTERS its group to that form: the doctor picks the
            // type first, then ticks from the few that are left. Ticked items and
            // untyped ones always stay visible.
            const outOfForm = p => !lineOf[p.id] && chosen && p.type_id && p.type_id !== chosen;
            const shown = g.items.filter(p => !outOfForm(p)).length;
            const items = g.items.map(p => {
                const l = lineOf[p.id], on = !!l;
                const dim = outOfForm(p);
                // How many are on the pharmacy shelf, right beside the name — or,
                // for a دەرمانی دەرەوە, that the patient buys it outside.
                const qty = p.outside ? '<span class="rxs-ox" title="لە کۆگا نییە — نەخۆش لە دەرەوە دەیکڕێت"><i class="fas fa-store-slash"></i> دەرەوە</span>'
                    : '<span class="rxs-q' + (p.stock <= 0 ? ' zero' : '') + '" title="'
                    + esc(p.stock <= 0 ? 'لە کۆگا نییە' : 'بەردەست لە کۆگا: ' + nf(p.stock) + (p.unit_name ? ' ' + p.unit_name : '')) + '">'
                    + nf(p.stock) + '</span>';
                return '<label class="rxs-it' + (on ? ' on' : '') + (p.outside ? ' is-ox' : (p.stock <= 0 ? ' out' : '')) + (dim ? ' typed-out' : '') + '"'
                    + ' data-name="' + esc(((p.name || '') + ' ' + (p.name_ku || '')).toLowerCase()) + '"'
                    + ' title="' + esc(p.name + (p.outside ? ' — دەرمانی دەرەوە، نەخۆش لە دەرەوە دەیکڕێت'
                        : (p.type_name ? ' — ' + p.type_name : '') + (p.stock <= 0 ? ' — لە کۆگا نییە' : ' — لە کۆگا: ' + nf(p.stock)))) + '">'
                    + '<input type="checkbox" data-p="' + p.id + '" data-g="' + g.id + '"' + (on ? ' checked' : '') + (CAN_WRITE ? '' : ' disabled') + '>'
                    + '<span class="bx">' + (on ? '<i class="fas fa-check"></i>' : '') + '</span>'
                    // ticked: the dose under the name, and the small dose button
                    // beside it — the doctor changes it right here, one click
                    + (on
                        ? '<span class="nmw"><span class="nm">' + esc(p.name) + '</span>'
                          + '<small class="ds' + (l.dosage ? '' : ' none') + '" title="' + esc(l.dosage) + '">'
                          + esc(l.dosage || 'دۆز دانەنراوە') + (Number(l.quantity) !== 1 ? ' · ×' + nf(l.quantity) : '') + '</small></span>'
                          + qty
                          + '<button type="button" class="rxs-dose' + (l.dosage ? ' set' : '') + '" data-dose="' + p.id + '"'
                          + ' title="گۆڕینی دۆز و بڕ" aria-label="گۆڕینی دۆزی ' + esc(p.name) + '"><i class="fas fa-prescription"></i></button>'
                        : '<span class="nm">' + esc(p.name) + '</span>' + qty)
                    // once ticked the type moves to the panel and the table, so the
                    // name keeps its room next to the dose button
                    + (p.type_name && !on ? '<span class="tp">' + esc(p.type_name) + '</span>' : '')
                    + '</label>';
            }).join('');
            // The paper signs off under its last column.
            const doctor = gi === groups.length - 1
                ? '<div class="rxs-doc"><h5>Doctor</h5><div>' + (RX_DOCTOR ? esc(/^\s*د\s*\./.test(RX_DOCTOR) ? RX_DOCTOR : 'د. ' + RX_DOCTOR) : '&nbsp;') + '</div></div>'
                : '';
            const none = chosen && !shown
                ? '<div class="rxs-none">هیچ کاڵایەک بەم جۆرە نییە — جۆرەکە دووبارە کلیک بکە بۆ پیشاندانی هەموو</div>'
                : '';
            return '<section class="rxs-col' + (chosen ? ' is-filtered' : '') + '" aria-label="' + esc(g.name) + '">'
                + '<div class="rxs-head"><h5>' + esc(g.name) + '</h5>' + types + '</div>'
                + '<div class="rxs-body">' + items + none + doctor + '</div></section>';
        }).join('');
        applyFind();
    }

    function applyFind() {
        const box = $('rxFind');
        const raw = box ? box.value.trim() : '', q = raw.toLowerCase();
        let shown = 0;
        document.querySelectorAll('#rxGrid .rxs-it').forEach(el => {
            const gone = q !== '' && !el.dataset.name.includes(q);
            el.classList.toggle('gone', gone);
            if (!gone) shown++;
        });
        // nothing on the sheet by that name: it can still go on the prescription
        const sug = $('rxFindOutside');
        if (sug) {
            sug.hidden = !(CAN_WRITE && !rxEdit && raw.length >= 2 && shown === 0);
            if (!sug.hidden) sug.innerHTML = '<i class="fas fa-plus"></i> «' + esc(raw) + '» لە سیستەم نییە — وەک دەرمانی دەرەوە زیادی بکە';
        }
    }

    /** A medicine bought outside, by name; focuses where the doctor types next. */
    function addOutside(name) {
        lines.push({ outside: true, product_id: null, name: name || '', type_name: '', quantity: 1, dosage: '', stock: 0, unit: '' });
        render();
        const i = lines.length - 1;
        const box = document.querySelector('#rxRows [data-i="' + i + '"][data-f="' + (name ? 'dosage' : 'name') + '"]');
        if (box) box.focus();
    }

    function findItem(pid) {
        for (const g of groups) {
            const p = g.items.find(x => x.id === pid);
            if (p) return { g, p };
        }
        return null;
    }

    function toggleItem(pid) {
        if (popPid === pid) { $('rxPop').hidden = true; popPid = 0; }
        const at = lines.findIndex(l => l.product_id === pid);
        if (at >= 0) { lines.splice(at, 1); }
        else {
            const hit = findItem(pid);
            if (!hit) return;
            // the admin's default dose (erp2/dosages.php) fills the line; the
            // other saved doses stay a pick away in the line's dropdown
            const doses = hit.p.doses || [];
            lines.push(hit.p.outside
                // a دەرمانی دەرەوە: goes as an outside line, by name — the
                // negative id only ties it to its tick on the sheet
                ? { outside: true, sheet: true, product_id: pid, group_id: hit.g.id, name: hit.p.name, type_name: '',
                    quantity: 1, dosage: doses.length ? doses[0].text : '', doses: doses, stock: 0, unit: '' }
                : {
                product_id: pid, group_id: hit.g.id, name: hit.p.name, type_name: hit.p.type_name || '',
                quantity: 1, dosage: doses.length ? doses[0].text : '', doses: doses,
                stock: Number(hit.p.stock) || 0, unit: hit.p.unit_name || ''
            });
        }
        renderSheet();
        render();
    }

    /* ---------- the dose panel of a ticked item ----------
     * Opened by the small button beside the name. A saved dose is one click;
     * a dose of the doctor's own is typed and Enter; the quantity is − / +. */
    let popPid = 0;

    function popAnchor() { return document.querySelector('#rxGrid .rxs-dose[data-dose="' + popPid + '"]'); }

    function placeDose() {
        const el = $('rxPop'), a = popAnchor();
        if (!el || el.hidden) return;
        if (!a) { closeDose(); return; }
        const r = a.getBoundingClientRect();
        const vw = document.documentElement.clientWidth, vh = document.documentElement.clientHeight;
        const w = el.offsetWidth, h = el.offsetHeight;
        el.style.left = Math.max(8, Math.min(r.left - 12, vw - w - 8)) + 'px';
        el.style.top = (r.bottom + 6 + h > vh - 8 && r.top - 6 - h > 8 ? r.top - 6 - h : Math.min(r.bottom + 6, vh - h - 8)) + 'px';
    }

    function openDose(pid, anchor) {
        const l = lines.find(x => x.product_id === pid);
        if (!l) return;
        let el = $('rxPop');
        if (!el) {
            el = document.createElement('div');
            el.id = 'rxPop';
            el.className = 'rxs-pop';
            el.setAttribute('role', 'dialog');
            document.body.appendChild(el);
            el.addEventListener('click', onDoseClick);
            el.addEventListener('keydown', e => {
                if (e.key === 'Escape') { e.preventDefault(); closeDose(); }
                if (e.key === 'Enter' && e.target.id === 'rxPopDose') { e.preventDefault(); applyTyped(); }
            });
        }
        popPid = pid;
        const form = lineForm(l), presets = l.doses || [];
        el.innerHTML =
            '<div class="hd"><i class="fas fa-prescription"></i><bdi>' + esc(l.name) + '</bdi>'
            + (form ? '<span class="tp">' + esc(form) + '</span>' : '')
            + '<button type="button" class="x" data-act="close" aria-label="داخستن">×</button></div>'
            + (presets.length
                ? '<div class="ps">' + presets.map((d, k) =>
                    '<button type="button" class="pr' + (d.text === l.dosage ? ' on' : '') + '" data-preset="' + k + '">'
                    + (d.label ? '<span class="lb">' + esc(d.label) + '</span>' : '')
                    + '<span class="tx">' + esc(d.text) + '</span>'
                    + (d.text === l.dosage ? '<i class="fas fa-check"></i>' : '') + '</button>').join('') + '</div>'
                : '<div class="nops">دۆزی پاشەکەوتکراو نییە — لێرە بینووسە</div>')
            + '<div class="cu"><input type="text" id="rxPopDose" value="' + esc(l.dosage) + '" placeholder="دۆزی تایبەت بەم نەخۆشە…" autocomplete="off">'
            + '<button type="button" class="ok" data-act="ok" title="پاشەکەوت (Enter)"><i class="fas fa-check"></i></button></div>'
            + '<div class="qt"><span>بڕ</span><button type="button" data-act="dec" aria-label="کەمکردنەوە">−</button>'
            + '<b id="rxPopQty">' + nf(l.quantity) + '</b><button type="button" data-act="inc" aria-label="زیادکردن">+</button>'
            + (l.unit ? '<small>' + esc(l.unit) + '</small>' : '') + '</div>';
        el.hidden = false;
        placeDose();
        // no saved dose: straight into typing one
        const box = $('rxPopDose');
        if (!presets.length && box) { box.focus(); box.select(); }
    }

    // Update a ticked item's dose line and button in place. Never a redraw of
    // the sheet here: closing runs on mousedown, and replacing the item under
    // the pointer would swallow the click that follows (a tick needing two).
    function refreshItem(pid) {
        const l = lines.find(x => x.product_id === pid);
        const btn = document.querySelector('#rxGrid .rxs-dose[data-dose="' + pid + '"]');
        if (!l || !btn) return;
        const ds = btn.closest('.rxs-it').querySelector('.ds');
        if (ds) {
            ds.textContent = (l.dosage || 'دۆز دانەنراوە') + (Number(l.quantity) !== 1 ? ' · ×' + nf(l.quantity) : '');
            ds.classList.toggle('none', !l.dosage);
        }
        btn.classList.toggle('set', !!l.dosage);
    }

    function closeDose() {
        const el = $('rxPop'), pid = popPid;
        if (el) el.hidden = true;
        popPid = 0;
        if (pid) refreshItem(pid);
    }

    function applyTyped() {
        const l = lines.find(x => x.product_id === popPid), box = $('rxPopDose');
        if (l && box) l.dosage = box.value.trim();
        render();
        closeDose();
    }

    function onDoseClick(e) {
        const l = lines.find(x => x.product_id === popPid);
        if (!l) { closeDose(); return; }
        const pr = e.target.closest('[data-preset]');
        if (pr) {
            const d = (l.doses || [])[Number(pr.dataset.preset)];
            if (d) l.dosage = d.text;
            render();
            closeDose();
            return;
        }
        const act = (e.target.closest('[data-act]') || {}).dataset;
        if (!act) return;
        if (act.act === 'close') closeDose();
        else if (act.act === 'ok') applyTyped();
        else if (act.act === 'inc' || act.act === 'dec') {
            l.quantity = Math.max(1, (Number(l.quantity) || 1) + (act.act === 'inc' ? 1 : -1));
            $('rxPopQty').textContent = nf(l.quantity);
            render();
        }
    }

    // a click anywhere else closes it; the page scrolling carries it along
    document.addEventListener('mousedown', e => {
        const el = $('rxPop');
        if (el && !el.hidden && !el.contains(e.target) && !e.target.closest('.rxs-dose')) closeDose();
    });
    window.addEventListener('scroll', placeDose, true);
    window.addEventListener('resize', placeDose);

    /* ---------- lines ---------- */
    function removeLine(i) { lines.splice(i, 1); renderSheet(); render(); }

    function render() {
        const body = $('rxRows');
        if (!body) return;
        body.innerHTML = '';
        lines.forEach((l, i) => {
            const tr = document.createElement('tr');
            if (l.outside) {
                tr.className = 'rx-row-outside';
                tr.innerHTML =
                    '<td style="color:#999">' + (i + 1) + '</td>' +
                    '<td><input type="text" class="rx-out-name" value="' + esc(l.name) + '" data-i="' + i + '" data-f="name" placeholder="ناوی دەرمان، بۆ نموونە: Augmentin 1g" maxlength="200"></td>' +
                    '<td><input type="number" min="0.001" step="any" value="' + l.quantity + '" data-i="' + i + '" data-f="quantity"></td>' +
                    '<td><input type="text" value="' + esc(l.dosage) + '" data-i="' + i + '" data-f="dosage" placeholder="ڕۆژانە ٢ جار…"></td>' +
                    '<td><span class="rx-outside-tag" title="لە سیستەم نییە — نەخۆش لە دەرەوە دەیکڕێت"><i class="fas fa-store-slash"></i> دەرەوە</span></td>' +
                    '<td class="rx-del" data-del="' + i + '" title="لابردن"><i class="fas fa-times"></i></td>';
                body.appendChild(tr);
                return;
            }
            const form = lineForm(l);
            tr.innerHTML =
                '<td style="color:#999">' + (i + 1) + '</td>' +
                '<td>' + esc(l.name) + (form ? '<span class="rx-form">' + esc(form) + '</span>' : '') + '</td>' +
                '<td><input type="number" min="0.001" step="any" value="' + l.quantity + '" data-i="' + i + '" data-f="quantity"></td>' +
                '<td><input type="text" value="' + esc(l.dosage) + '" data-i="' + i + '" data-f="dosage" placeholder="ڕۆژانە ٢ جار…">' +
                    ((l.doses || []).length > 1
                        ? '<select class="rx-preset" data-i="' + i + '" data-f="preset" title="دۆزە پاشەکەوتکراوەکان">' +
                          l.doses.map((d, k) => '<option value="' + k + '"' + (d.text === l.dosage ? ' selected' : '') + '>' +
                              esc((d.label ? d.label + ': ' : '') + d.text) + '</option>').join('') + '</select>'
                        : '') + '</td>' +
                '<td class="rx-stock">' + (l.stock > 0 ? nf(l.stock) + ' ' + esc(l.unit) : '<span class="rx-out">نییە</span>') + '</td>' +
                '<td class="rx-del" data-del="' + i + '" title="لابردن"><i class="fas fa-times"></i></td>';
            body.appendChild(tr);
        });
        const empty = $('rxEmpty');
        if (empty) empty.style.display = lines.length ? 'none' : 'block';
        const btn = $('rxSendBtn');
        if (btn) btn.disabled = !lines.length;
        // the step-two counters: the ناردن tab and the bar under the sheet
        if ($('rxSendCount')) $('rxSendCount').textContent = lines.length;
        if ($('rxNextCount')) $('rxNextCount').textContent = lines.length;
        if ($('rxNext')) $('rxNext').hidden = !lines.length;
        stockWarning();
    }

    /* ---------- the two steps: هەڵبژاردن / ناردن ---------- */
    function showRxTab(name) {
        document.querySelectorAll('#medicineDialog .rx-tab').forEach(t => {
            const on = t.dataset.rxTab === name;
            t.classList.toggle('on', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        document.querySelectorAll('#medicineDialog [data-rx-pane]').forEach(p => {
            p.hidden = p.dataset.rxPane !== name;
        });
        if (name === 'send') closeDose();
        const body = document.querySelector('#medicineDialog .rx-dialog-body');
        if (body) body.scrollTop = 0;
    }
    window.showRxTab = showRxTab;
    document.addEventListener('click', e => {
        const t = e.target.closest('#medicineDialog [data-rx-tab]');
        if (t) showRxTab(t.dataset.rxTab);
    });

    /** Not a block — on paper the doctor may ask for anything — but say so. */
    function stockWarning() {
        const short = lines.filter(l => !l.outside && Number(l.quantity) > Number(l.stock)).map(l => l.name);
        if (short.length) {
            msg('ئاگاداری: ئەمانە لە کۆگا نین یان کەمن — دەرمانخانە دەبێت دابینیان بکات: ' + short.join('، '), 'warn');
        } else if ($('rxMsg') && /\b(warn|err)\b/.test($('rxMsg').className)) {
            msg('');
        }
    }

    /* ---------- send ---------- */
    window.rxSend = async function () {
        if (!lines.length) return;
        const bad = lines.find(l => !(Number(l.quantity) > 0));
        if (bad) { msg('بڕی «' + (bad.name || 'دەرمانی دەرەوە') + '» دروست نییە', 'err'); return; }
        const nameless = lines.findIndex(l => l.outside && !String(l.name || '').trim());
        if (nameless >= 0) {
            msg('ناوی دەرمانی دەرەوە بنووسە (ڕیزی ' + (nameless + 1) + ')', 'err');
            const box = document.querySelector('#rxRows [data-i="' + nameless + '"][data-f="name"]');
            if (box) box.focus();
            return;
        }
        const btn = $('rxSendBtn');
        btn.disabled = true;
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ناردن…';
        try {
            const fd = new FormData();
            fd.append('action', 'save');
            fd.append('patient_id', RX_PATIENT);
            fd.append('case_id', RX_CASE_ID);
            // no case (the doctor's room, a plan): whose prescription it is when
            // someone other than a doctor writes it
            if (RX_DOCTOR_ID) fd.append('doctor_id', RX_DOCTOR_ID);
            fd.append('notes', $('rxNotes') ? $('rxNotes').value : '');
            // notes per line = the circled form/brand, when the item has none of its own
            fd.append('items', JSON.stringify(lines.map(l => l.outside
                ? { outside: true, name: String(l.name).trim(), quantity: l.quantity, dosage: l.dosage }
                : { product_id: l.product_id, quantity: l.quantity, dosage: l.dosage, notes: l.type_name ? '' : lineForm(l) })));
            const res  = await fetch(RX_API, { method: 'POST', body: fd });
            const data = await res.json();
            if (data && data.ok) {
                lines = [];
                Object.keys(groupForm).forEach(k => delete groupForm[k]);
                if ($('rxNotes')) $('rxNotes').value = '';
                renderSheet();
                render();
                // all of it bought outside: nothing for the counter, the paper is the point
                msg(data.outside ? 'ڕەچەتەکە پاشەکەوت کرا — هەمووی لە دەرەوە دەکڕدرێت، چاپی بکە بۆ نەخۆش ✓' : 'نێردرا بۆ دەرمانخانە ✓', 'ok');
                // the doctor can hand the patient the printed sheet right away
                if (data.id && window.RxPrint) {
                    const pb = document.createElement('button');
                    pb.type = 'button';
                    pb.className = 'rx-msg-print';
                    pb.innerHTML = '<i class="fas fa-print"></i> پێشبینین و چاپی ڕەچەتە';
                    pb.addEventListener('click', () => window.RxPrint.open(data.id));
                    $('rxMsg').appendChild(pb);
                }
                loadHistory();
            } else {
                msg((data && (data.error || data.message)) || 'هەڵەیەک ڕوویدا', 'err');
            }
        } catch (e) {
            msg('هەڵەی تەکنیکی ڕوویدا', 'err');
        }
        btn.innerHTML = original;
        btn.disabled = !lines.length;
    };

    /* ---------- history ---------- */
    async function loadHistory() {
        const box = $('rxHistory');
        if (!box) return;
        try {
            if (!RX_CASE_ID && !RX_PATIENT) { box.innerHTML = ''; return; }
            // a case: its own; otherwise the patient's, whatever the case
            const res  = await fetch(RX_API + (RX_CASE_ID
                ? '?action=case_list&case_id=' + RX_CASE_ID
                : '?action=patient_list&patient_id=' + RX_PATIENT));
            const data = await res.json();
            const rows = (data && data.results) || [];
            const labels = { pending: 'چاوەڕوانی فرۆشتن', sold: 'فرۆشراوە', outside: 'دەرەوەی کلینیک', cancelled: 'ڕەتکراوە' };
            const pending = rows.filter(r => r.status === 'pending').length;
            const count = $('rxTabCount');
            if (count) count.textContent = pending;
            if (!rows.length) {
                box.innerHTML = '<div class="rx-empty">هیچ داواکارییەکی دەرمان بۆ ئەم ' + (RX_CASE_ID ? 'کەیسە' : 'نەخۆشە') + ' نییە.</div>';
                return;
            }
            // delete: an admin (erp2.delete) any time; the doctor who wrote it
            // while it is still pending — the server checks the same rule
            const mayDelete = r => RX_CAN_DELETE || (r.status === 'pending' && CAN_WRITE &&
                (Number(r.created_by) === RX_ME || Number(r.doctor_id) === RX_ME));
            box.innerHTML = rows.map(r =>
                '<div class="rx-card"><div class="top">' +
                    '<span><b>#' + r.id + '</b> · ' + esc((r.created_at || '').substring(0, 16)) +
                        ' · ' + esc(r.doctor_name || '—') + '</span>' +
                    '<span style="display:flex;align-items:center;gap:6px">' +
                    '<span class="rx-badge ' + esc(r.status) + '">' + esc(labels[r.status] || r.status) + '</span>' +
                    '<button type="button" class="rx-card-print" data-rxprint="' + r.id + '"' + (r.status === 'sold' ? ' data-sold="1"' : '') + ' title="پێشبینین و چاپی ڕەچەتە"><i class="fas fa-print"></i></button>' +
                    (mayDelete(r) ? '<button type="button" class="rx-card-del" data-rxdel="' + r.id + '" title="سڕینەوە"><i class="fas fa-trash-can"></i></button>' : '') +
                    '</span>' +
                '</div><div class="items" dir="auto">' + esc(r.items_summary || '') + '</div></div>'
            ).join('');
        } catch (e) {
            box.innerHTML = '<div class="rx-empty">نەتوانرا بارببرێت</div>';
        }
    }

    /* ---------- arranging the sheet (admin) ----------
     * «ڕێکخستنی پەڕە»: the admin drags the columns, the round choices and the
     * items into order (an item into another column too) and clicks one for
     * its name on the sheet or to take it off. Saved by the API in
     * erp2_rx_sheet_layout — the pharmacy keeps its own names and groups, and
     * the till and the printed prescription the real product. */
    const RX_KIND_KU = { group: 'ستوون', type: 'جۆر', item: 'دەرمان' };
    let edAt = null;   // {kind, id} of the open panel
    let drag = null;   // what is being dragged

    function findNode(kind, id) {
        if (kind === 'group') {
            const g = groups.find(x => x.id === id);
            return g ? { node: g, list: groups, g } : null;
        }
        for (const g of groups) {
            const list = kind === 'type' ? g.types : g.items;
            const node = list.find(x => x.id === id);
            if (node) return { node, list, g };
        }
        return null;
    }

    function renderEditSheet() {
        const grid = $('rxGrid');
        if (!groups.length) { grid.innerHTML = '<div class="rx-empty">هیچ ستوونێک لە دەرمانخانە نییە</div>'; return; }
        const pen = n => n.renamed ? '<i class="fas fa-pen rn" title="' + esc('ناوی پەڕە — لە دەرمانخانە: ' + n.erp_name) + '"></i>' : '';
        const eye = (k, n) => '<button type="button" class="rxs-eye' + (n.hidden ? ' off' : '') + '" data-eye="' + k + '" data-eid="' + n.id + '"'
            + ' title="' + (n.hidden ? 'گەڕاندنەوە بۆ پەڕە' : 'لابردن لە پەڕە') + '" aria-label="' + esc((n.hidden ? 'گەڕاندنەوەی ' : 'لابردنی ') + n.name) + '">'
            + '<i class="fas fa-' + (n.hidden ? 'eye-slash' : 'eye') + '"></i></button>';
        grid.innerHTML = groups.map(g => {
            const types = g.types.length
                ? '<div class="rxs-types">' + g.types.map(t =>
                    '<span class="rxs-t ed' + (t.hidden ? ' off' : '') + '" draggable="true" data-ek="type" data-eid="' + t.id + '" data-g="' + g.id + '"'
                    + ' role="button" tabindex="0" title="' + esc(t.name + ' — ڕایبکێشە بۆ ڕیز، کلیک بۆ دەستکاری') + '">' + esc(t.name) + pen(t) + '</span>').join('') + '</div>'
                : '';
            const items = g.items.map(p =>
                '<div class="rxs-it ed' + (p.hidden ? ' off' : '') + '" draggable="true" data-ek="item" data-eid="' + p.id + '" data-g="' + g.id + '"'
                + ' data-name="' + esc(((p.name || '') + ' ' + (p.erp_name || '') + ' ' + (p.name_ku || '')).toLowerCase()) + '"'
                + ' role="button" tabindex="0" title="' + esc(p.name + ' — ڕایبکێشە بۆ ڕیز یان ستوونێکی تر، کلیک بۆ دەستکاری') + '">'
                + '<i class="fas fa-grip-vertical gr" aria-hidden="true"></i>'
                + '<span class="nm">' + esc(p.name) + '</span>' + pen(p)
                + (g.id && p.home_group !== g.id ? '<i class="fas fa-right-left mv" title="لە ستوونی خۆیەوە هێنراوە بۆ ئێرە"></i>' : '')
                + (p.outside ? '<span class="rxs-ox" title="لە کۆگا نییە — نەخۆش لە دەرەوە دەیکڕێت"><i class="fas fa-store-slash"></i> دەرەوە</span>' : '<span class="rxs-q' + (p.stock <= 0 ? ' zero' : '') + '">' + nf(p.stock) + '</span>')
                + eye('item', p) + '</div>').join('');
            return '<section class="rxs-col ed' + (g.hidden ? ' off' : '') + '" draggable="true" data-ek="group" data-eid="' + g.id + '" aria-label="' + esc(g.name) + '">'
                + '<div class="rxs-head"><h5 class="rxs-eh" data-ek="group" data-eid="' + g.id + '" role="button" tabindex="0"'
                + ' title="' + esc(g.name + ' — ڕایبکێشە بۆ ڕیزی ستوونەکان، کلیک بۆ دەستکاری') + '">'
                + '<i class="fas fa-grip gr" aria-hidden="true"></i><span>' + esc(g.name) + '</span>' + pen(g) + eye('group', g) + '</h5>'
                + (g.hidden ? '<div class="offtag">ئەم ستوونە لەسەر پەڕە نییە</div>' : '')
                + types + '</div>'
                + '<div class="rxs-body" data-drop-g="' + g.id + '">' + items
                + (g.items.length ? '' : '<div class="rxs-none">دەرمانێک ڕابکێشە ئێرە</div>')
                // a medicine the pharmacy does not have, for the patient to buy outside
                + (g.id ? '<button type="button" class="rxs-addox" data-add-ox="' + g.id + '"><i class="fas fa-plus"></i> دەرمانی دەرەوە</button>' : '')
                + '</div></section>';
        }).join('');
        applyFind();
    }

    function edMsg(text, kind) {
        const st = $('rxEditMsg');
        if (!st) return;
        st.className = 'st ' + (kind || '');
        st.textContent = text || '';
    }

    /** One write to the arrangement, then the sheet as the server now has it. */
    async function arrange(action, data) {
        const fd = new FormData();
        fd.append('action', action);
        Object.keys(data).forEach(k => fd.append(k, data[k]));
        edMsg('پاشەکەوت دەکرێت…');
        let ok = false;
        try {
            const res = await fetch(RX_API, { method: 'POST', body: fd });
            const d = await res.json();
            ok = !!(d && d.ok);
            edMsg(ok ? 'پاشەکەوت کرا ✓' : ((d && d.error) || 'پاشەکەوت نەکرا'), ok ? 'ok' : 'err');
        } catch (e) {
            edMsg('پاشەکەوت نەکرا — هەڵەی تەکنیکی', 'err');
        }
        await loadSheet();
        return ok;
    }

    function edAnchor() {
        if (!edAt) return null;
        if (edAt.kind === 'new') return document.querySelector('#rxGrid [data-add-ox="' + edAt.g + '"]');
        return document.querySelector(edAt.kind === 'group'
            ? '#rxGrid .rxs-eh[data-eid="' + edAt.id + '"]'
            : '#rxGrid [data-ek="' + edAt.kind + '"][data-eid="' + edAt.id + '"]');
    }

    function placeEditor() {
        const el = $('rxEd'), a = edAnchor();
        if (!el || el.hidden) return;
        if (!a) { closeEditor(); return; }
        const r = a.getBoundingClientRect();
        const vw = document.documentElement.clientWidth, vh = document.documentElement.clientHeight;
        const w = el.offsetWidth, h = el.offsetHeight;
        el.style.left = Math.max(8, Math.min(r.left, vw - w - 8)) + 'px';
        el.style.top = (r.bottom + 6 + h > vh - 8 && r.top - 6 - h > 8 ? r.top - 6 - h : Math.min(r.bottom + 6, vh - h - 8)) + 'px';
    }

    function closeEditor() {
        const el = $('rxEd');
        if (el) el.hidden = true;
        edAt = null;
    }

    /** The panel's element, made once. */
    function edEl() {
        let el = $('rxEd');
        if (!el) {
            el = document.createElement('div');
            el.id = 'rxEd';
            el.className = 'rxs-pop rxs-ed';
            el.setAttribute('role', 'dialog');
            document.body.appendChild(el);
            el.addEventListener('click', onEditorClick);
            el.addEventListener('keydown', e => {
                if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeEditor(); }
                if (e.key === 'Enter' && (e.target.id === 'rxEdName' || e.target.id === 'rxEdDose')) { e.preventDefault(); saveEditor(); }
            });
        }
        return el;
    }

    /**
     * A دەرمانی دەرەوە: a new one for a column (n null, gid the column) or
     * one already on the sheet. Nothing to do with the pharmacy — a name, a
     * dose to start from, its column.
     */
    function openOutsidePanel(n, hit, gid, keepFocus) {
        const el = edEl();
        const isNew = !n;
        edAt = isNew ? { kind: 'new', id: 0, g: gid } : { kind: 'item', id: n.id };
        const col = isNew ? gid : hit.g.id;
        const at = isNew ? 0 : hit.list.indexOf(n), last = isNew ? 0 : hit.list.length - 1;
        const ord = (act, icon, text, off) => '<button type="button" data-act="' + act + '"' + (off ? ' disabled' : '') + '>'
            + '<i class="fas fa-' + icon + '"></i> ' + text + '</button>';
        el.innerHTML =
            '<div class="hd"><i class="fas fa-store-slash"></i><bdi>' + (isNew ? 'دەرمانی دەرەوەی نوێ' : esc(n.name)) + '</bdi>'
            + '<span class="tp ox">دەرەوە</span>'
            + '<button type="button" class="x" data-act="close" aria-label="داخستن">×</button></div>'
            + '<div class="oxnote">لە کۆگا نییە و هیچ پەیوەندییەکی بە دەرمانخانەوە نییە — کە دکتۆر دیاری بکات لەسەر ڕەچەتە دەنووسرێت و نەخۆش لە دەرەوە دەیکڕێت.</div>'
            + '<label class="fl"><span>ناوی دەرمان</span><input type="text" id="rxEdName" class="tx" maxlength="160" value="' + esc(isNew ? '' : n.name) + '"'
            + ' placeholder="بۆ نموونە: Augmentin 1g" autocomplete="off" dir="auto"></label>'
            + '<label class="fl"><span>دۆز و ڕێنمایی (ئیختیاری)</span><input type="text" id="rxEdDose" class="tx" maxlength="255" value="' + esc(isNew ? '' : (n.dosage || '')) + '"'
            + ' placeholder="بۆ نموونە: ڕۆژانە ٢ جار دوای نان" autocomplete="off" dir="auto"></label>'
            + '<label class="fl"><span>ستوون</span><select id="rxEdGroup">' + groups.filter(g => g.id > 0).map(g =>
                '<option value="' + g.id + '"' + (g.id === col ? ' selected' : '') + '>' + esc(g.name) + (g.hidden ? ' (لابراوە)' : '') + '</option>').join('')
            + (col === 0 && !isNew ? '<option value="0" selected>' + esc(hit.g.name) + '</option>' : '') + '</select></label>'
            + (isNew
                ? '<button type="button" class="okw" data-act="save"><i class="fas fa-plus"></i> زیادکردن بۆ پەڕە</button>'
                : '<div class="ord"><span>ڕیز</span>'
                  + ord('first', 'angles-up', 'یەکەم', at <= 0) + ord('up', 'arrow-up', 'پێشتر', at <= 0)
                  + ord('down', 'arrow-down', 'دواتر', at >= last) + ord('last', 'angles-down', 'کۆتایی', at >= last) + '</div>'
                  + '<button type="button" class="okw" data-act="save"><i class="fas fa-check"></i> پاشەکەوت</button>'
                  + '<div class="row2"><button type="button" class="hide' + (n.hidden ? ' back' : '') + '" data-act="hide">'
                  + (n.hidden ? '<i class="fas fa-eye"></i> گەڕاندنەوە بۆ پەڕە' : '<i class="fas fa-eye-slash"></i> لابردن لە پەڕە') + '</button>'
                  + '<button type="button" class="del" data-act="del"><i class="fas fa-trash"></i> سڕینەوە</button></div>');
        el.hidden = false;
        placeEditor();
        const box = $('rxEdName');
        if (box && !keepFocus) { box.focus(); box.select(); }
    }

    function openEditor(kind, id, keepFocus) {
        const hit = findNode(kind, id);
        if (!hit) { closeEditor(); return; }
        const n = hit.node;
        if (n.outside) { openOutsidePanel(n, hit, 0, keepFocus); return; }
        const el = edEl();
        edAt = { kind, id };
        const at = hit.list.indexOf(n), last = hit.list.length - 1;
        // the columns stand side by side (the sheet reads left to right)
        const across = kind === 'group';
        const ord = (act, icon, text, off) => '<button type="button" data-act="' + act + '"' + (off ? ' disabled' : '') + '>'
            + '<i class="fas fa-' + icon + '"></i> ' + text + '</button>';
        el.innerHTML =
            '<div class="hd"><i class="fas fa-' + ({ group: 'table-columns', type: 'circle-dot', item: 'pills' })[kind] + '"></i>'
            + '<bdi>' + esc(n.name) + '</bdi><span class="tp">' + RX_KIND_KU[kind] + '</span>'
            + '<button type="button" class="x" data-act="close" aria-label="داخستن">×</button></div>'
            + '<label class="fl"><span>ناو لەسەر پەڕە</span>'
            + '<div class="cu"><input type="text" id="rxEdName" maxlength="160" value="' + esc(n.name) + '" placeholder="' + esc(n.erp_name) + '" autocomplete="off">'
            + '<button type="button" class="ok" data-act="save" title="پاشەکەوت (Enter)"><i class="fas fa-check"></i></button></div></label>'
            + '<div class="erp">ناوی لە دەرمانخانە: <bdi>' + esc(n.erp_name) + '</bdi>'
            + (n.renamed ? ' · <button type="button" class="lnk" data-act="unname">گەڕاندنەوەی ئەو ناوە</button>' : '') + '</div>'
            + (kind === 'item'
                ? '<label class="fl"><span>ستوون</span><select id="rxEdGroup">' + groups.filter(g => g.id > 0).map(g =>
                    '<option value="' + g.id + '"' + (g.id === hit.g.id ? ' selected' : '') + '>' + esc(g.name)
                    + (g.id === n.home_group ? ' — گرووپی خۆی' : '') + (g.hidden ? ' (لابراوە)' : '') + '</option>').join('')
                    + (hit.g.id === 0 ? '<option value="0" selected>' + esc(hit.g.name) + '</option>' : '') + '</select></label>'
                : '')
            + '<div class="ord"><span>ڕیز</span>'
            + ord('first', across ? 'angles-left' : 'angles-up', 'یەکەم', at <= 0)
            + ord('up', across ? 'arrow-left' : 'arrow-up', 'پێشتر', at <= 0)
            + ord('down', across ? 'arrow-right' : 'arrow-down', 'دواتر', at >= last)
            + ord('last', across ? 'angles-right' : 'angles-down', 'کۆتایی', at >= last) + '</div>'
            + '<button type="button" class="hide' + (n.hidden ? ' back' : '') + '" data-act="hide">'
            + (n.hidden ? '<i class="fas fa-eye"></i> گەڕاندنەوە بۆ پەڕە' : '<i class="fas fa-eye-slash"></i> لابردن لە پەڕە') + '</button>'
            + (n.hidden ? '' : '<div class="nt">تەنها لەم پەڕەیە لادەبرێت — لە دەرمانخانە و کۆگا وەک خۆی دەمێنێت و هەر کاتێک دەگەڕێنرێتەوە.</div>');
        el.hidden = false;
        placeEditor();
        const box = $('rxEdName');
        if (box && !keepFocus) { box.focus(); box.select(); }
    }

    async function saveEditor() {
        if (!edAt) return;
        // a دەرمانی دەرەوە: new, or one on the sheet
        if (edAt.kind === 'new' || (findNode(edAt.kind, edAt.id) || { node: {} }).node.outside) {
            const box = $('rxEdName'), dose = $('rxEdDose'), sel = $('rxEdGroup');
            const name = box ? box.value.trim().replace(/\s+/g, ' ') : '';
            if (!name) { edMsg('ناوی دەرمانەکە بنووسە', 'err'); if (box) box.focus(); return; }
            if (edAt.kind === 'new') {
                const data = { kind: 'outside', id: 0, label: name, dosage: dose ? dose.value.trim() : '', group_id: sel ? sel.value : edAt.g };
                closeEditor();
                await arrange('sheet_set', data);
                return;
            }
            const hit = findNode(edAt.kind, edAt.id), n = hit.node;
            const data = { kind: 'outside', id: -n.id };
            let changed = false;
            if (name !== n.name) { data.label = name; changed = true; }
            if (dose && dose.value.trim() !== (n.dosage || '')) { data.dosage = dose.value.trim(); changed = true; }
            if (sel && Number(sel.value) !== hit.g.id) { data.group_id = sel.value; changed = true; }
            closeEditor();
            if (changed) await arrange('sheet_set', data);
            return;
        }
        const hit = findNode(edAt.kind, edAt.id);
        if (!hit) { closeEditor(); return; }
        const n = hit.node, box = $('rxEdName'), sel = $('rxEdGroup');
        const name = box ? box.value.trim().replace(/\s+/g, ' ') : n.name;
        const data = { kind: edAt.kind, id: edAt.id };
        let changed = false;
        // empty, or the pharmacy's own name again: back to the pharmacy's name
        if (name !== n.name) { data.label = (name === '' || name === n.erp_name) ? '' : name; changed = true; }
        if (sel && Number(sel.value) !== hit.g.id) { data.group_id = sel.value; changed = true; }
        closeEditor();
        if (changed) await arrange('sheet_set', data);
    }

    /** A new order for one list, on screen at once and then saved. */
    async function saveOrder(kind, g, list, keepOpen) {
        if (kind === 'group') groups.splice(0, groups.length, ...list);
        else if (kind === 'type') g.types = list;
        else g.items = list;
        const keep = keepOpen && edAt ? { kind: edAt.kind, id: edAt.id } : null;
        renderSheet();
        if (keep) openEditor(keep.kind, keep.id, true);
        const data = { kind, ids: JSON.stringify(list.map(x => x.id)) };
        if (kind !== 'group') data.group_id = g.id;
        await arrange('sheet_order', data);
        if (keep && edAt && edAt.kind === keep.kind && edAt.id === keep.id) openEditor(keep.kind, keep.id, true);
    }

    function onEditorClick(e) {
        const b = e.target.closest('[data-act]');
        if (!b || b.disabled || !edAt) return;
        const act = b.dataset.act;
        if (edAt.kind === 'new') {
            if (act === 'close') closeEditor(); else if (act === 'save') saveEditor();
            return;
        }
        const hit = findNode(edAt.kind, edAt.id);
        if (!hit) { closeEditor(); return; }
        const ox = !!hit.node.outside;
        if (act === 'close') closeEditor();
        else if (act === 'save') saveEditor();
        else if (act === 'unname') { const box = $('rxEdName'); if (box) box.value = hit.node.erp_name; saveEditor(); }
        else if (act === 'del') {
            if (!ox || !confirm('«' + hit.node.name + '» لە پەڕەکە بسڕدرێتەوە؟ ڕەچەتە نووسراوەکان وەک خۆیان دەمێننەوە.')) return;
            const data = { id: -hit.node.id };
            closeEditor();
            arrange('sheet_delete', data);
        } else if (act === 'hide') {
            const data = ox ? { kind: 'outside', id: -hit.node.id, hidden: hit.node.hidden ? 0 : 1 }
                : { kind: edAt.kind, id: edAt.id, hidden: hit.node.hidden ? 0 : 1 };
            closeEditor();
            arrange('sheet_set', data);
        } else {
            const list = hit.list.slice(), i = list.indexOf(hit.node);
            list.splice(i, 1);
            const to = act === 'first' ? 0 : act === 'last' ? list.length : act === 'up' ? i - 1 : i + 1;
            list.splice(Math.max(0, Math.min(list.length, to)), 0, hit.node);
            saveOrder(edAt.kind, hit.g, list, true);
        }
    }

    function onEditClick(e) {
        const add = e.target.closest('[data-add-ox]');
        if (add) {
            const gid = Number(add.dataset.addOx);
            if (edAt && edAt.kind === 'new' && edAt.g === gid) { closeEditor(); return; }
            openOutsidePanel(null, null, gid);
            return;
        }
        const eyeB = e.target.closest('.rxs-eye');
        if (eyeB) {
            e.preventDefault();
            e.stopPropagation();
            const hit = findNode(eyeB.dataset.eye, Number(eyeB.dataset.eid));
            if (hit) {
                closeEditor();
                arrange('sheet_set', hit.node.outside
                    ? { kind: 'outside', id: -hit.node.id, hidden: hit.node.hidden ? 0 : 1 }
                    : { kind: eyeB.dataset.eye, id: hit.node.id, hidden: hit.node.hidden ? 0 : 1 });
            }
            return;
        }
        const t = e.target.closest('.rxs-eh, .rxs-t.ed, .rxs-it.ed');
        if (!t) return;
        const kind = t.dataset.ek, id = Number(t.dataset.eid);
        if (edAt && edAt.kind === kind && edAt.id === id) { closeEditor(); return; }
        openEditor(kind, id);
    }

    /* dragging: an item before/after another (any column) or into a column's
       empty space; a round choice within its column; a column among columns */
    function clearDrop() {
        document.querySelectorAll('#rxGrid .drop-before, #rxGrid .drop-after, #rxGrid .drop-in')
            .forEach(x => x.classList.remove('drop-before', 'drop-after', 'drop-in'));
    }

    function dropSpot(e) {
        if (!drag) return null;
        const t = e.target.nodeType === 1 ? e.target : e.target.parentElement;
        if (!t) return null;
        if (drag.kind === 'item') {
            const it = t.closest('.rxs-it.ed');
            if (it && it !== drag.el) {
                const g = Number(it.dataset.g), r = it.getBoundingClientRect();
                if (g === 0 && drag.g !== 0) return null;   // «بێ گرووپ» takes no one in
                return { g, ref: Number(it.dataset.eid), before: e.clientY < r.top + r.height / 2, el: it };
            }
            if (it) return null;
            const col = t.closest('.rxs-col.ed');
            if (!col) return null;
            const g = Number(col.dataset.eid);
            if (g === 0 && drag.g !== 0) return null;
            return { g, ref: null, el: col.querySelector('.rxs-body') };
        }
        if (drag.kind === 'type') {
            const ty = t.closest('.rxs-t.ed');
            if (!ty || ty === drag.el || Number(ty.dataset.g) !== drag.g) return null;
            const r = ty.getBoundingClientRect();
            return { g: drag.g, ref: Number(ty.dataset.eid), before: e.clientX < r.left + r.width / 2, el: ty };
        }
        const col = t.closest('.rxs-col.ed');
        if (!col || col === drag.el) return null;
        const r = col.getBoundingClientRect();
        return { ref: Number(col.dataset.eid), before: e.clientX < r.left + r.width / 2, el: col };
    }

    function applyDrop(spot) {
        const hit = findNode(drag.kind, drag.id);
        if (!hit) return;
        if (drag.kind === 'group') {
            const list = groups.filter(x => x !== hit.node);
            const at = list.findIndex(x => x.id === spot.ref);
            list.splice(at + (spot.before ? 0 : 1), 0, hit.node);
            saveOrder('group', null, list, false);
            return;
        }
        const to = drag.kind === 'type' ? hit.g : groups.find(x => x.id === spot.g);
        if (!to) return;
        const field = drag.kind === 'type' ? 'types' : 'items';
        if (to !== hit.g) hit.g.items = hit.g.items.filter(x => x !== hit.node);   // left its old column
        const list = to[field].filter(x => x !== hit.node);
        const at = spot.ref === null ? list.length : list.findIndex(x => x.id === spot.ref) + (spot.before ? 0 : 1);
        list.splice(at, 0, hit.node);
        saveOrder(drag.kind, to, list, false);
    }

    async function setArranging(on) {
        rxEdit = on;
        closeEditor();
        if (popPid) closeDose();
        const bar = $('rxEditBar'), btn = $('rxArrange'), grid = $('rxGrid'), sheet = $('rxSheet');
        if (bar) bar.hidden = !on;
        if (btn) { btn.classList.toggle('on', on); btn.setAttribute('aria-pressed', on ? 'true' : 'false'); }
        if (grid) grid.classList.toggle('is-editing', on);
        if (sheet) sheet.classList.toggle('is-editing', on);
        edMsg('');
        await loadSheet();
    }

    function wireArranging() {
        const grid = $('rxGrid'), btn = $('rxArrange');
        if (!grid || !btn) return;
        btn.addEventListener('click', () => setArranging(!rxEdit));
        const done = $('rxEditDone');
        if (done) done.addEventListener('click', () => setArranging(false));
        const reset = $('rxEditReset');
        if (reset) reset.addEventListener('click', () => {
            if (!confirm('هەموو ڕێکخستنەکانی پەڕە (ناو، ڕیز، لابردن، گواستنەوە) بسڕدرێنەوە و پەڕەکە وەک دەرمانخانە بگەڕێتەوە؟ دەرمانە دەرەکییەکان لە ستوونی خۆیان دەمێننەوە.')) return;
            closeEditor();
            arrange('sheet_reset', {});
        });
        grid.addEventListener('keydown', e => {
            if (!rxEdit || (e.key !== 'Enter' && e.key !== ' ')) return;
            const t = e.target.closest && e.target.closest('.rxs-eh, .rxs-t.ed, .rxs-it.ed');
            if (!t || e.target.closest('.rxs-eye')) return;
            e.preventDefault();
            openEditor(t.dataset.ek, Number(t.dataset.eid));
        });
        grid.addEventListener('dragstart', e => {
            if (!rxEdit) return;
            const el = e.target.nodeType === 1 ? e.target : e.target.parentElement;
            if (!el || !el.dataset || !el.dataset.ek) return;
            drag = { kind: el.dataset.ek, id: Number(el.dataset.eid), g: Number(el.dataset.g || 0), el };
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', drag.kind + ':' + drag.id);
            closeEditor();
            requestAnimationFrame(() => el.classList.add('dragging'));
        });
        grid.addEventListener('dragover', e => {
            if (!drag) return;
            const spot = dropSpot(e);
            clearDrop();
            if (!spot) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            spot.el.classList.add(spot.ref === null ? 'drop-in' : (spot.before ? 'drop-before' : 'drop-after'));
        });
        grid.addEventListener('drop', e => {
            if (!drag) return;
            e.preventDefault();
            const spot = dropSpot(e);
            clearDrop();
            if (spot) applyDrop(spot);
        });
        grid.addEventListener('dragend', () => {
            clearDrop();
            if (drag && drag.el) drag.el.classList.remove('dragging');
            drag = null;
        });
        document.addEventListener('mousedown', e => {
            const el = $('rxEd');
            if (el && !el.hidden && !el.contains(e.target) && !e.target.closest('#rxGrid .rxs-eh, #rxGrid .rxs-t.ed, #rxGrid .rxs-it.ed, #rxGrid [data-add-ox]')) closeEditor();
        });
        window.addEventListener('scroll', placeEditor, true);
        window.addEventListener('resize', placeEditor);
    }

    /* ---------- wiring ---------- */
    document.addEventListener('DOMContentLoaded', function () {
        const grid = $('rxGrid');
        if (grid && CAN_WRITE) {
            grid.addEventListener('change', e => {
                const cb = e.target.closest('input[data-p]');
                if (cb) toggleItem(Number(cb.dataset.p));
            });
            grid.addEventListener('click', e => {
                if (rxEdit) { onEditClick(e); return; }
                const db = e.target.closest('.rxs-dose');
                if (db) {
                    // inside the item's label: keep the click from ticking it off
                    e.preventDefault();
                    e.stopPropagation();
                    if (popPid === Number(db.dataset.dose)) closeDose(); else openDose(Number(db.dataset.dose), db);
                    return;
                }
                const t = e.target.closest('.rxs-t');
                if (!t) return;
                const gid = Number(t.dataset.g), tid = Number(t.dataset.t);
                // the circled one again clears it
                if (groupForm[gid] === tid) delete groupForm[gid]; else groupForm[gid] = tid;
                renderSheet();
                render();
            });
            const find = $('rxFind');
            if (find) find.addEventListener('input', applyFind);
            const sug = $('rxFindOutside');
            if (sug) sug.addEventListener('click', () => {
                const name = find ? find.value.trim() : '';
                if (!name) return;
                find.value = '';
                applyFind();
                showRxTab('send');
                addOutside(name);
            });
            wireArranging();
            loadSheet();
        }
        const body = $('rxRows');
        if (body) {
            body.addEventListener('input', e => {
                const el = e.target;
                if (!el.dataset || el.dataset.i === undefined) return;
                const i = Number(el.dataset.i), f = el.dataset.f;
                if (f === 'preset') {
                    // a saved dose picked from the line's dropdown
                    const d = (lines[i].doses || [])[Number(el.value)];
                    if (!d) return;
                    lines[i].dosage = d.text;
                    const box = el.closest('td').querySelector('input[data-f="dosage"]');
                    if (box) box.value = d.text;
                    return;
                }
                lines[i][f] = f === 'quantity' ? Number(el.value) : el.value;
                if (f === 'quantity') stockWarning();
            });
            body.addEventListener('click', e => {
                const cell = e.target.closest('[data-del]');
                if (cell) removeLine(Number(cell.dataset.del));
            });
            const addOut = $('rxAddOutside');
            if (addOut) addOut.addEventListener('click', () => addOutside(''));
            // a request withdrawn from the history list
            const hist = $('rxHistory');
            if (hist) hist.addEventListener('click', async e => {
                const b = e.target.closest('[data-rxdel]');
                if (!b) return;
                const id = Number(b.dataset.rxdel);
                if (!confirm('داواکاری دەرمانی #' + id + ' بسڕدرێتەوە؟')) return;
                const fd = new FormData();
                fd.append('action', 'prescription');
                fd.append('id', id);
                try {
                    const res = await fetch('<?= APP_URL ?>/erp2/api/delete.php', { method: 'POST', body: fd });
                    const d = await res.json();
                    if (d && d.ok) { msg('داواکاری #' + id + ' سڕایەوە', 'ok'); loadHistory(); }
                    else msg((d && (d.error || d.message)) || 'هەڵە', 'err');
                } catch (err) { msg('هەڵەی تەکنیکی ڕوویدا', 'err'); }
            });
            // a dose or quantity finished in the table shows under the name too
            body.addEventListener('change', () => lines.forEach(l => refreshItem(l.product_id)));
        }
        // print (assets/js/rx-print.js): for whoever sees the list, not only who writes
        const histBox = $('rxHistory');
        if (histBox) histBox.addEventListener('click', e => {
            const p = e.target.closest('[data-rxprint]');
            if (p && window.RxPrint) window.RxPrint.open(Number(p.dataset.rxprint), { sold: p.dataset.sold === '1' });
        });
        loadHistory();
    });
    // دەرمان is a dialog, not a tab. It lives under <body> so no panel styling
    // (overflow, stacking) can clip a fixed overlay. With {patientId,
    // patientName, doctorId, doctorName, caseId} it is for that patient — the
    // doctor's room opens it per patient; another patient starts a clean sheet.
    window.openMedicineDialog = function (ctx) {
        if (!document.getElementById('medicineDialog')) return;
        if (ctx && typeof ctx === 'object') {
            const pid = Number(ctx.patientId) || 0;
            if (pid !== RX_PATIENT) {
                lines = [];
                Object.keys(groupForm).forEach(k => delete groupForm[k]);
                if ($('rxNotes')) $('rxNotes').value = '';
                msg('');
            }
            RX_PATIENT   = pid;
            RX_CASE_ID   = Number(ctx.caseId) || 0;
            RX_DOCTOR_ID = Number(ctx.doctorId) || 0;
            RX_DOCTOR    = String(ctx.doctorName || '');
            const nm = $('rxPatientName');
            if (nm) nm.textContent = ctx.patientName || '';
            if (groups.length) renderSheet();
            render();
            loadHistory();
        }
        showRxTab('pick');   // always start on step one
        openDialog('medicineDialog');
    };
    document.addEventListener('DOMContentLoaded', function () {
        const dlg = document.getElementById('medicineDialog');
        if (dlg && dlg.parentElement !== document.body) document.body.appendChild(dlg);
    });
})();
</script>
