</div><!-- /.page-content -->
</div><!-- /.main -->

<script>
function showToast(msg, type='info'){
  const c = document.getElementById('toast-container');
  const t = document.createElement('div');
  t.className = 'toast ' + type;
  const icons = {success:'circle-check',error:'circle-x',info:'info-circle'};
  t.innerHTML = `<i class="ti ti-${icons[type]||'info-circle'}" style="font-size:1.1rem"></i><span>${msg}</span>`;
  c.appendChild(t);
  setTimeout(()=>{ t.style.animation='slideOut .3s ease forwards'; setTimeout(()=>t.remove(),300); }, 3500);
}
<?php if (!empty($_SESSION['toast'])): ?>
showToast(<?= json_encode($_SESSION['toast']['msg']) ?>, <?= json_encode($_SESSION['toast']['type']) ?>);
<?php unset($_SESSION['toast']); endif; ?>
</script>
</body>
</html>

