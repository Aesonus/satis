<?php

function getPackagesToUpdate(Psr\Http\Message\ServerRequestInterface $request)
{
    if ('35.195.211.84' === $request->getServerParams()['REMOTE_ADDR']) {
        $payload = $request->getParsedBody();
        $vendor = str_replace(" ", "", strtolower($payload['project']['name']));
        $package = $payload['repository']['name'];
        return [$vendor . '/' . $package];
    } elseif ('192.30.252.34' === $request->getServerParams()['REMOTE_ADDR']) {
        return [str_replace(" ", "", strtolower($payload['project']['full_name']))];
    }
}

function getUserCredentials()
{
    $raw_creds = file(ROOT_DIR . '/config/angrycats', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $creds = [];

    foreach ($raw_creds as $raw_creds_line) {
        $exploded_cred = explode(':', $raw_creds_line);
        $creds[$exploded_cred[0]] = $exploded_cred[1];
    }

    return $creds;
}

function cliBuild(Psr\Container\ContainerInterface $c, array $packages)
{
    array_merge($c->build_cmd, [implode(' ', $packages)]);
    $build_cmd = vsprintf(
        $c->build_template, array_merge($c->build_cmd, [implode(' ', $packages)])
    );
    $output = $c->ssh->exec($build_cmd);
    return $output;
}
