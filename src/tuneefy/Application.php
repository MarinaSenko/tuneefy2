<?php

namespace tuneefy;

use Chadicus\Slim\OAuth2\Middleware;
use Chadicus\Slim\OAuth2\Routes;
use OAuth2;
use Slim\App;
use Slim\Views\Twig;
use Slim\Views\TwigExtension;
use Symfony\Component\Yaml\Yaml;
use tuneefy\Controller\ApiController;
use tuneefy\Controller\FrontendController;
use tuneefy\DB\DatabaseHandler;
use tuneefy\Platform\Platform;
use tuneefy\Utils\ContentTypeMiddleware;
use tuneefy\Utils\CustomErrorHandler;
use tuneefy\Utils\CustomNotFoundHandler;
use tuneefy\Utils\Utils;

class Application
{
    const PATHS = [
        'parameters' => '/../../app/config/parameters.yml',
        'platforms' => '/../../app/config/platforms.yml',
        'templates' => '/../../app/templates',
        'cache' => '/../../var/cache',
    ];

    private $slimApp;
    private $engine;

    public function __construct()
    {
        $this->slimApp = new App();

        // Get container
        $container = $this->slimApp->getContainer();

        // Register component on container
        $container['view'] = function ($container) {
            $view = new Twig(self::getPath('templates'), [
                //'cache' => self::getPath('cache'),
            ]);

            $basePath = rtrim(str_ireplace('index.php', '', $container['request']->getUri()->getBasePath()), '/');
            $view->addExtension(new TwigExtension($container['router'], $basePath));

            return $view;
        };

        // Custom 404, 405 and 500 handlers
        // Override the default handlers
        $container['notFoundHandler'] = function ($container) {
            return new CustomNotFoundHandler($container->get('view'), 404, 'NOT_FOUND');
        };
        $container['notAllowedHandler'] = function ($container) {
            return new CustomNotFoundHandler($container->get('view'), 405, 'NOT_ALLOWED');
        };

        // $container['errorHandler'] = function ($container) {
        //     return new CustomErrorHandler($container->get('view'), 500, 'GENERAL_ERROR');
        // };

        // $container['phpErrorHandler'] = function ($container) {
        //     return $container['errorHandler'];
        // };

        // Application engine
        $this->engine = new PlatformEngine();
    }

    public static function getPath(string $which)
    {
        return dirname(__FILE__).self::PATHS[$which];
    }

    public function set(string $key, $value)
    {
        $container = $this->slimApp->getContainer();
        $container[$key] = $value;
    }

    public function run(bool $returnObject = false)
    {
        return $this->slimApp->run($returnObject);
    }

    public function configure()
    {
        // Fetch config files
        try {
            $platforms = Yaml::parse(file_get_contents(self::getPath('platforms')));
            $params = Yaml::parse(file_get_contents(self::getPath('parameters')));
        } catch (\Exception $e) {
            // TODO  : translate / template : this will not happen in Slim's run loop, handle differently
            throw new \Exception('No config files found');
        }

        if ($platforms === null || $params === null) {
            // TODO  : translate / template : this will not happen in Slim's run loop, handle differently
            throw new \Exception('Bad config files');
        }

        // Sets the base for the urls
        Utils::setBase($params['urls']['base']);

        foreach ($platforms as $key => $platform) {
            $p = $this->engine->getPlatformByTag($key);

            if ($p === null) {
                continue;
            }

            $p->setEnables($platform['enable'])
              ->setCapabilities($platform['capabilities'])
              ->setCredentials($platform['key'], $platform['secret']);
        }

        // Connect to DB
        try {
            $db = new DatabaseHandler($params);
        } catch (\Exception $e) {
            // TODO  : translate / template
            throw new \Exception('Problem with database instantiation : '.$e->getMessage());
        }

        $container = $this->slimApp->getContainer();
        $container['params'] = $params;

        /* Documentation for the API, has to go first */
        $this->slimApp->get('/api', FrontendController::class.':api');

        /* The API group, behind an (optional) OAuth2 Server */
        $api = $this->slimApp->group('/api', function () {
            $this->get('/platforms', ApiController::class.':getAllPlatforms');
            $this->get('/platform/{tag}', ApiController::class.':getPlatform');
            $this->get('/lookup', ApiController::class.':lookup');
            $this->get('/search/{type}/{platform_str}', ApiController::class.':search');
            $this->get('/aggregate/{type}', ApiController::class.':aggregate');
            $this->get('/share/{intent}', ApiController::class.':share');
        });

        if ($params['api']['use_oauth'] === true) {
            /* Set up storage (tokens, credentials) for OAuth2 server */
            $storage = new OAuth2\Storage\Pdo($db->getConnection());

            /* Create the oauth2 server */
            $this->oauth2Server = new OAuth2\Server(
                $storage,
                ['access_lifetime' => $params['api']['access_lifetime']],
                [new OAuth2\GrantType\ClientCredentials($storage)]
            );

            /* OAuth2 Middleware for the API */
            $api->add(new Middleware\Authorization($this->oauth2Server, $container));

            /* The token route for OAuth */
            $this->slimApp->post('/api/auth'.Routes\Token::ROUTE, new Routes\Token($this->oauth2Server))->setName('token');
        }

        /* The display/show page for a musical entity */
        $this->slimApp->get($params['urls']['format'], FrontendController::class.':show')->setName('show');

        /* Listen to a musical entity => goes to the platform link */
        $this->slimApp->get($params['urls']['format'].'/listen/{platform}[/{i:[0-9]+}]', FrontendController::class.':listen')->setName('listen');

        /* The other frontend routes */
        $this->slimApp->get('/', FrontendController::class.':home')->setName('home');
        $this->slimApp->get('/about', FrontendController::class.':about')->setName('about');
        $this->slimApp->get('/trends', FrontendController::class.':trends')->setName('trends');

        $this->slimApp->add(new ContentTypeMiddleware());

        return $this;
    }
}
