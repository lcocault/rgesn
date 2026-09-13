<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Rgesn\Config;
use Rgesn\Controllers\AuthController;
use Rgesn\Controllers\DeclarationController;
use Rgesn\Controllers\EvaluationController;
use Rgesn\Controllers\McpController;
use Rgesn\Controllers\ProjectController;
use Rgesn\Router;
use Rgesn\Support\Auth;
use Rgesn\Support\Http;
use Rgesn\Support\View;

session_start();

$rootPath = dirname(__DIR__);
Config::init($rootPath);

if (Config::isDebug()) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

// Sous-répertoire éventuel de publication (vide à la racine du domaine, cas recommandé).
// Configurable via APP_BASE_PATH dans .env plutôt que déduit de SCRIPT_NAME, qui varie
// de façon peu fiable selon le serveur HTTP (Apache, PHP-FPM, serveur intégré...).
define('BASE_URL_PREFIX', Config::basePath());

View::init($rootPath . '/templates', Config::isDebug());

$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'] ?? '/';

// Retire le préfixe de sous-répertoire pour que le routeur ne travaille qu'avec des chemins relatifs à l'app.
if (BASE_URL_PREFIX !== '' && str_starts_with($uri, BASE_URL_PREFIX)) {
    $uri = substr($uri, strlen(BASE_URL_PREFIX)) ?: '/';
}

$mcpEnabled = Config::accessPassword() !== null && Config::accessPassword() !== '';

$publicRoutes = ['/login'];
if ($mcpEnabled) {
    $publicRoutes[] = '/mcp';
}
$path = parse_url($uri, PHP_URL_PATH) ?: '/';

if (Auth::isRequired() && !Auth::isLoggedIn() && !in_array($path, $publicRoutes, true)) {
    Http::redirect('/login');
}

try {
    $router = new Router();

    $router->get('/', function () {
        Http::redirect('/projects');
    });

    $router->get('/login', fn () => (new AuthController())->form());
    $router->post('/login', fn () => (new AuthController())->attempt());
    $router->post('/logout', fn () => (new AuthController())->logout());
    if ($mcpEnabled) {
        $router->post('/mcp', fn () => (new McpController())->handle());
    }

    $router->get('/projects', fn () => (new ProjectController())->index());
    $router->post('/projects', fn () => (new ProjectController())->create());
    $router->get('/projects/{id}', fn ($p) => (new ProjectController())->show($p));
    $router->post('/projects/{id}', fn ($p) => (new ProjectController())->update($p));
    $router->post('/projects/{id}/delete', fn ($p) => (new ProjectController())->delete($p));

    $router->post('/projects/{project}/evaluations', fn ($p) => (new EvaluationController())->create($p));

    $router->get('/evaluations/{id}', fn ($p) => (new EvaluationController())->show($p));
    $router->post('/evaluations/{id}/delete', fn ($p) => (new EvaluationController())->delete($p));
    $router->get('/evaluations/{id}/gating', fn ($p) => (new EvaluationController())->gatingForm($p));
    $router->post('/evaluations/{id}/gating', fn ($p) => (new EvaluationController())->gatingSave($p));
    $router->post('/evaluations/{id}/meta', fn ($p) => (new EvaluationController())->updateMeta($p));
    $router->post('/evaluations/{id}/complete', fn ($p) => (new EvaluationController())->complete($p));
    $router->post('/evaluations/{id}/reopen', fn ($p) => (new EvaluationController())->reopen($p));
    $router->get('/evaluations/{id}/criteria/{code}', fn ($p) => (new EvaluationController())->criterionForm($p));
    $router->post('/evaluations/{id}/criteria/{code}', fn ($p) => (new EvaluationController())->criterionSave($p));

    $router->get('/evaluations/{id}/declaration/preview', fn ($p) => (new DeclarationController())->preview($p));
    $router->post('/evaluations/{id}/declaration', fn ($p) => (new DeclarationController())->generate($p));
    $router->get('/declarations/{id}', fn ($p) => (new DeclarationController())->show($p));
    $router->get('/declarations/{id}/html', fn ($p) => (new DeclarationController())->raw($p));
    $router->get('/declarations/{id}/pdf', fn ($p) => (new DeclarationController())->pdf($p));

    $router->dispatch($method, $uri);
} catch (\Throwable $e) {
    http_response_code(500);
    if (Config::isDebug()) {
        echo '<pre>' . htmlspecialchars($e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>';
    } else {
        echo View::render('errors/500.html.twig', []);
    }
}
