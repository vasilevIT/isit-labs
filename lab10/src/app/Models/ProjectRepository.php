<?php
// app/Models/ProjectRepository.php — модель проектов (таблица projects).

declare(strict_types=1);

namespace App\Models;

class ProjectRepository extends PgRepository
{
    protected string $table = 'projects';
    protected string $columns = 'id, title, description';
}
