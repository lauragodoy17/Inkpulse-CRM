<!-- Modal Historial de un backorder sin adopción (lo llena BSA.mostrarHistorial() en src/backorders_sa.js) -->
<div class="modal fade" id="ModalHistorialBsa" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content" style="border-radius:12px; overflow:hidden;">
      <div class="modal-header" style="background:#eef2ff; border-bottom:1px solid #c7d2fe;">
        <h4 class="modal-title" style="color:#1e3a8a; font-weight:700; font-size:1rem;"><i class="bi bi-clock-history"></i> Backorder #<span id="hi-id"></span></h4>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body" style="padding:20px;" id="hi-body"></div>
    </div>
  </div>
</div>
