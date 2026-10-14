<?php
// app/Models/ProjectRepository.php — модель проектов (файл storage/projects.json).

declare(strict_types=1);

namespace App\Models;

final class ProjectRepository extends JsonRepository
{
    protected string $name = 'projects';
}
