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
$app = new \Slim\App(require ROOT_DIR . '/config/config.inc.php');

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

$container['x_hub'] = function (Psr\Container\ContainerInterface $c) {
    //Creates a new verification object
    $x_hub = new \Keruald\GitHub\XHubSignature($c->ssh_password);
    //Need to remove the algo so the verification object'll work
    $x_hub->signature = str_replace("sha1=", "", $c->request->getHeader("HTTP_X_HUB_SIGNATURE")[0]);
    $x_hub->payload = json_encode($c->request->getParsedBody(), JSON_UNESCAPED_SLASHES);
    return $x_hub;
};

$container['log_file'] = function (Psr\Container\ContainerInterface $c) {
    $file = new SplFileObject(ROOT_DIR . "/logs/requestLog.txt", 'a+b');
    return $file;
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

$app->get('/update[/{vendor}/{package}]', function ($request, $response, $args) {
    $ssh = $this->ssh;
    if (isset($args['vendor'])) {
        $packages = [$args['vendor'] . '/' . $args['package']];
    } else {
        $packages = [''];
    }
    $output = cliBuild($this, $packages);
    if ((int) $ssh->getExitStatus() === 0) {
        echo $output;
        return $response;
    } else {
        echo $output;
        return $response->withStatus(500);
    }
})->add($container->http_auth);

//Webhook to update each specific repository
$app->post('/webhook', function (\Psr\Http\Message\ServerRequestInterface $request, $response, $args) {
    $ssh = $this->ssh;
    $packages = getPackagesToUpdate($request);
    if (MODE !== "production") {
        $handle = fopen('lastpayload.json', 'wb');
        $submission = $request->getParsedBody();
        fwrite($handle, json_encode($submission, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        fclose($handle);
    }
    //Check the request header
    $valid = $this->x_hub->validate();
    //Log what happened
    $this->log_file->fwrite(
        vsprintf('Request with signature (%5$s): "%1$s" from ip: %3$s, %2$s, Packages: %4$s' . "\n", [
        $this->x_hub->signature,
        $valid ? "Okay" : "Denied",
        $request->getServerParams()['REMOTE_ADDR'],
        implode(", ", $packages),
        \Carbon\Carbon::now(),
    ]));

    if ($valid) {
        $output = cliBuild($this, $packages);
        //Log the exit code
        $this->log_file->fwrite(
            vsprintf('Exit code: %s' . "\n", [$ssh->getExitStatus()])
        );
        if ((int) $ssh->getExitStatus() === 0) {
            return $response;
        }
    }
    $this->log_file->fwrite(
        vsprintf('Output: %s' . "\n", [$output])
    );

    return $response->withStatus(500);
});

//RUN
$app->run();
