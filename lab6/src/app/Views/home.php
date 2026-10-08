<?php
// Шаблон главной страницы. Переменные приходят из HomeController::index():
//   $total (всего задач), $byStatus (массив «статус => количество»), $projectsCount
?>
<h1>Главная</h1>

<div class="stats">
  <div class="card">
    <div class="stat-value"><?= e($total) ?></div>
    <div class="stat-label">всего задач</div>
  </div>
  <div class="card">
    <div class="stat-value"><?= e($byStatus['new']) ?></div>
    <div class="stat-label">новых</div>
  </div>
  <div class="card">
    <div class="stat-value"><?= e($byStatus['in_progress']) ?></div>
    <div class="stat-label">в работе</div>
  </div>
  <div class="card">
    <div class="stat-value"><?= e($byStatus['done']) ?></div>
    <div class="stat-label">выполнено</div>
  </div>
</div>

<p>В системе <strong><?= e($projectsCount) ?></strong> проекта. Перейдите к <a href="/tasks">списку задач</a> или <a href="/projects">проектам</a>.</p>
