<?php

declare(strict_types=1);

use Chess\Function\StandardFunction;
use Chess\Heuristics\SanHeuristics;
use Chess\Media\BoardToPng;
use Chess\Play\SanPlay;
use Chess\Tutor\FenParagraph;
use Dotenv\Dotenv;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Http\Response as Response;
use Slim\Views\PhpRenderer;

const IMG_FOLDER = __DIR__.'/../public/assets/img';
const DATA_FOLDER = __DIR__.'/../resources/data';

$dotenv = Dotenv::createImmutable(__DIR__.'/../');
$dotenv->load();

return function (App $app) {
    /*
    |---------------------------------------------------------------------------
    | CORS Pre-Flight OPTIONS Request Handler.
    |---------------------------------------------------------------------------
    */

    $app->options('/{routes:.*}', function (Request $request, Response $response) {
        return $response;
    });

    /*
    |---------------------------------------------------------------------------
    | Server-side rendered (SSR) pages.
    |---------------------------------------------------------------------------
    */

    $urlArgs = [
        'prot' => $_ENV['PROT'],
        'host' => $_ENV['HOST'],
        'port' => $_ENV['PORT'],
    ];

    $app->get('/', function (Request $request, Response $response) use ($urlArgs) {
        $renderer = new PhpRenderer('../templates');

        return $renderer->render($response, "games.html.php", $urlArgs);
    });

    $app->get('/games', function (Request $request, Response $response) use ($urlArgs) {
        $renderer = new PhpRenderer('../templates');

        return $renderer->render($response, "games.html.php", $urlArgs);
    });

    $app->get('/openings', function (Request $request, Response $response) use ($urlArgs) {
        $renderer = new PhpRenderer('../templates');

        return $renderer->render($response, "openings.html.php", $urlArgs);
    });

    $app->get('/opening/{eco}/{name}', function (Request $request, Response $response, $args) use ($urlArgs) {
        $contents = file_get_contents(DATA_FOLDER.'/openings.json');
        $json = json_decode($contents, true);
        foreach ($json as $opening) {
            $slug = URLify::slug($opening['name']);
            if ($slug === $args['name']) {
                $args['name'] = $opening['name'];
                $args['movetext'] = $opening['movetext'];
                $board = (new SanPlay($opening['movetext']))->validate()->getBoard();
                $paragraph = (new FenParagraph($board->toFen()))->getParagraph();
                $args['paragraph'] = implode(' ', $paragraph);
                if (!file_exists(IMG_FOLDER."$slug.png")) {
                    $args['output'] = (new BoardToPng($board, $flip = false))->output(IMG_FOLDER, $slug);
                } else {
                    $args['output'] = "$slug.png";
                }
            }
        }
        $renderer = new PhpRenderer('../templates');

        return $renderer->render($response, "opening.html.php", [...$args, ...$urlArgs]);
    });

    $app->get('/about', function (Request $request, Response $response) {
        $renderer = new PhpRenderer('../templates');

        return $renderer->render($response, "about.html.php");
    });

    /*
    |---------------------------------------------------------------------------
    | API endpoints.
    |---------------------------------------------------------------------------
    */

    $app->get('/api/openings/{letter}', function (Request $request, Response $response, $args) {
        $contents = file_get_contents(DATA_FOLDER.'/openings.json');
        $json = json_decode($contents, true);
        foreach ($json as $opening) {
            if (str_starts_with(strtolower($opening['eco']), $args['letter'])) {
                $opening['slug'] = URLify::slug($opening['name']);
                $openings[] = $opening;
            }
        }
        if (!isset($openings)) {
            return $response->withStatus(204);
        }

        return $response->withJson($openings, 200);
    });

    $app->post('/api/heuristics', function (Request $request, Response $response) {
        $params = $request->getParsedBody();

        // TODO: Parameter validation

        $function = new StandardFunction();
        $heuristics = new SanHeuristics($params['movetext']);

        $json = [
            'evalNames' => $function->names(),
            'balance' => $heuristics->getBalance(),
        ];

        return $response->withJson($json, 200);
    });

    $app->post('/api/tutor', function (Request $request, Response $response) {
        $params = $request->getParsedBody();

        // TODO: Parameter validation

        $board = (new SanPlay($params['movetext']))->validate()->getBoard();
        $paragraph = (new FenParagraph($board->toFen()))->getParagraph();

        $json = [
            'paragraph' => implode(' ', $paragraph),
        ];

        return $response->withJson($json, 200);
    });

    /*
    |---------------------------------------------------------------------------
    | Sitemap.
    |---------------------------------------------------------------------------
    */

    $app->get('/sitemap', function (Request $request, Response $response) {
        $xml = new \SimpleXMLElement(
            '<?xml version="1.0" encoding="utf-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
            </urlset>'
        );
        $contents = file_get_contents(DATA_FOLDER.'/openings.json');
        $json = json_decode($contents, true);
        foreach ($json as $opening) {
            $eco = strtolower($opening['eco']);
            $name = URLify::slug($opening['name']);
            $url = $xml->addChild('url');
            $url->addChild('loc', "{$_ENV['PROT']}://{$_ENV['HOST']}/opening/{$eco}/$name");
        }
        $body = $response->getBody();
        $body->write($xml->asXML());

        return $response->withHeader('Content-Type', 'text/xml')->withBody($body);
    });
};
