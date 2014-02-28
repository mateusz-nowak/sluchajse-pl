<?php

/**
 * Require autoload file
 */
require_once '../vendor/autoload.php';

use Silex\Application;
use Pagerfanta\Pagerfanta;
use Pagerfanta\Adapter\ArrayAdapter;
use Macedigital\Silex\Provider\SerializerProvider;
use Symfony\Component\HttpFoundation\Request;
use Buzz\Browser;
use Silex\Provider\TwigServiceProvider;
use FranMoreno\Silex\Provider\PagerfantaServiceProvider;
use Symfony\Component\DomCrawler\Crawler;

$app = new Application();
$app['debug'] = true;

$app->register(new \Silex\Provider\UrlGeneratorServiceProvider());
$app->register(new SerializerProvider);
$app->register(new TwigServiceProvider, array(
    'twig.path' => __DIR__ . '/../views',
));
$app->register(new PagerfantaServiceProvider);
$app->register(new Sfk\Silex\Provider\MongoDBServiceProvider(), array(
    'mongodb.server' => 'mongodb://localhost:27017/sluchajse',
    'mongodb.options' => []
));

$app->get('/', function(Request $request) use ($app) {
    /** @var MongoClient $mongo */
    $mongo = $app['mongodb'];

    $lastTracks = $mongo->selectCollection('tracks')->find()->sort(['createdAt' => -1])->limit(10);
    $topTracks = $mongo->selectCollection('tracks')->find()->sort(['listen' => -1])->limit(10);

    return $app['twig']->render('index.html.twig', [
        'lastTracks' => $lastTracks,
        'topTracks' => $topTracks
    ]);
});

$app->get('/mp3/{slug}/download', function(Request $request, $slug) use ($app) {
    $id = (Integer) $slug;

    /** @var MongoClient $mongo */
    $mongo = $app['mongodb'];

    $track = $mongo->selectCollection('tracks')->findOne([
        '_id' => $id
    ]);

    $downloadUrl = sprintf('%s%s', 'http://www.mp3poisk.net', $track['download']);
    $browser = new Browser();

    $response = $browser->get($downloadUrl);

    $crawler = new Crawler();
    $crawler->addContent($response->getContent());

    $trackDownloadUrl = sprintf('%s%s', 'http://www.mp3poisk.net', $crawler->filter('.btn-download')->attr('href'));

    $stream = function () use ($trackDownloadUrl) {
        readfile($trackDownloadUrl);
    };

    return $app->stream($stream, 200, array(
        'Content-Type' => 'audio/mp3',
        'Content-Disposition' => sprintf('attachment; filename="[%s] %s.mp3"', 'sluchajse.pl', $track['name'])
    ));
});

$app->get('/mp3/{slug}', function(Request $request, $slug) use ($app) {
    $id = (Integer) $slug;

    /** @var MongoClient $mongo */
    $mongo = $app['mongodb'];

    $mongo->selectCollection('tracks')->update([
        '_id' => $id
    ], [
        '$inc' => [
            'listen' => 1
        ]
    ]);

    $track = $mongo->selectCollection('tracks')->findOne([
        '_id' => $id
    ]);

    return $app['twig']->render('show.html.twig', [
        'track' => $track,
        'slug' => $slug
    ]);
});

$app->get('/last', function(Request $request) use ($app) {
    /** @var MongoClient $mongo */
    $mongo = $app['mongodb'];

    $searches = $mongo->selectCollection('search')->find()->sort([ 'createdAt' => -1 ])->limit(200);

    return $app['twig']->render('last.html.twig', [
        'searches' => $searches
    ]);
});

$app->get('/search', function(Request $request) use ($app) {
    /** @var MongoClient $mongo */
    $mongo = $app['mongodb'];

    $browser = new Browser();
    $serializer = $app['serializer'];

    $q = $request->query->get('search');
    $page = $request->query->get('page') ? (Integer) $request->query->get('page'): 1;

    if (strlen($q) < 3) {
        throw new \InvalidArgumentException('Search query parameters must be greater than 2 chars');
    }

    $query = strtolower(preg_replace('/-{1,}/', '-', str_replace(' ', '-', preg_replace('/[-+=!@#$%^&*()\]\[\,\.\;\\\\\/]+/', '', $q))));

    $mongo->selectCollection('search')->update(['search' => $q], [
        '$set' => [
            'search' => $q,
            'createdAt' => new \Datetime()
        ]
    ], ['upsert' => true]);

    $response = $browser->get('http://www.mp3poisk.net/' . $query . '/' . $page, [
        "Content-Type: application/json",
        "X-Requested-With: XMLHttpRequest"
    ]);

    try {
        $result = $serializer->deserialize($response->getcontent(), 'array', 'json');

        if (isset($result['error'])) {
            throw new \RuntimeException('Not found');
        }

        foreach ($result['items'] as $track) {
            $mongo->selectCollection('tracks')->update([
                '_id' => $track['file_id']
            ], [
                '$set' => [
                    'name' => sprintf('%s - %s', $track['artist']['name'], $track['title']),
                    'slug' => sprintf('%d-%s-%s', $track['file_id'], $track['slug']['artist'], $track['slug']['track']),
                    'download' => $track['links']['download'],
                    'duration' => $track['duration'],
                    'createdAt' => new \Datetime(),
                    'listen' => 0
                ]
            ], ['upsert' => true]);
        }

        $adapter = new ArrayAdapter(range(1, $result['pagination']['total_items']));
        $pagerfanta = new Pagerfanta($adapter);
        $pagerfanta->setMaxPerPage(20);
        $pagerfanta->setCurrentPage($request->query->get('page', 1));
    } catch (\Exception $e) {
        $result = [];
        $pagerfanta = null;
    }

    return $app['twig']->render('search.html.twig', [
        'result' => $result,
        'query' => ucwords($q),
        'pager' => $pagerfanta,
        'q' => $q
    ]);
});

$app->run();
