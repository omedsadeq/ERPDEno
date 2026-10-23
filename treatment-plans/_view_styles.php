<?php if (!isset($plan)) { exit; } ?>
<style>
/* ═══ Page-specific ═══ */
.tp-notice {
    display: flex; gap: 11px; align-items: flex-start; padding: 13px 16px; margin-bottom: 18px;
    background: linear-gradient(135deg, #ede7f6, #e8eaf6); border: 1px solid #c5cae9; border-left: 4px solid #5e35b1;
    border-radius: 9px; font-size: 13px; line-height: 1.75; color: #37306b;
}
.tp-notice > i { color: #5e35b1; font-size: 17px; margin-top: 2px; }
.pv-summary-row { display: flex; justify-content: space-between; gap: 10px; padding: 8px 0; border-bottom: 1px solid #f0f0f5; font-size: 12.5px; color: #78788c; }
.pv-summary-row b { color: #37306b; text-align: left; max-width: 60%; word-break: break-word; }
.case-total-chip { display: inline-flex; align-items: center; gap: 7px; margin-top: 7px; padding: 4px 12px; border: 1px solid var(--border-color, #e1dfdd); border-radius: 8px; background: var(--bg-secondary, #faf9f8); white-space: nowrap; flex-wrap: wrap; }
.case-total-chip > i { font-size: 11px; color: var(--text-muted, #8a8886); }
.ctc-label { font-size: 11.5px; color: var(--text-secondary, #605e5c); }
.ctc-value { font-size: 15px; font-weight: 700; color: var(--text-primary, #201f1e); direction: ltr; }
.ctc-value.is-paid { color: #4527a0; }
.ctc-value.is-due  { color: #e65100; }
.ctc-sep { width: 1px; align-self: stretch; min-height: 16px; margin: 0 2px; background: var(--border-color, #e1dfdd); }
@media (max-width: 1400px) { .collapsible-sidebar-grid { grid-template-columns: 1fr !important; gap: 16px !important; } }
@media (max-width: 767px) {
    #tab-chart .panel-body > .d-flex.justify-between { flex-direction: column; align-items: stretch; gap: 10px; }
}

/* Items table */
.tp-items-wrap { overflow-x: auto; }
.tp-items { width: 100%; border-collapse: collapse; font-size: 12.5px; background: #fff; }
.tp-items th { padding: 9px 10px; background: #f4f2fb; color: #4b4470; font-size: 11px; font-weight: 700; text-align: right; white-space: nowrap; border-bottom: 1px solid #e3e0f0; }
.tp-items td { padding: 9px 10px; border-bottom: 1px solid #f2f2f8; vertical-align: middle; }
.tp-items tr.is-transferred { background: #f6fbf6; }
.tp-items tr.is-transferred td { color: #5b7a5e; }
.tp-tooth-chip { display: inline-block; padding: 2px 8px; border-radius: 5px; background: #ede7f6; color: #4527a0; font-weight: 700; font-size: 11.5px; direction: ltr; }
.tp-arch-chip { background: #f3e5f5; color: #7b1fa2; }
.tp-items .tp-num { direction: ltr; text-align: left; font-variant-numeric: tabular-nums; white-space: nowrap; }
.tp-item-state { padding: 2px 8px; border-radius: 999px; font-size: 10.5px; font-weight: 700; white-space: nowrap; }
.tp-item-state.planned { background: #ede7f6; color: #5e35b1; }
.tp-item-state.transferred { background: #e8f5e9; color: #2e7d32; }
.tp-row-actions { display: flex; gap: 5px; }
.tp-icon-btn { width: 27px; height: 27px; border: 1px solid #e0e0ec; border-radius: 6px; background: #fff; color: #78788c; cursor: pointer; font-size: 11px; display: inline-flex; align-items: center; justify-content: center; }
.tp-icon-btn:hover { background: #f3effb; color: #5e35b1; border-color: #c5b3e6; }
.tp-icon-btn.danger:hover { background: #ffebee; color: #c62828; border-color: #ffcdd2; }
.tp-empty-items { text-align: center; padding: 26px; color: #9a9ab0; font-size: 12.5px; }
.tp-totals { display: grid; grid-template-columns: repeat(auto-fit, minmax(135px, 1fr)); gap: 10px; margin-top: 15px; }
.tp-total-box { padding: 11px 13px; border-radius: 9px; background: #fff; border: 1px solid #e8e8f0; text-align: center; }
.tp-total-box .tp-total-label { font-size: 10.5px; color: #9a9ab0; font-weight: 600; margin-bottom: 3px; }
.tp-total-box .tp-total-value { font-size: 16px; font-weight: 800; color: #37306b; direction: ltr; }
.tp-total-box.is-discount .tp-total-value { color: #e65100; }
.tp-total-box.is-final { background: linear-gradient(135deg,#ede7f6,#e8eaf6); border-color: #c5cae9; }
.tp-total-box.is-final .tp-total-value { color: #4527a0; font-size: 18px; }

/* Dialog bits shared with the patient page */
.tp-radio { display: inline-flex; align-items: center; gap: 7px; padding: 8px 13px; border: 1.5px solid #e0e0ec; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 500; }
.tp-radio:has(input:checked) { border-color: #5e35b1; background: #f3effb; color: #4527a0; font-weight: 600; }
.tp-radio input { accent-color: #5e35b1; }
.tp-radio-block { display: flex; width: 100%; margin-bottom: 7px; }
.tp-dialog-msg { font-size: 12.5px; font-weight: 600; min-height: 17px; margin-top: 6px; }
.tp-dialog-msg.is-error { color: #c62828; }
.tp-dialog-msg.is-ok { color: #2e7d32; }
.tp-transfer-warn { display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; margin-bottom: 15px; background: #fff8e1; border: 1px solid #ffe082; border-left: 4px solid #f9a825; border-radius: 8px; font-size: 12.5px; line-height: 1.7; color: #7a5c00; }
.tp-transfer-warn > i { color: #f9a825; font-size: 16px; margin-top: 1px; }
.tp-tr-items { max-height: 210px; overflow-y: auto; border: 1px solid #e8e8f0; border-radius: 8px; }
.tp-tr-item { display: flex; align-items: center; gap: 9px; padding: 9px 12px; border-bottom: 1px solid #f4f4fa; font-size: 12.5px; cursor: pointer; }
.tp-tr-item:last-child { border-bottom: none; }
.tp-tr-item:hover { background: #faf8fe; }
.tp-tr-item input { accent-color: #2e7d32; width: 16px; height: 16px; }
.tp-tr-item .tp-tr-price { margin-inline-start: auto; font-weight: 700; direction: ltr; color: #37306b; }
.tp-tr-summary { padding: 10px 13px; border-radius: 8px; background: #e8f5e9; border: 1px solid #c8e6c9; color: #1b5e20; font-size: 13px; font-weight: 600; text-align: center; margin-bottom: 10px; }

/* ═══ Odontogram — copied from cases/view.php (smart chart) ═══ */
.dental-chart-container { display: flex; flex-direction: column; align-items: center; padding: 12px; background: #f8f9fa; border-radius: 8px; border: 1px solid var(--border-light); }
.tooth-tooltip { display: none; position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); background: #333; color: white; padding: 4px 8px; border-radius: 4px; font-size: 10px; white-space: nowrap; pointer-events: none; z-index: 1000; margin-bottom: 4px; direction: rtl; }
.tooth-tooltip::after { content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%); border: 4px solid transparent; border-top-color: #333; }
.tooth:hover .tooth-tooltip { display: block; }
@media (hover: none), (max-width: 767px) { .tooth:hover .tooth-tooltip { display: none; } }
.chart-legend { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; justify-content: center; padding: 10px; background: white; border-radius: 8px; }
.legend-color { width: 12px; height: 12px; border-radius: 3px; border: 1px solid rgba(0,0,0,0.1); }

.smart-dental-chart { --chart-blue: #1677ff; --chart-ink: #25354a; --chart-scale: 1; display: flex; flex-direction: column; align-items: center; width: 100%; box-sizing: border-box; padding: 18px 22px 16px; background: #fff; border: 1px solid #dfe8f1; border-radius: 10px; box-shadow: none; }
.smart-dental-chart .jaw-section { width: 100%; box-sizing: border-box; padding: 11px 2px 13px; margin: 0; background: transparent; overflow-x: auto; -webkit-overflow-scrolling: touch; overscroll-behavior-x: contain; scrollbar-width: none; }
.smart-dental-chart .jaw-section::-webkit-scrollbar { display: none; }
.smart-dental-chart .jaw-section + .jaw-section { border-top: 1px solid #e7edf4; padding-top: 17px; }
.smart-dental-chart .jaw-label { display: flex; align-items: center; justify-content: center; gap: 8px; margin: 0 0 9px; color: var(--chart-ink); font-size: 13px; font-weight: 800; letter-spacing: 0.02em; text-align: center; }
.smart-dental-chart .jaw-label::before, .smart-dental-chart .jaw-label::after { content: ''; display: block; width: min(8vw, 78px); height: 1px; background: linear-gradient(90deg, transparent, #ccd8e6); }
.smart-dental-chart .jaw-label::after { transform: scaleX(-1); }
.smart-dental-chart .teeth-row { display: flex; align-items: center; justify-content: center; width: 100%; min-width: min-content; }
.smart-dental-chart .quadrant { display: flex; align-items: flex-start; gap: calc(4px * var(--chart-scale)); padding: 3px 0 5px; background: transparent; border-radius: 0; direction: ltr; }
.dentition-switch { display: inline-flex; padding: 2px; gap: 2px; background: #eef2f7; border: 1px solid #dbe3ec; border-radius: 999px; }
.dentition-opt { display: inline-flex; align-items: center; gap: 5px; padding: 4px 12px; border: none; border-radius: 999px; background: transparent; color: var(--text-secondary, #5a6b7d); font-family: inherit; font-size: 11.5px; font-weight: 600; cursor: pointer; white-space: nowrap; transition: background .15s, color .15s, box-shadow .15s; }
.dentition-opt i { font-size: 11px; }
.dentition-opt:hover:not(.is-active) { background: #e2e9f2; color: #33475b; }
.dentition-opt.is-active { background: #fff; color: #0078d4; box-shadow: 0 1px 3px rgba(16, 42, 67, 0.14); }
.dentition-pane[hidden] { display: none; }
.smart-dental-chart .dentition-pane { width: 100%; box-sizing: border-box; min-width: 0; }
.smart-dental-chart .dentition-pane + .dentition-pane { margin-top: 14px; padding-top: 12px; border-top: 1px dashed #d8e2ee; }
.dentition-pane-title { display: flex; align-items: center; justify-content: center; gap: 6px; margin: 0 0 4px; color: #7a8b9d; font-size: 11px; font-weight: 700; letter-spacing: 0.02em; }
.dentition-pane-title i { font-size: 10px; color: #0078d4; }
.dentition-pane-title .dentition-pane-count { padding: 1px 7px; border-radius: 999px; background: #e8f3fc; color: #0a6cb8; font-size: 10px; font-weight: 700; }
.dentition-pane-title .dentition-pane-count:empty { display: none; }
.smart-dental-chart .quadrant-divider { flex: 0 0 auto; width: 1px; height: calc(102px * var(--chart-scale)); margin: 0 calc(clamp(10px, 1.15vw, 18px) * var(--chart-scale)); background: repeating-linear-gradient(to bottom, #cfd9e5 0 5px, transparent 5px 10px); border-radius: 1px; opacity: 0.96; }
.smart-dental-chart .tooth { --tooth-base-w: 56px; --tooth-base-art: 48px; --tooth-width: calc(var(--tooth-base-w) * var(--chart-scale)); --tooth-art-width: calc(var(--tooth-base-art) * var(--chart-scale)); --tooth-art-height: calc(82px * var(--chart-scale)); width: var(--tooth-width); min-width: var(--tooth-width); min-height: calc(112px * var(--chart-scale)); box-sizing: border-box; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 4px; position: relative; padding: 4px 2px 6px; font-size: 10px; background: transparent; border: 2px solid transparent; border-radius: 12px; transition: none; -webkit-tap-highlight-color: transparent; }
.smart-dental-chart .tooth--incisor { --tooth-base-w: 45px; --tooth-base-art: 42px; }
.smart-dental-chart .tooth--canine { --tooth-base-w: 50px; --tooth-base-art: 44px; }
.smart-dental-chart .tooth--premolar { --tooth-base-w: 54px; --tooth-base-art: 47px; }
.smart-dental-chart .tooth--molar { --tooth-base-w: 59px; --tooth-base-art: 51px; }
.smart-dental-chart .tooth:hover { background: #f5f9ff; border-color: #c9dbf1; box-shadow: none; z-index: 100; }
.smart-dental-chart .tooth.selected { border-color: var(--chart-blue); background: #f3f8ff; box-shadow: 0 0 0 3px rgba(22, 119, 255, 0.14), 0 10px 22px rgba(22, 119, 255, 0.12); }
.smart-dental-chart .tooth-svg { display: block; width: var(--tooth-art-width); height: var(--tooth-art-height, 82px); overflow: visible; }
.smart-dental-chart .tooth-svg svg { display: block; width: 100%; height: 100%; overflow: visible; filter: drop-shadow(0 3px 3px rgba(49, 76, 104, 0.10)); }
.smart-dental-chart .tooth-number { display: inline-grid; place-items: center; min-width: 22px; height: 22px; padding: 0 5px; border-radius: 7px; color: #2c3d54; font-size: 13px; font-weight: 800; line-height: 1; transition: background 160ms ease, color 160ms ease, box-shadow 160ms ease; }
.smart-dental-chart .tooth:hover .tooth-number, .smart-dental-chart .tooth.selected .tooth-number { background: var(--chart-blue); color: #fff; box-shadow: 0 3px 8px rgba(22, 119, 255, 0.28); }
.smart-dental-chart .tooth.has-treatments .tooth-number { color: #1b2d43; }
.smart-dental-chart .treatment-count { position: absolute; top: 26px; inset-inline-end: 1px; z-index: 3; min-width: 14px; height: 14px; padding: 0 2px; box-sizing: border-box; background: #2d7ff9; color: #fff; border: 1.5px solid #fff; border-radius: 999px; box-shadow: 0 2px 6px rgba(23, 89, 175, 0.28); font-size: 8px; font-weight: 800; line-height: 1; display: flex; align-items: center; justify-content: center; }
.smart-dental-chart .tooth.has-transferred .treatment-count { background: #2e7d32; }
.smart-dental-chart .chart-legend { display: flex; justify-content: center; align-items: center; gap: 7px 9px; flex-wrap: wrap; width: 100%; box-sizing: border-box; margin-top: 8px; padding: 10px 12px; background: rgba(255, 255, 255, 0.86); border: 1px solid #e0e8f0; border-radius: 12px; box-shadow: inset 0 1px 0 rgba(255,255,255,0.9); }
.smart-dental-chart .smart-chart-legend-item { display: inline-flex; align-items: center; gap: 6px; padding: 4px 7px; color: #4a5b70; font-size: 10.5px; font-weight: 650; line-height: 1.35; white-space: nowrap; }
.smart-dental-chart .smart-chart-legend-icon { display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 24px; flex: 0 0 auto; }
.smart-dental-chart .legend-color { width: 13px; height: 13px; border-radius: 4px; border: 1px solid rgba(0,0,0,0.12); box-shadow: inset 0 1px 0 rgba(255,255,255,0.45); }
@media (max-width: 1180px) {
    .smart-dental-chart { padding-inline: 15px; }
    .smart-dental-chart .jaw-section { overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-color: #c8d6e6 transparent; scrollbar-width: thin; }
    .smart-dental-chart .jaw-section::-webkit-scrollbar { display: block; height: 5px; }
    .smart-dental-chart .teeth-row { justify-content: flex-start; }
}
@media (max-width: 900px) {
    .smart-dental-chart .teeth-row, .smart-dental-chart .dentition-pane--primary .teeth-row { flex-wrap: wrap; justify-content: center; row-gap: 4px; }
    .smart-dental-chart .quadrant { flex: 1 0 100%; justify-content: center; }
    .smart-dental-chart .teeth-row > .quadrant:first-child { order: 2; }
    .smart-dental-chart .quadrant-divider { flex: 1 0 100%; order: 1; width: auto; height: 1px; margin: 3px 0; background: repeating-linear-gradient(to right, #cfd9e5 0 5px, transparent 5px 10px); }
}
@media (max-width: 767px) {
    .smart-dental-chart { padding: 13px 10px 12px; border-radius: 13px; }
    .smart-dental-chart .jaw-section { padding-inline: 2px; overflow-x: visible; }
    .smart-dental-chart .jaw-label { font-size: 12px; }
    .smart-dental-chart .jaw-label::before, .smart-dental-chart .jaw-label::after { width: 34px; }
    .smart-dental-chart .tooth { min-height: 0; gap: 2px; padding: 3px 1px 4px; }
    .smart-dental-chart .tooth-number { min-width: 18px; height: 18px; font-size: 11px; }
    .smart-dental-chart .treatment-count { transform: scale(0.85); }
}

/* Whole-arch / service work strip */
.chart-service-work { display: none; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin: 0 0 12px; }
.chart-service-work.has-items { display: grid; }
.chart-service-lane { min-width: 0; padding: 9px; border: 1px solid #dbe7f3; border-radius: 9px; background: #f8fbff; }
.chart-service-lane.is-upper { border-color: #bfdbfe; background: #eff6ff; }
.chart-service-lane.is-lower { border-color: #ddd6fe; background: #f5f3ff; }
.chart-service-lane.is-service { border-color: #99f6e4; background: #f0fdfa; }
.chart-service-title { display: flex; align-items: center; gap: 6px; margin-bottom: 6px; color: #526579; font-size: 11px; font-weight: 800; }
.chart-service-list { display: flex; flex-wrap: wrap; gap: 5px; }
.chart-service-card { display: inline-flex; align-items: center; gap: 6px; max-width: 100%; padding: 5px 7px; border: 1px solid rgba(100,116,139,.2); border-radius: 7px; background: #fff; font-size: 10.5px; cursor: pointer; }
.chart-service-card strong { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.chart-service-card .qty { color: #64748b; }
.chart-service-card .price { color: #b91c1c; font-weight: 800; white-space: nowrap; }
@media (max-width: 767px) { .chart-service-work { grid-template-columns: 1fr; } }

/* ═══ Tooth detail panel ═══ */
.tooth-detail-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 2500; display: none; }
.tooth-detail-overlay.open { display: flex; }
.tooth-detail-panel { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) scale(0.9); width: 340px; max-width: 95vw; max-height: 70vh; background: white; border-radius: 10px; box-shadow: 0 10px 40px rgba(0,0,0,0.3); z-index: 2500; opacity: 0; visibility: hidden; transition: all 0.2s ease; overflow: hidden; display: flex; flex-direction: column; }
.tooth-detail-panel.open { opacity: 1; visibility: visible; transform: translate(-50%, -50%) scale(1); }
.tooth-detail-header { background: linear-gradient(135deg, #0078d4, #0063b1); color: white; padding: 12px; display: flex; justify-content: space-between; align-items: center; }
.tooth-detail-info { display: flex; align-items: center; gap: 10px; }
.tooth-big-icon { width: 52px; height: 64px; background: rgba(255,255,255,0.92); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 18px; padding: 3px; }
.tooth-big-icon svg { width: 100%; height: 100%; display: block; }
.treatment-surface-tags { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 5px; }
.treatment-surface-tag { font-size: 10px; background: rgba(0, 120, 212, 0.08); color: #0067b8; border: 1px solid rgba(0, 120, 212, 0.25); padding: 1px 7px; border-radius: 10px; }
.treatment-del-btn { background: transparent; border: none; color: #c50f1f; cursor: pointer; font-size: 12px; padding: 3px 6px; border-radius: 4px; float: left; }
.treatment-del-btn:hover { background: rgba(197, 15, 31, 0.1); }
.treatment-edit-btn { background: transparent; border: none; color: #0078d4; cursor: pointer; font-size: 12px; padding: 3px 6px; border-radius: 4px; float: left; }
.treatment-edit-btn:hover { background: rgba(0, 120, 212, 0.1); }
.tooth-detail-title h3 { margin: 0; font-size: 14px; }
.tooth-detail-title p { margin: 0; font-size: 11px; opacity: 0.9; }
.close-detail-btn { background: rgba(255,255,255,0.2); border: none; color: white; width: 28px; height: 28px; border-radius: 50%; cursor: pointer; font-size: 12px; display: flex; align-items: center; justify-content: center; }
.close-detail-btn:hover { background: rgba(255,255,255,0.3); transform: rotate(90deg); }
.tooth-detail-body { padding: 12px; overflow-y: auto; flex: 1; }
.tooth-detail-section { margin-bottom: 12px; }
.tooth-detail-section h4 { font-size: 12px; color: var(--text-secondary); margin-bottom: 8px; display: flex; align-items: center; gap: 6px; padding-bottom: 6px; border-bottom: 1px solid var(--border-light); }
.treatment-history-item { background: var(--bg-main); border-radius: 6px; padding: 8px 10px; margin-bottom: 6px; border-right: 3px solid var(--primary-color); }
.treatment-history-item.filling { border-right-color: #00BCD4; }
.treatment-history-item.crown { border-right-color: #FFC107; }
.treatment-history-item.root_canal { border-right-color: #9C27B0; }
.treatment-history-item.extraction { border-right-color: #F44336; }
.treatment-history-item.implant { border-right-color: #FF9800; }
.treatment-history-item.cleaning { border-right-color: #4CAF50; }
.treatment-history-item .treatment-name { font-weight: 600; color: var(--text-color); margin-bottom: 4px; font-size: 12px; }
.treatment-history-item .treatment-meta { font-size: 10px; color: var(--text-secondary); display: flex; flex-wrap: wrap; gap: 8px; }
.treatment-history-item .treatment-meta span { display: flex; align-items: center; gap: 3px; }
.treatment-history-item .treatment-price { color: var(--success-color); font-weight: 600; }
.no-treatments { text-align: center; padding: 20px 10px; color: var(--text-secondary); }
.no-treatments i { font-size: 32px; margin-bottom: 8px; opacity: 0.3; display: block; }
.no-treatments p { font-size: 11px; margin: 0; }
.tooth-detail-footer { padding: 10px 12px; background: var(--bg-main); border-top: 1px solid var(--border-light); }
.add-treatment-btn-panel { width: 100%; padding: 8px 12px; font-size: 12px; }
.tooth-quick-stats { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; margin-bottom: 12px; }
.quick-stat { background: var(--bg-main); padding: 8px; border-radius: 6px; text-align: center; }
.quick-stat-value { font-size: 16px; font-weight: 700; color: var(--primary-color); }
.quick-stat-label { font-size: 9px; color: var(--text-secondary); margin-top: 2px; }
#addTreatmentDialog, #archTreatmentDialog, #planHeaderDialog, #tpTransferDialog { z-index: 3000 !important; }
#addTreatmentDialog.open, #archTreatmentDialog.open, #planHeaderDialog.open, #tpTransferDialog.open { z-index: 3000 !important; display: flex !important; align-items: center !important; justify-content: center !important; }
#addTreatmentDialog.open .dialog, #archTreatmentDialog.open .dialog { position: relative !important; top: auto !important; left: auto !important; }

/* ═══ Treatment picker ═══ */
.tp-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 3200; display: none; align-items: center; justify-content: center; padding: 16px; }
.tp-overlay.open { display: flex; }
.tp-sheet { background: var(--bg-color, #fff); width: 560px; max-width: 100%; max-height: 82vh; border-radius: 14px; box-shadow: 0 16px 48px rgba(0,0,0,0.3); display: flex; flex-direction: column; overflow: hidden; }
@media (max-width: 600px) { .tp-overlay { align-items: flex-end; padding: 0; } .tp-sheet { width: 100%; max-height: 88vh; border-radius: 14px 14px 0 0; } }
.tp-head { display: flex; justify-content: space-between; align-items: center; padding: 14px 16px; border-bottom: 1px solid var(--border-color, #e3e6ea); }
.tp-head h4 { margin: 0; font-size: 15px; display: flex; align-items: center; gap: 8px; }
.tp-close { background: var(--bg-main, #f2f4f6); border: none; width: 30px; height: 30px; border-radius: 50%; cursor: pointer; color: var(--text-secondary); }
.tp-search-row { display: flex; align-items: center; gap: 8px; margin: 12px 16px 0; padding: 8px 12px; border: 1px solid var(--border-color, #e3e6ea); border-radius: 10px; background: var(--bg-main, #f8f9fa); }
.tp-search-row i { color: var(--text-secondary); font-size: 13px; }
.tp-search-row input { border: none; outline: none; background: transparent; flex: 1; font-family: inherit; font-size: 13px; }
.tp-cats { display: flex; gap: 6px; flex-wrap: wrap; padding: 12px 16px 8px; }
.tp-cat { border: 1px solid var(--border-color, #e3e6ea); background: var(--bg-color, #fff); color: var(--text-secondary); padding: 5px 12px; border-radius: 20px; font-size: 12px; cursor: pointer; font-family: inherit; }
.tp-cat.on { background: #0078d4; border-color: #0078d4; color: #fff; font-weight: 600; }
.tp-list { overflow-y: auto; padding: 4px 12px 14px; flex: 1; }
.tp-item { display: flex; align-items: center; gap: 10px; width: 100%; padding: 10px 12px; margin-top: 6px; border: 1px solid var(--border-color, #e3e6ea); border-radius: 10px; background: var(--bg-color, #fff); cursor: pointer; font-family: inherit; text-align: right; transition: all 0.12s; }
.tp-item:hover { border-color: #0078d4; background: rgba(0, 120, 212, 0.05); }
.tp-item-icon { flex-shrink: 0; display: flex; align-items: center; }
.tp-item-name { flex: 1; font-size: 13px; font-weight: 600; color: var(--text-color, #222); }
.tp-item-price { font-size: 12.5px; font-weight: 700; color: #107c10; }
.tp-empty { text-align: center; padding: 30px 0; color: var(--text-secondary); font-size: 13px; }
.tp-trigger { flex: 1; display: flex; align-items: center; gap: 8px; padding: 8px 12px; border: 1px solid var(--border-color, #d5dbe1); border-radius: 6px; background: var(--bg-color, #fff); cursor: pointer; font-family: inherit; font-size: 13px; color: var(--text-secondary); min-height: 38px; text-align: right; }
.tp-trigger:hover { border-color: #0078d4; }
.tp-btn-icon { display: flex; align-items: center; flex-shrink: 0; }
.tp-btn-name { flex: 1; color: var(--text-color, #222); font-weight: 600; }
.tp-btn-price { font-weight: 700; color: #107c10; font-size: 12.5px; }

/* ═══ Surface picker ═══ */
.surface-picker { display: flex; align-items: center; gap: 14px; padding: 10px; border: 1px solid var(--border-color, #e3e6ea); border-radius: 10px; background: var(--bg-main, #f8f9fa); }
.surface-tooth-holder { width: 74px; height: 100px; flex-shrink: 0; }
.surface-tooth-holder svg { width: 100%; height: 100%; }
.surface-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.surface-chip { border: 1px solid var(--border-color, #d5dbe1); background: var(--bg-color, #fff); color: var(--text-secondary); padding: 5px 11px; border-radius: 16px; font-size: 12px; cursor: pointer; font-family: inherit; }
.surface-chip.on { background: #0078d4; border-color: #0078d4; color: #fff; font-weight: 600; }

/* ═══ Modern treatment dialog ═══ */
.treatment-dialog-modern { max-width: 780px; width: 96%; border-radius: 16px; overflow: hidden; box-shadow: 0 25px 80px rgba(0, 0, 0, 0.35); background: #fff; }
.treatment-dialog-header { background: linear-gradient(135deg, #0078d4 0%, #005a9e 100%); color: white; padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; }
.treatment-dialog-header h4 { margin: 0; font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
.treatment-dialog-close { background: rgba(255,255,255,0.15); border: none; color: white; width: 32px; height: 32px; border-radius: 8px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; }
.treatment-dialog-close:hover { background: rgba(255,255,255,0.25); }
.treatment-dialog-body { padding: 16px 20px; max-height: calc(100vh - 130px); overflow-y: auto; }
.treatment-alert { border-radius: 8px; padding: 12px; margin-bottom: 16px; display: flex; align-items: center; gap: 10px; }
.treatment-alert-warning { background: #fff3e0; border: 1px solid #ffe0b2; }
.treatment-alert-warning i { color: #f57c00; font-size: 18px; }
.treatment-alert-warning span { color: #e65100; font-size: 13px; }
.treatment-alert-warning a { color: #0078d4; text-decoration: underline; }
.treatment-form-grid { display: grid; grid-template-columns: 1fr 2fr; gap: 12px; margin-bottom: 12px; }
.treatment-form-field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px; }
.treatment-form-field.treatment-field-full { grid-column: 1 / -1; }
.treatment-form-field label { font-size: 12px; font-weight: 600; color: #37474f; display: flex; align-items: center; gap: 6px; }
.treatment-form-field label i { color: #0078d4; font-size: 11px; }
.treatment-form-field label .required { color: #f44336; }
.treatment-form-field input, .treatment-form-field select { padding: 10px 12px; border: 1.5px solid #e0e0e0; border-radius: 8px; font-size: 13px; font-family: inherit; transition: all 0.2s; background: #fff; }
.treatment-form-field input:focus, .treatment-form-field select:focus { border-color: #0078d4; box-shadow: 0 0 0 3px rgba(0, 120, 212, 0.1); outline: none; }
.treatment-pricing-section { background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border-radius: 12px; overflow: hidden; margin-bottom: 12px; }
.treatment-pricing-section .pricing-header { background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%); color: white; padding: 10px 14px; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
.treatment-pricing-section .pricing-content { padding: 14px; }
.treatment-pricing-section .price-row { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
.treatment-pricing-section .price-row:last-child { margin-bottom: 0; }
.treatment-pricing-section .price-label { font-size: 12px; color: #546e7a; min-width: 100px; }
.treatment-pricing-section .price-value { font-size: 14px; font-weight: 700; color: #2e7d32; }
.treatment-pricing-section .agreement-price input { flex: 1; padding: 8px 10px; border: 1.5px solid #e0e0e0; border-radius: 6px; font-size: 13px; font-family: inherit; max-width: 120px; }
.treatment-pricing-section .agreement-price input:focus { border-color: #ff9800; box-shadow: 0 0 0 3px rgba(255, 152, 0, 0.1); outline: none; }
.treatment-pricing-section .use-default-btn { background: linear-gradient(135deg, #0078d4 0%, #005a9e 100%); border: none; color: white; width: 32px; height: 32px; border-radius: 6px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; }
.treatment-pricing-section .use-default-btn:hover { transform: rotate(180deg); }
.treatment-total-display { background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%); border: 1px solid #a5d6a7; border-radius: 10px; padding: 12px 16px; display: flex; align-items: center; justify-content: center; gap: 8px; margin-bottom: 12px; }
.treatment-total-display .total-label { font-size: 13px; font-weight: 600; color: #2e7d32; }
.treatment-total-display .total-value { font-size: 18px; font-weight: 700; color: #1b5e20; direction: ltr; }
.treatment-dialog-footer { padding: 14px 20px; background: #f8f9fa; border-top: 1px solid #e0e0e0; display: flex; gap: 10px; flex-direction: row-reverse; flex-wrap: wrap; }
.treatment-btn-primary, .treatment-btn-secondary { padding: 10px 20px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: all 0.2s; border: none; font-family: inherit; }
.treatment-btn-primary { background: linear-gradient(135deg, #0078d4 0%, #005a9e 100%); color: white; }
.treatment-btn-primary:hover { background: linear-gradient(135deg, #005a9e 0%, #004578 100%); transform: translateY(-1px); }
.treatment-btn-primary:disabled { opacity: .6; cursor: wait; transform: none; }
.treatment-btn-secondary { background: white; color: #546e7a; border: 1.5px solid #e0e0e0; }
.treatment-btn-secondary:hover { background: #f5f5f5; border-color: #bdbdbd; }
@media (max-width: 480px) { .treatment-form-grid { grid-template-columns: 1fr; } .treatment-form-field.treatment-field-full { grid-column: 1; } }
.pl-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; align-items: start; }
.pl-col { min-width: 0; }
@media (max-width: 680px) { .pl-grid { grid-template-columns: 1fr; gap: 8px; } }

/* ═══ Arch chart + arch dialog ═══ */
.arch-view-chart { padding: 16px; background: #f8f9fa; border-radius: 12px; border: 1px solid #e0e0e0; }
.arch-occ-topbar { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
.arch-occ-topbar-label { font-weight: 700; font-size: 14px; color: var(--text-color, #222); display: flex; align-items: center; gap: 8px; }
.arch-occ-topbar-label i { color: #0078d4; }
.arch-occ-chip { background: #e7f1fb; color: #0067b8; border: 1px solid #c4ddf3; font-size: 12px; font-weight: 600; padding: 4px 14px; border-radius: 16px; }
.arch-occ-panel { display: flex; gap: 16px; align-items: stretch; border: 1.5px solid #74c69d; background: var(--bg-color, #fff); border-radius: 16px; padding: 16px; margin-bottom: 18px; flex-wrap: wrap; }
.arch-occ-panel[hidden] { display: none; }
.arch-occ-chart { flex: 1; min-width: 300px; max-width: 560px; margin: 0 auto; }
.arch-occ-chart svg { width: 100%; height: auto; display: block; }
.arch-occ-info { width: 300px; flex-shrink: 0; display: flex; flex-direction: column; gap: 12px; }
@media (max-width: 860px) { .arch-occ-info { width: 100%; } }
.arch-occ-title { display: flex; align-items: center; gap: 10px; }
.arch-occ-title h4 { margin: 0; font-size: 15px; }
.arch-occ-title small { color: var(--text-secondary); font-size: 11px; }
.arch-occ-mouth { width: 46px; height: 46px; border-radius: 12px; background: #d8f3e3; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
.arch-occ-mouth::after { content: ''; width: 26px; height: 15px; border: 3px solid #2f9e44; background: #fff; display: block; }
.arch-occ-mouth--upper::after { border-radius: 13px 13px 5px 5px; border-bottom-width: 4px; }
.arch-occ-mouth--lower::after { border-radius: 5px 5px 13px 13px; border-top-width: 4px; }
.arch-occ-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.arch-occ-stat { background: var(--bg-main, #f7f8fa); border: 1px solid var(--border-light, #eceff2); border-radius: 10px; padding: 8px 10px; display: flex; align-items: center; gap: 8px; }
.arch-occ-stat b { font-size: 16px; color: var(--text-color, #222); }
.arch-occ-stat small { color: var(--text-secondary); font-size: 11px; }
.arch-occ-stat-icon { display: flex; align-items: center; flex-shrink: 0; }
.arch-occ-statusbar { background: #e8f7ee; border: 1px solid #bfe6cd; color: #217a3c; border-radius: 10px; padding: 8px 12px; font-size: 12px; display: flex; align-items: center; gap: 8px; margin-top: auto; }
.arch-occ-statusbar.is-empty { background: var(--bg-main, #f4f5f7); border-color: var(--border-light, #e3e6ea); color: var(--text-secondary); }
.arch-occ-legend { display: flex; flex-wrap: wrap; justify-content: center; gap: 8px 26px; background: var(--bg-color, #fff); border-radius: 12px; padding: 12px 16px; border: 1px solid var(--border-light, #eceff2); }
.arch-occ-legend-item { display: flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 600; color: var(--text-color, #333); }
.arch-tooth { cursor: pointer; }
.arch-tooth:hover .at-body { stroke: #0078d4; stroke-width: 2.6px; }
.arch-tooth.selected .at-body { stroke: #0078d4; stroke-width: 3px; }
.arch-num:hover rect { stroke: #0078d4; }

.arch-dialog-modern { max-width: 780px; width: 96%; border-radius: 16px; overflow: hidden; box-shadow: 0 25px 80px rgba(0, 0, 0, 0.35); background: #fff; }
.arch-dialog-header { background: linear-gradient(135deg, #9c27b0 0%, #7b1fa2 100%); color: white; padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; }
.arch-dialog-header h4 { margin: 0; font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
.arch-dialog-close { background: rgba(255,255,255,0.15); border: none; color: white; width: 32px; height: 32px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.arch-dialog-close:hover { background: rgba(255,255,255,0.25); }
.arch-dialog-body { padding: 16px 20px; max-height: calc(100vh - 130px); overflow-y: auto; }
.arch-form-field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
.arch-form-field label { font-size: 12px; font-weight: 600; color: #37474f; display: flex; align-items: center; gap: 6px; }
.arch-form-field label i { color: #9c27b0; font-size: 11px; }
.arch-form-field label .required { color: #f44336; }
.arch-form-field input, .arch-form-field select { padding: 10px 12px; border: 1.5px solid #e0e0e0; border-radius: 8px; font-size: 13px; font-family: inherit; background: #fff; }
.arch-form-field input:focus, .arch-form-field select:focus { border-color: #9c27b0; box-shadow: 0 0 0 3px rgba(156, 39, 176, 0.1); outline: none; }
.arch-select-chart-modern { display: flex; flex-direction: column; gap: 10px; }
.arch-select-panel { border: 2px solid #e0e0e0; border-radius: 10px; padding: 12px; cursor: pointer; transition: all 0.3s ease; background: #fafafa; }
.arch-select-panel:hover { border-color: #ce93d8; background: #faf5fc; transform: translateY(-1px); }
.arch-select-panel.selected { border-color: #9c27b0; background: linear-gradient(135deg, #f3e5f5 0%, #e1bee7 100%); box-shadow: 0 3px 10px rgba(156, 39, 176, 0.2); }
.arch-select-panel.selected .arch-select-outline { stroke: #9c27b0; stroke-width: 3; }
.arch-select-panel.selected .arch-teeth-indicators rect { fill: #9c27b0; }
.arch-select-visual { display: flex; justify-content: center; }
.arch-select-svg { width: 100%; max-width: 280px; height: 70px; }
.arch-teeth-indicators rect { fill: #90caf9; transition: all 0.2s ease; }
.arch-select-panel:hover .arch-teeth-indicators rect { fill: #ce93d8; }
.arch-select-label { display: flex; align-items: center; gap: 8px; margin-top: 8px; padding-top: 8px; border-top: 1px solid #e8e8e8; }
.arch-select-label span:first-of-type { flex: 1; font-weight: 600; color: #2c3e50; font-size: 13px; }
.arch-select-count { font-size: 11px; color: #757575; background: #f0f0f0; padding: 2px 8px; border-radius: 10px; }
.arch-select-check { color: #bdbdbd; font-size: 16px; transition: all 0.3s ease; }
.arch-select-panel.selected .arch-select-check { color: #9c27b0; }
.arch-select-hint-modern { font-size: 12px; color: #78909c; margin-top: 8px; display: flex; align-items: center; gap: 6px; }
.arch-select-hint-modern i { color: #9c27b0; }
.arch-pricing-mode-selector { display: flex; gap: 8px; flex-wrap: wrap; }
.arch-pricing-mode-option { flex: 1; min-width: 120px; display: flex; align-items: center; gap: 8px; padding: 10px 12px; border: 1.5px solid #e0e0e0; border-radius: 8px; cursor: pointer; transition: all 0.2s; font-size: 12px; background: #fafafa; }
.arch-pricing-mode-option input { display: none; }
.arch-pricing-mode-option i { color: #9c27b0; }
.arch-pricing-mode-option:hover { border-color: #ce93d8; background: #faf5fc; }
.arch-pricing-mode-option.selected { border-color: #9c27b0; background: linear-gradient(135deg, #f3e5f5, #e1bee7); font-weight: 600; }
.arch-pricing-section { background: #f8f9fa; border-radius: 12px; overflow: hidden; margin-bottom: 14px; }
.arch-pricing-header { padding: 10px 14px; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 8px; color: white; }
.arch-pricing-header.orange { background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%); }
.arch-pricing-header.green { background: linear-gradient(135deg, #4caf50 0%, #388e3c 100%); }
.arch-pricing-header.purple { background: linear-gradient(135deg, #9c27b0 0%, #7b1fa2 100%); }
.arch-pricing-content { padding: 14px; }
.arch-price-row { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
.arch-price-row:last-child { margin-bottom: 0; }
.arch-price-row.calculated { background: #e8f5e9; margin: 10px -14px -14px; padding: 10px 14px; }
.arch-price-label { font-size: 12px; color: #546e7a; min-width: 110px; }
.arch-price-value { font-size: 14px; font-weight: 700; color: #ff9800; }
.arch-price-value.green { color: #2e7d32; }
.arch-pricing-content input { flex: 1; padding: 8px 10px; border: 1.5px solid #e0e0e0; border-radius: 6px; font-size: 13px; font-family: inherit; max-width: 140px; }
.arch-pricing-content input:focus { outline: none; box-shadow: 0 0 0 3px rgba(156, 39, 176, 0.1); }
.arch-pricing-content input.green-input { border-color: #4caf50; }
.arch-pricing-content input.blue-input { border-color: #0078d4; }
.arch-pricing-content input.purple-input { border-color: #9c27b0; }
.arch-use-default-btn { background: linear-gradient(135deg, #9c27b0 0%, #7b1fa2 100%); border: none; color: white; width: 32px; height: 32px; border-radius: 6px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; }
.arch-use-default-btn:hover { transform: rotate(180deg); }
.arch-total-display { background: linear-gradient(135deg, #f3e5f5 0%, #e1bee7 100%); border: 1px solid #ce93d8; border-radius: 10px; padding: 14px 16px; display: flex; align-items: center; justify-content: center; gap: 8px; margin-bottom: 14px; }
.arch-total-display .arch-total-label { font-size: 13px; font-weight: 600; color: #7b1fa2; }
.arch-total-display .arch-total-value { font-size: 20px; font-weight: 700; color: #4a148c; direction: ltr; }
.arch-dialog-footer { padding: 14px 20px; background: #f8f9fa; border-top: 1px solid #e0e0e0; display: flex; gap: 10px; flex-direction: row-reverse; }
.arch-btn-primary, .arch-btn-secondary { padding: 10px 20px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: all 0.2s; border: none; font-family: inherit; }
.arch-btn-primary { background: linear-gradient(135deg, #9c27b0 0%, #7b1fa2 100%); color: white; }
.arch-btn-primary:hover { background: linear-gradient(135deg, #7b1fa2 0%, #6a1b9a 100%); transform: translateY(-1px); }
.arch-btn-primary:disabled { opacity: .6; cursor: wait; transform: none; }
.arch-btn-secondary { background: white; color: #546e7a; border: 1.5px solid #e0e0e0; }
.arch-btn-secondary:hover { background: #f5f5f5; border-color: #bdbdbd; }
</style>
