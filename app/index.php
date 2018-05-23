<?php
//Set App Mode
define("MODE", "development");

//Set the app's root directory
define("ROOT_DIR", __DIR__ . '/..');

//Show errors if in development mode
ini_set('show_errors', MODE !== 'production');

//Load autoloader
require ROOT_DIR . '/vendor/autoload.php';

//Get function library
require 'functions.inc.php';

//CONFIG
$app = new \Slim\App([
    'settings' => [
        //Debug setting. Turn off for production
        'displayErrorDetails' => MODE !== 'production',
        //Some middleware relies on knowing what route the app is taking
        'determineRouteBeforeAppMiddleware' => true,
        'http_auth' => [
            "users" => getUserCredentials(),
            "environment" => "REDIRECT_HTTP_AUTHORIZATION",
            "path" => "/",
            "passthough" => "/webhook"
        ]
    ],
    'ssh_host' => 'home706206022.1and1-data.host',
    'ssh_user' => 'u90967977',
    'ssh_password' => '5jyv*!VbksMKZ$9C',
    'php_cli' => '/usr/bin/php5.5-cli',
    'satis_bin' => ROOT_DIR . '/bin/satis',
    'satis_conf' => ROOT_DIR . '/satis.json',
    'web_output' => ROOT_DIR . '/packages-mirror',
    'build_template' => '%s %s build %s %s %s',
    ]);

//Need the container for stuff
$container = $app->getContainer();

//CONTAINER ENTRIES
$container['ssh'] = function (\Psr\Container\ContainerInterface $c) {
    $ssh = new phpseclib\Net\SSH2($c->get('ssh_host'));
    if (!$ssh->login($c->get('ssh_user'), $c->get('ssh_password'))) {
        throw new RuntimeException("Failed to log in to ssh host");
    }
    return $ssh;
};

//AUTHENTICATION MIDDLEWARE
$container['http_auth'] = function (\Psr\Container\ContainerInterface $c) {
    return new \Slim\Middleware\HttpBasicAuthentication($c->get('settings')['http_auth']);
};

//ROUTES
//Composer readable repository, including http authentication
$app->get('/', function ($request, $response, $args) {
    include ROOT_DIR . '/packages-mirror/index.html';
    return $response;
})->add($container->http_auth);

$app->get('/update', function ($request, $response, $args) {
    $ssh = $this->ssh;
    $packages = [];
    $build_cmd = sprintf(
        $this->build_template, 
        $this->php_cli, 
        $this->satis_bin, 
        $this->satis_conf, 
        $this->web_output, 
        implode(' ', $packages)
    );
    $output = $ssh->exec($build_cmd);
    if ((int) $ssh->getExitStatus() === 0) {
        echo $output;
        return $response;
    } else {
        return $response->withStatus(500);
    }
})->add($container->http_auth);

//Webhook to update each specific repository
$app->post('/webhook', function (\Psr\Http\Message\ServerRequestInterface $request, $response, $args) {
    $ssh = $this->ssh;
    $packages = getPackagesToUpdate($request);

    $handle = fopen('lastpayload.json', 'wb');
    $submission = $request->getParsedBody();
    fwrite($handle, json_encode($submission, JSON_JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
    fclose($handle);
    
    $build_cmd = sprintf(
        $this->build_template, 
        $this->php_cli, 
        $this->satis_bin, 
        $this->satis_conf, 
        $this->web_output, 
        implode(' ', $packages)
    );
    $output = $ssh->exec($build_cmd);
    if ((int) $ssh->getExitStatus() === 0) {
        return $response;
    } else {
        return $response->withStatus(500);
    }
});

//RUN
$app->run();
