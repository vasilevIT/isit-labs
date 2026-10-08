<?php
// Шаблон списка проектов. Переменная: $projects
?>
<h1>Проекты</h1>

<div class="grid">
  <?php foreach ($projects as $project): ?>
    <div class="card">
      <h3><a href="/projects/<?= e($project['id']) ?>"><?= e($project['title']) ?></a></h3>
      <p><?= e($project['description']) ?></p>
    </div>
  <?php endforeach; ?>
</div>
