<?php

function getPackagesToUpdate(Psr\Http\Message\RequestInterface $request)
{
    return [];
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
