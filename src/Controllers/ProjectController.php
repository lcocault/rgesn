<?php

declare(strict_types=1);

namespace Rgesn\Controllers;

use Rgesn\Database;
use Rgesn\Repositories\EvaluationRepository;
use Rgesn\Repositories\ProjectRepository;
use Rgesn\Support\Csrf;
use Rgesn\Support\Http;
use Rgesn\Support\View;

final class ProjectController
{
    private ProjectRepository $projects;
    private EvaluationRepository $evaluations;

    public function __construct()
    {
        $db = Database::connection();
        $this->projects = new ProjectRepository($db);
        $this->evaluations = new EvaluationRepository($db);
    }

    public function index(): void
    {
        echo View::render('projects/index.html.twig', [
            'projects' => $this->projects->all(),
            'csrf' => Csrf::token(),
        ]);
    }

    public function create(): void
    {
        if (!Csrf::check()) {
            Http::flash('error', 'Session expirée, merci de réessayer.');
            Http::redirect('/projects');
        }

        $name = Http::input('name');
        if ($name === '') {
            Http::flash('error', "Le nom du projet est obligatoire.");
            Http::redirect('/projects');
        }

        $id = $this->projects->create($name, Http::input('description'));
        Http::flash('success', 'Projet créé.');
        Http::redirect('/projects/' . $id);
    }

    public function show(array $params): void
    {
        $project = $this->projects->find((int) $params['id']);
        if ($project === null) {
            http_response_code(404);
            echo View::render('errors/404.html.twig', []);
            return;
        }

        echo View::render('projects/show.html.twig', [
            'project' => $project,
            'evaluations' => $this->evaluations->forProject((int) $project['id']),
            'csrf' => Csrf::token(),
        ]);
    }

    public function update(array $params): void
    {
        $id = (int) $params['id'];
        if (!Csrf::check()) {
            Http::flash('error', 'Session expirée, merci de réessayer.');
            Http::redirect('/projects/' . $id);
        }

        $name = Http::input('name');
        if ($name === '') {
            Http::flash('error', "Le nom du projet est obligatoire.");
            Http::redirect('/projects/' . $id);
        }

        $this->projects->update($id, $name, Http::input('description'));
        Http::flash('success', 'Projet mis à jour.');
        Http::redirect('/projects/' . $id);
    }

    public function delete(array $params): void
    {
        if (!Csrf::check()) {
            Http::flash('error', 'Session expirée, merci de réessayer.');
            Http::redirect('/projects');
        }

        $this->projects->delete((int) $params['id']);
        Http::flash('success', 'Projet supprimé.');
        Http::redirect('/projects');
    }
}
