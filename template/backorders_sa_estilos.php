<style>
  /* Backorders sin adopción (backorders_sa.php / backorders_sa_informe.php) — mismo lenguaje visual
     que reporte_backorders_pedido.php. */
  .bsa-section { background:#fff; border-radius:14px; box-shadow:0 2px 10px rgba(15,23,42,.08); margin-bottom:20px; overflow:hidden; }
  .bsa-section-head { display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; padding:18px 24px; border-bottom:1px solid #e2e8f0; }
  .bsa-section-title { font-size:.95rem; font-weight:700; color:#0f172a; margin:0; }
  .bsa-section-body { padding:24px; }

  .bsa-btn { display:inline-flex; align-items:center; gap:8px; padding:8px 18px; border-radius:8px; font-size:.85rem; font-weight:700;
             background:linear-gradient(135deg,#1d4ed8,#2563eb); color:#fff; border:none; cursor:pointer; text-decoration:none; transition:opacity .15s; }
  .bsa-btn:hover { opacity:.9; color:#fff; text-decoration:none; }
  .bsa-btn-outline { background:#fff; color:#1d4ed8; border:1.5px solid #1d4ed8; }
  .bsa-btn-outline:hover { color:#1d4ed8; }
  .bsa-btn-danger { background:#fff; color:#dc2626; border:1.5px solid #fecaca; }
  .bsa-btn-danger:hover { color:#dc2626; background:#fef2f2; }
  .bsa-btn-sm { padding:5px 10px; font-size:.75rem; gap:5px; }
  .bsa-acciones { display:flex; gap:6px; flex-wrap:wrap; }

  .bsa-label { display:block; font-size:.72rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px; }
  .bsa-req { color:#ef4444; }
  .bsa-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:14px; }
  .bsa-grid-3 { display:grid; grid-template-columns:repeat(3, 1fr); gap:16px; margin-bottom:14px; }
  .bsa-grid-filtros { display:grid; grid-template-columns:2fr 2fr 1.3fr 1fr 1fr auto; gap:12px; align-items:end; margin-bottom:16px; }
  @media (max-width: 900px) { .bsa-grid-2, .bsa-grid-3, .bsa-grid-filtros { grid-template-columns:1fr; } }
  .bsa-dato { font-size:1.1rem; font-weight:800; color:#0f172a; }
  .bsa-aviso-azul { background:#eff6ff; border:1px solid #bfdbfe; color:#1e3a8a; border-radius:10px; padding:12px 16px; font-size:.85rem; margin:0 0 16px; }

  .bsa-resumen { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
  .bsa-card { border-radius:10px; padding:12px 18px; min-width:150px; }
  .bsa-card .num { font-size:1.3rem; font-weight:800; }
  .bsa-card .lbl { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:#64748b; }
  .bsa-card-sol { background:#eff6ff; } .bsa-card-sol .num { color:#1d4ed8; }
  .bsa-card-at  { background:#f0fdf4; } .bsa-card-at .num  { color:#16a34a; }
  .bsa-card-pen { background:#fffbeb; } .bsa-card-pen .num { color:#b45309; }
  .bsa-card-cnt { background:#f8fafc; } .bsa-card-cnt .num { color:#0f172a; }

  table.bsa-tabla { width:100%; border-collapse:collapse; font-size:.83rem; }
  table.bsa-tabla th, table.bsa-tabla td { border:1px solid #e2e8f0; padding:8px 10px; vertical-align:top; }
  table.bsa-tabla thead th { background:#eef2ff; color:#1e3a8a; font-weight:700; text-align:left; }
  table.bsa-tabla .bsa-num { text-align:center; font-variant-numeric:tabular-nums; }
  table.bsa-tabla tr.bsa-fila-anulada td { color:#94a3b8; }
  .bsa-sub { display:block; font-size:.72rem; color:#94a3b8; }
  .bsa-vacio { color:#64748b; text-align:center; padding:30px 0 !important; }

  .bsa-badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:.72rem; font-weight:700; white-space:nowrap; }
  .bsa-badge-pendiente { background:#fef3c7; color:#b45309; }
  .bsa-badge-atendido  { background:#dcfce7; color:#15803d; }
  .bsa-badge-anulado   { background:#fee2e2; color:#b91c1c; }

  .bsa-hist-item { border-left:3px solid #c7d2fe; padding:4px 0 4px 12px; margin-bottom:10px; font-size:.83rem; }
  .bsa-hist-item .meta { font-size:.72rem; color:#64748b; }
  .bsa-hist-anulado { border-left-color:#fecaca; }
  .bsa-hist-atencion { border-left-color:#bbf7d0; }

  /* ── Formulario "Nuevo backorder" ── */
  .bsa-nuevo { background:#fff; border-radius:16px; box-shadow:0 4px 24px rgba(15,23,42,.08); margin-bottom:22px; overflow:hidden; border:1px solid #e0e7ff; }
  .bsa-nuevo-head { display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;
                    padding:22px 26px; background:linear-gradient(135deg,#1e3a8a 0%,#4f46e5 55%,#7c3aed 100%); color:#fff; }
  .bsa-nuevo-head-txt { display:flex; align-items:center; gap:14px; }
  .bsa-nuevo-icono { width:48px; height:48px; border-radius:14px; background:rgba(255,255,255,.16); display:flex; align-items:center; justify-content:center; font-size:1.35rem; flex-shrink:0; }
  .bsa-nuevo-head h5 { margin:0; font-size:1.08rem; font-weight:800; color:#fff; }
  .bsa-nuevo-head p { margin:2px 0 0; font-size:.8rem; color:rgba(255,255,255,.82); }
  .bsa-btn-glass { background:rgba(255,255,255,.14); border:1px solid rgba(255,255,255,.35); color:#fff; backdrop-filter:blur(4px); }
  .bsa-btn-glass:hover { background:rgba(255,255,255,.24); color:#fff; }

  .bsa-nuevo-body { padding:22px 26px 6px; }
  .bsa-paso { display:flex; gap:16px; padding-bottom:20px; position:relative; }
  .bsa-paso:not(:last-child)::before { content:''; position:absolute; left:15px; top:36px; bottom:6px; width:2px; background:#e0e7ff; }
  .bsa-paso-num { width:32px; height:32px; border-radius:50%; background:#eef2ff; color:#4338ca; font-weight:800; font-size:.85rem;
                  display:flex; align-items:center; justify-content:center; flex-shrink:0; border:2px solid #c7d2fe; position:relative; z-index:1; }
  .bsa-paso-cont { flex:1; min-width:0; }
  .bsa-paso-titulo { margin:4px 0 2px; font-weight:800; font-size:.92rem; color:#0f172a; }
  .bsa-paso-ayuda { margin:0 0 10px; font-size:.76rem; color:#64748b; }
  .bsa-opcional { font-size:.68rem; font-weight:600; color:#94a3b8; background:#f1f5f9; padding:1px 8px; border-radius:10px; margin-left:4px; }
  .bsa-input { width:100%; border:1.5px solid #e2e8f0; border-radius:10px; padding:10px 12px; font-size:.86rem; transition:border-color .15s, box-shadow .15s; resize:vertical; }
  .bsa-input:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.15); }

  .bsa-linea { display:flex; align-items:center; gap:10px; background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:12px;
               padding:8px 10px; margin-bottom:10px; transition:border-color .15s, box-shadow .15s; }
  .bsa-linea:hover { border-color:#c7d2fe; box-shadow:0 2px 8px rgba(79,70,229,.08); }
  .bsa-linea-num { width:24px; height:24px; border-radius:7px; background:#e0e7ff; color:#4338ca; font-size:.72rem; font-weight:800;
                   display:flex; align-items:center; justify-content:center; flex-shrink:0; }
  .bsa-linea-libro { flex:1; min-width:0; }
  .bsa-stepper { display:flex; align-items:center; background:#fff; border:1.5px solid #e2e8f0; border-radius:10px; overflow:hidden; flex-shrink:0; }
  .bsa-step { width:32px; height:34px; border:none; background:#fff; color:#4338ca; font-size:1rem; cursor:pointer; transition:background .15s; }
  .bsa-step:hover { background:#eef2ff; }
  .bsa-cant { width:64px; height:34px; border:none; border-left:1px solid #e2e8f0; border-right:1px solid #e2e8f0; text-align:center; font-weight:700; font-size:.9rem; -moz-appearance:textfield; }
  .bsa-cant::-webkit-inner-spin-button, .bsa-cant::-webkit-outer-spin-button { -webkit-appearance:none; margin:0; }
  .bsa-cant:focus { outline:none; background:#f5f3ff; }
  .bsa-linea-quitar { width:34px; height:34px; border-radius:10px; border:1.5px solid #fecaca; background:#fff; color:#dc2626; cursor:pointer; flex-shrink:0; transition:background .15s; }
  .bsa-linea-quitar:hover:not(:disabled) { background:#fef2f2; }
  .bsa-linea-quitar:disabled { opacity:.35; cursor:not-allowed; }
  .bsa-btn-agregar { width:100%; padding:10px; border:2px dashed #c7d2fe; border-radius:12px; background:#fafaff; color:#4338ca;
                     font-weight:700; font-size:.84rem; cursor:pointer; transition:background .15s, border-color .15s; }
  .bsa-btn-agregar:hover { background:#eef2ff; border-color:#818cf8; }

  .bsa-nuevo-footer { display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap;
                      padding:16px 26px; background:#f8fafc; border-top:1px solid #e2e8f0; }
  .bsa-nuevo-resumen { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
  .bsa-chip { display:inline-flex; align-items:center; gap:6px; background:#fff; border:1px solid #e0e7ff; color:#3730a3; border-radius:20px; padding:5px 12px; font-size:.8rem; }
  .bsa-nuevo-fecha { font-size:.75rem; color:#64748b; }
  .bsa-btn-lg { padding:11px 24px; font-size:.9rem; border-radius:10px; background:linear-gradient(135deg,#4f46e5,#7c3aed); box-shadow:0 4px 14px rgba(79,70,229,.3); }

  /* select2 dentro del formulario moderno */
  .bsa-nuevo .select2-container .select2-selection--single { height:40px !important; border:1.5px solid #e2e8f0 !important; border-radius:10px !important; background:#fff; }
  .bsa-nuevo .select2-container .select2-selection__rendered { line-height:37px !important; font-size:.86rem; padding-left:12px !important; }
  .bsa-nuevo .select2-container .select2-selection__arrow { height:38px !important; }
  .bsa-nuevo .select2-container--open .select2-selection--single { border-color:#6366f1 !important; box-shadow:0 0 0 3px rgba(99,102,241,.15); }
  .bsa-linea .select2-container .select2-selection--single { height:36px !important; }
  .bsa-linea .select2-container .select2-selection__rendered { line-height:33px !important; }
  .bsa-linea .select2-container .select2-selection__arrow { height:34px !important; }
  @media (max-width: 700px) {
    .bsa-linea { flex-wrap:wrap; }
    .bsa-linea-libro { flex-basis:calc(100% - 34px); }
    .bsa-nuevo-body, .bsa-nuevo-head, .bsa-nuevo-footer { padding-left:16px; padding-right:16px; }
  }
</style>
